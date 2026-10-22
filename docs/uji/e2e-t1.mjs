// Verifikasi end-to-end T1 lewat browser sungguhan.
// Menjalankan Chrome lewat DevTools Protocol tanpa dependensi tambahan:
// Node 22 punya WebSocket bawaan, jadi tidak perlu puppeteer.
//
// Pakai: node docs/uji/e2e-t1.mjs
// Prasyarat: docker compose up sudah jalan (web :3000, api :8081).

const CDP_BASE = process.env.CDP_BASE || 'http://127.0.0.1:9222'
const APP = process.env.APP_URL || 'http://localhost:3000'

const langkah = []
function lapor(nama, lulus, detail = '') {
  langkah.push({ nama, lulus, detail })
  console.log(`${lulus ? 'LULUS' : 'GAGAL'}  ${nama}${detail ? ' — ' + detail : ''}`)
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

let idPesan = 0
function kirim(ws, method, params = {}) {
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

async function evaluasi(ws, ekspresi) {
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

async function tungguSampai(ws, ekspresi, batasMs = 10000) {
  const mulai = Date.now()
  while (Date.now() - mulai < batasMs) {
    if (await evaluasi(ws, `Boolean(${ekspresi})`)) return true
    await new Promise((r) => setTimeout(r, 150))
  }
  return false
}

async function jalan() {
  const tab = await targetBaru()
  const ws = await hubungkan(tab.webSocketDebuggerUrl)

  await kirim(ws, 'Page.enable')
  await kirim(ws, 'Runtime.enable')

  // --- 1. Halaman login tampil ---------------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
  const adaForm = await tungguSampai(ws, `document.querySelector('#email')`)
  lapor('halaman login tampil', Boolean(adaForm))

  // --- 2. Rute terlindungi tanpa sesi dialihkan ke /masuk ------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik` })
  const dialihkan = await tungguSampai(ws, `location.pathname === '/masuk'`)
  lapor('akses /pemilik tanpa login dialihkan ke /masuk', Boolean(dialihkan),
    await evaluasi(ws, 'location.pathname'))

  // --- 3. Login pemilik ----------------------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
  await tungguSampai(ws, `document.querySelector('#email')`)
  await evaluasi(ws, `
    (() => {
      const set = (el, v) => {
        el.value = v
        el.dispatchEvent(new Event('input', { bubbles: true }))
      }
      set(document.querySelector('#email'), 'pemilik@kos.test')
      set(document.querySelector('#password'), 'password')
      document.querySelector('form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true })
      )
      return true
    })()
  `)
  const kePemilik = await tungguSampai(ws, `location.pathname === '/pemilik'`)
  lapor('login pemilik mengarah ke /pemilik', Boolean(kePemilik),
    await evaluasi(ws, 'location.pathname'))

  const judul = await evaluasi(ws, `document.querySelector('h1')?.textContent || ''`)
  lapor('dashboard pemilik menampilkan judul yang benar',
    judul.includes('Dashboard Pemilik'), judul)

  const tokenTersimpan = await evaluasi(ws, `Boolean(localStorage.getItem('kos.token'))`)
  lapor('token tersimpan di localStorage', Boolean(tokenTersimpan))

  // --- 4. Pemisahan peran: penghuni tidak boleh masuk /pemilik -------------
  await evaluasi(ws, `localStorage.clear(); true`)
  await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
  await tungguSampai(ws, `document.querySelector('#email')`)
  await evaluasi(ws, `
    (() => {
      const set = (el, v) => {
        el.value = v
        el.dispatchEvent(new Event('input', { bubbles: true }))
      }
      set(document.querySelector('#email'), 'penghuni@kos.test')
      set(document.querySelector('#password'), 'password')
      document.querySelector('form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true })
      )
      return true
    })()
  `)
  const kePenghuni = await tungguSampai(ws, `location.pathname === '/penghuni'`)
  lapor('login penghuni mengarah ke /penghuni', Boolean(kePenghuni),
    await evaluasi(ws, 'location.pathname'))

  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik` })
  const ditolak = await tungguSampai(ws, `location.pathname === '/penghuni'`, 6000)
  lapor('penghuni diarahkan keluar dari /pemilik', Boolean(ditolak),
    await evaluasi(ws, 'location.pathname'))

  // --- 5. Keluar -----------------------------------------------------------
  const keluarBerkas = await evaluasi(ws, `
    (() => {
      const btn = [...document.querySelectorAll('button')].find(b => /keluar/i.test(b.textContent))
      if (!btn) return false
      btn.click()
      return true
    })()
  `)
  const keMasuk = keluarBerkas && (await tungguSampai(ws, `location.pathname === '/masuk'`))
  lapor('tombol keluar kembali ke /masuk', Boolean(keMasuk),
    await evaluasi(ws, 'location.pathname'))

  const tokenBersih = await evaluasi(ws, `!localStorage.getItem('kos.token')`)
  lapor('token dihapus saat keluar', Boolean(tokenBersih))

  await kirim(ws, 'Page.close').catch(() => {})
  ws.close()

  const gagal = langkah.filter((l) => !l.lulus)
  console.log(`\n${langkah.length - gagal.length}/${langkah.length} langkah lulus`)
  if (gagal.length) process.exitCode = 1
}

jalan().catch((e) => {
  console.error('E2E gagal:', e.message)
  process.exitCode = 1
})
