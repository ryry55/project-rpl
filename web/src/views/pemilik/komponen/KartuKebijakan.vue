<script setup>
/**
 * Satu dimensi kebijakan: nilai yang berlaku sekarang, dan formulir untuk
 * menetapkan nilai baru.
 *
 * Dipisah dari view karena keempat jenis kebijakan punya bentuk yang sama
 * persis — label, nilai berlaku, menu pilihan, tanggal berlaku, tombol simpan —
 * dan hanya berbeda pada medan angka yang menyertainya. Menulisnya empat kali
 * di view berarti empat tempat yang harus diubah setiap kali daftar jenis
 * bertambah.
 *
 * **Medan angka tidak ditentukan di sini.** Komponen ini menerima `kolom` dari
 * server: peta `nilai -> {wajib, terlarang}` yang berasal dari
 * `PilihanKebijakan::kolomAngka`, sumber yang sama yang dipakai validasi HTTP
 * dan CHECK constraint. Menyalin matriks itu ke JavaScript berarti tiga salinan
 * harus setuju soal bentuk denda; yang paling sering tertinggal adalah salinan
 * yang paling jarang dibaca, dan gejalanya adalah formulir yang mengirim kolom
 * yang ditolak server.
 *
 * Komponen ini **tidak** memanggil API sendiri: ia mengirim nilai yang dipilih
 * lewat `simpan`, dan induk yang menyimpan. Alasannya, setelah menyimpan induk
 * harus memuat ulang seluruh layar (nilai berlaku, daftar kurang, riwayat) —
 * kalau komponen menyimpan sendiri, ia hanya tahu sebagian, dan dua bagian
 * layar bisa menampilkan keadaan yang berbeda.
 */
import { computed, ref, watch } from 'vue'
import { tanggal as formatTanggal, tanggalBerlakuBulanIni } from '../../../format.js'
import PanelKartu from '../../../komponen/ui/PanelKartu.vue'
import MedanForm from '../../../komponen/ui/MedanForm.vue'
import PesanBaris from '../../../komponen/ui/PesanBaris.vue'

const props = defineProps({
  /** Kode tipe, mis. `satuan_sewa`. */
  tipe: { type: String, required: true },
  /** Label yang dibaca pemilik, dari server. */
  label: { type: String, required: true },
  /** Baris yang berlaku sekarang, atau null bila belum ditetapkan. */
  berlaku: { type: Object, default: null },
  /** Peta nilai => label untuk tipe ini, dari server. */
  pilihan: { type: Object, default: () => ({}) },
  /** Peta nilai => {wajib, terlarang} untuk tipe ini, dari server. */
  kolom: { type: Object, default: () => ({}) },
  /** Apakah tipe ini termasuk yang wajib diisi sebelum menagih. */
  wajib: { type: Boolean, default: true },
  /** Sedang menyimpan; menonaktifkan tombol. */
  menyimpan: { type: Boolean, default: false },
  /**
   * Apakah pengguna boleh menetapkan nilai baru.
   *
   * Pemilik selalu boleh; pengelola hanya bila punya `ubah_kebijakan`. Formulir
   * disembunyikan, bukan dinonaktifkan: tombol abu-abu yang tidak bisa ditekan
   * tetap terlihat seperti sesuatu yang rusak. Yang ditampilkan hanyalah nilai
   * yang berlaku sekarang, dan itu memang berguna dibaca siapa pun.
   */
  bolehUbah: { type: Boolean, default: true },
  /**
   * Apakah tombol ini MENETAPKAN atau hanya MENGUSULKAN (T26).
   *
   * Kalimat tombolnya berbeda karena akibatnya berbeda, dan tombol yang
   * berbunyi sama untuk dua akibat berbeda adalah tombol yang menipu. Pengelola
   * yang menekan "Tetapkan" lalu melihat nilainya tidak berubah akan mengira
   * aplikasinya rusak; pengelola yang menekan "Ajukan perubahan" sudah tahu apa
   * yang akan terjadi.
   */
  menetapkan: { type: Boolean, default: true },
  /**
   * Tipe ini hanya berlaku untuk seluruh kos (pola, tanggal terbit).
   *
   * Ditampilkan sebagai keterangan karena pemilih "berlaku untuk kamar" di
   * atas halaman tetap terlihat, sementara pilihan kamar tidak dipakai tipe
   * ini — dan tanpa keterangan, baris riwayat yang muncul sebagai "Semua
   * kamar" terbaca seperti pilihan yang diabaikan.
   */
  seluruhKos: { type: Boolean, default: false },
  /**
   * Tipe ini nilainya teks bebas (nama kos), bukan pilihan dari menu.
   *
   * Dikirim server lewat `meta.tipe_teks`, bukan disimpulkan dari `pilihan`
   * yang kosong: tipe yang menunya belum termuat juga punya `pilihan` kosong,
   * dan menampilkan medan teks untuk tipe itu akan mengirim nilai yang ditolak
   * server.
   */
  teks: { type: Boolean, default: false },
  /** Batas panjang medan teks, dari server. */
  maksTeks: { type: Number, default: 60 },
})

const emit = defineEmits(['simpan'])

/**
 * Keterangan tiap kolom angka. Teksnya di sini, bukan dari server, karena ini
 * murni bantuan mengisi — berbeda dengan *wajib/tidaknya* sebuah kolom, yang
 * menentukan sah tidaknya data dan karena itu datang dari server.
 */
const KETERANGAN_KOLOM = {
  persen: {
    label: 'Tarif denda (% per hari/bulan)',
    bantuan: 'Persentase dari pokok tagihan, dihitung per hari atau per bulan sesuai pilihan di atas.',
    contoh: '1',
    jenis: 'desimal',
  },
  plafon_persen: {
    label: 'Plafon denda (%)',
    bantuan: 'Batas atas denda relatif terhadap pokok. Denda berhenti bertambah setelah batas ini tercapai.',
    contoh: '30',
    jenis: 'desimal',
  },
  masa_tenggang: {
    label: 'Masa tenggang (hari)',
    bantuan: 'Hari setelah jatuh tempo sebelum denda mulai berjalan. Isi 0 bila denda mulai sejak hari pertama.',
    contoh: '7',
    jenis: 'bulat',
  },
  nominal: {
    label: 'Nominal (Rp)',
    bantuan: 'Angka rupiah bulat, tanpa titik atau koma.',
    contoh: '50000',
    jenis: 'bulat',
  },
  tanggal: {
    label: 'Tanggal jatuh tempo',
    bantuan: 'Tanggal 29—31 dijepit ke akhir bulan yang lebih pendek.',
    contoh: '10',
    jenis: 'bulat',
  },
}

const nilai = ref('')
const angka = ref({})
// Awal bulan berjalan, bukan hari ini: kebijakan diselesaikan terhadap awal
// periode, jadi tanggal hari ini tidak berlaku untuk periode bulan ini.
const berlakuDari = ref(tanggalBerlakuBulanIni())
const galat = ref('')

// Daftar pilihan datang dari server, jadi nilainya bisa berubah setelah muat
// ulang (mis. saat LANGKAH 3 menambah jenis denda). Menyelaraskan `nilai`
// dengan pilihan pertama yang tersedia mencegah formulir terkirim dengan nilai
// kosong hanya karena komponen sempat dirender sebelum datanya tiba.
watch(
  () => props.pilihan,
  (daftar) => {
    if (!nilai.value && Object.keys(daftar || {}).length > 0) {
      nilai.value = Object.keys(daftar)[0]
    }
  },
  { immediate: true, deep: true },
)

/**
 * Kolom yang wajib untuk pilihan yang sedang aktif.
 *
 * Server yang menentukan; komponen hanya membaca. Kalau tidak ada entri untuk
 * pilihan ini, daftarnya kosong dan formulir tidak menampilkan medan angka
 * apa pun — bentuk yang benar untuk `bulanan`, `tanpa_denda`, `satu_bulan`,
 * dan sejenisnya.
 */
const kolomWajib = computed(() => props.kolom?.[nilai.value]?.wajib || [])

/**
 * Aturan kolom `tanggal` untuk pilihan yang sedang aktif, dari server.
 *
 * Batasnya berbeda: jatuh tempo 1–31, tanggal terbit 1–28. Menyalin angka itu
 * ke sini berarti dua aturan yang bisa berbeda, dan yang tertinggal biasanya
 * yang di JavaScript.
 */
const kolomAktif = computed(() => props.kolom?.[nilai.value] || {})
const maksTanggal = computed(() => kolomAktif.value.maks_tanggal || 31)

/** Satu pilihan tidak perlu dropdown: tidak ada yang bisa dipilih. */
const banyakPilihan = computed(() => Object.keys(props.pilihan || {}).length > 1)

const medanAngka = computed(() =>
  kolomWajib.value.map((kunci) => {
    const dasar = KETERANGAN_KOLOM[kunci]

    // Label dan bantuan kolom tanggal bergantung pada tipe (jatuh tempo vs
    // tanggal terbit), jadi datang dari server bersama batasnya.
    const dariServer = kunci === 'tanggal'
      ? {
          label: kolomAktif.value.label_tanggal || dasar.label,
          bantuan: kolomAktif.value.bantuan_tanggal || dasar.bantuan,
        }
      : {}

    return {
      kunci,
      // `tanggal` sudah punya id mapan sejak T16 dan dipakai skrip uji, jadi
      // id-nya dipertahankan apa adanya; kolom angka baru memakai awalan
      // `kebijakan-` supaya tidak bertabrakan dengan id lain di halaman.
      id: kunci === 'tanggal' ? 'tanggal-kebijakan' : `kebijakan-${kunci}`,
      ...dasar,
      ...dariServer,
    }
  }),
)

// Saat pilihan berganti, angka yang tidak lagi dipakai dibuang. Tanpa ini,
// pemilik yang mencoba "persen per hari" lalu beralih ke "tanpa denda" akan
// mengirim tarif persen yang masih tersisa di state, dan server menolaknya
// dengan pesan yang membingungkan karena medan itu sudah tidak tampak di layar.
watch(nilai, () => {
  const dipakai = new Set(kolomWajib.value)
  for (const kunci of Object.keys(angka.value)) {
    if (!dipakai.has(kunci)) {
      delete angka.value[kunci]
    }
  }
})

const labelBerlaku = computed(() => {
  if (!props.berlaku) {
    return props.wajib ? 'Belum ditetapkan — tagihan belum bisa diterbitkan' : 'Belum ditetapkan'
  }
  // Ringkasan disusun server: kalimatnya bergantung pada pasangan tipe+nilai,
  // dan server yang paling tahu bentuknya.
  return props.berlaku.ringkasan || props.berlaku.label_nilai
})

function kirim() {
  galat.value = ''

  const isi = {
    tipe: props.tipe,
    nilai: props.teks ? String(nilai.value ?? '').trim() : nilai.value,
    berlaku_dari: berlakuDari.value,
  }

  // Tipe teks bebas tidak punya pilihan yang bisa kosong: nama kos yang kosong
  // akan tersimpan sebagai baris tanpa arti, dan layar akan menampilkan
  // "Belum ditetapkan" padahal barisnya ada.
  if (props.teks && isi.nilai === '') {
    galat.value = `${props.label} wajib diisi.`
    return
  }

  for (const kunci of kolomWajib.value) {
    const teks = String(angka.value[kunci] ?? '').trim()
    const keterangan = KETERANGAN_KOLOM[kunci]

    if (teks === '') {
      galat.value = `${keterangan.label} wajib diisi.`
      return
    }

    // Divalidasi di sini juga, bukan hanya di server: pengguna yang mengetik
    // "0" atau mengosongkan medan tidak perlu menunggu perjalanan jaringan
    // untuk tahu apa yang salah.
    const n = Number(teks)
    if (!Number.isFinite(n)) {
      galat.value = `${keterangan.label} harus berupa angka.`
      return
    }

    if (keterangan.jenis === 'bulat' && !Number.isInteger(n)) {
      galat.value = `${keterangan.label} harus bilangan bulat.`
      return
    }

    if (n < 0) {
      galat.value = `${keterangan.label} tidak boleh negatif.`
      return
    }

    isi[kunci] = n
  }

  // Batas atas tanggal datang dari server: 31 untuk jatuh tempo, 28 untuk
  // tanggal terbit. Kolom lain punya batasnya sendiri yang ditegakkan server
  // (persen <= 100, dst.), dan memeriksanya dua kali di sini akan menjadi dua
  // aturan yang bisa berbeda.
  if (kolomWajib.value.includes('tanggal') && (isi.tanggal < 1 || isi.tanggal > maksTanggal.value)) {
    galat.value = `${kolomAktif.value.label_tanggal || 'Tanggal'} harus berupa angka 1 sampai ${maksTanggal.value}.`
    return
  }

  emit('simpan', isi)
}
</script>

<template>
  <PanelKartu :judul="label" :kelas="wajib ? 'kebijakan-kartu kebijakan-wajib' : 'kebijakan-kartu'">
    <template #keterangan>
      <p class="lemah mungil">
        Berlaku sekarang:
        <strong v-if="berlaku" class="kebijakan-nilai">{{ labelBerlaku }}</strong>
        <span v-else class="kebijakan-belum">{{ labelBerlaku }}</span>
        <span v-if="berlaku"> sejak {{ formatTanggal(berlaku.berlaku_dari) }}</span>
        <span v-if="seluruhKos" class="lemah mungil"> · berlaku untuk seluruh kos</span>
      </p>
    </template>

    <form v-if="bolehUbah" class="bidang" @submit.prevent="kirim">
      <div class="baris-bidang">
        <MedanForm v-if="teks" id="nilai-kebijakan" :label="label" :bantuan="`Maksimal ${maksTeks} karakter. Nama ini dipakai di layar masuk, bilah, email, dan kuitansi.`">
          <input
            id="nilai-kebijakan"
            v-model="nilai"
            type="text"
            :maxlength="maksTeks"
            autocomplete="off"
            :placeholder="berlaku?.label_nilai || 'Nama kos Anda'"
          />
        </MedanForm>

        <MedanForm v-else-if="banyakPilihan" id="nilai-kebijakan" label="Pilihan">
          <select id="nilai-kebijakan" v-model="nilai">
            <option v-for="(teks, kode) in pilihan" :key="kode" :value="kode">
              {{ teks }}
            </option>
          </select>
        </MedanForm>

        <MedanForm
          v-for="medan in medanAngka"
          :id="medan.id"
          :key="medan.kunci"
          :label="medan.label"
          :bantuan="medan.bantuan"
        >
          <input
            :id="medan.id"
            v-model="angka[medan.kunci]"
            inputmode="numeric"
            :placeholder="medan.contoh"
            autocomplete="off"
          />
        </MedanForm>

        <MedanForm
          id="berlaku-kebijakan"
          label="Berlaku dari"
          bantuan="Kebijakan dipakai untuk periode yang dimulai pada atau sesudah tanggal ini. Baris lama tetap tersimpan; ubah dengan menambah baris baru."
        >
          <input id="berlaku-kebijakan" v-model="berlakuDari" type="date" />
        </MedanForm>
      </div>

      <PesanBaris v-if="galat" jenis="galat">{{ galat }}</PesanBaris>

      <div class="aksi">
        <button type="submit" :disabled="menyimpan">
          {{ menyimpan ? 'Menyimpan.' : (menetapkan ? 'Tetapkan' : 'Ajukan perubahan') }}
        </button>
      </div>
    </form>
  </PanelKartu>
</template>

<style scoped>
/* Kartu yang wajib diisi diberi garis kiri tegas: pemilik harus bisa melihat
   mana yang menghalangi penagihan tanpa membaca ulang keterangannya. */
.kebijakan-kartu {
  border-left: 3px solid var(--garis);
}

.kebijakan-wajib {
  border-left-color: var(--bahaya);
}

.kebijakan-belum {
  color: var(--bahaya);
  font-weight: 600;
}

.kebijakan-nilai {
  font-weight: 600;
}
</style>
