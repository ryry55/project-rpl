<script setup>
import { ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { sesi } from '../sesi.js'

const route = useRoute()
const router = useRouter()

// Petunjuk akun demo hanya untuk build demo.
//
// Sebelumnya baris ini tampil tanpa syarat. Di produksi itu bukan sekadar
// keterangan salah tempat: ia mengundang siapa pun mencoba `pemilik@kos.test`
// dengan sandi `password`, dan `docs/uji/smoke-produksi.sh` memang memeriksa
// bahwa akun itu mati. Menyembunyikannya di sini membuat build produksi
// berhenti mengiklankan pintu yang seharusnya terkunci.
//
// Nilainya datang dari `VITE_MODE_DEMO` saat build, bukan ditebak saat jalan:
// halaman masuk belum punya sesi, jadi tidak ada API terautentikasi yang bisa
// ditanyai apakah mode demo aktif. Default-nya mati — lupa mengeset variabel
// berarti petunjuk tidak muncul, bukan sebaliknya.
const MODE_DEMO = import.meta.env.VITE_MODE_DEMO === 'true'

const email = ref('')
const password = ref('')
const galat = ref('')
const galatField = ref({})

/**
 * Pesan sekali-tampil dari halaman lain.
 *
 * Dipakai setelah penghapusan identitas (T28): akunnya sudah tidak ada, jadi
 * layar masuk tidak bisa menjelaskan apa pun lewat sesi - satu-satunya jalan
 * adalah membawa penjelasannya di alamat.
 */
const pesan = ref(
  route.query.pesan === 'dihapus'
    ? 'Identitas Anda sudah dilepas dari catatan kos ini. Riwayat pembayaran tetap tersimpan tanpa nama.'
    : '',
)

async function kirim() {
  galat.value = ''
  galatField.value = {}
  pesan.value = ''

  try {
    const user = await sesi.masuk(email.value, password.value)

    // `lanjut` dihormati bila ada, supaya pengguna yang terlempar ke sini dari
    // halaman tertentu kembali ke halaman itu. Tanpa `lanjut`, `beranda`
    // memilih halaman muka sesuai peran - pengelola tidak lagi mendarat di
    // Kamar, halaman yang justru tidak boleh ia ubah.
    //
    // Gerbang wajib-ganti-sandi dan gerbang PDP diperiksa penjaga router
    // SETELAH pengalihan ini, jadi keduanya tetap berlaku di sini.
    router.replace(route.query.lanjut || { name: 'beranda' })

    return user
  } catch (e) {
    galat.value = e.message
    galatField.value = e.errors || {}
  }
}
</script>

<template>
  <div class="halaman-masuk">
    <div class="kartu kotak">
      <header class="kepala-masuk">
        <div class="lencana-masuk">KOS</div>
        <h1>Masuk</h1>
        <p class="lemah mungil">Sistem Informasi Operasional Kos-Kosan</p>
      </header>

      <form @submit.prevent="kirim">
        <div class="bidang">
          <label for="email">Email</label>
          <input
            id="email"
            v-model="email"
            type="email"
            autocomplete="username"
            placeholder="nama@kos.test"
            :aria-invalid="Boolean(galatField.email)"
          />
        </div>

        <div class="bidang">
          <label for="password">Kata sandi</label>
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="current-password"
            placeholder="••••••••"
            :aria-invalid="Boolean(galatField.password)"
          />
        </div>

        <p v-if="pesan" class="pesan-sukses" role="status">{{ pesan }}</p>

        <p v-if="galat" class="pesan-galat" role="alert">{{ galat }}</p>

        <button type="submit" class="lebar" :disabled="sesi.sedangMemuat.value">
          {{ sesi.sedangMemuat.value ? 'Memeriksa…' : 'Masuk' }}
        </button>
      </form>

      <p class="lupa">
        <RouterLink :to="{ name: 'lupa-sandi' }" class="tautan-teks">
          Lupa kata sandi?
        </RouterLink>
      </p>

      <div v-if="MODE_DEMO" class="petunjuk lemah mungil">
        <p>Akun demo</p>
        <p class="deret-akun">
          <code>pemilik@kos.test</code>
          <code>penghuni@kos.test</code>
          <code>password</code>
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Halaman masuk tidak memakai .halaman global: ia bukan tata letak bersusun
   seperti halaman lain, melainkan satu kotak di tengah layar. Kelasnya diberi
   akhiran -masuk supaya tidak bertabrakan dengan versi global. */
.halaman-masuk {
  display: grid;
  place-items: center;
  min-height: 100%;
  padding: 24px;
}

.kotak {
  width: 100%;
  max-width: 380px;
  padding: 28px;
}

.kepala-masuk {
  margin-bottom: 22px;
}

.lencana-masuk {
  display: inline-grid;
  place-items: center;
  width: 38px;
  height: 38px;
  margin-bottom: 14px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.1em;
  color: var(--permukaan);
  background: var(--teks);
  border-radius: var(--radius-kecil);
}

.kepala-masuk h1 {
  margin-bottom: 4px;
}

.bidang {
  margin-bottom: 14px;
}

.lebar {
  width: 100%;
  margin-top: 4px;
}

.pesan-galat {
  margin: 0 0 14px;
}

/* "Lupa kata sandi?" adalah tautan, bukan tindakan utama.
 *
 * Sebelum ini ia memakai `.tombol-tautan` — biru penuh, selebar tombol Masuk.
 * Dua bidang biru sebesar itu di satu kartu membuat mata tidak tahu mana yang
 * harus ditekan lebih dulu, dan yang benar-benar tindakan utama (Masuk) justru
 * tidak menonjol. Sekarang ia memakai `.tautan-teks` global (style.css),
 * sejajar kanan di bawah tombol — perlakuan yang sama dengan "Kembali ke
 * halaman masuk" di layar lupa sandi.
 */
.lupa {
  margin: 12px 0 0;
  text-align: right;
}

.petunjuk {
  margin: 18px 0 0;
  padding-top: 16px;
  border-top: 1px solid var(--garis);
  text-align: center;
}

.petunjuk p {
  margin: 0;
}

/* Deret akun demo.
 *
 * Dibuat flex-wrap, bukan kalimat yang dibiarkan membungkus sendiri. Terukur:
 * saat ditulis sebagai satu kalimat, chip `password` terlempar ke baris ketiga
 * sendirian di kartu 380px — terlihat seperti kesalahan tata letak. Dengan
 * flex, ketiganya duduk sebaris dan hanya patah bila memang tidak muat, dengan
 * jarak yang sama rata.
 */
.deret-akun {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 5px 10px;
  margin-top: 5px;
}

code {
  font-size: 12px;
  padding: 1px 5px;
  color: var(--teks);
  background: var(--permukaan-turun);
  border: 1px solid var(--garis-lemah);
  border-radius: var(--radius-kecil);
}

/* Chip kode di tengah kalimat.
 *
 * Versi sebelumnya menambahkan `margin` pada setiap `code` untuk memberi jarak
 * dari kata sebelumnya. Itu salah sasaran: masalahnya bukan jarak, melainkan
 * bahwa chip diikuti tanda baca — padding chip membuat titik penutup kalimat
 * tampak terpisah ("password ."). Kalimatnya kini disusun ulang supaya chip
 * tidak pernah diikuti tanda baca, jadi tidak ada aturan jarak yang dibutuhkan.
 *
 * Yang tersisa hanya satu hal: chip tidak boleh terbelah dua baris.
 */
.petunjuk code {
  white-space: nowrap;
}
</style>
