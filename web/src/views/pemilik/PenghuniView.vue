<script setup>
import { computed, onMounted, ref } from 'vue'
import { sesi } from '../../sesi.js'
import { tanggal, pesanGalat, galatPerBidang } from '../../format.js'

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
    <header class="kepala">
      <div>
        <h1>Penghuni</h1>
        <p class="lemah mungil">
          {{ daftar.length }} penghuni terdaftar. Setiap penghuni otomatis mendapat akun masuk.
        </p>
      </div>
      <button v-if="!formTerbuka" @click="bukaForm">Tambah penghuni</button>
    </header>

    <p v-if="galatMuat" class="pesan-galat">{{ galatMuat }}</p>

    <section v-if="formTerbuka" class="kartu panel">
      <h2>Tambah penghuni</h2>
      <form class="bidang" @submit.prevent="simpan">
        <div class="baris-bidang">
          <div>
            <label for="nama">Nama lengkap</label>
            <input
              id="nama"
              v-model="form.nama"
              placeholder="Budi Santoso"
              :aria-invalid="Boolean(galatBidang.nama)"
              autocomplete="off"
            />
            <p v-if="galatBidang.nama" class="galat-bidang">{{ galatBidang.nama }}</p>
          </div>
          <div>
            <label for="no_hp">Nomor HP <span class="lemah">(opsional)</span></label>
            <input id="no_hp" v-model="form.no_hp" placeholder="081200000001" autocomplete="off" />
            <p v-if="galatBidang.no_hp" class="galat-bidang">{{ galatBidang.no_hp }}</p>
          </div>
        </div>

        <div class="baris-bidang">
          <div>
            <label for="email">Email</label>
            <input
              id="email"
              v-model="form.email"
              type="email"
              placeholder="budi@contoh.id"
              :aria-invalid="Boolean(galatBidang.email)"
              autocomplete="off"
            />
            <p v-if="galatBidang.email" class="galat-bidang">{{ galatBidang.email }}</p>
            <p v-else class="bantuan">Dipakai penghuni untuk masuk dan melihat tagihannya.</p>
          </div>
          <div>
            <label for="password">Kata sandi awal</label>
            <input
              id="password"
              v-model="form.password"
              type="text"
              placeholder="minimal 6 karakter"
              :aria-invalid="Boolean(galatBidang.password)"
              autocomplete="off"
            />
            <p v-if="galatBidang.password" class="galat-bidang">{{ galatBidang.password }}</p>
            <p v-else class="bantuan">Sampaikan ke penghuni; bisa diubah nanti.</p>
          </div>
        </div>

        <p v-if="galatForm" class="pesan-galat">{{ galatForm }}</p>

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
            <tr v-if="!memuat && tersaring.length === 0">
              <td colspan="5" class="sel-kosong">
                {{ cari ? 'Tidak ada penghuni yang cocok dengan pencarian.' : 'Belum ada penghuni terdaftar.' }}
              </td>
            </tr>
            <tr v-if="memuat">
              <td colspan="5" class="sel-kosong">Memuat…</td>
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

.panel-cari {
  padding: 12px 14px;
  border-bottom: 1px solid var(--garis);
}

.panel-cari input {
  max-width: 320px;
}

.aksi {
  display: flex;
  gap: 9px;
}
</style>
