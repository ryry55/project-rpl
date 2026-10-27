<script setup>
/**
 * Pengaturan notifikasi (T25).
 *
 * Satu matriks: sembilan peristiwa x dua kanal. Bentuknya sengaja berupa tabel
 * centang, bukan sembilan kartu - pertanyaan yang dijawab layar ini adalah
 * "peristiwa mana yang mau saya terima lewat apa", dan pertanyaan itu dibaca
 * sebagai perbandingan, bukan sebagai sembilan keputusan terpisah.
 *
 * Dua hal yang ditentukan server dan **tidak** disalin ke sini:
 *
 *   1. **Daftar peristiwa dan labelnya** (`meta.peristiwa`,
 *      `meta.label_peristiwa`). Daftarnya tertutup di `Peristiwa::SEMUA`, dan
 *      menyalinnya ke JavaScript berarti layar bisa menampilkan baris yang tidak
 *      pernah dipicu kode mana pun.
 *
 *   2. **Kanal yang wajib** (`meta.kanal_wajib`). In-app tidak bisa dimatikan:
 *      mematikannya berarti peristiwa yang mengubah uang tidak memberi tahu
 *      siapa pun, dan satu-satunya cara mengetahui tarif naik adalah membuka
 *      layarnya kebetulan. Centangnya dinonaktifkan di sini, dan server memaksa
 *      `true` walaupun ada yang mengirim `false` lewat curl.
 *
 * Pengaturan ini **per akun**, bukan per kos. Pengelola yang mematikan emailnya
 * tidak mendiamkan peringatan pemilik.
 */
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { pesanGalat } from '../../format.js'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PanelKartu from '../../komponen/ui/PanelKartu.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'

const memuat = ref(true)
const galatMuat = ref('')
const menyimpan = ref(false)
const galatSimpan = ref('')
const sukses = ref('')

const peristiwa = ref([])
const labelPeristiwa = ref({})
const kanal = ref([])
const labelKanal = ref({})
const kanalWajib = ref([])

/**
 * Matriks lokal: `{ peristiwa: { kanal: boolean } }`.
 *
 * Dibuat sebagai objek reaktif, bukan dihitung dari respons server setiap kali
 * dirender. Centang harus bisa diubah tanpa memanggil server, dan menyimpan
 * baru terjadi saat tombol ditekan - matriks yang langsung tersimpan per
 * centang akan mengirim sembilan permintaan untuk satu kali pengaturan.
 */
const matriks = ref({})

const wajib = computed(() => new Set(kanalWajib.value))

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const hasil = await sesi.panggil('/pengaturan-notifikasi')
    peristiwa.value = hasil.meta?.peristiwa || []
    labelPeristiwa.value = hasil.meta?.label_peristiwa || {}
    kanal.value = hasil.meta?.kanal || []
    labelKanal.value = hasil.meta?.label_kanal || {}
    kanalWajib.value = hasil.meta?.kanal_wajib || []
    matriks.value = hasil.data?.matriks || {}
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

function nilai(p, k) {
  return Boolean(matriks.value?.[p]?.[k])
}

function ubah(p, k, aktif) {
  if (!matriks.value[p]) {
    matriks.value[p] = {}
  }
  matriks.value[p][k] = aktif
}

async function simpan() {
  menyimpan.value = true
  galatSimpan.value = ''
  sukses.value = ''

  try {
    const hasil = await sesi.panggil('/pengaturan-notifikasi', {
      method: 'PUT',
      body: JSON.stringify({ matriks: matriks.value }),
    })
    // Matriks dari server dipakai ulang, bukan matriks lokal: server memaksa
    // kanal wajib dan membuang baris yang sama dengan bawaan. Menampilkan
    // matriks lokal sesudah simpan akan memperlihatkan centang yang tidak
    // benar-benar tersimpan.
    matriks.value = hasil.data?.matriks || matriks.value
    sukses.value = 'Pengaturan notifikasi disimpan.'
  } catch (e) {
    galatSimpan.value = pesanGalat(e)
  } finally {
    menyimpan.value = false
  }
}

onMounted(muat)
</script>

<template>
  <div class="halaman">
    <KepalaHalaman judul="Pengaturan notifikasi">
      <template #keterangan>
        Cara Anda sendiri diberi tahu. Pengaturan ini tidak memengaruhi akun lain.
      </template>
    </KepalaHalaman>

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>
    <PesanBaris v-if="sukses" jenis="sukses">{{ sukses }}</PesanBaris>
    <PesanBaris v-if="galatSimpan" jenis="galat">{{ galatSimpan }}</PesanBaris>

    <p v-if="memuat" class="lemah mungil">Memuat pengaturan.</p>

    <template v-else>
      <PanelKartu judul="Peristiwa dan kanalnya">
        <template #keterangan>
          <p class="lemah mungil">
            Notifikasi dalam aplikasi selalu menyala dan tidak bisa dimatikan.
            Email hanya untuk peristiwa yang jarang terjadi tetapi mengubah uang
            atau cara kos menagih.
          </p>
        </template>

        <div class="tabel-bungkus">
          <table class="tabel tabel-notifikasi" v-kartu-tabel>
            <thead>
              <tr>
                <th>Peristiwa</th>
                <th v-for="k in kanal" :key="k" class="tengah">
                  {{ labelKanal[k] || k }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in peristiwa" :key="p">
                <td>
                  <span class="nama-peristiwa">{{ labelPeristiwa[p] || p }}</span>
                  <p v-if="wajib.has('in_app')" class="mungil lemah kode">{{ p }}</p>
                </td>
                <td v-for="k in kanal" :key="k" class="tengah">
                  <label class="pilihan-kanal">
                    <input
                      type="checkbox"
                      :checked="nilai(p, k)"
                      :disabled="wajib.has(k)"
                      :aria-label="`${labelPeristiwa[p] || p} lewat ${labelKanal[k] || k}`"
                      @change="ubah(p, k, $event.target.checked)"
                    />
                  </label>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="aksi">
          <button type="button" :disabled="menyimpan" @click="simpan">
            {{ menyimpan ? 'Menyimpan.' : 'Simpan pengaturan' }}
          </button>
        </div>
      </PanelKartu>

      <p class="lemah mungil kaki">
        Email dikirim setelah perubahan tersimpan, bukan sebagai syaratnya.
        Kalau email gagal terkirim, perubahannya tetap sah dan jejaknya tetap
        tercatat di menu Jejak audit.
      </p>
    </template>
  </div>
</template>

<style scoped>
.tabel-notifikasi td {
  vertical-align: middle;
}

.nama-peristiwa {
  font-size: 13px;
  font-weight: 550;
}

.kode {
  margin: 1px 0 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 11px;
  opacity: 0.8;
  /* Kode peristiwa seperti `tagihan.terbit` adalah satu kata tanpa spasi, jadi
     ia tidak bisa dibungkus dan memaksa kolom pertama selebar 407px — terukur:
     tabel 3 kolom ini meluber 47px pada layar 360px meski hanya punya tiga
     kolom. `overflow-wrap: anywhere` mengizinkannya patah di titik mana pun,
     sehingga tabel muat tanpa menggulir mendatar.
   */
  overflow-wrap: anywhere;
}

.tengah {
  text-align: center;
}

/* Kotak centang diperbesar dan dispusatkan: matriks sembilan baris dibaca dengan
   mata menyapu satu kolom, dan kotak 13px di tengah sel membuat kolomnya sulit
   diikuti. */
.pilihan-kanal {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin: 0;
  cursor: pointer;
}

.pilihan-kanal input {
  width: 17px;
  height: 17px;
  margin: 0;
  accent-color: var(--aksen);
  cursor: pointer;
}

.pilihan-kanal input:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.kaki {
  margin-top: 14px;
  text-align: center;
}

@media (max-width: 620px) {
  .tabel-notifikasi th,
  .tabel-notifikasi td {
    padding: 8px 6px;
  }

  .nama-peristiwa {
    font-size: 12.5px;
  }
}
</style>
