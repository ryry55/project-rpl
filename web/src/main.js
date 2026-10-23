import { createApp } from 'vue'
import App from './App.vue'
import router from './router.js'
import kartuTabel from './direktif/kartu-tabel.js'
import './style.css'

const app = createApp(App).use(router)

// Direktif global: dipakai sebagai `v-kartu-tabel` pada elemen `<table>`.
// Global, bukan impor per berkas, karena ia dipakai di hampir setiap layar
// pemilik - mengimpornya 12 kali berarti 12 kesempatan untuk lupa.
app.directive('kartu-tabel', kartuTabel)

app.mount('#app')

/**
 * Mendaftarkan service worker, kalau lingkungannya mendukung.
 *
 * Dua penjagaan, keduanya disengaja:
 *
 * 1. **Hanya di produksi.** Saat `npm run dev`, Vite menyajikan modul dari
 *    memori dan service worker akan menyajikan versi basi yang tidak pernah
 *    cocok dengan berkas di disk. Yang paling menjengkelkan: perubahan tidak
 *    terlihat, dan tidak ada yang memberi tahu kenapa.
 * 2. **Kegagalan tidak menghentikan aplikasi.** Pemasangan bisa gagal karena
 *    peramban menolak (mode privat, kebijakan organisasi, penyimpanan penuh).
 *    Semua itu bukan alasan membuat aplikasi tidak bisa dipakai - PWA adalah
 *    tambahan, bukan syarat.
 */
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch((galat) => {
      console.warn('Service worker gagal didaftarkan:', galat)
    })
  })
}
