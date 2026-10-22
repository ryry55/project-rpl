// Verifikasi end-to-end T9 lewat browser sungguhan.
//
// Yang dibuktikan: isolasi tagihan antar penghuni, dan log audit yang bisa
// dibaca pemilik tetapi tidak bisa diubah siapa pun. Keduanya diuji dari layar,
// bukan hanya lewat API, karena kebocoran yang paling berbahaya adalah yang
// terlihat normal di API tetapi tampil di layar orang yang salah.
//
// Pakai: node docs/uji/e2e-t9.mjs
// Prasyarat: docker compose up, Chrome --remote-debugging-port=9222,
// database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo.

const CDP_BASE = process.env.CDP_BASE || 'http://127.0.0.1:9222'
const APP = process.env.APP_URL || 'http://localhost:3000'
const API = process.env.API_URL || 'http://localhost:8081/api'

const SIMULASI = '2026-11-23'
const PERIODE = '2026-11-01'
const POKOK = 1_500_000

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

/** Login lewat formulir, bukan dengan menanam token — jalur yang dipakai orang. */
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

async function jalan() {
  const tab = await targetBaru()
  const ws = await hubungkan(tab.webSocketDebuggerUrl)

  await kirim(ws, 'Page.enable')
  await kirim(ws, 'Runtime.enable')

  // --- Siapkan dua penghuni, masing-masing satu tagihan --------------------
  const masukPemilik = await masukSebagai(ws, 'pemilik@kos.test')
  lapor('login pemilik berhasil', masukPemilik, await evaluasi(ws, 'location.pathname'))
  if (!masukPemilik) throw new Error('login pemilik gagal')

  const siap = await evaluasi(ws, `
    (async () => {
      const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
      }
      const panggil = async (jalur, metode, isi) => {
        const opsi = { method: metode, headers }
        if (isi) opsi.body = JSON.stringify(isi)
        const res = await fetch('${API}' + jalur, opsi)
        const teks = await res.text()
        let data = null
        try { data = teks ? JSON.parse(teks) : null } catch { data = null }
        return { status: res.status, data }
      }

      const buat = async (nomor, nama, email) => {
        const kamar = await panggil('/kamar', 'POST', { nomor, tipe: 'Uji T9' })
        if (kamar.status !== 201) return { galat: 'kamar ' + nomor + ': ' + JSON.stringify(kamar.data) }

        const tarif = await panggil('/tarif', 'POST', {
          kamar_id: kamar.data.data.id, nilai: ${POKOK}, berlaku_dari: '2026-01-01',
        })
        if (tarif.status !== 201) return { galat: 'tarif ' + nomor + ': ' + JSON.stringify(tarif.data) }

        const penghuni = await panggil('/penghuni', 'POST', {
          nama, email, password: 'password',
        })
        if (penghuni.status !== 201) return { galat: 'penghuni ' + nama + ': ' + JSON.stringify(penghuni.data) }

        const kontrak = await panggil('/kontrak', 'POST', {
          kamar_id: kamar.data.data.id,
          penghuni_id: penghuni.data.data.id,
          tgl_masuk: '2026-01-15',
          deposit: 0,
        })
        if (kontrak.status !== 201) return { galat: 'kontrak ' + nama + ': ' + JSON.stringify(kontrak.data) }

        const tagihan = await panggil('/tagihan', 'POST', {
          kontrak_id: kontrak.data.data.id, periode: '${PERIODE}',
        })
        if (tagihan.status !== 201) return { galat: 'tagihan ' + nama + ': ' + JSON.stringify(tagihan.data) }

        return { tagihanId: tagihan.data.data.id }
      }

      const a = await buat('T9-A', 'Sari Sembilan', 't9-a@kos.test')
      if (a.galat) return a
      const b = await buat('T9-B', 'Bayu Sembilan', 't9-b@kos.test')
      if (b.galat) return b

      return { tagihanA: a.tagihanId, tagihanB: b.tagihanId }
    })()
  `)
  lapor('dua penghuni dengan satu tagihan masing-masing siap', !siap.galat, siap.galat || `#${siap.tagihanA} dan #${siap.tagihanB}`)
  if (siap.galat) throw new Error('data uji gagal disiapkan: ' + siap.galat)

  // --- Portal penghuni: hanya tagihannya sendiri ---------------------------
  const masukA = await masukSebagai(ws, 't9-a@kos.test')
  lapor('login penghuni A berhasil', masukA, await evaluasi(ws, 'location.pathname'))
  if (!masukA) throw new Error('login penghuni gagal')

  // Tanggal simulasi disimpan di localStorage supaya portal ikut menghitung
  // denda terhadap tanggal demo yang sama dengan layar pemilik.
  await evaluasi(ws, `
    (() => {
      localStorage.setItem('kos.simulasiTanggal', '${SIMULASI}')
      return true
    })()
  `)
  await kirim(ws, 'Page.navigate', { url: `${APP}/penghuni` })

  const portal = await tungguSampai(ws, `document.querySelector('.tabel tbody tr')`)
  lapor('portal penghuni menampilkan daftar tagihan', portal)

  const isiPortal = await evaluasi(ws, `
    (() => {
      const baris = [...document.querySelectorAll('.tabel tbody tr')]
        .filter(tr => tr.querySelector('.lencana-status'))
      const lencana = baris[0]?.querySelector('.lencana-status')
      return {
        jumlah: baris.length,
        teks: document.querySelector('.tabel')?.textContent.replace(/\\s+/g, ' ').trim() || '',
        warna: lencana ? getComputedStyle(lencana).color : '',
        latar: lencana ? getComputedStyle(lencana).backgroundColor : '',
      }
    })()
  `)
  lapor(
    'penghuni A hanya melihat satu tagihan',
    isiPortal.jumlah === 1,
    `${isiPortal.jumlah} baris`,
  )
  lapor(
    'yang tampil adalah tagihan penghuni A, bukan milik B',
    /Sari Sembilan|T9-A/.test(isiPortal.teks) && !/Bayu Sembilan|T9-B/.test(isiPortal.teks),
    isiPortal.teks.slice(0, 130),
  )
  lapor(
    'lencana status berwarna, bukan putih di atas putih',
    isiPortal.warna !== 'rgb(255, 255, 255)' && isiPortal.latar !== 'rgb(255, 255, 255)',
    `${isiPortal.warna} di atas ${isiPortal.latar}`,
  )

  // --- Rincian: denda dan riwayat muncul di layar --------------------------
  const rincian = await evaluasi(ws, `
    (async () => {
      const tombol = [...document.querySelectorAll('.tabel tbody button')]
        .find(b => /rincian/i.test(b.textContent))
      if (!tombol) return { galat: 'tombol rincian tidak ada' }
      tombol.click()
      await new Promise(r => setTimeout(r, 1600))
      const panel = document.querySelector('.rincian')
      if (!panel) return { galat: 'rincian tidak terbuka' }
      return { teks: panel.textContent.replace(/\\s+/g, ' ').trim() }
    })()
  `)
  lapor(
    'rincian menampilkan denda dan riwayat pembayaran',
    !rincian.galat && /Denda/i.test(rincian.teks) && /Riwayat pembayaran/i.test(rincian.teks),
    rincian.galat || rincian.teks.slice(0, 140),
  )

  // Denda 15.000 (hari ke-8, 1% dari 1.500.000) harus terlihat di rincian.
  // Regex ditulis dengan satu backslash: skrip ini JavaScript biasa, bukan
  // string yang di-escape. `\\d` di sini berarti "backslash literal", kesalahan
  // yang sudah tercatat di T5 dan T6.
  lapor(
    'rincian menyebut angka denda yang benar',
    !rincian.galat && /15\.000/.test(rincian.teks),
    rincian.galat || rincian.teks.slice(0, 140),
  )

  // --- Tangkapan layar portal ---------------------------------------------
  const potretPortal = await kirim(ws, 'Page.captureScreenshot', { format: 'png' })
  const { writeFileSync } = await import('node:fs')
  writeFileSync('docs/uji/T9-portal-penghuni.png', Buffer.from(potretPortal.data, 'base64'))
  lapor('tangkapan layar portal tersimpan', true)

  // --- Pintu samping: tagihan penghuni lain harus 404 ----------------------
  const silang = await evaluasi(ws, `
    (async () => {
      const h = {
        Accept: 'application/json',
        Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
        'X-Simulasi-Tanggal': '${SIMULASI}',
      }
      const a = await fetch('${API}/tagihan/${siap.tagihanA}', { headers: h })
      const b = await fetch('${API}/tagihan/${siap.tagihanB}', { headers: h })
      const bDenda = await fetch('${API}/tagihan/${siap.tagihanB}/denda', { headers: h })
      const tidakAda = await fetch('${API}/tagihan/999999', { headers: h })
      const daftar = await fetch('${API}/penghuni/tagihan', { headers: h })
      const isi = await daftar.json()
      return {
        milikSendiri: a.status,
        milikOrangLain: b.status,
        dendaOrangLain: bDenda.status,
        tidakAda: tidakAda.status,
        jumlahDiDaftar: (isi.data || []).length,
      }
    })()
  `)
  lapor(
    'penghuni A boleh membaca tagihannya sendiri',
    silang.milikSendiri === 200,
    String(silang.milikSendiri),
  )
  lapor(
    'tagihan penghuni B dijawab 404, bukan 403',
    silang.milikOrangLain === 404,
    String(silang.milikOrangLain),
  )
  lapor(
    'pintu samping /denda juga menutup tagihan orang lain',
    silang.dendaOrangLain === 404,
    String(silang.dendaOrangLain),
  )
  lapor(
    'id yang tidak ada menghasilkan jawaban yang sama persis',
    silang.tidakAda === 404,
    String(silang.tidakAda),
  )
  lapor(
    'daftar portal hanya berisi satu tagihan',
    silang.jumlahDiDaftar === 1,
    `${silang.jumlahDiDaftar} baris`,
  )

  // --- Penghuni ditolak di endpoint pemilik -------------------------------
  const ditolak = await evaluasi(ws, `
    (async () => {
      const h = {
        Accept: 'application/json',
        Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
      }
      const panggil = async (jalur, metode, isi) => {
        const opsi = { method: metode, headers: { ...h, 'Content-Type': 'application/json' } }
        if (isi) opsi.body = JSON.stringify(isi)
        const res = await fetch('${API}' + jalur, opsi)
        return res.status
      }
      return {
        tagihanPemilik: await panggil('/tagihan', 'GET'),
        audit: await panggil('/audit-log', 'GET'),
        kamar: await panggil('/kamar', 'GET'),
        bayar: await panggil('/tagihan/${siap.tagihanA}/pembayaran', 'POST', {
          jumlah: 1000, tanggal_pembayaran: '${SIMULASI}',
        }),
      }
    })()
  `)
  lapor(
    'penghuni ditolak 403 di seluruh endpoint pemilik',
    ditolak.tagihanPemilik === 403 && ditolak.audit === 403 && ditolak.kamar === 403,
    `tagihan=${ditolak.tagihanPemilik} audit=${ditolak.audit} kamar=${ditolak.kamar}`,
  )
  lapor(
    'penghuni tidak bisa mencatat pembayaran',
    ditolak.bayar === 403,
    String(ditolak.bayar),
  )

  // --- Log audit: pemilik membaca, tidak ada tombol mengubah --------------
  const masukLagi = await masukSebagai(ws, 'pemilik@kos.test')
  lapor('login pemilik untuk log audit berhasil', masukLagi)
  if (!masukLagi) throw new Error('login pemilik ulang gagal')

  // Nama aktor diambil dari `/me`, bukan ditulis di skrip: nama pemilik berasal
  // dari seeder, dan asersi yang menghafalnya akan gagal setiap kali data
  // contoh diubah — kegagalan yang tidak berarti apa-apa tentang aplikasi.
  const namaPemilik = await evaluasi(ws, `
    (async () => {
      const res = await fetch('${API}/me', {
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
        },
      })
      const isi = await res.json()
      return isi.data?.nama || ''
    })()
  `)

  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/audit` })
  await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Jejak audit')`)
  const adaLog = await tungguSampai(ws, `document.querySelector('.tabel-audit tbody tr')`)

  const audit = await evaluasi(ws, `
    (() => {
      const baris = [...document.querySelectorAll('.tabel-audit tbody tr')]
        .filter(tr => tr.querySelector('.lencana-aksi'))
      const tombol = [...document.querySelectorAll('.tabel-audit button, .tabel-audit form')]
      return {
        jumlah: baris.length,
        teks: document.querySelector('.tabel-audit')?.textContent.replace(/\\s+/g, ' ').trim() || '',
        adaTombolUbah: tombol.length,
        adaAksi: baris.map(tr => tr.querySelector('.lencana-aksi')?.textContent.trim()),
        aktor: baris.map(tr => tr.querySelector('td:nth-child(2) strong')?.textContent.trim()),
      }
    })()
  `)
  lapor('halaman jejak audit terbuka untuk pemilik', Boolean(adaLog))
  lapor(
    'log berisi kejadian penerbitan tagihan',
    audit.adaAksi.some((a) => /Tagihan diterbitkan/i.test(a || '')),
    audit.adaAksi.join(', '),
  )
  lapor(
    'log menyebut aktor yang melakukannya',
    Boolean(namaPemilik) && audit.aktor.every((a) => a === namaPemilik),
    `aktor: ${[...new Set(audit.aktor)].join(', ') || '(kosong)'} · diharapkan ${namaPemilik}`,
  )
  lapor(
    'tidak ada tombol untuk mengubah atau menghapus log',
    audit.adaTombolUbah === 0,
    `${audit.adaTombolUbah} tombol/form`,
  )

  const potretAudit = await kirim(ws, 'Page.captureScreenshot', { format: 'png' })
  writeFileSync('docs/uji/T9-log-audit.png', Buffer.from(potretAudit.data, 'base64'))
  lapor('tangkapan layar log audit tersimpan', true)

  await kirim(ws, 'Page.close').catch(() => {})
  ws.close()

  const gagal = langkah.filter((l) => !l.lulus)
  console.log(`\n${langkah.length - gagal.length}/${langkah.length} langkah lulus`)
  if (gagal.length) process.exitCode = 1
}

jalan().catch(async (e) => {
  console.error('E2E gagal:', e.message)
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
