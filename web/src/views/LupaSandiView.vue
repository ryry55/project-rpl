<script setup>
/**
 * Minta tautan atur ulang kata sandi (T27).
 *
 * Satu keputusan bentuk yang menentukan seluruh layar ini: **jawabannya selalu
 * sama**, apakah emailnya terdaftar atau tidak. Server sudah menjamin itu, dan
 * layar ini tidak boleh merusaknya dengan kalimat seperti "email tidak
 * ditemukan" - kalimat yang menjadikan halaman ini alat untuk memeriksa alamat
 * mana yang punya akun di kos ini, padahal daftar penghuni adalah data pribadi.
 */
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { sesi } from '../sesi.js'

const email = ref('')
const terkirim = ref(false)
const menyimpan = ref(false)
const galat = ref('')
const galatBidang = ref({})

async function kirim() {
  galat.value = ''
  galatBidang.value = {}
  menyimpan.value = true

  try {
    await sesi.panggil('/lupa-sandi', {
      method: 'POST',
      body: JSON.stringify({ email: email.value.trim() }),
    })
    terkirim.value = true
  } catch (e) {
    // 429 tetap ditampilkan sebagai galat: pesan "terlalu banyak permintaan"
    // bukan kebocoran, karena ia tidak bergantung pada ada tidaknya akun.
    galat.value = e.message
    galatBidang.value = e.errors || {}
  } finally {
    menyimpan.value = false
  }
}
</script>

<template>
  <div class="halaman-masuk">
    <div class="kartu kotak">
      <header class="kepala-masuk">
        <div class="lencana-masuk">KOS</div>
        <h1>Lupa kata sandi</h1>
        <p class="lemah mungil">
          Masukkan email akun Anda. Kami akan mengirim tautan untuk mengatur kata sandi baru.
        </p>
      </header>

      <template v-if="!terkirim">
        <form @submit.prevent="kirim">
          <div class="bidang">
            <label for="email-lupa">Email</label>
            <input
              id="email-lupa"
              v-model="email"
              type="email"
              autocomplete="username"
              placeholder="nama@kos.test"
              :aria-invalid="Boolean(galatBidang.email)"
            />
            <p v-if="galatBidang.email" class="galat-bidang">{{ galatBidang.email }}</p>
          </div>

          <p v-if="galat" class="pesan-galat" role="alert">{{ galat }}</p>

          <button type="submit" class="lebar" :disabled="menyimpan">
            {{ menyimpan ? 'Mengirim…' : 'Kirim tautan' }}
          </button>
        </form>
      </template>

      <template v-else>
        <p class="pesan-sukses" role="status">
          Bila email itu terdaftar, tautan untuk mengatur kata sandi sudah dikirim. Periksa kotak
          masuk Anda - dan folder spam, karena surat dari sistem baru sering masuk ke sana.
        </p>
        <p class="lemah mungil">
          Tautan berlaku 60 menit dan hanya bisa dipakai sekali.
        </p>
      </template>

      <p class="kembali lemah mungil">
        <RouterLink :to="{ name: 'masuk' }" replace class="tautan-teks">
          Kembali ke halaman masuk
        </RouterLink>
      </p>
    </div>
  </div>
</template>

<style scoped>
.halaman-masuk {
  display: grid;
  place-items: center;
  min-height: 100%;
  padding: 24px;
}

.kotak {
  width: 100%;
  max-width: 400px;
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
  margin-bottom: 6px;
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

.kembali {
  margin: 18px 0 0;
  padding-top: 16px;
  border-top: 1px solid var(--garis);
  text-align: center;
}
</style>
