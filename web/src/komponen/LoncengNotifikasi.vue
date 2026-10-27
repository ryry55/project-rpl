<script setup>
/**
 * Lonceng notifikasi in-app (T25).
 *
 * Satu komponen untuk dua tempat: tata letak pemilik/pengelola dan portal
 * penghuni. Endpoint `/notifikasi` sama untuk ketiga peran - isinya sudah
 * terbatas pada notifikasi milik akun pemanggil - jadi tidak ada alasan
 * menulis dua daftar yang akan berbeda perilaku.
 *
 * Empat keputusan:
 *
 *   1. **Jumlah belum dibaca diambil dari `meta.belum_dibaca`, bukan dari
 *      panjang daftar.** Daftar dibatasi 30 baris; menghitung dari situ akan
 *      berhenti bertambah setelah tiga puluh dan pemilik mengira tidak ada lagi
 *      yang baru.
 *
 *   2. **Tidak ada polling berkala.** Aplikasi ini dipakai beberapa menit sehari
 *      oleh satu orang, bukan puluhan orang sepanjang hari. Polling 30 detik
 *      akan menghabiskan kuota Render gratis, dan di instans yang tidur setiap
 *      15 menit ia hanya menghasilkan permintaan yang membangunkan server.
 *      Dimuat saat halaman dibuka dan saat lonceng diklik.
 *
 *   3. **Menandai terbaca tidak menghapus baris.** Kotak masuk yang menghapus
 *      notifikasi setelah dibaca membuat orang kehilangan catatan justru saat
 *      ia ingin memeriksa ulang apa yang terjadi kemarin.
 *
 *   4. **Kegagalan memuat tidak ditampilkan sebagai galat merah.** Lonceng
 *      adalah pelengkap; halaman yang sedang dibuka tetap berguna. Galatnya
 *      muncul sebagai catatan kecil di dalam panel.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { sesi } from '../sesi.js'
import { pesanGalat } from '../format.js'

const router = useRouter()

const daftar = ref([])
const belumDibaca = ref(0)
const terbuka = ref(false)
const memuat = ref(false)
const galat = ref('')
const menandai = ref(false)

const adaBaru = computed(() => belumDibaca.value > 0)

async function muat() {
  memuat.value = true
  galat.value = ''
  try {
    const hasil = await sesi.panggil('/notifikasi?batas=30')
    daftar.value = hasil.data || []
    belumDibaca.value = hasil.meta?.belum_dibaca ?? 0
  } catch (e) {
    galat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

async function bukaTutup() {
  terbuka.value = !terbuka.value

  // Dimuat ulang setiap kali dibuka: notifikasi baru lahir dari tindakan orang
  // lain, dan lonceng yang hanya memuat sekali saat halaman dibuka akan
  // menampilkan keadaan beberapa menit lalu.
  if (terbuka.value) {
    await muat()
  }
}

async function tandaiSatu(n) {
  if (n.dibaca_pada) {
    return
  }

  // Diperbarui di layar lebih dulu, lalu dikirim. Menunggu server berarti satu
  // perjalanan jaringan untuk setiap klik, padahal hasilnya sudah pasti.
  n.dibaca_pada = new Date().toISOString()
  belumDibaca.value = Math.max(0, belumDibaca.value - 1)

  try {
    await sesi.panggil(`/notifikasi/${n.id}/baca`, { method: 'POST' })
  } catch {
    // Gagal menandai terbaca bukan alasan menampilkan galat: yang salah hanya
    // lencananya, bukan isinya. Dimuat ulang saat panel dibuka berikutnya.
  }
}

async function tandaiSemua() {
  if (belumDibaca.value === 0) {
    return
  }

  menandai.value = true
  const sekarang = new Date().toISOString()

  for (const n of daftar.value) {
    if (!n.dibaca_pada) {
      n.dibaca_pada = sekarang
    }
  }
  belumDibaca.value = 0

  try {
    await sesi.panggil('/notifikasi/baca-semua', { method: 'POST' })
  } catch (e) {
    galat.value = pesanGalat(e)
    await muat()
  } finally {
    menandai.value = false
  }
}

/**
 * Membuka tautan notifikasi.
 *
 * Tautan disimpan server sebagai jalur SPA (`/pemilik/rekap`, `/penghuni`),
 * jadi dipakai lewat router - bukan `<a href>`, yang akan memuat ulang seluruh
 * aplikasi dan kehilangan sesi di memori.
 */
async function ikuti(n) {
  await tandaiSatu(n)

  if (n.tautan) {
    terbuka.value = false
    router.push(n.tautan)
  }
}

function waktu(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''

  const selisih = (Date.now() - d.getTime()) / 1000

  if (selisih < 60) return 'baru saja'
  if (selisih < 3600) return `${Math.floor(selisih / 60)} menit lalu`
  if (selisih < 86400) return `${Math.floor(selisih / 3600)} jam lalu`
  if (selisih < 604800) return `${Math.floor(selisih / 86400)} hari lalu`

  return new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
  }).format(d)
}

onMounted(muat)
</script>

<template>
  <div class="lonceng-bungkus">
    <button
      type="button"
      class="lonceng"
      :class="{ berdenyut: adaBaru }"
      :aria-label="adaBaru ? `${belumDibaca} notifikasi belum dibaca` : 'Notifikasi'"
      :aria-expanded="terbuka"
      @click="bukaTutup"
    >
      <svg class="ikon" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true" focusable="false">
        <path
          fill="none"
          stroke="currentColor"
          stroke-width="1.9"
          stroke-linecap="round"
          stroke-linejoin="round"
          d="M18 8a6 6 0 1 0-12 0c0 5-2 6-2 6h16s-2-1-2-6M10.3 20a2 2 0 0 0 3.4 0"
        />
      </svg>
      <span>Notifikasi</span>
      <span v-if="adaBaru" class="jumlah">{{ belumDibaca > 99 ? '99+' : belumDibaca }}</span>
    </button>

    <!-- Panel ditutup dengan tombol yang sama; tidak ada penutup transparan
         layar penuh, karena panel ini tidak memblokir halaman. -->
    <section v-if="terbuka" class="panel-lonceng" aria-label="Daftar notifikasi">
      <header class="kepala-lonceng">
        <h2>Notifikasi</h2>
        <button
          type="button"
          class="halus mungil"
          :disabled="menandai || belumDibaca === 0"
          @click="tandaiSemua"
        >
          Tandai semua terbaca
        </button>
      </header>

      <p v-if="galat" class="pesan-galat mungil">{{ galat }}</p>
      <p v-else-if="memuat" class="lemah mungil pad">Memuat.</p>

      <ul v-else-if="daftar.length > 0" class="daftar-lonceng">
        <li v-for="n in daftar" :key="n.id" :class="{ belum: !n.dibaca_pada }">
          <button type="button" class="satu-lonceng" @click="ikuti(n)">
            <span class="judul-lonceng">{{ n.judul || n.label_peristiwa || 'Pemberitahuan' }}</span>
            <span class="pesan-lonceng mungil">{{ n.pesan }}</span>
            <span class="waktu-lonceng mungil lemah">{{ waktu(n.dibuat_pada) }}</span>
          </button>
        </li>
      </ul>

      <p v-else class="lemah mungil pad">Belum ada notifikasi.</p>
    </section>
  </div>
</template>

<style scoped>
.lonceng-bungkus {
  position: relative;
}

.lonceng {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 7px 12px;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--teks-lemah);
  background: transparent;
  border: 1px solid var(--garis);
  border-radius: var(--radius-kecil, 3px);
}

.lonceng:hover {
  color: var(--teks);
  border-color: var(--teks-lemah);
  background: var(--permukaan-turun, #f4eddd);
}

.lonceng.berdenyut {
  color: var(--aksen);
  background: var(--aksen-lemah);
  border-color: var(--aksen);
}

.ikon {
  display: block;
  flex: none;
  line-height: 1;
}

.jumlah {
  display: grid;
  place-items: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  font-size: 11px;
  font-weight: 700;
  color: var(--permukaan);
  background: var(--aksen);
  border-radius: 999px;
}

.panel-lonceng {
  position: absolute;
  right: 0;
  top: calc(100% + 8px);
  z-index: 30;
  width: 340px;
  max-width: calc(100vw - 32px);
  max-height: 420px;
  overflow-y: auto;
  padding: 12px;
  background: var(--permukaan);
  border: 1px solid var(--garis);
  border-radius: var(--radius);
  box-shadow: var(--bayang);
}

.kepala-lonceng {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 8px;
}

.kepala-lonceng h2 {
  margin: 0;
  font-size: 13.5px;
}

.pad {
  margin: 0;
  padding: 6px 2px;
}

.daftar-lonceng {
  display: grid;
  gap: 2px;
  margin: 0;
  padding: 0;
  list-style: none;
}

/* Notifikasi yang belum dibaca ditandai garis kiri, bukan latar penuh: latar
   penuh pada banyak baris membuat panelnya terbaca sebagai satu blok warna dan
   judulnya hilang. */
.daftar-lonceng li.belum {
  border-left: 2px solid var(--aksen);
}

.daftar-lonceng li:not(.belum) {
  border-left: 2px solid transparent;
}

.satu-lonceng {
  display: grid;
  gap: 1px;
  width: 100%;
  padding: 7px 10px;
  font: inherit;
  text-align: left;
  color: inherit;
  background: none;
  border: none;
  border-radius: 7px;
  cursor: pointer;
}

.satu-lonceng:hover {
  background: var(--permukaan-turun);
}

.judul-lonceng {
  font-size: 12.5px;
  font-weight: 600;
}

.pesan-lonceng {
  color: var(--teks-lemah);
  line-height: 1.4;
}

.waktu-lonceng {
  opacity: 0.85;
}

@media (max-width: 520px) {
  .panel-lonceng {
    position: fixed;
    left: 12px;
    right: 12px;
    top: auto;
    bottom: 12px;
    width: auto;
  }
}
</style>
