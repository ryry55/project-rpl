// Asap menyeluruh: buka SETIAP halaman sebagai pemilik dan sebagai penghuni,
// lalu laporkan galat konsol, permintaan yang gagal, dan halaman yang kosong.
//
// Ini bukan pengganti E2E per tiket. E2E membuktikan satu alur bisnis benar;
// skrip ini membuktikan tidak ada halaman yang diam-diam rusak — kelas
// kesalahan yang tidak tertangkap tes karena tes tidak pernah membukanya.
//
// Pakai: node docs/uji/asap-semua-halaman.mjs
// Prasyarat: docker compose up, Chrome --remote-debugging-port=9222,
// database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini TIDAK mengubah database selain membuat satu kontrak lewat API
// supaya halaman Tagihan punya isi.

const CDP_BASE = process.env.CDP_BASE || 'http://127.0.0.1:9222'
const APP = process.env.APP_URL || 'http://localhost:3000'
const API = process.env.API_URL || 'http://localhost:8081/api'

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

async function tungguSampai(ws, ekspresi, batasMs = 12000) {
  const mulai = Date.now()
  while (Date.now() - mulai < batasMs) {
    if (await evaluasi(ws, `Boolean(${ekspresi})`)) return true
    await new Promise((r) => setTimeout(r, 150))
  }
  return false
}

async function masukSebagai(ws, email) {
  await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
  await tungguSampai(ws, `document.querySelector('#email')`)
  await evaluasi(ws, `
    (() => {
      const set = (sel, v) => {
        const el = document.querySelector(sel)
        el.value = v
        el.dispatchEvent(new Event('input', { bubbles: true }))
      }
      set('#email', '${email}')
      set('#password', 'password')
      document.querySelector('form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      return true
    })()
  `)
  return tungguSampai(ws, `!location.pathname.startsWith('/masuk')`, 15000)
}

/** Galat konsol dan permintaan gagal dikumpulkan per halaman. */
async function pasangPerekam(ws) {
  await evaluasi(ws, `
    (() => {
      if (window.__rekam) return true
      window.__rekam = { galat: [], gagal: [] }
      window.addEventListener('error', (e) => {
        window.__rekam.galat.push(String(e.message || e.error || 'error'))
      })
      window.addEventListener('unhandledrejection', (e) => {
        window.__rekam.galat.push('promise: ' + String(e.reason))
      })
      const asli = window.fetch
      window.fetch = async (...a) => {
        const res = await asli(...a)
        if (res.status >= 400) {
          window.__rekam.gagal.push(res.status + ' ' + String(a[0]).replace(location.origin, ''))
        }
        return res
      }
      return true
    })()
  `)
}

async function bersihkanRekaman(ws) {
  await evaluasi(ws, `(() => { if (window.__rekam) window.__rekam = { galat: [], gagal: [] }; return true })()`)
}

async function bacaRekaman(ws) {
  return evaluasi(ws, `(() => window.__rekam || { galat: [], gagal: [] })()`)
}

/** Apakah halaman benar-benar menggambar sesuatu, bukan kerangka kosong. */
async function ukurHalaman(ws) {
  return evaluasi(ws, `
    (() => {
      const teks = (document.body?.innerText || '').replace(/\\s+/g, ' ').trim()
      return {
        judul: document.querySelector('h1')?.textContent?.trim() || '',
        panjangTeks: teks.length,
        jumlahBarisTabel: document.querySelectorAll('tbody tr').length,
        jumlahKartu: document.querySelectorAll('.kartu').length,
        jumlahTombol: document.querySelectorAll('button').length,
      }
    })()
  `)
}

async function kunjungi(ws, jalur, nama) {
  await bersihkanRekaman(ws)
  await kirim(ws, 'Page.navigate', { url: `${APP}${jalur}` })
  // Tunggu sampai Vue benar-benar menggambar sesuatu. Jeda tetap tidak cukup:
  // build baru dengan cache dingin butuh waktu lebih lama, dan halaman yang
  // belum sempat dirender terlihat sama persis dengan halaman yang rusak.
  await tungguSampai(ws, `document.querySelector('#app')?.children.length > 0`, 15000)
  const ukuran = await ukurHalaman(ws)
  const rekam = await bacaRekaman(ws)

  const galatNyata = rekam.galat.filter((g) => !/ResizeObserver/.test(g))
  // 401/403/404 sah untuk halaman yang memang memfilternya; yang tidak sah
  // adalah 500 dan permintaan yang gagal karena galat JavaScript.
  const gagalNyata = rekam.gagal.filter((g) => /^5\d\d/.test(g))

  const lulus = galatNyata.length === 0 && gagalNyata.length === 0 && ukuran.panjangTeks > 60
  lapor(
    `${nama} (${jalur})`,
    lulus,
    [
      ukuran.judul ? `"${ukuran.judul}"` : 'tanpa h1',
      `${ukuran.panjangTeks} huruf`,
      `${ukuran.jumlahBarisTabel} baris tabel`,
      galatNyata.length ? `galat konsol: ${galatNyata.join(' | ')}` : '',
      gagalNyata.length ? `HTTP 5xx: ${gagalNyata.join(' | ')}` : '',
    ]
      .filter(Boolean)
      .join(' · '),
  )
}

async function jalan() {
  const tab = await targetBaru()
  const ws = await hubungkan(tab.webSocketDebuggerUrl)
  await kirim(ws, 'Page.enable')
  await kirim(ws, 'Runtime.enable')

  // --- Pemilik -------------------------------------------------------------
  const masuk = await masukSebagai(ws, 'pemilik@kos.test')
  lapor('login pemilik', masuk, await evaluasi(ws, 'location.pathname'))
  if (!masuk) throw new Error('login pemilik gagal')

  await pasangPerekam(ws)

  // Satu kontrak supaya halaman Tagihan, Tunggakan, dan audit tidak kosong.
  const siap = await evaluasi(ws, `
    (async () => {
      const h = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
      }
      const p = async (jalur, metode, isi) => {
        const o = { method: metode, headers: h }
        if (isi) o.body = JSON.stringify(isi)
        const r = await fetch('${API}' + jalur, o)
        const t = await r.text()
        let d = null
        try { d = t ? JSON.parse(t) : null } catch { d = null }
        return { status: r.status, data: d }
      }
      const kamar = await p('/kamar', 'POST', { nomor: 'ASAP-01', tipe: 'Uji asap' })
      if (kamar.status !== 201) return 'kamar: ' + JSON.stringify(kamar.data)
      const tarif = await p('/tarif', 'POST', {
        kamar_id: kamar.data.data.id, nilai: 1500000, berlaku_dari: '2026-01-01',
      })
      if (tarif.status !== 201) return 'tarif: ' + JSON.stringify(tarif.data)
      const penghuni = await p('/penghuni', 'POST', {
        nama: 'Asap Satu', email: 'asap@kos.test', password: 'password',
      })
      if (penghuni.status !== 201) return 'penghuni: ' + JSON.stringify(penghuni.data)
      const kontrak = await p('/kontrak', 'POST', {
        kamar_id: kamar.data.data.id, penghuni_id: penghuni.data.data.id,
        tgl_masuk: '2026-01-15', deposit: 0,
      })
      if (kontrak.status !== 201) return 'kontrak: ' + JSON.stringify(kontrak.data)
      const tagihan = await p('/tagihan', 'POST', {
        kontrak_id: kontrak.data.data.id, periode: '2026-11-01',
      })
      if (tagihan.status !== 201) return 'tagihan: ' + JSON.stringify(tagihan.data)
      return 'ok'
    })()
  `)
  lapor('data uji disiapkan', siap === 'ok', siap)

  const halamanPemilik = [
    ['/pemilik/kamar', 'Kamar'],
    ['/pemilik/penghuni', 'Penghuni'],
    ['/pemilik/kontrak', 'Kontrak'],
    ['/pemilik/tagihan', 'Tagihan'],
    ['/pemilik/tunggakan', 'Tunggakan'],
    ['/pemilik/audit', 'Jejak audit'],
  ]
  for (const [jalur, nama] of halamanPemilik) {
    await kunjungi(ws, jalur, nama)
  }

  // --- Penghuni ------------------------------------------------------------
  const masukP = await masukSebagai(ws, 'asap@kos.test')
  lapor('login penghuni', masukP, await evaluasi(ws, 'location.pathname'))
  if (!masukP) throw new Error('login penghuni gagal')

  await pasangPerekam(ws)
  await kunjungi(ws, '/penghuni', 'Portal penghuni')

  // --- Rute yang tidak ada -------------------------------------------------
  await bersihkanRekaman(ws)
  await kirim(ws, 'Page.navigate', { url: `${APP}/tidak-ada-halaman-ini` })
  await tungguSampai(ws, `document.querySelector('#app')?.children.length > 0`, 15000)
  const nyasar = await ukurHalaman(ws)
  lapor(
    'rute tak dikenal tidak menampilkan halaman kosong',
    nyasar.panjangTeks > 20,
    `${nyasar.panjangTeks} huruf · "${nyasar.judul}"`,
  )

  await kirim(ws, 'Page.close').catch(() => {})
  ws.close()

  const gagal = langkah.filter((l) => !l.lulus)
  console.log(`\n${langkah.length - gagal.length}/${langkah.length} langkah lulus`)
  if (gagal.length) process.exitCode = 1
}

jalan().catch(async (e) => {
  console.error('Asap gagal:', e.message)
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
  process.exitCode = 1
})
