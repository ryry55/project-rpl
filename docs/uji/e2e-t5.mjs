// Verifikasi end-to-end T5 lewat browser sungguhan.
//
// Yang dibuktikan: siklus penuh tagihan -> uang masuk -> status berubah, dengan
// dua pembayaran (sebagian lalu sisa) dan riwayat yang bisa diperiksa ulang.
// Juga bahwa kelebihan bayar ditolak di layar, bukan diam-diam tersimpan.
//
// Pakai: node docs/uji/e2e-t5.mjs
// Prasyarat: docker compose up, Chrome --remote-debugging-port=9222,
// database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo.

// Tanggal acuan simulasi dipakai supaya denda tidak ikut bermain dan angkanya
// bisa diasersikan persis. Tanpa ini, hasil tes bergantung pada hari ia
// dijalankan.
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

const SIMULASI = '2026-11-10'
const PERIODE = '2026-11-01'
const POKOK = 1_500_000

const langkah = []
function lapor(nama, lulus, detail = '') {
  langkah.push({ nama, lulus, detail })
  console.log(`${lulus ? 'LULUS' : 'GAGAL'}  ${nama}${detail ? ' - ' + detail : ''}`)
}

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
  // --- Siapkan kontrak + tagihan lewat API ---------------------------------
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

      const kamar = await panggil('/kamar', 'POST', { nomor: 'T5-A', tipe: 'Uji T5' })
      if (kamar.status !== 201) return { galat: 'kamar: ' + JSON.stringify(kamar.data) }

      const tarif = await panggil('/tarif', 'POST', {
        kamar_id: kamar.data.data.id, nilai: ${POKOK}, berlaku_dari: '2026-01-01',
      })
      if (tarif.status !== 201) return { galat: 'tarif: ' + JSON.stringify(tarif.data) }

      const penghuni = await panggil('/penghuni', 'POST', {
        nama: 'Dewi Lima', email: 't5-a@kos.test',
      })
      if (penghuni.status !== 201) return { galat: 'penghuni: ' + JSON.stringify(penghuni.data) }

      const kontrak = await panggil('/kontrak', 'POST', {
        kamar_id: kamar.data.data.id,
        penghuni_id: penghuni.data.data.id,
        tgl_masuk: '2026-01-15',
        deposit: 0,
      })
      if (kontrak.status !== 201) return { galat: 'kontrak: ' + JSON.stringify(kontrak.data) }

      const tagihan = await panggil('/tagihan', 'POST', {
        kontrak_id: kontrak.data.data.id, periode: '${PERIODE}',
      })
      if (tagihan.status !== 201) return { galat: 'tagihan: ' + JSON.stringify(tagihan.data) }

      return { tagihanId: tagihan.data.data.id, pokok: tagihan.data.data.pokok }
    })()
  `)
  lapor('tagihan uji siap', !siap.galat, siap.galat || `tagihan #${siap.tagihanId}`)
  if (siap.galat) throw new Error('data uji gagal disiapkan: ' + siap.galat)

  // --- Buka halaman tagihan dengan simulasi tanggal ------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tagihan` })
  await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Tagihan')`)
  await tungguSampai(ws, `document.querySelector('.tabel tbody tr')`)

  // Aktifkan simulasi supaya denda nol dan tanggal formulir bisa diprediksi.
  const simulasiSiap = await evaluasi(ws, `
    (async () => {
      const kandidat = document.querySelector('input[aria-label="Tanggal simulasi"]')
      if (!kandidat) return 'kendali simulasi tidak ditemukan'
      kandidat.value = '${SIMULASI}'
      kandidat.dispatchEvent(new Event('input', { bubbles: true }))
      await new Promise(r => setTimeout(r, 250))

      const panel = kandidat.closest('section')
      const tombol = [...panel.querySelectorAll('button')]
        .find(b => /terapkan/i.test(b.textContent))
      if (!tombol) return 'tombol terapkan tidak ditemukan'
      tombol.click()
      await new Promise(r => setTimeout(r, 1500))
      return 'aktif'
    })()
  `)
  lapor('kendali simulasi tanggal aktif', simulasiSiap === 'aktif', String(simulasiSiap))

  // --- Buka rincian tagihan ------------------------------------------------
  const rincian = await evaluasi(ws, `
    (async () => {
      const baris = [...document.querySelectorAll('.tabel tbody tr')]
        .find(tr => tr.textContent.includes('Dewi Lima'))
      if (!baris) return { galat: 'baris tagihan tidak ditemukan' }
      baris.querySelector('button').click()
      await new Promise(r => setTimeout(r, 1200))
      const panel = [...document.querySelectorAll('.kartu.panel')]
        .find(p => /Dewi Lima/.test(p.textContent) && p.querySelector('.blok-bayar'))
      if (!panel) return { galat: 'panel rincian tidak muncul' }
      return { teks: panel.textContent.replace(/\\s+/g, ' ').trim() }
    })()
  `)
  lapor(
    'rincian tagihan terbuka dan menampilkan tombol catat pembayaran',
    !rincian.galat && /catat pembayaran/i.test(rincian.teks),
    rincian.galat || rincian.teks.slice(0, 110),
  )

  // --- Pembayaran sebagian -------------------------------------------------
  const sebagian = await evaluasi(ws, `
    (async () => {
      const tombol = [...document.querySelectorAll('.blok-bayar button')]
        .find(b => /catat pembayaran/i.test(b.textContent))
      if (!tombol) return { galat: 'tombol catat pembayaran tidak ada' }
      tombol.click()
      await new Promise(r => setTimeout(r, 400))

      const jumlah = document.querySelector('#bayar-jumlah')
      if (!jumlah) return { galat: 'form pembayaran tidak muncul' }

      // Nilai awal harus sisa penuh: itulah yang paling sering dibayar.
      const nilaiAwal = jumlah.value

      jumlah.value = '500000'
      jumlah.dispatchEvent(new Event('input', { bubbles: true }))
      const tanggal = document.querySelector('#bayar-tanggal')
      tanggal.value = '${SIMULASI}'
      tanggal.dispatchEvent(new Event('input', { bubbles: true }))
      // Metode wajib sejak T20 dan sengaja tanpa nilai bawaan, jadi skrip ini
      // harus memilihnya sendiri. Memilih 'tunai' menjaga langkah-langkah di
      // bawah tetap menguji hal yang sama seperti sebelumnya: siklus tagihan ->
      // uang masuk -> status berubah, bukan aturan metode.
      const metode = document.querySelector('#bayar-metode')
      metode.value = 'tunai'
      metode.dispatchEvent(new Event('change', { bubbles: true }))
      await new Promise(r => setTimeout(r, 250))

      document.querySelector('.form-bayar').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      await new Promise(r => setTimeout(r, 2500))

      const panel = [...document.querySelectorAll('.kartu.panel')]
        .find(p => /Dewi Lima/.test(p.textContent))
      return {
        nilaiAwal,
        teks: panel ? panel.textContent.replace(/\\s+/g, ' ').trim() : '',
        sukses: Boolean(panel?.querySelector('.pesan-sukses')),
      }
    })()
  `)
  lapor(
    'form pembayaran terisi sisa penuh sebagai nilai awal',
    sebagian.nilaiAwal === String(POKOK),
    `nilai awal ${sebagian.nilaiAwal}`,
  )
  lapor(
    'pembayaran sebagian tersimpan dan status berubah',
    !sebagian.galat && sebagian.sukses && /sebagian/i.test(sebagian.teks),
    sebagian.galat || sebagian.teks.slice(0, 150),
  )
  lapor(
    'pesan menyebut status berubah dari belum bayar ke sebagian',
    !sebagian.galat && /belum bayar/i.test(sebagian.teks) && /menjadi/i.test(sebagian.teks),
    sebagian.galat || '',
  )
  lapor(
    'sisa setelah pembayaran sebagian benar',
    !sebagian.galat && /1\.000\.000/.test(sebagian.teks),
    sebagian.galat || '',
  )

  // --- Riwayat pembayaran --------------------------------------------------
  const riwayat = await evaluasi(ws, `
    (() => {
      const panel = [...document.querySelectorAll('.kartu.panel')]
        .find(p => /Dewi Lima/.test(p.textContent))
      const tabel = panel?.querySelector('.tabel-riwayat')
      if (!tabel) return { galat: 'tabel riwayat tidak ada' }
      return {
        baris: [...tabel.querySelectorAll('tbody tr')]
          .map(tr => tr.textContent.replace(/\\s+/g, ' ').trim()),
      }
    })()
  `)
  lapor(
    'riwayat pembayaran menampilkan satu baris',
    !riwayat.galat && riwayat.baris?.length === 1,
    riwayat.galat || JSON.stringify(riwayat.baris),
  )
  lapor(
    'riwayat menyebut jumlah dan tanggal pembayaran',
    !riwayat.galat && /500\.000/.test(riwayat.baris[0] || '') && /10 Nov 2026/.test(riwayat.baris[0] || ''),
    riwayat.galat || String(riwayat.baris?.[0]),
  )

  // --- Kelebihan bayar ditolak --------------------------------------------
  const lebih = await evaluasi(ws, `
    (async () => {
      const tombol = [...document.querySelectorAll('.blok-bayar button')]
        .find(b => /catat pembayaran/i.test(b.textContent))
      if (!tombol) return { galat: 'tombol tidak ada' }
      tombol.click()
      await new Promise(r => setTimeout(r, 400))

      const jumlah = document.querySelector('#bayar-jumlah')
      jumlah.value = '1000001'
      jumlah.dispatchEvent(new Event('input', { bubbles: true }))
      const tanggal = document.querySelector('#bayar-tanggal')
      tanggal.value = '${SIMULASI}'
      tanggal.dispatchEvent(new Event('input', { bubbles: true }))
      // Metode wajib sejak T20; lihat komentar di langkah pembayaran sebagian.
      const metode = document.querySelector('#bayar-metode')
      metode.value = 'tunai'
      metode.dispatchEvent(new Event('change', { bubbles: true }))
      await new Promise(r => setTimeout(r, 250))

      document.querySelector('.form-bayar').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      await new Promise(r => setTimeout(r, 2000))

      const panel = [...document.querySelectorAll('.kartu.panel')]
        .find(p => /Dewi Lima/.test(p.textContent))
      return {
        pesan: panel?.querySelector('.pesan-galat')?.textContent?.trim() || '',
        formMasihTerbuka: Boolean(document.querySelector('.form-bayar')),
        // Pesan harus menyebut angka sisa, bukan "permintaan gagal".
        adaAngka: /1\.000\.000/.test(panel?.querySelector('.pesan-galat')?.textContent || ''),
      }
    })()
  `)
  lapor(
    'kelebihan bayar ditolak dan form tetap terbuka',
    Boolean(lebih.pesan) && lebih.formMasihTerbuka,
    lebih.pesan || 'tidak ada pesan galat',
  )
  lapor(
    'pesan penolakan menyebut sisa dengan angka',
    Boolean(lebih.adaAngka),
    lebih.pesan || '',
  )

  // --- Pelunasan -----------------------------------------------------------
  const lunas = await evaluasi(ws, `
    (async () => {
      const jumlah = document.querySelector('#bayar-jumlah')
      if (!jumlah) return { galat: 'form tertutup' }
      jumlah.value = '1000000'
      jumlah.dispatchEvent(new Event('input', { bubbles: true }))
      await new Promise(r => setTimeout(r, 150))

      document.querySelector('.form-bayar').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      await new Promise(r => setTimeout(r, 2500))

      const panel = [...document.querySelectorAll('.kartu.panel')]
        .find(p => /Dewi Lima/.test(p.textContent))
      return {
        teks: panel ? panel.textContent.replace(/\\s+/g, ' ').trim() : '',
        sukses: Boolean(panel?.querySelector('.pesan-sukses')),
        netral: Boolean(panel?.querySelector('.pesan-netral')),
        // Yang menentukan: tombol pembayaran tidak boleh tersedia lagi.
        adaTombolBayar: [...(panel?.querySelectorAll('.blok-bayar button') || [])]
          .some(b => /catat pembayaran/i.test(b.textContent)),
        adaFormBayar: Boolean(panel?.querySelector('.form-bayar')),
        riwayat: panel?.querySelectorAll('.tabel-riwayat tbody tr').length || 0,
      }
    })()
  `)
  lapor(
    'pelunasan mengubah status menjadi lunas',
    !lunas.galat && lunas.sukses && /lunas/i.test(lunas.teks),
    lunas.galat || lunas.teks.slice(0, 150),
  )
  lapor(
    'riwayat menampilkan dua pembayaran',
    lunas.riwayat === 2,
    `${lunas.riwayat} baris`,
  )
  lapor(
    'tagihan lunas tidak lagi menawarkan pembayaran',
    !lunas.galat && lunas.adaTombolBayar === false && lunas.adaFormBayar === false,
    lunas.galat || `tombol=${lunas.adaTombolBayar} form=${lunas.adaFormBayar}`,
  )

  // --- Tangkapan layar: keadaan lunas dengan riwayat -----------------------
  const tangkapan = await kirim(ws, 'Page.captureScreenshot', { format: 'png' })
  const { writeFileSync } = await import('node:fs')
  writeFileSync('docs/uji/T5-pembayaran.png', Buffer.from(tangkapan.data, 'base64'))
  lapor('tangkapan layar tersimpan', true)

  // --- Status di daftar ikut berubah --------------------------------------
  const diDaftar = await evaluasi(ws, `
    (() => {
      const baris = [...document.querySelectorAll('.tabel tbody tr')]
        .find(tr => tr.textContent.includes('Dewi Lima') && tr.querySelector('.lencana-status'))
      if (!baris) return { galat: 'baris tidak ada' }
      const lencana = baris.querySelector('.lencana-status')
      return {
        status: lencana.textContent.trim(),
        warna: getComputedStyle(lencana).color,
        teks: baris.textContent.replace(/\\s+/g, ' ').trim(),
      }
    })()
  `)
  lapor(
    'daftar menampilkan status lunas',
    !diDaftar.galat && diDaftar.status === 'lunas',
    diDaftar.galat || diDaftar.status,
  )
  lapor(
    'lencana status berwarna, bukan putih di atas putih',
    !diDaftar.galat && diDaftar.warna !== 'rgb(255, 255, 255)',
    diDaftar.galat || diDaftar.warna,
  )
  lapor(
    'daftar menampilkan sisa nol untuk tagihan lunas',
    !diDaftar.galat && /Rp 0/.test(diDaftar.teks),
    diDaftar.galat || diDaftar.teks.slice(0, 140),
  )

  // --- Angka di API sama dengan yang di layar -----------------------------
  const api = await evaluasi(ws, `
    (async () => {
      const res = await fetch('${API}/tagihan/${siap.tagihanId}', {
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
          'X-Simulasi-Tanggal': '${SIMULASI}',
        },
      })
      const isi = await res.json()
      const d = isi.data
      return {
        status: d.status,
        pokok: d.pokok,
        total: d.total,
        dibayar: d.dibayar,
        sisa: d.sisa,
        jumlahPembayaran: d.pembayaran.length,
      }
    })()
  `)
  lapor(
    'API: total = pokok, dibayar = total, sisa = 0',
    api.total === api.pokok && api.dibayar === api.total && api.sisa === 0,
    `total ${api.total}, dibayar ${api.dibayar}, sisa ${api.sisa}`,
  )
  lapor(
    'API: dua pembayaran tercatat',
    api.jumlahPembayaran === 2,
    `${api.jumlahPembayaran} pembayaran`,
  )

  await kirim(ws, 'Page.close').catch(() => {})
  ws.close()

  const gagal = langkah.filter((l) => !l.lulus)
  console.log(`\n${langkah.length - gagal.length}/${langkah.length} langkah lulus`)
  if (gagal.length) process.exitCode = 1
}

jalan().catch(async (e) => {
  console.error('E2E gagal:', e.message)
  // Tab ditutup juga saat gagal. Tanpa ini, tiap kegagalan meninggalkan tab
  // yatim - dan setelah beberapa tab, langkah login mulai gagal karena alasan
  // yang tidak ada hubungannya dengan aplikasi.
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
