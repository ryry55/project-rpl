<script setup>
/**
 * Halaman untuk alamat yang tidak dikenal.
 *
 * Tanpa ini, alamat yang salah ketik menghasilkan layar putih kosong: tidak ada
 * judul, tidak ada pesan, tidak ada jalan kembali. Orang yang mengalaminya tidak
 * bisa membedakan "alamatnya salah" dari "aplikasinya rusak" — dan satu-satunya
 * cara keluar adalah mengedit bilah alamat sendiri.
 *
 * Sengaja tidak menampilkan menu samping: menunya bergantung pada peran, dan
 * halaman ini juga muncul untuk orang yang belum masuk.
 */
import { computed } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { sesi } from '../sesi.js'

const route = useRoute()

// Alamat yang dituju ditampilkan apa adanya supaya salah ketiknya terlihat —
// menyembunyikannya membuat orang menebak-nebak apa yang salah.
const alamat = computed(() => route.fullPath)

// Tujuan pulang menyesuaikan peran; pengunjung yang belum masuk diarahkan ke
// halaman masuk, yang memang satu-satunya tempat yang berguna baginya.
const pulang = computed(() => {
  const peran = sesi.user.value?.peran
  if (peran === 'pemilik') return { name: 'pemilik-kamar', label: 'Kembali ke dasbor pemilik' }
  if (peran === 'penghuni') return { name: 'penghuni', label: 'Kembali ke tagihan saya' }
  return { name: 'masuk', label: 'Ke halaman masuk' }
})
</script>

<template>
  <div class="bingkai">
    <div class="kartu panel">
      <p class="kode mungil">404</p>
      <h1>Halaman tidak ditemukan</h1>
      <p class="lemah">
        Alamat <code>{{ alamat }}</code> tidak ada di aplikasi ini. Mungkin salah ketik, atau
        tautannya sudah tidak berlaku.
      </p>
      <RouterLink :to="pulang" class="tombol-pulang">
        {{ pulang.label }}
      </RouterLink>
    </div>
  </div>
</template>

<style scoped>
.bingkai {
  display: grid;
  place-items: center;
  min-height: 100vh;
  padding: 24px;
}

.panel {
  max-width: 440px;
  padding: 28px 30px;
}

.kode {
  margin: 0 0 6px;
  font-weight: 700;
  letter-spacing: 0.08em;
  color: var(--teks-lemah);
}

.panel h1 {
  margin: 0 0 8px;
  font-size: 20px;
}

.panel p {
  margin: 0 0 18px;
  line-height: 1.55;
}

code {
  padding: 1px 5px;
  font-size: 12.5px;
  background: var(--garis-lemah);
  border-radius: 5px;
  overflow-wrap: anywhere;
}

/* Tautan, bukan tombol: ia memang berpindah halaman, dan harus bisa dibuka di
   tab baru seperti tautan biasa. */
.tombol-pulang {
  display: inline-block;
  padding: 8px 16px;
  font-size: 13px;
  font-weight: 600;
  color: #fff;
  text-decoration: none;
  background: var(--aksen);
  border-radius: 8px;
}

.tombol-pulang:hover {
  filter: brightness(0.95);
}
</style>
