<script setup>
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { rupiah, tanggal, pesanGalat } from '../../format.js'
import { simulasiTanggal } from '../../simulasi.js'
import KendaliSimulasi from '../../komponen/KendaliSimulasi.vue'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'

const data = ref(null)
const memuat = ref(true)
const galatMuat = ref('')

// Kelompok yang sedang dibuka. `null` berarti semua kelompok ditampilkan
// bertumpuk; memilih satu menyaring layar tanpa menghilangkan kartu ringkasan,
// supaya pemilik tetap melihat gambaran besarnya.
const kelompokAktif = ref(null)

/** Status simulasi dibaca dari server, bukan ditebak dari nilai di localStorage. */
const simulasiDipakai = ref(false)
const tanggalAcuan = ref('')

const kelompok = computed(() => data.value?.kelompok || [])

const ringkasan = computed(() => data.value?.ringkasan || {
  jumlah: 0,
  total_pokok: 0,
  total_denda: 0,
  total: 0,
})

/**
 * Kelompok yang punya isi. Kartu kosong tetap ditampilkan, tetapi daftar
 * bertumpuk hanya menampilkan yang berisi — kalau tidak, layar penuh judul
 * kosong.
 */
const kelompokBerisi = computed(() => kelompok.value.filter((k) => k.jumlah > 0))

const kelompokTampil = computed(() => {
  if (kelompokAktif.value) {
    return kelompok.value.filter((k) => k.kunci === kelompokAktif.value)
  }
  return kelompokBerisi.value
})

/**
 * Penekanan visual per kelompok: makin lama menunggak, makin keras.
 *
 * Angka tanpa penekanan tidak menuntun perhatian. Pemilik yang membuka layar
 * harus langsung tahu mana yang perlu tindakan hari ini.
 */
function kelasKelompok(kunci) {
  return {
    'kelompok-belum': kunci === 'belum_jatuh_tempo',
    'kelompok-ringan': kunci === '0_30',
    'kelompok-sedang': kunci === '31_60',
    'kelompok-berat': kunci === 'lebih_60',
    'kelompok-dipilih': kelompokAktif.value === kunci,
  }
}

function pilihKelompok(kunci) {
  // Menekan kartu yang sama membatalkan saringan — tanpa ini, pemilik tidak
  // bisa kembali ke tampilan penuh setelah memilih satu kelompok.
  kelompokAktif.value = kelompokAktif.value === kunci ? null : kunci
}

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const hasil = await sesi.panggil('/tunggakan', { headers: simulasiTanggal.header() })
    data.value = hasil.data
    simulasiDipakai.value = Boolean(data.value?.simulasi)
    tanggalAcuan.value = data.value?.tanggal_acuan || ''
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

onMounted(muat)

/**
 * Unduh daftar tunggakan sebagai CSV.
 *
 * Berbeda dari ekspor rekap, yang ini juga terbuka untuk pengelola: dialah yang
 * menagih, dan daftar ini memang sudah bisa ia baca di layar. Menutup
 * ekspornya hanya memaksanya menyalin dengan tangan ke WhatsApp satu per satu -
 * lebih lambat, dan lebih rawan salah sebut nominal.
 *
 * Tanggal acuannya tidak dikirim: `/tunggakan` sengaja tidak menerima parameter
 * tanggal (dua jalur penggeseran tanggal akan menghasilkan dua tanggal acuan
 * berbeda untuk tagihan yang sama). Ekspornya memakai acuan yang sama dengan
 * layar, yaitu "hari ini" menurut server.
 */
const mengunduh = ref(false)
const galatUnduh = ref('')
const suksesUnduh = ref('')

async function unduhTunggakan() {
  mengunduh.value = true
  galatUnduh.value = ''
  suksesUnduh.value = ''

  try {
    const nama = await sesi.unduhBerkas('/ekspor/tunggakan')
    suksesUnduh.value = `Berkas ${nama} diunduh.`
  } catch (e) {
    galatUnduh.value = pesanGalat(e)
  } finally {
    mengunduh.value = false
  }
}
</script>

<template>
  <div class="halaman">
    <KepalaHalaman judul="Tunggakan">
      <template #keterangan>
        {{ ringkasan.jumlah }} tagihan belum lunas · denda {{ rupiah(ringkasan.total_denda) }}
      </template>
      <template #aksi>
        <button
          v-if="data && ringkasan.jumlah > 0"
          type="button"
          class="halus"
          :disabled="mengunduh"
          @click="unduhTunggakan"
        >
          {{ mengunduh ? 'Mengunduh…' : 'Ekspor CSV' }}
        </button>
      </template>
    </KepalaHalaman>

    <p v-if="galatUnduh" class="galat-bidang">{{ galatUnduh }}</p>
    <p v-if="suksesUnduh" class="bantuan mungil">{{ suksesUnduh }}</p>

    <KendaliSimulasi
      :dipakai="simulasiDipakai"
      :tanggal-acuan="tanggalAcuan"
      konteks="Umur tunggakan"
      @berubah="muat"
    />

    <p v-if="galatMuat" class="pesan-galat">{{ galatMuat }}</p>
    <p v-else-if="memuat" class="bantuan">Memuat…</p>

    <template v-else>
      <!-- Empat kartu kelompok. Yang kosong tetap tampil: "0 di atas 60 hari"
           adalah kabar baik, dan menghilangkannya membuat layar terasa kosong
           padahal keadaannya baik. -->
      <div class="kisi-kelompok">
        <button
          v-for="k in kelompok"
          :key="k.kunci"
          type="button"
          class="kartu kartu-kelompok"
          :class="kelasKelompok(k.kunci)"
          @click="pilihKelompok(k.kunci)"
        >
          <span class="kelompok-judul mungil">{{ k.label }}</span>
          <span class="kelompok-angka">{{ k.jumlah }}</span>
          <span class="kelompok-total mungil">{{ rupiah(k.total) }}</span>
          <span class="kelompok-ket mungil lemah">{{ k.keterangan }}</span>
        </button>
      </div>

      <p v-if="kelompokAktif" class="bantuan mungil">
        Menampilkan satu kelompok.
        <button class="halus mungil" @click="kelompokAktif = null">Tampilkan semua</button>
      </p>

      <!-- Daftar per kelompok -->
      <section v-for="k in kelompokTampil" :key="k.kunci" class="kartu bagian-kelompok">
        <header class="kepala-kelompok">
          <div>
            <h2 class="mungil">{{ k.label }}</h2>
            <p class="bantuan mungil lemah">{{ k.keterangan }}</p>
          </div>
          <div class="kanan-kelompok mungil">
            <strong>{{ k.jumlah }} tagihan</strong>
            <span class="lemah">{{ rupiah(k.total) }}</span>
          </div>
        </header>

        <div class="tabel-bungkus">
          <table class="tabel" v-kartu-tabel>
            <thead>
              <tr>
                <th>Penghuni</th>
                <th>Kamar</th>
                <th>Periode</th>
                <th class="kanan">Pokok</th>
                <th class="kanan">Denda</th>
                <th class="kanan">Total</th>
                <th class="kanan">Terlambat</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in k.tagihan" :key="t.id">
                <td><strong>{{ t.penghuni || '—' }}</strong></td>
                <td>{{ t.kamar || '—' }}</td>
                <td>{{ tanggal(t.periode) }}</td>
                <td class="kanan">{{ rupiah(t.pokok) }}</td>
                <td class="kanan" :class="{ 'sel-denda': t.denda > 0 }">
                  {{ t.denda > 0 ? rupiah(t.denda) : '—' }}
                </td>
                <td class="kanan"><strong>{{ rupiah(t.total) }}</strong></td>
                <td class="kanan">
                  <template v-if="t.hari_terlambat < 0">
                    {{ -t.hari_terlambat }} hari lagi
                  </template>
                  <template v-else>{{ t.hari_terlambat }} hari</template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <p v-if="kelompokTampil.length === 0" class="kartu kosong-semua bantuan">
        Tidak ada tunggakan. Semua tagihan sudah lunas atau belum jatuh tempo.
      </p>
    </template>
  </div>
</template>

<style scoped>
/* Halaman ini sengaja memakai lebar penuh: empat kartu kelompok tunggakan
   berjajar dan akan terpotong kalau dibatasi 1020px. */
.halaman {
  max-width: none;
}

/* Tanpa margin bawah: kartu kelompok di bawahnya sudah punya jarak sendiri dari
   `gap` milik .halaman, dan menambah 2px membuatnya terlihat tidak rata
   dibanding halaman lain. */
.kepala {
  margin-bottom: 0;
}

.kepala h1 {
  margin: 0 0 3px;
}

/* Empat kartu dalam satu baris di layar lebar, dua di tablet, satu di ponsel. */
.kisi-kelompok {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

/* Kartu kelompok memakai <button> supaya bisa difokus dan diaktifkan lewat
   papan ketik. Konsekuensinya ia mewarisi gaya tombol global (latar aksen, teks
   putih), jadi seluruh tampilan tombol harus direset di sini. Tanpa reset ini,
   teks total menjadi putih di atas kartu putih — tidak terlihat sama sekali,
   dan tidak ada tes DOM yang menangkapnya. */
.kartu-kelompok {
  display: grid;
  gap: 2px;
  padding: 14px 16px;
  font: inherit;
  font-size: 13.5px;
  font-weight: 400;
  color: var(--teks);
  text-align: left;
  cursor: pointer;
  background: var(--permukaan);
  border: 1px solid var(--garis);
  border-radius: 12px;
  transition: border-color 120ms ease, box-shadow 120ms ease;
}

.kartu-kelompok:hover {
  border-color: #cfd4dc;
  box-shadow: 0 1px 3px rgb(16 24 40 / 6%);
}

.kelompok-judul {
  font-weight: 600;
  color: var(--teks-lemah);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

/* Angka kelompok adalah hal pertama yang dicari mata, jadi paling besar. */
.kelompok-angka {
  font-size: 30px;
  font-weight: 680;
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}

.kelompok-total {
  color: var(--teks);
  font-variant-numeric: tabular-nums;
}

.kelompok-ket {
  margin-top: 2px;
  line-height: 1.35;
}

/* Penekanan bertingkat: makin lama menunggak, makin keras warnanya. Warna
   dipakai sebagai urutan keparahan, bukan hiasan. */
.kelompok-belum .kelompok-angka {
  color: #667085;
}

.kelompok-ringan .kelompok-angka {
  color: #8a5a00;
}

.kelompok-sedang .kelompok-angka {
  color: #a3411a;
}

.kelompok-berat .kelompok-angka {
  color: #b42318;
}

.kelompok-berat {
  border-color: #f0c7c2;
}

.kelompok-dipilih {
  border-color: var(--aksen);
  box-shadow: 0 0 0 1px var(--aksen);
}

.bagian-kelompok {
  display: grid;
  gap: 10px;
}

.kepala-kelompok {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}

.kepala-kelompok h2 {
  margin: 0 0 2px;
}

.kanan-kelompok {
  display: grid;
  justify-items: end;
  font-variant-numeric: tabular-nums;
}

.sel-denda {
  color: #8a5a00;
  font-weight: 600;
}

.kosong-semua {
  text-align: center;
}

@media (max-width: 1100px) {
  .kisi-kelompok {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 620px) {
  .kisi-kelompok {
    grid-template-columns: 1fr;
  }
}
</style>
