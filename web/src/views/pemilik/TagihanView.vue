<script setup>
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { rupiah, tanggal, tanggalHariIni, pesanGalat, galatPerBidang } from '../../format.js'
import { simulasiTanggal } from '../../simulasi.js'
import KendaliSimulasi from '../../komponen/KendaliSimulasi.vue'

const daftar = ref([])
const kontrak = ref([])
const memuat = ref(true)
const galatMuat = ref('')

const formTerbuka = ref(false)
const menyimpan = ref(false)
const galatForm = ref('')
const galatBidang = ref({})
const form = ref({ kontrak_id: '', periode: '' })

const detail = ref(null)
const memuatDetail = ref(false)

// --- Pembayaran (T5) --------------------------------------------------------
// Form pembayaran hidup di dalam panel detail, bukan sebagai panel sendiri:
// mencatat pembayaran selalu tentang satu tagihan tertentu, dan memaksa pemilik
// memilih ulang tagihan dari daftar hanya menambah satu langkah yang bisa salah.
const bayarTerbuka = ref(false)
const bayarProses = ref(false)
const bayarGalat = ref('')
const bayarBidang = ref({})
const bayarForm = ref({ jumlah: '', tanggal_pembayaran: '', catatan: '' })
const bayarHasil = ref(null)

// --- Terbit massal (T4) -----------------------------------------------------
// Hasil batch ditampilkan sebagai ringkasan yang bisa dibaca, bukan sekadar
// "berhasil": pemilik harus tahu berapa yang terbit, berapa yang dilewati, dan
// — yang paling penting — kamar mana yang gagal dan mengapa.
const massalTerbuka = ref(false)
const massalProses = ref(false)
const massalHasil = ref(null)
const massalGalat = ref('')
const massalPeriode = ref('')

// --- Simulasi tanggal (mode demo) ------------------------------------------
// Tanggalnya sendiri disimpan di `simulasi.js` karena juga dipakai halaman
// Tunggakan. Kalau tiap halaman menyimpan sendiri, dua layar bisa memakai
// tanggal acuan berbeda untuk tagihan yang sama.
const simulasiDipakai = ref(false)
const tanggalAcuan = ref('')

/** Header tambahan untuk setiap permintaan selama simulasi aktif. */
const headerSimulasi = simulasiTanggal.header

const totalPokok = computed(() => daftar.value.reduce((j, t) => j + t.pokok, 0))
const totalDenda = computed(() => daftar.value.reduce((j, t) => j + (t.denda || 0), 0))

/**
 * Total yang belum masuk.
 *
 * Dihitung di frontend dari `sisa` tiap baris, bukan dari `total - dibayar`
 * seluruhan: tagihan yang dibatalkan tidak boleh ikut dihitung sebagai piutang,
 * dan `sisa` sudah memperhitungkan itu per baris.
 */
const totalSisa = computed(() =>
  daftar.value.reduce((j, t) => j + (t.status === 'dibatalkan' ? 0 : t.sisa || 0), 0),
)

function periodeHariIni() {
  const sekarang = new Date()
  const bulan = String(sekarang.getMonth() + 1).padStart(2, '0')
  return `${sekarang.getFullYear()}-${bulan}-01`
}

/**
 * Tanggal untuk mengisi formulir pembayaran.
 *
 * Memakai tanggal acuan dari server bila ada, bukan jam dinding browser.
 * Selama simulasi aktif, server menganggap "hari ini" adalah tanggal simulasi —
 * mengisi formulir dengan tanggal nyata akan langsung ditolak 422 sebagai
 * "masa depan", dan pemilik akan mengira sistemnya rusak.
 */
function tanggalUntukFormulir() {
  return tanggalAcuan.value || tanggalHariIni()
}

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const [hasilTagihan, hasilKontrak] = await Promise.all([
      sesi.panggil('/tagihan', { headers: headerSimulasi() }),
      sesi.panggil('/kontrak'),
    ])
    daftar.value = hasilTagihan.data
    kontrak.value = hasilKontrak.data.filter((k) => k.status === 'aktif')

    // Status simulasi dibaca dari server, bukan ditebak dari ada/tidaknya
    // header. Server yang tahu apakah mode demo aktif; menganggap "ada tanggal
    // berarti berlaku" akan salah saat APP_DEMO_MODE=false.
    simulasiDipakai.value = Boolean(hasilTagihan.meta?.simulasi)
    tanggalAcuan.value = hasilTagihan.meta?.tanggal_acuan || ''

    // Panel rincian harus ikut disegarkan saat tanggal acuan berubah. Tanpa ini,
    // setelah simulasi dibersihkan daftar menampilkan denda "—" sementara panel
    // di atasnya masih menampilkan Rp 450.000 — dua angka berbeda untuk tagihan
    // yang sama di satu layar, dan tidak ada cara tahu mana yang benar.
    if (detail.value) {
      const id = detail.value.id
      await bukaDetail({ id })
    }
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

function bukaForm() {
  form.value = { kontrak_id: '', periode: periodeHariIni() }
  galatForm.value = ''
  galatBidang.value = {}
  formTerbuka.value = true
}

async function terbitkan() {
  menyimpan.value = true
  galatForm.value = ''
  galatBidang.value = {}
  try {
    await sesi.panggil('/tagihan', {
      method: 'POST',
      body: JSON.stringify({
        kontrak_id: Number(form.value.kontrak_id),
        periode: form.value.periode,
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

async function bukaDetail(tagihan) {
  memuatDetail.value = true
  detail.value = null
  try {
    // Dua panggilan, sengaja: /tagihan/{id} memberi rincian pokok yang dibekukan,
    // /tagihan/{id}/denda memberi uraian denda (hari terlambat, plafon, sisa hari).
    // Menggabungkannya di sini membuat layar bisa menjelaskan angka, bukan hanya
    // menampilkannya.
    const [hasilTagihan, hasilDenda] = await Promise.all([
      sesi.panggil(`/tagihan/${tagihan.id}`, { headers: headerSimulasi() }),
      sesi.panggil(`/tagihan/${tagihan.id}/denda`, { headers: headerSimulasi() }),
    ])
    detail.value = { ...hasilTagihan.data, dendaDetail: hasilDenda.data }
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuatDetail.value = false
  }
}

function tutupDetail() {
  detail.value = null
  bayarTerbuka.value = false
  bayarHasil.value = null
}

/**
 * Label status yang bisa dibaca.
 *
 * `belum_bayar` diganti spasi menjadi "belum bayar" di templat; `lunas` dan
 * `sebagian` tidak butuh apa-apa. Fungsi ini dipakai di tempat yang menyebut
 * status di tengah kalimat, supaya tidak ada tiga cara berbeda menuliskannya.
 */
function labelStatus(status) {
  return String(status || '').replace(/_/g, ' ')
}

function bukaBayar() {
  bayarForm.value = {
    // Sisa diisikan sebagai nilai awal: itulah jumlah yang paling sering
    // dibayar, dan membiarkannya kosong memaksa pemilik menyalin angka dari
    // tabel di atasnya.
    jumlah: detail.value?.sisa > 0 ? String(detail.value.sisa) : '',
    tanggal_pembayaran: tanggalUntukFormulir(),
    catatan: '',
  }
  bayarGalat.value = ''
  bayarBidang.value = {}
  bayarHasil.value = null
  bayarTerbuka.value = true
}

async function catatPembayaran() {
  bayarProses.value = true
  bayarGalat.value = ''
  bayarBidang.value = {}
  try {
    const hasil = await sesi.panggil(`/tagihan/${detail.value.id}/pembayaran`, {
      method: 'POST',
      headers: headerSimulasi(),
      body: JSON.stringify({
        jumlah: Number(bayarForm.value.jumlah),
        tanggal_pembayaran: bayarForm.value.tanggal_pembayaran,
        catatan: bayarForm.value.catatan || null,
      }),
    })

    bayarHasil.value = hasil.data
    bayarTerbuka.value = false

    // Detail dan daftar sama-sama disegarkan: panel harus menampilkan sisa baru,
    // dan baris di daftar harus menampilkan status baru. Menyegarkan hanya salah
    // satunya menghasilkan dua angka berbeda untuk tagihan yang sama di satu
    // layar.
    await bukaDetail({ id: detail.value.id })
    await muat()
  } catch (e) {
    bayarGalat.value = pesanGalat(e)
    bayarBidang.value = galatPerBidang(e)
  } finally {
    bayarProses.value = false
  }
}

function bukaMassal() {
  massalPeriode.value = periodeHariIni()
  massalHasil.value = null
  massalGalat.value = ''
  massalTerbuka.value = true
}

/**
 * Tiga nasib kontrak perlu tiga label berbeda.
 *
 * Kalau semuanya ditampilkan "berhasil", batch yang separuh gagal karena tarif
 * belum diisi akan tampak sama dengan batch yang berhasil penuh.
 */
function labelHasil(status) {
  return { terbit: 'Terbit', dilewati: 'Dilewati', gagal: 'Gagal' }[status] || status
}

function kelasHasil(status) {
  // `lencana-status` global sudah punya warna untuk lunas (hijau) dan
  // dibatalkan (merah). Dipakai ulang supaya bahasa warnanya konsisten.
  return { terbit: 'lunas', dilewati: 'selesai', gagal: 'dibatalkan' }[status] || 'kosong'
}

async function terbitkanMassal() {
  massalProses.value = true
  massalGalat.value = ''
  massalHasil.value = null
  try {
    const hasil = await sesi.panggil('/tagihan/terbitkan', {
      method: 'POST',
      body: JSON.stringify({ periode: massalPeriode.value }),
    })
    massalHasil.value = hasil.data

    // Daftar ikut disegarkan supaya tagihan yang baru terbit langsung terlihat.
    // Tanpa ini, pemilik melihat ringkasan "12 terbit" di bawah tabel yang
    // masih kosong.
    await muat()
  } catch (e) {
    // 409 bukan kesalahan sistem: artinya periode ini sudah pernah diterbitkan.
    // Server tetap mengirim rincian yang berguna, jadi ditampilkan sebagai
    // hasil, bukan sebagai galat merah.
    if (e?.status === 409 && e?.body?.data) {
      massalHasil.value = e.body.data
    } else {
      massalGalat.value = pesanGalat(e)
    }
  } finally {
    massalProses.value = false
  }
}

onMounted(muat)
</script>

<template>
  <div class="halaman">
    <header class="kepala">
      <div>
        <h1>Tagihan</h1>
        <p class="lemah mungil">
          {{ daftar.length }} tagihan · pokok {{ rupiah(totalPokok) }}
          <template v-if="totalDenda > 0">· denda {{ rupiah(totalDenda) }}</template>
          <template v-if="totalSisa > 0">· belum masuk {{ rupiah(totalSisa) }}</template>
        </p>
      </div>
      <div class="aksi-kepala">
        <button v-if="!massalTerbuka" class="halus" @click="bukaMassal">Terbitkan semua</button>
        <button v-if="!formTerbuka" @click="bukaForm">Terbitkan tagihan</button>
      </div>
    </header>

    <!-- Terbit massal (T4). Panel terpisah dari form satu kontrak karena
         maksudnya berbeda: yang ini untuk sebulan sekaligus. -->
    <section v-if="massalTerbuka" class="kartu panel panel-massal">
      <div class="panel-kepala">
        <div>
          <h2>Terbitkan semua kontrak aktif</h2>
          <p class="bantuan mungil">
            Satu tagihan per kontrak untuk periode ini. Kontrak yang sudah punya tagihan
            dilewati, bukan diterbitkan dua kali.
          </p>
        </div>
        <button class="halus mungil" @click="massalTerbuka = false">Tutup</button>
      </div>

      <form class="bidang" @submit.prevent="terbitkanMassal">
        <div class="baris-bidang">
          <div>
            <label for="periode-massal">Periode</label>
            <input id="periode-massal" v-model="massalPeriode" type="date" required />
            <p class="bantuan">Tanggal berapa pun dalam bulan itu; disimpan sebagai tanggal 1.</p>
          </div>
        </div>

        <p v-if="massalGalat" class="pesan-galat">{{ massalGalat }}</p>

        <div class="aksi">
          <button type="submit" :disabled="massalProses">
            {{ massalProses ? 'Menerbitkan…' : 'Terbitkan semua' }}
          </button>
        </div>
      </form>

      <!-- Ringkasan hasil. Tiga angka dipisah karena artinya berbeda bagi
           pemilik: terbit = berhasil, dilewati = tidak perlu tindakan,
           gagal = perlu diperbaiki. -->
      <div v-if="massalHasil" class="hasil-massal">
        <p v-if="massalHasil.terbit > 0" class="pesan-sukses mungil">
          {{ massalHasil.terbit }} tagihan terbit untuk periode
          {{ tanggal(massalHasil.periode) }}.
        </p>
        <!-- Netral, bukan merah: tidak ada yang salah di sini. Seluruh kontrak
             memang sudah tertagih, dan itu keadaan yang baik. -->
        <p v-else class="pesan-netral mungil">
          Tidak ada tagihan baru yang terbit. Semua kontrak aktif sudah punya tagihan
          periode ini, atau belum mulai.
        </p>

        <p class="mungil lemah">
          {{ massalHasil.terbit }} terbit · {{ massalHasil.dilewati }} dilewati ·
          {{ massalHasil.gagal }} gagal
        </p>

        <details v-if="massalHasil.rincian.length" class="rincian-massal">
          <summary class="mungil lemah">Rincian per kontrak</summary>
          <div class="tabel-bungkus">
            <table class="tabel">
              <thead>
                <tr>
                  <th>Penghuni</th>
                  <th>Kamar</th>
                  <th>Hasil</th>
                  <th>Keterangan</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in massalHasil.rincian" :key="r.kontrak_id">
                  <td>{{ r.penghuni || '—' }}</td>
                  <td>{{ r.kamar || '—' }}</td>
                  <td>
                    <span class="lencana-status" :class="'status-' + kelasHasil(r.status)">
                      {{ labelHasil(r.status) }}
                    </span>
                  </td>
                  <td class="mungil lemah">
                    <template v-if="r.status === 'terbit'">
                      Jatuh tempo {{ tanggal(r.jatuh_tempo) }} · {{ rupiah(r.pokok) }}
                    </template>
                    <template v-else>{{ r.alasan }}</template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </details>
      </div>
    </section>

    <!-- Kendali simulasi tanggal, dipakai bersama halaman Tunggakan. -->
    <KendaliSimulasi
      :dipakai="simulasiDipakai"
      :tanggal-acuan="tanggalAcuan"
      konteks="Denda"
      @berubah="muat"
    />

    <p v-if="galatMuat" class="pesan-galat">{{ galatMuat }}</p>

    <section v-if="formTerbuka" class="kartu panel">
      <h2>Terbitkan tagihan</h2>
      <form class="bidang" @submit.prevent="terbitkan">
        <div class="baris-bidang">
          <div>
            <label for="kontrak">Kontrak</label>
            <select id="kontrak" v-model="form.kontrak_id" :aria-invalid="Boolean(galatBidang.kontrak_id)">
              <option value="" disabled>Pilih kontrak</option>
              <option v-for="k in kontrak" :key="k.id" :value="k.id">
                {{ k.penghuni?.nama }} — kamar {{ k.kamar?.nomor }}
              </option>
            </select>
            <p v-if="galatBidang.kontrak_id" class="galat-bidang">{{ galatBidang.kontrak_id }}</p>
          </div>
          <div>
            <label for="periode">Periode</label>
            <input
              id="periode"
              v-model="form.periode"
              type="date"
              :aria-invalid="Boolean(galatBidang.periode)"
            />
            <p v-if="galatBidang.periode" class="galat-bidang">{{ galatBidang.periode }}</p>
            <p v-else class="bantuan">Tanggal berapa pun dalam bulan itu; disimpan sebagai tanggal 1.</p>
          </div>
        </div>

        <p v-if="galatForm" class="pesan-galat">{{ galatForm }}</p>

        <div class="aksi">
          <button type="submit" :disabled="menyimpan">
            {{ menyimpan ? 'Menerbitkan…' : 'Terbitkan' }}
          </button>
          <button type="button" class="halus" @click="formTerbuka = false">Batal</button>
        </div>
      </form>
    </section>

    <section v-if="detail" class="kartu panel">
      <div class="panel-kepala">
        <div>
          <h2>Tagihan {{ detail.kontrak?.penghuni?.nama }} — kamar {{ detail.kontrak?.kamar?.nomor }}</h2>
          <p class="lemah mungil">
            Periode {{ tanggal(detail.periode) }} · jatuh tempo {{ tanggal(detail.jatuh_tempo) }}
          </p>
        </div>
        <button class="halus mungil" @click="tutupDetail">Tutup</button>
      </div>

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

      <!-- Uraian denda: angka tanpa penjelasan selalu jadi pertanyaan. -->
      <div v-if="detail.dendaDetail" class="denda-uraian">
        <p v-if="detail.dendaDetail.berhenti" class="mungil lemah">
          {{ detail.dendaDetail.alasan_berhenti }} Denda tidak berjalan lagi.
        </p>
        <template v-else>
          <p class="mungil">
            <template v-if="detail.dendaDetail.hari_terlambat <= 0">
              Belum jatuh tempo — tidak ada denda.
            </template>
            <template v-else-if="detail.dendaDetail.hari_terlambat <= detail.dendaDetail.masa_tenggang">
              Terlambat {{ detail.dendaDetail.hari_terlambat }} hari, masih dalam masa tenggang
              {{ detail.dendaDetail.masa_tenggang }} hari — belum ada denda.
            </template>
            <template v-else>
              Terlambat <strong>{{ detail.dendaDetail.hari_terlambat }} hari</strong>,
              lewat tenggang {{ detail.dendaDetail.masa_tenggang }} hari, jadi
              <strong>{{ detail.dendaDetail.hari_denda }} hari</strong> kena denda.
            </template>
          </p>
          <p class="mungil lemah">
            Denda {{ rupiah(detail.dendaDetail.denda) }} dari maksimum
            {{ rupiah(detail.dendaDetail.plafon) }}.
            <template v-if="detail.dendaDetail.sisa_hari_ke_plafon > 0">
              Mencapai plafon dalam {{ detail.dendaDetail.sisa_hari_ke_plafon }} hari lagi.
            </template>
            <template v-else>Plafon sudah tercapai.</template>
          </p>
          <p class="mungil lemah">
            Dihitung pada {{ tanggal(detail.dendaDetail.tanggal_acuan) }}.
          </p>
        </template>
      </div>

      <!-- Pembayaran (T5). Diletakkan sebelum snapshot karena inilah aksi yang
           paling sering dipakai di panel ini. -->
      <div class="blok-bayar">
        <!-- Hasil pencatatan: yang penting bukan "berhasil", melainkan status
             berubah dari apa ke apa. -->
        <p v-if="bayarHasil" class="pesan-sukses mungil">
          Pembayaran {{ rupiah(bayarHasil.pembayaran.jumlah) }} tercatat.
          Status berubah dari <strong>{{ labelStatus(bayarHasil.status_sebelum) }}</strong>
          menjadi <strong>{{ labelStatus(bayarHasil.status_sesudah) }}</strong>.
          <template v-if="bayarHasil.sisa > 0">Sisa {{ rupiah(bayarHasil.sisa) }}.</template>
          <template v-else>Tagihan lunas.</template>
        </p>

        <!-- Pesan keadaan hanya muncul bila tidak ada hasil yang baru dicatat.
             Kalau keduanya tampil, "sudah lunas" muncul dua kali berturut-turut
             dan pemilik membaca kalimat yang sama dua kali. -->
        <p v-if="!bayarHasil && detail.status === 'lunas'" class="pesan-netral mungil">
          Tagihan ini sudah lunas. Tidak ada sisa yang bisa dibayar.
        </p>
        <p v-else-if="!bayarHasil && detail.status === 'dibatalkan'" class="pesan-netral mungil">
          Tagihan ini dibatalkan. Pembayaran tidak dapat dicatat.
        </p>

        <!-- Tombol tetap tersedia setelah pembayaran tercatat selama masih ada
             sisa. Menyembunyikannya karena "sudah ada hasil" akan memaksa
             pemilik menutup dan membuka ulang panel hanya untuk mencicil lagi. -->
        <div
          v-if="detail.status !== 'lunas' && detail.status !== 'dibatalkan' && !bayarTerbuka"
          class="aksi"
        >
          <button class="halus mungil" @click="bukaBayar">
            Catat pembayaran{{ detail.sisa > 0 ? ' — sisa ' + rupiah(detail.sisa) : '' }}
          </button>
        </div>

        <form v-if="bayarTerbuka" class="bidang form-bayar" @submit.prevent="catatPembayaran">
          <div class="baris-bidang">
            <div>
              <label for="bayar-jumlah">Jumlah dibayar</label>
              <input
                id="bayar-jumlah"
                v-model="bayarForm.jumlah"
                type="number"
                min="1"
                step="1"
                required
                :aria-invalid="Boolean(bayarBidang.jumlah)"
              />
              <p v-if="bayarBidang.jumlah" class="galat-bidang">{{ bayarBidang.jumlah }}</p>
              <p v-else class="bantuan">Rupiah bulat. Kelebihan bayar ditolak, tidak jadi saldo.</p>
            </div>
            <div>
              <label for="bayar-tanggal">Tanggal pembayaran</label>
              <input
                id="bayar-tanggal"
                v-model="bayarForm.tanggal_pembayaran"
                type="date"
                required
                :aria-invalid="Boolean(bayarBidang.tanggal_pembayaran)"
              />
              <p v-if="bayarBidang.tanggal_pembayaran" class="galat-bidang">
                {{ bayarBidang.tanggal_pembayaran }}
              </p>
              <p v-else class="bantuan">Tidak boleh melewati hari ini ({{ tanggal(tanggalUntukFormulir()) }}).</p>
            </div>
          </div>

          <div>
            <label for="bayar-catatan">Catatan <span class="lemah">(opsional)</span></label>
            <input id="bayar-catatan" v-model="bayarForm.catatan" type="text" maxlength="255" />
          </div>

          <p v-if="bayarGalat" class="pesan-galat">{{ bayarGalat }}</p>

          <div class="aksi">
            <button type="submit" :disabled="bayarProses">
              {{ bayarProses ? 'Menyimpan…' : 'Simpan pembayaran' }}
            </button>
            <button type="button" class="halus" @click="bayarTerbuka = false">Batal</button>
          </div>
        </form>

        <!-- Riwayat: uang yang diterima harus bisa diperiksa ulang, bukan hanya
             dijumlahkan menjadi satu angka. -->
        <div v-if="detail.pembayaran?.length" class="riwayat-bayar">
          <p class="mungil lemah">Riwayat pembayaran</p>
          <div class="tabel-bungkus">
            <table class="tabel tabel-riwayat">
              <thead>
                <tr>
                  <th>Tanggal</th>
                  <th class="kanan">Jumlah</th>
                  <th>Catatan</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in detail.pembayaran" :key="p.id">
                  <td>{{ tanggal(p.tanggal_pembayaran) }}</td>
                  <td class="kanan">{{ rupiah(p.jumlah) }}</td>
                  <td class="mungil lemah">{{ p.catatan || '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <details class="snapshot">
        <summary class="mungil lemah">Tarif yang dibekukan saat terbit</summary>
        <pre class="mungil">{{ JSON.stringify(detail.tarif_terpakai, null, 2) }}</pre>
      </details>
    </section>

    <section class="kartu">
      <div class="tabel-bungkus">
        <table class="tabel">
          <thead>
            <tr>
              <th>Periode</th>
              <th>Penghuni</th>
              <th>Kamar</th>
              <th class="kanan">Pokok</th>
              <th class="kanan">Denda</th>
              <th class="kanan">Total</th>
              <th class="kanan">Dibayar</th>
              <th class="kanan">Sisa</th>
              <th>Jatuh tempo</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in daftar" :key="t.id">
              <td><strong>{{ tanggal(t.periode) }}</strong></td>
              <td>{{ t.kontrak?.penghuni?.nama || '—' }}</td>
              <td>{{ t.kontrak?.kamar?.nomor || '—' }}</td>
              <td class="kanan">{{ rupiah(t.pokok) }}</td>
              <td class="kanan" :class="{ 'sel-denda': t.denda > 0 }">
                {{ t.denda > 0 ? rupiah(t.denda) : '—' }}
              </td>
              <td class="kanan"><strong>{{ rupiah(t.total) }}</strong></td>
              <td class="kanan lemah">
                {{ t.dibayar > 0 ? rupiah(t.dibayar) : '—' }}
              </td>
              <td class="kanan" :class="{ 'sel-sisa': t.sisa > 0 && t.status !== 'dibatalkan' }">
                {{ rupiah(t.sisa) }}
              </td>
              <td>{{ tanggal(t.jatuh_tempo) }}</td>
              <td>
                <span class="lencana-status" :class="`status-${t.status}`">{{ labelStatus(t.status) }}</span>
              </td>
              <td class="kanan">
                <button class="halus mungil" @click="bukaDetail(t)">Rincian</button>
              </td>
            </tr>
            <tr v-if="!memuat && daftar.length === 0">
              <td colspan="11" class="sel-kosong">
                Belum ada tagihan. Terbitkan tagihan pertama lewat tombol di atas.
              </td>
            </tr>
            <tr v-if="memuat">
              <td colspan="9" class="sel-kosong">Memuat…</td>
            </tr>
            <tr v-if="memuatDetail">
              <td colspan="9" class="sel-kosong">Memuat rincian…</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.halaman {
  display: grid;
  gap: 16px;
  max-width: 1080px;
}

.kepala {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 2px;
}

.kepala p {
  margin: 3px 0 0;
}

/* Dua tombol di kepala halaman. Dikelompokkan supaya tetap rapat di layar
   sempit, bukan tersebar oleh justify-content milik .kepala. */
.aksi-kepala {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
}

.panel-massal {
  border-color: #cfd9f5;
  background: #fbfcfe;
}

.hasil-massal {
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px solid var(--garis);
  display: grid;
  gap: 8px;
}

.hasil-massal p {
  margin: 0;
}

.rincian-massal summary {
  cursor: pointer;
}

.rincian-massal .tabel-bungkus {
  margin-top: 10px;
}

.panel {
  padding: 20px 22px;
}

.panel h2 {
  margin-bottom: 14px;
}

.panel-kepala {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 14px;
}

.panel-kepala h2 {
  margin-bottom: 3px;
}

.panel-kepala p {
  margin: 0;
}

.aksi {
  display: flex;
  gap: 9px;
}

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

/* Denda bukan angka biasa — ia satu-satunya nilai yang bertambah sendiri, jadi
   diberi warna peringatan supaya tidak terlewat saat memindai daftar. */
.sel-denda {
  color: #8a5a00;
  font-weight: 600;
}

/* Sisa yang masih harus masuk. Ditebalkan karena inilah angka yang dicari
   pemilik saat membuka halaman ini; pokok dan total hanya konteks. */
.sel-sisa {
  font-weight: 600;
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
}
</style>
