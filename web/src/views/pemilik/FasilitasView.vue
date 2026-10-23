<script setup>
/**
 * Fasilitas kos (T18).
 *
 * Halaman ini sengaja hanya mengelola DAFTAR fasilitas. Pemilihan fasilitas
 * per kamar ada di halaman Kamar, karena di sanalah pemilik sedang memikirkan
 * satu kamar tertentu.
 *
 * Tidak ada satu pun angka uang di halaman ini, dan itu bukan kelalaian.
 * Fasilitas mencatat "kamar ini punya apa"; yang menagih adalah komponen biaya.
 * Kalau daftar ini menampilkan tarif, pemilik akan mengira mencentang AC
 * otomatis menagih penghuni - dan biaya yang muncul tanpa persetujuan adalah
 * persis yang ingin dicegah.
 */
import { onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { pesanGalat, galatPerBidang } from '../../format.js'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'
import BarisKosong from '../../komponen/ui/BarisKosong.vue'
import MedanForm from '../../komponen/ui/MedanForm.vue'

const daftar = ref([])
const memuat = ref(true)
const galatMuat = ref('')

const formTerbuka = ref(false)
const menyimpan = ref(false)
const galatForm = ref('')
const galatBidang = ref({})
const sedangDiubah = ref(null)
const form = ref({ nama: '', ikon: '', urutan: 0 })

const konfirmasiHapus = ref(null)
const menghapus = ref(false)
const galatHapus = ref('')

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const hasil = await sesi.panggil('/fasilitas')
    daftar.value = hasil.data
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

function bukaTambah() {
  sedangDiubah.value = null
  form.value = { nama: '', ikon: '', urutan: 0 }
  galatForm.value = ''
  galatBidang.value = {}
  formTerbuka.value = true
}

function bukaUbah(f) {
  sedangDiubah.value = f
  form.value = { nama: f.nama, ikon: f.ikon || '', urutan: f.urutan ?? 0 }
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
    const isi = {
      nama: form.value.nama.trim(),
      ikon: form.value.ikon.trim() || null,
      urutan: Number(form.value.urutan) || 0,
    }

    if (sedangDiubah.value) {
      await sesi.panggil(`/fasilitas/${sedangDiubah.value.id}`, {
        method: 'PUT',
        body: JSON.stringify(isi),
      })
    } else {
      await sesi.panggil('/fasilitas', {
        method: 'POST',
        body: JSON.stringify(isi),
      })
    }

    tutupForm()
    await muat()
  } catch (e) {
    galatForm.value = pesanGalat(e)
    galatBidang.value = galatPerBidang(e)
  } finally {
    menyimpan.value = false
  }
}

function mintaHapus(f) {
  konfirmasiHapus.value = f
  galatHapus.value = ''
}

async function hapus() {
  menghapus.value = true
  galatHapus.value = ''
  try {
    await sesi.panggil(`/fasilitas/${konfirmasiHapus.value.id}`, { method: 'DELETE' })
    konfirmasiHapus.value = null
    await muat()
  } catch (e) {
    galatHapus.value = pesanGalat(e)
  } finally {
    menghapus.value = false
  }
}

onMounted(muat)
</script>

<template>
  <div class="halaman">
    <KepalaHalaman judul="Fasilitas">
      <template #keterangan>
        {{ daftar.length }} fasilitas terdaftar. Fasilitas dicatat per kamar dan tidak menagih
        apa pun.
      </template>
      <template #aksi>
        <button v-if="!formTerbuka" @click="bukaTambah">Tambah fasilitas</button>
      </template>
    </KepalaHalaman>

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>

    <section v-if="formTerbuka" class="kartu panel">
      <h2>{{ sedangDiubah ? 'Ubah fasilitas' : 'Tambah fasilitas' }}</h2>
      <form class="bidang" @submit.prevent="simpan">
        <div class="baris-bidang">
          <MedanForm id="nama-fasilitas" label="Nama fasilitas" :galat="galatBidang.nama">
            <input
              id="nama-fasilitas"
              v-model="form.nama"
              placeholder="AC"
              :aria-invalid="Boolean(galatBidang.nama)"
              autocomplete="off"
            />
          </MedanForm>
          <MedanForm
            id="urutan-fasilitas"
            label="Urutan tampil"
            bantuan="Angka kecil tampil lebih dulu. Boleh dikosongkan."
            :galat="galatBidang.urutan"
          >
            <input
              id="urutan-fasilitas"
              v-model="form.urutan"
              type="number"
              min="0"
              placeholder="0"
              :aria-invalid="Boolean(galatBidang.urutan)"
            />
          </MedanForm>
        </div>

        <MedanForm
          id="ikon-fasilitas"
          label="Ikon"
          bantuan="Kode pendek untuk ditampilkan di kartu kamar, mis. ac, wifi. Opsional."
          :galat="galatBidang.ikon"
        >
          <input
            id="ikon-fasilitas"
            v-model="form.ikon"
            placeholder="ac"
            :aria-invalid="Boolean(galatBidang.ikon)"
            autocomplete="off"
          />
        </MedanForm>

        <PesanBaris v-if="galatForm" jenis="galat">{{ galatForm }}</PesanBaris>

        <div class="aksi">
          <button type="submit" :disabled="menyimpan">
            {{ menyimpan ? 'Menyimpan...' : 'Simpan fasilitas' }}
          </button>
          <button type="button" class="halus" @click="tutupForm">Batal</button>
        </div>
      </form>
    </section>

    <section v-if="konfirmasiHapus" class="kartu panel">
      <h2>Hapus fasilitas &ldquo;{{ konfirmasiHapus.nama }}&rdquo;?</h2>
      <p class="mungil">
        Fasilitas ini akan dilepas dari
        <strong>{{ konfirmasiHapus.dipakai_kamar || 0 }} kamar</strong>.
        Tagihan yang sudah terbit tidak berubah, karena tagihan tidak pernah membaca daftar ini.
      </p>
      <PesanBaris v-if="galatHapus" jenis="galat">{{ galatHapus }}</PesanBaris>
      <div class="aksi">
        <button type="button" :disabled="menghapus" @click="hapus">
          {{ menghapus ? 'Menghapus...' : 'Hapus fasilitas' }}
        </button>
        <button type="button" class="halus" @click="konfirmasiHapus = null">Batal</button>
      </div>
    </section>

    <section class="kartu">
      <div class="tabel-bungkus">
        <table class="tabel">
          <thead>
            <tr>
              <th>Nama</th>
              <th>Ikon</th>
              <th class="kanan">Dipakai</th>
              <th class="kanan">Urutan</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="f in daftar" :key="f.id">
              <td><strong>{{ f.nama }}</strong></td>
              <td class="lemah mungil">{{ f.ikon || '-' }}</td>
              <td class="kanan">
                {{ f.dipakai_kamar || 0 }} kamar
              </td>
              <td class="kanan lemah mungil">{{ f.urutan }}</td>
              <td class="kanan aksi-baris">
                <button class="halus mungil" @click="bukaUbah(f)">Ubah</button>
                <button class="halus mungil" @click="mintaHapus(f)">Hapus</button>
              </td>
            </tr>
            <BarisKosong v-if="!memuat && daftar.length === 0" :kolom="5">
              Belum ada fasilitas. Tambahkan yang benar-benar dimiliki kamar kos ini.
            </BarisKosong>
            <BarisKosong v-if="memuat" :kolom="5">Memuat fasilitas...</BarisKosong>
          </tbody>
        </table>
      </div>
    </section>

    <PesanBaris jenis="netral" mungil>
      Fasilitas hanya dicatat, tidak menagih. Untuk menagih sesuatu (mis. listrik AC Rp 100.000
      per bulan), tambahkan lewat komponen biaya di halaman Tagihan.
    </PesanBaris>
  </div>
</template>

<style scoped>
/* Tombol aksi ditahan satu baris: "Hapus" yang terlipat ke bawah "Ubah" membuat
   baris dua kali lebih tinggi tanpa ada asersi yang menangkapnya. */
.aksi-baris {
  white-space: nowrap;
}
</style>
