<script setup>
/**
 * Kepala halaman: judul, satu baris keterangan, dan tombol aksi.
 *
 * Sebelum ini pola yang sama ditulis ulang di enam view dengan markup yang
 * identik. Akibatnya `h1` kadang diikuti `<p class="lemah mungil">`, kadang
 * tidak, dan tombol aksi kadang dibungkus `.aksi-kepala`, kadang tidak —
 * perbedaan yang tidak disengaja dan tidak terlihat sampai dua halaman
 * dibandingkan berdampingan.
 *
 * `<h1>` tetap dirender apa adanya karena skrip E2E membaca
 * `document.querySelector('h1')` untuk memastikan halaman yang benar terbuka.
 */
defineProps({
  /** Judul halaman. Wajib: halaman tanpa judul tidak bisa dipastikan terbuka. */
  judul: { type: String, required: true },
})
</script>

<template>
  <header class="kepala">
    <div>
      <h1>{{ judul }}</h1>
      <p v-if="$slots.keterangan" class="lemah mungil"><slot name="keterangan" /></p>
    </div>
    <div v-if="$slots.aksi" class="aksi-kepala"><slot name="aksi" /></div>
  </header>
</template>
