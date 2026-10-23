<script setup>
/**
 * Menetapkan kata sandi dari tautan aktivasi atau reset (T27).
 *
 * Satu layar untuk tiga keadaan, dan itu disengaja:
 *
 *   1. **Aktivasi** - akun baru yang pemiliknya belum pernah menetapkan sandi.
 *   2. **Lupa sandi** - akun aktif yang pemiliknya lupa sandinya.
 *   3. **Wajib ganti sandi** - akun lama yang sandinya pernah diketik pemilik,
 *      dibuka dalam keadaan sudah masuk dan ditahan middleware.
 *
 * Ketiganya berakhir pada satu tindakan yang sama: mengetik sandi baru dua
 * kali. Memisahkannya menjadi tiga layar akan menggandakan validasi, pesan
 * galat, dan penanganan tautan kedaluwarsa - dan yang paling sering salah
 * adalah yang paling jarang dibuka.
 *
 * Yang berbeda hanya **cara memperoleh hak untuk mengganti**: dua keadaan
 * pertama memakai token dari email, yang ketiga memakai sesi yang sudah ada.
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { sesi } from '../sesi.js'

const route = useRoute()
const router = useRouter()

// Dibaca langsung dari alamat, bukan di `onMounted`. `computed` di bawah
// bergantung pada `token`, dan computed pertama kali dievaluasi saat render -
// yaitu SEBELUM `onMounted`. Mengisinya di `onMounted` membuat render pertama
// melihat token kosong, dan pengguna yang sedang masuk lalu membuka tautan
// aktivasi akan melihat layar "ganti sandi" sebentar sebelum berubah.
const token = ref(String(route.query.token || ''))
const jenis = ref(route.query.jenis === 'aktivasi' ? 'aktivasi' : 'reset')

// Keadaan halaman: 'memeriksa' -> 'siap' | 'tautan-mati' | 'selesai'
const keadaan = ref('memeriksa')
const namaAkun = ref('')
const emailAkun = ref('')

const password = ref('')
const passwordUlang = ref('')
const passwordLama = ref('')
const menyimpan = ref(false)
const galat = ref('')
const galatBidang = ref({})

/**
 * Apakah ini penggantian sandi dari dalam sesi, bukan dari tautan email.
 *
 * Dua sumbernya: `?dari=sesi` dari pengalihan middleware, atau tidak ada token
 * sama sekali sementara pengguna sudah masuk. Yang kedua menangani kasus
 * pengguna membuka `/atur-sandi` langsung - dan jawabannya lebih berguna
 * daripada "tautan tidak lengkap".
 */
const dariSesi = computed(() => route.query.dari === 'sesi' || (!token.value && sesi.sudahMasuk.value))

const wajibGanti = computed(() => sesi.wajibGantiSandi.value)

const judul = computed(() => {
  if (dariSesi.value) {
    return wajibGanti.value ? 'Ganti kata sandi dulu' : 'Ganti kata sandi'
  }
  return jenis.value === 'aktivasi' ? 'Aktifkan akun' : 'Atur ulang kata sandi'
})

const keterangan = computed(() => {
  if (dariSesi.value) {
    return wajibGanti.value
      ? 'Kata sandi akun ini pernah diketahui orang lain, jadi harus diganti sebelum aplikasi bisa dipakai.'
      : 'Masukkan kata sandi lama Anda, lalu kata sandi baru.'
  }
  if (jenis.value === 'aktivasi') {
    return 'Tetapkan kata sandi Anda. Setelah tersimpan, akun ini langsung bisa dipakai masuk.'
  }
  return 'Masukkan kata sandi baru untuk akun Anda.'
})

onMounted(async () => {
  if (dariSesi.value) {
    // Sesi yang menahan kita sudah memuat data pengguna; tidak perlu bertanya
    // ke server lagi.
    if (!sesi.user.value) {
      await sesi.muatSaya()
    }
    namaAkun.value = sesi.user.value?.nama || ''
    emailAkun.value = sesi.user.value?.email || ''
    keadaan.value = sesi.user.value ? 'siap' : 'tautan-mati'
    return
  }

  if (!token.value) {
    keadaan.value = 'tautan-mati'
    return
  }

  // Diperiksa SEBELUM pengguna mengetik apa pun. Tanpa langkah ini, tautan
  // yang sudah kedaluwarsa baru ketahuan setelah orangnya mengetik sandi baru
  // dua kali - dan galatnya muncul setelah usaha, bukan sebelum.
  try {
    const hasil = await sesi.panggil('/periksa-token', {
      method: 'POST',
      body: JSON.stringify({ token: token.value, jenis: jenis.value }),
    })

    if (hasil.data?.berlaku) {
      namaAkun.value = hasil.data.nama || ''
      emailAkun.value = hasil.data.email || ''
      keadaan.value = 'siap'
    } else {
      keadaan.value = 'tautan-mati'
    }
  } catch {
    keadaan.value = 'tautan-mati'
  }
})

async function simpan() {
  galat.value = ''
  galatBidang.value = {}

  if (password.value !== passwordUlang.value) {
    galatBidang.value = { password: 'Ulangan kata sandi tidak sama.' }
    return
  }

  if (password.value.length < 8) {
    galatBidang.value = { password: 'Kata sandi minimal 8 karakter.' }
    return
  }

  menyimpan.value = true

  try {
    if (dariSesi.value) {
      const isi = {
        password: password.value,
        password_confirmation: passwordUlang.value,
      }

      // Sandi lama hanya ditanyakan bila akun ini TIDAK sedang ditahan. Akun
      // yang ditahan justru ada di sini karena sandi lamanya sudah diketahui
      // orang lain - menanyakannya mengunci orang yang lupa sandi lamanya,
      // padahal itu alasan ia membuka layar ini.
      if (!wajibGanti.value) {
        isi.password_lama = passwordLama.value
      }

      await sesi.panggil('/ganti-sandi', {
        method: 'POST',
        body: JSON.stringify(isi),
      })

      sesi.wajibGantiSandi.value = false
      keadaan.value = 'selesai'
      return
    }

    await sesi.panggil('/atur-sandi', {
      method: 'POST',
      body: JSON.stringify({
        token: token.value,
        jenis: jenis.value,
        password: password.value,
        password_confirmation: passwordUlang.value,
      }),
    })

    keadaan.value = 'selesai'
  } catch (e) {
    // 410 berarti tautannya sudah tidak berlaku - keadaan yang harus
    // ditampilkan sebagai halaman, bukan sebagai galat di bawah formulir.
    if (e.status === 410) {
      keadaan.value = 'tautan-mati'
      return
    }

    galat.value = e.message
    galatBidang.value = e.errors || {}
  } finally {
    menyimpan.value = false
  }
}

function keMasuk() {
  router.replace({ name: 'masuk' })
}

function lanjut() {
  router.replace({ name: 'beranda' })
}
</script>

<template>
  <div class="halaman-masuk">
    <div class="kartu kotak">
      <header class="kepala-masuk">
        <div class="lencana-masuk">KOS</div>
        <h1>{{ judul }}</h1>
        <p v-if="keadaan !== 'selesai'" class="lemah mungil">{{ keterangan }}</p>
      </header>

      <!-- Keadaan 1: memeriksa tautan -->
      <p v-if="keadaan === 'memeriksa'" class="lemah mungil tengah">Memeriksa tautan…</p>

      <!-- Keadaan 2: tautan tidak berlaku -->
      <template v-else-if="keadaan === 'tautan-mati'">
        <p class="pesan-galat" role="alert">
          Tautan ini sudah tidak berlaku. Tautan hanya bisa dipakai sekali dan berbatas waktu.
        </p>
        <p class="lemah mungil">
          Minta tautan baru lewat <strong>Lupa kata sandi</strong> di halaman masuk. Bila akun ini
          baru dibuat pemilik kos, minta pemilik mengirim ulang tautan aktivasi.
        </p>
        <div class="aksi">
          <button type="button" class="lebar" @click="keMasuk">Ke halaman masuk</button>
        </div>
      </template>

      <!-- Keadaan 3: formulir -->
      <template v-else-if="keadaan === 'siap'">
        <p v-if="emailAkun" class="akun-lemah mungil">
          Akun: <strong>{{ namaAkun || emailAkun }}</strong>
          <span v-if="namaAkun" class="lemah">({{ emailAkun }})</span>
        </p>

        <form @submit.prevent="simpan">
          <div v-if="dariSesi && !wajibGanti" class="bidang">
            <label for="password-lama">Kata sandi lama</label>
            <input
              id="password-lama"
              v-model="passwordLama"
              type="password"
              autocomplete="current-password"
              :aria-invalid="Boolean(galatBidang.password_lama)"
            />
            <p v-if="galatBidang.password_lama" class="galat-bidang">
              {{ galatBidang.password_lama }}
            </p>
          </div>

          <div class="bidang">
            <label for="password-baru">Kata sandi baru</label>
            <input
              id="password-baru"
              v-model="password"
              type="password"
              autocomplete="new-password"
              placeholder="minimal 8 karakter"
              :aria-invalid="Boolean(galatBidang.password)"
            />
            <p v-if="galatBidang.password" class="galat-bidang">{{ galatBidang.password }}</p>
            <p v-else class="bantuan mungil">Minimal 8 karakter.</p>
          </div>

          <div class="bidang">
            <label for="password-ulang">Ulangi kata sandi baru</label>
            <input
              id="password-ulang"
              v-model="passwordUlang"
              type="password"
              autocomplete="new-password"
              placeholder="ulangi kata sandi"
            />
          </div>

          <p v-if="galat" class="pesan-galat" role="alert">{{ galat }}</p>

          <button type="submit" class="lebar" :disabled="menyimpan">
            {{ menyimpan ? 'Menyimpan…' : 'Simpan kata sandi' }}
          </button>
        </form>
      </template>

      <!-- Keadaan 4: selesai -->
      <template v-else>
        <p class="pesan-sukses" role="status">
          Kata sandi tersimpan.
          <template v-if="dariSesi">Anda bisa melanjutkan memakai aplikasi.</template>
          <template v-else>Silakan masuk dengan kata sandi baru Anda.</template>
        </p>

        <div class="aksi">
          <button v-if="dariSesi" type="button" class="lebar" @click="lanjut">Lanjut</button>
          <button v-else type="button" class="lebar" @click="keMasuk">Ke halaman masuk</button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
/* Sama seperti LoginView: satu kotak di tengah, bukan tata letak bersusun. */
.halaman-masuk {
  display: grid;
  place-items: center;
  min-height: 100%;
  padding: 24px;
}

.kotak {
  width: 100%;
  max-width: 420px;
  padding: 28px;
}

.kepala-masuk {
  margin-bottom: 22px;
}

.lencana-masuk {
  display: inline-grid;
  place-items: center;
  width: 38px;
  height: 38px;
  margin-bottom: 14px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  color: var(--aksen);
  background: var(--aksen-lemah);
  border-radius: 9px;
}

.kepala-masuk h1 {
  margin-bottom: 4px;
}

.bidang {
  margin-bottom: 14px;
}

.lebar {
  width: 100%;
  margin-top: 4px;
}

.pesan-galat {
  margin: 0 0 14px;
}

.tengah {
  text-align: center;
  padding: 12px 0;
}

/* Baris akun di atas formulir: orang yang membuka tautan dari kotak masuk
   bersama perlu tahu akun siapa yang sedang diatur - dan bukan akun orang
   lain yang kebetulan memakai peramban yang sama. */
.akun-lemah {
  margin: 0 0 16px;
  padding: 8px 10px;
  background: #f7f8fa;
  border-radius: 8px;
}

.aksi {
  margin-top: 4px;
}
</style>
