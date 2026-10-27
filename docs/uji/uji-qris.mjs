// Verifikasi end-to-end gambar QRIS (utang teknis yang ditutup).
//
// Sampai fase terakhir, `jenis: qris` hanya menyimpan teks: nama merchant dan
// nomor. Padahal QRIS statis **adalah** gambarnya - kode QR tidak bisa dibayar
// dengan mengetik ulang nomornya. Skrip ini membuktikan gambarnya benar-benar
// bisa diunggah pemilik, tersimpan utuh, dan terlihat oleh penghuni.
//
// Yang dibuktikan:
//   1. Pemilik mengunggah PNG lewat formulir; pratinjaunya muncul sebelum
//      disimpan.
//   2. Sesudah disimpan, daftar rekening menyebut gambarnya ada.
//   3. Penghuni melihat gambar itu di portalnya, dan gambarnya benar-benar
//      termuat (bukan ikon rusak) - diambil dengan token lalu jadi blob.
//   4. Gambar pada rekening transfer ditolak, dengan pesan yang bisa dibaca.
//   5. Rekening nonaktif: gambarnya tidak bisa dibuka penghuni.
//
// Pakai: node docs/uji/uji-qris.mjs
// Prasyarat: docker compose up, Chrome --remote-debugging-port=9222,
// database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo.

import {
  APP,
  API,
  mulaiUji,
  masukSebagai,
  panggilApi,
  evaluasi,
  tungguSampai,
  kirim,
  tangkapLayar,
  jalankan,
  pastikanKebijakan,
} from './_cdp.mjs'

// PNG kode QR sungguhan, dibuat `_buat-qr.py` (butuh `segno`).
//
// Sengaja bukan PNG 1x1: gambar 1x1 memang "termuat" menurut `naturalWidth`,
// tetapi tangkapan layarnya tidak membuktikan apa pun kepada orang yang
// melihatnya. Kode QR yang benar-benar bisa dipindai membuat bukti uji bisa
// diperiksa mata, bukan hanya oleh asersi.
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const DIREKTORI = dirname(fileURLToPath(import.meta.url))
const PNG_B64 = readFileSync(join(DIREKTORI, 'qris-uji.png')).toString('base64')

async function main() {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  // --- Masuk sebagai pemilik -----------------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
  await tungguSampai(ws, `document.querySelector('#email')`)
  const masuk = await masukSebagai(ws, 'pemilik@kos.test', 'password')
  lapor('login pemilik berhasil', Boolean(masuk), String(masuk))
  if (!masuk) throw new Error('login gagal, sisa langkah tidak bisa dijalankan')

  const kebijakan = await pastikanKebijakan(ws)
  lapor('kebijakan kos siap', kebijakan.ok, kebijakan.alasan)

  // --- Buka halaman rekening ----------------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/rekening` })
  await tungguSampai(ws, `document.querySelector('#rekening-nama')`)
  // Pilihan jenis dibangun dari `meta` yang dimuat lewat onMounted, jadi
  // formulirnya bisa sudah tampil sementara <option> belum ada. Menunggu
  // #rekening-nama saja membuat langkah berikutnya bisa gagal SENYAP: menulis
  // `pilih.value = 'qris'` pada <select> yang belum punya opsi itu tidak
  // melempar apa pun, nilainya hanya tidak berubah, dan asersi berikutnya
  // melaporkan "medan gambar tidak muncul" - gejala yang menunjuk ke tempat
  // yang salah. Terbukti di CI: lulus di lokal, gagal 5 langkah di runner.
  const opsiSiap = await tungguSampai(
    ws,
    `[...document.querySelectorAll('#rekening-jenis option')].some(o => o.value === 'qris')`,
    15000,
  )
  lapor('pilihan jenis rekening sudah termuat', Boolean(opsiSiap), 'opsi qris tersedia')
  lapor('halaman rekening terbuka', true, 'formulir tambah rekening tersedia')

  // --- Medan gambar hanya muncul untuk QRIS -------------------------------
  //
  // Ini keputusan yang diuji, bukan detail tampilan: rekening transfer tidak
  // punya gambar untuk dipindai, dan server menolaknya. Medan yang selalu
  // tampil akan membuat pemilik mengunggah lalu kecewa.
  const medanAwal = await evaluasi(ws, `
    (() => {
      const ada = Boolean(document.querySelector('#rekening-gambar'))
      const jenis = document.querySelector('#rekening-jenis')?.value
      return { ada, jenis }
    })()
  `)
  lapor(
    'medan gambar tersembunyi saat jenis transfer',
    medanAwal.ada === false,
    `jenis=${medanAwal.jenis}, medan=${medanAwal.ada}`,
  )

  const gantiKeQris = await evaluasi(ws, `
    (async () => {
      const pilih = document.querySelector('#rekening-jenis')
      pilih.value = 'qris'
      pilih.dispatchEvent(new Event('change', { bubbles: true }))
      pilih.dispatchEvent(new Event('input', { bubbles: true }))
      await new Promise(r => setTimeout(r, 500))
      return { ada: Boolean(document.querySelector('#rekening-gambar')) }
    })()
  `)
  lapor(
    'medan gambar muncul saat jenis diganti ke QRIS',
    gantiKeQris.ada === true,
    `medan=${gantiKeQris.ada}`,
  )

  // --- Unggah gambar lewat formulir ---------------------------------------
  //
  // Berkas ditempel ke <input type=file> lewat `DataTransfer`, karena
  // `input.files` tidak bisa diisi langsung. Ini satu-satunya cara menguji
  // unggahan sungguhan dari layar tanpa memanggil API.
  const unggah = await evaluasi(ws, `
    (async () => {
      const b64 = '${PNG_B64}'
      const biner = atob(b64)
      const byte = new Uint8Array(biner.length)
      for (let i = 0; i < biner.length; i++) byte[i] = biner.charCodeAt(i)
      const berkas = new File([byte], 'qris-uji.png', { type: 'image/png' })

      const masukan = document.querySelector('#rekening-gambar')
      if (!masukan) return { galat: 'medan gambar tidak ada' }

      const dt = new DataTransfer()
      dt.items.add(berkas)
      masukan.files = dt.files
      masukan.dispatchEvent(new Event('change', { bubbles: true }))

      await new Promise(r => setTimeout(r, 700))

      // Pratinjau harus muncul SEBELUM disimpan.
      const pratinjau = document.querySelector('.pratinjau-gambar img')

      const isi = (sel, v) => {
        const el = document.querySelector(sel)
        if (!el) return false
        el.value = v
        el.dispatchEvent(new Event('input', { bubbles: true }))
        return true
      }
      isi('#rekening-nama', 'QRIS Uji Gambar')

      document.querySelector('form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))

      await new Promise(r => setTimeout(r, 2600))
      return {
        adaPratinjau: Boolean(pratinjau),
        // Tanda tanya opsional di sini bukan kehati-hatian berlebihan: submit
        // formulir bisa memicu navigasi, dan saat itu document.body sempat
        // null. Nilai ini hanya dipakai untuk pesan kegagalan, jadi tidak
        // sepadan membuat seluruh skrip berhenti karena pesan bantuannya
        // gagal disusun. (Jangan pakai backtick di komentar ini: ia menutup
        // template literal pemanggil.)
        teksHalaman: (document.body?.textContent ?? '').replace(/\\s+/g, ' ').trim().slice(0, 200),
      }
    })()
  `)
  lapor(
    'pratinjau gambar muncul sebelum disimpan',
    !unggah.galat && unggah.adaPratinjau === true,
    unggah.galat || `pratinjau=${unggah.adaPratinjau}`,
  )

  const tersimpan = await evaluasi(ws, `
    (async () => {
      const baris = [...document.querySelectorAll('.tabel tbody tr')]
        .find(tr => tr.textContent.includes('QRIS Uji Gambar'))
      if (!baris) return { galat: 'baris rekening tidak muncul' }
      const teks = baris.textContent.replace(/\\s+/g, ' ').trim()
      return { teks, adaPenanda: /ada gambar qris/i.test(teks) }
    })()
  `)
  lapor(
    'rekening tersimpan dan daftar menyebut gambarnya ada',
    !tersimpan.galat && tersimpan.adaPenanda === true,
    tersimpan.galat || tersimpan.teks?.slice(0, 120),
  )

  // Digulir ke tabel daftar sebelum ditangkap: formulir yang sudah kosong
  // setelah menyimpan tidak membuktikan apa pun, sedangkan baris tabelnya
  // menunjukkan bahwa rekening itu memang tersimpan beserta gambarnya.
  await evaluasi(ws, `
    (async () => {
      const judul = [...document.querySelectorAll('h2, .judul-panel')]
        .find(h => /daftar rekening/i.test(h.textContent))
      if (judul) judul.scrollIntoView({ block: 'start' })
      await new Promise(r => setTimeout(r, 400))
      return true
    })()
  `)
  await tangkapLayar(ws, 'QR-rekening-pemilik')

  // --- Gambar pada rekening transfer ditolak ------------------------------
  //
  // Diuji SELAGI MASIH PEMILIK. Kalau diuji setelah berganti akun, yang
  // dijawab 403 adalah penjagaan peran - bukan penolakan gambar - dan tesnya
  // akan lulus tanpa membuktikan apa pun tentang aturan ini.
  //
  // `panggilApi` mengirim JSON, sedangkan unggahan butuh multipart. Karena itu
  // permintaannya disusun langsung di peramban, dengan token yang sedang aktif.
  const tolakTransfer = await evaluasi(ws, `
    (async () => {
      const b64 = '${PNG_B64}'
      const biner = atob(b64)
      const byte = new Uint8Array(biner.length)
      for (let i = 0; i < biner.length; i++) byte[i] = biner.charCodeAt(i)

      const isi = new FormData()
      isi.append('jenis', 'transfer')
      isi.append('nama', 'BCA Uji Tolak')
      isi.append('nomor', '1234567890')
      isi.append('gambar', new File([byte], 'qris.png', { type: 'image/png' }))

      const res = await fetch('${API}/rekening', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
        },
        body: isi,
      })
      const data = await res.json().catch(() => null)
      return { status: res.status, pesan: data?.message ?? '', galat: data?.errors?.gambar ?? [] }
    })()
  `)
  lapor(
    'gambar pada rekening transfer ditolak (422) dengan pesan yang bisa dibaca',
    tolakTransfer.status === 422 && /gambar hanya berlaku untuk jenis qris/i.test(tolakTransfer.pesan),
    `status ${tolakTransfer.status}: ${tolakTransfer.pesan}`,
  )

  // Rekening nonaktif untuk uji berikutnya, dibuat selagi masih pemilik.
  const nonaktif = await panggilApi(ws, '/rekening', 'POST', {
    jenis: 'qris',
    nama: 'QRIS Nonaktif Uji',
    aktif: false,
  })
  lapor(
    'rekening QRIS nonaktif uji dibuat',
    nonaktif.status === 201,
    `status ${nonaktif.status}`,
  )

  // --- Penghuni melihat gambarnya -----------------------------------------
  //
  // Bagian terpenting: gambar harus benar-benar TERMUAT di portal penghuni.
  // Rute gambar dijaga token, sedangkan <img> tidak bisa mengirim header
  // Authorization - jadi komponennya mengambil lewat fetch lalu membuat blob.
  // Yang diperiksa karena itu `naturalWidth`, bukan sekadar keberadaan <img>.
  //
  // Tab yang sama dipakai ulang, hanya berganti akun. `bersihkanTab()`
  // menutup SEMUA tab dan akan memutus sesi yang sedang berjalan.
  const keluar = await evaluasi(ws, `
    (() => { localStorage.removeItem('kos.token'); return true })()
  `)
  lapor('token pemilik dibersihkan sebelum ganti akun', keluar === true, String(keluar))

  await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
  await tungguSampai(ws, `document.querySelector('#email')`)
  const masukPenghuni = await masukSebagai(ws, 'penghuni@kos.test', 'password')
  lapor('login penghuni berhasil', Boolean(masukPenghuni), String(masukPenghuni))

  await kirim(ws, 'Page.navigate', { url: `${APP}/penghuni` })
  await tungguSampai(ws, `document.body.textContent.includes('Cara membayar')`, 15000)

  const portal = await evaluasi(ws, `
    (async () => {
      // Tunggu gambar selesai dimuat: blob butuh satu putaran fetch.
      for (let i = 0; i < 40; i++) {
        const img = document.querySelector('.daftar-rekening img')
        if (img && img.complete && img.naturalWidth > 0) {
          return {
            adaGambar: true,
            lebar: img.naturalWidth,
            tinggi: img.naturalHeight,
            src: img.src.slice(0, 20),
            alt: img.alt,
          }
        }
        await new Promise(r => setTimeout(r, 250))
      }
      const img = document.querySelector('.daftar-rekening img')
      return {
        adaGambar: Boolean(img),
        lebar: img?.naturalWidth ?? 0,
        src: img?.src?.slice(0, 20) ?? '',
        teks: document.querySelector('.daftar-rekening')?.textContent.replace(/\\s+/g, ' ').trim().slice(0, 160) ?? '',
      }
    })()
  `)
  lapor(
    'penghuni melihat gambar QRIS yang benar-benar termuat',
    portal.adaGambar === true && portal.lebar > 0,
    `lebar=${portal.lebar}, src=${portal.src}, alt=${portal.alt}`,
  )
  lapor(
    'gambar disajikan sebagai blob, bukan URL bertoken',
    String(portal.src || '').startsWith('blob:'),
    `src=${portal.src}`,
  )

  await tangkapLayar(ws, 'QR-portal-penghuni')

  // --- Rekening nonaktif: gambar tidak bisa dibuka penghuni ---------------
  //
  // Penghuni yang membuka gambar rekening lama harus dijawab 404 - bukan 403,
  // karena 403 akan memberi tahu bahwa rekening itu masih ada.
  if (nonaktif.status === 201) {
    const id = nonaktif.data.data.id
    const bukaGambar = await evaluasi(ws, `
      (async () => {
        const res = await fetch('${API}/rekening/${id}/gambar', {
          headers: { Authorization: 'Bearer ' + localStorage.getItem('kos.token') },
        })
        return { status: res.status }
      })()
    `)
    lapor(
      'penghuni tidak bisa membuka gambar rekening nonaktif (404)',
      bukaGambar.status === 404,
      `status ${bukaGambar.status}`,
    )
  }

  // --- Penghuni tetap tidak boleh menulis rekening ------------------------
  const penghuniMenulis = await panggilApi(ws, '/rekening', 'POST', {
    jenis: 'qris',
    nama: 'QRIS Penghuni',
  })
  lapor(
    'penghuni tidak boleh membuat rekening (403)',
    penghuniMenulis.status === 403,
    `status ${penghuniMenulis.status}`,
  )

  const gagal = await uji.selesai()
  if (gagal.length) {
    console.error('\nLangkah gagal: ' + gagal.map((g) => g.nama).join('; '))
  }
}

jalankan(main)
