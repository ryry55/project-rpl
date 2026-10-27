<script setup>
/**
 * Satu medan formulir: label, kendali, dan keterangan di bawahnya.
 *
 * Kendalinya tetap ditulis di view lewat slot, bukan dioper sebagai prop.
 * Alasannya: kendali di aplikasi ini beragam (`input` dengan `type` berbeda,
 * `select` dengan opsi dari server, `textarea`) dan menyamakannya di dalam
 * komponen berarti menambah satu lapis prop untuk setiap variasi. Yang
 * dihilangkan di sini hanya bagian yang benar-benar berulang: label, pesan
 * galat per bidang, dan teks bantuan yang saling meniadakan.
 *
 * Galat dan bantuan sengaja saling meniadakan. Menampilkan keduanya sekaligus
 * membuat pengguna membaca "tidak boleh melewati hari ini" tepat di bawah
 * "tanggal tidak boleh di masa depan" — dua kalimat yang maksudnya sama.
 */
defineProps({
  /** Harus sama dengan `id` kendali di dalam slot, supaya label terhubung. */
  id: { type: String, required: true },
  label: { type: String, required: true },
  /** Pesan galat validasi untuk medan ini. */
  galat: { type: String, default: '' },
  /** Teks bantuan; hanya tampil bila tidak ada galat. */
  bantuan: { type: String, default: '' },
})
</script>

<template>
  <div>
    <label :for="id">{{ label }} <slot name="label-ekstra" /></label>
    <slot />
    <p v-if="galat" class="galat-bidang">{{ galat }}</p>
    <p v-else-if="bantuan || $slots.bantuan" class="bantuan">
      <slot name="bantuan">{{ bantuan }}</slot>
    </p>
  </div>
</template>
