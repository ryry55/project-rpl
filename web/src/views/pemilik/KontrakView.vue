<script setup>
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { rupiah, tanggal, tanggalHariIni, pesanGalat, galatPerBidang } from '../../format.js'

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
      deposit: form.value.deposit === '' ? 0 : Number(form.value.deposit),
    }
    // Tarif hanya dikirim bila diisi. Kalau kamar sudah punya tarif yang
    // berlaku, server memakai tarif itu; mengirim angka berbeda akan ditolak
    // dan pesannya ditampilkan apa adanya.
    if (form.value.tarif !== '') {
      isi.tarif = Number(form.value.tarif)
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
    <header class="kepala">
      <div>
        <h1>Kontrak</h1>
        <p class="lemah mungil">
          {{ kamarKosong.length }} kamar kosong siap ditempati.
        </p>
      </div>
      <button v-if="!formTerbuka" @click="bukaForm">Buat kontrak</button>
    </header>

    <p v-if="galatMuat" class="pesan-galat">{{ galatMuat }}</p>
    <p v-if="galatAksi" class="pesan-galat">{{ galatAksi }}</p>

    <section v-if="formTerbuka" class="kartu panel">
      <h2>Kontrak baru</h2>
      <form class="bidang" @submit.prevent="simpan">
        <div class="baris-bidang">
          <div>
            <label for="kamar">Kamar</label>
            <select id="kamar" v-model="form.kamar_id" :aria-invalid="Boolean(galatBidang.kamar_id)">
              <option value="" disabled>Pilih kamar</option>
              <option v-for="k in kamar" :key="k.id" :value="k.id">
                {{ k.nomor }}{{ k.tipe ? ` — ${k.tipe}` : '' }}{{
                  k.status_huni === 'terisi' ? ' (terisi)' : ''
                }}
              </option>
            </select>
            <p v-if="galatBidang.kamar_id" class="galat-bidang">{{ galatBidang.kamar_id }}</p>
          </div>
          <div>
            <label for="penghuni">Penghuni</label>
            <select
              id="penghuni"
              v-model="form.penghuni_id"
              :aria-invalid="Boolean(galatBidang.penghuni_id)"
            >
              <option value="" disabled>Pilih penghuni</option>
              <option v-for="p in penghuni" :key="p.id" :value="p.id">{{ p.nama }}</option>
            </select>
            <p v-if="galatBidang.penghuni_id" class="galat-bidang">{{ galatBidang.penghuni_id }}</p>
          </div>
        </div>

        <div class="baris-bidang">
          <div>
            <label for="tgl_masuk">Tanggal masuk</label>
            <input
              id="tgl_masuk"
              v-model="form.tgl_masuk"
              type="date"
              :max="tanggalHariIni()"
              :aria-invalid="Boolean(galatBidang.tgl_masuk)"
            />
            <p v-if="galatBidang.tgl_masuk" class="galat-bidang">{{ galatBidang.tgl_masuk }}</p>
            <p v-else class="bantuan">Jatuh tempo tagihan mengikuti tanggal ini.</p>
          </div>
          <div>
            <label for="deposit">Deposit <span class="lemah">(opsional)</span></label>
            <input
              id="deposit"
              v-model="form.deposit"
              inputmode="numeric"
              placeholder="0"
              :aria-invalid="Boolean(galatBidang.deposit)"
            />
            <p v-if="galatBidang.deposit" class="galat-bidang">{{ galatBidang.deposit }}</p>
          </div>
        </div>

        <div>
          <label for="tarif">Tarif bulanan</label>
          <input
            id="tarif"
            v-model="form.tarif"
            inputmode="numeric"
            :placeholder="tarifKamarTerpilih ? String(tarifKamarTerpilih.nilai) : '1500000'"
            :aria-invalid="Boolean(galatBidang.tarif)"
          />
          <p v-if="galatBidang.tarif" class="galat-bidang">{{ galatBidang.tarif }}</p>
          <p v-else-if="tarifKamarTerpilih" class="bantuan">
            Kamar ini sudah bertarif {{ rupiah(tarifKamarTerpilih.nilai) }} sejak
            {{ tanggal(tarifKamarTerpilih.berlaku_dari) }}. Kosongkan kolom ini untuk memakai tarif
            tersebut.
          </p>
          <p v-else class="bantuan">
            Kamar ini belum punya tarif, jadi tarif wajib diisi dan akan dicatat sebagai tarif
            pertama kamar.
          </p>
        </div>

        <p v-if="galatForm" class="pesan-galat">{{ galatForm }}</p>

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
                <span class="lencana-status" :class="`status-${k.status}`">{{ k.status }}</span>
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
            <tr v-if="!memuat && daftar.length === 0">
              <td colspan="8" class="sel-kosong">
                Belum ada kontrak. Buat kontrak untuk menempatkan penghuni di kamar.
              </td>
            </tr>
            <tr v-if="memuat">
              <td colspan="8" class="sel-kosong">Memuat…</td>
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
  max-width: 1020px;
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

.panel {
  padding: 20px 22px;
}

.panel h2 {
  margin-bottom: 14px;
}

.aksi {
  display: flex;
  gap: 9px;
}
</style>
