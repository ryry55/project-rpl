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
  //
  // Sejak REV-SEC-06 halaman ini bernama berbeda menurut peran: pemilik melihat
  // rekap pendapatan kos, pengelola melihat uang tunai miliknya sendiri. Satu
  // rute, dua pertanyaan - dan label yang menyebut "Rekap" kepada pengelola
  // akan menjanjikan angka yang tidak diberikan server.
  { nama: 'rekap', label: 'Rekap', ket: 'Uang masuk per metode bayar', labelPengelola: 'Tunai saya', ketPengelola: 'Uang tunai yang Anda pegang' },
  { nama: 'tunggakan', label: 'Tunggakan', ket: 'Siapa menunggak dan sudah separah apa' },
  // Diletakkan setelah Tunggakan, bukan di paling atas: pemilik membuka menu
  // ini sekali di awal lalu jarang lagi, sementara Tagihan dibuka setiap bulan.
  // Fasilitas dan Aturan menyusul di sini karena alasan yang sama - keduanya
  // diisi sekali saat kos dibuka, bukan setiap bulan.
  { nama: 'kebijakan', label: 'Kebijakan', ket: 'Satuan sewa dan jatuh tempo kos ini', wewenang: 'ubah_kebijakan' },
  // Rekening diletakkan tepat setelah Kebijakan: keduanya menjawab "bagaimana
  // kos ini menagih", dan rekening adalah pertanyaan lanjutannya - "ke mana
  // uangnya dikirim".
  //
  // `hanyaPemilik`, bukan `wewenang`. Nomor rekening adalah alamat uang masuk:
  // pengelola yang boleh menggantinya bisa mengarahkan seluruh setoran penghuni
  // ke rekeningnya sendiri, lalu menandai tagihannya lunas dengan wewenang
  // `catat_pembayaran` yang memang ia punya. Tidak ada pemeriksaan lain yang
  // menangkapnya, jadi tidak ada wewenang yang bisa membukanya.
  { nama: 'rekening', label: 'Rekening', ket: 'Ke mana penghuni mentransfer', hanyaPemilik: true },
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
  // Bantuan diletakkan paling akhir dan tanpa `wewenang`: ia bukan tindakan
  // atas data kos, dan justru dibutuhkan orang yang wewenangnya terbatas -
  // pengelola yang menerima penolakan atas hal yang bukan urusannya.
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
      <!-- Merek dan lonceng dibungkus satu baris. Di ponsel keduanya dulu
           bertumpuk, dan bersama menu serta blok akun mereka menghabiskan
           360px dari 800px layar sebelum konten pertama muncul. -->
      <div class="kepala-sisi">
        <div class="merek">
          <span class="lencana">KOS</span>
          <div>
            <strong>Kos-Kosan</strong>
            <p class="mungil lemah">{{ sebutanPeran }}</p>
          </div>
        </div>

        <LoncengNotifikasi />
      </div>

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
        <div class="baris-akun">
          <div class="akun">
            <span class="avatar">{{ inisial || 'P' }}</span>
            <div class="akun-teks">
              <strong class="mungil">{{ sesi.user.value?.nama }}</strong>
              <span class="mungil lemah">{{ sesi.user.value?.email }}</span>
            </div>
          </div>
          <button class="halus mungil" @click="keluar">Keluar</button>
        </div>

        <!-- Data pribadi (T28) untuk pemilik dan pengelola. Diletakkan di kaki
             samping, bukan di menu: hak atas data sendiri bukan pekerjaan atas
             kos, dan menu yang dibuka setiap hari tidak perlu memuatnya.
             Keduanya disejajarkan satu baris di ponsel supaya tidak menambah
             dua baris tinggi kepala. -->
        <div class="tautan-akun">
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
  /* Hanya kolom isi yang boleh menggulir; sidebar tinggal diam. Tanpa ini,
     sidebar ikut setinggi DOKUMEN (terukur 1204px pada layar 900px), dan blok
     akun beserta tombol Keluar — yang diletakkan di kaki sidebar — terdorong
     ke bawah garis pandang. Pemilik harus menggulir ke dasar halaman hanya
     untuk menemukan tombol keluar. */
  height: 100vh;
  overflow: hidden;
}

/* Sidebar bertiga baris: kepala (tetap), menu (bergulir sendiri), kaki
 * (menempel di dasar).
 *
 * Terukur sebelum perbaikan ini: 15 item menu dua baris = 979px, ditambah
 * kepala 67px dan kaki 110px = 1231px. Pada layar 900px kaki terdorong 331px
 * ke bawah lipatan — dan itu terjadi juga pada 1080px, karena isinya memang
 * selalu 1231px. Akibatnya tombol Keluar dan tautan "Data pribadi saya"
 * hanya bisa ditemukan dengan menggulir seluruh menu lebih dulu.
 *
 * Dengan grid tiga baris, hanya baris menu yang menggulir; kaki tidak pernah
 * ikut berpindah. Sidebar yang menggulir sebagai satu kesatuan adalah
 * penyebabnya, jadi memperkecil padding menu hanya akan menunda cacatnya.
 */
.sisi {
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  gap: 18px;
  padding: 20px 16px;
  background: var(--permukaan);
  border-right: 1px solid var(--garis);
  /* `min-width: 0` wajib di sini juga. Grid item memakai `min-width: auto`,
     jadi menu mendatar yang isinya `max-content` membuat `.sisi` melebar
     melewati layar. */
  min-width: 0;
  /* `min-height: 0` mengizinkan baris menu menyusut di bawah tinggi isinya,
     sehingga `overflow-y` di `.menu` benar-benar bekerja. Tanpa ini baris
     `1fr` tetap selebar isinya dan tidak pernah menggulir. */
  min-height: 0;
}

.merek {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 0 4px;
  /* Merek tidak boleh menyusut: nama "Kos-Kosan" terukur terbelah menjadi dua
     baris ("Kos-" / "Kosan") pada sidebar 244px karena tombol Notifikasi
     mengambil ruangnya. Yang boleh menyusut adalah teks peran di bawahnya. */
  min-width: 0;
  flex: none;
}

/* Nama produk tidak boleh terbelah. Ia dua kata berhubung yang sudah menjadi
   satu merek — memenggalnya di tanda hubung membuat kepala sidebar terbaca
   sebagai dua baris keterangan, bukan nama. */
.merek strong {
  display: block;
  white-space: nowrap;
}

.merek p {
  margin: 0;
}

/* Lonceng menyusut lebih dulu daripada merek, karena labelnya bisa dipendekkan
   tanpa kehilangan arti. */
.kepala-sisi > :last-child {
  min-width: 0;
  flex-shrink: 0;
}

/* Di sidebar, lonceng tampil sebagai ikon saja.
 *
 * Terukur: tombol berlabel "Notifikasi" memakan 128px dari 212px ruang kepala
 * sidebar 244px, sehingga nama produk terbelah menjadi "Kos-" / "Kosan" dan
 * terbaca sebagai dua baris keterangan, bukan sebagai nama. Label itu juga
 * mubazir di sini — ikon lonceng sudah menyatakan hal yang sama, dan
 * `aria-label` pada tombolnya tetap membawa teks untuk pembaca layar.
 */
.kepala-sisi :deep(.lonceng > span:not(.jumlah)) {
  display: none;
}

.kepala-sisi :deep(.lonceng) {
  padding: 7px 9px;
}

/* Merek di kiri, lonceng di kanan. Di ponsel keduanya harus sebaris: kalau
   bertumpuk, kepala memakan lebih dari sepertiga layar sebelum konten mulai. */
.kepala-sisi {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.lencana {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.1em;
  color: var(--permukaan);
  background: var(--teks);
  border-radius: var(--radius-kecil);
}

.menu {
  display: grid;
  gap: 3px;
  align-content: start;
  /* Menu yang lebih tinggi dari ruang tersedia menggulir di dalam dirinya
     sendiri, bukan mendorong kaki keluar layar. */
  overflow-y: auto;
  overscroll-behavior: contain;
  min-height: 0;
  /* Ruang untuk bilah gulir supaya nama menu tidak tertutup saat menu
     menggulir. `scrollbar-gutter` memesan ruangnya sejak awal, jadi label
     tidak bergeser saat bilah muncul. */
  scrollbar-gutter: stable;
  padding-right: 2px;
}

.menu::-webkit-scrollbar {
  width: 8px;
}

.menu::-webkit-scrollbar-thumb {
  background: var(--garis);
  border-radius: 999px;
}

.menu::-webkit-scrollbar-track {
  background: transparent;
}

.tautan {
  display: grid;
  gap: 1px;
  padding: 8px 11px;
  color: var(--teks-lemah);
  text-decoration: none;
  border-left: 2px solid transparent;
  border-radius: 0 var(--radius-kecil) var(--radius-kecil) 0;
  transition: background-color 120ms ease, color 120ms ease,
    border-color 120ms ease;
}

.tautan:hover {
  background: var(--permukaan-turun);
  color: var(--teks);
}

/* Halaman aktif ditandai garis tebal di tepi kiri — bahasa yang sama dengan
   tab pemisah katalog, tanpa mengubah menu menjadi tab rail. */
.tautan.aktif {
  color: var(--aksen);
  background: var(--aksen-lemah);
  border-left-color: var(--aksen);
}

.tautan-label {
  font-size: 14px;
  font-weight: 600;
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
  /* Baris ketiga sudah `auto`, jadi kaki selalu menempel di dasar tanpa
     `margin-top: auto`. */
  display: grid;
  gap: 10px;
  padding-top: 14px;
  border-top: 1px solid var(--garis);
}

/* Akun di kiri, tombol Keluar di kanan. `min-width: 0` pada teks akun
   mengizinkan nama panjang terpotong elipsis alih-alih mendorong tombolnya
   keluar dari layar. */
.baris-akun {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  min-width: 0;
}

/* Dua tautan akun dalam satu deret, dipisah titik tengah. */
.tautan-akun {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 14px;
  padding: 0 4px;
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
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  color: var(--teks-lemah);
  background: var(--permukaan-turun);
  border: 1px solid var(--garis);
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
  /* Kolom isi yang menggulir, bukan dokumen. Pasangan dari `height: 100vh` di
     `.tata-letak`. */
  overflow-y: auto;
  overscroll-behavior: contain;
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
    /* Di layar sempit sidebar bukan lagi kolom samping yang tetap: ia kepala
       yang ikut menggulir bersama isi. `100vh` + `overflow: hidden` di sini
       akan mengurung isi halaman dalam kotak yang tidak bisa dicapai. */
    height: auto;
    overflow: visible;
  }

  .sisi {
    gap: 12px;
    border-right: none;
    border-bottom: 1px solid var(--garis);
    /* Di layar sempit sidebar bukan kolom setinggi layar lagi: ia kepala
       biasa yang ikut menggulir bersama isi. Tiga baris `auto minmax(0,1fr)
       auto` di sini akan mengurung menu di kotak mati. */
    grid-template-rows: auto;
    min-height: 0;
  }

  .utama {
    overflow-y: visible;
  }

  .menu {
    grid-auto-flow: column;
    grid-auto-columns: max-content;
    overflow-x: auto;
    overflow-y: visible;
    align-content: stretch;
    padding-right: 0;
    scrollbar-gutter: auto;
    /* Batang gulir disembunyikan: di ponsel ia memakan tinggi dan tampil
       sebagai garis abu-abu yang tidak menjelaskan apa pun. Menu tetap bisa
       digeser; yang hilang hanya penandanya. */
    scrollbar-width: none;
  }

  .menu::-webkit-scrollbar {
    display: none;
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

/* Ponsel: kepala dipadatkan.
 *
 * Terukur sebelum perbaikan ini: pada viewport 360x800, kepala menghabiskan
 * 360px - hampir setengah layar - sebelum judul halaman pertama muncul. Yang
 * memakan tempat: merek dan lonceng bertumpuk (77px), menu, lalu blok akun
 * empat baris (160px).
 *
 * Pemadatan di sini bukan sekadar mengecilkan: email disembunyikan karena ia
 * hanya perlu dibaca sekali saat mendaftar, tombol Keluar dinaikkan sebaris
 * dengan avatar, dan dua tautan akun dijadikan satu baris kecil. Semuanya
 * tetap ada dan tetap bisa ditekan.
 */
@media (max-width: 620px) {
  .sisi {
    padding: 14px 12px;
    gap: 10px;
  }

  .kaki {
    padding-top: 10px;
    gap: 8px;
  }

  /* Avatar + nama + Keluar dalam satu baris. `min-width: 0` pada teks
     mengizinkan nama panjang terpotong elipsis alih-alih mendorong tombol
     keluar dari layar. */
  .akun {
    gap: 8px;
    padding: 0;
  }

  .akun-teks strong {
    font-size: 12.5px;
  }

  /* Email disembunyikan, bukan diperkecil: alamat surel milik sendiri tidak
     membantu apa pun saat sedang bekerja, dan barisnya menambah tinggi kepala
     di setiap halaman. */
  .akun-teks .lemah {
    display: none;
  }

  /* Dua tautan akun dijadikan satu deret mendatar yang rapat. */
  .tautan-akun {
    padding: 0;
    gap: 2px 12px;
  }

  .tautan-data {
    padding: 0;
    font-size: 12px;
  }

  .sisi :deep(.lonceng) {
    padding: 6px 10px;
  }
}
</style>
