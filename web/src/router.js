import { createRouter, createWebHistory } from 'vue-router'
import { sesi } from './sesi.js'
import LoginView from './views/LoginView.vue'
import TataLetakPemilik from './komponen/TataLetakPemilik.vue'
import KamarView from './views/pemilik/KamarView.vue'
import PenghuniView from './views/pemilik/PenghuniView.vue'
import KontrakView from './views/pemilik/KontrakView.vue'
import TagihanView from './views/pemilik/TagihanView.vue'
import TunggakanView from './views/pemilik/TunggakanView.vue'
import RekapView from './views/pemilik/RekapView.vue'
import AuditLogView from './views/pemilik/AuditLogView.vue'
import KebijakanView from './views/pemilik/KebijakanView.vue'
import FasilitasView from './views/pemilik/FasilitasView.vue'
import AturanView from './views/pemilik/AturanView.vue'
import PersetujuanView from './views/pemilik/PersetujuanView.vue'
import PengaturanNotifikasiView from './views/pemilik/PengaturanNotifikasiView.vue'
import PengelolaView from './views/pemilik/PengelolaView.vue'
import DashboardPenghuni from './views/DashboardPenghuni.vue'
import TidakDitemukanView from './views/TidakDitemukanView.vue'

/**
 * Rute di bawah `/pemilik` dipakai pemilik DAN pengelola.
 *
 * Awalan `pemilik` dipertahankan walaupun sekarang juga dipakai pengelola,
 * karena mengganti namanya menjadi `/kelola` berarti mengubah semua tautan
 * yang sudah tercatat di dokumen, tangkapan layar, dan laporan. Nama rute
 * (`pemilik-kamar`) juga tetap: itu kunci internal, bukan yang dibaca orang.
 *
 * Pembatasan per wewenang TIDAK dilakukan di sini, melainkan oleh server.
 * Router hanya tahu peran; menyembunyikan menu dilakukan TataLetakPemilik
 * berdasarkan `sesi.user.wewenang`. Menaruh pemeriksaan wewenang di router
 * akan membuatnya tampak seperti pengamanan padahal seluruh data tetap
 * terkirim ke peramban.
 */
const routes = [
  { path: '/', redirect: '/masuk' },
  { path: '/masuk', name: 'masuk', component: LoginView, meta: { publik: true } },
  {
    path: '/pemilik',
    component: TataLetakPemilik,
    // `peran` boleh berupa daftar: pemilik dan pengelola sama-sama mengurus
    // kos ini. `meta.peranSaja` menandai halaman yang tetap hanya untuk
    // pemilik - yaitu akun dan izin, yang tidak bisa didelegasikan.
    meta: { peran: ['pemilik', 'pengelola'] },
    children: [
      { path: '', redirect: { name: 'pemilik-kamar' } },
      { path: 'kamar', name: 'pemilik-kamar', component: KamarView },
      { path: 'penghuni', name: 'pemilik-penghuni', component: PenghuniView },
      { path: 'kontrak', name: 'pemilik-kontrak', component: KontrakView },
      { path: 'tagihan', name: 'pemilik-tagihan', component: TagihanView },
      // Rekap diletakkan tepat setelah Tagihan: keduanya dibuka setiap bulan,
      // dan rekap adalah pertanyaan lanjutan yang wajar setelah tagihan terbit -
      // "berapa yang benar-benar masuk".
      { path: 'rekap', name: 'pemilik-rekap', component: RekapView },
      { path: 'tunggakan', name: 'pemilik-tunggakan', component: TunggakanView },
      { path: 'kebijakan', name: 'pemilik-kebijakan', component: KebijakanView },
      { path: 'fasilitas', name: 'pemilik-fasilitas', component: FasilitasView },
      { path: 'aturan', name: 'pemilik-aturan', component: AturanView },
      { path: 'audit', name: 'pemilik-audit', component: AuditLogView },
      {
        path: 'persetujuan',
        name: 'pemilik-persetujuan',
        component: PersetujuanView,
        // Terbuka untuk pemilik DAN pengelola, dengan isi yang berbeda:
        // server menyaring `GET /usulan` menjadi usulan milik pemanggil bila ia
        // bukan pemilik. Tanpa ini pengelola tidak punya tempat melihat nasib
        // usulannya - ia hanya menerima satu notifikasi lalu tidak bisa
        // memeriksa apa pun lagi.
        //
        // Yang tetap hanya untuk pemilik adalah tombol keputusannya, dan itu
        // ditegakkan server lewat `peran:pemilik` pada endpoint setujui/tolak.
        // Menyembunyikan tombolnya di sini hanya mencegah pengelola menekan
        // tombol yang pasti ditolak.
      },
      // Pengaturan notifikasi terbuka untuk siapa pun yang punya kotak masuk:
      // mengatur cara diri sendiri diberi tahu bukan tindakan atas data kos.
      {
        path: 'pengaturan-notifikasi',
        name: 'pemilik-pengaturan-notifikasi',
        component: PengaturanNotifikasiView,
      },
      {
        path: 'pengelola',
        name: 'pemilik-pengelola',
        component: PengelolaView,
        // Hanya pemilik: siapa pun yang boleh memberi wewenang pada dasarnya
        // boleh memberi dirinya wewenang apa pun, termasuk mengubah tarif.
        meta: { peranSaja: 'pemilik' },
      },
    ],
  },
  {
    path: '/penghuni',
    name: 'penghuni',
    component: DashboardPenghuni,
    meta: { peran: ['penghuni'] },
  },
  // Penangkap terakhir. Didaftarkan paling akhir supaya tidak menyerobot rute
  // mana pun; `meta.publik` membuat alamat yang salah ketik tidak dilempar ke
  // halaman masuk lebih dulu - halaman 404 justru yang menjelaskan masalahnya.
  {
    path: '/:sisa(.*)*',
    name: 'tidak-ditemukan',
    component: TidakDitemukanView,
    meta: { publik: true },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

/**
 * Halaman muka untuk satu peran.
 *
 * Pengelola diarahkan ke Tagihan, bukan ke Kamar: pekerjaan hariannya mencatat
 * pembayaran dan menerbitkan tagihan, sementara kamar ia buka sekali-sekali.
 * Mengarahkannya ke halaman yang tidak boleh ia ubah hanya membuat kesan
 * pertama terlihat seperti aplikasi yang rusak.
 */
function halamanMuka(peran) {
  if (peran === 'pemilik') {
    return { name: 'pemilik-kamar' }
  }

  if (peran === 'pengelola') {
    return { name: 'pemilik-tagihan' }
  }

  return { name: 'penghuni' }
}

router.beforeEach(async (to) => {
  if (to.meta.publik) {
    return true
  }

  // Coba pulihkan sesi dari token tersimpan sebelum menolak akses.
  if (!sesi.user.value) {
    await sesi.muatSaya()
  }

  if (!sesi.user.value) {
    return { name: 'masuk', query: { lanjut: to.fullPath } }
  }

  const peran = sesi.user.value.peran

  // `meta.peran` hanya terbaca di rute induk, jadi diperiksa pada rute yang
  // cocok beserta seluruh induknya. Nilainya boleh satu peran atau daftar.
  const peranDibutuhkan = to.matched.map((r) => r.meta?.peran).filter(Boolean)[0]

  if (peranDibutuhkan) {
    const diizinkan = Array.isArray(peranDibutuhkan)
      ? peranDibutuhkan.includes(peran)
      : peranDibutuhkan === peran

    if (!diizinkan) {
      return halamanMuka(peran)
    }
  }

  // Pembatasan yang lebih sempit daripada peran induknya - mis. halaman
  // pengelola di dalam `/pemilik`. Diperiksa terpisah karena rute induk sudah
  // menerima dua peran, sehingga tanpa ini pengelola bisa membukanya.
  const peranSaja = to.matched.map((r) => r.meta?.peranSaja).filter(Boolean)[0]

  if (peranSaja && peran !== peranSaja) {
    return halamanMuka(peran)
  }

  return true
})

export default router
