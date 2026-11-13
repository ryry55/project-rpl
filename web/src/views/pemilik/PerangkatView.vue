<script setup>
/**
 * Kredensial perangkat kos (K-16): WiFi, CCTV, RFID.
 *
 * Halaman ini menjawab satu pertanyaan yang paling sering diulang di kos -
 * "sandi WiFi-nya apa" - tanpa harus mencari chat lama. Sandi disimpan
 * terenkripsi dan **tidak ikut di daftar**: tombol "Lihat sandi" memintanya
 * satu per satu, supaya sandi CCTV tidak melintas di layar setiap kali halaman
 * ini dibuka.
 *
 * `aktif` dipakai alih-alih hapus untuk perangkat yang sudah tidak dipakai.
 * Menghapusnya akan menghilangkan jawaban "sandi WiFi lama yang mana" saat
 * penghuni lama bertanya.
 */
import { onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { pesanGalat, galatPerBidang } from '../../format.js'
import { salinTeks } from '../../bagikan.js'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'
import BarisKosong from '../../komponen/ui/BarisKosong.vue'
import MedanForm from '../../komponen/ui/MedanForm.vue'
import LencanaStatus from '../../komponen/ui/LencanaStatus.vue'

const daftar = ref([])
const jenisTersedia = ref([])
const labelJenis = ref({})
const memuat = ref(true)
const galatMuat = ref('')

const formTerbuka = ref(false)
const menyimpan = ref(false)
const galatForm = ref('')
const galatBidang = ref({})
const sedangDiubah = ref(null)
const form = ref(kosong())

const konfirmasiHapus = ref(null)
const menghapus = ref(false)
const galatHapus = ref('')

// Sandi yang sedang ditampilkan, per id. Disimpan di sini, bukan di `daftar`:
// sandi yang sudah diminta tidak ikut terkirim ulang saat daftar dimuat ulang.
const sandiTerlihat = ref({})
const memuatSandi = ref(null)
const galatSandi = ref('')
const tersalinId = ref(null)

function kosong() {
  return {
    jenis: 'wifi',
    nama: '',
    lokasi: '',
    nama_pengguna: '',
    sandi: '',
    catatan: '',
    aktif: true,
    urutan: 0,
  }
}

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const hasil = await sesi.panggil('/perangkat')
    daftar.value = hasil.data
    // Daftar jenis datang dari server, bukan ditulis di sini: dua daftar yang
    // tumbuh sendiri-sendiri akan berbeda, dan layar akan menawarkan jenis
    // yang ditolak server.
    jenisTersedia.value = hasil.meta?.jenis || []
    labelJenis.value = hasil.meta?.label_jenis || {}
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

function bukaTambah() {
  sedangDiubah.value = null
  form.value = kosong()
  galatForm.value = ''
  galatBidang.value = {}
  formTerbuka.value = true
}

function bukaUbah(p) {
  sedangDiubah.value = p
  form.value = {
    jenis: p.jenis,
    nama: p.nama,
    lokasi: p.lokasi || '',
    nama_pengguna: p.nama_pengguna || '',
    // Kosong berarti "jangan ganti sandi" - lihat `SimpanPerangkatRequest`.
    sandi: '',
    catatan: p.catatan || '',
    aktif: p.aktif,
    urutan: p.urutan ?? 0,
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
    const isi = {
      jenis: form.value.jenis,
      nama: form.value.nama.trim(),
      lokasi: form.value.lokasi.trim() || null,
      nama_pengguna: form.value.nama_pengguna.trim() || null,
      catatan: form.value.catatan.trim() || null,
      aktif: form.value.aktif,
      urutan: Number(form.value.urutan) || 0,
    }

    // Sandi hanya dikirim bila diisi. Mengirim string kosong akan menghapus
    // sandi lama, dan pemilik yang hanya memperbaiki letak perangkat tidak
    // bermaksud melakukannya.
    if (form.value.sandi.trim()) {
      isi.sandi = form.value.sandi.trim()
    }

    if (sedangDiubah.value) {
      await sesi.panggil(`/perangkat/${sedangDiubah.value.id}`, {
        method: 'PUT',
        body: JSON.stringify(isi),
      })
    } else {
      await sesi.panggil('/perangkat', {
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

async function balikAktif(p) {
  galatMuat.value = ''
  try {
    await sesi.panggil(`/perangkat/${p.id}`, {
      method: 'PUT',
      body: JSON.stringify({
        jenis: p.jenis,
        nama: p.nama,
        lokasi: p.lokasi,
        nama_pengguna: p.nama_pengguna,
        catatan: p.catatan,
        aktif: !p.aktif,
        urutan: p.urutan,
      }),
    })
    await muat()
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  }
}

/**
 * Minta sandi satu perangkat, atau sembunyikan yang sedang tampil.
 *
 * Diminta saat tombolnya ditekan, bukan saat halaman dimuat: sandi yang tidak
 * sedang dilihat tidak perlu melintas di jaringan sama sekali.
 */
async function balikSandi(p) {
  galatSandi.value = ''

  if (sandiTerlihat.value[p.id] !== undefined) {
    const salinan = { ...sandiTerlihat.value }
    delete salinan[p.id]
    sandiTerlihat.value = salinan
    return
  }

  memuatSandi.value = p.id
  try {
    const hasil = await sesi.panggil(`/perangkat/${p.id}/sandi`)
    sandiTerlihat.value = { ...sandiTerlihat.value, [p.id]: hasil.data?.sandi || '' }
  } catch (e) {
    galatSandi.value = pesanGalat(e)
  } finally {
    memuatSandi.value = null
  }
}

async function salinSandi(p) {
  const sandi = sandiTerlihat.value[p.id]
  if (!sandi) {
    return
  }

  const berhasil = await salinTeks(sandi)
  if (berhasil) {
    tersalinId.value = p.id
    setTimeout(() => {
      tersalinId.value = null
    }, 2000)
  }
}

function mintaHapus(p) {
  konfirmasiHapus.value = p
  galatHapus.value = ''
}

async function hapus() {
  menghapus.value = true
  galatHapus.value = ''
  try {
    await sesi.panggil(`/perangkat/${konfirmasiHapus.value.id}`, { method: 'DELETE' })
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
    <KepalaHalaman judul="Perangkat &amp; akses">
      <template #keterangan>
        {{ daftar.filter((p) => p.aktif).length }} perangkat aktif. Penghuni hanya melihat sandi
        WiFi; CCTV dan kartu RFID tetap milik pengurus kos.
      </template>
      <template #aksi>
        <button v-if="!formTerbuka && sesi.boleh('kelola_kamar')" @click="bukaTambah">
          Tambah perangkat
        </button>
      </template>
    </KepalaHalaman>

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>
    <PesanBaris v-if="galatSandi" jenis="galat">{{ galatSandi }}</PesanBaris>

    <section v-if="formTerbuka" class="kartu panel">
      <h2>{{ sedangDiubah ? 'Ubah perangkat' : 'Tambah perangkat' }}</h2>
      <form class="bidang" @submit.prevent="simpan">
        <div class="baris-bidang">
          <MedanForm id="jenis-perangkat" label="Jenis" :galat="galatBidang.jenis">
            <select id="jenis-perangkat" v-model="form.jenis">
              <option v-for="j in jenisTersedia" :key="j" :value="j">
                {{ labelJenis[j] || j }}
              </option>
            </select>
          </MedanForm>

          <MedanForm
            id="nama-perangkat"
            label="Nama"
            bantuan="Nama yang dikenali penghuni, mis. &ldquo;WiFi Lantai 1&rdquo;."
            :galat="galatBidang.nama"
          >
            <input
              id="nama-perangkat"
              v-model="form.nama"
              placeholder="WiFi Lantai 1"
              :aria-invalid="Boolean(galatBidang.nama)"
              autocomplete="off"
            />
          </MedanForm>
        </div>

        <div class="baris-bidang">
          <MedanForm
            id="lokasi-perangkat"
            label="Letak"
            bantuan="Boleh dikosongkan."
            :galat="galatBidang.lokasi"
          >
            <input
              id="lokasi-perangkat"
              v-model="form.lokasi"
              placeholder="Dekat tangga"
              :aria-invalid="Boolean(galatBidang.lokasi)"
              autocomplete="off"
            />
          </MedanForm>

          <MedanForm
            id="pengguna-perangkat"
            label="SSID / nama pengguna"
            bantuan="Nama jaringan WiFi, atau nama pengguna perangkat."
            :galat="galatBidang.nama_pengguna"
          >
            <input
              id="pengguna-perangkat"
              v-model="form.nama_pengguna"
              placeholder="KOS-LANTAI-1"
              :aria-invalid="Boolean(galatBidang.nama_pengguna)"
              autocomplete="off"
            />
          </MedanForm>
        </div>

        <MedanForm
          id="sandi-perangkat"
          label="Sandi"
          :bantuan="
            sedangDiubah
              ? 'Kosongkan bila sandinya tidak diganti.'
              : 'Boleh dikosongkan bila perangkat tidak punya sandi.'
          "
          :galat="galatBidang.sandi"
        >
          <input
            id="sandi-perangkat"
            v-model="form.sandi"
            type="text"
            autocomplete="new-password"
            :placeholder="sedangDiubah ? 'Tidak diganti' : 'Sandi perangkat'"
            :aria-invalid="Boolean(galatBidang.sandi)"
          />
        </MedanForm>

        <MedanForm
          id="catatan-perangkat"
          label="Catatan"
          bantuan="Petunjuk pemakaian, mis. &ldquo;jangan dipakai untuk printer&rdquo;."
          :galat="galatBidang.catatan"
        >
          <input
            id="catatan-perangkat"
            v-model="form.catatan"
            placeholder="SSID 5 GHz"
            :aria-invalid="Boolean(galatBidang.catatan)"
            autocomplete="off"
          />
        </MedanForm>

        <div class="baris-bidang">
          <MedanForm
            id="urutan-perangkat"
            label="Urutan tampil"
            bantuan="Angka kecil tampil lebih dulu."
            :galat="galatBidang.urutan"
          >
            <input
              id="urutan-perangkat"
              v-model="form.urutan"
              type="number"
              min="0"
              placeholder="0"
              :aria-invalid="Boolean(galatBidang.urutan)"
            />
          </MedanForm>

          <div>
            <label for="aktif-perangkat">Tampil di portal penghuni</label>
            <label class="pilihan-centang">
              <input id="aktif-perangkat" v-model="form.aktif" type="checkbox" />
              <span>Ya, tampilkan</span>
            </label>
          </div>
        </div>

        <PesanBaris v-if="galatForm" jenis="galat">{{ galatForm }}</PesanBaris>

        <div class="aksi">
          <button type="submit" :disabled="menyimpan">
            {{ menyimpan ? 'Menyimpan...' : 'Simpan perangkat' }}
          </button>
          <button type="button" class="halus" @click="tutupForm">Batal</button>
        </div>
      </form>
    </section>

    <section v-if="konfirmasiHapus" class="kartu panel">
      <h2>Hapus perangkat &ldquo;{{ konfirmasiHapus.nama }}&rdquo;?</h2>
      <p class="mungil">
        Perangkat ini hilang dari daftar dan dari portal penghuni. Bila hanya ingin
        menyembunyikannya, matikan sakelar <strong>Tampil di portal</strong> alih-alih menghapus.
      </p>
      <PesanBaris v-if="galatHapus" jenis="galat">{{ galatHapus }}</PesanBaris>
      <div class="aksi">
        <button type="button" :disabled="menghapus" @click="hapus">
          {{ menghapus ? 'Menghapus...' : 'Hapus perangkat' }}
        </button>
        <button type="button" class="halus" @click="konfirmasiHapus = null">Batal</button>
      </div>
    </section>

    <section class="kartu">
      <div class="tabel-bungkus">
        <table class="tabel" v-kartu-tabel>
          <thead>
            <tr>
              <th>Nama</th>
              <th>Jenis</th>
              <th>SSID / pengguna</th>
              <th>Sandi</th>
              <th>Portal</th>
              <th class="kanan">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in daftar" :key="p.id">
              <td>
                <strong>{{ p.nama }}</strong>
                <p v-if="p.lokasi" class="lemah mungil">{{ p.lokasi }}</p>
                <p v-if="p.catatan" class="lemah mungil">{{ p.catatan }}</p>
              </td>
              <td class="lemah mungil">{{ p.label_jenis }}</td>
              <td class="lemah mungil">{{ p.nama_pengguna || '-' }}</td>
              <td>
                <template v-if="p.ada_sandi">
                  <code v-if="sandiTerlihat[p.id] !== undefined" class="sandi-tampil">
                    {{ sandiTerlihat[p.id] || '(kosong)' }}
                  </code>
                  <span v-else class="lemah mungil">••••••••</span>
                  <div class="aksi-sandi">
                    <button
                      type="button"
                      class="halus mungil"
                      :disabled="memuatSandi === p.id"
                      @click="balikSandi(p)"
                    >
                      {{
                        memuatSandi === p.id
                          ? 'Memuat...'
                          : sandiTerlihat[p.id] !== undefined
                            ? 'Sembunyikan'
                            : 'Lihat sandi'
                      }}
                    </button>
                    <button
                      v-if="sandiTerlihat[p.id] !== undefined"
                      type="button"
                      class="halus mungil"
                      @click="salinSandi(p)"
                    >
                      {{ tersalinId === p.id ? 'Tersalin' : 'Salin' }}
                    </button>
                  </div>
                </template>
                <span v-else class="lemah mungil">Tidak ada</span>
              </td>
              <td>
                <LencanaStatus :status="p.aktif ? 'aktif' : 'nonaktif'" />
              </td>
              <td class="kanan aksi-baris">
                <template v-if="sesi.boleh('kelola_kamar')">
                  <button class="halus mungil" @click="balikAktif(p)">
                    {{ p.aktif ? 'Sembunyikan' : 'Tampilkan' }}
                  </button>
                  <button class="halus mungil" @click="bukaUbah(p)">Ubah</button>
                  <button class="halus mungil" @click="mintaHapus(p)">Hapus</button>
                </template>
                <span v-else class="lemah mungil">Hanya lihat</span>
              </td>
            </tr>
            <BarisKosong v-if="!memuat && !galatMuat && daftar.length === 0" :kolom="6">
              Belum ada perangkat. Penghuni tidak akan melihat sandi WiFi di portalnya.
            </BarisKosong>
            <BarisKosong v-if="memuat" :kolom="6">Memuat perangkat...</BarisKosong>
          </tbody>
        </table>
      </div>
    </section>

    <PesanBaris jenis="netral" mungil>
      Sandi disimpan terenkripsi dan hanya dikirim saat tombol <strong>Lihat sandi</strong> ditekan.
    </PesanBaris>
  </div>
</template>

<style scoped>
.pilihan-centang {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-weight: 400;
  cursor: pointer;
}

.pilihan-centang input {
  width: auto;
  margin: 0;
}

.aksi-baris {
  white-space: nowrap;
}

/* Sandi ditampilkan dalam huruf monospace supaya "l" dan "1" bisa dibedakan
   saat dibacakan lewat telepon. */
.sandi-tampil {
  display: inline-block;
  padding: 1px 6px;
  font-size: 12.5px;
  background: var(--aktif);
  border-radius: 4px;
  word-break: break-all;
}

.aksi-sandi {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 4px;
}
</style>
