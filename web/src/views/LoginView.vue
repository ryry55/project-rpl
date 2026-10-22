<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { sesi } from '../sesi.js'

const route = useRoute()
const router = useRouter()

const email = ref('')
const password = ref('')
const galat = ref('')
const galatField = ref({})

async function kirim() {
  galat.value = ''
  galatField.value = {}

  try {
    const user = await sesi.masuk(email.value, password.value)
    const tujuan = route.query.lanjut || (user.peran === 'pemilik' ? '/pemilik' : '/penghuni')
    router.replace(tujuan)
  } catch (e) {
    galat.value = e.message
    galatField.value = e.errors || {}
  }
}
</script>

<template>
  <div class="halaman">
    <div class="kartu kotak">
      <header class="kepala">
        <div class="lencana">KOS</div>
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

        <p v-if="galat" class="pesan-galat" role="alert">{{ galat }}</p>

        <button type="submit" class="lebar" :disabled="sesi.sedangMemuat.value">
          {{ sesi.sedangMemuat.value ? 'Memeriksa…' : 'Masuk' }}
        </button>
      </form>

      <p class="petunjuk lemah mungil">
        Akun demo: <code>pemilik@kos.test</code> atau <code>penghuni@kos.test</code>,
        kata sandi <code>password</code>.
      </p>
    </div>
  </div>
</template>

<style scoped>
.halaman {
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

.kepala {
  margin-bottom: 22px;
}

.lencana {
  display: inline-grid;
  place-items: center;
  width: 38px;
  height: 38px;
  margin-bottom: 14px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  color: var(--aksen);
  background: var(--aksen-lemah);
  border-radius: 9px;
}

.kepala h1 {
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

.petunjuk {
  margin: 18px 0 0;
  padding-top: 16px;
  border-top: 1px solid var(--garis);
  text-align: center;
}

code {
  font-size: 12px;
  padding: 1px 5px;
  background: #f1f3f6;
  border-radius: 4px;
}
</style>
