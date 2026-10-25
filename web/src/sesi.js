import { ref, computed } from 'vue'

const KUNCI_TOKEN = 'kos.token'

const token = ref(localStorage.getItem(KUNCI_TOKEN) || '')
const user = ref(null)
const sedangMemuat = ref(false)

/**
 * Dua gerbang akun (T27, T28), dilaporkan server lewat `meta` pada `GET /me`.
 *
 * Disimpan di sini, bukan di komponen, karena router perlu membacanya pada
 * setiap perpindahan halaman - dan router tidak punya akses ke state komponen.
 *
 * `wajibGantiSandi` **menahan** seluruh aplikasi: server menjawab 403 untuk
 * setiap endpoint lain, jadi layar yang membiarkannya lewat hanya akan
 * menampilkan deretan galat. `perluPersetujuanPdp` **tidak** menahan apa pun:
 * persetujuan adalah pilihan yang harus bisa ditolak, dan menahannya membuat
 * kotak centang berubah menjadi syarat memakai aplikasi.
 */
const wajibGantiSandi = ref(false)
const perluPersetujuanPdp = ref(false)
const versiPdp = ref('')

const apiDasar = import.meta.env.VITE_API_BASE || '/api'

async function panggil(path, opsi = {}) {
  // `tanpaTipeIsi` dipakai untuk unggahan berkas (gambar QRIS). Saat badan
  // permintaan berupa `FormData`, peramban HARUS memasang sendiri
  // `Content-Type` beserta `boundary`-nya; menyetelnya manual membuat boundary
  // hilang dan seluruh badan tidak terbaca di server.
  const { tanpaTipeIsi, ...sisa } = opsi

  const headers = {
    Accept: 'application/json',
    ...(opsi.body && !tanpaTipeIsi ? { 'Content-Type': 'application/json' } : {}),
    ...(token.value ? { Authorization: `Bearer ${token.value}` } : {}),
    ...(opsi.headers || {}),
  }

  const res = await fetch(`${apiDasar}${path}`, { ...sisa, headers })

  let isi = null
  const teks = await res.text()
  if (teks) {
    try {
      isi = JSON.parse(teks)
    } catch {
      isi = { message: teks }
    }
  }

  if (!res.ok) {
    const galat = new Error(isi?.message || `Permintaan gagal (${res.status})`)
    galat.status = res.status
    galat.errors = isi?.errors || {}
    // Badan respons ikut dibawa. Beberapa penolakan bukan kegagalan melainkan
    // jawaban yang berguna — 409 pada terbit massal menyertakan rincian kontrak
    // mana yang dilewati dan mengapa. Membuangnya memaksa layar menampilkan
    // "permintaan gagal" padahal server sudah menjelaskan.
    galat.body = isi
    throw galat
  }

  return isi
}

async function masuk(email, password) {
  sedangMemuat.value = true
  try {
    const hasil = await panggil('/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    })
    token.value = hasil.data.token
    user.value = hasil.data.user
    localStorage.setItem(KUNCI_TOKEN, token.value)

    // Login tidak membawa `meta` gerbang - hanya `GET /me` yang membawanya.
    // Diambil di sini supaya pengguna yang wajib mengganti sandi langsung
    // diarahkan, bukan menunggu 403 pertama dari layar berikutnya.
    await muatSaya()

    return user.value
  } finally {
    sedangMemuat.value = false
  }
}

async function muatSaya() {
  if (!token.value) {
    return null
  }
  try {
    const hasil = await panggil('/me')
    user.value = hasil.data
    wajibGantiSandi.value = hasil.meta?.wajib_ganti_sandi === true
    perluPersetujuanPdp.value = hasil.meta?.perlu_persetujuan_pdp === true
    versiPdp.value = hasil.meta?.versi_pdp || ''
    return user.value
  } catch (e) {
    // Token kedaluwarsa atau dicabut — bersihkan supaya tidak mencoba terus.
    if (e.status === 401) {
      keluarLokal()
    }
    return null
  }
}

async function keluar() {
  try {
    await panggil('/logout', { method: 'POST' })
  } catch {
    // Mengabaikan kegagalan logout di server; sesi lokal tetap dibersihkan.
  }
  keluarLokal()
}

function keluarLokal() {
  token.value = ''
  user.value = null
  wajibGantiSandi.value = false
  perluPersetujuanPdp.value = false
  versiPdp.value = ''
  localStorage.removeItem(KUNCI_TOKEN)
}

/**
 * Apakah pengguna yang sedang masuk punya wewenang tertentu.
 *
 * `wewenang` dikirim server untuk semua peran - pemilik menerima seluruh
 * daftar, penghuni menerima daftar kosong - sehingga layar cukup bertanya
 * "apakah `ubah_tarif` ada di sini" tanpa cabang peran. Cabang "kalau pemilik
 * semua boleh" adalah cabang yang mudah terlupa di satu tempat dan membuat
 * tombol pemilik menghilang tanpa sebab.
 *
 * Ini hanya untuk menyembunyikan tombol yang pasti ditolak. Server tetap
 * memeriksa setiap permintaan lewat middleware `wewenang:`; menyembunyikan
 * tombol bukan pengamanan, dan memperlakukannya sebagai pengamanan adalah cara
 * paling umum membuat aplikasi yang tampak aman tetapi tidak.
 */
function boleh(wewenang) {
  return Boolean(user.value?.wewenang?.includes(wewenang))
}

export const sesi = {
  token,
  user,
  sedangMemuat,
  wajibGantiSandi,
  perluPersetujuanPdp,
  versiPdp,
  apiDasar,
  panggil,
  masuk,
  muatSaya,
  keluar,
  keluarLokal,
  boleh,
  sudahMasuk: computed(() => Boolean(user.value)),
}
