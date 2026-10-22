<script setup>
import { useRouter } from 'vue-router'
import { sesi } from '../sesi.js'

const router = useRouter()

async function keluar() {
  await sesi.keluar()
  router.replace('/masuk')
}
</script>

<template>
  <div class="bingkai">
    <header class="bar">
      <div class="merek">
        <span class="lencana">KOS</span>
        <div>
          <h1>Dashboard Pemilik</h1>
          <p class="lemah mungil">{{ sesi.user.value?.nama }}</p>
        </div>
      </div>
      <button class="halus" @click="keluar">Keluar</button>
    </header>

    <main class="isi">
      <section class="kartu panel">
        <h2>Kerangka aplikasi siap</h2>
        <p class="lemah mungil">
          Login, pemisahan peran, dan koneksi basis data sudah berjalan. Modul kamar,
          penghuni, tagihan, denda, dan tunggakan menyusul pada tiket berikutnya.
        </p>
      </section>

      <section class="kartu panel">
        <h2>Status</h2>
        <dl class="daftar">
          <div><dt>Peran</dt><dd>{{ sesi.user.value?.peran }}</dd></div>
          <div><dt>Email</dt><dd>{{ sesi.user.value?.email }}</dd></div>
          <div><dt>ID pengguna</dt><dd>{{ sesi.user.value?.id }}</dd></div>
        </dl>
      </section>
    </main>
  </div>
</template>

<style scoped>
.bingkai {
  max-width: 860px;
  margin: 0 auto;
  padding: 24px;
}

.bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 22px;
}

.merek {
  display: flex;
  align-items: center;
  gap: 12px;
}

.lencana {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: 0.06em;
  color: var(--aksen);
  background: var(--aksen-lemah);
  border-radius: 9px;
}

.isi {
  display: grid;
  gap: 16px;
}

.panel {
  padding: 20px 22px;
}

.panel h2 {
  margin-bottom: 8px;
}

.daftar {
  margin: 0;
}

.daftar > div {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding: 9px 0;
  border-bottom: 1px solid var(--garis);
}

.daftar > div:last-child {
  border-bottom: none;
}

dt {
  font-size: 13.5px;
  color: var(--teks-lemah);
}

dd {
  margin: 0;
  font-size: 13.5px;
  font-weight: 550;
}
</style>
