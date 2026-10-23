<script setup>
/**
 * Akun pengelola dan wewenangnya (T24).
 *
 * Halaman ini hanya bisa dibuka pemilik, dan itu bukan pembatasan tampilan
 * belaka: endpoint `/pengelola` dijaga `peran:pemilik` di server. Alasannya
 * tertulis di PengelolaController - siapa pun yang boleh memberi wewenang
 * pada dasarnya boleh memberi dirinya wewenang apa pun.
 *
 * Dua keputusan tampilan yang disengaja:
 *
 *   1. **Kotak centang, bukan kata sandi.** Wewenang adalah daftar tertutup
 *      yang diperiksa kode, jadi memilih dari daftar lebih jujur daripada
 *      mengetik. Daftarnya datang dari `meta.semua_wewenang`, bukan disimpan
 *      di berkas ini: salinan di peramban adalah salinan yang paling sering
 *      tertinggal saat wewenang baru ditambahkan, dan gejalanya adalah kotak
 *      centang yang tidak berpengaruh apa pun.
 *
 *   2. **Wewenang sensitif dipisah ke kelompoknya sendiri.** Mengubah tarif
 *      bukan keputusan yang sama dengan mengelola kamar. Mengelompokkannya
 *      membuat pemilik berhenti sejenak sebelum mencentangnya, dan kelompok
 *      itulah yang nanti (T26) menuntut persetujuan.
 */
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { pesanGalat, galatPerBidang } from '../../format.js'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'
import BarisKosong from '../../komponen/ui/BarisKosong.vue'
import MedanForm from '../../komponen/ui/MedanForm.vue'

const daftar = ref([])
const memuat = ref(true)
const galatMuat = ref('')

// Daftar wewenang dari server. Dibiarkan kosong sampai muatan pertama selesai;
// formulir tidak bisa dibuka sebelum itu, jadi tidak ada saat di mana layar
// menampilkan daftar kosong yang terlihat seperti "tidak ada wewenang".
const semuaWewenang = ref([])
const labelWewenang = ref({})
const wewenangSensitif = ref([])

const formTerbuka = ref(false)
const menyimpan = ref(false)
const galatForm = ref('')
const galatBidang = ref({})
const sedangDiubah = ref(null)
const form = ref({ nama: '', email: '', wewenang: [] })

/**
 * Hasil pengiriman tautan aktivasi terakhir (T27).
 *
 * Pemilik tidak lagi mengetik kata sandi pengelola - jadi satu-satunya hal
 * yang perlu ia pastikan adalah tautan aktivasi benar-benar sampai. Tanpa
 * baris ini, akun yang emailnya gagal terkirim tampak sama dengan akun yang
 * berhasil, dan pengelola baru tidak akan pernah bisa masuk.
 */
const tautanTerkirim = ref(null)
const kirimUlangId = ref(null)

const konfirmasiCabut = ref(null)
const mencabut = ref(false)
const galatCabut = ref('')

/**
 * Wewenang harian, yaitu yang bukan sensitif.
 *
 * Dipisahkan supaya layar bisa menampilkan dua kelompok tanpa menulis ulang
 * daftar sensitifnya. Server yang menentukan mana yang sensitif
 * (`meta.wewenang_sensitif`); layar tidak menebaknya dari namanya.
 */
const wewenangHarian = computed(() =>
  semuaWewenang.value.filter((w) => !wewenangSensitif.value.includes(w)),
)

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const hasil = await sesi.panggil('/pengelola')
    daftar.value = hasil.data
    semuaWewenang.value = hasil.meta?.semua_wewenang || []
    labelWewenang.value = hasil.meta?.label_wewenang || {}
    wewenangSensitif.value = hasil.meta?.wewenang_sensitif || []
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

function bukaTambah() {
  sedangDiubah.value = null
  form.value = { nama: '', email: '', wewenang: [] }
  galatForm.value = ''
  galatBidang.value = {}
  tautanTerkirim.value = null
  formTerbuka.value = true
}

function bukaUbah(p) {
  sedangDiubah.value = p
  form.value = {
    nama: p.nama,
    email: p.email,
    wewenang: [...(p.wewenang || [])],
  }
  galatForm.value = ''
  galatBidang.value = {}
  formTerbuka.value = true
}

function tutupForm() {
  formTerbuka.value = false
  sedangDiubah.value = null
}

async function simpan() {
  menyimpan.value = true
  galatForm.value = ''
  galatBidang.value = {}
  try {
    // Tidak ada medan kata sandi di sini, dan ketiadaannya disengaja (T27):
    // pemilik yang tahu sandi pengelolanya bisa mengubah tarif atas namanya,
    // dan jejak audit yang tertulis adalah nama orang yang tidak melakukannya.
    // Sandi ditetapkan pemilik akun sendiri lewat tautan aktivasi.
    const isi = {
      nama: form.value.nama.trim(),
      email: form.value.email.trim(),
      wewenang: form.value.wewenang,
    }

    let hasil

    if (sedangDiubah.value) {
      hasil = await sesi.panggil(`/pengelola/${sedangDiubah.value.id}`, {
        method: 'PUT',
        body: JSON.stringify(isi),
      })
    } else {
      hasil = await sesi.panggil('/pengelola', {
        method: 'POST',
        body: JSON.stringify(isi),
      })
    }

    tutupForm()

    // Hanya ditampilkan bila ada yang perlu dikatakan: saat membuat akun baru,
    // atau saat emailnya berubah sehingga aktivasi lama batal.
    if (hasil.meta?.tautan_aktivasi_terkirim !== null) {
      tautanTerkirim.value = {
        email: hasil.meta?.email || isi.email,
        terkirim: hasil.meta?.tautan_aktivasi_terkirim === true,
      }
    }

    await muat()
  } catch (e) {
    galatForm.value = pesanGalat(e)
    galatBidang.value = galatPerBidang(e)
  } finally {
    menyimpan.value = false
  }
}

async function kirimUlang(p) {
  kirimUlangId.value = p.id
  galatMuat.value = ''
  try {
    const hasil = await sesi.panggil(`/pengelola/${p.id}/kirim-ulang-aktivasi`, {
      method: 'POST',
    })
    tautanTerkirim.value = {
      email: hasil.meta?.email || p.email,
      terkirim: hasil.meta?.tautan_aktivasi_terkirim === true,
    }
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    kirimUlangId.value = null
  }
}

function mintaCabut(p) {
  konfirmasiCabut.value = p
  galatCabut.value = ''
}

async function cabut() {
  mencabut.value = true
  galatCabut.value = ''
  try {
    await sesi.panggil(`/pengelola/${konfirmasiCabut.value.id}`, { method: 'DELETE' })
    konfirmasiCabut.value = null
    await muat()
  } catch (e) {
    galatCabut.value = pesanGalat(e)
  } finally {
    mencabut.value = false
  }
}

/** Label yang layak dibaca, dengan nama kode sebagai cadangan. */
function label(w) {
  return labelWewenang.value[w] || w
}

onMounted(muat)
</script>

<template>
  <div class="halaman">
    <KepalaHalaman judul="Pengelola">
      <template #keterangan>
        {{ daftar.length }} akun pengelola. Pengelola mengurus kos ini tanpa menjadi pemilik:
        wewenangnya dibatasi satu per satu.
      </template>
      <template #aksi>
        <button v-if="!formTerbuka" @click="bukaTambah">Tambah pengelola</button>
      </template>
    </KepalaHalaman>

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>

    <PesanBaris
      v-if="tautanTerkirim"
      :jenis="tautanTerkirim.terkirim ? 'sukses' : 'galat'"
      kelas="pesan-tautan"
    >
      <template v-if="tautanTerkirim.terkirim">
        Tautan aktivasi sudah dikirim ke <strong>{{ tautanTerkirim.email }}</strong>.
        Pengelola menetapkan kata sandinya sendiri lewat tautan itu.
      </template>
      <template v-else>
        Akun untuk <strong>{{ tautanTerkirim.email }}</strong> sudah dibuat, tetapi email
        tautan aktivasi gagal dikirim. Periksa alamat emailnya, lalu tekan
        &ldquo;kirim ulang tautan&rdquo; pada baris akunnya.
      </template>
    </PesanBaris>

    <section v-if="formTerbuka" class="kartu panel">
      <h2>{{ sedangDiubah ? 'Ubah pengelola' : 'Tambah pengelola' }}</h2>

      <form class="bidang" @submit.prevent="simpan">
        <div class="baris-bidang">
          <MedanForm id="nama-pengelola" label="Nama" :galat="galatBidang.nama">
            <input
              id="nama-pengelola"
              v-model="form.nama"
              placeholder="Sari"
              :aria-invalid="Boolean(galatBidang.nama)"
              autocomplete="off"
            />
          </MedanForm>

          <MedanForm
            id="email-pengelola"
            label="Email"
            bantuan="Dipakai untuk masuk. Tidak bisa dipakai dua akun."
            :galat="galatBidang.email"
          >
            <input
              id="email-pengelola"
              v-model="form.email"
              type="email"
              placeholder="sari@kos.test"
              :aria-invalid="Boolean(galatBidang.email)"
              autocomplete="off"
            />
          </MedanForm>
        </div>

        <!-- T27: tidak ada medan kata sandi. Pemilik yang tahu sandi pengelola
             bisa mengubah tarif atas namanya, dan jejak audit lalu menulis nama
             orang yang tidak melakukannya. Pengelola menetapkan sandinya
             sendiri lewat tautan yang dikirim ke emailnya. -->
        <PesanBaris jenis="netral" mungil>
          Pengelola menetapkan kata sandinya sendiri lewat tautan aktivasi yang dikirim ke
          emailnya. Anda tidak perlu mengetahui kata sandi itu.
        </PesanBaris>

        <!-- Kelompok 1: pekerjaan harian -->
        <fieldset class="kelompok">
          <legend>Yang boleh dikerjakan</legend>
          <p class="bantuan mungil">
            Pekerjaan harian. Pilih yang benar-benar dibutuhkan; sisanya bisa ditambahkan nanti.
          </p>
          <div class="kisi-wewenang">
            <label v-for="w in wewenangHarian" :key="w" class="pilihan">
              <input v-model="form.wewenang" type="checkbox" :value="w" />
              <span>{{ label(w) }}</span>
            </label>
          </div>
        </fieldset>

        <!-- Kelompok 2: sensitif. Diberi penanda dan penjelasan, bukan
             disembunyikan: pemilik harus bisa memberikannya, tetapi harus
             tahu apa yang ia berikan. -->
        <fieldset class="kelompok kelompok-sensitif">
          <legend>Menyentuh uang</legend>
          <p class="bantuan mungil">
            Wewenang ini mengubah angka yang ditagih ke penghuni. Berikan hanya kepada orang yang
            memang menetapkan tarif dan kebijakan kos.
          </p>
          <div class="kisi-wewenang">
            <label v-for="w in wewenangSensitif" :key="w" class="pilihan">
              <input v-model="form.wewenang" type="checkbox" :value="w" />
              <span>{{ label(w) }}</span>
            </label>
          </div>
        </fieldset>

        <PesanBaris v-if="galatForm" jenis="galat">{{ galatForm }}</PesanBaris>

        <div class="aksi">
          <button type="submit" :disabled="menyimpan">
            {{ menyimpan ? 'Menyimpan...' : 'Simpan pengelola' }}
          </button>
          <button type="button" class="halus" @click="tutupForm">Batal</button>
        </div>
      </form>
    </section>

    <section v-if="konfirmasiCabut" class="kartu panel panel-cabut">
      <h2>Cabut akses &ldquo;{{ konfirmasiCabut.nama }}&rdquo;?</h2>
      <p class="mungil">
        Akunnya <strong>tidak dihapus</strong>, tetapi diturunkan menjadi penghuni dan seluruh
        wewenangnya dilepas. Namanya tetap tercatat di jejak audit, karena riwayat pembayaran yang
        pernah ia catat harus tetap bisa dipertanggungjawabkan.
      </p>
      <p class="mungil">
        Ia juga langsung keluar dari perangkat yang sedang dipakai. Untuk mengembalikan aksesnya,
        buat akun pengelola baru.
      </p>
      <PesanBaris v-if="galatCabut" jenis="galat">{{ galatCabut }}</PesanBaris>
      <div class="aksi">
        <button type="button" :disabled="mencabut" @click="cabut">
          {{ mencabut ? 'Mencabut...' : 'Cabut akses' }}
        </button>
        <button type="button" class="halus" @click="konfirmasiCabut = null">Batal</button>
      </div>
    </section>

    <section class="kartu">
      <div class="tabel-bungkus">
        <table class="tabel">
          <thead>
            <tr>
              <th>Nama</th>
              <th>Email</th>
              <th>Wewenang</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in daftar" :key="p.id">
              <td>
                <strong>{{ p.nama }}</strong>
                <span v-if="p.sudah_aktif" class="lencana-status status-aktif lencana-akun">aktif</span>
                <span v-else class="lencana-status status-belum_bayar lencana-akun">belum aktif</span>
              </td>
              <td class="lemah mungil">{{ p.email }}</td>
              <td>
                <span v-if="!p.wewenang || p.wewenang.length === 0" class="lemah mungil">
                  Belum ada wewenang
                </span>
                <span v-else class="kumpulan-lencana">
                  <span
                    v-for="w in p.wewenang"
                    :key="w"
                    class="lencana-wewenang"
                    :class="{ sensitif: wewenangSensitif.includes(w) }"
                  >
                    {{ label(w) }}
                  </span>
                </span>
              </td>
              <td class="kanan aksi-baris">
                <button class="halus mungil" @click="bukaUbah(p)">Ubah</button>
                <button
                  v-if="!p.sudah_aktif"
                  class="halus mungil"
                  :disabled="kirimUlangId === p.id"
                  @click="kirimUlang(p)"
                >
                  {{ kirimUlangId === p.id ? 'mengirim…' : 'kirim ulang tautan' }}
                </button>
                <button class="halus mungil" @click="mintaCabut(p)">Cabut akses</button>
              </td>
            </tr>
            <BarisKosong v-if="!memuat && daftar.length === 0" :kolom="4">
              Belum ada pengelola. Kos ini hanya diurus pemilik.
            </BarisKosong>
            <BarisKosong v-if="memuat" :kolom="4">Memuat pengelola...</BarisKosong>
          </tbody>
        </table>
      </div>
    </section>

    <PesanBaris jenis="netral" mungil>
      Pemilik selalu boleh melakukan semuanya. Daftar ini hanya membatasi pengelola, dan setiap
      tindakannya tercatat di jejak audit dengan namanya sendiri.
    </PesanBaris>
  </div>
</template>

<style scoped>
.aksi-baris {
  white-space: nowrap;
}

/* Lencana status di sebelah nama: ukurannya dikecilkan supaya tidak menggeser
   kolom wewenang, dan `vertical-align` menjaga garis dasarnya sejajar teks. */
.lencana-akun {
  margin-left: 6px;
  vertical-align: 1px;
  font-size: 10.5px;
}

.kelompok {
  margin: 0;
  padding: 12px 14px;
  border: 1px solid var(--garis);
  border-radius: 10px;
}

.kelompok legend {
  padding: 0 6px;
  font-size: 12.5px;
  font-weight: 620;
}

/* Kelompok sensitif ditandai garis kiri, bukan warna latar penuh: latar penuh
   membuat dua kelompok terlihat seperti dua tingkat kepentingan yang berbeda,
   padahal keduanya sama-sama wewenang yang sah. */
.kelompok-sensitif {
  border-left: 3px solid var(--bahaya);
}

.kisi-wewenang {
  display: grid;
  /* `minmax(0, 1fr)`, bukan `1fr`: minimum `auto` mengambil lebar min-content,
     dan label panjang seperti "Memverifikasi pembayaran" memaksa kolom lebih
     lebar daripada layar 360px. */
  grid-template-columns: repeat(auto-fit, minmax(0, 1fr));
  gap: 8px 16px;
  margin-top: 8px;
}

.pilihan {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  font-size: 13px;
  cursor: pointer;
}

.pilihan input {
  /* Ukuran bawaan kotak centang 13px terlalu kecil untuk jari; 16px adalah
     ukuran terkecil yang masih bisa ditekan tanpa salah sasaran. */
  width: 16px;
  height: 16px;
  margin-top: 1px;
  flex: none;
}

.kumpulan-lencana {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.lencana-wewenang {
  display: inline-block;
  padding: 2px 7px;
  font-size: 11.5px;
  color: var(--teks-lemah);
  background: #eef0f3;
  border-radius: 999px;
}

.lencana-wewenang.sensitif {
  color: var(--bahaya);
  background: var(--bahaya-lemah);
}

.panel-cabut {
  border-left: 3px solid var(--bahaya);
}

.panel-cabut :deep(h2) {
  color: var(--bahaya);
}

@media (max-width: 620px) {
  .kisi-wewenang {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
