<script setup>
/**
 * Penanda urutan penyiapan kos.
 *
 * **Masalah yang dijawab komponen ini.** Instalasi baru benar-benar kosong.
 * Pemilik yang baru pertama masuk melihat menu yang sama persis dengan kos yang
 * sudah berjalan setahun, lalu menekan hal pertama yang terlihat menarik.
 * Seringkali yang ditekan lebih dulu adalah "Tambah kamar", karena itu yang
 * paling jelas. Ia lalu membuat kontrak, menekan "Terbitkan tagihan", dan
 * menerima penolakan:
 *
 *     Kebijakan kos belum lengkap: satuan sewa belum ditetapkan.
 *
 * Pesan itu benar, tetapi datang setelah ia mengetik satu kontrak penuh. Yang
 * tidak dikatakan pesan itu adalah **bahwa kebijakan seharusnya diisi lebih
 * dulu**, dan itulah satu-satunya hal yang perlu ia ketahui.
 *
 * **Kenapa urutannya tidak bisa ditukar.** Kebijakan menahan segalanya: tanpa
 * satuan sewa, jatuh tempo, dan denda, tagihan tidak bisa terbit - sistem
 * sengaja tidak menebak kebijakan kos (R-05). Tarif menempel pada kamar, jadi
 * kamar lebih dulu. Rekening tidak menahan apa pun, tetapi tanpanya portal
 * penghuni tidak bisa memberi tahu ke mana uang dikirim.
 *
 * **Kenapa komponen ini menghilang sendiri saat selesai.** Penanda penyiapan
 * yang tetap tampil setelah kos siap berubah menjadi hiasan, lalu menjadi
 * gangguan, lalu diabaikan - dan begitu diabaikan, ia tidak lagi memberi tahu
 * apa pun saat benar-benar ada yang kurang. Yang membuatnya berguna justru
 * kesediaannya untuk tidak ada.
 *
 * **Kenapa datanya dari server.** Menghitung "kebijakan lengkap" di peramban
 * berarti menebak aturan yang sebenarnya dipakai gerbang tagihan. Kalau dua
 * tempat itu menyimpang, layar menyatakan siap sementara tagihan tetap ditolak
 * - dan pemilik yang sudah mengikuti panduan akan menyalahkan aplikasinya.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { sesi } from '../sesi.js'
import { pesanGalat } from '../format.js'
const router = useRouter()

const status = ref(null)
const memuat = ref(true)

/**
 * Kegagalan memuat TIDAK ditampilkan sebagai galat.
 *
 * Penanda ini pelengkap, bukan isi halaman. Halaman Kamar tetap berguna tanpa
 * ia, dan menampilkan "gagal memuat status penyiapan" di atas daftar kamar yang
 * berhasil dimuat akan membuat pemilik mengira aplikasinya rusak padahal hanya
 * satu panggilan tambahan yang gagal.
 */
const galat = ref('')

const tampil = computed(() => status.value !== null && !status.value.selesai)

const langkah = computed(() => status.value?.langkah || [])

const kemajuan = computed(() => {
  if (!status.value) return ''
  return `${status.value.jumlah_selesai} dari ${status.value.jumlah_wajib} langkah wajib selesai`
})

async function muat() {
  memuat.value = true
  galat.value = ''

  try {
    const hasil = await sesi.panggil('/penyiapan')
    status.value = hasil.data
  } catch (e) {
    // Dibiarkan kosong dengan sengaja: lihat catatan `galat` di atas.
    galat.value = pesanGalat(e)
    status.value = null
  } finally {
    memuat.value = false
  }
}

onMounted(muat)

function buka(tautan) {
  router.push(tautan)
}

/**
 * Langkah yang belum selesai lebih dulu, tetapi urutannya di dalam kelompok
 * itu tetap urutan aslinya.
 *
 * Mengurutkan ulang seluruh daftar menurut "selesai" akan membuat langkah yang
 * sudah dikerjakan melompat ke bawah, dan pemilik yang sedang mengerjakan
 * urutan itu kehilangan tempatnya. Yang perlu terlihat lebih dulu adalah apa
 * yang belum, bukan apa yang sudah.
 */
const langkahTampil = computed(() => {
  const belum = langkah.value.filter((l) => !l.selesai)
  const sudah = langkah.value.filter((l) => l.selesai)
  return [...belum, ...sudah]
})
</script>

<template>
  <section v-if="tampil" class="kartu panel panel-penyiapan" aria-labelledby="judul-penyiapan">
    <div class="panel-kepala">
      <div>
        <h2 id="judul-penyiapan">Kos ini belum siap dipakai</h2>
        <p class="lemah mungil">
          {{ kemajuan }}. Urutannya penting: langkah paling atas menahan langkah di bawahnya.
        </p>
      </div>
    </div>

    <ol class="daftar-penyiapan">
      <li v-for="l in langkahTampil" :key="l.kunci" :class="{ selesai: l.selesai }">
        <div class="penyiapan-teks">
          <p class="penyiapan-judul">
            <span class="penyiapan-tanda" aria-hidden="true">{{ l.selesai ? '✓' : '○' }}</span>
            {{ l.judul }}
            <span v-if="!l.wajib" class="lencana lencana-netral">tidak wajib</span>
          </p>
          <p class="lemah mungil">{{ l.keterangan }}</p>
          <p v-if="l.rincian" class="penyiapan-rincian">{{ l.rincian }}</p>
        </div>
        <button
          v-if="!l.selesai"
          type="button"
          class="tombol-kecil"
          @click="buka(l.tautan)"
        >
          {{ l.aksi }}
        </button>
      </li>
    </ol>

    <p class="lemah mungil penyiapan-catatan">
      Penanda ini hilang sendiri setelah seluruh langkah wajib selesai. Ia dihitung
      dari data yang ada sekarang, bukan dari catatan bahwa penyiapan pernah
      diselesaikan — jadi kalau kamar dihapus atau rekening dinonaktifkan, ia
      muncul lagi.
    </p>
  </section>
</template>

<style scoped>
.panel-penyiapan {
  /* Garis kiri, bukan latar berwarna: penanda ini perlu terlihat lebih dulu
     tanpa terlihat seperti galat. Galat memakai warna bahaya, dan penyiapan
     yang belum selesai bukan kesalahan siapa pun. */
  border-left: 3px solid var(--aksen, #2563eb);
}

.daftar-penyiapan {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.daftar-penyiapan li {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  justify-content: space-between;
  /* `wrap` supaya tombol turun ke bawah pada layar 360px, bukan memaksa
     baris melebar. Uji tata letak mengukur luberan mendatar. */
  flex-wrap: wrap;
}

.daftar-penyiapan li.selesai .penyiapan-judul {
  color: var(--teks-lemah, #64748b);
  text-decoration: line-through;
}

.penyiapan-teks {
  flex: 1 1 240px;
  min-width: 0;
}

.penyiapan-judul {
  margin: 0 0 2px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.penyiapan-tanda {
  font-variant-numeric: tabular-nums;
}

.penyiapan-rincian {
  margin: 4px 0 0;
  font-size: 12.5px;
  color: var(--teks-lemah, #64748b);
}

.penyiapan-catatan {
  margin: 16px 0 0;
}

.tombol-kecil {
  flex: 0 0 auto;
}
</style>
