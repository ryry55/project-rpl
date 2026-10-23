<script setup>
import { computed } from 'vue'
import { useRouter, useRoute, RouterLink, RouterView } from 'vue-router'
import { sesi } from '../sesi.js'

const router = useRouter()
const route = useRoute()

const menu = [
  { nama: 'kamar', label: 'Kamar', ket: 'Daftar kamar dan tarifnya' },
  { nama: 'penghuni', label: 'Penghuni', ket: 'Data penghuni dan akun masuk' },
  { nama: 'kontrak', label: 'Kontrak', ket: 'Penghuni menempati kamar' },
  { nama: 'tagihan', label: 'Tagihan', ket: 'Terbitkan dan periksa tagihan' },
  { nama: 'tunggakan', label: 'Tunggakan', ket: 'Siapa menunggak dan sudah separah apa' },
  // Diletakkan setelah Tunggakan, bukan di paling atas: pemilik membuka menu
  // ini sekali di awal lalu jarang lagi, sementara Tagihan dibuka setiap bulan.
  { nama: 'kebijakan', label: 'Kebijakan', ket: 'Satuan sewa dan jatuh tempo kos ini' },
  { nama: 'audit', label: 'Jejak audit', ket: 'Siapa mengubah apa, kapan' },
]

const inisial = computed(() => {
  const nama = sesi.user.value?.nama || ''
  return nama
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((k) => k[0].toUpperCase())
    .join('')
})

async function keluar() {
  await sesi.keluar()
  router.replace('/masuk')
}
</script>

<template>
  <div class="tata-letak">
    <aside class="sisi">
      <div class="merek">
        <span class="lencana">KOS</span>
        <div>
          <strong>Kos-Kosan</strong>
          <p class="mungil lemah">Pemilik</p>
        </div>
      </div>

      <nav class="menu">
        <RouterLink
          v-for="m in menu"
          :key="m.nama"
          :to="{ name: `pemilik-${m.nama}` }"
          class="tautan"
          :class="{ aktif: route.name === `pemilik-${m.nama}` }"
        >
          <span class="tautan-label">{{ m.label }}</span>
          <span class="tautan-ket mungil">{{ m.ket }}</span>
        </RouterLink>
      </nav>

      <div class="kaki">
        <div class="akun">
          <span class="avatar">{{ inisial || 'P' }}</span>
          <div class="akun-teks">
            <strong class="mungil">{{ sesi.user.value?.nama }}</strong>
            <span class="mungil lemah">{{ sesi.user.value?.email }}</span>
          </div>
        </div>
        <button class="halus mungil" @click="keluar">Keluar</button>
      </div>
    </aside>

    <main class="utama">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.tata-letak {
  display: grid;
  grid-template-columns: 244px minmax(0, 1fr);
  min-height: 100%;
}

.sisi {
  display: flex;
  flex-direction: column;
  gap: 22px;
  padding: 20px 16px;
  background: var(--permukaan);
  border-right: 1px solid var(--garis);
}

.merek {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 0 4px;
}

.merek p {
  margin: 0;
}

.lencana {
  display: grid;
  place-items: center;
  width: 34px;
  height: 34px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.06em;
  color: var(--aksen);
  background: var(--aksen-lemah);
  border-radius: 9px;
}

.menu {
  display: grid;
  gap: 3px;
}

.tautan {
  display: grid;
  gap: 1px;
  padding: 9px 11px;
  color: var(--teks-lemah);
  text-decoration: none;
  border-radius: 8px;
  transition: background 120ms ease, color 120ms ease;
}

.tautan:hover {
  background: #f6f7f9;
}

.tautan.aktif {
  color: var(--aksen);
  background: var(--aksen-lemah);
}

.tautan-label {
  font-size: 13.5px;
  font-weight: 570;
}

.tautan-ket {
  font-size: 11.5px;
  opacity: 0.82;
}

.tautan.aktif .tautan-ket {
  opacity: 0.9;
}

.kaki {
  margin-top: auto;
  display: grid;
  gap: 10px;
  padding-top: 14px;
  border-top: 1px solid var(--garis);
}

.akun {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 4px;
}

.avatar {
  display: grid;
  place-items: center;
  flex: none;
  width: 30px;
  height: 30px;
  font-size: 11.5px;
  font-weight: 650;
  color: var(--teks-lemah);
  background: #eef0f3;
  border-radius: 999px;
}

.akun-teks {
  display: grid;
  min-width: 0;
}

.akun-teks span,
.akun-teks strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.utama {
  padding: 26px 28px 40px;
  min-width: 0;
}

@media (max-width: 860px) {
  .tata-letak {
    grid-template-columns: 1fr;
  }

  .sisi {
    gap: 14px;
    border-right: none;
    border-bottom: 1px solid var(--garis);
  }

  .menu {
    grid-auto-flow: column;
    grid-auto-columns: max-content;
    overflow-x: auto;
  }

  .tautan-ket {
    display: none;
  }

  .kaki {
    margin-top: 0;
  }

  .utama {
    padding: 20px 16px 32px;
  }
}
</style>
