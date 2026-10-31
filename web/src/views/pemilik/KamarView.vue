<script setup>
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import {
  rupiah,
  tanggal,
  tanggalHariIni,
  tanggalBerlakuBulanIni,
  pesanGalat,
  galatPerBidang,
} from '../../format.js'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'
import LencanaStatus from '../../komponen/ui/LencanaStatus.vue'
import BarisKosong from '../../komponen/ui/BarisKosong.vue'
import PanduanPenyiapan from '../../komponen/PanduanPenyiapan.vue'

const daftar = ref([])
const memuat = ref(true)
const galatMuat = ref('')

const formTerbuka = ref(false)
const menyimpan = ref(false)
const galatForm = ref('')
const galatBidang = ref({})
const form = ref({ nomor: '', tipe: '', catatan: '' })

// Kamar yang riwayat tarifnya sedang dibuka.
const kamarTarif = ref(null)
const riwayat = ref([])
const memuatRiwayat = ref(false)
const formTarif = ref({ nilai: '', berlaku_dari: tanggalHariIni() })
const galatTarif = ref('')
const galatTarifBidang = ref({})
const menyimpanTarif = ref(false)
const suksesTarif = ref('')

/**
 * Komponen biaya tetap kamar ini (REV-UI-02).
 *
 * `null` berarti "belum atau gagal dimuat", dan itu dibedakan dari `[]` yang
 * berarti "sudah dimuat, memang kosong". Tanpa pembedaan ini, permintaan yang
 * gagal tampil sebagai "belum ada komponen biaya" - layar yang berbohong dengan
 * tenang, cacat yang sudah pernah terjadi di portal (REV-UI-04).
 *
 * Daftarnya diambil lewat `berlaku_pada`, bukan seluruh riwayat: yang perlu
 * dijawab panel ini adalah "kenapa tagihan kamar ini sebesar itu", dan
 * jawabannya hanya baris yang benar-benar menagih bulan ini.
 */
const komponen = ref(null)
const formKomponen = ref(kosongkanKomponen())
const galatKomponen = ref('')
const galatKomponenBidang = ref({})
const menyimpanKomponen = ref(false)
const suksesKomponen = ref('')

function kosongkanKomponen() {
  return {
    nama: '',
    nilai: '',
    satuan: '',
    // Awal bulan, bukan hari ini: komponen diselesaikan terhadap awal periode,
    // jadi tanggal hari ini membuat baris baru belum menagih sampai bulan
    // depan. Alasannya sama dengan `tanggalBerlakuBulanIni` di format.js.
    berlaku_dari: tanggalBerlakuBulanIni(),
    semuaKamar: false,
  }
}

// Kamar yang fasilitasnya sedang diatur (T18).
const kamarFasilitas = ref(null)
const semuaFasilitas = ref([])
const dipilih = ref([])
const memuatFasilitas = ref(false)
const menyimpanFasilitas = ref(false)
const galatFasilitas = ref('')
const suksesFasilitas = ref('')

const jumlahTerisi = computed(() => daftar.value.filter((k) => k.status_huni === 'terisi').length)
const jumlahKosong = computed(() => daftar.value.filter((k) => k.status_huni === 'kosong').length)
// Kamar yang sedang diperbaiki (T14) dihitung sendiri: ia bukan "kosong" -
// kamar itu tidak bisa disewakan sampai perbaikannya selesai.
const jumlahPerbaikan = computed(
  () => daftar.value.filter((k) => k.status_huni === 'perbaikan').length,
)

const kamarRusak = ref(null)
const formRusak = ref({ deskripsi: '' })
const menyimpanRusak = ref(false)
const galatRusak = ref('')
const galatRusakBidang = ref({})
const suksesRusak = ref('')

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const hasil = await sesi.panggil('/kamar')
    daftar.value = hasil.data
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

function bukaForm() {
  form.value = { nomor: '', tipe: '', catatan: '' }
  galatForm.value = ''
  galatBidang.value = {}
  suksesFasilitas.value = ''
  formTerbuka.value = true
}

function tutupForm() {
  formTerbuka.value = false
}

// --- Laporan kerusakan (T14) ---------------------------------------------

function bukaLaporan(kamar) {
  kamarRusak.value = kamar
  formRusak.value = { deskripsi: '' }
  galatRusak.value = ''
  galatRusakBidang.value = {}
  suksesRusak.value = ''
}

function tutupLaporan() {
  kamarRusak.value = null
}

async function simpanLaporan() {
  menyimpanRusak.value = true
  galatRusak.value = ''
  galatRusakBidang.value = {}
  try {
    await sesi.panggil('/laporan-kerusakan', {
      method: 'POST',
      body: JSON.stringify({
        kamar_id: kamarRusak.value.id,
        deskripsi: formRusak.value.deskripsi.trim(),
      }),
    })
    suksesRusak.value = `Kerusakan kamar ${kamarRusak.value.nomor} dilaporkan. Kamar ditandai diperbaiki.`
    kamarRusak.value = null
    await muat()
  } catch (e) {
    galatRusak.value = pesanGalat(e)
    galatRusakBidang.value = galatPerBidang(e)
  } finally {
    menyimpanRusak.value = false
  }
}

async function selesaikanPerbaikan(kamar) {
  // Laporan yang ditutup diambil dari barisnya sendiri, bukan dari state
  // terpilih: dua kamar bisa diperbaiki bersamaan, dan panel yang tertinggal
  // terbuka akan menutup laporan kamar yang salah.
  const id = kamar.laporan_terbuka?.id
  if (!id) {
    return
  }

  galatRusak.value = ''
  try {
    await sesi.panggil(`/laporan-kerusakan/${id}/selesai`, { method: 'POST' })
    suksesRusak.value = `Perbaikan kamar ${kamar.nomor} selesai.`
    await muat()
  } catch (e) {
    galatRusak.value = pesanGalat(e)
  }
}

async function simpan() {
  menyimpan.value = true
  galatForm.value = ''
  galatBidang.value = {}
  try {
    await sesi.panggil('/kamar', {
      method: 'POST',
      body: JSON.stringify({
        nomor: form.value.nomor.trim(),
        tipe: form.value.tipe.trim() || null,
        catatan: form.value.catatan.trim() || null,
      }),
    })
    formTerbuka.value = false
    await muat()
  } catch (e) {
    galatForm.value = pesanGalat(e)
    galatBidang.value = galatPerBidang(e)
  } finally {
    menyimpan.value = false
  }
}

async function bukaTarif(kamar) {
  kamarTarif.value = kamar
  riwayat.value = []
  komponen.value = null
  galatTarif.value = ''
  galatTarifBidang.value = {}
  galatKomponen.value = ''
  galatKomponenBidang.value = {}
  formTarif.value = { nilai: '', berlaku_dari: tanggalHariIni() }
  formKomponen.value = kosongkanKomponen()
  memuatRiwayat.value = true
  try {
    // Dua permintaan sekaligus: keduanya mengisi panel yang sama, dan
    // memuatnya berurutan membuat separuh panel tampak kosong selama
    // permintaan kedua berjalan.
    const [hasil, hasilKomponen] = await Promise.all([
      sesi.panggil(`/tarif?kamar_id=${kamar.id}`),
      sesi.panggil(
        `/komponen-biaya?kamar_id=${kamar.id}&berlaku_pada=${tanggalHariIni()}`,
      ),
    ])
    riwayat.value = hasil.data
    komponen.value = hasilKomponen.data
  } catch (e) {
    galatTarif.value = pesanGalat(e)
  } finally {
    memuatRiwayat.value = false
  }
}

function tutupTarif() {
  kamarTarif.value = null
  riwayat.value = []
  komponen.value = null
  suksesTarif.value = ''
  suksesKomponen.value = ''
}

/**
 * Membuka panel fasilitas satu kamar (T18).
 *
 * Daftar seluruh fasilitas diambil sekali di sini, bukan saat halaman dimuat:
 * pemilik yang membuka halaman Kamar hanya untuk melihat nomor kamar tidak
 * perlu ikut mengunduh katalog fasilitas.
 *
 * Centang yang sudah tersimpan dibaca dari `k.fasilitas` yang dikirim
 * `GET /kamar`, bukan dari panggilan tambahan - dua sumber akan berbeda bila
 * ada yang mengubah pivot di tab lain.
 */
async function bukaFasilitas(kamar) {
  kamarFasilitas.value = kamar
  galatFasilitas.value = ''
  dipilih.value = (kamar.fasilitas || []).map((f) => f.id)

  if (semuaFasilitas.value.length > 0) {
    return
  }

  memuatFasilitas.value = true
  try {
    const hasil = await sesi.panggil('/fasilitas')
    semuaFasilitas.value = hasil.data
  } catch (e) {
    galatFasilitas.value = pesanGalat(e)
  } finally {
    memuatFasilitas.value = false
  }
}

function tutupFasilitas() {
  kamarFasilitas.value = null
  dipilih.value = []
  galatFasilitas.value = ''
}

function gantiCentang(id, tercentang) {
  // `filter` alih-alih `push`/`splice`: daftar centang selalu tanpa duplikat,
  // dan `sync()` di server menolak daftar berganda dengan 422 yang akan
  // terbaca seperti kerusakan layar padahal kesalahannya ada di sini.
  if (tercentang) {
    dipilih.value = [...dipilih.value, id]
  } else {
    dipilih.value = dipilih.value.filter((x) => x !== id)
  }
}

async function simpanFasilitas() {
  menyimpanFasilitas.value = true
  galatFasilitas.value = ''
  try {
    await sesi.panggil(`/kamar/${kamarFasilitas.value.id}/fasilitas`, {
      method: 'PUT',
      body: JSON.stringify({ fasilitas: dipilih.value }),
    })
    const nomor = kamarFasilitas.value.nomor
    tutupFasilitas()
    await muat()
    // Panel ditutup dan tabel disegarkan, jadi tidak ada lagi tempat
    // menampilkan "tersimpan". Pesan sukses singkat di kepala halaman sudah
    // cukup untuk memberi tahu bahwa centang benar-benar tersimpan.
    suksesFasilitas.value = `Fasilitas kamar ${nomor} tersimpan.`
  } catch (e) {
    galatFasilitas.value = pesanGalat(e)
  } finally {
    menyimpanFasilitas.value = false
  }
}

/**
 * Menyimpan tarif baru.
 *
 * Sejak T26 jawabannya punya dua bentuk, dan layar harus membedakannya:
 *
 *   pemilik   -> 201, baris tarif tertulis, riwayat bertambah
 *   pengelola -> 202, usulan tercatat, riwayat TIDAK bertambah
 *
 * Kalau 202 diperlakukan seperti 201, pengelola akan melihat riwayat yang tidak
 * berubah setelah menekan Berlakukan tarif - dan menyimpulkan aplikasinya rusak,
 * lalu menekan tombolnya lagi. Karena itu pesannya berbeda, dan riwayat hanya
 * dimuat ulang pada jalur 201.
 */
async function simpanTarif() {
  menyimpanTarif.value = true
  galatTarif.value = ''
  galatTarifBidang.value = {}
  suksesTarif.value = ''
  try {
    const hasil = await sesi.panggil('/tarif', {
      method: 'POST',
      body: JSON.stringify({
        kamar_id: kamarTarif.value.id,
        // Nilai dikirim sebagai bilangan bulat. Kalau pengguna mengetik
        // "1500000.5", Number() menghasilkan pecahan dan server menolaknya
        // dengan 422 - itu memang yang diinginkan, bukan dipotong diam-diam.
        nilai: Number(formTarif.value.nilai),
        berlaku_dari: formTarif.value.berlaku_dari,
      }),
    })

    formTarif.value = { nilai: '', berlaku_dari: tanggalHariIni() }

    if (hasil?.meta?.diajukan) {
      suksesTarif.value =
        hasil.meta.pesan ||
        'Usulan terkirim. Tarif belum berubah sampai pemilik menyetujuinya.'
      // Riwayat tetap dimuat ulang: isinya tidak berubah, tetapi memuatnya
      // memastikan layar tidak menampilkan sisa keadaan sebelumnya.
      await bukaTarif(kamarTarif.value)
    } else {
      suksesTarif.value = 'Tarif baru berlaku. Tagihan periode berikutnya memakai nilai ini.'
      await bukaTarif(kamarTarif.value)
      await muat()
    }
  } catch (e) {
    galatTarif.value = pesanGalat(e)
    galatTarifBidang.value = galatPerBidang(e)
  } finally {
    menyimpanTarif.value = false
  }
}

/**
 * Menyimpan komponen biaya tetap.
 *
 * Nasibnya sama dengan tarif dan harus dibedakan di layar:
 *
 *   pemilik   -> 201, baris komponen tertulis
 *   pengelola -> 202, usulan tercatat, komponen BELUM menagih
 *
 * Kalau 202 diperlakukan seperti 201, pengelola akan melihat daftar yang tidak
 * bertambah setelah menekan tombolnya dan menyimpulkan aplikasinya rusak.
 */
async function simpanKomponen() {
  menyimpanKomponen.value = true
  galatKomponen.value = ''
  galatKomponenBidang.value = {}
  suksesKomponen.value = ''

  // Kolom nilai kosong menjadi `Number('') === 0`, dan 0 berarti "komponen
  // dihentikan". Tanpa penjagaan ini, formulir yang dikirim dengan kolom nilai
  // kosong akan menghentikan penagihan tanpa satu pun peringatan.
  if (String(formKomponen.value.nilai).trim() === '') {
    galatKomponenBidang.value = {
      nilai: 'Nilai wajib diisi. Isi 0 hanya bila ingin menghentikan komponen.',
    }
    menyimpanKomponen.value = false
    return
  }

  const berlaku = formKomponen.value.berlaku_dari
  try {
    const hasil = await sesi.panggil('/komponen-biaya', {
      method: 'POST',
      body: JSON.stringify({
        kamar_id: formKomponen.value.semuaKamar ? null : kamarTarif.value.id,
        nama: formKomponen.value.nama.trim(),
        nilai: Number(formKomponen.value.nilai),
        satuan: formKomponen.value.satuan.trim() || null,
        berlaku_dari: berlaku,
      }),
    })

    if (hasil?.meta?.diajukan) {
      suksesKomponen.value =
        hasil.meta.pesan ||
        'Usulan terkirim. Komponen belum menagih sampai pemilik menyetujuinya.'
    } else {
      suksesKomponen.value =
        `Komponen tersimpan dan menagih mulai ${tanggal(berlaku)}.`
    }

    await bukaTarif(kamarTarif.value)
    await muat()
  } catch (e) {
    galatKomponen.value = pesanGalat(e)
    galatKomponenBidang.value = galatPerBidang(e)
  } finally {
    menyimpanKomponen.value = false
  }
}

onMounted(muat)
</script>

<template>
  <div class="halaman">
    <KepalaHalaman judul="Kamar">
      <template #keterangan>
        {{ daftar.length }} kamar · {{ jumlahTerisi }} terisi · {{ jumlahKosong }} kosong<template
          v-if="jumlahPerbaikan > 0"
        > · {{ jumlahPerbaikan }} diperbaiki</template>
      </template>
      <template #aksi>
        <button v-if="!formTerbuka && sesi.boleh('kelola_kamar')" @click="bukaForm">
          Tambah kamar
        </button>
      </template>
    </KepalaHalaman>

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>
    <PesanBaris v-if="suksesFasilitas" jenis="sukses">{{ suksesFasilitas }}</PesanBaris>
    <PesanBaris v-if="suksesRusak" jenis="sukses">{{ suksesRusak }}</PesanBaris>
    <PesanBaris v-if="galatRusak && !kamarRusak" jenis="galat">{{ galatRusak }}</PesanBaris>

    <!--
      Penanda urutan penyiapan, hanya untuk pemilik.

      Ditaruh di halaman Kamar karena inilah halaman muka pemilik (lihat
      `halamanMuka` di router). Menaruhnya di halaman tersendiri berarti pemilik
      harus tahu ia ada lebih dulu - dan orang yang belum tahu urutan penyiapan
      adalah orang yang paling tidak akan mencarinya.

      Tidak dirender untuk pengelola: langkah-langkahnya (kebijakan, rekening)
      memang bukan wewenangnya, dan daftar berisi tombol yang pasti ditolak
      hanya membuatnya merasa aplikasinya rusak.

      **Juga tidak dirender saat `galatMuat`.** Ini bukan kerapian: penanda ini
      menjawab "apa yang masih kurang", dan jawaban itu hanya masuk akal bila
      halaman memang berhasil membaca keadaannya. Saat daftar kamar gagal
      dimuat, menampilkan "Belum ada kamar sama sekali" di atas pesan galat
      membuat layar menyatakan dua hal yang bertentangan - dan yang dipercaya
      orang adalah yang lebih spesifik, yaitu daftar langkahnya. Uji
      `uji-galat-muat.mjs` menangkap tepat kekeliruan ini.
    -->
    <PanduanPenyiapan v-if="sesi.user.value?.peran === 'pemilik' && !galatMuat" />

    <section v-if="formTerbuka" class="kartu panel">
      <h2>Tambah kamar</h2>
      <form class="bidang" @submit.prevent="simpan">
        <div class="baris-bidang">
          <div>
            <label for="nomor">Nomor kamar</label>
            <input
              id="nomor"
              v-model="form.nomor"
              placeholder="A1"
              :aria-invalid="Boolean(galatBidang.nomor)"
              autocomplete="off"
            />
            <p v-if="galatBidang.nomor" class="galat-bidang">{{ galatBidang.nomor }}</p>
          </div>
          <div>
            <label for="tipe">Tipe <span class="lemah">(opsional)</span></label>
            <input id="tipe" v-model="form.tipe" placeholder="Standar" autocomplete="off" />
          </div>
        </div>

        <div>
          <label for="catatan">Catatan <span class="lemah">(opsional)</span></label>
          <input id="catatan" v-model="form.catatan" placeholder="Kamar pojok, jendela utara" />
        </div>

        <p v-if="galatForm" class="pesan-galat">{{ galatForm }}</p>

        <div class="aksi">
          <button type="submit" :disabled="menyimpan">
            {{ menyimpan ? 'Menyimpan…' : 'Simpan kamar' }}
          </button>
          <button type="button" class="halus" @click="tutupForm">Batal</button>
        </div>
      </form>
    </section>

    <!--
      Laporan kerusakan (T14). Satu panel untuk satu kamar, dan hanya satu
      laporan boleh terbuka per kamar - server menolak yang kedua. Karena itu
      tombolnya tidak perlu bisa membuka dua panel sekaligus.
    -->
    <section v-if="kamarRusak" class="kartu panel">
      <div class="panel-kepala">
        <div>
          <h2>Laporkan kerusakan kamar {{ kamarRusak.nomor }}</h2>
          <p class="lemah mungil">
            Kamar akan ditandai <strong>diperbaiki</strong> sampai laporannya ditutup. Sewa tetap
            berjalan; biaya perbaikan tidak masuk tagihan penghuni.
          </p>
        </div>
        <button class="halus mungil" @click="tutupLaporan">Tutup</button>
      </div>

      <form class="bidang" @submit.prevent="simpanLaporan">
        <div>
          <label for="deskripsi-kerusakan">Apa yang rusak?</label>
          <textarea
            id="deskripsi-kerusakan"
            v-model="formRusak.deskripsi"
            rows="3"
            placeholder="Plafon kamar mandi bocor dan merembes ke lemari"
            :aria-invalid="Boolean(galatRusakBidang.deskripsi)"
          />
          <p v-if="galatRusakBidang.deskripsi" class="galat-bidang">
            {{ galatRusakBidang.deskripsi }}
          </p>
        </div>

        <p v-if="galatRusak" class="pesan-galat">{{ galatRusak }}</p>

        <div class="aksi">
          <button type="submit" :disabled="menyimpanRusak">
            {{ menyimpanRusak ? 'Menyimpan…' : 'Laporkan kerusakan' }}
          </button>
          <button type="button" class="halus" @click="tutupLaporan">Batal</button>
        </div>
      </form>
    </section>

    <section v-if="kamarTarif" class="kartu panel">
      <div class="panel-kepala">
        <div>
          <h2>Tarif &amp; komponen kamar {{ kamarTarif.nomor }}</h2>
          <p class="lemah mungil">
            Mengubah tarif menambah baris baru. Baris lama tetap tersimpan sebagai riwayat.
          </p>
        </div>
        <button class="halus mungil" @click="tutupTarif">Tutup</button>
      </div>

      <p v-if="memuatRiwayat" class="lemah mungil">Memuat riwayat…</p>

      <div v-else class="tabel-bungkus">
        <table class="tabel" v-kartu-tabel>
          <thead>
            <tr>
              <th>Berlaku dari</th>
              <th class="kanan">Tarif per bulan</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(t, i) in riwayat" :key="t.id">
              <td>{{ tanggal(t.berlaku_dari) }}</td>
              <td class="kanan">{{ rupiah(t.nilai) }}</td>
              <td>
                <LencanaStatus v-if="i === 0" status="aktif" label="Berlaku sekarang" />
                <span v-else class="lemah mungil">Riwayat</span>
              </td>
            </tr>
            <BarisKosong v-if="riwayat.length === 0" :kolom="3">
              Belum ada tarif untuk kamar ini.
            </BarisKosong>
          </tbody>
        </table>
      </div>

      <form class="bidang riwayat-form" @submit.prevent="simpanTarif">
        <div class="baris-bidang">
          <div>
            <label for="nilai-tarif">Tarif baru per bulan</label>
            <input
              id="nilai-tarif"
              v-model="formTarif.nilai"
              inputmode="numeric"
              placeholder="1500000"
              :aria-invalid="Boolean(galatTarifBidang.nilai)"
              autocomplete="off"
            />
            <p v-if="galatTarifBidang.nilai" class="galat-bidang">{{ galatTarifBidang.nilai }}</p>
          </div>
          <div>
            <label for="berlaku-tarif">Berlaku dari</label>
            <input
              id="berlaku-tarif"
              v-model="formTarif.berlaku_dari"
              type="date"
              :aria-invalid="Boolean(galatTarifBidang.berlaku_dari)"
            />
            <p v-if="galatTarifBidang.berlaku_dari" class="galat-bidang">
              {{ galatTarifBidang.berlaku_dari }}
            </p>
          </div>
        </div>

        <p v-if="galatTarif" class="pesan-galat">{{ galatTarif }}</p>
        <PesanBaris v-if="suksesTarif" jenis="sukses" mungil>{{ suksesTarif }}</PesanBaris>

        <div class="aksi">
          <button type="submit" :disabled="menyimpanTarif">
            {{ menyimpanTarif ? 'Menyimpan.' : (sesi.user.value?.peran === 'pemilik' ? 'Berlakukan tarif' : 'Ajukan perubahan tarif') }}
          </button>
        </div>
      </form>

      <!-- Komponen biaya tetap. Satu blok dengan tarif karena keduanya angka
           uang per kamar dan digerbang wewenang yang sama (`ubah_tarif`);
           memisahkannya ke halaman lain membuat pemilik mengira biaya
           tambahan tidak ada tempatnya. -->
      <div class="komponen-blok">
        <h3>Komponen biaya tetap</h3>
        <p class="lemah mungil">
          Yang menagih pada bulan berjalan. Untuk menghentikan sebuah komponen, tambahkan baris
          baru bernilai 0 dengan nama yang sama.
        </p>

        <p v-if="komponen === null" class="lemah mungil">Komponen biaya belum bisa dimuat.</p>

        <div v-else class="tabel-bungkus">
          <table class="tabel" v-kartu-tabel>
            <thead>
              <tr>
                <th>Nama</th>
                <th class="kanan">Nilai</th>
                <th>Satuan</th>
                <th>Berlaku dari</th>
                <th>Cakupan</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="k in komponen" :key="k.id">
                <td>{{ k.nama }}</td>
                <td class="kanan">
                  <span v-if="k.dihentikan" class="lemah">Dihentikan</span>
                  <template v-else>{{ rupiah(k.nilai) }}</template>
                </td>
                <td>{{ k.satuan || '—' }}</td>
                <td>{{ tanggal(k.berlaku_dari) }}</td>
                <td class="mungil">{{ k.kamar_id === null ? 'Semua kamar' : 'Kamar ini' }}</td>
              </tr>
              <BarisKosong v-if="komponen.length === 0" :kolom="5">
                Belum ada komponen biaya yang menagih kamar ini.
              </BarisKosong>
            </tbody>
          </table>
        </div>

        <form id="form-komponen" class="bidang riwayat-form" @submit.prevent="simpanKomponen">
          <div class="baris-bidang">
            <div>
              <label for="komponen-nama">Nama komponen</label>
              <input
                id="komponen-nama"
                v-model="formKomponen.nama"
                placeholder="Listrik AC"
                autocomplete="off"
                :aria-invalid="Boolean(galatKomponenBidang.nama)"
              />
              <p v-if="galatKomponenBidang.nama" class="galat-bidang">{{ galatKomponenBidang.nama }}</p>
            </div>
            <div>
              <label for="komponen-nilai">Nilai per bulan</label>
              <input
                id="komponen-nilai"
                v-model="formKomponen.nilai"
                inputmode="numeric"
                placeholder="100000"
                autocomplete="off"
                :aria-invalid="Boolean(galatKomponenBidang.nilai)"
              />
              <p v-if="galatKomponenBidang.nilai" class="galat-bidang">{{ galatKomponenBidang.nilai }}</p>
            </div>
          </div>

          <div class="baris-bidang">
            <div>
              <label for="komponen-satuan">Satuan <span class="lemah">(opsional)</span></label>
              <input
                id="komponen-satuan"
                v-model="formKomponen.satuan"
                placeholder="per bulan"
                autocomplete="off"
              />
            </div>
            <div>
              <label for="komponen-berlaku">Berlaku dari</label>
              <input
                id="komponen-berlaku"
                v-model="formKomponen.berlaku_dari"
                type="date"
                :aria-invalid="Boolean(galatKomponenBidang.berlaku_dari)"
              />
              <p v-if="galatKomponenBidang.berlaku_dari" class="galat-bidang">
                {{ galatKomponenBidang.berlaku_dari }}
              </p>
            </div>
          </div>

          <label class="pilihan-fasilitas">
            <input id="komponen-semua" v-model="formKomponen.semuaKamar" type="checkbox" />
            <span>Berlaku untuk semua kamar</span>
          </label>

          <p v-if="galatKomponen" class="pesan-galat">{{ galatKomponen }}</p>
          <PesanBaris v-if="suksesKomponen" jenis="sukses" mungil>{{ suksesKomponen }}</PesanBaris>

          <div class="aksi">
            <button type="submit" class="halus" :disabled="menyimpanKomponen">
              {{ menyimpanKomponen ? 'Menyimpan.' : (sesi.user.value?.peran === 'pemilik' ? 'Tambah komponen' : 'Ajukan komponen') }}
            </button>
          </div>
        </form>
      </div>
    </section>

    <section v-if="kamarFasilitas" class="kartu panel">
      <div class="panel-kepala">
        <div>
          <h2>Fasilitas kamar {{ kamarFasilitas.nomor }}</h2>
          <p class="lemah mungil">
            Fasilitas hanya dicatat, tidak menagih. Untuk menagih sesuatu, tambahkan komponen
            biaya di panel Atur tarif kamar ini.
          </p>
        </div>
        <button class="halus mungil" @click="tutupFasilitas">Tutup</button>
      </div>

      <p v-if="memuatFasilitas" class="lemah mungil">Memuat fasilitas…</p>

      <template v-else>
        <PesanBaris v-if="semuaFasilitas.length === 0" jenis="netral" mungil>
          Belum ada fasilitas terdaftar. Tambahkan dulu di halaman Fasilitas.
        </PesanBaris>

        <div v-else class="kisi-fasilitas">
          <label v-for="f in semuaFasilitas" :key="f.id" class="pilihan-fasilitas">
            <input
              type="checkbox"
              :value="f.id"
              :checked="dipilih.includes(f.id)"
              @change="gantiCentang(f.id, $event.target.checked)"
            />
            <span>{{ f.nama }}</span>
          </label>
        </div>

        <PesanBaris v-if="galatFasilitas" jenis="galat">{{ galatFasilitas }}</PesanBaris>

        <div class="aksi">
          <button type="button" :disabled="menyimpanFasilitas" @click="simpanFasilitas">
            {{ menyimpanFasilitas ? 'Menyimpan…' : 'Simpan fasilitas' }}
          </button>
        </div>
      </template>
    </section>

    <section class="kartu">
      <div class="tabel-bungkus">
        <table class="tabel" v-kartu-tabel>
          <thead>
            <tr>
              <th>Nomor</th>
              <th>Tipe</th>
              <th>Fasilitas</th>
              <th>Status</th>
              <th>Penghuni</th>
              <th class="kanan">Tarif terakhir</th>
              <th class="kanan">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="k in daftar" :key="k.id">
              <td><strong>{{ k.nomor }}</strong></td>
              <td>{{ k.tipe || '—' }}</td>
              <!-- Fasilitas di kolomnya sendiri, bukan ditempel di bawah nomor:
                   badge yang menumpuk di kolom sempit membuat tabel mengalokasikan
                   lebar kolom nomor mengikuti badge terpanjang, dan seluruh kolom
                   lain bergeser tanpa ada yang berubah pada datanya. -->
              <td>
                <span v-if="k.fasilitas?.length" class="label-fasilitas">
                  <span v-for="f in k.fasilitas" :key="f.id" class="label-fasilitas-satu">
                    {{ f.nama }}
                  </span>
                </span>
                <span v-else class="lemah mungil">—</span>
              </td>
              <td>
                <LencanaStatus :status="k.status_huni" />
                <!-- Alasan perbaikannya ditulis di bawah lencana, bukan di
                     kolom sendiri: tanpa ini "diperbaiki" hanya label tanpa
                     penjelasan, dan pemilik harus membuka panel lain untuk
                     tahu apa yang rusak. -->
                <span v-if="k.laporan_terbuka" class="alasan-rusak mungil">
                  {{ k.laporan_terbuka.deskripsi }}
                  <span class="lemah" v-if="k.laporan_terbuka.pelapor">
                    · {{ k.laporan_terbuka.pelapor }}
                  </span>
                </span>
              </td>
              <td>{{ k.penghuni?.nama || '—' }}</td>
              <td class="kanan">
                <template v-if="k.tarif_terakhir">
                  {{ rupiah(k.tarif_terakhir.nilai) }}
                  <span class="lemah mungil tarif-sejak">sejak {{ tanggal(k.tarif_terakhir.berlaku_dari) }}</span>
                </template>
                <span v-else class="lemah mungil">Belum ditetapkan</span>
              </td>
              <td class="kanan aksi-baris">
                <!-- Tombol tarif butuh wewenang tersendiri: mengubah angka uang
                     bukan bagian dari mengelola kamar. Menyembunyikannya lebih
                     jujur daripada menampilkannya lalu menolak dengan 403. -->
                <button
                  v-if="sesi.boleh('ubah_tarif')"
                  class="halus mungil"
                  @click="bukaTarif(k)"
                >
                  Atur tarif
                </button>
                <button
                  v-if="sesi.boleh('kelola_kamar')"
                  class="halus mungil"
                  @click="bukaFasilitas(k)"
                >
                  Fasilitas
                </button>
                <!-- Satu tombol untuk satu keadaan: yang terbuka ditutup, yang
                     tidak terbuka dilaporkan. Dua tombol sekaligus akan
                     memaksa orang memilih di antara dua hal yang tidak bisa
                     terjadi bersamaan. -->
                <button
                  v-if="sesi.boleh('kelola_kamar') && !k.laporan_terbuka"
                  class="halus mungil"
                  @click="bukaLaporan(k)"
                >
                  Laporkan rusak
                </button>
                <button
                  v-if="sesi.boleh('kelola_kamar') && k.laporan_terbuka"
                  class="halus mungil"
                  @click="selesaikanPerbaikan(k)"
                >
                  Selesai diperbaiki
                </button>
                <span
                  v-if="!sesi.boleh('ubah_tarif') && !sesi.boleh('kelola_kamar')"
                  class="lemah mungil"
                >
                  Hanya lihat
                </span>
              </td>
            </tr>
            <BarisKosong
              v-if="!memuat && !galatMuat && daftar.length === 0"
              :kolom="7"
            >
              Belum ada kamar. Tambahkan kamar pertama lewat tombol di atas.
            </BarisKosong>
            <BarisKosong v-if="memuat" :kolom="7">Memuat…</BarisKosong>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.riwayat-form {
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--garis);
}

.komponen-blok {
  margin-top: 24px;
  padding-top: 16px;
  border-top: 1px solid var(--garis);
}

.komponen-blok h3 {
  margin-bottom: 4px;
}

/* Kisi, bukan satu kolom: daftar fasilitas biasanya 4-8 butir, dan satu kolom
   membuat panel setinggi layar hanya untuk beberapa kotak centang. */
.kisi-fasilitas {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 8px 16px;
  margin: 4px 0 16px;
}

.pilihan-fasilitas {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 400;
  cursor: pointer;
}

.pilihan-fasilitas input {
  width: auto;
  margin: 0;
}

.label-fasilitas {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 4px;
}

.label-fasilitas-satu {
  padding: 0 8px;
  font-size: 12px;
  font-weight: 400;
  color: var(--teks-lemah);
  background: var(--aktif);
  border-radius: 999px;
  white-space: nowrap;
}

/* Tanggal tarif berlaku turun ke baris kedua: satu baris berisi "Rp 1.250.000
   sejak 1 Jan 2026" memaksa kolom nominal selebar dua kali angkanya, dan di
   layar sempit nominalnya terpotong di tengah angka. */
.tarif-sejak {
  display: block;
}

/* Alasan perbaikan turun ke baris kedua di bawah lencana. Dibatasi lebarnya
   supaya kalimat panjang tidak melebarkan kolom status dan mendorong kolom
   tarif keluar layar. */
.alasan-rusak {
  display: block;
  max-width: 220px;
  margin-top: 4px;
  color: var(--teks-lemah);
  white-space: normal;
}

/* Tombol aksi ditahan satu baris: "Fasilitas" yang terlipat ke bawah "Atur
   tarif" membuat baris dua kali lebih tinggi tanpa ada asersi yang menangkapnya. */
.aksi-baris {
  white-space: nowrap;
}
</style>
