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
import { RouterLink } from 'vue-router'
import { sesi } from '../../sesi.js'
import { rupiah, pesanGalat } from '../../format.js'
import { simulasiTanggal } from '../../simulasi.js'
import KendaliSimulasi from '../../komponen/KendaliSimulasi.vue'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'
import PanelKartu from '../../komponen/ui/PanelKartu.vue'
import PanelTerbitMassal from './komponen/PanelTerbitMassal.vue'
import PanelTerbitTagihan from './komponen/PanelTerbitTagihan.vue'
import PanelDetailTagihan from './komponen/PanelDetailTagihan.vue'
import TabelTagihan from './komponen/TabelTagihan.vue'

const daftar = ref([])
const kontrak = ref([])
const memuat = ref(true)
const galatMuat = ref('')

// Rekening tujuan pembayaran (REV-UI-05). Dimuat di sini, bukan di panel
// rincian, karena pesan WhatsApp yang disusun panel harus menyebut rekening yang
// sama dengan yang dilihat penghuni di portalnya.
const rekening = ref([])

// Gerbang kebijakan (T16). `kebijakanKurang` berisi label jenis yang belum
// ditetapkan; selama tidak kosong, tombol terbit tidak ditampilkan sama sekali.
// Server tetap menolak dengan 422 walau tombolnya dipaksa lewat - yang ini
// hanya supaya pemilik tidak menempuh jalan buntu yang tidak dijelaskan.
//
// Yang disimpan label, bukan kode: `satuan_sewa` dan `jatuh_tempo` adalah nama
// kolom, bukan bahasa yang dibaca pemilik. Label datang dari server
// (`meta.label_tipe`) supaya layar ini tidak menyimpan salinan kedua kosakata.
const kebijakanKurang = ref([])

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

/**
 * Petunjuk saat tabel kosong.
 *
 * Menyebut tombol hanya kalau tombolnya ada. Selama gerbang kebijakan menutup,
 * tombol terbit disembunyikan, dan petunjuk lama menunjuk sesuatu yang tidak
 * bisa dilihat - pemilik mencari tombol yang memang tidak dirender.
 *
 * Pengelola yang tidak berwenang menerbitkan mendapat kalimat yang berbeda:
 * menyuruhnya menekan tombol yang tidak ada, atau menyuruhnya mengisi kebijakan
 * yang juga bukan haknya, sama-sama menyesatkan.
 */
const pesanKosong = computed(() => {
  if (!sesi.boleh('terbitkan_tagihan')) {
    return 'Belum ada tagihan. Penerbitan tagihan bukan wewenang akun Anda - hubungi pemilik kos.'
  }

  return kebijakanKurang.value.length
    ? 'Belum ada tagihan. Tetapkan kebijakan kos lebih dulu sebelum menerbitkan.'
    : 'Belum ada tagihan. Terbitkan tagihan pertama lewat tombol di atas.'
})

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const [hasilTagihan, hasilKontrak, hasilKebijakan] = await Promise.all([
      sesi.panggil('/tagihan', { headers: simulasiTanggal.header() }),
      sesi.panggil('/kontrak'),
      // Kegagalan membaca kebijakan tidak boleh mengosongkan halaman Tagihan:
      // daftar tagihan tetap berguna, dan gerbang di bawah hanya menampilkan
      // peringatan bila datanya memang berhasil dibaca.
      sesi.panggil('/kebijakan', { headers: simulasiTanggal.header() }).catch(() => null),
    ])
    daftar.value = hasilTagihan.data
    kontrak.value = hasilKontrak.data.filter((k) => k.status === 'aktif')

    // Rekening dimuat terpisah dan kegagalannya diabaikan: tagihan tetap harus
    // tampil walau daftar rekening belum diisi. Yang hilang hanya satu baris di
    // pesan WhatsApp, dan itu bukan alasan mengosongkan seluruh halaman.
    sesi.panggil('/rekening?aktif=1').then((hasilRekening) => {
      rekening.value = hasilRekening.data || []
    }).catch(() => {
      rekening.value = []
    })

    // Kode tipe diterjemahkan ke label di sini, sekali, lalu dibuang. Kalau
    // kode mentah yang disimpan, setiap tempat yang menampilkannya harus ingat
    // menerjemahkan - dan satu tempat yang lupa akan menampilkan `jatuh_tempo`
    // kepada pemilik kos.
    const labelTipe = hasilKebijakan?.meta?.label_tipe || {}
    kebijakanKurang.value = (hasilKebijakan?.meta?.kurang || []).map(
      (tipe) => labelTipe[tipe] || tipe,
    )

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
        <RouterLink
          v-if="kebijakanKurang.length && sesi.boleh('ubah_kebijakan')"
          class="tombol-tautan"
          :to="{ name: 'pemilik-kebijakan' }"
        >
          Isi kebijakan dulu
        </RouterLink>
        <!--
          Tombol terbit hanya muncul bila memang boleh dipakai. Pengelola yang
          bertugas mencatat pembayaran tetap membuka halaman ini untuk membaca
          tagihan, dan menampilkan tombol yang pasti ditolak 403 akan membuatnya
          mengira aplikasinya rusak.
        -->
        <template v-else-if="sesi.boleh('terbitkan_tagihan')">
          <button v-if="!massalTerbuka" class="halus" @click="massalTerbuka = true">
            Terbitkan semua
          </button>
          <button v-if="!formTerbuka" @click="formTerbuka = true">Terbitkan tagihan</button>
        </template>
      </template>
    </KepalaHalaman>

    <!-- Gerbang kebijakan (T16). Panel, bukan modal: pemilik harus bisa
         membaca akibatnya sambil melihat daftar tagihan yang sudah ada, dan
         modal yang menutup layar membuat tagihan lama seolah hilang. -->
    <PanelKartu v-if="kebijakanKurang.length" kelas="gerbang-tagihan" judul="Kebijakan kos belum lengkap">
      <template #keterangan>
        <p class="bantuan">
          Tagihan tidak bisa diterbitkan sebelum
          <strong>{{ kebijakanKurang.join(' dan ') }}</strong>
          ditetapkan. Sistem tidak menebak kebijakan kos.
        </p>
        <p v-if="!sesi.boleh('ubah_kebijakan')" class="bantuan">
          Menetapkan kebijakan adalah wewenang pemilik kos, jadi akun Anda tidak bisa
          melengkapinya sendiri.
        </p>
      </template>
      <RouterLink
        v-if="sesi.boleh('ubah_kebijakan')"
        class="tombol-tautan"
        :to="{ name: 'pemilik-kebijakan' }"
      >
        Buka Kebijakan
      </RouterLink>
    </PanelKartu>

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
      :rekening="rekening"
      @tutup="tutupDetail"
      @berubah="setelahBayar"
    />

    <TabelTagihan
      :daftar="daftar"
      :memuat="memuat"
      :memuat-detail="memuatDetail"
      :galat-muat="Boolean(galatMuat)"
      :pesan-kosong="pesanKosong"
      @buka="bukaDetail"
    />
  </div>
</template>

<style scoped>
/* Gerbang ditandai garis kiri, sama seperti di layar Kebijakan, supaya dua
   tempat yang membicarakan hal yang sama terlihat sebagai satu hal. */
.gerbang-tagihan {
  border-left: 3px solid var(--bahaya);
  background: var(--bahaya-lemah);
}

.gerbang-tagihan :deep(h2) {
  color: var(--bahaya);
}
</style>
