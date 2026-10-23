<script setup>
/**
 * Aturan kos (T19).
 *
 * Aturan adalah bacaan untuk penghuni, bukan tagihan. Karena itu halaman ini
 * tidak menampilkan angka uang sama sekali, dan barisnya boleh diubah maupun
 * dihapus - berbeda dari Kebijakan yang append-only.
 *
 * `aktif` dipakai alih-alih hapus untuk aturan yang sudah tidak berlaku.
 * Menghapusnya akan menghilangkan jawaban atas pertanyaan "memang ada aturan
 * itu?" yang sering ditanyakan penghuni lama, dan itu justru pertanyaan yang
 * paling perlu dijawab pemilik.
 */
import { onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { pesanGalat, galatPerBidang } from '../../format.js'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'
import BarisKosong from '../../komponen/ui/BarisKosong.vue'
import MedanForm from '../../komponen/ui/MedanForm.vue'
import LencanaStatus from '../../komponen/ui/LencanaStatus.vue'

const daftar = ref([])
const kategoriTersedia = ref([])
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

function kosong() {
  return { judul: '', isi: '', kategori: 'umum', sanksi: '', aktif: true, urutan: 0 }
}

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const hasil = await sesi.panggil('/aturan')
    daftar.value = hasil.data
    // Daftar kategori datang dari server, bukan ditulis di sini: dua daftar
    // yang tumbuh sendiri-sendiri akan berbeda, dan layar akan menawarkan
    // kategori yang tidak dikenal server.
    kategoriTersedia.value = hasil.meta?.kategori || []
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

function bukaUbah(a) {
  sedangDiubah.value = a
  form.value = {
    judul: a.judul,
    isi: a.isi,
    kategori: a.kategori || 'umum',
    sanksi: a.sanksi || '',
    aktif: a.aktif,
    urutan: a.urutan ?? 0,
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
      judul: form.value.judul.trim(),
      isi: form.value.isi.trim(),
      kategori: form.value.kategori.trim() || 'umum',
      sanksi: form.value.sanksi.trim() || null,
      aktif: form.value.aktif,
      urutan: Number(form.value.urutan) || 0,
    }

    if (sedangDiubah.value) {
      await sesi.panggil(`/aturan/${sedangDiubah.value.id}`, {
        method: 'PUT',
        body: JSON.stringify(isi),
      })
    } else {
      await sesi.panggil('/aturan', {
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

/**
 * Menyalakan atau mematikan satu aturan tanpa membuka formulir.
 *
 * Dikirim sebagai PUT penuh, bukan endpoint tambahan: server hanya punya satu
 * jalan mengubah aturan, sehingga tidak ada dua jalur yang bisa menyimpang.
 */
async function balikAktif(a) {
  galatMuat.value = ''
  try {
    await sesi.panggil(`/aturan/${a.id}`, {
      method: 'PUT',
      body: JSON.stringify({
        judul: a.judul,
        isi: a.isi,
        kategori: a.kategori,
        sanksi: a.sanksi,
        aktif: !a.aktif,
        urutan: a.urutan,
      }),
    })
    await muat()
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  }
}

function mintaHapus(a) {
  konfirmasiHapus.value = a
  galatHapus.value = ''
}

async function hapus() {
  menghapus.value = true
  galatHapus.value = ''
  try {
    await sesi.panggil(`/aturan/${konfirmasiHapus.value.id}`, { method: 'DELETE' })
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
    <KepalaHalaman judul="Aturan kos">
      <template #keterangan>
        {{ daftar.filter((a) => a.aktif).length }} aturan berlaku. Penghuni membacanya di portal
        mereka.
      </template>
      <template #aksi>
        <button v-if="!formTerbuka" @click="bukaTambah">Tambah aturan</button>
      </template>
    </KepalaHalaman>

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>

    <section v-if="formTerbuka" class="kartu panel">
      <h2>{{ sedangDiubah ? 'Ubah aturan' : 'Tambah aturan' }}</h2>
      <form class="bidang" @submit.prevent="simpan">
        <MedanForm id="judul-aturan" label="Judul" :galat="galatBidang.judul">
          <input
            id="judul-aturan"
            v-model="form.judul"
            placeholder="Jam malam"
            :aria-invalid="Boolean(galatBidang.judul)"
            autocomplete="off"
          />
        </MedanForm>

        <MedanForm
          id="isi-aturan"
          label="Isi aturan"
          bantuan="Tulis apa yang harus dipatuhi penghuni, dalam kalimat yang bisa langsung dipahami."
          :galat="galatBidang.isi"
        >
          <textarea
            id="isi-aturan"
            v-model="form.isi"
            rows="4"
            placeholder="Gerbang dikunci pukul 23.00."
            :aria-invalid="Boolean(galatBidang.isi)"
          ></textarea>
        </MedanForm>

        <div class="baris-bidang">
          <MedanForm
            id="kategori-aturan"
            label="Kategori"
            bantuan="Untuk mengelompokkan di portal penghuni."
            :galat="galatBidang.kategori"
          >
            <input
              id="kategori-aturan"
              v-model="form.kategori"
              list="daftar-kategori"
              placeholder="umum"
              :aria-invalid="Boolean(galatBidang.kategori)"
              autocomplete="off"
            />
            <datalist id="daftar-kategori">
              <option v-for="k in kategoriTersedia" :key="k" :value="k" />
            </datalist>
          </MedanForm>

          <MedanForm
            id="sanksi-aturan"
            label="Sanksi"
            bantuan="Boleh dikosongkan bila aturan ini tidak bersanksi."
            :galat="galatBidang.sanksi"
          >
            <input
              id="sanksi-aturan"
              v-model="form.sanksi"
              placeholder="Teguran lisan"
              :aria-invalid="Boolean(galatBidang.sanksi)"
              autocomplete="off"
            />
          </MedanForm>
        </div>

        <div class="baris-bidang">
          <MedanForm
            id="urutan-aturan"
            label="Urutan tampil"
            bantuan="Angka kecil tampil lebih dulu."
            :galat="galatBidang.urutan"
          >
            <input
              id="urutan-aturan"
              v-model="form.urutan"
              type="number"
              min="0"
              placeholder="0"
              :aria-invalid="Boolean(galatBidang.urutan)"
            />
          </MedanForm>

          <div>
            <label for="aktif-aturan">Tampil di portal penghuni</label>
            <label class="pilihan-centang">
              <input id="aktif-aturan" v-model="form.aktif" type="checkbox" />
              <span>Ya, tampilkan</span>
            </label>
          </div>
        </div>

        <PesanBaris v-if="galatForm" jenis="galat">{{ galatForm }}</PesanBaris>

        <div class="aksi">
          <button type="submit" :disabled="menyimpan">
            {{ menyimpan ? 'Menyimpan...' : 'Simpan aturan' }}
          </button>
          <button type="button" class="halus" @click="tutupForm">Batal</button>
        </div>
      </form>
    </section>

    <section v-if="konfirmasiHapus" class="kartu panel">
      <h2>Hapus aturan &ldquo;{{ konfirmasiHapus.judul }}&rdquo;?</h2>
      <p class="mungil">
        Aturan ini hilang dari daftar dan dari portal penghuni. Bila hanya ingin menyembunyikannya,
        matikan sakelar <strong>Tampil di portal</strong> alih-alih menghapus.
      </p>
      <PesanBaris v-if="galatHapus" jenis="galat">{{ galatHapus }}</PesanBaris>
      <div class="aksi">
        <button type="button" :disabled="menghapus" @click="hapus">
          {{ menghapus ? 'Menghapus...' : 'Hapus aturan' }}
        </button>
        <button type="button" class="halus" @click="konfirmasiHapus = null">Batal</button>
      </div>
    </section>

    <section class="kartu">
      <div class="tabel-bungkus">
        <table class="tabel">
          <thead>
            <tr>
              <th>Judul</th>
              <th>Kategori</th>
              <th>Sanksi</th>
              <th>Portal</th>
              <th class="kanan">Urutan</th>
              <th class="kanan">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in daftar" :key="a.id">
              <td>
                <strong>{{ a.judul }}</strong>
                <p class="lemah mungil">{{ a.isi }}</p>
              </td>
              <td class="lemah mungil">{{ a.kategori }}</td>
              <td class="lemah mungil">{{ a.sanksi || '-' }}</td>
              <td>
                <LencanaStatus :status="a.aktif ? 'aktif' : 'nonaktif'" />
              </td>
              <td class="kanan lemah mungil">{{ a.urutan }}</td>
              <td class="kanan aksi-baris">
                <button class="halus mungil" @click="balikAktif(a)">
                  {{ a.aktif ? 'Sembunyikan' : 'Tampilkan' }}
                </button>
                <button class="halus mungil" @click="bukaUbah(a)">Ubah</button>
                <button class="halus mungil" @click="mintaHapus(a)">Hapus</button>
              </td>
            </tr>
            <BarisKosong v-if="!memuat && daftar.length === 0" :kolom="6">
              Belum ada aturan. Penghuni tidak akan melihat bagian aturan di portalnya.
            </BarisKosong>
            <BarisKosong v-if="memuat" :kolom="6">Memuat aturan...</BarisKosong>
          </tbody>
        </table>
      </div>
    </section>

    <PesanBaris jenis="netral" mungil>
      Aturan adalah bacaan, bukan tagihan. Denda dan nominalnya tetap diatur di Kebijakan, dan
      angka itu tidak pernah diambil dari halaman ini.
    </PesanBaris>
  </div>
</template>

<style scoped>
/* Label pembungkus kotak centang ditata mendatar: satu baris "Ya, tampilkan"
   di sebelah kotaknya, bukan label bertumpuk yang terbaca seperti dua medan. */
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

/* Tombol aksi ditahan satu baris. Tanpa ini "Hapus" terlipat ke bawah "Ubah"
   dan barisnya menjadi dua kali lebih tinggi - cacat tata letak yang tidak
   ditangkap asersi mana pun karena DOM-nya benar. */
.aksi-baris {
  white-space: nowrap;
}
</style>
