<script setup>
/**
 * Persetujuan perubahan (T26).
 *
 * Halaman ini adalah alasan T26 ada. Sebelum ini, wewenang `ubah_tarif` berarti
 * "boleh langsung mengubah angka uang", dan satu centang yang salah pada satu
 * akun pengelola bisa menaikkan tarif seluruh kos tanpa ada yang melihat.
 * Sekarang pengelola hanya bisa **mengusulkan**, dan halaman inilah tempat
 * pemilik memutuskan.
 *
 * Tiga keputusan tampilan yang menentukan apakah halaman ini berguna:
 *
 *   1. **Yang ditampilkan adalah `status_efektif`, bukan `status`.** Usulan yang
 *      lewat tujuh hari masih tersimpan sebagai `menunggu` di basis data, tetapi
 *      sudah tidak bisa diputuskan. Kalau layar memakai `status`, pemilik akan
 *      melihat tombol Setujui yang selalu ditolak server - dan tombol yang tidak
 *      bekerja adalah keluhan yang paling sulit dijelaskan.
 *
 *   2. **`ringkasan` dibaca apa adanya dari server.** Kalimatnya dibekukan saat
 *      usulan dibuat, jadi kamar yang berganti nomor tidak mengubah bunyi usulan
 *      lama. Menyusun ulang kalimat di sini akan mengembalikan masalah itu.
 *
 *   3. **Penolakan wajib beralasan, dan alasannya ditulis di tempat.** Penolakan
 *      tanpa alasan membuat pengelola mengajukan ulang hal yang sama persis,
 *      dan pemilik akan menolaknya lagi dengan alasan yang sama yang tidak
 *      pernah ia tulis.
 *
 * Halaman ini hanya untuk pemilik. Yang boleh mengusulkan tidak boleh
 * memutuskan usulannya sendiri - itu inti pemisahannya, dan server menegakkannya
 * lewat `peran:pemilik`.
 */
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { pesanGalat } from '../../format.js'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PanelKartu from '../../komponen/ui/PanelKartu.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'

const daftar = ref([])
const meta = ref({ jumlah_menunggu: 0, label_tipe: {} })
const memuat = ref(true)
const galatMuat = ref('')

/**
 * Saringan status.
 *
 * `kedaluwarsa` ada di sini walaupun bukan nilai kolom: server menerjemahkannya
 * menjadi "masih menunggu DAN batas waktunya sudah lewat". Tanpa pilihan ini,
 * usulan yang mati diam-diam hanya terlihat sebagai "Menunggu" yang tombolnya
 * tidak bekerja.
 */
const saring = ref('menunggu')

const PILIHAN_SARING = [
  { nilai: 'menunggu', label: 'Menunggu keputusan' },
  { nilai: 'kedaluwarsa', label: 'Lewat 7 hari' },
  { nilai: 'disetujui', label: 'Disetujui' },
  { nilai: 'ditolak', label: 'Ditolak' },
  { nilai: '', label: 'Semua' },
]

// Id usulan yang sedang diproses. Satu per satu, bukan satu bendera global:
// menonaktifkan seluruh tombol karena satu baris sedang disimpan membuat
// pemilik mengira halamannya membeku.
const memproses = ref(0)
const galatAksi = ref({})
const galatUmum = ref('')
const sukses = ref('')

// Usulan yang form penolakannya sedang terbuka.
const menolak = ref(null)
const alasan = ref('')
const galatAlasan = ref('')

const jumlahMenunggu = computed(() => meta.value?.jumlah_menunggu ?? 0)

/**
 * Apakah akun ini boleh memutuskan.
 *
 * Pemilik selalu boleh; pengelola tidak pernah - termasuk bila ia punya
 * `ubah_tarif`, karena wewenang itu sekarang berarti "boleh mengusulkan".
 * Server menegakkannya lewat `peran:pemilik`; yang dilakukan di sini hanya
 * tidak merender tombol yang pasti ditolak.
 */
const bolehMemutuskan = computed(() => sesi.user.value?.peran === 'pemilik')

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const kueri = saring.value ? `?status=${saring.value}` : ''
    const hasil = await sesi.panggil(`/usulan${kueri}`)
    daftar.value = hasil.data || []
    meta.value = hasil.meta || meta.value
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

function gantiSaring() {
  sukses.value = ''
  galatUmum.value = ''
  menolak.value = null
  muat()
}

async function setujui(usulan) {
  memproses.value = usulan.id
  galatAksi.value = { ...galatAksi.value, [usulan.id]: '' }
  galatUmum.value = ''
  sukses.value = ''

  try {
    await sesi.panggil(`/usulan/${usulan.id}/setujui`, { method: 'POST' })
    sukses.value = `Usulan disetujui. ${usulan.label_tipe} baru langsung berlaku.`
    await muat()
  } catch (e) {
    // 409 dibiarkan apa adanya. Pesannya dari server sudah menjelaskan keadaan
    // sebenarnya ("sudah diputuskan", "lewat 7 hari", "tanggal berlaku sudah
    // dipakai"), dan menggantinya dengan kalimat umum akan menghapus satu-satunya
    // keterangan yang berguna.
    if (e.status === 409) {
      galatAksi.value = { ...galatAksi.value, [usulan.id]: e.message }
      await muat()
    } else {
      galatUmum.value = pesanGalat(e)
    }
  } finally {
    memproses.value = 0
  }
}

function bukaPenolakan(usulan) {
  menolak.value = usulan.id
  alasan.value = ''
  galatAlasan.value = ''
  galatAksi.value = { ...galatAksi.value, [usulan.id]: '' }
}

function batalPenolakan() {
  menolak.value = null
  alasan.value = ''
  galatAlasan.value = ''
}

async function tolak(usulan) {
  const teks = alasan.value.trim()

  if (teks === '') {
    // Diperiksa di sini juga, bukan hanya di server: pengelola menunggu jawaban
    // atas penolakan yang tidak bisa dikirim, dan itu terasa seperti kesalahan
    // sistem padahal hanya medan yang kosong.
    galatAlasan.value = 'Alasan penolakan wajib diisi.'
    return
  }

  memproses.value = usulan.id
  galatUmum.value = ''
  sukses.value = ''

  try {
    await sesi.panggil(`/usulan/${usulan.id}/tolak`, {
      method: 'POST',
      body: JSON.stringify({ alasan: teks }),
    })
    sukses.value = 'Usulan ditolak. Pengusul menerima alasannya.'
    batalPenolakan()
    await muat()
  } catch (e) {
    if (e.status === 409) {
      galatAksi.value = { ...galatAksi.value, [usulan.id]: e.message }
      batalPenolakan()
      await muat()
    } else {
      galatAlasan.value = pesanGalat(e)
    }
  } finally {
    memproses.value = 0
  }
}

/** Waktu lengkap: usulan dibaca menurut urutan kejadian. */
function waktu(iso) {
  if (!iso) return '-'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(d)
}

/**
 * Sisa hari dalam kalimat.
 *
 * Server mengirim angka, bukan kalimat, supaya layar bisa memilih kata yang
 * tepat: "1 hari lagi" dan "kurang dari sehari" adalah dua keadaan yang berbeda
 * bagi orang yang harus memutuskan hari ini.
 */
function sisaHari(usulan) {
  if (usulan.sisa_hari === null || usulan.sisa_hari === undefined) {
    return ''
  }

  if (usulan.sisa_hari <= 0) {
    return 'Batas waktunya sudah lewat'
  }

  if (usulan.sisa_hari === 1) {
    return 'Kurang dari sehari lagi'
  }

  return `${usulan.sisa_hari} hari lagi`
}

/** Lencana status memakai kelas `status-<nilai>` global. */
function kelasStatus(usulan) {
  return `status-${usulan.status_efektif}`
}

function labelStatus(usulan) {
  return {
    menunggu: 'Menunggu',
    disetujui: 'Disetujui',
    ditolak: 'Ditolak',
    kedaluwarsa: 'Lewat 7 hari',
  }[usulan.status_efektif] || usulan.status_efektif
}

onMounted(muat)
</script>

<template>
  <div class="halaman">
    <KepalaHalaman judul="Persetujuan perubahan">
      <template #keterangan>
        <template v-if="bolehMemutuskan">
          Perubahan tarif dan kebijakan yang diajukan pengelola.
          <template v-if="jumlahMenunggu > 0">
            <strong>{{ jumlahMenunggu }} menunggu keputusan Anda.</strong>
          </template>
          <template v-else> Tidak ada yang menunggu.</template>
        </template>
        <template v-else>
          Usulan perubahan yang Anda ajukan, dan nasibnya.
          <template v-if="jumlahMenunggu > 0">
            <strong>{{ jumlahMenunggu }} masih menunggu keputusan pemilik.</strong>
          </template>
          <template v-else> Tidak ada yang sedang menunggu.</template>
        </template>
      </template>
    </KepalaHalaman>

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>
    <PesanBaris v-if="galatUmum" jenis="galat">{{ galatUmum }}</PesanBaris>
    <PesanBaris v-if="sukses" jenis="sukses">{{ sukses }}</PesanBaris>

    <section class="kartu saring">
      <div class="baris-saring">
        <div>
          <label for="saring-usulan">Tampilkan</label>
          <select id="saring-usulan" v-model="saring" @change="gantiSaring">
            <option v-for="p in PILIHAN_SARING" :key="p.nilai" :value="p.nilai">
              {{ p.label }}
            </option>
          </select>
        </div>
        <p class="bantuan">
          Usulan yang tidak diputuskan dalam 7 hari ditutup dengan sendirinya.
          Pengelola bisa mengajukan ulang, jadi tidak ada yang hilang - tetapi
          perubahan yang ia butuhkan juga tidak terjadi.
        </p>
      </div>
    </section>

    <p v-if="memuat" class="lemah mungil">Memuat usulan.</p>

    <template v-else>
      <PanelKartu v-if="daftar.length > 0" judul="Antrean usulan">
        <template #keterangan>
          <p class="lemah mungil">
            <template v-if="bolehMemutuskan">
              Menyetujui langsung menulis tarif atau kebijakan baru. Menolak
              wajib beralasan supaya pengusul tahu apa yang harus diperbaiki.
            </template>
            <template v-else>
              Pemilik yang memutuskan. Anda bisa memantau di sini sampai
              usulannya disetujui, ditolak, atau lewat 7 hari.
            </template>
          </p>
        </template>

        <div class="daftar-usulan">
          <article v-for="u in daftar" :key="u.id" class="satu-usulan">
            <div class="kepala-usulan">
              <div>
                <span class="lencana-tipe mungil">{{ u.label_tipe }}</span>
                <span class="lencana-status" :class="kelasStatus(u)">{{ labelStatus(u) }}</span>
              </div>
              <p class="mungil lemah">
                Diajukan {{ waktu(u.dibuat_pada) }}
                <template v-if="u.pengusul"> oleh {{ u.pengusul }}</template>
              </p>
            </div>

            <p class="ringkasan-usulan">{{ u.ringkasan }}</p>

            <p v-if="u.bisa_diputuskan" class="mungil lemah">
              <template v-if="bolehMemutuskan">{{ sisaHari(u) }} sebelum ditutup.</template>
              <template v-else>{{ sisaHari(u) }} sebelum usulan ini ditutup otomatis.</template>
            </p>

            <p v-if="u.status_efektif === 'ditolak' && u.alasan" class="mungil lemah alasan">
              Alasan penolakan: {{ u.alasan }}
              <template v-if="u.pemutus"> ({{ u.pemutus }})</template>
            </p>

            <p v-if="u.status_efektif === 'disetujui' && u.pemutus" class="mungil lemah">
              Disetujui {{ waktu(u.diputuskan_pada) }} oleh {{ u.pemutus }}.
            </p>

            <p v-if="u.status_efektif === 'kedaluwarsa'" class="mungil lemah alasan">
              Lewat 7 hari tanpa keputusan, jadi usulan ini sudah tidak bisa
              diputuskan. Minta pengelola mengajukan ulang.
            </p>

            <PesanBaris v-if="galatAksi[u.id]" jenis="galat" mungil>
              {{ galatAksi[u.id] }}
            </PesanBaris>

            <div v-if="u.bisa_diputuskan && bolehMemutuskan" class="aksi-usulan">
              <template v-if="menolak === u.id">
                <div class="bidang-tolak">
                  <label :for="`alasan-${u.id}`">Alasan penolakan</label>
                  <input
                    :id="`alasan-${u.id}`"
                    v-model="alasan"
                    maxlength="255"
                    placeholder="mis. Terlalu tinggi untuk kamar ini."
                    autocomplete="off"
                    @keyup.enter="tolak(u)"
                  />
                  <p v-if="galatAlasan" class="galat-bidang">{{ galatAlasan }}</p>
                </div>
                <button
                  type="button"
                  class="bahaya"
                  :disabled="memproses === u.id"
                  @click="tolak(u)"
                >
                  {{ memproses === u.id ? 'Mengirim.' : 'Kirim penolakan' }}
                </button>
                <button type="button" class="halus" @click="batalPenolakan">Batal</button>
              </template>

              <template v-else>
                <button type="button" :disabled="memproses === u.id" @click="setujui(u)">
                  {{ memproses === u.id ? 'Memproses.' : 'Setujui' }}
                </button>
                <button type="button" class="halus" @click="bukaPenolakan(u)">Tolak</button>
              </template>
            </div>
          </article>
        </div>
      </PanelKartu>

      <section v-else class="kartu kosong-usulan">
        <p class="lemah mungil">
          {{ saring === 'menunggu'
            ? 'Tidak ada usulan yang menunggu. Perubahan tarif dan kebijakan yang diajukan pengelola akan muncul di sini.'
            : 'Tidak ada usulan dengan status ini.' }}
        </p>
      </section>
    </template>
  </div>
</template>

<style scoped>
.saring {
  padding: 14px 16px;
  margin-bottom: 14px;
}

.baris-saring {
  display: flex;
  gap: 18px;
  align-items: flex-start;
  flex-wrap: wrap;
}

.baris-saring > div {
  min-width: 220px;
}

.baris-saring .bantuan {
  flex: 1;
  min-width: 240px;
  margin: 0;
  padding-top: 21px;
}

.kosong-usulan {
  padding: 18px;
  text-align: center;
}

.kosong-usulan p {
  margin: 0;
}

.daftar-usulan {
  display: grid;
  gap: 12px;
}

/* Satu usulan diberi garis kiri, bukan kotak penuh: daftar kotak bersarang di
   dalam kartu membuat setiap usulan terlihat sama pentingnya dengan kartunya. */
.satu-usulan {
  padding: 12px 0 12px 14px;
  border-left: 2px solid var(--garis);
}

.satu-usulan:first-child {
  padding-top: 0;
}

.kepala-usulan {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.kepala-usulan > div {
  display: flex;
  align-items: baseline;
  gap: 8px;
  flex-wrap: wrap;
}

.kepala-usulan p {
  margin: 0;
}

/* Tipe usulan: cap, bukan pil biru. */
.lencana-tipe {
  padding: 1px 7px;
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--teks-lemah);
  background: transparent;
  border: 1px solid var(--garis);
  border-radius: var(--radius-kecil);
  vertical-align: 1px;
}

.ringkasan-usulan {
  margin: 6px 0 0;
  font-size: 13.5px;
  line-height: 1.5;
}

.alasan {
  color: var(--bahaya);
}

.aksi-usulan {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  margin-top: 10px;
  flex-wrap: wrap;
}

.bidang-tolak {
  flex: 1;
  min-width: 240px;
}

.bidang-tolak label {
  margin-bottom: 4px;
}

/* Tombol bahaya hanya untuk tindakan yang menutup sesuatu secara permanen.
   Menolak usulan memang begitu: usulannya tidak bisa dibuka lagi. */
button.bahaya {
  color: #fff;
  background: var(--bahaya);
  border-color: var(--bahaya);
}

button.bahaya:hover:not(:disabled) {
  filter: brightness(0.94);
}

/* Lencana status kedaluwarsa belum punya warna sendiri di style.css. Dibuat
   netral di sini, bukan merah: usulan yang lewat batas bukan kesalahan pemilik,
   dan warna bahaya akan membuatnya terasa seperti ada yang rusak. */
.lencana-status.status-kedaluwarsa {
  color: var(--teks-lemah);
  background: var(--permukaan-turun);
}

.lencana-status.status-menunggu {
  color: var(--aksen);
  background: var(--aksen-lemah);
}

.lencana-status.status-disetujui {
  color: var(--sukses);
  background: var(--sukses-lemah);
}

.lencana-status.status-ditolak {
  color: var(--bahaya);
  background: var(--bahaya-lemah);
}

@media (max-width: 700px) {
  .baris-saring .bantuan {
    padding-top: 0;
  }

  .aksi-usulan {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>
