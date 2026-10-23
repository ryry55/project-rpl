<script setup>
/**
 * Pesan satu baris: galat, sukses, atau netral.
 *
 * Tiga jenis ini tersebar 24 kali di seluruh view sebagai `<p class="pesan-…">`.
 * Dibuat komponen karena bedanya bukan hanya warna: pesan galat perlu
 * `role="alert"` supaya pembaca layar mengumumkannya, sedangkan pesan netral
 * tidak boleh diumumkan — ia bukan keadaan yang perlu ditindak.
 *
 * Jenis `netral` dipakai untuk "tidak ada yang perlu dikerjakan". Mewarnainya
 * merah membuat pemilik mengira ada yang rusak padahal justru sedang baik.
 */
defineProps({
  /** `galat` | `sukses` | `netral`. */
  jenis: { type: String, default: 'netral' },
  /** Ukuran teks kecil, untuk pesan yang menemani tabel atau panel. */
  mungil: { type: Boolean, default: false },
  /** Atribut `role`; isi `alert` untuk galat yang harus diumumkan. */
  peran: { type: String, default: '' },
})
</script>

<template>
  <p :class="['pesan-' + jenis, { mungil }]" :role="peran || undefined">
    <slot />
  </p>
</template>
