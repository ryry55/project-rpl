import { createRouter, createWebHistory } from 'vue-router'
import { sesi } from './sesi.js'
import LoginView from './views/LoginView.vue'
import DashboardPemilik from './views/DashboardPemilik.vue'
import DashboardPenghuni from './views/DashboardPenghuni.vue'

const routes = [
  { path: '/', redirect: '/masuk' },
  { path: '/masuk', name: 'masuk', component: LoginView, meta: { publik: true } },
  {
    path: '/pemilik',
    name: 'pemilik',
    component: DashboardPemilik,
    meta: { peran: 'pemilik' },
  },
  {
    path: '/penghuni',
    name: 'penghuni',
    component: DashboardPenghuni,
    meta: { peran: 'penghuni' },
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

  if (to.meta.peran && sesi.user.value.peran !== to.meta.peran) {
    return { name: sesi.user.value.peran === 'pemilik' ? 'pemilik' : 'penghuni' }
  }

  return true
})

export default router
