<script setup>
/**
 * Satu baris tabel yang memberitahu bahwa isinya kosong atau sedang dimuat.
 *
 * Ditulis sebagai komponen karena `colspan` yang salah adalah cacat yang tidak
 * menimbulkan galat: barisnya tetap tampil, hanya melebar atau menyempit tanpa
 * alasan yang terlihat. Dengan satu tempat, `kolom` bisa diperiksa sekilas
 * terhadap jumlah `<th>` di tabel yang bersangkutan.
 *
 * Elemen akarnya `<tr>` dan selnya tetap `.sel-kosong`; keduanya dipakai skrip
 * E2E saat menghitung baris tabel.
 */
defineProps({
  /** Jumlah kolom tabel yang ditempati. */
  kolom: { type: Number, required: true },
})
</script>

<template>
  <tr>
    <td :colspan="kolom" class="sel-kosong"><slot /></td>
  </tr>
</template>
