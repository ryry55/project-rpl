<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter, useRoute, RouterLink, RouterView } from 'vue-router'
import { sesi } from '../sesi.js'
import LoncengNotifikasi from './LoncengNotifikasi.vue'

const router = useRouter()
const route = useRoute()

/**
 * Menu sidebar.
 *
 * `wewenang` adalah izin yang dibutuhkan untuk MEMBUKA halaman itu, bukan izin
 * untuk memakainya. Halaman yang hanya menampilkan data dibiarkan terbuka
 * untuk semua pengelola, karena membaca daftar kamar bukan tindakan yang
 * merugikan siapa pun - dan pengelola yang mencatat pembayaran memang harus
 * melihat nama penghuni.
 *
 * Halaman yang isinya formulir selalu ditutup wewenang: menampilkan formulir
 * yang pasti ditolak membuat pengelola mengira aplikasinya rusak.
 *
 * `hanyaPemilik` dipakai untuk halaman akun dan izin. Pemberian wewenang tidak
 * bisa didelegasikan, jadi tidak ada wewenang yang bisa membukanya.
 */
const semuaMenu = [
  { nama: 'kamar', label: 'Kamar', ket: 'Daftar kamar dan tarifnya' },
  { nama: 'penghuni', label: 'Penghuni', ket: 'Data penghuni dan akun masuk' },
  { nama: 'kontrak', label: 'Kontrak', ket: 'Penghuni menempati kamar' },
  { nama: 'tagihan', label: 'Tagihan', ket: 'Terbitkan dan periksa tagihan' },
  // Rekap setelah Tagihan dan sebelum Tunggakan: ia menjawab "berapa yang
  // benar-benar masuk", pertanyaan yang muncul tepat setelah tagihan terbit.
  // Tunggakan menjawab pertanyaan yang berlawanan arah (siapa yang belum
  // membayar), jadi keduanya sengaja tidak berdampingan.
  { nama: 'rekap', label: 'Rekap', ket: 'Uang masuk per metode bayar' },
  { nama: 'tunggakan', label: 'Tunggakan', ket: 'Siapa menunggak dan sudah separah apa' },
  // Diletakkan setelah Tunggakan, bukan di paling atas: pemilik membuka menu
  // ini sekali di awal lalu jarang lagi, sementara Tagihan dibuka setiap bulan.
  // Fasilitas dan Aturan menyusul di sini karena alasan yang sama - keduanya
  // diisi sekali saat kos dibuka, bukan setiap bulan.
  { nama: 'kebijakan', label: 'Kebijakan', ket: 'Satuan sewa dan jatuh tempo kos ini', wewenang: 'ubah_kebijakan' },
  { nama: 'fasilitas', label: 'Fasilitas', ket: 'Apa saja yang dimiliki tiap kamar', wewenang: 'kelola_kamar' },
  { nama: 'aturan', label: 'Aturan kos', ket: 'Yang dibaca penghuni di portalnya', wewenang: 'kelola_kamar' },
  { nama: 'audit', label: 'Jejak audit', ket: 'Siapa mengubah apa, kapan', wewenang: 'baca_audit' },
  // Persetujuan diletakkan setelah Jejak audit, bukan di atas: halamannya kosong
  // pada sebagian besar hari, dan menu yang selalu ada tetapi hampir selalu
  // kosong akan terlatih untuk diabaikan. Lencananya yang memberi tahu kapan
  // halaman ini perlu dibuka.
  //
  // Terbuka untuk pengelola juga: server menyaring isinya menjadi usulan milik
  // pemanggil, sehingga pengelola melihat nasib usulannya sendiri di sini.
  // Tombol keputusannya hanya dirender untuk pemilik.
  {
    nama: 'persetujuan',
    label: 'Persetujuan',
    ket: 'Usulan perubahan tarif dan kebijakan',
  },
  // Terbuka untuk semua yang punya kotak masuk: mengatur cara diri sendiri
  // diberi tahu bukan tindakan atas data kos.
  {
    nama: 'pengaturan-notifikasi',
    label: 'Pengaturan notifikasi',
    ket: 'Peristiwa mana yang dikirim ke Anda',
  },
  // Paling bawah karena dibuka paling jarang: sekali saat menambah orang,
  // lalu tidak lagi.
  { nama: 'pengelola', label: 'Pengelola', ket: 'Siapa membantu dan boleh apa', hanyaPemilik: true },
]

const menu = computed(() =>
  semuaMenu.filter((m) => {
    if (m.hanyaPemilik) {
      return sesi.user.value?.peran === 'pemilik'
    }

    if (!m.wewenang) {
      return true
    }

    return sesi.boleh(m.wewenang)
  }),
)

/**
 * Jumlah usulan yang menunggu keputusan pemilik.
 *
 * Diambil sekali saat tata letak dipasang, bukan dari daftar usulan yang
 * kebetulan sedang terbuka. Lencana menu harus benar dari halaman mana pun -
 * termasuk dari halaman yang tidak berhubungan dengan persetujuan, dan justru
 * di sanalah pemilik perlu tahu ada yang menunggu.
 *
 * Hanya untuk pemilik: pengelola tidak bisa memutuskan, dan lencana yang tidak
 * bisa dikerjakan hanya menimbulkan rasa bersalah tanpa jalan keluar.
 */
const jumlahMenunggu = ref(0)

async function muatJumlahMenunggu() {
  if (sesi.user.value?.peran !== 'pemilik') {
    return
  }

  try {
    const hasil = await sesi.panggil('/usulan?status=menunggu')
    jumlahMenunggu.value = hasil.meta?.jumlah_menunggu ?? 0
  } catch {
    // Lencana adalah pelengkap. Gagal memuatnya tidak boleh menampilkan galat
    // di halaman yang sedang dibuka pemilik.
    jumlahMenunggu.value = 0
  }
}

onMounted(muatJumlahMenunggu)

/**
 * Sebutan peran di bawah nama kos.
 *
 * Pengelola melihat "Pengelola", bukan "Pemilik": kalau layar menyebutnya
 * pemilik, ia akan mengira boleh mengubah tarif - dan baru tahu tidak setelah
 * menekan tombol yang tidak ada.
 */
const sebutanPeran = computed(() => {
  const peran = sesi.user.value?.peran
  if (peran === 'pengelola') {
    return 'Pengelola'
  }

  return 'Pemilik'
})

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
          <p class="mungil lemah">{{ sebutanPeran }}</p>
        </div>
      </div>

      <LoncengNotifikasi />

      <nav class="menu">
        <RouterLink
          v-for="m in menu"
          :key="m.nama"
          :to="{ name: `pemilik-${m.nama}` }"
          class="tautan"
          :class="{ aktif: route.name === `pemilik-${m.nama}` }"
        >
          <span class="tautan-label">
            {{ m.label }}
            <span v-if="m.nama === 'persetujuan' && jumlahMenunggu > 0" class="tautan-jumlah">
              {{ jumlahMenunggu }}
            </span>
          </span>
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

        <!-- Data pribadi (T28) untuk pemilik dan pengelola. Diletakkan di kaki
             samping, bukan di menu: hak atas data sendiri bukan pekerjaan atas
             kos, dan menu yang dibuka setiap hari tidak perlu memuatnya. -->
        <RouterLink :to="{ name: 'pengaturan-pdp' }" class="tautan-data mungil">
          Data pribadi saya
        </RouterLink>
        <RouterLink
          :to="{ name: 'atur-sandi', query: { dari: 'sesi' } }"
          class="tautan-data mungil"
        >
          Ganti kata sandi
        </RouterLink>
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
  /* `min-width: 0` wajib di sini juga. Grid item memakai `min-width: auto`,
     jadi menu mendatar yang isinya `max-content` membuat `.sisi` melebar
     melewati layar - dan luberannya keluar ke dokumen, bukan ke `.menu` yang
     sudah punya `overflow-x: auto`. */
  min-width: 0;
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

/* Lencana jumlah menunggu. Bentuknya bulat dan kecil supaya tidak terbaca
   sebagai bagian dari nama menu; yang dibaca mata adalah namanya, dan angkanya
   hanya menempel di ujung. */
.tautan-jumlah {
  display: inline-grid;
  place-items: center;
  min-width: 17px;
  height: 17px;
  padding: 0 5px;
  margin-left: 5px;
  font-size: 10.5px;
  font-weight: 700;
  color: #fff;
  background: var(--bahaya);
  border-radius: 999px;
  vertical-align: 1px;
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

.tautan-data {
  padding: 0 4px;
  color: var(--teks-lemah);
  text-align: left;
  text-decoration: none;
}

.tautan-data:hover {
  color: var(--aksen);
}

@media (max-width: 860px) {
  .tata-letak {
    /* `minmax(0, 1fr)`, bukan `1fr`: minimum `auto` membuat kolom selebar
       min-content, dan menu mendatar yang tidak muat memaksa seluruh halaman
       bergulir mendatar. Dengan 0, menu yang menggulir sendiri di dalam
       `.menu` (yang memang `overflow-x: auto`), bukan halamannya. */
    grid-template-columns: minmax(0, 1fr);
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
