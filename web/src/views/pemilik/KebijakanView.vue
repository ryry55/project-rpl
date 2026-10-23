<script setup>
/**
 * Halaman Kebijakan — pemilik menyatakan cara kos ini menagih.
 *
 * Ini halaman yang paling langsung menjawab tesis proyek: tiap kos berbeda, dan
 * perbedaannya tidak boleh jadi kode. Sebelum T16, satuan sewa selalu bulanan
 * dan jatuh tempo selalu mengikuti tanggal masuk, karena itulah yang tertulis
 * di Kalkulator. Sekarang keduanya data.
 *
 * Tiga bagian, berurutan sesuai yang dibutuhkan pemilik:
 *
 *   1. Peringatan bila belum lengkap — dengan akibatnya yang konkret ("tagihan
 *      tidak bisa diterbitkan"), bukan sekadar "belum diisi".
 *   2. Kartu per jenis, untuk menetapkan nilai baru.
 *   3. Riwayat, supaya perubahan bisa dipertanggungjawabkan dan tagihan lama
 *      bisa dijelaskan.
 */
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { pesanGalat, galatPerBidang, tanggal as formatTanggal } from '../../format.js'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PanelKartu from '../../komponen/ui/PanelKartu.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'
import BarisKosong from '../../komponen/ui/BarisKosong.vue'
import KartuKebijakan from './komponen/KartuKebijakan.vue'

const daftar = ref([])
const meta = ref(null)
const memuat = ref(true)
const galatMuat = ref('')

const menyimpanTipe = ref('')
const galatSimpan = ref('')
const galatBidang = ref({})
const sukses = ref('')

const kamar = ref([])
const kamarTerpilih = ref('')

const belumLengkap = computed(() => meta.value && !meta.value.lengkap)

const tipeTersedia = computed(() => meta.value?.tipe || [])
const pilihan = computed(() => meta.value?.pilihan || {})
// Matriks kolom angka per tipe, dari server. Kartu memakainya untuk tahu medan
// mana yang wajib muncul; menyalinnya di Vue akan menjadi salinan ketiga dari
// aturan yang sama dengan validasi dan CHECK constraint.
const kolom = computed(() => meta.value?.kolom || {})
const labelTipe = computed(() => meta.value?.label_tipe || {})
const kurang = computed(() => meta.value?.kurang || [])

/**
 * Apakah akun ini MENETAPKAN atau hanya MENGUSULKAN.
 *
 * Dibaca dari server, bukan disimpulkan dari peran di peramban. Server sudah
 * tahu jawabannya (`boleh_menetapkan`), dan menyimpulkannya lagi di sini berarti
 * dua tempat yang harus setuju soal siapa yang boleh menetapkan angka uang.
 */
const bolehMenetapkan = computed(() => meta.value?.boleh_menetapkan !== false)

/** Baris yang berlaku untuk kamar yang sedang dipilih (atau default kos). */
function berlakuUntuk(tipe) {
  return meta.value?.berlaku?.[tipe] || null
}

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const kueri = kamarTerpilih.value ? `?kamar_id=${kamarTerpilih.value}` : ''
    const [hasil, hasilKamar] = await Promise.all([
      sesi.panggil(`/kebijakan${kueri}`),
      // Daftar kamar hanya untuk pemilih "berlaku untuk kamar mana". Kegagalan
      // memuatnya tidak boleh menggagalkan seluruh layar: kebijakan default
      // tetap bisa ditetapkan tanpa daftar kamar.
      sesi.panggil('/kamar').catch(() => ({ data: [] })),
    ])
    daftar.value = hasil.data
    meta.value = hasil.meta
    kamar.value = hasilKamar.data
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

/**
 * Menyimpan kebijakan baru.
 *
 * Sejak T26 jawabannya punya dua bentuk. Pemilik menetapkan (201); pengelola
 * mengusulkan (202) dan kebijakan **belum** berubah. Kalau keduanya diperlakukan
 * sama, pengelola akan membaca "Kebijakan tersimpan" padahal tidak ada yang
 * berubah, lalu menunggu tagihan yang tidak pernah memakai nilainya.
 */
async function simpan(isi) {
  menyimpanTipe.value = isi.tipe
  galatSimpan.value = ''
  galatBidang.value = {}
  sukses.value = ''

  try {
    const hasil = await sesi.panggil('/kebijakan', {
      method: 'POST',
      body: JSON.stringify({
        ...isi,
        kamar_id: kamarTerpilih.value ? Number(kamarTerpilih.value) : null,
      }),
    })

    if (hasil?.meta?.diajukan) {
      // Riwayat tidak dimuat ulang pada jalur ini: isinya memang tidak berubah,
      // dan memuat ulang hanya menambah satu permintaan untuk jawaban yang sama.
      sukses.value =
        hasil.meta.pesan ||
        'Usulan terkirim. Kebijakan belum berubah sampai pemilik menyetujuinya.'
      return
    }

    await muat()

    // Tersimpan belum tentu berlaku. Kebijakan diselesaikan terhadap awal
    // periode, jadi baris bertanggal setelah awal bulan ini baru terpakai bulan
    // depan. Menulis "Kebijakan tersimpan" saja membuat gerbang yang tetap
    // menutup terbaca seperti kerusakan, padahal tanggalnya yang terlalu maju.
    if (kurang.value.includes(isi.tipe)) {
      sukses.value = `Tersimpan, tetapi belum berlaku untuk periode bulan ini karena tanggal berlakunya ${formatTanggal(isi.berlaku_dari)}. Pakai tanggal awal bulan agar langsung dipakai.`
    } else {
      sukses.value = 'Kebijakan tersimpan. Tagihan periode berikutnya memakai nilai ini.'
    }
  } catch (e) {
    galatSimpan.value = pesanGalat(e)
    galatBidang.value = galatPerBidang(e)
  } finally {
    menyimpanTipe.value = ''
  }
}

function gantiKamar() {
  sukses.value = ''
  muat()
}

onMounted(muat)
</script>

<template>
  <div class="halaman">
    <KepalaHalaman judul="Kebijakan kos">
      <template #keterangan>
        Cara kos ini menagih: satuan sewa dan jatuh tempo. Wajib ditetapkan sebelum tagihan bisa terbit.
      </template>
    </KepalaHalaman>

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>

    <!--
      Pengelola tanpa wewenang `ubah_kebijakan` tetap boleh membuka halaman ini
      untuk MEMBACA cara kos ini menagih, tetapi formulirnya tidak ditampilkan:
      server akan menolaknya 403, dan formulir yang pasti ditolak membuat orang
      mengira aplikasinya rusak. Halaman ini juga disembunyikan dari menu, tetapi
      alamatnya bisa dibuka langsung - jadi keadaan ini tetap harus ditangani.
    -->
    <PesanBaris v-if="!sesi.boleh('ubah_kebijakan')" jenis="netral">
      Akun Anda hanya dapat membaca kebijakan kos. Mengubahnya adalah wewenang pemilik.
    </PesanBaris>

    <!-- Pengelola yang PUNYA wewenang tetap tidak menetapkan. Sejak T26 ia
         mengusulkan, dan pemilik yang menyetujui. Dikatakan di sini supaya
         kalimat tombol "Ajukan perubahan" tidak mengejutkan. -->
    <PesanBaris v-else-if="!bolehMenetapkan" jenis="netral">
      Perubahan yang Anda ajukan akan masuk ke antrean persetujuan pemilik. Nilai
      baru berlaku setelah disetujui, bukan setelah tombol ini ditekan.
    </PesanBaris>

    <section v-if="belumLengkap" class="kartu panel gerbang-kebijakan">
      <div class="gerbang-isi">
        <div>
          <h2>Kebijakan belum lengkap</h2>
          <p class="bantuan">
            Tagihan tidak bisa diterbitkan sebelum
            <strong>{{ kurang.map((t) => labelTipe[t] || t).join(' dan ') }}</strong>
            ditetapkan. Sistem tidak menebak kebijakan kos — kos yang berbeda
            menagih dengan cara yang berbeda.
          </p>
        </div>
      </div>
    </section>

    <section class="kartu panel pemilih-kamar">
      <div class="pemilih-baris">
        <div>
          <h2 class="mungil">Berlaku untuk</h2>
          <p class="bantuan">
            Tanpa pilihan kamar, nilai di bawah menjadi default seluruh kos.
            Memilih kamar membuat nilai yang ditetapkan hanya berlaku untuk kamar itu.
          </p>
        </div>
        <select v-model="kamarTerpilih" aria-label="Berlaku untuk kamar" @change="gantiKamar">
          <option value="">Semua kamar (default kos)</option>
          <option v-for="k in kamar" :key="k.id" :value="k.id">Kamar {{ k.nomor }}</option>
        </select>
      </div>
    </section>

    <PesanBaris v-if="sukses" jenis="sukses">{{ sukses }}</PesanBaris>
    <PesanBaris v-if="galatSimpan" jenis="galat">{{ galatSimpan }}</PesanBaris>

    <p v-if="memuat" class="lemah mungil">Memuat kebijakan…</p>

    <template v-else>
      <KartuKebijakan
        v-for="tipe in tipeTersedia"
        :key="tipe"
        :tipe="tipe"
        :label="labelTipe[tipe] || tipe"
        :berlaku="berlakuUntuk(tipe)"
        :pilihan="pilihan[tipe] || {}"
        :kolom="kolom[tipe] || {}"
        :wajib="kurang.includes(tipe)"
        :menyimpan="menyimpanTipe === tipe"
        :boleh-ubah="sesi.boleh('ubah_kebijakan')"
        :menetapkan="bolehMenetapkan"
        @simpan="simpan"
      />

      <p v-if="galatBidang.berlaku_dari" class="pesan-galat mungil">
        {{ galatBidang.berlaku_dari }}
      </p>

      <PanelKartu judul="Riwayat kebijakan">
        <template #keterangan>
          <p class="lemah mungil">
            Baris lama tidak pernah diubah. Menetapkan nilai baru menambah baris,
            dan tagihan yang sudah terbit tetap memakai nilai saat ia terbit.
          </p>
        </template>

        <div class="tabel-bungkus">
          <table class="tabel" v-kartu-tabel>
            <thead>
              <tr>
                <th>Berlaku dari</th>
                <th>Jenis</th>
                <th>Nilai</th>
                <th>Untuk</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="k in daftar" :key="k.id">
                <td>{{ formatTanggal(k.berlaku_dari) }}</td>
                <td>{{ k.label_tipe }}</td>
                <td>
                  {{ k.label_nilai }}
                  <span v-if="k.tanggal" class="lemah mungil">tanggal {{ k.tanggal }}</span>
                </td>
                <td>
                  <span v-if="k.kamar_id === null" class="lemah mungil">Semua kamar</span>
                  <span v-else>Kamar {{ kamar.find((x) => x.id === k.kamar_id)?.nomor || k.kamar_id }}</span>
                </td>
              </tr>
              <BarisKosong v-if="daftar.length === 0" :kolom="4">
                Belum ada kebijakan. Tetapkan satuan sewa dan jatuh tempo di atas.
              </BarisKosong>
            </tbody>
          </table>
        </div>
      </PanelKartu>
    </template>
  </div>
</template>

<style scoped>
/* Gerbang diberi latar peringatan, bukan galat: ini bukan kesalahan sistem,
   ini langkah yang memang belum dikerjakan. Warna merah penuh akan membuat
   pemilik mengira ada yang rusak. */
.gerbang-kebijakan {
  border-left: 3px solid var(--bahaya);
  background: var(--bahaya-lemah);
}

.gerbang-kebijakan h2 {
  color: var(--bahaya);
}

.pemilih-baris {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}

.pemilih-baris h2 {
  margin: 0 0 3px;
}

.pemilih-baris select {
  width: auto;
  min-width: 220px;
}

.gerbang-isi {
  display: flex;
  gap: 16px;
  align-items: flex-start;
}
</style>
