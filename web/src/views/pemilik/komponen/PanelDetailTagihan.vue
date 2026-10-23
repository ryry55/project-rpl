<script setup>
/**
 * Panel rincian satu tagihan: pokok, denda, pembayaran, dan snapshot tarif.
 *
 * Ini bagian terbesar halaman Tagihan dan sengaja dipisah: mencatat pembayaran,
 * menjelaskan denda, dan menampilkan tarif yang dibekukan adalah tiga urusan
 * berbeda yang kebetulan tampil di satu tempat.
 *
 * Kelas `.blok-bayar`, `.denda-uraian`, `.form-bayar`, `.tabel-riwayat`, dan
 * `.snapshot` dipakai skrip E2E untuk menemukan panel ini. Jangan menggantinya
 * hanya karena namanya kurang enak dibaca.
 */
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../../sesi.js'
import {
  rupiah,
  tanggal,
  tanggalHariIni,
  labelStatus,
  pesanGalat,
  galatPerBidang,
} from '../../../format.js'
import { simulasiTanggal } from '../../../simulasi.js'
import PanelKartu from '../../../komponen/ui/PanelKartu.vue'
import MedanForm from '../../../komponen/ui/MedanForm.vue'
import PesanBaris from '../../../komponen/ui/PesanBaris.vue'

const props = defineProps({
  /** Tagihan lengkap beserta `dendaDetail`, hasil `bukaDetail()` di induk. */
  detail: { type: Object, required: true },
  /** Tanggal acuan dari server; dipakai mengisi tanggal formulir pembayaran. */
  tanggalAcuan: { type: String, default: '' },
})

const emit = defineEmits(['tutup', 'berubah'])

const bayarTerbuka = ref(false)
const bayarProses = ref(false)
const bayarGalat = ref('')
const bayarBidang = ref({})
const bayarHasil = ref(null)
const bayarForm = ref({
  jumlah: '',
  tanggal_pembayaran: '',
  metode: '',
  referensi: '',
  diterima_oleh: '',
  catatan: '',
})

/**
 * Pilihan metode bayar.
 *
 * Daftar tertutup ini **salinan** dari `MetodePembayaran::SEMUA` di server, dan
 * itu disengaja hanya karena formulir butuh pilihannya sebelum permintaan apa
 * pun dikirim. Kalau kelak metode baru ditambahkan di server, daftar ini yang
 * harus ikut - dan server tetap menolak nilai yang tidak dikenalnya, jadi
 * salinan yang tertinggal menghasilkan penolakan yang jelas, bukan data salah.
 */
const SEMUA_METODE = [
  { nilai: 'tunai', label: 'Tunai' },
  { nilai: 'transfer', label: 'Transfer bank' },
  { nilai: 'qris', label: 'QRIS' },
  { nilai: 'gateway', label: 'Payment gateway' },
]

/**
 * Pemegang uang yang mungkin.
 *
 * Dimuat dari `/pembayaran/penerima` saat panel dibuka, bukan sekaligus di
 * halaman Tagihan: daftar ini hanya dipakai formulir pembayaran, dan memuatnya
 * untuk setiap kunjungan halaman berarti satu permintaan yang sia-sia pada
 * setiap kunjungan yang tidak mencatat pembayaran.
 */
const daftarPenerima = ref([])
const memuatPenerima = ref(false)

/**
 * Metode yang dipilih benar-benar menuntut pemegang uang.
 *
 * Hanya tunai. Transfer, QRIS, dan gateway masuk rekening, jadi menanyakan
 * "siapa yang memegang uangnya" untuk ketiganya adalah pertanyaan yang tidak
 * punya jawaban - dan medan yang tidak punya jawaban akan diisi asal-asalan.
 */
const butuhPemegang = computed(() => bayarForm.value.metode === 'tunai')

/**
 * Tanggal untuk mengisi formulir pembayaran.
 *
 * Memakai tanggal acuan dari server bila ada, bukan jam dinding browser.
 * Selama simulasi aktif, server menganggap "hari ini" adalah tanggal simulasi —
 * mengisi formulir dengan tanggal nyata akan langsung ditolak 422 sebagai
 * "masa depan", dan pemilik akan mengira sistemnya rusak.
 */
function tanggalUntukFormulir() {
  return props.tanggalAcuan || tanggalHariIni()
}

async function muatPenerima() {
  if (daftarPenerima.value.length || memuatPenerima.value) {
    return
  }
  memuatPenerima.value = true
  try {
    const hasil = await sesi.panggil('/pembayaran/penerima')
    daftarPenerima.value = hasil.data || []
  } catch {
    // Kegagalan memuat daftar ini tidak boleh menutup formulir: server tetap
    // mengisi pemegang uang dengan pencatat bila medannya kosong, jadi
    // pembayaran masih bisa dicatat. Yang hilang hanya kemampuan menyebut
    // orang lain - dan itu dijelaskan di teks bantuan medannya.
    daftarPenerima.value = []
  } finally {
    memuatPenerima.value = false
  }
}

function bukaBayar() {
  bayarForm.value = {
    // Sisa diisikan sebagai nilai awal: itulah jumlah yang paling sering
    // dibayar, dan membiarkannya kosong memaksa pemilik menyalin angka dari
    // tabel di atasnya.
    jumlah: props.detail?.sisa > 0 ? String(props.detail.sisa) : '',
    tanggal_pembayaran: tanggalUntukFormulir(),
    // Metode sengaja TIDAK diberi nilai awal. Membiarkannya "tunai" berarti
    // pemilik yang mengetik jumlah lalu langsung menyimpan akan mencatat
    // transfer sebagai uang tunai - dan rekap tunai per pemegang akan
    // menghitung uang yang tidak pernah lewat tangan siapa pun. Satu pilihan
    // sadar lebih baik daripada satu baris rekap yang salah.
    metode: '',
    referensi: '',
    diterima_oleh: '',
    catatan: '',
  }
  bayarGalat.value = ''
  bayarBidang.value = {}
  bayarHasil.value = null
  bayarTerbuka.value = true
  muatPenerima()
}

async function catatPembayaran() {
  bayarProses.value = true
  bayarGalat.value = ''
  bayarBidang.value = {}
  try {
    const isi = {
      jumlah: Number(bayarForm.value.jumlah),
      tanggal_pembayaran: bayarForm.value.tanggal_pembayaran,
      metode: bayarForm.value.metode,
      referensi: bayarForm.value.referensi || null,
      catatan: bayarForm.value.catatan || null,
    }

    // `diterima_oleh` hanya dikirim untuk tunai. Mengirimnya untuk transfer
    // akan tercatat sebagai "ada yang memegang uang" padahal uangnya di bank.
    if (butuhPemegang.value && bayarForm.value.diterima_oleh) {
      isi.diterima_oleh = Number(bayarForm.value.diterima_oleh)
    }

    const hasil = await sesi.panggil(`/tagihan/${props.detail.id}/pembayaran`, {
      method: 'POST',
      headers: simulasiTanggal.header(),
      body: JSON.stringify(isi),
    })

    bayarHasil.value = hasil.data
    bayarTerbuka.value = false

    // Detail dan daftar sama-sama disegarkan: panel harus menampilkan sisa baru,
    // dan baris di daftar harus menampilkan status baru. Menyegarkan hanya salah
    // satunya menghasilkan dua angka berbeda untuk tagihan yang sama di satu
    // layar.
    emit('berubah')
  } catch (e) {
    bayarGalat.value = pesanGalat(e)
    bayarBidang.value = galatPerBidang(e)
  } finally {
    bayarProses.value = false
  }
}

// --- Verifikasi pembayaran (T20) -------------------------------------------

/** Pembayaran yang sedang diperiksa. `null` = tidak ada formulir terbuka. */
const verifikasiUntuk = ref(null)
const verifikasiProses = ref(false)
const verifikasiGalat = ref('')
const verifikasiBidang = ref({})
const verifikasiForm = ref({ keputusan: 'terima', catatan: '' })

function bukaVerifikasi(p) {
  verifikasiUntuk.value = p.id
  // Bawaannya "terima": pemeriksaan yang dilakukan memang untuk memastikan
  // uangnya benar, dan kasus yang paling sering adalah cocok. Menolak tetap
  // menuntut alasan, jadi pilihan ini tidak bisa dipakai untuk menolak
  // tanpa berpikir.
  verifikasiForm.value = { keputusan: 'terima', catatan: '' }
  verifikasiGalat.value = ''
  verifikasiBidang.value = {}
}

function tutupVerifikasi() {
  verifikasiUntuk.value = null
}

async function kirimVerifikasi() {
  verifikasiProses.value = true
  verifikasiGalat.value = ''
  verifikasiBidang.value = {}
  try {
    await sesi.panggil(`/pembayaran/${verifikasiUntuk.value}/verifikasi`, {
      method: 'POST',
      body: JSON.stringify({
        keputusan: verifikasiForm.value.keputusan,
        catatan: verifikasiForm.value.catatan || null,
      }),
    })
    verifikasiUntuk.value = null
    emit('berubah')
  } catch (e) {
    verifikasiGalat.value = pesanGalat(e)
    verifikasiBidang.value = galatPerBidang(e)
  } finally {
    verifikasiProses.value = false
  }
}

/**
 * Label keadaan pemeriksaan satu baris pembayaran.
 *
 * Tiga keadaan, bukan dua: "belum diperiksa" berbeda dari "ditolak". Layar yang
 * menyamakan keduanya akan menandai uang yang belum sempat diperiksa sebagai
 * uang bermasalah, dan pemilik akan mengejar pengelolanya tanpa sebab.
 */
function labelVerifikasi(p) {
  const v = p.verifikasi_terakhir
  if (!v) {
    return 'Belum diperiksa'
  }
  return v.keputusan === 'terima' ? 'Diterima' : 'Ditolak'
}

function kelasVerifikasi(p) {
  const v = p.verifikasi_terakhir
  if (!v) {
    return 'verifikasi-belum'
  }
  return v.keputusan === 'terima' ? 'verifikasi-terima' : 'verifikasi-tolak'
}

onMounted(() => {
  // Daftar pemegang dimuat saat panel dibuka, bukan saat formulir dibuka,
  // supaya pilihannya sudah siap ketika pemilik menekan "Catat pembayaran".
  muatPenerima()
})
</script>

<template>
  <PanelKartu
    :judul="`Tagihan ${detail.kontrak?.penghuni?.nama} — kamar ${detail.kontrak?.kamar?.nomor}`"
  >
    <template #keterangan>
      <p class="lemah mungil">
        Periode {{ tanggal(detail.periode) }} · jatuh tempo {{ tanggal(detail.jatuh_tempo) }}
      </p>
    </template>
    <template #aksi>
      <button class="halus mungil" @click="emit('tutup')">Tutup</button>
    </template>

    <div class="tabel-bungkus">
      <table class="tabel">
        <thead>
          <tr>
            <th>Rincian</th>
            <th class="kanan">Nilai</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in detail.rincian" :key="r.id">
            <td>{{ r.nama }}</td>
            <td class="kanan">{{ rupiah(r.nilai) }}</td>
          </tr>
          <tr class="baris-total">
            <td><strong>Pokok</strong></td>
            <td class="kanan"><strong>{{ rupiah(detail.pokok) }}</strong></td>
          </tr>
          <tr>
            <td class="lemah">Denda</td>
            <td class="kanan lemah">{{ rupiah(detail.denda) }}</td>
          </tr>
          <tr class="baris-total">
            <td><strong>Total</strong></td>
            <td class="kanan"><strong>{{ rupiah(detail.total) }}</strong></td>
          </tr>
          <tr v-if="detail.dibayar > 0">
            <td class="lemah">Sudah dibayar</td>
            <td class="kanan lemah">− {{ rupiah(detail.dibayar) }}</td>
          </tr>
          <tr v-if="detail.dibayar > 0" class="baris-total">
            <td><strong>Sisa</strong></td>
            <td class="kanan"><strong>{{ rupiah(detail.sisa) }}</strong></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Uraian denda: angka tanpa penjelasan selalu jadi pertanyaan.
         Sejak T17 kalimatnya bergantung pada bentuk denda yang dipilih pemilik.
         Tiga bentuk, tiga kalimat: persen (menyebut tenggang dan plafon),
         nominal (nominal tetap per bulan), dan tanpa denda (tidak menyebut
         apa pun). `berjalan` saja tidak cukup membedakan yang terakhir dari
         yang kedua — keduanya sama-sama `false` — jadi cabangnya memakai
         `bentuk` yang dikirim server. -->
    <div v-if="detail.dendaDetail" class="denda-uraian">
      <p v-if="detail.dendaDetail.berhenti" class="mungil lemah">
        {{ detail.dendaDetail.alasan_berhenti }} Denda tidak berjalan lagi.
      </p>
      <!-- Tanpa denda: tidak ada yang perlu dijelaskan selain bahwa tidak ada
           dendanya. Menulis "nominal tetap Rp 0" akan terbaca seperti
           kebijakan yang belum diisi, padahal ini pilihan pemilik. -->
      <template v-else-if="detail.dendaDetail.bentuk === 'tanpa'">
        <p class="mungil">
          Kebijakan kos ini tidak menerapkan denda keterlambatan. Tidak ada
          tambahan biaya meskipun tagihan lewat jatuh tempo.
        </p>
        <p class="mungil lemah">Dihitung pada {{ tanggal(detail.dendaDetail.tanggal_acuan) }}.</p>
      </template>
      <template v-else>
        <p class="mungil">
          <template v-if="detail.dendaDetail.hari_terlambat <= 0">
            Belum jatuh tempo — tidak ada denda.
          </template>
          <!-- Denda persen: berjalan tiap hari, punya tenggang dan plafon. -->
          <template v-else-if="detail.dendaDetail.bentuk === 'persen'">
            <template
              v-if="detail.dendaDetail.hari_terlambat <= detail.dendaDetail.masa_tenggang"
            >
              Terlambat {{ detail.dendaDetail.hari_terlambat }} hari, masih dalam masa tenggang
              {{ detail.dendaDetail.masa_tenggang }} hari — belum ada denda.
            </template>
            <template v-else>
              Terlambat <strong>{{ detail.dendaDetail.hari_terlambat }} hari</strong>,
              lewat tenggang {{ detail.dendaDetail.masa_tenggang }} hari, jadi
              <strong>{{ detail.dendaDetail.hari_denda }} hari</strong> kena denda.
            </template>
          </template>
          <!-- Denda nominal: tidak berjalan per hari, jadi cukup jumlah hari
               terlambat tanpa menyebut tenggang maupun hari kena denda. -->
          <template v-else>
            Terlambat <strong>{{ detail.dendaDetail.hari_terlambat }} hari</strong> dari
            jatuh tempo {{ tanggal(detail.dendaDetail.jatuh_tempo) }}.
          </template>
        </p>

        <!-- Plafon hanya bermakna untuk denda persen. `null` di sini bukan nol:
             artinya bentuk denda ini memang tidak dibatasi plafon. -->
        <p v-if="detail.dendaDetail.bentuk === 'persen'" class="mungil lemah">
          Denda {{ rupiah(detail.dendaDetail.denda) }} dari maksimum
          {{ rupiah(detail.dendaDetail.plafon) }}.
          <template v-if="detail.dendaDetail.sisa_hari_ke_plafon > 0">
            Mencapai plafon dalam {{ detail.dendaDetail.sisa_hari_ke_plafon }} hari lagi.
          </template>
          <template v-else>Plafon sudah tercapai.</template>
        </p>
        <p v-else class="mungil lemah">
          Denda {{ rupiah(detail.dendaDetail.denda) }} — nominal tetap, dihitung per bulan
          keterlambatan.
        </p>

        <p class="mungil lemah">Dihitung pada {{ tanggal(detail.dendaDetail.tanggal_acuan) }}.</p>
      </template>
    </div>

    <!-- Pembayaran (T5). Diletakkan sebelum snapshot karena inilah aksi yang
         paling sering dipakai di panel ini. -->
    <div class="blok-bayar">
      <!-- Hasil pencatatan: yang penting bukan "berhasil", melainkan status
           berubah dari apa ke apa. -->
      <PesanBaris v-if="bayarHasil" jenis="sukses" mungil>
        Pembayaran {{ rupiah(bayarHasil.pembayaran.jumlah) }} tercatat.
        Status berubah dari <strong>{{ labelStatus(bayarHasil.status_sebelum) }}</strong>
        menjadi <strong>{{ labelStatus(bayarHasil.status_sesudah) }}</strong>.
        <template v-if="bayarHasil.sisa > 0">Sisa {{ rupiah(bayarHasil.sisa) }}.</template>
        <template v-else>Tagihan lunas.</template>
      </PesanBaris>

      <!-- Pesan keadaan hanya muncul bila tidak ada hasil yang baru dicatat.
           Kalau keduanya tampil, "sudah lunas" muncul dua kali berturut-turut
           dan pemilik membaca kalimat yang sama dua kali. -->
      <PesanBaris v-if="!bayarHasil && detail.status === 'lunas'" jenis="netral" mungil>
        Tagihan ini sudah lunas. Tidak ada sisa yang bisa dibayar.
      </PesanBaris>
      <PesanBaris
        v-else-if="!bayarHasil && detail.status === 'dibatalkan'"
        jenis="netral"
        mungil
      >
        Tagihan ini dibatalkan. Pembayaran tidak dapat dicatat.
      </PesanBaris>

      <!-- Tombol tetap tersedia setelah pembayaran tercatat selama masih ada
           sisa. Menyembunyikannya karena "sudah ada hasil" akan memaksa
           pemilik menutup dan membuka ulang panel hanya untuk mencicil lagi. -->
      <div
        v-if="
          detail.status !== 'lunas' &&
          detail.status !== 'dibatalkan' &&
          !bayarTerbuka &&
          sesi.boleh('catat_pembayaran')
        "
        class="aksi"
      >
        <button class="halus mungil" @click="bukaBayar">
          Catat pembayaran{{ detail.sisa > 0 ? ' — sisa ' + rupiah(detail.sisa) : '' }}
        </button>
      </div>

      <form v-if="bayarTerbuka" class="bidang form-bayar" @submit.prevent="catatPembayaran">
        <div class="baris-bidang">
          <MedanForm
            id="bayar-jumlah"
            label="Jumlah dibayar"
            :galat="bayarBidang.jumlah"
            bantuan="Rupiah bulat. Kelebihan bayar ditolak, tidak jadi saldo."
          >
            <input
              id="bayar-jumlah"
              v-model="bayarForm.jumlah"
              type="number"
              min="1"
              step="1"
              required
              :aria-invalid="Boolean(bayarBidang.jumlah)"
            />
          </MedanForm>
          <MedanForm
            id="bayar-tanggal"
            label="Tanggal pembayaran"
            :galat="bayarBidang.tanggal_pembayaran"
            :bantuan="`Tidak boleh melewati hari ini (${tanggal(tanggalUntukFormulir())}).`"
          >
            <input
              id="bayar-tanggal"
              v-model="bayarForm.tanggal_pembayaran"
              type="date"
              required
              :aria-invalid="Boolean(bayarBidang.tanggal_pembayaran)"
            />
          </MedanForm>
        </div>

        <!-- Metode wajib dan tanpa nilai bawaan. Lihat komentar di `bukaBayar`:
             nilai bawaan "tunai" akan membuat setiap transfer yang dicatat
             tergesa-gesa terhitung sebagai uang tunai di rekap. -->
        <div class="baris-bidang">
          <MedanForm
            id="bayar-metode"
            label="Metode"
            :galat="bayarBidang.metode"
            bantuan="Menentukan masuk kelompok mana di rekap pendapatan."
          >
            <select
              id="bayar-metode"
              v-model="bayarForm.metode"
              required
              :aria-invalid="Boolean(bayarBidang.metode)"
            >
              <option value="" disabled>Pilih metode…</option>
              <option v-for="m in SEMUA_METODE" :key="m.nilai" :value="m.nilai">
                {{ m.label }}
              </option>
            </select>
          </MedanForm>
          <MedanForm
            id="bayar-referensi"
            label="Nomor referensi"
            :galat="bayarBidang.referensi"
            bantuan="Nomor transfer atau ID transaksi. Boleh dikosongkan."
          >
            <template #label-ekstra> <span class="lemah">(opsional)</span></template>
            <input
              id="bayar-referensi"
              v-model="bayarForm.referensi"
              type="text"
              maxlength="100"
              placeholder="mis. TRX-2026-001"
            />
          </MedanForm>
        </div>

        <!-- Pemegang uang hanya ditanyakan untuk tunai. Menanyakannya untuk
             transfer akan diisi asal-asalan, karena jawabannya memang tidak ada:
             uangnya sudah di rekening sejak awal. -->
        <MedanForm
          v-if="butuhPemegang"
          id="bayar-penerima"
          label="Uang dipegang oleh"
          :galat="bayarBidang.diterima_oleh"
          bantuan="Kosongkan bila Anda sendiri yang menerimanya."
        >
          <select id="bayar-penerima" v-model="bayarForm.diterima_oleh">
            <option value="">Saya sendiri</option>
            <option v-for="p in daftarPenerima" :key="p.id" :value="String(p.id)">
              {{ p.nama }}
            </option>
          </select>
        </MedanForm>

        <MedanForm id="bayar-catatan" label="Catatan">
          <template #label-ekstra> <span class="lemah">(opsional)</span></template>
          <input id="bayar-catatan" v-model="bayarForm.catatan" type="text" maxlength="255" />
        </MedanForm>

        <PesanBaris v-if="bayarGalat" jenis="galat">{{ bayarGalat }}</PesanBaris>

        <div class="aksi">
          <button type="submit" :disabled="bayarProses">
            {{ bayarProses ? 'Menyimpan…' : 'Simpan pembayaran' }}
          </button>
          <button type="button" class="halus" @click="bayarTerbuka = false">Batal</button>
        </div>
      </form>

      <!-- Riwayat: uang yang diterima harus bisa diperiksa ulang, bukan hanya
           dijumlahkan menjadi satu angka. Sejak T20 setiap baris juga menyebut
           metode dan keadaan pemeriksaannya. -->
      <div v-if="detail.pembayaran?.length" class="riwayat-bayar">
        <p class="mungil lemah">Riwayat pembayaran</p>
        <div class="tabel-bungkus">
          <table class="tabel tabel-riwayat">
            <thead>
              <tr>
                <th>Tanggal</th>
                <th class="kanan">Jumlah</th>
                <th>Metode</th>
                <th>Pemeriksaan</th>
                <th>Catatan</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in detail.pembayaran" :key="p.id">
                <td>{{ tanggal(p.tanggal_pembayaran) }}</td>
                <td class="kanan">{{ rupiah(p.jumlah) }}</td>
                <td class="mungil">
                  <!-- `metode` null hanya mungkin untuk baris sebelum T20.
                       Menampilkannya sebagai "Tunai" berarti mengarang fakta
                       tentang uang, jadi ia dibiarkan terlihat sebagai lubang. -->
                  <span :class="{ lemah: !p.metode }">{{ p.metode_label || 'Tidak tercatat' }}</span>
                  <template v-if="p.referensi">
                    <br /><span class="lemah">{{ p.referensi }}</span>
                  </template>
                  <template v-if="p.diterima_oleh_nama">
                    <br /><span class="lemah">dipegang {{ p.diterima_oleh_nama }}</span>
                  </template>
                </td>
                <td class="mungil">
                  <span class="lencana-verifikasi" :class="kelasVerifikasi(p)">
                    {{ labelVerifikasi(p) }}
                  </span>
                  <template v-if="p.verifikasi_terakhir?.catatan">
                    <br /><span class="lemah">{{ p.verifikasi_terakhir.catatan }}</span>
                  </template>
                </td>
                <td class="mungil lemah">{{ p.catatan || '—' }}</td>
                <td class="kanan">
                  <button
                    v-if="sesi.boleh('verifikasi_pembayaran') && verifikasiUntuk !== p.id"
                    type="button"
                    class="halus mungil tombol-verifikasi"
                    @click="bukaVerifikasi(p)"
                  >
                    {{ p.verifikasi_terakhir ? 'Periksa ulang' : 'Verifikasi' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Formulir pemeriksaan. Diletakkan di luar tabel, bukan di dalam
             baris: formulir yang dirender di dalam <td> membuat tinggi baris
             melompat dan seluruh tabel bergeser setiap kali dibuka. -->
        <form
          v-if="verifikasiUntuk"
          class="bidang verifikasi-form"
          @submit.prevent="kirimVerifikasi"
        >
          <p class="mungil lemah">
            Pemeriksaan tidak mengubah jumlah uang maupun status tagihan. Ia hanya
            menyatakan uang ini sudah diperiksa, dan pemeriksaan terakhir yang berlaku.
          </p>
          <div class="baris-bidang">
            <MedanForm
              id="verifikasi-keputusan"
              label="Keputusan"
              :galat="verifikasiBidang.keputusan"
            >
              <select id="verifikasi-keputusan" v-model="verifikasiForm.keputusan">
                <option value="terima">Terima — uangnya cocok</option>
                <option value="tolak">Tolak — ada yang tidak cocok</option>
              </select>
            </MedanForm>
            <MedanForm
              id="verifikasi-catatan"
              label="Catatan"
              :galat="verifikasiBidang.catatan"
              :bantuan="
                verifikasiForm.keputusan === 'tolak'
                  ? 'Wajib diisi: penolakan harus menyebutkan alasannya.'
                  : 'Boleh dikosongkan.'
              "
            >
              <input
                id="verifikasi-catatan"
                v-model="verifikasiForm.catatan"
                type="text"
                maxlength="255"
                :required="verifikasiForm.keputusan === 'tolak'"
              />
            </MedanForm>
          </div>

          <PesanBaris v-if="verifikasiGalat" jenis="galat">{{ verifikasiGalat }}</PesanBaris>

          <div class="aksi">
            <button type="submit" :disabled="verifikasiProses">
              {{ verifikasiProses ? 'Menyimpan…' : 'Simpan pemeriksaan' }}
            </button>
            <button type="button" class="halus" @click="tutupVerifikasi">Batal</button>
          </div>
        </form>
      </div>
    </div>

    <details class="snapshot">
      <summary class="mungil lemah">Tarif yang dibekukan saat terbit</summary>
      <pre class="mungil">{{ JSON.stringify(detail.tarif_terpakai, null, 2) }}</pre>
    </details>
  </PanelKartu>
</template>

<style scoped>
.baris-total td {
  border-top: 1px solid var(--garis);
  background: #fafbfc;
}

.snapshot {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid var(--garis);
}

.snapshot summary {
  cursor: pointer;
}

.snapshot pre {
  margin: 10px 0 0;
  padding: 12px;
  overflow-x: auto;
  background: #f6f7f9;
  border: 1px solid var(--garis);
  border-radius: 8px;
}

.denda-uraian {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--garis);
}

.denda-uraian p {
  margin: 0 0 4px;
}

.blok-bayar {
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px solid var(--garis);
  display: grid;
  gap: 10px;
}

/* Paragraf di dalam blok ini memakai `gap` sebagai jarak, bukan margin
   bawaan browser. Tanpa ini jaraknya menjadi dua kali lipat — 10px dari gap
   ditambah 1em margin, dan blok terlihat renggang tanpa alasan. */
.blok-bayar p {
  margin: 0;
}

.form-bayar {
  padding: 14px;
  background: #fbfcfe;
  border: 1px solid var(--garis);
  border-radius: 10px;
}

.verifikasi-form {
  margin-top: 4px;
  padding: 14px;
  background: #fbfcfe;
  border: 1px solid var(--garis);
  border-radius: 10px;
}

.riwayat-bayar {
  display: grid;
  gap: 6px;
}

.riwayat-bayar p {
  margin: 0;
}

.tabel-riwayat th,
.tabel-riwayat td {
  padding-top: 7px;
  padding-bottom: 7px;
  vertical-align: top;
}

/* Tiga keadaan pemeriksaan, tiga warna. "Belum diperiksa" sengaja abu-abu,
   bukan merah: ia bukan masalah, hanya sesuatu yang belum dikerjakan. */
.lencana-verifikasi {
  display: inline-block;
  padding: 1px 7px;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
  border-radius: 999px;
}

.verifikasi-belum {
  color: var(--teks-lemah);
  background: #eef0f3;
}

.verifikasi-terima {
  color: var(--sukses);
  background: var(--sukses-lemah);
}

.verifikasi-tolak {
  color: var(--bahaya);
  background: var(--bahaya-lemah);
}
</style>
