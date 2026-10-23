<script setup>
/**
 * Satu dimensi kebijakan: nilai yang berlaku sekarang, dan formulir untuk
 * menetapkan nilai baru.
 *
 * Dipisah dari view karena dua jenis kebijakan punya bentuk yang sama persis —
 * label, nilai berlaku, menu pilihan, tanggal berlaku, tombol simpan — dan
 * hanya berbeda pada ada/tidaknya medan tambahan (`tanggal` untuk "tanggal
 * tetap"). Menulisnya dua kali di view berarti dua tempat yang harus diubah
 * setiap kali daftar jenis bertambah di LANGKAH 3.
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
  /** Apakah tipe ini termasuk yang wajib diisi sebelum menagih. */
  wajib: { type: Boolean, default: true },
  /** Sedang menyimpan; menonaktifkan tombol. */
  menyimpan: { type: Boolean, default: false },
})

const emit = defineEmits(['simpan'])

const nilai = ref('')
const tanggalTetap = ref('')
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

/** Medan `tanggal` hanya muncul untuk jatuh tempo "tanggal tetap". */
const butuhTanggal = computed(() => props.tipe === 'jatuh_tempo' && nilai.value === 'tanggal_tetap')

const labelBerlaku = computed(() => {
  if (!props.berlaku) {
    return props.wajib ? 'Belum ditetapkan — tagihan belum bisa diterbitkan' : 'Belum ditetapkan'
  }
  return props.berlaku.label_nilai
})

function kirim() {
  galat.value = ''

  const isi = {
    tipe: props.tipe,
    nilai: nilai.value,
    berlaku_dari: berlakuDari.value,
  }

  if (butuhTanggal.value) {
    const angka = Number(tanggalTetap.value)
    // Divalidasi di sini juga, bukan hanya di server: pengguna yang mengetik
    // "0" atau mengosongkan medan tidak perlu menunggu perjalanan jaringan
    // untuk tahu apa yang salah.
    if (!Number.isInteger(angka) || angka < 1 || angka > 31) {
      galat.value = 'Tanggal jatuh tempo harus berupa angka 1 sampai 31.'
      return
    }
    isi.tanggal = angka
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
      </p>
    </template>

    <form class="bidang" @submit.prevent="kirim">
      <div class="baris-bidang">
        <MedanForm id="nilai-kebijakan" label="Pilihan">
          <select id="nilai-kebijakan" v-model="nilai">
            <option v-for="(teks, kode) in pilihan" :key="kode" :value="kode">
              {{ teks }}
            </option>
          </select>
        </MedanForm>

        <MedanForm
          v-if="butuhTanggal"
          id="tanggal-kebijakan"
          label="Tanggal jatuh tempo"
          bantuan="Tanggal 29–31 dijepit ke akhir bulan yang lebih pendek."
        >
          <input
            id="tanggal-kebijakan"
            v-model="tanggalTetap"
            inputmode="numeric"
            placeholder="10"
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
          {{ menyimpan ? 'Menyimpan…' : 'Tetapkan' }}
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
