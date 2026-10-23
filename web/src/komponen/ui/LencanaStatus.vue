<script setup>
/**
 * Lencana status.
 *
 * Warnanya ditentukan oleh kelas global `status-<nilai>` di style.css, jadi
 * menambah status baru berarti menambah satu aturan warna di sana — bukan
 * menyentuh setiap view yang menampilkannya.
 *
 * Teksnya sengaja diturunkan dari nilai apa adanya (`belum_bayar` → "belum
 * bayar") alih-alih dari peta label. Peta label berarti setiap status baru
 * harus didaftarkan di sini juga, dan yang lupa didaftarkan akan tampil sebagai
 * nilai mentah bercampur label — persis yang sulit dikenali saat memeriksa layar.
 */
const props = defineProps({
  /** Nilai status dari API, mis. `lunas`, `belum_bayar`, `terisi`. */
  status: { type: String, required: true },
  /** Teks pengganti bila nilainya bukan kata yang layak ditampilkan. */
  label: { type: String, default: '' },
})

const teks = () => props.label || String(props.status).replace(/_/g, ' ')
</script>

<template>
  <span class="lencana-status" :class="`status-${status}`">{{ teks() }}</span>
</template>
