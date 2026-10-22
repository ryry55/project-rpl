/**
 * Tanggal simulasi untuk demo, dibagi antar-halaman.
 *
 * Disimpan di `localStorage`, bukan di state komponen, karena tanggal ini harus
 * berlaku lintas halaman: kalau halaman Tunggakan memakai tanggal berbeda dari
 * halaman Tagihan, dua layar akan menampilkan umur tunggakan yang berbeda untuk
 * tagihan yang sama — dan tidak ada cara tahu mana yang benar.
 *
 * Header ini hanya berlaku bila backend berjalan dengan `APP_DEMO_MODE=true`.
 * Bila tidak, server mengabaikannya; halaman yang memakainya harus membaca
 * `meta.simulasi` dari respons, bukan menebak dari ada/tidaknya nilai di sini.
 */
import { ref, computed } from 'vue'

const KUNCI = 'kos.simulasiTanggal'

const tanggal = ref(localStorage.getItem(KUNCI) || '')

/** Apakah pengguna sedang meminta tanggal simulasi. */
const aktif = computed(() => tanggal.value !== '')

/** Header tambahan untuk setiap permintaan selama simulasi aktif. */
function header() {
  return tanggal.value ? { 'X-Simulasi-Tanggal': tanggal.value } : {}
}

function pakai(nilai) {
  tanggal.value = nilai || ''
  if (tanggal.value) {
    localStorage.setItem(KUNCI, tanggal.value)
  } else {
    localStorage.removeItem(KUNCI)
  }
}

function bersihkan() {
  pakai('')
}

export const simulasiTanggal = {
  tanggal,
  aktif,
  header,
  pakai,
  bersihkan,
}
