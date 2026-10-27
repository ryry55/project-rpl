<script setup>
/**
 * Ikon menu sidebar.
 *
 * Satu komponen dengan peta jalur, bukan lima belas `<svg>` di dalam template
 * tata letak: nama menu dan ikonnya harus terus berpasangan, dan peta di satu
 * tempat membuat ikon yang lupa didaftarkan terlihat sebagai kotak kosong saat
 * dibandingkan berdampingan.
 *
 * Semua ikon digambar dengan satu jalur bergaris supaya ketebalannya seragam.
 * `aria-hidden` karena teks menu di sebelahnya sudah menyebut hal yang sama.
 */
const JALUR = {
  kamar: 'M4 21V5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v16M3 21h18M14 12h.01',
  penghuni:
    'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z',
  kontrak:
    'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5',
  tagihan: 'M6 2h12v20l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6',
  tunggakan: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 8v5M12 16h.01',
  rekap: 'M3 21h18M6 21v-7M11 21V8M16 21v-4M21 21V4',
  rekening: 'M3 10 12 4l9 6M5 10v8M12 10v8M19 10v8M3 21h18',
  kebijakan:
    'M4 6h8M16 6h4M4 12h4M12 12h8M4 18h10M18 18h2M16 6a2 2 0 1 1-4 0 2 2 0 0 1 4 0zM12 12a2 2 0 1 1-4 0 2 2 0 0 1 4 0zM18 18a2 2 0 1 1-4 0 2 2 0 0 1 4 0z',
  aturan:
    'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z',
  fasilitas: 'm12 2 9 5v10l-9 5-9-5V7zM3 7l9 5 9-5M12 12v10',
  pengelola:
    'M12 15a4 4 0 1 0-8 0 4 4 0 0 0 8 0zM11 12 19 4M17 4l3 3M14 7l3 3',
  persetujuan:
    'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM8.5 12.5l2.5 2.5 4.5-5',
  audit: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
  'pengaturan-notifikasi':
    'M18 8a6 6 0 1 0-12 0c0 5-2 6-2 6h16s-2-1-2-6M10.3 20a2 2 0 0 0 3.4 0',
  bantuan:
    'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM9.2 9a3 3 0 0 1 5.8 1c0 2-3 2.5-3 4M12 17h.01',
}

defineProps({
  /** Nama menu; harus punya entri di `JALUR`. */
  nama: { type: String, required: true },
})
</script>

<template>
  <svg class="ikon-menu" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
    <path :d="JALUR[nama]" />
  </svg>
</template>

<style scoped>
.ikon-menu {
  width: 16px;
  height: 16px;
  flex: none;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.5;
  stroke-linecap: round;
  stroke-linejoin: round;
  opacity: 0.85;
}
</style>
