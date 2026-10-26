<script setup>
/**
 * Halaman bantuan untuk pemilik dan pengelola.
 *
 * **Kenapa halaman ini ada, dan kenapa isinya bukan daftar fitur.**
 * Dokumentasi yang menjelaskan setiap tombol sudah ada di `docs/produk`, dan
 * tidak ada pemilik kos yang membacanya. Yang benar-benar dicari orang saat
 * membuka bantuan adalah jawaban atas satu kalimat: **"kenapa ini tidak bisa
 * saya lakukan?"** Halaman ini disusun menurut kalimat itu, bukan menurut menu.
 *
 * Setiap butir di bawah berasal dari penolakan yang benar-benar bisa ditemui
 * pemakaian sehari-hari - bukan dari daftar fitur yang disalin. Pesan galatnya
 * ditulis apa adanya seperti yang muncul di layar, supaya orang yang mencari
 * dengan potongan kalimat itu menemukannya.
 *
 * **Kenapa tidak ada pencarian atau akordeon.** Halamannya satu layar panjang
 * yang bisa dibaca dari atas ke bawah dan dicari dengan Ctrl+F peramban.
 * Pencarian buatan sendiri akan lebih buruk daripada Ctrl+F, dan akordeon
 * menyembunyikan justru jawaban yang sedang dicari.
 *
 * **Yang sengaja TIDAK ada di sini: tombol "hubungi dukungan".** Aplikasi ini
 * dipasang dan dijalankan sendiri oleh pemilik kos; tidak ada tim dukungan di
 * baliknya. Tombol yang membuka surel ke alamat yang tidak pernah dibaca
 * adalah janji yang lebih buruk daripada tidak ada tombol sama sekali.
 */
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { sesi } from '../../sesi.js'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'

const peran = computed(() => sesi.user.value?.peran || '')
const pengelola = computed(() => peran.value === 'pengelola')

/**
 * Butir bantuan, dikelompokkan menurut pertanyaan yang membawanya.
 *
 * `galat` ditulis persis seperti potongan pesan yang muncul di layar. Itu satu-
 * satunya cara orang menemukannya: mereka menyalin pesannya, bukan menebak
 * judul bantuannya.
 */
const kelompok = [
  {
    judul: 'Kos baru: kenapa tagihan tidak bisa terbit',
    butir: [
      {
        tanya: 'Muncul "Kebijakan kos belum lengkap: satuan sewa belum ditetapkan"',
        jawab:
          'Sistem ini sengaja tidak menebak kebijakan kos. Satuan sewa, cara jatuh tempo, ' +
          'dan aturan denda harus ditetapkan pemilik lebih dulu, karena kos yang berbeda ' +
          'memakai aturan yang berbeda dan tebakan yang salah menghasilkan tagihan yang ' +
          'salah nominal. Buka menu Kebijakan, isi ketiga bagian, lalu terbitkan lagi.',
        tautan: { ke: '/pemilik/kebijakan', label: 'Buka Kebijakan' },
      },
      {
        tanya: 'Muncul "Kamar belum punya tarif"',
        jawab:
          'Tagihan dihitung dari tarif yang berlaku pada kamar itu. Buka menu Kamar, tekan ' +
          '"Atur tarif" pada kamar yang disebut, lalu isi nominal dan tanggal berlakunya. ' +
          'Tarif bersifat menambah riwayat: mengubahnya tidak menghapus tarif lama, sehingga ' +
          'tagihan yang sudah terbit tetap bisa dijelaskan.',
        tautan: { ke: '/pemilik/kamar', label: 'Buka Kamar' },
      },
      {
        tanya: 'Tagihan sudah terbit, tetapi penghuni tidak tahu harus bayar ke mana',
        jawab:
          'Tambahkan rekening tujuan pembayaran di menu Rekening. Tanpa rekening aktif, ' +
          'portal penghuni tidak bisa menampilkan cara membayar, dan setiap penghuni akan ' +
          'bertanya lewat WhatsApp.',
        tautan: { ke: '/pemilik/rekening', label: 'Buka Rekening' },
      },
    ],
  },
  {
    judul: 'Tagihan dan pembayaran',
    butir: [
      {
        tanya: 'Salah mencatat nominal pembayaran. Bisa dihapus?',
        jawab:
          'Tidak, dan itu disengaja: catatan uang yang bisa dihapus tidak bisa dipakai untuk ' +
          'memeriksa apa pun. Yang tersedia adalah pembalikan - baris negatif yang menunjuk ' +
          'baris aslinya, dengan alasan yang wajib diisi. Kedua baris tetap terlihat, ' +
          'sehingga urutan kejadiannya bisa dibaca siapa pun. Buka detail tagihan, lalu ' +
          'tekan "Balik" pada pembayaran yang salah.',
      },
      {
        tanya: 'Tagihan sudah terbit padahal kontraknya batal',
        jawab:
          'Tagihan tidak dihapus, melainkan dibatalkan. Setelah dibatalkan ia berhenti ' +
          'dihitung sebagai piutang dan berhenti menambah denda, tetapi tetap tersimpan ' +
          'sebagai riwayat. Pembatalan bisa dilakukan dari daftar Tagihan.',
        tautan: { ke: '/pemilik/tagihan', label: 'Buka Tagihan' },
      },
      {
        tanya: 'Denda terlihat terlalu besar',
        jawab:
          'Denda dihitung dari persen harian dan dibatasi plafon yang Anda tetapkan sendiri ' +
          'di Kebijakan, jadi angkanya tidak bisa melewati plafon itu. Kalau tagihan tertentu ' +
          'memang layak dimaafkan, gunakan "Maafkan denda" pada detail tagihan - pemaafan ' +
          'juga tercatat, lengkap dengan alasannya.',
        tautan: { ke: '/pemilik/kebijakan', label: 'Periksa Kebijakan' },
      },
      {
        tanya: 'Pembayaran tunai yang saya pegang tidak muncul di rekap pemilik',
        jawab:
          'Setiap baris tunai mencatat siapa pemegangnya. Kalau Anda pengelola, uang yang ' +
          'Anda pegang muncul di "Tunai saya", bukan di rekap pendapatan pemilik - karena ' +
          'uangnya belum sampai ke pemilik. Setelah disetorkan, pemilik mencatatnya sebagai ' +
          'penerimaan.',
      },
    ],
  },
  {
    judul: 'Akun dan akses',
    butir: [
      {
        tanya: 'Penghuni bilang tidak pernah menerima email aktivasi',
        jawab:
          'Periksa tiga hal berurutan. Pertama, folder spam - email aktivasi paling sering ' +
          'berakhir di sana. Kedua, pastikan alamat emailnya benar; salah satu huruf saja ' +
          'membuat emailnya tidak sampai ke siapa pun. Ketiga, kirim ulang dari menu ' +
          'Penghuni: tombol "Kirim ulang aktivasi" membuat tautan baru. Kalau ketiganya ' +
          'sudah dicoba, penyebabnya ada di pengaturan email server dan perlu diperiksa oleh ' +
          'yang memasang aplikasi ini.',
        tautan: { ke: '/pemilik/penghuni', label: 'Buka Penghuni' },
      },
      {
        tanya: 'Saya lupa kata sandi sendiri',
        jawab:
          'Gunakan "Lupa sandi" di halaman masuk. Tautan pemulihannya dikirim ke email akun ' +
          'Anda, dan berlaku terbatas. Pemilik tidak bisa melihat atau mengganti sandi ' +
          'penghuni maupun pengelola - itu disengaja, supaya tidak ada yang bisa masuk ' +
          'sebagai orang lain lalu mengaku tidak pernah melakukannya.',
        tautan: { ke: '/lupa-sandi', label: 'Lupa sandi' },
      },
      {
        tanya: 'Pengelola tidak bisa mengubah tarif',
        jawab:
          'Mengubah tarif dan kebijakan sengaja tidak bisa dilakukan pengelola sendirian. ' +
          'Pengelola dapat mengajukan usulan, dan usulan itu menunggu keputusan pemilik ' +
          'selama tujuh hari sebelum kedaluwarsa. Aturannya ada karena tarif adalah angka ' +
          'yang menentukan seluruh tagihan: satu perubahan yang salah berlaku untuk semua ' +
          'penghuni sekaligus.',
        tautan: { ke: '/pemilik/persetujuan', label: 'Buka Persetujuan' },
      },
      {
        tanya: 'Apa bedanya pemilik dan pengelola?',
        jawab:
          'Pemilik memegang keputusan yang mengubah angka dan uang: tarif, kebijakan, ' +
          'rekening, dan akun. Pengelola menjalankan pekerjaan harian sesuai wewenang yang ' +
          'diberikan pemilik - mencatat pembayaran, mengurus kamar, menagih. Setiap tindakan ' +
          'pengelola tercatat atas namanya sendiri di jejak audit, sehingga pertanyaan ' +
          '"siapa yang mengubah ini" selalu punya jawaban.',
        tautan: { ke: '/pemilik/pengelola', label: 'Atur pengelola', hanyaPemilik: true },
      },
    ],
  },
  {
    judul: 'Data dan laporan',
    butir: [
      {
        tanya: 'Bagaimana menyerahkan laporan keuangan ke pembukuan saya?',
        jawab:
          'Gunakan tombol ekspor di halaman Rekap, Tunggakan, atau Tagihan. Berkasnya CSV ' +
          'yang bisa dibuka langsung di Excel. Angkanya ditulis mentah (1500000, bukan ' +
          '"Rp 1.500.000") supaya bisa dijumlahkan; memformatnya menjadi teks akan membuat ' +
          'SUM menghasilkan nol.',
        tautan: { ke: '/pemilik/rekap', label: 'Buka Rekap' },
      },
      {
        tanya: 'Kenapa angka rekap berbeda dari jumlah tagihan yang terbit?',
        jawab:
          'Keduanya memang mengukur hal berbeda. Rekap menghitung uang yang benar-benar ' +
          'diterima; jumlah tagihan menghitung yang seharusnya dibayar. Selisihnya adalah ' +
          'piutang - termasuk denda yang belum dibayar siapa pun. Rekap menampilkan piutang ' +
          'itu terpisah dan tidak pernah menjumlahkannya ke pendapatan.',
      },
      {
        tanya: 'Apakah data saya bisa hilang?',
        jawab:
          'Aplikasi ini menyimpan data di basis data yang harus dicadangkan sendiri oleh ' +
          'yang menjalankannya. Kalau Anda memakai layanan hosting, pastikan pencadangan ' +
          'otomatisnya menyala. Aplikasi tidak menghapus data secara diam-diam: tagihan dan ' +
          'pembayaran bersifat menambah, dan pembatalan adalah perubahan status, bukan ' +
          'penghapusan.',
      },
    ],
  },
]

const kelompokTampil = computed(() =>
  kelompok
    .map((k) => ({
      ...k,
      butir: k.butir.filter((b) => !b.hanyaPemilik || !pengelola.value),
    }))
    .filter((k) => k.butir.length > 0),
)
</script>

<template>
  <div class="halaman">
    <KepalaHalaman judul="Bantuan">
      <template #keterangan>
        Jawaban atas penolakan yang paling sering ditemui, disusun menurut masalahnya.
      </template>
    </KepalaHalaman>

    <p class="lemah mungil petunjuk-cari">
      Tekan <kbd>Ctrl</kbd> + <kbd>F</kbd> lalu tempel potongan pesan galat yang Anda lihat.
    </p>

    <section v-for="k in kelompokTampil" :key="k.judul" class="kartu panel">
      <h2>{{ k.judul }}</h2>

      <dl class="daftar-bantuan">
        <div v-for="b in k.butir" :key="b.tanya" class="butir-bantuan">
          <dt>{{ b.tanya }}</dt>
          <dd>
            <p>{{ b.jawab }}</p>
            <RouterLink v-if="b.tautan" :to="b.tautan.ke" class="tautan-bantuan">
              {{ b.tautan.label }} →
            </RouterLink>
          </dd>
        </div>
      </dl>
    </section>

    <section class="kartu panel">
      <h2>Kalau masalahnya tidak ada di sini</h2>
      <p>
        Aplikasi ini dijalankan sendiri oleh pemilik kos, jadi tidak ada meja bantuan yang
        bisa dihubungi. Yang bisa dilakukan:
      </p>
      <ul class="daftar-tindakan">
        <li>
          Periksa <RouterLink to="/pemilik/audit" class="tautan-bantuan">Jejak audit</RouterLink>
          — di sana terlihat siapa mengubah apa dan kapan, termasuk perubahan yang tidak Anda
          ingat melakukannya.
        </li>
        <li>
          Periksa halaman
          <RouterLink to="/pemilik/kebijakan" class="tautan-bantuan">Kebijakan</RouterLink>
          — banyak penolakan berasal dari kebijakan yang belum lengkap atau baru berlaku
          bulan depan.
        </li>
        <li>
          Kalau aplikasinya tidak bisa dibuka sama sekali, itu urusan pemasangan (server,
          basis data, atau email), bukan urusan pemakaian. Catat waktu kejadiannya; keterangan
          itu yang dibutuhkan orang yang memasangnya.
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.petunjuk-cari {
  margin: -4px 0 14px;
}

kbd {
  padding: 1px 5px;
  font-family: inherit;
  font-size: 11.5px;
  background: var(--garis-lemah);
  border-radius: 4px;
}

.daftar-bantuan {
  margin: 0;
}

.butir-bantuan + .butir-bantuan {
  margin-top: 18px;
  padding-top: 18px;
  border-top: 1px solid var(--garis-lemah);
}

.butir-bantuan dt {
  font-weight: 600;
  margin-bottom: 6px;
}

.butir-bantuan dd {
  margin: 0;
}

.butir-bantuan dd p {
  margin: 0 0 8px;
  line-height: 1.6;
}

.tautan-bantuan {
  font-size: 13px;
  font-weight: 600;
  color: var(--aksen);
  text-decoration: none;
}

.tautan-bantuan:hover {
  text-decoration: underline;
}

.daftar-tindakan {
  margin: 0;
  padding-left: 20px;
  line-height: 1.6;
}

.daftar-tindakan li + li {
  margin-top: 8px;
}
</style>
