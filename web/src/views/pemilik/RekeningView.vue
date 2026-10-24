<script setup>
/**
 * Halaman Rekening — ke mana penghuni mengirim uangnya (REV-UI-05).
 *
 * Sebelum ini portal penghuni menyebut angka yang harus dibayar tanpa menyebut
 * tujuannya. Penghuni lalu bertanya lewat WhatsApp, dan pemilik mengetik ulang
 * nomor rekeningnya untuk tiap orang - pekerjaan berulang yang rawan salah
 * ketik.
 *
 * Halaman ini hanya untuk pemilik. Nomor rekening adalah **alamat uang masuk**:
 * pengelola yang boleh mengubahnya bisa mengarahkan seluruh setoran penghuni ke
 * rekeningnya sendiri, lalu menandai tagihannya lunas dengan wewenang
 * `catat_pembayaran` yang memang ia punya. Tidak ada pemeriksaan lain yang
 * menangkapnya, jadi gerbangnya harus di sini.
 *
 * Rekening tidak append-only, berbeda dari tarif dan kebijakan: tidak ada angka
 * tagihan yang dihitung darinya. Yang dijaga adalah jejaknya - setiap tambah,
 * ubah, dan hapus menulis log audit, dan itulah yang menjawab "dulu saya
 * transfer ke mana".
 */
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { pesanGalat, galatPerBidang } from '../../format.js'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PanelKartu from '../../komponen/ui/PanelKartu.vue'
import MedanForm from '../../komponen/ui/MedanForm.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'
import BarisKosong from '../../komponen/ui/BarisKosong.vue'

const daftar = ref([])
const meta = ref({ jenis: [], label_jenis: {} })
const memuat = ref(true)
const galatMuat = ref('')

const sukses = ref('')
const galatSimpan = ref('')
const galatBidang = ref({})
const menyimpan = ref(false)

/**
 * Rekening yang sedang disunting. `null` = formulir tambah.
 *
 * Satu formulir dipakai untuk tambah dan ubah, bukan dua. Medan yang diisi
 * sama persis, dan dua formulir berarti dua tempat yang harus diubah setiap
 * kali ada medan baru.
 */
const disunting = ref(null)

const formulir = ref({
  jenis: 'transfer',
  nama: '',
  nomor: '',
  atas_nama: '',
  catatan: '',
  aktif: true,
})

const judulFormulir = computed(() =>
  disunting.value ? `Ubah rekening ${disunting.value.nama}` : 'Tambah rekening',
)

const pilihanJenis = computed(() => meta.value?.label_jenis || {})

function kosongkan() {
  disunting.value = null
  formulir.value = {
    jenis: 'transfer',
    nama: '',
    nomor: '',
    atas_nama: '',
    catatan: '',
    aktif: true,
  }
  galatSimpan.value = ''
  galatBidang.value = {}
}

function sunting(r) {
  disunting.value = r
  formulir.value = {
    jenis: r.jenis,
    nama: r.nama,
    nomor: r.nomor || '',
    atas_nama: r.atas_nama || '',
    catatan: r.catatan || '',
    aktif: r.aktif,
  }
  galatSimpan.value = ''
  galatBidang.value = {}
  sukses.value = ''
}

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const hasil = await sesi.panggil('/rekening')
    daftar.value = hasil.data || []
    meta.value = hasil.meta || meta.value
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

async function simpan() {
  menyimpan.value = true
  galatSimpan.value = ''
  galatBidang.value = {}
  sukses.value = ''

  try {
    const isi = {
      jenis: formulir.value.jenis,
      nama: formulir.value.nama,
      nomor: formulir.value.nomor || null,
      atas_nama: formulir.value.atas_nama || null,
      catatan: formulir.value.catatan || null,
      aktif: formulir.value.aktif,
    }

    if (disunting.value) {
      await sesi.panggil(`/rekening/${disunting.value.id}`, {
        method: 'PUT',
        body: JSON.stringify(isi),
      })
      sukses.value = 'Rekening diperbarui. Portal penghuni langsung memakai nilai ini.'
    } else {
      await sesi.panggil('/rekening', { method: 'POST', body: JSON.stringify(isi) })
      sukses.value = 'Rekening ditambahkan dan langsung tampil di portal penghuni.'
    }

    kosongkan()
    await muat()
  } catch (e) {
    galatSimpan.value = pesanGalat(e)
    galatBidang.value = galatPerBidang(e)
  } finally {
    menyimpan.value = false
  }
}

async function hapus(r) {
  // `confirm()` dipakai dengan sengaja: menghapus rekening adalah tindakan
  // yang menghilangkan sesuatu dari layar penghuni, dan tidak ada tombol urung
  // setelah terlanjur. Konfirmasi bawaan peramban cukup untuk itu, dan modal
  // sendiri akan menambah kode tanpa menambah jaminan.
  if (!window.confirm(`Hapus rekening ${r.nama}${r.nomor ? ' ' + r.nomor : ''}?`)) {
    return
  }

  galatSimpan.value = ''
  sukses.value = ''

  try {
    await sesi.panggil(`/rekening/${r.id}`, { method: 'DELETE' })
    sukses.value = 'Rekening dihapus. Salinannya tetap tersimpan di jejak audit.'
    if (disunting.value?.id === r.id) {
      kosongkan()
    }
    await muat()
  } catch (e) {
    galatSimpan.value = pesanGalat(e)
  }
}

/**
 * Aktifkan atau nonaktifkan satu rekening tanpa membuka formulir.
 *
 * Rekening yang tidak dipakai lagi cukup dinonaktifkan, bukan dihapus: barisnya
 * tetap ada untuk menjawab "dulu saya transfer ke mana", dan bisa diaktifkan
 * kembali tanpa dibuat ulang.
 */
async function balikAktif(r) {
  galatSimpan.value = ''
  sukses.value = ''

  try {
    await sesi.panggil(`/rekening/${r.id}`, {
      method: 'PUT',
      body: JSON.stringify({
        nama: r.nama,
        jenis: r.jenis,
        nomor: r.nomor,
        atas_nama: r.atas_nama,
        catatan: r.catatan,
        aktif: !r.aktif,
      }),
    })
    sukses.value = r.aktif
      ? 'Rekening dinonaktifkan: tidak lagi tampil di portal penghuni.'
      : 'Rekening diaktifkan kembali dan tampil di portal penghuni.'
    await muat()
  } catch (e) {
    galatSimpan.value = pesanGalat(e)
  }
}

onMounted(muat)
</script>

<template>
  <div class="halaman">
    <KepalaHalaman judul="Rekening tujuan pembayaran">
      <template #keterangan>
        Ke mana penghuni mengirim uangnya. Hanya yang aktif yang tampil di portal penghuni.
      </template>
    </KepalaHalaman>

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>
    <PesanBaris v-if="sukses" jenis="sukses">{{ sukses }}</PesanBaris>
    <PesanBaris v-if="galatSimpan && !disunting" jenis="galat">{{ galatSimpan }}</PesanBaris>

    <PanelKartu :judul="judulFormulir">
      <template #keterangan>
        <p class="lemah mungil">
          Nomor rekening adalah alamat uang masuk. Karena itu hanya pemilik yang dapat
          mengubahnya — pengelola yang bisa mengganti nomor bisa mengarahkan setoran
          penghuni ke rekening lain.
        </p>
      </template>

      <form class="bidang" @submit.prevent="simpan">
        <div class="baris-bidang">
          <MedanForm id="rekening-jenis" label="Jenis" :galat="galatBidang.jenis">
            <select id="rekening-jenis" v-model="formulir.jenis">
              <option v-for="(label, kode) in pilihanJenis" :key="kode" :value="kode">
                {{ label }}
              </option>
            </select>
          </MedanForm>

          <MedanForm
            id="rekening-nama"
            label="Bank atau penyedia"
            :galat="galatBidang.nama"
            bantuan="mis. BCA, Mandiri, QRIS BCA."
          >
            <input
              id="rekening-nama"
              v-model="formulir.nama"
              type="text"
              maxlength="60"
              required
              :aria-invalid="Boolean(galatBidang.nama)"
            />
          </MedanForm>
        </div>

        <div class="baris-bidang">
          <MedanForm
            id="rekening-nomor"
            label="Nomor rekening"
            :galat="galatBidang.nomor"
            bantuan="Boleh dikosongkan untuk QRIS yang hanya berupa gambar."
          >
            <template #label-ekstra> <span class="lemah">(opsional)</span></template>
            <input id="rekening-nomor" v-model="formulir.nomor" type="text" maxlength="60" />
          </MedanForm>

          <MedanForm
            id="rekening-atas-nama"
            label="Atas nama"
            :galat="galatBidang.atas_nama"
            bantuan="Penghuni yang melihat nama berbeda dari nama pemilik kos akan curiga."
          >
            <template #label-ekstra> <span class="lemah">(opsional)</span></template>
            <input
              id="rekening-atas-nama"
              v-model="formulir.atas_nama"
              type="text"
              maxlength="80"
            />
          </MedanForm>
        </div>

        <MedanForm
          id="rekening-catatan"
          label="Catatan"
          :galat="galatBidang.catatan"
          bantuan="mis. khusus transfer, jangan ke rekening pribadi."
        >
          <template #label-ekstra> <span class="lemah">(opsional)</span></template>
          <input id="rekening-catatan" v-model="formulir.catatan" type="text" maxlength="200" />
        </MedanForm>

        <label class="centang">
          <input v-model="formulir.aktif" type="checkbox" />
          <span class="mungil">Tampilkan di portal penghuni</span>
        </label>

        <PesanBaris v-if="galatSimpan && disunting" jenis="galat">{{ galatSimpan }}</PesanBaris>

        <div class="aksi">
          <button type="submit" :disabled="menyimpan">
            {{ menyimpan ? 'Menyimpan…' : disunting ? 'Simpan perubahan' : 'Tambah rekening' }}
          </button>
          <button v-if="disunting" type="button" class="halus" @click="kosongkan">
            Batal menyunting
          </button>
        </div>
      </form>
    </PanelKartu>

    <PanelKartu judul="Daftar rekening">
      <template #keterangan>
        <p class="lemah mungil">
          Rekening yang dinonaktifkan tetap tersimpan: pertanyaan "dulu saya transfer ke
          mana" harus tetap bisa dijawab, dan rekening lama bisa diaktifkan lagi tanpa
          dibuat ulang.
        </p>
      </template>

      <div class="tabel-bungkus">
        <table class="tabel" v-kartu-tabel>
          <thead>
            <tr>
              <th>Jenis</th>
              <th>Bank / penyedia</th>
              <th>Nomor</th>
              <th>Atas nama</th>
              <th>Tampil</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in daftar" :key="r.id" :class="{ 'baris-nonaktif': !r.aktif }">
              <td class="mungil">{{ r.jenis_label }}</td>
              <td>
                <strong>{{ r.nama }}</strong>
                <p v-if="r.catatan" class="mungil lemah">{{ r.catatan }}</p>
              </td>
              <td class="mungil">{{ r.nomor || '—' }}</td>
              <td class="mungil">{{ r.atas_nama || '—' }}</td>
              <td>
                <span class="lencana-aktif mungil" :class="r.aktif ? 'aktif' : 'nonaktif'">
                  {{ r.aktif ? 'Aktif' : 'Nonaktif' }}
                </span>
              </td>
              <td class="kanan">
                <button type="button" class="halus mungil" @click="sunting(r)">Ubah</button>
                <button type="button" class="halus mungil" @click="balikAktif(r)">
                  {{ r.aktif ? 'Nonaktifkan' : 'Aktifkan' }}
                </button>
                <button type="button" class="halus mungil" @click="hapus(r)">Hapus</button>
              </td>
            </tr>
            <BarisKosong v-if="!memuat && daftar.length === 0" :kolom="6">
              Belum ada rekening. Tambahkan lewat formulir di atas supaya penghuni tahu ke
              mana harus mentransfer.
            </BarisKosong>
            <BarisKosong v-if="memuat" :kolom="6">Memuat rekening…</BarisKosong>
          </tbody>
        </table>
      </div>
    </PanelKartu>
  </div>
</template>

<style scoped>
.centang {
  display: flex;
  align-items: center;
  gap: 8px;
}

.centang input {
  width: auto;
  margin: 0;
}

/* Rekening nonaktif diredupkan, bukan disembunyikan: ia tetap perlu terbaca
   supaya pemilik tahu nomor mana yang sudah tidak dipakai. */
.baris-nonaktif {
  opacity: 0.62;
}

.lencana-aktif {
  display: inline-block;
  padding: 1px 8px;
  font-weight: 600;
  border-radius: 999px;
}

.lencana-aktif.aktif {
  color: var(--sukses);
  background: var(--sukses-lemah);
}

.lencana-aktif.nonaktif {
  color: var(--teks-lemah);
  background: #eef0f3;
}
</style>
