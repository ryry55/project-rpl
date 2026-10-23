<script setup>
/**
 * Halaman Tagihan — orkestrator.
 *
 * Tugasnya hanya tiga: memuat data, memegang tagihan mana yang sedang dibuka,
 * dan merangkai panel-panelnya. Semua keadaan formulir tinggal di panelnya
 * masing-masing, supaya membuka panel terbit massal tidak ikut mengosongkan
 * formulir terbit satuan.
 */
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { rupiah, pesanGalat } from '../../format.js'
import { simulasiTanggal } from '../../simulasi.js'
import KendaliSimulasi from '../../komponen/KendaliSimulasi.vue'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'
import PanelTerbitMassal from './komponen/PanelTerbitMassal.vue'
import PanelTerbitTagihan from './komponen/PanelTerbitTagihan.vue'
import PanelDetailTagihan from './komponen/PanelDetailTagihan.vue'
import TabelTagihan from './komponen/TabelTagihan.vue'

const daftar = ref([])
const kontrak = ref([])
const memuat = ref(true)
const galatMuat = ref('')

const massalTerbuka = ref(false)
const formTerbuka = ref(false)

const detail = ref(null)
const memuatDetail = ref(false)

// --- Simulasi tanggal (mode demo) ------------------------------------------
// Tanggalnya sendiri disimpan di `simulasi.js` karena juga dipakai halaman
// Tunggakan. Kalau tiap halaman menyimpan sendiri, dua layar bisa memakai
// tanggal acuan berbeda untuk tagihan yang sama.
const simulasiDipakai = ref(false)
const tanggalAcuan = ref('')

const totalPokok = computed(() => daftar.value.reduce((j, t) => j + t.pokok, 0))
const totalDenda = computed(() => daftar.value.reduce((j, t) => j + (t.denda || 0), 0))

/**
 * Total yang belum masuk.
 *
 * Dihitung di frontend dari `sisa` tiap baris, bukan dari `total - dibayar`
 * seluruhan: tagihan yang dibatalkan tidak boleh ikut dihitung sebagai piutang,
 * dan `sisa` sudah memperhitungkan itu per baris.
 */
const totalSisa = computed(() =>
  daftar.value.reduce((j, t) => j + (t.status === 'dibatalkan' ? 0 : t.sisa || 0), 0),
)

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const [hasilTagihan, hasilKontrak] = await Promise.all([
      sesi.panggil('/tagihan', { headers: simulasiTanggal.header() }),
      sesi.panggil('/kontrak'),
    ])
    daftar.value = hasilTagihan.data
    kontrak.value = hasilKontrak.data.filter((k) => k.status === 'aktif')

    // Status simulasi dibaca dari server, bukan ditebak dari ada/tidaknya
    // header. Server yang tahu apakah mode demo aktif; menganggap "ada tanggal
    // berarti berlaku" akan salah saat APP_DEMO_MODE=false.
    simulasiDipakai.value = Boolean(hasilTagihan.meta?.simulasi)
    tanggalAcuan.value = hasilTagihan.meta?.tanggal_acuan || ''

    // Panel rincian harus ikut disegarkan saat tanggal acuan berubah. Tanpa ini,
    // setelah simulasi dibersihkan daftar menampilkan denda "—" sementara panel
    // di atasnya masih menampilkan Rp 450.000 — dua angka berbeda untuk tagihan
    // yang sama di satu layar, dan tidak ada cara tahu mana yang benar.
    if (detail.value) {
      await bukaDetail({ id: detail.value.id })
    }
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

async function bukaDetail(tagihan) {
  memuatDetail.value = true

  // Panel hanya dikosongkan bila yang dibuka adalah tagihan lain. Saat
  // menyegarkan tagihan yang sama — sesudah mencatat pembayaran, atau sesudah
  // tanggal simulasi berubah — mengosongkannya akan melepas panel dari layar
  // dan memasangnya kembali, dan seluruh keadaan di dalamnya ikut hilang:
  // pesan "pembayaran tercatat" lenyap seketika, padahal justru itulah yang
  // perlu dibaca pemilik.
  if (detail.value?.id !== tagihan.id) {
    detail.value = null
  }

  try {
    // Dua panggilan, sengaja: /tagihan/{id} memberi rincian pokok yang dibekukan,
    // /tagihan/{id}/denda memberi uraian denda (hari terlambat, plafon, sisa hari).
    // Menggabungkannya di sini membuat layar bisa menjelaskan angka, bukan hanya
    // menampilkannya.
    const [hasilTagihan, hasilDenda] = await Promise.all([
      sesi.panggil(`/tagihan/${tagihan.id}`, { headers: simulasiTanggal.header() }),
      sesi.panggil(`/tagihan/${tagihan.id}/denda`, { headers: simulasiTanggal.header() }),
    ])
    detail.value = { ...hasilTagihan.data, dendaDetail: hasilDenda.data }
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuatDetail.value = false
  }
}

function tutupDetail() {
  detail.value = null
}

/** Dipanggil panel setelah menerbitkan: tutup panelnya, segarkan daftar. */
async function setelahTerbit() {
  formTerbuka.value = false
  await muat()
}

/** Dipanggil panel rincian setelah mencatat pembayaran. */
async function setelahBayar() {
  await bukaDetail({ id: detail.value.id })
  await muat()
}

onMounted(muat)
</script>

<template>
  <div class="halaman halaman-lebar">
    <KepalaHalaman judul="Tagihan">
      <template #keterangan>
        {{ daftar.length }} tagihan · pokok {{ rupiah(totalPokok) }}
        <template v-if="totalDenda > 0">· denda {{ rupiah(totalDenda) }}</template>
        <template v-if="totalSisa > 0">· belum masuk {{ rupiah(totalSisa) }}</template>
      </template>
      <template #aksi>
        <button v-if="!massalTerbuka" class="halus" @click="massalTerbuka = true">
          Terbitkan semua
        </button>
        <button v-if="!formTerbuka" @click="formTerbuka = true">Terbitkan tagihan</button>
      </template>
    </KepalaHalaman>

    <PanelTerbitMassal v-if="massalTerbuka" @tutup="massalTerbuka = false" @terbit="muat" />

    <!-- Kendali simulasi tanggal, dipakai bersama halaman Tunggakan. -->
    <KendaliSimulasi
      :dipakai="simulasiDipakai"
      :tanggal-acuan="tanggalAcuan"
      konteks="Denda"
      @berubah="muat"
    />

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>

    <PanelTerbitTagihan
      v-if="formTerbuka"
      :kontrak="kontrak"
      @tutup="formTerbuka = false"
      @terbit="setelahTerbit"
    />

    <PanelDetailTagihan
      v-if="detail"
      :detail="detail"
      :tanggal-acuan="tanggalAcuan"
      @tutup="tutupDetail"
      @berubah="setelahBayar"
    />

    <TabelTagihan
      :daftar="daftar"
      :memuat="memuat"
      :memuat-detail="memuatDetail"
      @buka="bukaDetail"
    />
  </div>
</template>
