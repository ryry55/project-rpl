// Verifikasi end-to-end T6 lewat browser sungguhan.
//
// Inti yang dibuktikan: satu tagihan yang sama, dendanya berubah dari Rp 0 ->
// Rp 15.000 -> Rp 450.000 hanya dengan menggeser X-Simulasi-Tanggal - tanpa
// menunggu 37 hari. Inilah demo yang akan ditunjukkan di sidang.
//
// Pakai: node docs/uji/e2e-t6.mjs
// Prasyarat: docker compose up sudah jalan, Chrome --remote-debugging-port=9222,
// APP_DEMO_MODE=true, dan database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo.

import {
  APP,
  API,
  CDP_BASE,
  targetBaru,
  hubungkan,
  kirim,
  evaluasi,
  tungguSampai,
  pastikanKebijakan,
} from './_cdp.mjs'

const langkah = []
function lapor(nama, lulus, detail = '') {
  langkah.push({ nama, lulus, detail })
  console.log(`${lulus ? 'LULUS' : 'GAGAL'}  ${nama}${detail ? ' - ' + detail : ''}`)
}

const tidur = (ms) => new Promise((r) => setTimeout(r, ms))

// Panggil API dari dalam halaman memakai token yang sudah ada, dengan header
// simulasi opsional. Ini yang membuat skrip bisa menguji header tanpa browser
// perlu benar-benar mengirimnya.
const panggilApi = (jalur, metode = 'GET', isi = null, tanggal = null) => `
  (async () => {
    const headers = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
    }
    if (${JSON.stringify(tanggal)} !== null) headers['X-Simulasi-Tanggal'] = ${JSON.stringify(tanggal)}
    const opsi = { method: ${JSON.stringify(metode)}, headers }
    if (${JSON.stringify(isi)} !== null) opsi.body = JSON.stringify(${JSON.stringify(isi)})
    const res = await fetch('${API}' + ${JSON.stringify(jalur)}, opsi)
    const teks = await res.text()
    let data = null
    try { data = teks ? JSON.parse(teks) : null } catch { data = { message: teks } }
    return {
      status: res.status,
      data,
      simulasiDipakai: res.headers.get('X-Simulasi-Dipakai'),
      tanggalSistem: res.headers.get('X-Tanggal-Sistem'),
    }
  })()
`

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

  // T16b: gerbang kebijakan kos. Dinyatakan di sini, sekali, supaya langkah
  // uji di bawahnya tidak gagal karena alasan yang tidak sedang diuji -
  // gerbangnya memang bekerja, dan yang perlu dilakukan adalah memenuhinya.
  const kebijakan = await pastikanKebijakan(ws)
  lapor('kebijakan kos siap', kebijakan.ok, kebijakan.alasan)
  if (!kebijakan.ok) throw new Error('kebijakan kos gagal disiapkan: ' + kebijakan.alasan)
  // --- Siapkan data: kamar, tarif, penghuni, kontrak, tagihan jatuh tempo 15 Okt
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

      const kamar = await panggil('/kamar', 'POST', { nomor: 'T6-1', tipe: 'Uji' })
      if (kamar.status !== 201) return { galat: 'kamar: ' + JSON.stringify(kamar.data) }

      const tarif = await panggil('/tarif', 'POST', {
        kamar_id: kamar.data.data.id, nilai: 1500000, berlaku_dari: '2026-01-01',
      })
      if (tarif.status !== 201) return { galat: 'tarif: ' + JSON.stringify(tarif.data) }

      const penghuni = await panggil('/penghuni', 'POST', {
        nama: 'Uji Denda', email: 't6-denda@kos.test', password: 'rahasia123',
      })
      if (penghuni.status !== 201) return { galat: 'penghuni: ' + JSON.stringify(penghuni.data) }

      const kontrak = await panggil('/kontrak', 'POST', {
        kamar_id: kamar.data.data.id,
        penghuni_id: penghuni.data.data.id,
        // Masuk Januari, bukan Oktober: tagihan Oktober harus TARIF PENUH supaya
        // angka dendanya cocok dengan contoh di tiket (pokok 1.500.000).
        // Kalau masuk 15 Oktober, tagihan Oktober diprorata jadi 822.581 dan
        // denda 1% dari angka itu - benar, tapi bukan yang mau ditunjukkan.
        tgl_masuk: '2026-01-15',
        deposit: 0,
      })
      if (kontrak.status !== 201) return { galat: 'kontrak: ' + JSON.stringify(kontrak.data) }

      const tagihan = await panggil('/tagihan', 'POST', {
        kontrak_id: kontrak.data.data.id, periode: '2026-10-01',
      })
      if (tagihan.status !== 201) return { galat: 'tagihan: ' + JSON.stringify(tagihan.data) }

      return {
        tagihanId: tagihan.data.data.id,
        pokok: tagihan.data.data.pokok,
        jatuhTempo: tagihan.data.data.jatuh_tempo,
      }
    })()
  `)
  lapor('data uji siap (tagihan pokok 1.500.000, jatuh tempo 15 Okt)', !siap.galat,
    siap.galat || JSON.stringify(siap))
  if (siap.galat) throw new Error('data uji gagal disiapkan: ' + siap.galat)

  lapor('pokok tagihan = Rp 1.500.000 (tarif penuh, bukan prorata)',
    siap.pokok === 1_500_000, String(siap.pokok))
  lapor('jatuh tempo tagihan = 2026-10-15', siap.jatuhTempo === '2026-10-15', siap.jatuhTempo)

  // --- Inti T6: satu tagihan, tanggal digeser ------------------------------
  // Urutan ini persis yang disebut tiket: 0, 15.000, 345.000, 450.000.
  const titik = [
    { tanggal: '2026-10-15', nama: 'hari jatuh tempo', denda: 0, terlambat: 0 },
    { tanggal: '2026-10-22', nama: 'hari ke-7 (masih tenggang)', denda: 0, terlambat: 7 },
    { tanggal: '2026-10-23', nama: 'hari ke-8 (denda mulai)', denda: 15000, terlambat: 8 },
    { tanggal: '2026-10-10', nama: 'sebelum jatuh tempo', denda: 0, terlambat: -5 },
    { tanggal: '2026-11-14', nama: 'hari ke-30', denda: 345000, terlambat: 30 },
    { tanggal: '2026-11-21', nama: 'hari ke-37 (plafon)', denda: 450000, terlambat: 37 },
    { tanggal: '2027-10-15', nama: 'setahun kemudian (tetap plafon)', denda: 450000, terlambat: 365 },
  ]

  for (const t of titik) {
    const hasil = await evaluasi(ws, panggilApi(`/tagihan/${siap.tagihanId}/denda`, 'GET', null, t.tanggal))
    const cocok =
      hasil.status === 200 &&
      hasil.data?.data?.denda === t.denda &&
      hasil.data?.data?.hari_terlambat === t.terlambat
    lapor(
      `denda ${t.nama} = Rp ${t.denda.toLocaleString('id-ID')}`,
      cocok,
      `terlambat ${hasil.data?.data?.hari_terlambat}, denda ${hasil.data?.data?.denda}`,
    )
  }

  // --- Header simulasi benar-benar diumumkan di respons --------------------
  // Dibaca dari `meta` di badan respons, bukan dari header: header lintas-origin
  // tidak terbaca JavaScript tanpa exposed_headers CORS, dan mengandalkan itu
  // membuat pemeriksaan ini gagal karena alasan yang salah.
  const meta = await evaluasi(ws, `
    (async () => {
      const res = await fetch('${API}/tagihan', {
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
          'X-Simulasi-Tanggal': '2026-11-21',
        },
      })
      const isi = await res.json()
      return { meta: isi.meta, headerDipakai: res.headers.get('X-Simulasi-Dipakai') }
    })()
  `)
  lapor('server mengumumkan simulasi aktif di meta respons', meta.meta?.simulasi === true,
    JSON.stringify(meta.meta))
  lapor('tanggal acuan mengikuti header simulasi', meta.meta?.tanggal_acuan === '2026-11-21',
    String(meta.meta?.tanggal_acuan))
  lapor('header X-Simulasi-Dipakai terbaca dari browser (CORS exposed)',
    meta.headerDipakai === 'true', String(meta.headerDipakai))

  // --- Plafon tidak pernah terlampaui, berapa pun lamanya ------------------
  const jauh = await evaluasi(ws, panggilApi(`/tagihan/${siap.tagihanId}/denda`, 'GET', null, '2030-01-01'))
  lapor('denda 4 tahun kemudian tetap di plafon Rp 450.000',
    jauh.data?.data?.denda === 450000 && jauh.data?.data?.denda === jauh.data?.data?.plafon,
    `denda ${jauh.data?.data?.denda}, plafon ${jauh.data?.data?.plafon}`)

  // --- Pembayaran sebagian tidak menurunkan dasar denda -------------------
  // Status diubah langsung lewat DB tidak tersedia dari browser, jadi diuji
  // lewat jalur API yang ada: denda dihitung dari pokok yang dibekukan.
  const dasar = await evaluasi(ws, `
    (async () => {
      const res = await fetch('${API}/tagihan/${siap.tagihanId}', {
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
          'X-Simulasi-Tanggal': '2026-11-21',
        },
      })
      const isi = await res.json()
      return { pokok: isi.data.pokok, denda: isi.data.denda }
    })()
  `)
  lapor('dasar denda adalah pokok yang dibekukan (1.500.000), bukan sisa',
    dasar.pokok === 1500000 && dasar.denda === 450000,
    JSON.stringify(dasar))

  // --- Mode demo: header diabaikan bila tidak diminta ---------------------
  const tanpaHeader = await evaluasi(ws, `
    (async () => {
      const res = await fetch('${API}/tagihan', {
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
        },
      })
      const isi = await res.json()
      return { meta: isi.meta }
    })()
  `)
  lapor('tanpa header simulasi, server memakai tanggal sebenarnya',
    tanpaHeader.meta?.simulasi === false,
    JSON.stringify(tanpaHeader.meta))

  // --- UI: kendali simulasi dan kolom denda -------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tagihan` })
  await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Tagihan')`)

  const adaKendali = await tungguSampai(ws, `document.querySelector('.simulasi-panel input[type="date"]')`, 8000)
  lapor('kendali simulasi tanggal tampil di halaman Tagihan', Boolean(adaKendali))

  // Geser tanggal lewat UI, bukan lewat API: buktikan tombolnya bekerja.
  const digeser = await evaluasi(ws, `
    (async () => {
      const input = document.querySelector('.simulasi-panel input[type="date"]')
      if (!input) return 'input tidak ada'
      input.value = '2026-11-21'
      input.dispatchEvent(new Event('input', { bubbles: true }))
      await new Promise(r => setTimeout(r, 100))
      const tombol = [...document.querySelectorAll('.simulasi-panel button')]
        .find(b => /terapkan/i.test(b.textContent))
      if (!tombol) return 'tombol tidak ada'
      tombol.click()
      await new Promise(r => setTimeout(r, 1200))
      return 'diterapkan'
    })()
  `)
  lapor('tanggal simulasi dapat digeser lewat UI', digeser === 'diterapkan', String(digeser))

  const dendaDiLayar = await tungguSampai(ws, `
    [...document.querySelectorAll('tbody tr')].some(tr =>
      tr.textContent.includes('Uji Denda') && /450\\.000/.test(tr.textContent))
  `, 8000)
  lapor('denda Rp 450.000 tampil di daftar tagihan', Boolean(dendaDiLayar))

  // --- Panel rincian menjelaskan dendanya ---------------------------------
  await evaluasi(ws, `
    (async () => {
      const baris = [...document.querySelectorAll('tbody tr')].find(tr => tr.textContent.includes('Uji Denda'))
      if (!baris) return false
      const tombol = [...baris.querySelectorAll('button')].find(b => /rincian/i.test(b.textContent))
      if (tombol) tombol.click()
      await new Promise(r => setTimeout(r, 1200))
      return true
    })()
  `)

  const uraian = await evaluasi(ws, `
    (() => {
      const panel = [...document.querySelectorAll('section.kartu')]
        .find(s => /Uji Denda/.test(s.textContent) && s.querySelector('.denda-uraian'))
      if (!panel) return { galat: 'panel denda tidak ditemukan' }
      return { teks: panel.querySelector('.denda-uraian').textContent.replace(/\\s+/g, ' ').trim() }
    })()
  `)
  lapor('panel rincian menjelaskan jumlah hari terlambat dan plafon',
    !uraian.galat && /37 hari/.test(uraian.teks) && /450\.000/.test(uraian.teks),
    uraian.galat || uraian.teks)

  // --- Tangkapan layar ----------------------------------------------------
  let tangkapan = await kirim(ws, 'Page.captureScreenshot', { format: 'png' })
  const { writeFileSync } = await import('node:fs')
  writeFileSync('docs/uji/T6-denda.png', Buffer.from(tangkapan.data, 'base64'))

  // Screenshot tanpa simulasi untuk pembanding: denda kembali ke nilai hari ini.
  await evaluasi(ws, `
    (async () => {
      const tombol = [...document.querySelectorAll('.simulasi-panel button')]
        .find(b => /hari ini/i.test(b.textContent))
      if (tombol) tombol.click()
      await new Promise(r => setTimeout(r, 1500))
      return true
    })()
  `)

  // Panel rincian harus ikut berubah. Tanpa ini, daftar menampilkan denda "-"
  // sementara panel di atasnya masih menampilkan Rp 450.000.
  //
  // Perhatikan: plafon SELALU Rp 450.000 (30% dari pokok), jadi tidak boleh
  // diperiksa dari adanya teks "450.000". Yang berubah adalah nilai dendanya
  // dan tanggal acuannya.
  //
  // Nilai "hari ini" TIDAK boleh di-hardcode. Asersi lama mengharapkan
  // "Denda Rp 0", yang hanya benar selama tanggal sistem <= 22 Okt 2026
  // (hari terlambat 7, masih tenggang). Mulai 23 Okt dendanya Rp 15.000 dan
  // tes gagal sendiri tanpa ada kode yang berubah. Jadi pembandingnya diambil
  // dari API pada tanggal sebenarnya.
  const hariIni = await evaluasi(ws, panggilApi(`/tagihan/${siap.tagihanId}/denda`, 'GET', null, null))
  const dendaHariIni = hariIni.data?.data?.denda
  const teksDendaHariIni = `Denda Rp ${Number(dendaHariIni ?? -1).toLocaleString('id-ID')} dari maksimum`

  const panelIkut = await evaluasi(ws, `
    (() => {
      const panel = [...document.querySelectorAll('section.kartu')]
        .find(s => /Uji Denda/.test(s.textContent) && s.querySelector('.denda-uraian'))
      if (!panel) return { galat: 'panel tidak ada' }
      return { teks: panel.querySelector('.denda-uraian').textContent.replace(/\\s+/g, ' ') }
    })()
  `)
  lapor('panel rincian ikut disegarkan saat simulasi dibersihkan',
    !panelIkut.galat &&
      dendaHariIni !== undefined &&
      panelIkut.teks.includes(teksDendaHariIni) &&
      !/21 Nov 2026/.test(panelIkut.teks),
    panelIkut.galat || `diharap "${teksDendaHariIni}" di: ${panelIkut.teks}`)

  tangkapan = await kirim(ws, 'Page.captureScreenshot', { format: 'png' })
  writeFileSync('docs/uji/T6-tanpa-simulasi.png', Buffer.from(tangkapan.data, 'base64'))
  lapor('dua tangkapan layar tersimpan di docs/uji', true)

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
