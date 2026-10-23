<script setup>
/**
 * Panel bersusun: kartu dengan judul dan deretan tombol di kanannya.
 *
 * Elemen akarnya sengaja tetap `<section class="kartu panel">` — bukan `<div>` —
 * karena skrip E2E mencari panel lewat `document.querySelectorAll('section.kartu')`
 * dan `.kartu.panel`. Mengganti elemen akar akan mematahkan uji tanpa
 * mengubah tampilan sama sekali, jadi perhatikan itu sebelum menyentuh berkas ini.
 *
 * Keterangan di bawah judul diisi lewat slot, bukan prop bertipe String, karena
 * setiap panel memakai ukuran teks yang berbeda: panel terbit massal memakai
 * `.bantuan` (12,5px, paling kecil), panel rincian memakai `.lemah.mungil`.
 * Menyeragamkannya di sini berarti satu panel berubah tampilannya tanpa alasan.
 */
defineProps({
  /** Judul panel; bila kosong seluruh `.panel-kepala` tidak dirender. */
  judul: { type: String, default: '' },
  /** Kelas tambahan, mis. `panel-massal`. */
  kelas: { type: [String, Array, Object], default: '' },
})
</script>

<template>
  <section class="kartu panel" :class="kelas">
    <div v-if="judul" class="panel-kepala">
      <div>
        <h2>{{ judul }}</h2>
        <slot name="keterangan" />
      </div>
      <slot name="aksi" />
    </div>
    <slot />
  </section>
</template>
