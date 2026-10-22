<script setup>
/**
 * Portal penghuni (T9, R-07).
 *
 * Satu halaman, dua keadaan: daftar tagihan, dan rincian satu tagihan yang
 * sedang dibuka. Tidak dibuat halaman detail terpisah karena penghuni biasanya
 * hanya punya beberapa tagihan, dan berpindah halaman untuk melihat satu
 * baris terasa lebih berat daripada membukanya di tempat.
 *
 * Semua angka di sini datang dari server, termasuk denda. Denda **tidak**
 * dihitung ulang di peramban: kalau dihitung di dua tempat, cepat atau lambat
 * keduanya menyimpang, dan yang dilihat penghuni bukan yang dilihat pemilik.
 */
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { sesi } from '../sesi.js'
import { rupiah, tanggal, pesanGalat } from '../format.js'
import { simulasiTanggal } from '../simulasi.js'

const router = useRouter()

const daftar = ref([])
const meta = ref({ tanggal_acuan: '', simulasi: false })
const memuat = ref(true)
const galatMuat = ref('')

// Id tagihan yang sedang dibuka. `null` berarti semua tertutup.
const terbuka = ref(null)
const memuatDetail = ref(false)
const galatDetail = ref('')

const ringkasan = computed(() => {
  const belum = daftar.value.filter((t) => t.status !== 'lunas' && t.status !== 'dibatalkan')
  return {
    jumlah: daftar.value.length,
    belum: belum.length,
    sisa: belum.reduce((n, t) => n + (t.sisa || 0), 0),
  }
})

const tagihanTerbuka = computed(() => daftar.value.find((t) => t.id === terbuka.value) || null)

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    // Header simulasi ikut dikirim supaya angka denda di portal penghuni sama
    // dengan yang dilihat pemilik pada tanggal demo yang sama. Kalau tidak,
    // dua layar menampilkan denda berbeda untuk tagihan yang sama.
    const hasil = await sesi.panggil('/penghuni/tagihan', { headers: simulasiTanggal.header() })
    daftar.value = hasil.data || []
    meta.value = hasil.meta || meta.value
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

/**
 * Rincian diambil ulang dari `/tagihan/{id}`, bukan dipakai dari baris daftar.
 *
 * Bukan pemborosan: endpoint itulah yang juga dipakai kalau penghuni membuka
 * tautan langsung, jadi yang diuji E2E adalah jalur yang benar-benar dipakai.
 * Kalau rincian hanya dirakit dari data daftar, jalur itu tidak pernah
 * dijalankan sampai ada yang menemukannya di produksi.
 */
async function buka(id) {
  if (terbuka.value === id) {
    terbuka.value = null
    return
  }

  terbuka.value = id
  galatDetail.value = ''
  memuatDetail.value = true
  try {
    const hasil = await sesi.panggil(`/tagihan/${id}`, { headers: simulasiTanggal.header() })
    // Ganti barisnya dengan versi lengkap (berisi rincian dan riwayat bayar).
    daftar.value = daftar.value.map((t) => (t.id === id ? hasil.data : t))
  } catch (e) {
    galatDetail.value = pesanGalat(e)
  } finally {
    memuatDetail.value = false
  }
}

function labelStatus(status) {
  return {
    belum_bayar: 'Belum bayar',
    sebagian: 'Sebagian',
    lunas: 'Lunas',
    dibatalkan: 'Dibatalkan',
  }[status] || status
}

function namaPeriode(iso) {
  if (!iso) return '—'
  const [tahun, bulan] = iso.split('-').map(Number)
  return new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(
    new Date(tahun, bulan - 1, 1),
  )
}

async function keluar() {
  await sesi.keluar()
  router.replace('/masuk')
}

onMounted(muat)
</script>

<template>
  <div class="bingkai">
    <header class="bar">
      <div class="merek">
        <span class="lencana">KOS</span>
        <div>
          <h1>Tagihan saya</h1>
          <p class="lemah mungil">{{ sesi.user.value?.nama }}</p>
        </div>
      </div>
      <button class="halus" @click="keluar">Keluar</button>
    </header>

    <!-- Tanggal acuan diumumkan supaya penghuni tahu angka denda dihitung
         terhadap hari apa. Tanpa ini, denda yang berubah sendiri terlihat
         seperti kesalahan. -->
    <p v-if="meta.simulasi" class="pesan-netral mungil">
      Mode demo: tanggal sistem disimulasikan ke <strong>{{ tanggal(meta.tanggal_acuan) }}</strong>.
      Denda di bawah dihitung terhadap tanggal itu.
    </p>

    <p v-if="galatMuat" class="pesan-galat">{{ galatMuat }}</p>

    <section v-if="!memuat && daftar.length > 0" class="ringkas">
      <div class="petak">
        <span class="petak-label mungil">Tagihan</span>
        <strong>{{ ringkasan.jumlah }}</strong>
      </div>
      <div class="petak">
        <span class="petak-label mungil">Belum lunas</span>
        <strong>{{ ringkasan.belum }}</strong>
      </div>
      <div class="petak petak-lebar">
        <span class="petak-label mungil">Total yang masih harus dibayar</span>
        <strong class="angka-tagihan">{{ rupiah(ringkasan.sisa) }}</strong>
      </div>
    </section>

    <section class="kartu">
      <div class="tabel-bungkus">
        <table class="tabel">
          <thead>
            <tr>
              <th>Periode</th>
              <th class="kanan">Pokok</th>
              <th class="kanan">Denda</th>
              <th class="kanan">Total</th>
              <th class="kanan">Dibayar</th>
              <th class="kanan">Sisa</th>
              <th>Jatuh tempo</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="t in daftar" :key="t.id">
              <tr>
                <td>
                  <strong>{{ namaPeriode(t.periode) }}</strong>
                  <p class="mungil lemah">Kamar {{ t.kontrak?.kamar?.nomor || '—' }}</p>
                </td>
                <td class="kanan">{{ rupiah(t.pokok) }}</td>
                <td class="kanan" :class="{ 'sel-denda': t.denda > 0 }">{{ rupiah(t.denda) }}</td>
                <td class="kanan">{{ rupiah(t.total) }}</td>
                <td class="kanan lemah">{{ rupiah(t.dibayar) }}</td>
                <td class="kanan" :class="{ 'sel-sisa': t.sisa > 0 }">
                  <strong>{{ rupiah(t.sisa) }}</strong>
                </td>
                <td class="mungil">{{ tanggal(t.jatuh_tempo) }}</td>
                <td>
                  <span class="lencana-status" :class="`status-${t.status}`">
                    {{ labelStatus(t.status) }}
                  </span>
                </td>
                <td class="kanan">
                  <button class="halus mungil" @click="buka(t.id)">
                    {{ terbuka === t.id ? 'Tutup' : 'Rincian' }}
                  </button>
                </td>
              </tr>

              <tr v-if="terbuka === t.id" class="baris-rincian">
                <td colspan="9">
                  <p v-if="galatDetail" class="pesan-galat">{{ galatDetail }}</p>
                  <p v-else-if="memuatDetail" class="lemah mungil">Memuat rincian…</p>

                  <div v-else class="rincian">
                    <div>
                      <h3>Rincian tagihan</h3>
                      <ul class="daftar-nilai">
                        <li v-for="r in t.rincian || []" :key="r.id">
                          <span>{{ r.nama }}</span>
                          <strong>{{ rupiah(r.nilai) }}</strong>
                        </li>
                        <li class="total">
                          <span>Pokok</span>
                          <strong>{{ rupiah(t.pokok) }}</strong>
                        </li>
                        <li v-if="t.denda > 0" class="total">
                          <span>Denda keterlambatan</span>
                          <strong>{{ rupiah(t.denda) }}</strong>
                        </li>
                      </ul>

                      <h3>Denda</h3>
                      <ul class="daftar-nilai">
                        <li>
                          <span>Terlambat</span>
                          <strong>{{ t.denda_ringkas?.hari_terlambat || 0 }} hari</strong>
                        </li>
                        <li>
                          <span>Masa tenggang</span>
                          <strong>{{ t.denda_ringkas?.masa_tenggang || 0 }} hari</strong>
                        </li>
                        <li>
                          <span>Hari kena denda</span>
                          <strong>{{ t.denda_ringkas?.hari_denda || 0 }} hari</strong>
                        </li>
                        <li>
                          <span>Plafon</span>
                          <strong>{{ rupiah(t.denda_ringkas?.plafon || 0) }}</strong>
                        </li>
                      </ul>
                      <p v-if="t.denda_ringkas?.berhenti" class="pesan-netral mungil">
                        {{ t.denda_ringkas.alasan_berhenti }}
                        <template v-if="t.denda_ringkas.sisa_hari_ke_plafon > 0">
                          · {{ t.denda_ringkas.sisa_hari_ke_plafon }} hari lagi mencapai plafon.
                        </template>
                      </p>
                    </div>

                    <div>
                      <h3>Riwayat pembayaran</h3>
                      <p v-if="!t.pembayaran || t.pembayaran.length === 0" class="lemah mungil">
                        Belum ada pembayaran tercatat.
                      </p>
                      <ul v-else class="daftar-bayar">
                        <li v-for="p in t.pembayaran" :key="p.id">
                          <div>
                            <strong>{{ rupiah(p.jumlah) }}</strong>
                            <p class="mungil lemah">
                              {{ tanggal(p.tanggal_pembayaran) }}
                              <template v-if="p.catatan"> · {{ p.catatan }}</template>
                            </p>
                          </div>
                        </li>
                      </ul>
                    </div>
                  </div>
                </td>
              </tr>
            </template>

            <tr v-if="!memuat && daftar.length === 0">
              <td colspan="9" class="sel-kosong">
                Belum ada tagihan untuk Anda. Tagihan muncul setelah pemilik menerbitkannya.
              </td>
            </tr>
            <tr v-if="memuat">
              <td colspan="9" class="sel-kosong">Memuat tagihan…</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <p class="lemah mungil kaki">
      Anda hanya dapat melihat tagihan milik Anda sendiri. Membuka tagihan penghuni lain
      akan dijawab seolah tagihan itu tidak ada.
    </p>
  </div>
</template>

<style scoped>
.bingkai {
  max-width: 1040px;
  margin: 0 auto;
  padding: 24px;
}

.bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 20px;
}

.merek {
  display: flex;
  align-items: center;
  gap: 12px;
}

.merek h1 {
  margin: 0;
  font-size: 19px;
}

.merek p {
  margin: 1px 0 0;
}

.lencana {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: 0.06em;
  color: var(--aksen);
  background: var(--aksen-lemah);
  border-radius: 9px;
}

.ringkas {
  display: grid;
  grid-template-columns: 140px 140px minmax(0, 1fr);
  gap: 12px;
  margin-bottom: 14px;
}

.petak {
  display: grid;
  gap: 3px;
  padding: 13px 15px;
  background: var(--permukaan);
  border: 1px solid var(--garis);
  border-radius: var(--radius);
}

.petak-label {
  color: var(--teks-lemah);
}

.petak strong {
  font-size: 18px;
  font-variant-numeric: tabular-nums;
}

.petak-lebar strong {
  color: var(--bahaya);
}

.angka-tagihan {
  letter-spacing: -0.01em;
}

.baris-rincian > td {
  padding: 0;
  background: #fbfcfd;
  border-top: 1px solid var(--garis-lemah);
}

.rincian {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 22px;
  padding: 14px 18px 18px;
}

.rincian h3 {
  margin: 0 0 7px;
  font-size: 12px;
  font-weight: 650;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: var(--teks-lemah);
}

.rincian h3 + ul {
  margin-bottom: 16px;
}

.daftar-nilai,
.daftar-bayar {
  display: grid;
  gap: 4px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.daftar-nilai li {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 14px;
  font-size: 12.5px;
  color: var(--teks-lemah);
}

.daftar-nilai li strong {
  color: var(--teks);
  font-variant-numeric: tabular-nums;
}

.daftar-nilai li.total {
  padding-top: 4px;
  border-top: 1px solid var(--garis-lemah);
}

.daftar-bayar li {
  padding: 7px 10px;
  font-size: 12.5px;
  background: var(--permukaan);
  border: 1px solid var(--garis-lemah);
  border-radius: 8px;
}

.daftar-bayar p {
  margin: 1px 0 0;
}

.sel-denda {
  color: var(--bahaya);
  font-weight: 600;
}

.sel-sisa {
  color: var(--bahaya);
}

.kaki {
  margin-top: 14px;
  text-align: center;
}

@media (max-width: 820px) {
  .ringkas {
    grid-template-columns: 1fr;
  }

  .rincian {
    grid-template-columns: 1fr;
  }
}
</style>
