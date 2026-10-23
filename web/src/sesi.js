import { ref, computed } from 'vue'

const KUNCI_TOKEN = 'kos.token'

const token = ref(localStorage.getItem(KUNCI_TOKEN) || '')
const user = ref(null)
const sedangMemuat = ref(false)

const apiDasar = import.meta.env.VITE_API_BASE || '/api'

async function panggil(path, opsi = {}) {
  const headers = {
    Accept: 'application/json',
    ...(opsi.body ? { 'Content-Type': 'application/json' } : {}),
    ...(token.value ? { Authorization: `Bearer ${token.value}` } : {}),
    ...(opsi.headers || {}),
  }

  const res = await fetch(`${apiDasar}${path}`, { ...opsi, headers })

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
  apiDasar,
  panggil,
  masuk,
  muatSaya,
  keluar,
  keluarLokal,
  boleh,
  sudahMasuk: computed(() => Boolean(user.value)),
}
