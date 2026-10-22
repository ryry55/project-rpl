// Verifikasi end-to-end T2 lewat browser sungguhan.
//
// Membuktikan alur pemilik dari layar: mendaftarkan 8 kamar, 6 penghuni, lalu
// membuat kontrak — dan bahwa mengubah tarif menambah baris riwayat alih-alih
// menimpa.
//
// Pakai: node docs/uji/e2e-t2.mjs
// Prasyarat: docker compose up sudah jalan (web :3000, api :8081), Chrome
// dengan --remote-debugging-port=9222.

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

async function tungguSampai(ws, ekspresi, batasMs = 10000) {
  const mulai = Date.now()
  while (Date.now() - mulai < batasMs) {
    if (await evaluasi(ws, `Boolean(${ekspresi})`)) return true
    await new Promise((r) => setTimeout(r, 150))
  }
  return false
}

// Mengisi input Vue dengan memicu event input, bukan menulis value langsung:
// v-model hanya memperbarui state kalau event-nya terjadi.
const isiFormulir = (pasangan) => `
  (() => {
    const set = (sel, v) => {
      const el = document.querySelector(sel)
      if (!el) throw new Error('elemen tidak ada: ' + sel)
      el.value = v
      el.dispatchEvent(new Event('input', { bubbles: true }))
      el.dispatchEvent(new Event('change', { bubbles: true }))
      return el
    }
    ${pasangan}
    return true
  })()
`

const klikTeks = (teks) => `
  (() => {
    const btn = [...document.querySelectorAll('button')].find(b =>
      new RegExp(${JSON.stringify(teks)}, 'i').test(b.textContent))
    if (!btn) return false
    btn.click()
    return true
  })()
`

async function loginPemilik(ws) {
  await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
  await tungguSampai(ws, `document.querySelector('#email')`)
  await evaluasi(
    ws,
    isiFormulir(`
      set('#email', 'pemilik@kos.test')
      set('#password', 'password')
      document.querySelector('form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
    `),
  )
  return tungguSampai(ws, `location.pathname.startsWith('/pemilik')`)
}

async function jalan() {
  const tab = await targetBaru()
  const ws = await hubungkan(tab.webSocketDebuggerUrl)

  await kirim(ws, 'Page.enable')
  await kirim(ws, 'Runtime.enable')

  // --- Login ---------------------------------------------------------------
  const masuk = await loginPemilik(ws)
  lapor('login pemilik berhasil', Boolean(masuk), await evaluasi(ws, 'location.pathname'))

  const keKamar = await tungguSampai(ws, `location.pathname === '/pemilik/kamar'`)
  lapor('pemilik diarahkan ke halaman kamar', Boolean(keKamar),
    await evaluasi(ws, 'location.pathname'))

  // --- 8 kamar lewat UI ----------------------------------------------------
  const nomorKamar = ['A1', 'A2', 'A3', 'B1', 'B2', 'B3', 'C1', 'C2']
  const tipeKamar = ['Standar', 'Standar', 'Standar', 'Deluxe', 'Deluxe', 'Deluxe', 'Premium', 'Premium']
  let kamarBerhasil = 0

  for (let i = 0; i < nomorKamar.length; i++) {
    const terdaftar = await evaluasi(ws, `
      (async () => {
        const tombol = [...document.querySelectorAll('button')].find(b => /tambah kamar/i.test(b.textContent))
        if (!tombol) return false
        tombol.click()
        await new Promise(r => setTimeout(r, 120))
        const set = (sel, v) => {
          const el = document.querySelector(sel)
          el.value = v
          el.dispatchEvent(new Event('input', { bubbles: true }))
        }
        set('#nomor', ${JSON.stringify(nomorKamar[i])})
        set('#tipe', ${JSON.stringify(tipeKamar[i])})
        document.querySelector('form').dispatchEvent(
          new Event('submit', { bubbles: true, cancelable: true }))
        return true
      })()
    `)
    if (!terdaftar) break
    const muncul = await tungguSampai(ws, `
      [...document.querySelectorAll('td strong')].some(td => td.textContent.trim() === ${JSON.stringify(nomorKamar[i])})
    `, 6000)
    if (muncul) kamarBerhasil++
  }
  lapor('8 kamar terdaftar lewat UI', kamarBerhasil === 8, `${kamarBerhasil}/8`)

  // Nomor duplikat harus ditolak dengan pesan yang terbaca.
  const duplikatDitolak = await evaluasi(ws, `
    (async () => {
      const tombol = [...document.querySelectorAll('button')].find(b => /tambah kamar/i.test(b.textContent))
      tombol.click()
      await new Promise(r => setTimeout(r, 120))
      const set = (sel, v) => {
        const el = document.querySelector(sel)
        el.value = v
        el.dispatchEvent(new Event('input', { bubbles: true }))
      }
      set('#nomor', 'A1')
      document.querySelector('form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      await new Promise(r => setTimeout(r, 800))
      const pesan = document.querySelector('.pesan-galat')?.textContent || ''
      return /sudah dipakai/i.test(pesan)
    })()
  `)
  lapor('nomor kamar duplikat ditolak dengan pesan jelas', Boolean(duplikatDitolak))
  await evaluasi(ws, klikTeks('batal'))

  // --- 6 penghuni lewat UI -------------------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/penghuni` })
  await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Penghuni')`)

  // Enam penghuni baru. Namanya sengaja tidak memakai "Budi Santoso" — nama
  // itu sudah ada dari seeder, dan memakainya lagi membuat pemeriksaan
  // "nama sudah muncul di tabel" lulus sebelum formulir sempat terkirim.
  const namaPenghuni = [
    'Siti Rahayu',
    'Agus Prasetyo',
    'Dewi Lestari',
    'Rizky Firmansyah',
    'Nur Aisyah',
    'Joko Susilo',
  ]
  let penghuniBerhasil = 0

  for (let i = 0; i < namaPenghuni.length; i++) {
    const email = `penghuni${i + 1}@kos.test`
    const terdaftar = await evaluasi(ws, `
      (async () => {
        const tombol = [...document.querySelectorAll('button')].find(b => /tambah penghuni/i.test(b.textContent))
        if (!tombol) return false
        tombol.click()
        await new Promise(r => setTimeout(r, 200))
        const set = (sel, v) => {
          const el = document.querySelector(sel)
          if (!el) throw new Error('elemen tidak ada: ' + sel)
          el.value = v
          el.dispatchEvent(new Event('input', { bubbles: true }))
        }
        set('#nama', ${JSON.stringify(namaPenghuni[i])})
        set('#email', ${JSON.stringify(email)})
        set('#no_hp', '08120000${String(i + 1).padStart(4, '0')}')
        set('#password', 'rahasia123')
        document.querySelector('form').dispatchEvent(
          new Event('submit', { bubbles: true, cancelable: true }))
        return true
      })()
    `)
    if (!terdaftar) break

    // Tunggu formulir tertutup lebih dulu — itu tanda penyimpanan selesai —
    // baru periksa namanya ada di tabel.
    const formTertutup = await tungguSampai(ws, `!document.querySelector('#nama')`, 8000)
    if (!formTertutup) break

    const muncul = await tungguSampai(ws, `
      [...document.querySelectorAll('tbody strong')].some(td => td.textContent.trim() === ${JSON.stringify(namaPenghuni[i])})
    `, 6000)
    if (muncul) penghuniBerhasil++
  }
  lapor('6 penghuni terdaftar lewat UI', penghuniBerhasil === 6, `${penghuniBerhasil}/6`)

  // --- Kontrak lewat UI ----------------------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/kontrak` })
  await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Kontrak')`)

  const kontrakTerbentuk = await evaluasi(ws, `
    (async () => {
      const tombol = [...document.querySelectorAll('button')].find(b => /buat kontrak/i.test(b.textContent))
      if (!tombol) return 'tombol tidak ada'
      tombol.click()
      await new Promise(r => setTimeout(r, 200))

      const kamar = document.querySelector('#kamar')
      const penghuni = document.querySelector('#penghuni')
      if (!kamar || !penghuni) return 'formulir tidak tampil'

      // Pilih kamar A1 dan penghuni pertama.
      const opsiKamar = [...kamar.options].find(o => o.textContent.trim().startsWith('A1'))
      if (!opsiKamar) return 'opsi kamar A1 tidak ada'
      kamar.value = opsiKamar.value
      kamar.dispatchEvent(new Event('change', { bubbles: true }))

      const opsiPenghuni = [...penghuni.options].find(o => o.textContent.trim() === 'Budi Santoso')
      if (!opsiPenghuni) return 'opsi penghuni tidak ada'
      penghuni.value = opsiPenghuni.value
      penghuni.dispatchEvent(new Event('change', { bubbles: true }))

      await new Promise(r => setTimeout(r, 120))

      const set = (sel, v) => {
        const el = document.querySelector(sel)
        el.value = v
        el.dispatchEvent(new Event('input', { bubbles: true }))
      }
      set('#tgl_masuk', '2026-10-05')
      set('#tarif', '1500000')
      set('#deposit', '1500000')

      document.querySelector('form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      return 'dikirim'
    })()
  `)
  lapor('formulir kontrak terkirim', kontrakTerbentuk === 'dikirim', String(kontrakTerbentuk))

  const kontrakTampil = await tungguSampai(ws, `
    [...document.querySelectorAll('tbody tr')].some(tr =>
      tr.textContent.includes('Budi Santoso') && tr.textContent.includes('A1'))
  `, 6000)
  lapor('kontrak Budi di kamar A1 tampil di tabel', Boolean(kontrakTampil))

  const tarifAwalTampil = await evaluasi(ws, `
    (() => {
      const baris = [...document.querySelectorAll('tbody tr')].find(tr => tr.textContent.includes('Budi Santoso'))
      return baris ? /1\.500\.000/.test(baris.textContent) : false
    })()
  `)
  lapor('tarif awal Rp 1.500.000 tampil di baris kontrak', Boolean(tarifAwalTampil))

  // --- Tarif append-only lewat UI -----------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/kamar` })
  await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Kamar')`)

  const bukaTarif = await evaluasi(ws, `
    (async () => {
      const baris = [...document.querySelectorAll('tbody tr')].find(tr => tr.textContent.includes('A2'))
      if (!baris) return false
      const tombol = [...baris.querySelectorAll('button')].find(b => /atur tarif/i.test(b.textContent))
      if (!tombol) return false
      tombol.click()
      await new Promise(r => setTimeout(r, 400))
      return Boolean(document.querySelector('#nilai-tarif'))
    })()
  `)
  lapor('panel riwayat tarif terbuka', Boolean(bukaTarif))

  await evaluasi(ws, `
    (async () => {
      const set = (sel, v) => {
        const el = document.querySelector(sel)
        el.value = v
        el.dispatchEvent(new Event('input', { bubbles: true }))
      }
      set('#nilai-tarif', '2000000')
      set('#berlaku-tarif', '2026-10-01')
      document.querySelector('form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      return true
    })()
  `)
  await tungguSampai(ws, `
    [...document.querySelectorAll('table tbody tr')].some(tr => /2\.000\.000/.test(tr.textContent))
  `, 6000)

  // Tarif kedua untuk kamar yang sama: harus menjadi baris baru.
  await evaluasi(ws, `
    (async () => {
      const set = (sel, v) => {
        const el = document.querySelector(sel)
        el.value = v
        el.dispatchEvent(new Event('input', { bubbles: true }))
      }
      set('#nilai-tarif', '2200000')
      set('#berlaku-tarif', '2026-11-01')
      document.querySelector('form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      return true
    })()
  `)
  await tungguSampai(ws, `
    [...document.querySelectorAll('table tbody tr')].some(tr => /2\.200\.000/.test(tr.textContent))
  `, 6000)

  // Hitung baris riwayat lewat API: bukti bahwa baris lama tidak hilang.
  // ID kamar dicari dari daftar, bukan ditebak — asumsi "A2 ber-ID 2" akan
  // patah begitu urutan pembuatan kamar berubah.
  const riwayat = await evaluasi(ws, `
    (async () => {
      const headers = {
        Accept: 'application/json',
        Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
      }
      const kamar = await fetch('${API}/kamar', { headers }).then(r => r.json())
      const a2 = kamar.data.find(k => k.nomor === 'A2')
      if (!a2) return { galat: 'kamar A2 tidak ditemukan' }
      const tarif = await fetch('${API}/tarif?kamar_id=' + a2.id, { headers }).then(r => r.json())
      return tarif.data.map(t => ({ nilai: t.nilai, dari: t.berlaku_dari }))
    })().catch(e => ({ galat: String(e) }))
  `)

  const daftarRiwayat = Array.isArray(riwayat) ? riwayat : []
  lapor('riwayat tarif kamar A2 berisi dua baris, bukan satu yang ditimpa',
    daftarRiwayat.length === 2, JSON.stringify(riwayat))
  lapor('baris tarif lama Rp 2.000.000 masih utuh',
    daftarRiwayat.some((t) => t.nilai === 2000000 && t.dari === '2026-10-01'),
    JSON.stringify(daftarRiwayat))

  const hanyaSatuBerlaku = daftarRiwayat.filter((t) => t.dari <= '2026-10-31')
  lapor('tarif efektif Oktober tetap Rp 2.000.000, bukan tarif November',
    hanyaSatuBerlaku.length >= 1 && hanyaSatuBerlaku[0].nilai === 2000000)

  // --- Tangkapan layar -----------------------------------------------------
  for (const [nama, url] of [
    ['T2-kamar', `${APP}/pemilik/kamar`],
    ['T2-penghuni', `${APP}/pemilik/penghuni`],
    ['T2-kontrak', `${APP}/pemilik/kontrak`],
  ]) {
    await kirim(ws, 'Page.navigate', { url })
    await new Promise((r) => setTimeout(r, 1100))
    const tangkapan = await kirim(ws, 'Page.captureScreenshot', { format: 'png' })
    const { writeFileSync } = await import('node:fs')
    writeFileSync(`docs/uji/${nama}.png`, Buffer.from(tangkapan.data, 'base64'))
  }
  lapor('tiga tangkapan layar tersimpan di docs/uji', true)

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
