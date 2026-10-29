// Perkakas bersama untuk seluruh skrip uji browser di folder ini.
//
// Sebelumnya lima fungsi CDP yang sama disalin ke delapan berkas. Bukan cuma
// soal jumlah baris: dua kesalahan berulang lahir justru di bagian yang
// disalin itu.
//
//   1. `/15\\.000/` - regex ditulis di dalam template literal yang dikirim ke
//      peramban, jadi satu backslash berlebih membuatnya mencari "backslash
//      literal" dan asersinya tidak pernah cocok. Terjadi di T5, T6, dan T9.
//   2. Nama pemilik dari seeder ditulis tetap di skrip, lalu gagal setiap kali
//      data contoh berubah - kegagalan yang tidak mengatakan apa pun tentang
//      aplikasi.
//
// Karena itu `teksMemuat()` memakai pencocokan teks biasa, bukan regex. Kalau
// memang perlu pola, pakai `teksCocok()` dengan regex yang ditulis di berkas
// ini (JavaScript biasa, tanpa escaping ganda).
//
// Pakai:
//
//   import { mulaiUji, masukSebagai, teksMemuat } from './_cdp.mjs'
//   const uji = await mulaiUji()
//   await masukSebagai(uji.ws, 'pemilik@kos.test')
//   uji.lapor('sesuatu benar', teksMemuat(teks, '15.000'))
//   await uji.selesai()

const CDP_BASE = process.env.CDP_BASE || 'http://127.0.0.1:9222'
const APP = process.env.APP_URL || 'http://localhost:3000'
const API = process.env.API_URL || 'http://localhost:8081/api'
const MAILPIT = process.env.MAILPIT_URL || 'http://localhost:8025'

export { CDP_BASE, APP, API, MAILPIT }

/**
 * Mencocokkan teks yang tampil di layar.
 *
 * Spasi dirapatkan lebih dulu karena `textContent` dari tabel menghasilkan
 * "Rp   15.000" dengan jumlah spasi yang berubah-ubah mengikuti tata letak.
 * Pencocokan teks biasa, bukan regex: angka rupiah penuh titik, dan menulis
 * regex untuk itu hanya menambah satu lapis escaping yang bisa salah.
 */
export function teksMemuat(teks, potongan) {
  if (typeof teks !== 'string') return false
  return teks.replace(/\s+/g, ' ').includes(potongan)
}

/** Untuk pola yang memang perlu regex. Regex-nya hidup di berkas ini saja. */
export function teksCocok(teks, pola) {
  if (typeof teks !== 'string') return false
  return pola.test(teks.replace(/\s+/g, ' '))
}

/** Rupiah dengan titik ribuan, mis. 1.500.000 - untuk pesan kegagalan. */
export function rupiah(n) {
  return 'Rp ' + Number(n).toLocaleString('id-ID')
}

async function targetBaru() {
  const res = await fetch(`${CDP_BASE}/json/new?about:blank`, { method: 'PUT' })
  if (!res.ok) throw new Error(`gagal membuat tab: ${res.status}`)
  return res.json()
}

function hubungkan(wsUrl) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(wsUrl)
    ws.onopen = () => resolve(ws)
    ws.onerror = (e) => reject(new Error('websocket gagal: ' + e.message))
  })
}

export { targetBaru, hubungkan }

let idPesan = 0

export function kirim(ws, method, params = {}, sesi = null) {
  return new Promise((resolve, reject) => {
    const id = ++idPesan
    const onMsg = (ev) => {
      let msg
      try {
        msg = JSON.parse(ev.data)
      } catch {
        return
      }
      if (msg.id !== id) return
      ws.removeEventListener('message', onMsg)
      if (msg.error) reject(new Error(`${method}: ${msg.error.message}`))
      else resolve(msg.result)
    }
    ws.addEventListener('message', onMsg)
    // `sesi` dipakai untuk target yang ditumpangi dengan `Target.attachToTarget
    // { flatten: true }` - service worker, misalnya. Tanpa itu, perintah untuk
    // target lain tidak bisa dikirim dari koneksi yang sudah ada.
    ws.send(JSON.stringify(sesi ? { id, method, params, sessionId: sesi } : { id, method, params }))
  })
}

export async function evaluasi(ws, ekspresi) {
  const r = await kirim(ws, 'Runtime.evaluate', {
    expression: ekspresi,
    awaitPromise: true,
    returnByValue: true,
  })
  if (r.exceptionDetails) {
    throw new Error(r.exceptionDetails.exception?.description || 'evaluasi gagal')
  }
  return r.result.value
}

/**
 * Menunggu sampai ekspresi bernilai benar.
 *
 * Galat "dokumen belum siap" TIDAK dilempar keluar, melainkan dianggap belum
 * siap dan dicoba lagi. Alasannya nyata: predikat di sini sering dijalankan
 * tepat setelah `Page.navigate`, ketika dokumen lama sudah hilang dan dokumen
 * baru belum punya `body`. `document.body.textContent` saat itu melempar
 * `Cannot read properties of null (reading 'textContent')`, dan karena
 * `evaluasi` meneruskan galat, seluruh skrip uji berhenti — bukan karena
 * aplikasinya salah, melainkan karena pemeriksanya kalah balapan.
 *
 * Gejalanya menipu: lulus di laptop, gagal di CI, karena runner lebih lambat.
 *
 * HANYA galat semacam itu yang ditahan. Salah tulis predikat (misalnya memanggil
 * fungsi yang tidak ada) tetap dilempar keluar seketika, supaya tidak berubah
 * menjadi penantian 15 detik yang berakhir dengan pesan "tidak muncul" — pesan
 * yang menunjuk ke aplikasi padahal yang salah adalah pemeriksanya.
 */
const GALAT_BELUM_SIAP = /of null|of undefined|Cannot read propert|document is not defined|Execution context was destroyed|Cannot find context/i

export async function tungguSampai(ws, ekspresi, batasMs = 12000) {
  const mulai = Date.now()
  let galatTerakhir = null
  while (Date.now() - mulai < batasMs) {
    try {
      if (await evaluasi(ws, `Boolean(${ekspresi})`)) return true
    } catch (e) {
      const pesan = String(e?.message ?? e)
      if (!GALAT_BELUM_SIAP.test(pesan)) throw e
      galatTerakhir = e
    }
    await new Promise((r) => setTimeout(r, 150))
  }
  if (galatTerakhir) {
    console.warn(
      `  (tungguSampai habis; galat terakhir: ${String(galatTerakhir.message).slice(0, 160)})`,
    )
  }
  return false
}

/** Login lewat formulir, bukan dengan menanam token - jalur yang dipakai orang. */
export async function masukSebagai(ws, email, kataSandi = 'password') {
  await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
  await tungguSampai(ws, `document.querySelector('#email')`)
  await evaluasi(ws, `
    (() => {
      const set = (sel, v) => {
        const el = document.querySelector(sel)
        el.value = v
        el.dispatchEvent(new Event('input', { bubbles: true }))
      }
      set('#email', ${JSON.stringify(email)})
      set('#password', ${JSON.stringify(kataSandi)})
      document.querySelector('form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      return true
    })()
  `)
  return tungguSampai(ws, `!location.pathname.startsWith('/masuk')`, 15000)
}

/**
 * Memanggil API dari dalam peramban, memakai token yang sedang aktif.
 *
 * Sengaja dari peramban, bukan dari Node: yang diuji adalah permintaan dengan
 * kredensial yang benar-benar dimiliki pengguna, dan CORS serta header yang
 * ikut terkirim menjadi bagian dari bukti.
 */
export async function panggilApi(ws, jalur, metode = 'GET', isi = null, headerTambahan = {}) {
  return evaluasi(ws, `
    (async () => {
      const h = Object.assign({
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
      }, ${JSON.stringify(headerTambahan)})
      const o = { method: ${JSON.stringify(metode)}, headers: h }
      const isi = ${JSON.stringify(isi)}
      if (isi !== null) o.body = JSON.stringify(isi)
      const res = await fetch(${JSON.stringify(API + jalur)}, o)
      const teks = await res.text()
      let data = null
      try { data = teks ? JSON.parse(teks) : null } catch { data = null }
      return { status: res.status, data }
    })()
  `)
}

/**
 * Menyatakan kebijakan kos default bila belum ada (T16b).
 *
 * Sejak T16, `POST /tagihan` menolak terbit sebelum satuan sewa dan jatuh tempo
 * ditetapkan; sejak T17 denda ikut wajib, karena `parameter_denda` sudah
 * dihapus dan kebijakan adalah satu-satunya sumber angka denda. Tujuh skrip E2E
 * melakukan POST /kamar lalu POST /tagihan, jadi semuanya akan gagal pada
 * langkah yang tidak sedang diuji - dan kegagalan itu benar: gerbangnya memang
 * bekerja.
 *
 * Idempoten dengan sengaja. Skrip E2E dijalankan berulang tanpa
 * `migrate:fresh` lebih dulu, dan `kebijakan_kos` bersifat append-only: POST
 * kedua dengan tanggal yang sama akan ditolak unique index. Karena itu yang
 * diperiksa adalah `meta.lengkap` dari GET, bukan hasil POST sebelumnya -
 * "sudah lengkap" berarti tidak ada yang perlu ditulis, apa pun yang terjadi
 * di masa lalu.
 *
 * `berlaku_dari` 2026-01-01, bukan hari ini: periode yang diuji di skrip-skrip
 * ini adalah 2026, dan kebijakan yang berlaku mulai hari ini tidak akan
 * terpakai untuk periode yang sudah lewat.
 *
 * @returns {Promise<{ok: boolean, alasan: string}>}
 */
export async function pastikanKebijakan(ws) {
  const awal = await panggilApi(ws, '/kebijakan?berlaku_pada=2026-01-01')

  if (awal.status !== 200) {
    return { ok: false, alasan: `GET /kebijakan gagal (${awal.status})` }
  }

  if (awal.data?.meta?.lengkap) {
    return { ok: true, alasan: 'sudah lengkap' }
  }

  // Hanya tipe yang benar-benar kurang yang dikirim. Mengirim semuanya selalu
  // akan gagal 422 pada tipe yang sudah ada, karena unique index menolak
  // tanggal yang sama.
  //
  // Sejak T17 daftarnya tiga tipe, dan `denda` membawa angka. Angkanya R-03
  // (1% per hari, plafon 30%, tenggang 7) supaya skrip E2E yang memeriksa
  // nominal denda tetap melihat angka yang sama seperti sebelum tiket ini.
  // Kuncinya sengaja dikirim hanya untuk tipe yang memakainya: FormRequest
  // melarang kolom angka pada tipe yang tidak membacanya.
  const kurang = awal.data?.meta?.kurang || []
  const bawaan = {
    satuan_sewa: { nilai: 'bulanan' },
    jatuh_tempo: { nilai: 'tanggal_masuk' },
    denda: {
      nilai: 'persen_harian',
      persen: 1,
      plafon_persen: 30,
      masa_tenggang: 7,
    },
  }

  for (const tipe of kurang) {
    const isi = bawaan[tipe]
    if (!isi) {
      return { ok: false, alasan: `tidak ada nilai bawaan untuk tipe ${tipe}` }
    }

    const kirim = await panggilApi(ws, '/kebijakan', 'POST', {
      tipe,
      ...isi,
      berlaku_dari: '2026-01-01',
    })

    // 422 di sini berarti baris untuk tanggal itu sudah ada - keadaan yang
    // sama baiknya dengan berhasil, karena yang dipentingkan adalah ada baris
    // yang berlaku. Selain 201 dan 422 adalah kegagalan sungguhan.
    if (kirim.status !== 201 && kirim.status !== 422) {
      return { ok: false, alasan: `POST /kebijakan ${tipe} gagal (${kirim.status})` }
    }
  }

  const akhir = await panggilApi(ws, '/kebijakan?berlaku_pada=2026-01-01')

  if (!akhir.data?.meta?.lengkap) {
    return { ok: false, alasan: 'kebijakan masih belum lengkap setelah disimpan' }
  }

  return { ok: true, alasan: `diisi: ${kurang.join(', ')}` }
}

/** Tangkapan layar ke docs/uji/<nama>.png. */export async function tangkapLayar(ws, nama) {
  const { writeFileSync } = await import('node:fs')
  const potret = await kirim(ws, 'Page.captureScreenshot', { format: 'png' })
  writeFileSync(`docs/uji/${nama}.png`, Buffer.from(potret.data, 'base64'))
}

// --- Kotak surat Mailpit ----------------------------------------------------
//
// Sejak T27 akun baru tidak punya kata sandi yang diketahui siapa pun. Satu-
// satunya jalan masuk adalah tautan aktivasi yang dikirim ke email penghuni,
// dan tautan itu hanya ada di kotak surat. Skrip uji karena itu harus membaca
// email - bukan mengambil token dari basis data, karena tes yang membaca token
// dari basis data akan lulus walau emailnya tidak pernah berisi tautan yang
// benar, dan justru itu yang paling mudah rusak.

/** Cari pesan untuk satu alamat, terbaru lebih dulu, sejak waktu tertentu. */
async function pesanUntuk(ke, sejak) {
  const kueri = encodeURIComponent(`to:${ke}`)
  const res = await fetch(`${MAILPIT}/api/v1/search?query=${kueri}&limit=50`)

  if (!res.ok) {
    throw new Error(`Mailpit tidak bisa dibaca (${res.status}). Jalankan docker compose up -d mailpit.`)
  }

  const isi = await res.json()
  const batas = sejak ? sejak.getTime() : 0

  return (isi.messages || [])
    .filter((m) => new Date(m.Created).getTime() >= batas)
    .sort((a, b) => String(b.Created).localeCompare(String(a.Created)))
}

/** Isi teks satu pesan, berdasarkan ID-nya. */
async function teksPesan(id) {
  const res = await fetch(`${MAILPIT}/api/v1/message/${id}`)

  if (!res.ok) {
    throw new Error(`pesan Mailpit ${id} tidak bisa dibaca (${res.status})`)
  }

  const isi = await res.json()

  return String(isi.Text || '')
}

/**
 * Kosongkan seluruh kotak surat.
 *
 * Berguna saat menyiapkan ulang demo. Skrip uji sendiri tidak bergantung pada
 * ini: `tautanAkunTerakhir()` menyaring menurut waktu kirim, jadi surat lama
 * dari percobaan sebelumnya tidak pernah terpilih.
 */
export async function bersihkanSurat() {
  const res = await fetch(`${MAILPIT}/api/v1/messages`, { method: 'DELETE' })

  if (!res.ok) {
    throw new Error(`Mailpit tidak bisa dikosongkan (${res.status})`)
  }
}

/**
 * Isi teks surat terakhir untuk satu alamat.
 *
 * Dipakai untuk memeriksa hal-hal yang hanya tertulis di surat - mis. umur
 * tautan yang dinyatakan dalam kalimat. Diperiksa dari surat, bukan dari
 * konstanta di kode: konstanta yang benar tetapi tidak dipakai di templat akan
 * lulus uji yang membaca kode.
 */
export async function teksSuratTerakhir(ke, sejak = null, percobaan = 40) {
  for (let i = 0; i < percobaan; i++) {
    const pesan = await pesanUntuk(ke, sejak)

    if (pesan.length > 0) {
      return teksPesan(pesan[0].ID)
    }

    await new Promise((r) => setTimeout(r, 250))
  }

  throw new Error(`tidak ada surat untuk ${ke}`)
}

/**
 * Ambil tautan `/atur-sandi?...` yang dikirim ke satu alamat.
 *
 * `sejak` menyaring surat lama. Penting karena `periksa.ps1` me-reset basis
 * data sebelum setiap skrip tetapi TIDAK mengosongkan Mailpit: surat dari
 * percobaan sebelumnya masih ada di sana dengan token yang sudah tidak dikenal
 * server. Tanpa penyaringan, skrip akan membaca token mati dan melaporkan
 * "tautan tidak berlaku" - kegagalan yang menunjuk ke tempat yang salah.
 *
 * @returns {Promise<{token: string, jenis: string, jalur: string}>}
 */
export async function tautanAkunTerakhir(ke, sejak = null, percobaan = 40) {
  // Email dikirim setelah respons HTTP selesai, jadi ada jeda antara
  // `POST /penghuni` kembali dan suratnya tiba di Mailpit. Menunggu dengan
  // batas, bukan tidur tetap: mesin yang lambat butuh lebih lama, mesin yang
  // cepat tidak perlu menunggu penuh.
  for (let i = 0; i < percobaan; i++) {
    const pesan = await pesanUntuk(ke, sejak)

    for (const satu of pesan) {
      const teks = await teksPesan(satu.ID)
      const cocok = teks.match(/\/atur-sandi\?[^\s]+/)

      if (cocok) {
        const jalur = cocok[0].replace(/[.,)]+$/, '')
        const kueri = new URLSearchParams(jalur.split('?')[1] || '')

        return {
          token: kueri.get('token') || '',
          jenis: kueri.get('jenis') || '',
          jalur,
        }
      }
    }

    await new Promise((r) => setTimeout(r, 250))
  }

  throw new Error(`tidak ada tautan akun di kotak surat ${ke}`)
}

/**
 * Aktifkan akun lewat API, tanpa menyentuh layar.
 *
 * Dipakai skrip yang butuh akun yang bisa masuk tetapi TIDAK sedang menguji
 * aktivasi itu sendiri. Jalan pintas lewat basis data sengaja dihindari: kalau
 * email berhenti dikirim, skrip-skrip ini ikut gagal - dan itu benar, karena
 * penghuni sungguhan juga tidak akan bisa masuk.
 *
 * @param {Date|null} sejak lihat `tautanAkunTerakhir()`
 * @returns {Promise<{status: number, data: any}>}
 */
export async function aktifkanAkunApi(ke, sandi = 'password', sejak = null) {
  const { token, jenis } = await tautanAkunTerakhir(ke, sejak)

  return panggilApiPublik('/atur-sandi', 'POST', {
    token,
    jenis,
    password: sandi,
    password_confirmation: sandi,
  })
}

/**
 * Panggil API dari Node, tanpa sesi.
 *
 * Berbeda dari `panggilApi()`, yang berjalan di dalam peramban dan membawa
 * token pemilik. Dipakai untuk langkah yang memang tidak butuh sesi - aktivasi
 * akun adalah endpoint publik, dan memanggilnya dengan token pemilik akan
 * menyembunyikan kesalahan bila endpoint itu suatu saat tidak lagi publik.
 */
export async function panggilApiPublik(jalur, metode = 'GET', isi = null, token = '') {
  const res = await fetch(`${API}${jalur}`, {
    method: metode,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: isi === null ? undefined : JSON.stringify(isi),
  })

  const teks = await res.text()
  let data = null
  try {
    data = teks ? JSON.parse(teks) : null
  } catch {
    data = null
  }

  return { status: res.status, data }
}

/**
 * Aktifkan akun lewat tautan email - persis seperti yang dilakukan penghuni.
 *
 * `APP_URL` di server menunjuk ke alamat API (port 8081 di Docker), sedangkan
 * SPA dilayani di port lain. Tautannya karena itu dipakai sebagai JALUR saja,
 * dan hostnya diganti dengan `APP`. Di produksi keduanya satu alamat, jadi
 * penggantian ini tidak menyembunyikan kesalahan apa pun.
 *
 * @returns {Promise<boolean>} true bila halaman menyatakan sandi tersimpan
 */
export async function aktifkanAkun(ws, ke, sandiBaru = 'sandi-uji-12345', sejak = null) {
  const { jalur } = await tautanAkunTerakhir(ke, sejak)

  await kirim(ws, 'Page.navigate', { url: `${APP}${jalur}` })
  await tungguSampai(ws, `document.querySelector('#password-baru')`, 15000)

  await evaluasi(ws, `
    (() => {
      const set = (sel, v) => {
        const el = document.querySelector(sel)
        if (!el) return false
        el.value = v
        el.dispatchEvent(new Event('input', { bubbles: true }))
        return true
      }
      if (!set('#password-baru', ${JSON.stringify(sandiBaru)})) return false
      set('#password-ulang', ${JSON.stringify(sandiBaru)})
      document.querySelector('form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      return true
    })()
  `)

  return tungguSampai(ws, `document.body.textContent.includes('Kata sandi tersimpan')`, 15000)
}

/**
 * Menyetujui teks PDP bila gerbangnya muncul, lalu lanjut.
 *
 * Sejak T28 setiap akun baru mendarat di `/persetujuan-pdp` sekali, sebelum
 * boleh memakai aplikasi. Skrip yang menguji hal LAIN - isolasi tagihan,
 * wewenang pengelola, notifikasi - tidak sedang menguji gerbang itu, dan
 * membiarkannya berdiri membuat kegagalannya terbaca seperti kerusakan pada hal
 * yang sebenarnya benar.
 *
 * Dipanggil setelah `masukSebagai()`. Tidak melakukan apa pun bila gerbangnya
 * tidak muncul, jadi aman dipanggil pada akun yang sudah pernah menyetujui.
 *
 * @returns {Promise<boolean>} true bila tidak ada gerbang, atau gerbangnya berhasil dilewati
 */
export async function lewatiPersetujuanPdp(ws) {
  // Gerbang muncul sebagai pengalihan, bukan modal: tunggu sebentar untuk
  // memberi router kesempatan memutuskannya sebelum menyimpulkan "tidak ada".
  await new Promise((r) => setTimeout(r, 700))

  const diGerbang = await evaluasi(ws, `location.pathname === '/persetujuan-pdp'`)
  if (!diGerbang) return true

  await evaluasi(ws, `
    (() => {
      const tombol = [...document.querySelectorAll('button')]
        .find((b) => /saya setuju/i.test(b.textContent))
      if (tombol) tombol.click()
      return Boolean(tombol)
    })()
  `)

  return tungguSampai(ws, `location.pathname !== '/persetujuan-pdp'`, 15000)
}


/**
 * Menutup semua tab `page` yang menggantung.
 *
 * Wajib sebelum memulai: tab yatim dari percobaan sebelumnya membuat langkah
 * login gagal karena alasan yang tidak ada hubungannya dengan aplikasi, dan
 * gejalanya menyesatkan.
 */
export async function bersihkanTab() {
  try {
    const daftar = await fetch(`${CDP_BASE}/json/list`).then((r) => r.json())
    await Promise.all(
      daftar
        .filter((t) => t.type === 'page')
        .map((t) => fetch(`${CDP_BASE}/json/close/${t.id}`).catch(() => {})),
    )
  } catch {
    // Mengabaikan kegagalan pembersihan: galat aslinya yang perlu terlihat.
  }
}

/**
 * Menyiapkan satu sesi uji: tab baru, WebSocket tersambung, pelapor langkah.
 *
 * `selesai()` menutup tab, mencetak ringkasan, dan menyetel kode keluar.
 */
export async function mulaiUji() {
  const tab = await targetBaru()
  const ws = await hubungkan(tab.webSocketDebuggerUrl)

  await kirim(ws, 'Page.enable')
  await kirim(ws, 'Runtime.enable')

  // Cache dimatikan untuk seluruh sesi uji.
  //
  // Tanpa ini, Chrome menyajikan `index.html` dan bundel JS dari cache setelah
  // `docker compose build web`, dan seluruh uji berjalan terhadap kode LAMA
  // sambil melaporkan kegagalan pada fitur yang sebenarnya sudah ada. Gejalanya
  // menyesatkan ke arah yang paling mahal: halaman 404 untuk rute yang baru
  // ditambahkan, tombol yang bunyinya belum berubah, dan tidak satu pun pesan
  // galat yang menyebut cache.
  //
  // `Network.setCacheDisabled` berlaku pada target ini saja, jadi tidak
  // mengubah perilaku peramban di luar uji.
  await kirim(ws, 'Network.enable')
  await kirim(ws, 'Network.setCacheDisabled', { cacheDisabled: true })

  const langkah = []

  return {
    ws,
    langkah,

    lapor(nama, lulus, detail = '') {
      langkah.push({ nama, lulus: Boolean(lulus), detail })
      console.log(`${lulus ? 'LULUS' : 'GAGAL'}  ${nama}${detail ? ' - ' + detail : ''}`)
    },

    async selesai() {
      await kirim(ws, 'Page.close').catch(() => {})
      ws.close()
      const gagal = langkah.filter((l) => !l.lulus)
      console.log(`\n${langkah.length - gagal.length}/${langkah.length} langkah lulus`)
      if (gagal.length) process.exitCode = 1
      return gagal
    },
  }
}

/**
 * Membungkus `main` supaya kegagalan tak terduga tetap membersihkan tab.
 *
 * Tanpa ini, tiap kegagalan meninggalkan tab yatim, dan setelah beberapa tab
 * langkah login mulai gagal karena alasan yang tidak nyata.
 */
export function jalankan(main) {
  main().catch(async (e) => {
    console.error('E2E gagal:', e.message)
    await bersihkanTab()
    process.exitCode = 1
  })
}

// --- Varian tingkat modul ---------------------------------------------------
//
// Skrip lama memanggil `lapor()` langsung dari `jalan()`. Dua ekspor di bawah
// membuat skrip itu bisa memakai berkas ini tanpa menulis ulang isinya, supaya
// penggantian harness tidak bercampur dengan perubahan pada langkah ujinya.

const langkahModul = []

export function lapor(nama, lulus, detail = '') {
  langkahModul.push({ nama, lulus: Boolean(lulus), detail })
  console.log(`${lulus ? 'LULUS' : 'GAGAL'}  ${nama}${detail ? ' - ' + detail : ''}`)
}

/** Menutup tab, mencetak ringkasan, menyetel kode keluar. */
export async function selesaiModul(ws) {
  await kirim(ws, 'Page.close').catch(() => {})
  ws.close()
  const gagal = langkahModul.filter((l) => !l.lulus)
  console.log(`\n${langkahModul.length - gagal.length}/${langkahModul.length} langkah lulus`)
  if (gagal.length) process.exitCode = 1
  return gagal
}
