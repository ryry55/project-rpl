// Perkakas bersama untuk seluruh skrip uji browser di folder ini.
//
// Sebelumnya lima fungsi CDP yang sama disalin ke delapan berkas. Bukan cuma
// soal jumlah baris: dua kesalahan berulang lahir justru di bagian yang
// disalin itu.
//
//   1. `/15\\.000/` — regex ditulis di dalam template literal yang dikirim ke
//      peramban, jadi satu backslash berlebih membuatnya mencari "backslash
//      literal" dan asersinya tidak pernah cocok. Terjadi di T5, T6, dan T9.
//   2. Nama pemilik dari seeder ditulis tetap di skrip, lalu gagal setiap kali
//      data contoh berubah — kegagalan yang tidak mengatakan apa pun tentang
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

export { CDP_BASE, APP, API }

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

/** Rupiah dengan titik ribuan, mis. 1.500.000 — untuk pesan kegagalan. */
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

export function kirim(ws, method, params = {}) {
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
    ws.send(JSON.stringify({ id, method, params }))
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

export async function tungguSampai(ws, ekspresi, batasMs = 12000) {
  const mulai = Date.now()
  while (Date.now() - mulai < batasMs) {
    if (await evaluasi(ws, `Boolean(${ekspresi})`)) return true
    await new Promise((r) => setTimeout(r, 150))
  }
  return false
}

/** Login lewat formulir, bukan dengan menanam token — jalur yang dipakai orang. */
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

/** Tangkapan layar ke docs/uji/<nama>.png. */
export async function tangkapLayar(ws, nama) {
  const { writeFileSync } = await import('node:fs')
  const potret = await kirim(ws, 'Page.captureScreenshot', { format: 'png' })
  writeFileSync(`docs/uji/${nama}.png`, Buffer.from(potret.data, 'base64'))
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

  const langkah = []

  return {
    ws,
    langkah,

    lapor(nama, lulus, detail = '') {
      langkah.push({ nama, lulus: Boolean(lulus), detail })
      console.log(`${lulus ? 'LULUS' : 'GAGAL'}  ${nama}${detail ? ' — ' + detail : ''}`)
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
  console.log(`${lulus ? 'LULUS' : 'GAGAL'}  ${nama}${detail ? ' — ' + detail : ''}`)
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
