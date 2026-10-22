// Verifikasi end-to-end T7 lewat browser sungguhan.
//
// Yang dibuktikan: dashboard tunggakan memisahkan tagihan ke empat kelompok umur
// dengan benar, dan angka di dashboard **identik** dengan endpoint denda untuk
// tagihan yang sama. Kesamaan itu kriteria penerimaan tiket, bukan tambahan.
//
// Pakai: node docs/uji/e2e-t7.mjs
// Prasyarat: docker compose up sudah jalan, Chrome --remote-debugging-port=9222,
// APP_DEMO_MODE=true, dan database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo.

const CDP_BASE = process.env.CDP_BASE || 'http://127.0.0.1:9222'
const APP = process.env.APP_URL || 'http://localhost:3000'
const API = process.env.API_URL || 'http://localhost:8081/api'

// Tanggal acuan seluruh pengujian. Dipilih supaya keempat kelompok terisi
// dengan selisih hari yang jelas.
const ACUAN = '2026-11-21'

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

/**
 * Tagihan uji yang harus masuk ke tiap kelompok pada tanggal acuan 2026-11-21.
 *
 * Jatuh tempo ditentukan oleh tanggal masuk kontrak, jadi tiap kontrak memakai
 * tanggal masuk yang berbeda. Periode menentukan bulan tagihan.
 *
 *   belum jatuh tempo : jatuh tempo 15 Des 2026 → −24 hari
 *   0-30              : jatuh tempo  1 Nov 2026 →  20 hari
 *   31-60             : jatuh tempo  1 Okt 2026 →  51 hari
 *   lebih dari 60     : jatuh tempo  1 Agu 2026 → 112 hari
 */
const SKENARIO = [
  { kamar: 'T7-A', nama: 'Ani Belum Jatuh Tempo', masuk: '2026-01-15', periode: '2026-12-01', kelompok: 'belum_jatuh_tempo', hari: -24 },
  { kamar: 'T7-B', nama: 'Budi Baru Terlambat', masuk: '2026-01-01', periode: '2026-11-01', kelompok: '0_30', hari: 20 },
  { kamar: 'T7-C', nama: 'Citra Perlu Ditagih', masuk: '2026-01-01', periode: '2026-10-01', kelompok: '31_60', hari: 51 },
  { kamar: 'T7-D', nama: 'Dedi Sudah Lama', masuk: '2026-01-01', periode: '2026-08-01', kelompok: 'lebih_60', hari: 112 },
]

async function jalan() {
  const tab = await targetBaru()
  const ws = await hubungkan(tab.webSocketDebuggerUrl)

  await kirim(ws, 'Page.enable')
  await kirim(ws, 'Runtime.enable')

  // --- Login ---------------------------------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
  await tungguSampai(ws, `document.querySelector('#email')`)
  await evaluasi(ws, `
    (() => {
      const set = (sel, v) => {
        const el = document.querySelector(sel)
        el.value = v
        el.dispatchEvent(new Event('input', { bubbles: true }))
      }
      set('#email', 'pemilik@kos.test')
      set('#password', 'password')
      document.querySelector('form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      return true
    })()
  `)
  const masuk = await tungguSampai(ws, `location.pathname.startsWith('/pemilik')`)
  lapor('login pemilik berhasil', Boolean(masuk), await evaluasi(ws, 'location.pathname'))
  if (!masuk) throw new Error('login gagal, sisa langkah tidak bisa dijalankan')

  // --- Siapkan empat kontrak + tagihan -------------------------------------
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

      const hasil = []
      for (const s of ${JSON.stringify(SKENARIO)}) {
        const kamar = await panggil('/kamar', 'POST', { nomor: s.kamar, tipe: 'Uji T7' })
        if (kamar.status !== 201) return { galat: 'kamar ' + s.kamar + ': ' + JSON.stringify(kamar.data) }

        const tarif = await panggil('/tarif', 'POST', {
          kamar_id: kamar.data.data.id, nilai: 1500000, berlaku_dari: '2026-01-01',
        })
        if (tarif.status !== 201) return { galat: 'tarif ' + s.kamar + ': ' + JSON.stringify(tarif.data) }

        const penghuni = await panggil('/penghuni', 'POST', {
          nama: s.nama, email: s.kamar.toLowerCase() + '@kos.test', password: 'rahasia123',
        })
        if (penghuni.status !== 201) return { galat: 'penghuni ' + s.nama + ': ' + JSON.stringify(penghuni.data) }

        const kontrak = await panggil('/kontrak', 'POST', {
          kamar_id: kamar.data.data.id,
          penghuni_id: penghuni.data.data.id,
          tgl_masuk: s.masuk,
          deposit: 0,
        })
        if (kontrak.status !== 201) return { galat: 'kontrak ' + s.nama + ': ' + JSON.stringify(kontrak.data) }

        const tagihan = await panggil('/tagihan', 'POST', {
          kontrak_id: kontrak.data.data.id, periode: s.periode,
        })
        if (tagihan.status !== 201) return { galat: 'tagihan ' + s.nama + ': ' + JSON.stringify(tagihan.data) }

        hasil.push({
          kamar: s.kamar,
          nama: s.nama,
          kelompok: s.kelompok,
          hari: s.hari,
          tagihanId: tagihan.data.data.id,
          jatuhTempo: tagihan.data.data.jatuh_tempo,
          pokok: tagihan.data.data.pokok,
        })
      }
      return { hasil }
    })()
  `)
  lapor('empat tagihan uji siap', !siap.galat, siap.galat || `${siap.hasil?.length} tagihan`)
  if (siap.galat) throw new Error('data uji gagal disiapkan: ' + siap.galat)

  for (const s of siap.hasil) {
    lapor(`jatuh tempo ${s.nama} = ${s.jatuhTempo}`, Boolean(s.jatuhTempo), String(s.jatuhTempo))
  }

  // --- Dashboard tunggakan lewat API ---------------------------------------
  const panggilTunggakan = (tanggal) => `
    (async () => {
      const headers = {
        Accept: 'application/json',
        Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
      }
      if (${JSON.stringify(tanggal)} !== null) headers['X-Simulasi-Tanggal'] = ${JSON.stringify(tanggal)}
      const res = await fetch('${API}/tunggakan', { headers })
      const isi = await res.json()
      return { status: res.status, data: isi.data }
    })()
  `

  const tunggakan = await evaluasi(ws, panggilTunggakan(ACUAN))
  lapor('GET /tunggakan berhasil', tunggakan.status === 200, String(tunggakan.status))

  const kelompok = tunggakan.data?.kelompok || []
  lapor('respons berisi empat kelompok', kelompok.length === 4, String(kelompok.length))
  lapor(
    'urutan kelompok dari ringan ke parah',
    JSON.stringify(kelompok.map((k) => k.kunci)) ===
      JSON.stringify(['belum_jatuh_tempo', '0_30', '31_60', 'lebih_60']),
    JSON.stringify(kelompok.map((k) => k.kunci)),
  )

  // Tiap tagihan harus berada tepat di kelompok yang diharapkan, dengan hari
  // terlambat yang tepat. Kalau satu hari bergeser, kelompoknya ikut bergeser.
  for (const s of siap.hasil) {
    const wadah = kelompok.find((k) => k.kunci === s.kelompok)
    const baris = wadah?.tagihan?.find((t) => t.id === s.tagihanId)

    lapor(
      `${s.nama} masuk kelompok ${s.kelompok} (terlambat ${s.hari} hari)`,
      Boolean(baris) && baris.hari_terlambat === s.hari,
      baris ? `terlambat ${baris.hari_terlambat}` : 'tidak ditemukan di kelompok itu',
    )
  }

  // --- Angka dashboard identik dengan endpoint denda -----------------------
  // Kriteria penerimaan tiket. Diperiksa untuk setiap tagihan, bukan hanya satu.
  for (const s of siap.hasil) {
    const hasil = await evaluasi(ws, `
      (async () => {
        const headers = {
          Accept: 'application/json',
          Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
          'X-Simulasi-Tanggal': '${ACUAN}',
        }
        const res = await fetch('${API}/tagihan/${s.tagihanId}/denda', { headers })
        const isi = await res.json()
        return isi.data
      })()
    `)

    const wadah = kelompok.find((k) => k.kunci === s.kelompok)
    const baris = wadah?.tagihan?.find((t) => t.id === s.tagihanId)

    lapor(
      `denda ${s.nama} sama di dashboard dan endpoint denda`,
      Boolean(baris) &&
        baris.denda === hasil.denda &&
        baris.pokok === hasil.pokok &&
        baris.total === hasil.pokok + hasil.denda,
      baris ? `dashboard ${baris.denda}, denda ${hasil.denda}` : 'baris tidak ada',
    )
  }

  // --- Kelompok kosong tetap dikembalikan ----------------------------------
  // Dengan tanggal acuan jauh di masa lalu, keempat tagihan belum jatuh tempo:
  // semuanya masuk kelompok pertama dan tiga kelompok sisanya kosong. Ini
  // sekaligus membuktikan kelompok kosong tetap dikirim, dan bahwa "belum jatuh
  // tempo" benar-benar terpisah dari 0-30 — bukan sekadar wadah sementara.
  const masaLalu = await evaluasi(ws, panggilTunggakan('2020-01-01'))
  const jumlahMasaLalu = (masaLalu.data?.kelompok || []).map((k) => k.jumlah)
  lapor(
    'tanggal acuan di masa lalu: semua masuk belum jatuh tempo, tiga kelompok kosong',
    (masaLalu.data?.kelompok || []).length === 4 &&
      jumlahMasaLalu[0] === 4 &&
      jumlahMasaLalu.slice(1).every((n) => n === 0),
    JSON.stringify(jumlahMasaLalu),
  )

  // --- Tampilan ------------------------------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tunggakan` })
  await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Tunggakan')`)

  const adaMenu = await tungguSampai(ws, `
    [...document.querySelectorAll('nav a')].some(a => a.textContent.includes('Tunggakan'))
  `, 6000)
  lapor('menu Tunggakan ada di navigasi pemilik', Boolean(adaMenu))

  // Geser tanggal lewat UI, bukan lewat API — buktikan kendalinya bekerja.
  const digeser = await evaluasi(ws, `
    (async () => {
      const input = document.querySelector('.simulasi-panel input[type="date"]')
      if (!input) return 'input tidak ada'
      input.value = '${ACUAN}'
      input.dispatchEvent(new Event('input', { bubbles: true }))
      await new Promise(r => setTimeout(r, 100))
      const tombol = [...document.querySelectorAll('.simulasi-panel button')]
        .find(b => /terapkan/i.test(b.textContent))
      if (!tombol) return 'tombol tidak ada'
      tombol.click()
      await new Promise(r => setTimeout(r, 1500))
      return 'diterapkan'
    })()
  `)
  lapor('tanggal acuan dapat digeser lewat UI', digeser === 'diterapkan', String(digeser))

  // Empat kartu, masing-masing berisi 1 tagihan.
  const kartu = await evaluasi(ws, `
    (() => {
      const daftar = [...document.querySelectorAll('.kartu-kelompok')]
      return daftar.map(k => ({
        judul: k.querySelector('.kelompok-judul')?.textContent.trim(),
        angka: k.querySelector('.kelompok-angka')?.textContent.trim(),
      }))
    })()
  `)
  lapor('empat kartu kelompok tampil', kartu.length === 4, JSON.stringify(kartu))
  lapor(
    'tiap kartu menampilkan jumlah 1',
    kartu.length === 4 && kartu.every((k) => k.angka === '1'),
    JSON.stringify(kartu.map((k) => k.angka)),
  )

  // Kontras teks kartu. Kartu memakai <button>, jadi ia mewarisi `color: #fff`
  // dari gaya tombol global — teks putih di atas kartu putih. Tidak ada asersi
  // teks yang menangkap ini karena DOM-nya benar; hanya warnanya yang salah.
  const kontras = await evaluasi(ws, `
    (() => {
      const kartu = [...document.querySelectorAll('.kartu-kelompok')]
      return kartu.map(k => {
        const anak = [...k.querySelectorAll('.kelompok-judul, .kelompok-angka, .kelompok-total, .kelompok-ket')]
        return anak.map(a => getComputedStyle(a).color)
      })
    })()
  `)
  const putih = (w) => w === 'rgb(255, 255, 255)'
  lapor(
    'tidak ada teks kartu yang berwarna putih di atas kartu putih',
    kontras.length === 4 && kontras.every((baris) => baris.every((w) => !putih(w))),
    JSON.stringify(kontras[0]),
  )

  // Nama penghuni harus muncul di bagian kelompoknya masing-masing.
  for (const s of siap.hasil) {
    const ada = await evaluasi(ws, `
      (() => {
        const bagian = [...document.querySelectorAll('section.bagian-kelompok')]
        return bagian.some(b => b.textContent.includes(${JSON.stringify(s.nama)}))
      })()
    `)
    lapor(`${s.nama} tampil di daftar tunggakan`, Boolean(ada))
  }

  // Kolom yang diminta tiket harus ada di tabel.
  const kolom = await evaluasi(ws, `
    (() => {
      const tabel = document.querySelector('section.bagian-kelompok table')
      if (!tabel) return null
      return [...tabel.querySelectorAll('thead th')].map(th => th.textContent.trim())
    })()
  `)
  lapor(
    'tabel memuat kolom penghuni, kamar, periode, pokok, denda, total, terlambat',
    JSON.stringify(kolom) ===
      JSON.stringify(['Penghuni', 'Kamar', 'Periode', 'Pokok', 'Denda', 'Total', 'Terlambat']),
    JSON.stringify(kolom),
  )

  // Saringan per kelompok: menekan kartu menyaring daftar.
  const disaring = await evaluasi(ws, `
    (async () => {
      const kartu = [...document.querySelectorAll('.kartu-kelompok')]
      const berat = kartu.find(k => k.textContent.includes('Lebih dari 60'))
      if (!berat) return 'kartu tidak ada'
      berat.click()
      await new Promise(r => setTimeout(r, 600))
      const bagian = [...document.querySelectorAll('section.bagian-kelompok')]
      return {
        jumlahBagian: bagian.length,
        teks: bagian.map(b => b.textContent).join(' '),
      }
    })()
  `)
  lapor(
    'menekan kartu menyaring daftar ke satu kelompok',
    disaring.jumlahBagian === 1 && disaring.teks.includes('Dedi Sudah Lama'),
    `bagian ${disaring.jumlahBagian}`,
  )

  const dibatalkan = await evaluasi(ws, `
    (async () => {
      const tombol = [...document.querySelectorAll('button')]
        .find(b => /tampilkan semua/i.test(b.textContent))
      if (!tombol) return 'tombol tidak ada'
      tombol.click()
      await new Promise(r => setTimeout(r, 600))
      return document.querySelectorAll('section.bagian-kelompok').length
    })()
  `)
  lapor('saringan dapat dibatalkan kembali ke empat kelompok', dibatalkan === 4, String(dibatalkan))

  // --- Tangkapan layar -----------------------------------------------------
  const tangkapan = await kirim(ws, 'Page.captureScreenshot', { format: 'png' })
  const { writeFileSync } = await import('node:fs')
  writeFileSync('docs/uji/T7-tunggakan.png', Buffer.from(tangkapan.data, 'base64'))
  lapor('tangkapan layar tersimpan di docs/uji', true)

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
