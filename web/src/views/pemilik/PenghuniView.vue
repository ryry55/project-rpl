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
const form = ref({ nama: '', email: '', no_hp: '', password: '' })

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
  form.value = { nama: '', email: '', no_hp: '', password: '' }
  galatForm.value = ''
  galatBidang.value = {}
  formTerbuka.value = true
}

async function simpan() {
  menyimpan.value = true
  galatForm.value = ''
  galatBidang.value = {}
  try {
    await sesi.panggil('/penghuni', {
      method: 'POST',
      body: JSON.stringify({
        nama: form.value.nama.trim(),
        email: form.value.email.trim(),
        no_hp: form.value.no_hp.trim() || null,
        password: form.value.password,
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
            bantuan="Dipakai penghuni untuk masuk dan melihat tagihannya."
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
          <MedanForm
            id="password"
            label="Kata sandi awal"
            :galat="galatBidang.password"
            bantuan="Sampaikan ke penghuni; bisa diubah nanti."
          >
            <input
              id="password"
              v-model="form.password"
              type="text"
              placeholder="minimal 6 karakter"
              :aria-invalid="Boolean(galatBidang.password)"
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
            </tr>
            <BarisKosong v-if="!memuat && tersaring.length === 0" :kolom="5">
              {{ cari ? 'Tidak ada penghuni yang cocok dengan pencarian.' : 'Belum ada penghuni terdaftar.' }}
            </BarisKosong>
            <BarisKosong v-if="memuat" :kolom="5">Memuat…</BarisKosong>
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
