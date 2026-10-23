import { createRouter, createWebHistory } from 'vue-router'
import { sesi } from './sesi.js'
import LoginView from './views/LoginView.vue'
import TataLetakPemilik from './komponen/TataLetakPemilik.vue'
import KamarView from './views/pemilik/KamarView.vue'
import PenghuniView from './views/pemilik/PenghuniView.vue'
import KontrakView from './views/pemilik/KontrakView.vue'
import TagihanView from './views/pemilik/TagihanView.vue'
import TunggakanView from './views/pemilik/TunggakanView.vue'
import AuditLogView from './views/pemilik/AuditLogView.vue'
import KebijakanView from './views/pemilik/KebijakanView.vue'
import DashboardPenghuni from './views/DashboardPenghuni.vue'
import TidakDitemukanView from './views/TidakDitemukanView.vue'

const routes = [
  { path: '/', redirect: '/masuk' },
  { path: '/masuk', name: 'masuk', component: LoginView, meta: { publik: true } },
  {
    path: '/pemilik',
    component: TataLetakPemilik,
    meta: { peran: 'pemilik' },
    children: [
      { path: '', redirect: { name: 'pemilik-kamar' } },
      { path: 'kamar', name: 'pemilik-kamar', component: KamarView },
      { path: 'penghuni', name: 'pemilik-penghuni', component: PenghuniView },
      { path: 'kontrak', name: 'pemilik-kontrak', component: KontrakView },
      { path: 'tagihan', name: 'pemilik-tagihan', component: TagihanView },
      { path: 'tunggakan', name: 'pemilik-tunggakan', component: TunggakanView },
      { path: 'kebijakan', name: 'pemilik-kebijakan', component: KebijakanView },
      { path: 'audit', name: 'pemilik-audit', component: AuditLogView },
    ],
  },
  {
    path: '/penghuni',
    name: 'penghuni',
    component: DashboardPenghuni,
    meta: { peran: 'penghuni' },
  },
  // Penangkap terakhir. Didaftarkan paling akhir supaya tidak menyerobot rute
  // mana pun; `meta.publik` membuat alamat yang salah ketik tidak dilempar ke
  // halaman masuk lebih dulu — halaman 404 justru yang menjelaskan masalahnya.
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

  // `meta.peran` hanya terbaca di rute induk, jadi diperiksa pada rute yang
  // cocok beserta seluruh induknya.
  const peranDibutuhkan = to.matched.map((r) => r.meta?.peran).filter(Boolean)[0]

  if (peranDibutuhkan && sesi.user.value.peran !== peranDibutuhkan) {
    return { name: sesi.user.value.peran === 'pemilik' ? 'pemilik' : 'penghuni' }
  }

  return true
})

export default router
