<script setup>
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { rupiah, tanggal, tanggalHariIni, pesanGalat, galatPerBidang } from '../../format.js'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'
import MedanForm from '../../komponen/ui/MedanForm.vue'
import LencanaStatus from '../../komponen/ui/LencanaStatus.vue'
import BarisKosong from '../../komponen/ui/BarisKosong.vue'

const daftar = ref([])
const kamar = ref([])
const penghuni = ref([])
const memuat = ref(true)
const galatMuat = ref('')

const formTerbuka = ref(false)
const menyimpan = ref(false)
const galatForm = ref('')
const galatBidang = ref({})
const form = ref({ kamar_id: '', penghuni_id: '', tgl_masuk: '', tarif: '', deposit: '' })

const memproses = ref(null)
const galatAksi = ref('')

const kamarKosong = computed(() => kamar.value.filter((k) => k.status_huni === 'kosong'))

const tarifKamarTerpilih = computed(() => {
  const terpilih = kamar.value.find((k) => String(k.id) === String(form.value.kamar_id))
  return terpilih?.tarif_terakhir || null
})

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const [hasilKontrak, hasilKamar, hasilPenghuni] = await Promise.all([
      sesi.panggil('/kontrak'),
      sesi.panggil('/kamar'),
      sesi.panggil('/penghuni'),
    ])
    daftar.value = hasilKontrak.data
    kamar.value = hasilKamar.data
    penghuni.value = hasilPenghuni.data
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

function bukaForm() {
  form.value = {
    kamar_id: '',
    penghuni_id: '',
    tgl_masuk: tanggalHariIni(),
    tarif: '',
    deposit: '',
  }
  galatForm.value = ''
  galatBidang.value = {}
  formTerbuka.value = true
}

async function simpan() {
  menyimpan.value = true
  galatForm.value = ''
  galatBidang.value = {}
  try {
    const isi = {
      kamar_id: Number(form.value.kamar_id),
      penghuni_id: Number(form.value.penghuni_id),
      tgl_masuk: form.value.tgl_masuk,
    }
    // Deposit hanya dikirim bila diisi, sama seperti tarif. Mengirim 0 untuk
    // medan kosong akan membatalkan deposit dari kebijakan kos: server membaca
    // "0 yang diketik pemilik" sebagai kesepakatan eksplisit, dan tidak bisa
    // membedakannya dari "tidak diisi". Justru perbedaan itulah yang membuat
    // kebijakan deposit berguna sebagai bawaan.
    // Tarif hanya dikirim bila diisi. Kalau kamar sudah punya tarif yang
    // berlaku, server memakai tarif itu; mengirim angka berbeda akan ditolak
    // dan pesannya ditampilkan apa adanya.
    if (form.value.tarif !== '') {
      isi.tarif = Number(form.value.tarif)
    }
    // Nol yang diketik pemilik tetap dikirim: itu pernyataan "kamar ini tanpa
    // deposit", yang memang berbeda dari "ikuti kebijakan kos".
    if (form.value.deposit !== '') {
      isi.deposit = Number(form.value.deposit)
    }

    await sesi.panggil('/kontrak', { method: 'POST', body: JSON.stringify(isi) })
    formTerbuka.value = false
    await muat()
  } catch (e) {
    galatForm.value = pesanGalat(e)
    galatBidang.value = galatPerBidang(e)
  } finally {
    menyimpan.value = false
  }
}

async function akhiri(kontrak) {
  const yakin = window.confirm(
    `Akhiri kontrak ${kontrak.penghuni?.nama} di kamar ${kontrak.kamar?.nomor}? ` +
      'Kontrak yang sudah diakhiri tidak bisa diaktifkan kembali.',
  )
  if (!yakin) {
    return
  }

  memproses.value = kontrak.id
  galatAksi.value = ''
  try {
    await sesi.panggil(`/kontrak/${kontrak.id}/akhiri`, { method: 'POST' })
    await muat()
  } catch (e) {
    galatAksi.value = pesanGalat(e)
  } finally {
    memproses.value = null
  }
}

onMounted(muat)
</script>

<template>
  <div class="halaman">
    <KepalaHalaman judul="Kontrak">
      <template #keterangan>
        {{ kamarKosong.length }} kamar kosong siap ditempati.
      </template>
      <template #aksi>
        <button v-if="!formTerbuka" @click="bukaForm">Buat kontrak</button>
      </template>
    </KepalaHalaman>

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>
    <PesanBaris v-if="galatAksi" jenis="galat">{{ galatAksi }}</PesanBaris>

    <section v-if="formTerbuka" class="kartu panel">
      <h2>Kontrak baru</h2>
      <form class="bidang" @submit.prevent="simpan">
        <div class="baris-bidang">
          <MedanForm id="kamar" label="Kamar" :galat="galatBidang.kamar_id">
            <select
              id="kamar"
              v-model="form.kamar_id"
              :aria-invalid="Boolean(galatBidang.kamar_id)"
            >
              <option value="" disabled>Pilih kamar</option>
              <option v-for="k in kamar" :key="k.id" :value="k.id">
                {{ k.nomor }}{{ k.tipe ? ` — ${k.tipe}` : '' }}{{
                  k.status_huni === 'terisi' ? ' (terisi)' : ''
                }}
              </option>
            </select>
          </MedanForm>
          <MedanForm id="penghuni" label="Penghuni" :galat="galatBidang.penghuni_id">
            <select
              id="penghuni"
              v-model="form.penghuni_id"
              :aria-invalid="Boolean(galatBidang.penghuni_id)"
            >
              <option value="" disabled>Pilih penghuni</option>
              <option v-for="p in penghuni" :key="p.id" :value="p.id">{{ p.nama }}</option>
            </select>
          </MedanForm>
        </div>

        <div class="baris-bidang">
          <MedanForm
            id="tgl_masuk"
            label="Tanggal masuk"
            :galat="galatBidang.tgl_masuk"
            bantuan="Jatuh tempo tagihan mengikuti tanggal ini."
          >
            <input
              id="tgl_masuk"
              v-model="form.tgl_masuk"
              type="date"
              :max="tanggalHariIni()"
              :aria-invalid="Boolean(galatBidang.tgl_masuk)"
            />
          </MedanForm>
          <MedanForm
            id="deposit"
            label="Deposit"
            :galat="galatBidang.deposit"
            bantuan="Kosongkan untuk mengikuti kebijakan kos. Isi 0 bila kamar ini memang tanpa deposit."
          >
            <template #label-ekstra> <span class="lemah">(opsional)</span></template>
            <input
              id="deposit"
              v-model="form.deposit"
              inputmode="numeric"
              placeholder="Ikuti kebijakan"
              :aria-invalid="Boolean(galatBidang.deposit)"
            />
          </MedanForm>
        </div>

        <MedanForm id="tarif" label="Tarif bulanan" :galat="galatBidang.tarif">
          <input
            id="tarif"
            v-model="form.tarif"
            inputmode="numeric"
            :placeholder="tarifKamarTerpilih ? String(tarifKamarTerpilih.nilai) : '1500000'"
            :aria-invalid="Boolean(galatBidang.tarif)"
          />
          <template #bantuan>
            <template v-if="tarifKamarTerpilih">
              Kamar ini sudah bertarif {{ rupiah(tarifKamarTerpilih.nilai) }} sejak
              {{ tanggal(tarifKamarTerpilih.berlaku_dari) }}. Kosongkan kolom ini untuk memakai
              tarif tersebut.
            </template>
            <template v-else>
              Kamar ini belum punya tarif, jadi tarif wajib diisi dan akan dicatat sebagai tarif
              pertama kamar.
            </template>
          </template>
        </MedanForm>

        <PesanBaris v-if="galatForm" jenis="galat">{{ galatForm }}</PesanBaris>

        <div class="aksi">
          <button type="submit" :disabled="menyimpan">
            {{ menyimpan ? 'Menyimpan…' : 'Simpan kontrak' }}
          </button>
          <button type="button" class="halus" @click="formTerbuka = false">Batal</button>
        </div>
      </form>
    </section>

    <section class="kartu">
      <div class="tabel-bungkus">
        <table class="tabel">
          <thead>
            <tr>
              <th>Penghuni</th>
              <th>Kamar</th>
              <th>Masuk</th>
              <th>Keluar</th>
              <th class="kanan">Tarif awal</th>
              <th class="kanan">Deposit</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="k in daftar" :key="k.id">
              <td><strong>{{ k.penghuni?.nama || '—' }}</strong></td>
              <td>{{ k.kamar?.nomor || '—' }}</td>
              <td>{{ tanggal(k.tgl_masuk) }}</td>
              <td>{{ tanggal(k.tgl_keluar) }}</td>
              <td class="kanan">{{ rupiah(k.tarif_awal) }}</td>
              <td class="kanan">{{ rupiah(k.deposit) }}</td>
              <td>
                <LencanaStatus :status="k.status" />
              </td>
              <td class="kanan">
                <button
                  v-if="k.status === 'aktif'"
                  class="halus mungil"
                  :disabled="memproses === k.id"
                  @click="akhiri(k)"
                >
                  {{ memproses === k.id ? 'Memproses…' : 'Akhiri' }}
                </button>
              </td>
            </tr>
            <BarisKosong v-if="!memuat && daftar.length === 0" :kolom="8">
              Belum ada kontrak. Buat kontrak untuk menempatkan penghuni di kamar.
            </BarisKosong>
            <BarisKosong v-if="memuat" :kolom="8">Memuat…</BarisKosong>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
/* Seluruh tata letak halaman ini memakai kelas global di style.css:
   .halaman, .kepala, .panel, .aksi. Tidak ada gaya khas di sini. */
</style>
