<script setup>
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { tanggal, pesanGalat, galatPerBidang } from '../../format.js'
import KepalaHalaman from '../../komponen/ui/KepalaHalaman.vue'
import PesanBaris from '../../komponen/ui/PesanBaris.vue'
import MedanForm from '../../komponen/ui/MedanForm.vue'
import BarisKosong from '../../komponen/ui/BarisKosong.vue'

const daftar = ref([])
const memuat = ref(true)
const galatMuat = ref('')

const formTerbuka = ref(false)
const menyimpan = ref(false)
const galatForm = ref('')
const galatBidang = ref({})
const form = ref({ nama: '', email: '', no_hp: '' })

/**
 * Hasil pengiriman tautan aktivasi terakhir.
 *
 * Ditampilkan sebagai baris terpisah, bukan sebagai pesan sukses biasa:
 * "akun dibuat tetapi email gagal" adalah keadaan yang harus terlihat, bukan
 * kalimat yang tenggelam bersama "berhasil disimpan".
 */
const tautanTerkirim = ref(null)
const kirimUlangId = ref(null)

const cari = ref('')
const tersaring = computed(() => {
  const kunci = cari.value.trim().toLowerCase()
  if (!kunci) {
    return daftar.value
  }
  return daftar.value.filter(
    (p) =>
      p.nama.toLowerCase().includes(kunci) ||
      (p.email || '').toLowerCase().includes(kunci) ||
      (p.no_hp || '').includes(kunci),
  )
})

async function muat() {
  memuat.value = true
  galatMuat.value = ''
  try {
    const hasil = await sesi.panggil('/penghuni')
    daftar.value = hasil.data
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    memuat.value = false
  }
}

function bukaForm() {
  form.value = { nama: '', email: '', no_hp: '' }
  galatForm.value = ''
  galatBidang.value = {}
  tautanTerkirim.value = null
  formTerbuka.value = true
}

async function simpan() {
  menyimpan.value = true
  galatForm.value = ''
  galatBidang.value = {}
  try {
    const hasil = await sesi.panggil('/penghuni', {
      method: 'POST',
      body: JSON.stringify({
        nama: form.value.nama.trim(),
        email: form.value.email.trim(),
        no_hp: form.value.no_hp.trim() || null,
      }),
    })
    formTerbuka.value = false
    // T27: pemilik tidak lagi mengetik sandi penghuni. Yang perlu ia tahu
    // adalah apakah email berisi tautan aktivasi benar-benar terkirim - kalau
    // tidak, penghuni tidak akan pernah bisa masuk, dan tanpa pesan ini pemilik
    // mengira akunnya sudah selesai.
    tautanTerkirim.value = {
      email: hasil.meta?.email || form.value.email.trim(),
      terkirim: hasil.meta?.tautan_aktivasi_terkirim === true,
    }
    await muat()
  } catch (e) {
    galatForm.value = pesanGalat(e)
    galatBidang.value = galatPerBidang(e)
  } finally {
    menyimpan.value = false
  }
}

/**
 * Kirim ulang tautan aktivasi untuk penghuni yang emailnya gagal atau salah.
 *
 * Padanan tombol yang sama di layar Pengelola. Dipakai ketika pemilik melihat
 * "email gagal dikirim" dan tidak ingin mengubah alamatnya - mengubah lalu
 * mengembalikan alamat akan memutus sesi dan meninggalkan dua jejak audit
 * untuk satu maksud.
 */
async function kirimUlang(p) {
  kirimUlangId.value = p.id
  galatMuat.value = ''
  try {
    const hasil = await sesi.panggil(`/penghuni/${p.id}/kirim-ulang-aktivasi`, {
      method: 'POST',
    })
    tautanTerkirim.value = {
      email: hasil.meta?.email || p.email,
      terkirim: hasil.meta?.tautan_aktivasi_terkirim === true,
    }
  } catch (e) {
    galatMuat.value = pesanGalat(e)
  } finally {
    kirimUlangId.value = null
  }
}

onMounted(muat)
</script>

<template>
  <div class="halaman">
    <KepalaHalaman judul="Penghuni">
      <template #keterangan>
        {{ daftar.length }} penghuni terdaftar. Setiap penghuni otomatis mendapat akun masuk.
      </template>
      <template #aksi>
        <button v-if="!formTerbuka && sesi.boleh('kelola_penghuni')" @click="bukaForm">
          Tambah penghuni
        </button>
      </template>
    </KepalaHalaman>

    <PesanBaris v-if="galatMuat" jenis="galat">{{ galatMuat }}</PesanBaris>

    <!-- Hasil pengiriman tautan aktivasi. Dua keadaan dibedakan dengan
         sengaja: "terkirim" berarti penghuni bisa mengaktifkan akunnya
         sendiri, "gagal" berarti pemilik harus memperbaiki alamatnya. -->
    <PesanBaris
      v-if="tautanTerkirim"
      :jenis="tautanTerkirim.terkirim ? 'sukses' : 'galat'"
      kelas="pesan-tautan"
    >
      <template v-if="tautanTerkirim.terkirim">
        Tautan aktivasi sudah dikirim ke <strong>{{ tautanTerkirim.email }}</strong>.
        Penghuni menetapkan kata sandinya sendiri lewat tautan itu - Anda tidak perlu
        (dan tidak bisa) mengetikkan kata sandinya.
      </template>
      <template v-else>
        Akun untuk <strong>{{ tautanTerkirim.email }}</strong> sudah dibuat, tetapi email
        tautan aktivasi gagal dikirim. Periksa alamat emailnya lalu simpan ulang data
        penghuni ini supaya tautan baru dikirim.
      </template>
    </PesanBaris>

    <section v-if="formTerbuka" class="kartu panel">
      <h2>Tambah penghuni</h2>
      <form class="bidang" @submit.prevent="simpan">
        <div class="baris-bidang">
          <MedanForm id="nama" label="Nama lengkap" :galat="galatBidang.nama">
            <input
              id="nama"
              v-model="form.nama"
              placeholder="Budi Santoso"
              :aria-invalid="Boolean(galatBidang.nama)"
              autocomplete="off"
            />
          </MedanForm>
          <MedanForm id="no_hp" label="Nomor HP" :galat="galatBidang.no_hp">
            <template #label-ekstra> <span class="lemah">(opsional)</span></template>
            <input id="no_hp" v-model="form.no_hp" placeholder="081200000001" autocomplete="off" />
          </MedanForm>
        </div>

        <div class="baris-bidang">
          <MedanForm
            id="email"
            label="Email"
            :galat="galatBidang.email"
            bantuan="Tautan aktivasi dikirim ke alamat ini. Penghuni menetapkan kata sandinya sendiri."
          >
            <input
              id="email"
              v-model="form.email"
              type="email"
              placeholder="budi@contoh.id"
              :aria-invalid="Boolean(galatBidang.email)"
              autocomplete="off"
            />
          </MedanForm>
        </div>

        <PesanBaris v-if="galatForm" jenis="galat">{{ galatForm }}</PesanBaris>

        <div class="aksi">
          <button type="submit" :disabled="menyimpan">
            {{ menyimpan ? 'Menyimpan…' : 'Simpan penghuni' }}
          </button>
          <button type="button" class="halus" @click="formTerbuka = false">Batal</button>
        </div>
      </form>
    </section>

    <section class="kartu">
      <div class="panel-cari">
        <input v-model="cari" placeholder="Cari nama, email, atau nomor HP" autocomplete="off" />
      </div>
      <div class="tabel-bungkus">
        <table class="tabel">
          <thead>
            <tr>
              <th>Nama</th>
              <th>Email</th>
              <th>Nomor HP</th>
              <th>Kamar aktif</th>
              <th>Sejak</th>
              <th>Akun</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in tersaring" :key="p.id">
              <td><strong>{{ p.nama }}</strong></td>
              <td class="lemah">{{ p.email }}</td>
              <td>{{ p.no_hp || '—' }}</td>
              <td>
                <template v-if="p.kontrak_aktif">
                  Kamar {{ p.kontrak_aktif.kamar?.nomor || '—' }}
                </template>
                <span v-else class="lemah mungil">Belum menempati kamar</span>
              </td>
              <td>{{ p.kontrak_aktif ? tanggal(p.kontrak_aktif.tgl_masuk) : '—' }}</td>
              <td>
                <span v-if="p.sudah_aktif" class="lencana-status status-aktif">aktif</span>
                <template v-else>
                  <span class="lencana-status status-belum_bayar">belum aktif</span>
                  <button
                    v-if="sesi.boleh('kelola_penghuni')"
                    type="button"
                    class="tombol-tautan mungil"
                    :disabled="kirimUlangId === p.id"
                    @click="kirimUlang(p)"
                  >
                    {{ kirimUlangId === p.id ? 'mengirim…' : 'kirim ulang tautan' }}
                  </button>
                </template>
              </td>
            </tr>
            <BarisKosong v-if="!memuat && tersaring.length === 0" :kolom="6">
              {{ cari ? 'Tidak ada penghuni yang cocok dengan pencarian.' : 'Belum ada penghuni terdaftar.' }}
            </BarisKosong>
            <BarisKosong v-if="memuat" :kolom="6">Memuat…</BarisKosong>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
/* Tata letak halaman memakai kelas global. Hanya pencarian yang khas di sini. */
.panel-cari {
  padding: 12px 14px;
  border-bottom: 1px solid var(--garis);
}

.panel-cari input {
  max-width: 320px;
}
</style>
