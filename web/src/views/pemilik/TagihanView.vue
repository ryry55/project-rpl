<script setup>
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { rupiah, tanggal, tanggalHariIni, pesanGalat, galatPerBidang } from '../../format.js'

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

const totalPokok = computed(() => daftar.value.reduce((j, t) => j + t.pokok, 0))

function periodeHariIni() {
  const sekarang = new Date()
  const bulan = String(sekarang.getMonth() + 1).padStart(2, '0')
  return `${sekarang.getFullYear()}-${bulan}-01`
}

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const [hasilTagihan, hasilKontrak] = await Promise.all([
      sesi.panggil('/tagihan'),
      sesi.panggil('/kontrak'),
    ])
    daftar.value = hasilTagihan.data
    kontrak.value = hasilKontrak.data.filter((k) => k.status === 'aktif')
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
    const hasil = await sesi.panggil(`/tagihan/${tagihan.id}`)
    detail.value = hasil.data
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuatDetail.value = false
  }
}

function tutupDetail() {
  detail.value = null
}

onMounted(muat)
</script>

<template>
  <div class="halaman">
    <header class="kepala">
      <div>
        <h1>Tagihan</h1>
        <p class="lemah mungil">
          {{ daftar.length }} tagihan · total pokok {{ rupiah(totalPokok) }}
        </p>
      </div>
      <button v-if="!formTerbuka" @click="bukaForm">Terbitkan tagihan</button>
    </header>

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
          </tbody>
        </table>
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
              <td>{{ tanggal(t.jatuh_tempo) }}</td>
              <td>
                <span class="lencana-status" :class="`status-${t.status}`">{{ t.status.replace('_', ' ') }}</span>
              </td>
              <td class="kanan">
                <button class="halus mungil" @click="bukaDetail(t)">Rincian</button>
              </td>
            </tr>
            <tr v-if="!memuat && daftar.length === 0">
              <td colspan="7" class="sel-kosong">
                Belum ada tagihan. Terbitkan tagihan pertama lewat tombol di atas.
              </td>
            </tr>
            <tr v-if="memuat">
              <td colspan="7" class="sel-kosong">Memuat…</td>
            </tr>
            <tr v-if="memuatDetail">
              <td colspan="7" class="sel-kosong">Memuat rincian…</td>
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
</style>
