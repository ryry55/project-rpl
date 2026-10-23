<script setup>
/**
 * Jejak audit (T9, R-07).
 *
 * Halaman ini menjawab satu pertanyaan: **siapa mengubah apa, kapan**. Karena
 * itu urutannya waktu turun, aktornya ditampilkan sebagai nama beku (bukan id),
 * dan nilai lama/baru ditampilkan berdampingan — bukan hanya nilai barunya.
 *
 * Nilai lama sengaja tidak disembunyikan di balik tombol: tanpa nilai lama,
 * baris "status menjadi sebagian" tidak bisa menjelaskan dari mana asalnya, dan
 * log yang tidak bisa menjelaskan asal-usul tidak bisa dipakai untuk
 * mempertanggungjawabkan apa pun.
 */
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { pesanGalat } from '../../format.js'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'
import BarisKosong from '../../komponen/ui/BarisKosong.vue'

const daftar = ref([])
const meta = ref({ jumlah: 0, batas: 200, dipotong: false })
const memuat = ref(true)
const galatMuat = ref('')

// Saringan. `entitas_id` hanya masuk akal bila `entitas` dipilih, jadi bidang
// itu dinonaktifkan saat entitas kosong — bukan dibiarkan tampak berfungsi.
const saring = ref({ entitas: '', entitas_id: '', aksi: '' })

const ENTITAS = [
  { nilai: '', label: 'Semua entitas' },
  { nilai: 'tagihan', label: 'Tagihan' },
  { nilai: 'tarif', label: 'Tarif' },
  { nilai: 'parameter_denda', label: 'Parameter denda' },
]

const AKSI = [
  { nilai: '', label: 'Semua aksi' },
  { nilai: 'tagihan.terbit', label: 'Tagihan diterbitkan' },
  { nilai: 'tagihan.bayar', label: 'Pembayaran dicatat' },
  { nilai: 'tarif.tambah', label: 'Tarif ditambahkan' },
  { nilai: 'parameter_denda.tambah', label: 'Parameter denda ditambahkan' },
]

/** Nama aksi yang bisa dibaca, tanpa titik teknisnya. */
const labelAksi = {
  'tagihan.terbit': 'Tagihan diterbitkan',
  'tagihan.bayar': 'Pembayaran dicatat',
  'tarif.tambah': 'Tarif ditambahkan',
  'parameter_denda.tambah': 'Parameter denda ditambahkan',
}

const jumlahSaring = computed(() => {
  const s = saring.value
  return [s.entitas, s.entitas_id, s.aksi].filter(Boolean).length
})

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const q = new URLSearchParams()
    if (saring.value.entitas) q.set('entitas', saring.value.entitas)
    if (saring.value.entitas_id) q.set('entitas_id', saring.value.entitas_id)
    if (saring.value.aksi) q.set('aksi', saring.value.aksi)

    const hasil = await sesi.panggil(`/audit-log${q.toString() ? '?' + q : ''}`)
    daftar.value = hasil.data || []
    meta.value = hasil.meta || meta.value
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

function resetSaring() {
  saring.value = { entitas: '', entitas_id: '', aksi: '' }
  muat()
}

/** Waktu lengkap, karena jejak audit dibaca menurut urutan kejadian. */
function waktuLengkap(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return new Intl.DateTimeFormat('id-ID', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).format(d)
}

/**
 * Ringkasan perubahan sebagai daftar pendek.
 *
 * Ditampilkan sebagai "kunci: lama → baru", bukan sebagai JSON mentah: yang
 * dicari pembaca adalah perubahan mana yang terjadi, dan JSON membuatnya harus
 * dibaca satu per satu.
 */
function perubahan(log) {
  const lama = log.nilai_lama || {}
  const baru = log.nilai_baru || {}
  const kunci = [...new Set([...Object.keys(lama), ...Object.keys(baru)])]

  return kunci
    .filter((k) => k !== 'pembayaran_id')
    .map((k) => {
      const a = lama[k]
      const b = baru[k]
      return {
        kunci: k,
        lama: a === undefined ? null : a,
        baru: b === undefined ? null : b,
        // Baris yang dibuat dari nol tidak punya nilai lama. Menampilkannya
        // sebagai "— → 1.500.000" menyesatkan: itu terbaca seperti perubahan
        // dari kosong, padahal tidak ada yang berubah — barisnya memang baru.
        baruSaja: a === undefined && b !== undefined,
        berubah: a !== undefined && JSON.stringify(a) !== JSON.stringify(b),
      }
    })
}

function tampilNilai(v) {
  if (v === null || v === undefined) return '—'
  if (typeof v === 'number') return new Intl.NumberFormat('id-ID').format(v)
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}

onMounted(muat)
</script>

<template>
  <div class="halaman">
    <KepalaHalaman judul="Jejak audit">
      <template #keterangan>
        {{ daftar.length }} kejadian terakhir · tidak bisa diubah maupun dihapus
        <template v-if="meta.dipotong"> · menampilkan {{ meta.batas }} terbaru</template>
      </template>
    </KepalaHalaman>

    <section class="kartu saring">
      <div class="bidang-saring">
        <div>
          <label for="s-entitas">Entitas</label>
          <select id="s-entitas" v-model="saring.entitas" @change="muat">
            <option v-for="e in ENTITAS" :key="e.nilai" :value="e.nilai">{{ e.label }}</option>
          </select>
        </div>
        <div>
          <label for="s-id">Id entitas</label>
          <input
            id="s-id"
            v-model="saring.entitas_id"
            type="number"
            min="1"
            :disabled="!saring.entitas"
            placeholder="mis. 12"
            @keyup.enter="muat"
          />
          <p v-if="!saring.entitas" class="bantuan">Pilih entitas dulu.</p>
        </div>
        <div>
          <label for="s-aksi">Aksi</label>
          <select id="s-aksi" v-model="saring.aksi" @change="muat">
            <option v-for="a in AKSI" :key="a.nilai" :value="a.nilai">{{ a.label }}</option>
          </select>
        </div>
        <div class="aksi-saring">
          <button class="halus" :disabled="jumlahSaring === 0" @click="resetSaring">
            Bersihkan
          </button>
        </div>
      </div>
    </section>

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>

    <section class="kartu">
      <div class="tabel-bungkus">
        <table class="tabel tabel-audit">
          <thead>
            <tr>
              <th>Waktu</th>
              <th>Aktor</th>
              <th>Aksi</th>
              <th>Entitas</th>
              <th>Perubahan</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="log in daftar" :key="log.id">
              <td class="mungil sekarang">{{ waktuLengkap(log.waktu) }}</td>
              <td>
                <strong class="mungil">{{ log.aktor_nama || '—' }}</strong>
                <p v-if="!log.aktor_nama" class="mungil lemah">tanpa aktor</p>
              </td>
              <td>
                <span class="lencana-aksi">{{ labelAksi[log.aksi] || log.aksi }}</span>
              </td>
              <td class="mungil">
                {{ log.entitas }}<span v-if="log.entitas_id"> #{{ log.entitas_id }}</span>
              </td>
              <td>
                <ul class="daftar-ubah">
                  <li v-for="u in perubahan(log)" :key="u.kunci" :class="{ berubah: u.berubah }">
                    <span class="kunci">{{ u.kunci }}</span>
                    <template v-if="u.berubah">
                      <span class="nilai-lama">{{ tampilNilai(u.lama) }}</span>
                      <span class="panah">→</span>
                      <span class="nilai-baru">{{ tampilNilai(u.baru) }}</span>
                    </template>
                    <template v-else-if="u.baruSaja">
                      <span class="nilai-baru">{{ tampilNilai(u.baru) }}</span>
                    </template>
                    <template v-else>
                      <span class="nilai-sama">{{ tampilNilai(u.baru) }}</span>
                    </template>
                  </li>
                </ul>
              </td>
            </tr>
            <BarisKosong v-if="!memuat && daftar.length === 0" :kolom="5">
              {{ jumlahSaring > 0 ? 'Tidak ada kejadian yang cocok dengan saringan.' : 'Belum ada kejadian tercatat.' }}
            </BarisKosong>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.saring {
  padding: 14px 16px;
  margin-bottom: 14px;
}

.bidang-saring {
  display: grid;
  grid-template-columns: 1fr 140px 1fr auto;
  gap: 12px;
  align-items: start;
}

.aksi-saring {
  padding-top: 21px;
}

.bantuan {
  margin: 5px 0 0;
  font-size: 11.5px;
  color: var(--teks-lemah);
}

.tabel-audit td {
  vertical-align: top;
}

.sekarang {
  white-space: nowrap;
  color: var(--teks-lemah);
}

.lencana-aksi {
  display: inline-block;
  padding: 2px 8px;
  font-size: 11.5px;
  font-weight: 560;
  white-space: nowrap;
  color: var(--aksen);
  background: var(--aksen-lemah);
  border-radius: 6px;
}

.daftar-ubah {
  display: grid;
  gap: 2px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.daftar-ubah li {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  align-items: baseline;
  font-size: 12px;
  color: var(--teks-lemah);
}

.kunci {
  min-width: 92px;
  font-weight: 560;
  color: var(--teks);
}

.daftar-ubah li.berubah .nilai-baru {
  font-weight: 620;
}

.nilai-lama {
  text-decoration: line-through;
  opacity: 0.75;
}

.panah {
  opacity: 0.6;
}

@media (max-width: 860px) {
  .bidang-saring {
    grid-template-columns: 1fr;
  }

  .aksi-saring {
    padding-top: 0;
  }
}
</style>
