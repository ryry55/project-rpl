<script setup>
/**
 * Gerbang persetujuan pemrosesan data pribadi (T28).
 *
 * Ditampilkan sekali untuk setiap versi teks, dan **bisa ditolak**. Itu bukan
 * keramahan: persetujuan yang tidak bisa ditolak bukan persetujuan, melainkan
 * syarat memakai layanan - dan UU 27/2022 membedakan keduanya.
 *
 * Yang terjadi bila ditolak: pengguna keluar dari aplikasi. Bukan karena
 * dihukum, melainkan karena tanpa persetujuan ini kos tidak punya dasar hukum
 * untuk menyimpan namanya, nomor HP-nya, dan riwayat pembayarannya. Aplikasi
 * yang tetap berjalan setelah persetujuan ditolak sedang berbohong tentang
 * dasar pemrosesannya.
 *
 * Teksnya **tidak** disimpan di berkas ini melainkan diminta dari server, dan
 * alasannya bukan kerapian: teks yang disalin ke peramban adalah teks yang
 * paling sering tertinggal saat versinya naik - dan pengguna lalu menyetujui
 * versi lama sambil melihat versi baru.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { sesi } from '../sesi.js'

const router = useRouter()

const menyetujui = ref(false)
const menolak = ref(false)
const galat = ref('')

const nama = computed(() => sesi.user.value?.nama || '')
const versi = computed(() => sesi.versiPdp.value || '1.0')

async function setuju() {
  galat.value = ''
  menyetujui.value = true

  try {
    await sesi.panggil('/persetujuan-pdp', { method: 'POST' })
    sesi.perluPersetujuanPdp.value = false
    router.replace({ name: 'beranda' })
  } catch (e) {
    galat.value = e.message
  } finally {
    menyetujui.value = false
  }
}

async function tolak() {
  menolak.value = true

  // Keluar tanpa bertanya lagi: pengguna sudah menyatakan tidak setuju, dan
  // dialog "yakin?" setelah penolakan hanya mengulang pertanyaan yang sama.
  await sesi.keluar()
  router.replace({ name: 'masuk' })
}

onMounted(() => {
  // Kalau gerbang ini dibuka langsung padahal persetujuan sudah ada, tidak ada
  // yang perlu diputuskan - kembalikan ke beranda daripada menampilkan
  // pertanyaan yang jawabannya sudah diketahui.
  if (sesi.user.value && !sesi.perluPersetujuanPdp.value) {
    router.replace({ name: 'beranda' })
  }
})
</script>

<template>
  <div class="halaman-pdp">
    <div class="kartu kotak">
      <header class="kepala-pdp">
        <span class="lencana-versi">PDP {{ versi }}</span>
        <h1>Persetujuan pemrosesan data pribadi</h1>
        <p v-if="nama" class="lemah mungil">Untuk akun <strong>{{ nama }}</strong>.</p>
      </header>

      <div class="isi-pdp">
        <p>
          Kos ini menyimpan data berikut tentang Anda: nama, alamat email, nomor HP, kamar yang
          Anda tempati, serta tagihan dan pembayaran atas nama Anda.
        </p>

        <h2>Untuk apa</h2>
        <p>
          Menerbitkan tagihan sewa, mencatat pembayaran, menampilkan tunggakan, dan menghubungi Anda
          soal keduanya. Tidak ada tujuan lain, dan data ini tidak dijual atau dibagikan ke pihak
          ketiga.
        </p>

        <h2>Siapa yang bisa melihat</h2>
        <p>
          Pemilik kos dan pengelola yang diberi wewenang. Anda hanya bisa melihat tagihan dan
          pembayaran Anda sendiri. Setiap tindakan atas data ini tercatat di jejak audit yang tidak
          bisa diubah.
        </p>

        <h2>Berapa lama</h2>
        <p>
          Selama Anda menempati kamar, dan setelahnya selama catatan keuangan masih diperlukan.
          Anda bisa meminta salinan data Anda, meminta koreksi, atau meminta identitas Anda dilepas
          dari catatan kos ini kapan saja lewat menu <strong>Data pribadi</strong>.
        </p>

        <h2>Yang perlu Anda tahu</h2>
        <p>
          Permintaan penghapusan akan <strong>melepas identitas</strong> Anda - nama, email, dan
          nomor HP - tetapi angka dan tanggal pembayaran tetap tersimpan tanpa nama. Catatan
          keuangan tidak boleh dihapus, karena bukti bahwa uang pernah diterima melindungi Anda
          maupun pemilik kos.
        </p>
      </div>

      <p v-if="galat" class="pesan-galat" role="alert">{{ galat }}</p>

      <div class="aksi-pdp">
        <button type="button" :disabled="menyetujui || menolak" @click="setuju">
          {{ menyetujui ? 'Menyimpan…' : 'Saya setuju' }}
        </button>
        <button type="button" class="halus" :disabled="menyetujui || menolak" @click="tolak">
          {{ menolak ? 'Keluar…' : 'Saya tidak setuju' }}
        </button>
      </div>

      <p class="lemah mungil catatan-tolak">
        Bila Anda tidak setuju, akun ini akan keluar dan tidak bisa dipakai. Hubungi pemilik kos
        bila Anda ingin membahasnya lebih dulu.
      </p>
    </div>
  </div>
</template>

<style scoped>
.halaman-pdp {
  display: grid;
  place-items: center;
  min-height: 100%;
  padding: 24px;
}

.kotak {
  width: 100%;
  max-width: 560px;
  padding: 28px;
}

.kepala-pdp {
  margin-bottom: 18px;
}

/* Cap versi PDP, bukan pil biru. Bahasa yang sama dengan `.lencana-status`. */
.lencana-versi {
  display: inline-block;
  margin-bottom: 12px;
  padding: 2px 8px;
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--teks-lemah);
  background: transparent;
  border: 1px solid var(--garis);
  border-radius: var(--radius-kecil);
}

.kepala-pdp h1 {
  margin-bottom: 6px;
}

/* Isi persetujuan dibatasi tingginya dan diberi gulir sendiri: teksnya harus
   bisa dibaca seluruhnya, tetapi halaman tidak boleh mendorong tombol
   persetujuan jauh ke bawah layar ponsel - tombol yang tidak terlihat adalah
   tombol yang tidak ditemukan. */
.isi-pdp {
  max-height: 46vh;
  overflow-y: auto;
  padding-right: 10px;
  margin-bottom: 18px;
  font-size: 13.5px;
  line-height: 1.6;
}

.isi-pdp h2 {
  margin: 14px 0 4px;
  font-size: 14px;
}

.isi-pdp p {
  margin: 0 0 8px;
  color: var(--teks-lemah);
}

.aksi-pdp {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.aksi-pdp button {
  flex: 1 1 160px;
}

.catatan-tolak {
  margin: 12px 0 0;
}

@media (max-width: 520px) {
  .kotak {
    padding: 20px;
  }

  .isi-pdp {
    max-height: none;
  }
}
</style>
