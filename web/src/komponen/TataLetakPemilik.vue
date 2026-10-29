<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter, useRoute, RouterLink, RouterView } from 'vue-router'
import { sesi } from '../sesi.js'
import LoncengNotifikasi from './LoncengNotifikasi.vue'
import IkonMenu from './ui/IkonMenu.vue'

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
  // Kelompok Uang: pertanyaan yang muncul berurutan setiap bulan. Tagihan
  // lebih dulu, lalu "berapa yang benar-benar masuk" (Rekap), lalu pertanyaan
  // berlawanan arah (Tunggakan), lalu ke mana uangnya dikirim (Rekening).
  { nama: 'tagihan', label: 'Tagihan', ket: 'Terbitkan dan periksa tagihan', grup: 'Uang' },
  // Sejak REV-SEC-06 halaman ini bernama berbeda menurut peran: pemilik melihat
  // rekap pendapatan kos, pengelola melihat uang tunai miliknya sendiri. Satu
  // rute, dua pertanyaan - dan label yang menyebut "Rekap" kepada pengelola
  // akan menjanjikan angka yang tidak diberikan server.
  { nama: 'rekap', label: 'Rekap', ket: 'Uang masuk per metode bayar', labelPengelola: 'Tunai saya', ketPengelola: 'Uang tunai yang Anda pegang', grup: 'Uang' },
  { nama: 'tunggakan', label: 'Tunggakan', ket: 'Siapa menunggak dan sudah separah apa', grup: 'Uang' },
  // `hanyaPemilik`, bukan `wewenang`. Nomor rekening adalah alamat uang masuk:
  // pengelola yang boleh menggantinya bisa mengarahkan seluruh setoran penghuni
  // ke rekeningnya sendiri, lalu menandai tagihannya lunas dengan wewenang
  // `catat_pembayaran` yang memang ia punya. Tidak ada pemeriksaan lain yang
  // menangkapnya, jadi tidak ada wewenang yang bisa membukanya.
  { nama: 'rekening', label: 'Rekening', ket: 'Ke mana penghuni mentransfer', grup: 'Uang', hanyaPemilik: true },
  // Kelompok Kos: diisi sekali saat kos dibuka, bukan setiap bulan.
  { nama: 'kebijakan', label: 'Kebijakan', ket: 'Satuan sewa dan jatuh tempo kos ini', grup: 'Kos', wewenang: 'ubah_kebijakan' },
  { nama: 'fasilitas', label: 'Fasilitas', ket: 'Apa saja yang dimiliki tiap kamar', grup: 'Kos', wewenang: 'kelola_kamar' },
  { nama: 'aturan', label: 'Aturan kos', ket: 'Yang dibaca penghuni di portalnya', grup: 'Kos', wewenang: 'kelola_kamar' },
  // Kelompok Akses: siapa membantu, apa yang menunggu keputusan, dan apa yang
  // sudah terjadi.
  { nama: 'pengelola', label: 'Pengelola', ket: 'Siapa membantu dan boleh apa', grup: 'Akses', hanyaPemilik: true },
  // Persetujuan diletakkan setelah Pengelola, bukan di atas: halamannya kosong
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
    grup: 'Akses',
  },
  { nama: 'audit', label: 'Jejak audit', ket: 'Siapa mengubah apa, kapan', grup: 'Akses', wewenang: 'baca_audit' },
  // Terbuka untuk semua yang punya kotak masuk: mengatur cara diri sendiri
  // diberi tahu bukan tindakan atas data kos.
  {
    nama: 'pengaturan-notifikasi',
    label: 'Pengaturan notifikasi',
    ket: 'Peristiwa mana yang dikirim ke Anda',
    grup: 'Akses',
  },
  // Bantuan tanpa `wewenang`: ia bukan tindakan atas data kos, dan justru
  // dibutuhkan orang yang wewenangnya terbatas - pengelola yang menerima
  // penolakan atas hal yang bukan urusannya.
  { nama: 'bantuan', label: 'Bantuan', ket: 'Kenapa ini tidak bisa saya lakukan' },
]

const menu = computed(() =>
  semuaMenu
    .filter((m) => {
      if (m.hanyaPemilik) {
        return sesi.user.value?.peran === 'pemilik'
      }

      if (!m.wewenang) {
        return true
      }

      return sesi.boleh(m.wewenang)
    })
    // Label yang berbeda menurut peran dipakai bila ada. Hanya `rekap` yang
    // memilikinya sekarang: halaman yang sama menjawab pertanyaan berbeda untuk
    // pemilik dan pengelola (REV-SEC-06), jadi satu label tidak bisa benar
    // untuk keduanya.
    .map((m) =>
      sesi.user.value?.peran === 'pengelola' && m.labelPengelola
        ? { ...m, label: m.labelPengelola, ket: m.ketPengelola }
        : m,
    ),
)

/**
 * Tiga tujuan di navigasi bawah layar sempit (Fase 5.1).
 *
 * Diambil dari `menu` yang sudah tersaring, bukan ditulis ulang: label per
 * peran ("Tunai saya"), wewenang, dan nama rutenya harus tetap satu sumber.
 * Yang dipilih adalah pekerjaan bulanan - terbitkan dan periksa tagihan,
 * siapa menunggak, berapa yang masuk. Sisanya lewat tombol Menu.
 */
const menuBawah = computed(() =>
  ['tagihan', 'tunggakan', 'rekap']
    .map((nama) => menu.value.find((m) => m.nama === nama))
    .filter(Boolean),
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
 * Menu ponsel.
 *
 * Di layar sempit sidebar menjadi laci yang menutupi isi. Tiga hal yang mudah
 * terlewat dan membuat laci terasa rusak: fokus tidak pindah ke dalamnya,
 * latar tetap bisa digulir di belakangnya, dan laci tidak menutup setelah
 * navigasi. Ketiganya ditangani di bawah.
 */
const menuTerbuka = ref(false)
const tombolTutup = ref(null)
const sempit = window.matchMedia('(max-width: 900px)')

// Lonceng dirender di bilah atas pada layar sempit, dan di kepala sidebar pada
// layar lebar. Hanya SATU yang boleh ada: dua instance punya state sendiri, dan
// menandai notifikasi terbaca di panel yang satu tidak mengubah lencana yang
// lain - lencana di bilah atas yang tersembunyi tetap menghitung.
const layarSempit = ref(sempit.matches)

/** Label kelompok hanya dirender pada item pertama kelompoknya. */
const grupBaru = (i) => i === 0 || menu.value[i - 1].grup !== menu.value[i].grup

async function bukaMenu() {
  menuTerbuka.value = true
  await nextTick()
  tombolTutup.value?.focus()
}

function tutupMenu() {
  menuTerbuka.value = false
}

function saatTombol(e) {
  if (e.key === 'Escape' && menuTerbuka.value) {
    tutupMenu()
  }
}

function saatLebar(e) {
  layarSempit.value = e.matches

  if (!e.matches) {
    tutupMenu()
  }
}

watch(() => route.fullPath, tutupMenu)

watch(menuTerbuka, (buka) => {
  document.body.style.overflow = buka ? 'hidden' : ''
})

onMounted(() => {
  document.addEventListener('keydown', saatTombol)
  sempit.addEventListener('change', saatLebar)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', saatTombol)
  sempit.removeEventListener('change', saatLebar)
  document.body.style.overflow = ''
})

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
    <!-- Bilah atas hanya untuk layar sempit. Judul halaman sudah ditulis
         sebagai `h1` di dalamnya, jadi bilah ini menyebut produk - kalau ia
         menyebut halaman juga, nama yang sama muncul dua kali bertumpuk. -->
    <header class="bilah-atas">
      <span class="judul-bilah">Kos-Kosan</span>
      <div class="bilah-aksi">
        <LoncengNotifikasi v-if="layarSempit" />
        <button
          type="button"
          class="halus tombol-menu"
          aria-label="Buka menu"
          aria-controls="menu-utama"
          :aria-expanded="menuTerbuka"
          @click="bukaMenu"
        >
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M4 7h16M4 12h16M4 17h16" />
          </svg>
        </button>
      </div>
    </header>

    <aside id="menu-utama" class="sisi" :class="{ terbuka: menuTerbuka }">
      <div class="merek">
        <span class="lencana">KK</span>
        <div class="merek-teks">
          <strong>Kos-Kosan</strong>
          <p class="mungil lemah">{{ sebutanPeran }}</p>
        </div>
        <button
          ref="tombolTutup"
          type="button"
          class="halus tutup-menu"
          @click="tutupMenu"
        >
          Tutup
        </button>
        <LoncengNotifikasi v-if="!layarSempit" />
      </div>

      <nav class="menu">
        <template v-for="(m, i) in menu" :key="m.nama">
          <p v-if="m.grup && grupBaru(i)" class="grup">{{ m.grup }}</p>
          <RouterLink
            :to="{ name: `pemilik-${m.nama}` }"
            class="tautan"
            :class="{ aktif: route.name === `pemilik-${m.nama}` }"
          >
            <IkonMenu :nama="m.nama" />
            <span class="tautan-label">
              {{ m.label }}
              <span v-if="m.nama === 'persetujuan' && jumlahMenunggu > 0" class="tautan-jumlah">
                {{ jumlahMenunggu }}
              </span>
            </span>
          </RouterLink>
        </template>
      </nav>

      <div class="kaki">
        <div class="akun">
          <span class="avatar">{{ inisial || 'P' }}</span>
          <div class="akun-teks">
            <strong class="mungil">{{ sesi.user.value?.nama }}</strong>
            <span class="mungil lemah">{{ sesi.user.value?.email }}</span>
          </div>
          <button class="halus mungil keluar" @click="keluar">Keluar</button>
        </div>

        <!-- Data pribadi (T28) untuk pemilik dan pengelola. Hak atas data
             sendiri bukan pekerjaan atas kos, jadi tidak masuk menu utama. -->
        <div class="tautan-akun">
          <RouterLink :to="{ name: 'pengaturan-pdp' }" class="tautan-data">
            Data pribadi saya
          </RouterLink>
          <RouterLink
            :to="{ name: 'atur-sandi', query: { dari: 'sesi' } }"
            class="tautan-data"
          >
            Ganti kata sandi
          </RouterLink>
        </div>
      </div>
    </aside>

    <div v-if="menuTerbuka" class="tirai" @click="tutupMenu"></div>

    <main class="utama">
      <RouterView />
    </main>

    <!-- Navigasi bawah, hanya di layar sempit. Laci sidebar tetap ada untuk
         seluruh menu; tiga tautan di sini adalah pekerjaan yang dibuka tiap
         bulan, supaya tidak perlu dua ketukan untuk hal yang paling sering. -->
    <nav v-if="layarSempit" class="bawah" aria-label="Navigasi cepat">
      <RouterLink
        v-for="m in menuBawah"
        :key="m.nama"
        :to="{ name: `pemilik-${m.nama}` }"
        class="bawah-tautan"
        :class="{ aktif: route.name === `pemilik-${m.nama}` }"
      >
        <IkonMenu :nama="m.nama" />
        <span class="bawah-label">{{ m.label }}</span>
      </RouterLink>
      <button
        type="button"
        class="bawah-tautan"
        aria-label="Semua menu"
        aria-controls="menu-utama"
        :aria-expanded="menuTerbuka"
        @click="bukaMenu"
      >
        <IkonMenu nama="menu" />
        <span class="bawah-label">Menu</span>
      </button>
    </nav>
  </div>
</template>

<style scoped>
.tata-letak {
  display: grid;
  grid-template-columns: 256px minmax(0, 1fr);
  min-height: 100vh;
  min-height: 100svh;
}

/* Sidebar duduk langsung di latar halaman, bukan di atas bidang berwarna.
 * Tiga baris: merek, menu yang menggulir sendiri, kaki. Hanya baris menu yang
 * boleh menggulir — tombol Keluar tidak boleh terdorong keluar layar. */
.sisi {
  position: sticky;
  top: 0;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  gap: 6px;
  height: 100vh;
  height: 100svh;
  padding: 18px 14px 14px;
  background: var(--bg);
  border-right: 1px solid var(--garis);
  min-width: 0;
  min-height: 0;
}

.merek {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 8px 12px;
  min-width: 0;
}

.merek-teks {
  min-width: 0;
}

/* Nama produk tidak boleh terbelah: dua kata berhubung yang sudah menjadi
   satu merek. */
.merek strong {
  display: block;
  font-size: 14px;
  font-weight: 500;
  line-height: 18px;
  white-space: nowrap;
}

.merek p {
  margin: 0;
  line-height: 16px;
}

.lencana {
  display: grid;
  place-items: center;
  flex: none;
  width: 28px;
  height: 28px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.02em;
  color: #fff;
  background: var(--teks);
  border-radius: 6px;
}

/* Lonceng tampil sebagai ikon saja di sidebar: label "Notifikasi" memakan
   separuh lebar 256px dan mendorong nama produk keluar. `aria-label` pada
   tombolnya tetap membawa teks untuk pembaca layar. */
.merek :deep(.lonceng > span:not(.jumlah)) {
  display: none;
}

.merek :deep(.lonceng) {
  margin-left: auto;
  padding: 6px;
  color: var(--teks-lemah);
  background: transparent;
  border-color: transparent;
}

.merek :deep(.lonceng:hover) {
  color: var(--teks);
  background: var(--permukaan-turun);
  border-color: var(--garis);
}

.merek :deep(.lonceng.berdenyut) {
  color: var(--teks);
  background: var(--aktif);
  border-color: var(--garis);
}

.menu {
  display: flex;
  flex-direction: column;
  gap: 1px;
  align-content: start;
  overflow-y: auto;
  overscroll-behavior: contain;
  min-height: 0;
  padding-right: 2px;
  scrollbar-gutter: stable;
}

.menu::-webkit-scrollbar {
  width: 8px;
}

.menu::-webkit-scrollbar-thumb {
  background: var(--kendali);
  border-radius: 999px;
}

.menu::-webkit-scrollbar-track {
  background: transparent;
}

.grup {
  margin: 13px 8px 4px;
  font-size: 12px;
  color: var(--teks-lemah);
}

.grup:first-child {
  margin-top: 2px;
}

.tautan {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 30px;
  padding: 0 8px;
  font-size: 14px;
  color: var(--teks-lemah);
  text-decoration: none;
  border-radius: var(--radius-kecil);
  transition: background-color 150ms ease, color 150ms ease;
}

.tautan:hover {
  background: var(--permukaan-turun);
  color: var(--teks);
}

/* Halaman aktif adalah pil abu, bukan garis tepi berwarna: kerangka ini tidak
   memakai warna untuk memberi tahu posisi. */
.tautan.aktif {
  background: var(--aktif);
  color: var(--teks);
  font-weight: 500;
}

.tautan-label {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tautan-jumlah {
  display: inline-grid;
  place-items: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  margin-left: 5px;
  font-size: 11px;
  font-weight: 600;
  color: #fff;
  background: var(--bahaya);
  border-radius: 999px;
  vertical-align: 1px;
}

.kaki {
  display: grid;
  gap: 10px;
  padding-top: 14px;
  border-top: 1px solid var(--garis-lemah);
}

.akun {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 4px;
  min-width: 0;
}

.avatar {
  display: grid;
  place-items: center;
  flex: none;
  width: 26px;
  height: 26px;
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.04em;
  color: var(--teks-lemah);
  background: var(--permukaan-turun);
  border: 1px solid var(--garis);
  border-radius: 999px;
}

.akun-teks {
  display: grid;
  flex: 1;
  min-width: 0;
}

.akun-teks span,
.akun-teks strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.keluar {
  flex: none;
}

.tautan-akun {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 14px;
  padding: 0 4px;
}

.tautan-data {
  padding: 0;
  font-size: 12px;
  color: var(--teks-lemah);
  text-decoration: none;
}

.tautan-data:hover {
  color: var(--teks);
  text-decoration: underline;
  text-underline-offset: 3px;
}

/* Bilah atas, laci, dan navigasi bawah hanya ada di layar sempit. */
.bilah-atas,
.bawah,
.tirai,
.tutup-menu {
  display: none;
}

.utama {
  min-width: 0;
  padding: 32px 32px 56px;
}

@media (max-width: 900px) {
  .tata-letak {
    display: block;
  }

  .bilah-atas {
    position: sticky;
    top: 0;
    z-index: 40;
    display: flex;
    align-items: center;
    gap: 10px;
    height: 52px;
    padding: 0 16px;
    background: var(--permukaan);
    border-bottom: 1px solid var(--garis);
  }

  .judul-bilah {
    font-size: 14px;
    font-weight: 500;
  }

  .bilah-aksi {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-left: auto;
  }

  .bilah-aksi :deep(.lonceng > span:not(.jumlah)) {
    display: none;
  }

  .bilah-aksi :deep(.lonceng) {
    padding: 6px;
    color: var(--teks-lemah);
    background: transparent;
    border-color: transparent;
  }

  .bilah-aksi :deep(.lonceng:hover) {
    color: var(--teks);
    background: var(--permukaan-turun);
    border-color: var(--garis);
  }

  .tombol-menu {
    width: 32px;
    height: 32px;
    min-height: 32px;
    padding: 0;
  }

  .tombol-menu svg {
    width: 16px;
    height: 16px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.5;
    stroke-linecap: round;
  }

  .sisi {
    position: fixed;
    inset: 0 0 0 auto;
    z-index: 60;
    width: min(320px, 86vw);
    height: auto;
    padding: 16px 14px 14px;
    background: var(--permukaan);
    border-right: none;
    border-left: 1px solid var(--garis);
    transform: translateX(100%);
    transition: transform 180ms ease;
  }

  .sisi.terbuka {
    transform: none;
  }

  .tirai {
    display: block;
    position: fixed;
    inset: 0;
    z-index: 50;
    background: rgba(10, 10, 10, 0.3);
  }

  /* Empat kolom sama lebar: tiga tujuan dan tombol Menu. Tingginya ditambah
     `safe-area-inset-bottom` supaya tidak tertutup bilah gestur ponsel. */
  .bawah {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    position: fixed;
    inset: auto 0 0;
    z-index: 45;
    padding-bottom: env(safe-area-inset-bottom);
    background: var(--permukaan);
    border-top: 1px solid var(--garis);
  }

  .bawah-tautan {
    display: grid;
    place-items: center;
    gap: 3px;
    min-width: 0;
    min-height: 44px;
    padding: 6px 2px;
    font-size: 11px;
    color: var(--teks-lemah);
    background: transparent;
    border: 0;
    border-radius: 0;
    text-decoration: none;
  }

  .bawah-tautan.aktif {
    color: var(--teks);
    background: var(--aktif);
    font-weight: 500;
  }

  /* Tanpa ini, `button:hover` global (latar aksen gelap) menang atas
     `.bawah-tautan`, dan tombol Menu berubah gelap dengan teks abu. */
  .bawah-tautan:hover {
    color: var(--teks);
    background: var(--permukaan-turun);
  }

  .bawah-label {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .merek {
    padding: 0 4px 10px;
  }

  .merek :deep(.lonceng) {
    display: none;
  }

  .tutup-menu {
    display: inline-flex;
    margin-left: auto;
    min-height: 30px;
    padding: 3px 10px;
    font-size: 12px;
  }

  /* Ruang bawah menyisakan tinggi navigasi, kalau tidak baris terakhir tabel
     tertutup dan tidak bisa ditekan. */
  .utama {
    padding: 18px 16px calc(70px + env(safe-area-inset-bottom));
  }
}

@media (prefers-reduced-motion: reduce) {
  .sisi {
    transition: none;
  }
}
</style>
