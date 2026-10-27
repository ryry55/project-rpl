<script setup>
/**
 * Gambar QRIS yang diambil dengan token, lalu ditampilkan sebagai blob.
 *
 * **Kenapa bukan tag gambar biasa dengan `src`.** Rute itu dijaga
 * `auth:sanctum`, dan tag gambar tidak bisa mengirim header `Authorization` -
 * peramban hanya mengirim cookie, sedangkan API ini memakai Bearer token.
 * Memaksakan `src` langsung akan menghasilkan 401 yang tampil sebagai ikon
 * rusak, tanpa pesan apa pun.
 *
 * Tiga jalan keluarnya, dan dua di antaranya ditolak:
 *
 *   1. **Token di query string** (`?token=...`). Ditolak: token masuk ke log
 *      akses nginx, ke riwayat peramban, dan ke header `Referer` saat gambar
 *      diklik. Token yang bocor lewat log adalah token yang dipakai orang lain.
 *   2. **Rute publik tanpa token.** Ditolak: gambar QRIS tujuan pembayaran
 *      sebuah kos tidak boleh bisa diambil siapa pun yang menebak id-nya.
 *   3. **Ambil lewat `fetch` ber-token, jadikan blob.** Dipakai di sini.
 *
 * Biayanya: gambar tidak ikut cache peramban seperti tag gambar biasa. Untuk
 * satu kode QR yang dibuka sekali saat membayar, itu pertukaran yang benar.
 */
import { onBeforeUnmount, ref, watch } from 'vue'
import { sesi } from '../sesi.js'

const props = defineProps({
  /** Path relatif dari API, mis. `/rekening/3/gambar`. */
  path: { type: String, required: true },
  /** Teks alternatif. Kode QR tanpa teks alt tidak terbaca pembaca layar. */
  alt: { type: String, default: 'Gambar QRIS' },
})

const alamat = ref('')
const galat = ref('')
const memuat = ref(false)

/**
 * Blob URL yang sedang dipakai, disimpan supaya bisa dicabut.
 *
 * `URL.createObjectURL` menahan seluruh isi berkas di memori sampai halaman
 * ditutup bila tidak dicabut. Untuk gambar 400 kB yang dibuka berulang, itu
 * menjadi kebocoran yang tidak terlihat sampai tab menjadi berat.
 */
let alamatAktif = ''

function cabut() {
  if (alamatAktif) {
    URL.revokeObjectURL(alamatAktif)
    alamatAktif = ''
  }
}

async function muat() {
  cabut()
  alamat.value = ''
  galat.value = ''

  if (!props.path) {
    return
  }

  memuat.value = true
  try {
    const res = await fetch(`${sesi.apiDasar}${props.path}`, {
      headers: {
        Accept: 'image/*',
        ...(sesi.token.value ? { Authorization: `Bearer ${sesi.token.value}` } : {}),
      },
    })

    if (!res.ok) {
      // 404 di sini berarti gambarnya memang tidak ada, bukan kegagalan
      // jaringan. Membedakannya penting: yang pertama tidak perlu dicoba ulang.
      galat.value =
        res.status === 404
          ? 'Gambar QRIS tidak tersedia.'
          : 'Gambar QRIS gagal dimuat.'
      return
    }

    const blob = await res.blob()
    alamatAktif = URL.createObjectURL(blob)
    alamat.value = alamatAktif
  } catch {
    galat.value = 'Gambar QRIS gagal dimuat.'
  } finally {
    memuat.value = false
  }
}

watch(() => props.path, muat, { immediate: true })
onBeforeUnmount(cabut)
</script>

<template>
  <span class="pembungkus-gambar-qris">
    <img v-if="alamat" :src="alamat" :alt="alt" class="gambar-qris" />
    <span v-else-if="memuat" class="lemah mungil">Memuat gambar…</span>
    <span v-else-if="galat" class="lemah mungil">{{ galat }}</span>
  </span>
</template>

<style scoped>
.pembungkus-gambar-qris {
  display: block;
}

/* Kode QR harus cukup besar untuk dipindai dari layar ponsel, dan tidak boleh
   terpotong: `object-fit: contain` menjaga seluruh kode tetap terlihat. Kode
   yang terpotong tidak bisa dipindai, dan pemilik tidak akan tahu bedanya. */
.gambar-qris {
  display: block;
  max-width: 220px;
  max-height: 220px;
  object-fit: contain;
  margin-top: 10px;
  background: #fff;
  border: 1px solid var(--garis);
  border-radius: var(--radius);
}
</style>
