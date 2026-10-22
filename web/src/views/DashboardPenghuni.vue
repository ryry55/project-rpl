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
          <h1>Portal Penghuni</h1>
          <p class="lemah mungil">{{ sesi.user.value?.nama }}</p>
        </div>
      </div>
      <button class="halus" @click="keluar">Keluar</button>
    </header>

    <main class="isi">
      <section class="kartu panel">
        <h2>Selamat datang</h2>
        <p class="lemah mungil">
          Di sini nanti Anda bisa melihat tagihan bulanan dan riwayat pembayaran Anda
          sendiri. Belum ada tagihan pada tahap ini.
        </p>
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

.panel {
  padding: 20px 22px;
}

.panel h2 {
  margin-bottom: 8px;
}
</style>
