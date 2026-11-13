// Verifikasi end-to-end laporan kerusakan dari portal penghuni (K-15).
//
// Yang dibuktikan, dan kenapa masing-masing perlu:
//
//   1. **Penghuni benar-benar bisa melapor.** Sebelum K-15 alurnya setengah:
//      pencatatan dan penyelesaian ada, tetapi pelapor aslinya tidak punya
//      jalan masuk. Tes PHP membuktikan endpoint-nya; skrip ini membuktikan
//      formulirnya ada di layar yang dipakai orang.
//
//   2. **Fotonya sampai dan bisa dilihat.** Unggahan yang "berhasil" tetapi
//      fotonya kosong adalah kegagalan senyap yang khas kolom `bytea`. Karena
//      itu yang diperiksa bukan status 201, melainkan `naturalWidth` gambar di
//      layar - gambar yang gagal dimuat tetap punya elemen, tetapi lebarnya 0.
//
//   3. **Tautan bertanda tangan bekerja tanpa token.** Rute lampiran sengaja di
//      luar `auth:sanctum` karena tag `<img>` tidak bisa mengirim Bearer. Kalau
//      middleware `signed` hilang, fotonya terbuka untuk siapa pun yang menebak
//      id - dan tidak ada satu pun pesan yang memberitahunya.
//
//   4. **Pemilik melihat foto itu di layar Kamar.** Laporan yang fotonya hanya
//      bisa dilihat pelapornya tidak menyelesaikan masalah yang dikeluhkan
//      pemilik: "separah apa kerusakannya".
//
// Pakai: node docs/uji/e2e-kerusakan.mjs
// Prasyarat: docker compose up sudah jalan, Chrome --remote-debugging-port=9222,
// dan database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo: membuat kontrak dan laporan kerusakan.

import { writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import {
  APP,
  mulaiUji,
  masukSebagai,
  panggilApi,
  evaluasi,
  tungguSampai,
  kirim,
  tangkapLayar,
  jalankan,
  lewatiPersetujuanPdp,
} from './_cdp.mjs'

// PNG 1x1 sungguhan. Berkas palsu berisi byte acak akan ditolak `getimagesize`,
// dan skripnya akan gagal pada asersi yang tidak ada hubungannya.
const FOTO = join(tmpdir(), 'uji-kerusakan.png')
writeFileSync(
  FOTO,
  Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    'base64',
  ),
)

/**
 * Pasang berkas ke `<input type="file">` lewat CDP.
 *
 * Tidak ada jalan lain: `input.files` tidak bisa diisi dari JavaScript halaman,
 * dan `DataTransfer` yang dirakit sendiri tidak membawa berkas sungguhan.
 * `DOM.setFileInputFiles` juga memicu `change`, jadi penangan di Vue berjalan
 * seperti unggahan biasa.
 */
async function pasangFoto(ws, selektor, jalur) {
  const { root } = await kirim(ws, 'DOM.getDocument', { depth: -1 })
  const { nodeId } = await kirim(ws, 'DOM.querySelector', {
    nodeId: root.nodeId,
    selector: selektor,
  })

  if (!nodeId) {
    throw new Error(`elemen ${selektor} tidak ditemukan`)
  }

  await kirim(ws, 'DOM.setFileInputFiles', { nodeId, files: [jalur] })
}

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  try {
    // --- Siapkan kontrak untuk penghuni demo ------------------------------
    const masukPemilik = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik berhasil', masukPemilik)
    if (!masukPemilik) throw new Error('login pemilik gagal')

    const kamar = await panggilApi(ws, '/kamar')
    let kosong = (kamar.data?.data || []).find((k) => k.status_huni === 'kosong')

    if (!kosong) {
      const buat = await panggilApi(ws, '/kamar', 'POST', {
        nomor: 'UJI-K15',
        tipe: 'Standar',
      })
      kosong = buat.data?.data
    }

    lapor('ada kamar kosong untuk diuji', Boolean(kosong), JSON.stringify(kamar.data?.data?.length))

    const daftarPenghuni = await panggilApi(ws, '/penghuni')
    const budi = (daftarPenghuni.data?.data || []).find(
      (p) => p.email === 'penghuni@kos.test',
    )
    lapor('akun penghuni demo terhubung ke data penghuni', Boolean(budi))

    const kontrak = await panggilApi(ws, '/kontrak', 'POST', {
      kamar_id: kosong.id,
      penghuni_id: budi.id,
      tgl_masuk: '2026-10-01',
      tarif: 1500000,
    })
    lapor(
      'kontrak aktif penghuni dibuat',
      kontrak.status === 201 || kontrak.status === 422,
      `status ${kontrak.status}`,
    )

    // --- Penghuni melapor -------------------------------------------------
    const masukPenghuni = await masukSebagai(ws, 'penghuni@kos.test')
    lapor('login penghuni berhasil', masukPenghuni)
    if (!masukPenghuni) throw new Error('login penghuni gagal')

    await lewatiPersetujuanPdp(ws)

    await kirim(ws, 'Page.navigate', { url: `${APP}/penghuni` })
    await tungguSampai(ws, `document.querySelector('h1')`, 15000)

    // Judul dirender sebelum data tiba; tunggu panel yang hanya muncul setelah
    // kontrak termuat - tanpa itu asersi berikutnya bisa lulus di laptop dan
    // merah di runner yang lebih lambat.
    const adaPanel = await tungguSampai(
      ws,
      `document.querySelector('#deskripsi-rusak')`,
      15000,
    )
    lapor('portal penghuni menampilkan formulir laporan kerusakan', adaPanel)

    await evaluasi(
      ws,
      `(() => {
        const el = document.querySelector('#deskripsi-rusak')
        el.value = 'Keran kamar mandi bocor dan airnya merembes ke lantai'
        el.dispatchEvent(new Event('input', { bubbles: true }))
        return true
      })()`,
    )

    await pasangFoto(ws, '#foto-rusak', FOTO)

    const terpilih = await evaluasi(
      ws,
      `document.body.textContent.includes('1 foto dipilih')`,
    )
    lapor('berkas foto terpasang di formulir', Boolean(terpilih))

    await evaluasi(
      ws,
      `(() => {
        document.querySelector('#deskripsi-rusak')
          .closest('form')
          .querySelector('button[type=submit]')
          .click()
        return true
      })()`,
    )

    const terkirim = await tungguSampai(
      ws,
      `document.body.textContent.includes('Laporan terkirim')`,
      15000,
    )
    lapor('laporan terkirim dan dikonfirmasi di layar', terkirim)

    const adaLaporan = await tungguSampai(
      ws,
      `document.body.textContent.includes('Menunggu perbaikan')`,
      15000,
    )
    lapor('laporan muncul di riwayat dengan status menunggu', adaLaporan)

    // Inti skrip ini: gambarnya benar-benar termuat, bukan sekadar elemennya ada.
    const fotoTampil = await tungguSampai(
      ws,
      `(() => {
        const i = document.querySelector('.foto-laporan img')
        return Boolean(i && i.complete && i.naturalWidth > 0)
      })()`,
      15000,
    )
    lapor('foto laporan termuat di portal penghuni', fotoTampil)

    const bertandaTangan = await evaluasi(
      ws,
      `(document.querySelector('.foto-laporan img')?.getAttribute('src') || '').includes('signature=')`,
    )
    lapor('foto disajikan lewat tautan bertanda tangan', Boolean(bertandaTangan))

    await tangkapLayar(ws, 'kerusakan-portal')

    // --- Pemilik melihatnya ----------------------------------------------
    const masukLagi = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik untuk memeriksa laporan berhasil', masukLagi)

    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/kamar` })
    await tungguSampai(ws, `document.querySelector('h1')`, 15000)

    const kamarDiperbaiki = await tungguSampai(
      ws,
      `document.body.textContent.includes('Keran kamar mandi bocor')`,
      15000,
    )
    lapor('pemilik melihat laporan pada baris kamar', kamarDiperbaiki)

    const fotoPemilik = await tungguSampai(
      ws,
      `(() => {
        const i = document.querySelector('.foto-rusak img')
        return Boolean(i && i.complete && i.naturalWidth > 0)
      })()`,
      15000,
    )
    lapor('pemilik melihat foto kerusakan di layar Kamar', fotoPemilik)

    await tangkapLayar(ws, 'kerusakan-kamar')
  } finally {
    const gagal = await uji.selesai()
    if (gagal.length) {
      console.error(`\n${gagal.length} langkah gagal:`)
      for (const g of gagal) console.error(` - ${g.nama}${g.detail ? ': ' + g.detail : ''}`)
    }
  }
})
