<script setup>
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { rupiah, tanggal, tanggalHariIni, pesanGalat, galatPerBidang } from '../../format.js'

const daftar = ref([])
const memuat = ref(true)
const galatMuat = ref('')

const formTerbuka = ref(false)
const menyimpan = ref(false)
const galatForm = ref('')
const galatBidang = ref({})
const form = ref({ nomor: '', tipe: '', catatan: '' })

// Kamar yang riwayat tarifnya sedang dibuka.
const kamarTarif = ref(null)
const riwayat = ref([])
const memuatRiwayat = ref(false)
const formTarif = ref({ nilai: '', berlaku_dari: tanggalHariIni() })
const galatTarif = ref('')
const galatTarifBidang = ref({})
const menyimpanTarif = ref(false)

const jumlahTerisi = computed(() => daftar.value.filter((k) => k.status_huni === 'terisi').length)
const jumlahKosong = computed(() => daftar.value.length - jumlahTerisi.value)

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const hasil = await sesi.panggil('/kamar')
    daftar.value = hasil.data
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

function bukaForm() {
  form.value = { nomor: '', tipe: '', catatan: '' }
  galatForm.value = ''
  galatBidang.value = {}
  formTerbuka.value = true
}

function tutupForm() {
  formTerbuka.value = false
}

async function simpan() {
  menyimpan.value = true
  galatForm.value = ''
  galatBidang.value = {}
  try {
    await sesi.panggil('/kamar', {
      method: 'POST',
      body: JSON.stringify({
        nomor: form.value.nomor.trim(),
        tipe: form.value.tipe.trim() || null,
        catatan: form.value.catatan.trim() || null,
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

async function bukaTarif(kamar) {
  kamarTarif.value = kamar
  riwayat.value = []
  galatTarif.value = ''
  galatTarifBidang.value = {}
  formTarif.value = { nilai: '', berlaku_dari: tanggalHariIni() }
  memuatRiwayat.value = true
  try {
    const hasil = await sesi.panggil(`/tarif?kamar_id=${kamar.id}`)
    riwayat.value = hasil.data
  } catch (e) {
    galatTarif.value = pesanGalat(e)
  } finally {
    memuatRiwayat.value = false
  }
}

function tutupTarif() {
  kamarTarif.value = null
  riwayat.value = []
}

async function simpanTarif() {
  menyimpanTarif.value = true
  galatTarif.value = ''
  galatTarifBidang.value = {}
  try {
    await sesi.panggil('/tarif', {
      method: 'POST',
      body: JSON.stringify({
        kamar_id: kamarTarif.value.id,
        // Nilai dikirim sebagai bilangan bulat. Kalau pengguna mengetik
        // "1500000.5", Number() menghasilkan pecahan dan server menolaknya
        // dengan 422 — itu memang yang diinginkan, bukan dipotong diam-diam.
        nilai: Number(formTarif.value.nilai),
        berlaku_dari: formTarif.value.berlaku_dari,
      }),
    })
    formTarif.value = { nilai: '', berlaku_dari: tanggalHariIni() }
    await bukaTarif(kamarTarif.value)
    await muat()
  } catch (e) {
    galatTarif.value = pesanGalat(e)
    galatTarifBidang.value = galatPerBidang(e)
  } finally {
    menyimpanTarif.value = false
  }
}

onMounted(muat)
</script>

<template>
  <div class="halaman">
    <header class="kepala">
      <div>
        <h1>Kamar</h1>
        <p class="lemah mungil">
          {{ daftar.length }} kamar · {{ jumlahTerisi }} terisi · {{ jumlahKosong }} kosong
        </p>
      </div>
      <button v-if="!formTerbuka" @click="bukaForm">Tambah kamar</button>
    </header>

    <p v-if="galatMuat" class="pesan-galat">{{ galatMuat }}</p>

    <section v-if="formTerbuka" class="kartu panel">
      <h2>Tambah kamar</h2>
      <form class="bidang" @submit.prevent="simpan">
        <div class="baris-bidang">
          <div>
            <label for="nomor">Nomor kamar</label>
            <input
              id="nomor"
              v-model="form.nomor"
              placeholder="A1"
              :aria-invalid="Boolean(galatBidang.nomor)"
              autocomplete="off"
            />
            <p v-if="galatBidang.nomor" class="galat-bidang">{{ galatBidang.nomor }}</p>
          </div>
          <div>
            <label for="tipe">Tipe <span class="lemah">(opsional)</span></label>
            <input id="tipe" v-model="form.tipe" placeholder="Standar" autocomplete="off" />
          </div>
        </div>

        <div>
          <label for="catatan">Catatan <span class="lemah">(opsional)</span></label>
          <input id="catatan" v-model="form.catatan" placeholder="Kamar pojok, jendela utara" />
        </div>

        <p v-if="galatForm" class="pesan-galat">{{ galatForm }}</p>

        <div class="aksi">
          <button type="submit" :disabled="menyimpan">
            {{ menyimpan ? 'Menyimpan…' : 'Simpan kamar' }}
          </button>
          <button type="button" class="halus" @click="tutupForm">Batal</button>
        </div>
      </form>
    </section>

    <section v-if="kamarTarif" class="kartu panel">
      <div class="panel-kepala">
        <div>
          <h2>Tarif kamar {{ kamarTarif.nomor }}</h2>
          <p class="lemah mungil">
            Mengubah tarif menambah baris baru. Baris lama tetap tersimpan sebagai riwayat.
          </p>
        </div>
        <button class="halus mungil" @click="tutupTarif">Tutup</button>
      </div>

      <p v-if="memuatRiwayat" class="lemah mungil">Memuat riwayat…</p>

      <div v-else class="tabel-bungkus">
        <table class="tabel">
          <thead>
            <tr>
              <th>Berlaku dari</th>
              <th class="kanan">Tarif per bulan</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(t, i) in riwayat" :key="t.id">
              <td>{{ tanggal(t.berlaku_dari) }}</td>
              <td class="kanan">{{ rupiah(t.nilai) }}</td>
              <td>
                <span v-if="i === 0" class="lencana-status status-aktif">Berlaku sekarang</span>
                <span v-else class="lemah mungil">Riwayat</span>
              </td>
            </tr>
            <tr v-if="riwayat.length === 0">
              <td colspan="3" class="sel-kosong">Belum ada tarif untuk kamar ini.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <form class="bidang riwayat-form" @submit.prevent="simpanTarif">
        <div class="baris-bidang">
          <div>
            <label for="nilai-tarif">Tarif baru per bulan</label>
            <input
              id="nilai-tarif"
              v-model="formTarif.nilai"
              inputmode="numeric"
              placeholder="1500000"
              :aria-invalid="Boolean(galatTarifBidang.nilai)"
              autocomplete="off"
            />
            <p v-if="galatTarifBidang.nilai" class="galat-bidang">{{ galatTarifBidang.nilai }}</p>
          </div>
          <div>
            <label for="berlaku-tarif">Berlaku dari</label>
            <input
              id="berlaku-tarif"
              v-model="formTarif.berlaku_dari"
              type="date"
              :aria-invalid="Boolean(galatTarifBidang.berlaku_dari)"
            />
            <p v-if="galatTarifBidang.berlaku_dari" class="galat-bidang">
              {{ galatTarifBidang.berlaku_dari }}
            </p>
          </div>
        </div>

        <p v-if="galatTarif" class="pesan-galat">{{ galatTarif }}</p>

        <div class="aksi">
          <button type="submit" :disabled="menyimpanTarif">
            {{ menyimpanTarif ? 'Menyimpan…' : 'Berlakukan tarif' }}
          </button>
        </div>
      </form>
    </section>

    <section class="kartu">
      <div class="tabel-bungkus">
        <table class="tabel">
          <thead>
            <tr>
              <th>Nomor</th>
              <th>Tipe</th>
              <th>Status</th>
              <th>Penghuni</th>
              <th class="kanan">Tarif terakhir</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="k in daftar" :key="k.id">
              <td><strong>{{ k.nomor }}</strong></td>
              <td>{{ k.tipe || '—' }}</td>
              <td>
                <span class="lencana-status" :class="`status-${k.status_huni}`">{{ k.status_huni }}</span>
              </td>
              <td>{{ k.penghuni?.nama || '—' }}</td>
              <td class="kanan">
                <template v-if="k.tarif_terakhir">
                  {{ rupiah(k.tarif_terakhir.nilai) }}
                  <span class="lemah mungil">sejak {{ tanggal(k.tarif_terakhir.berlaku_dari) }}</span>
                </template>
                <span v-else class="lemah mungil">Belum ditetapkan</span>
              </td>
              <td class="kanan">
                <button class="halus mungil" @click="bukaTarif(k)">Atur tarif</button>
              </td>
            </tr>
            <tr v-if="!memuat && daftar.length === 0">
              <td colspan="6" class="sel-kosong">
                Belum ada kamar. Tambahkan kamar pertama lewat tombol di atas.
              </td>
            </tr>
            <tr v-if="memuat">
              <td colspan="6" class="sel-kosong">Memuat…</td>
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

.riwayat-form {
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--garis);
}
</style>
