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
import { ref } from 'vue'
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
const bayarForm = ref({ jumlah: '', tanggal_pembayaran: '', catatan: '' })

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

function bukaBayar() {
  bayarForm.value = {
    // Sisa diisikan sebagai nilai awal: itulah jumlah yang paling sering
    // dibayar, dan membiarkannya kosong memaksa pemilik menyalin angka dari
    // tabel di atasnya.
    jumlah: props.detail?.sisa > 0 ? String(props.detail.sisa) : '',
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
    const hasil = await sesi.panggil(`/tagihan/${props.detail.id}/pembayaran`, {
      method: 'POST',
      headers: simulasiTanggal.header(),
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
    emit('berubah')
  } catch (e) {
    bayarGalat.value = pesanGalat(e)
    bayarBidang.value = galatPerBidang(e)
  } finally {
    bayarProses.value = false
  }
}
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
