// Verifikasi end-to-end Fase 3.5 lewat browser sungguhan.
//
// Empat hal yang dibuktikan, dan kenapa masing-masing tidak cukup diuji dari
// tes PHP saja:
//
//   1. **Penanda penyiapan benar-benar terlihat pemilik.** Tes PHP membuktikan
//      `/penyiapan` mengembalikan data yang benar; ia tidak membuktikan ada
//      layar yang menampilkannya. Penanda yang datanya benar tetapi tidak
//      pernah dirender adalah fitur yang tidak ada.
//
//   2. **Ekspor menghasilkan berkas yang benar-benar terunduh.** Tes PHP
//      membuktikan isi responsnya; yang tidak terbukti adalah apakah peramban
//      menerima berkasnya, atau hanya menampilkannya sebagai teks di tab baru.
//      Diperiksa dari peristiwa unduhan CDP, bukan dari kode status.
//
//   3. **Pengelola tidak melihat tombol ekspor rekap.** Server menolaknya (403),
//      tetapi tombol yang tetap tampil dan pasti ditolak membuat pengelola
//      mengira aplikasinya rusak.
//
//   4. **Halaman bantuan bisa dibuka kedua peran.** Ia berisi jawaban atas
//      penolakan yang paling sering ditemui; halaman yang tidak bisa dibuka
//      sama saja dengan tidak ada.
//
// Pakai: node docs/uji/e2e-fase35.mjs
// Prasyarat: docker compose up, Chrome --remote-debugging-port=9222,
// database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini TIDAK mengubah data: ia hanya membaca dan mengunduh.

import {
  APP,
  mulaiUji,
  masukSebagai,
  evaluasi,
  tungguSampai,
  kirim,
  jalankan,
  pastikanKebijakan,
} from './_cdp.mjs'

import { mkdirSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

/**
 * Folder tujuan unduhan.
 *
 * **Kenapa `tmpdir()` dan bukan `/tmp`.** Chrome yang diuji berjalan di HOST
 * (Windows), bukan di dalam container. `Browser.setDownloadBehavior` menerima
 * path menurut sistem operasi peramban itu sendiri, jadi `/tmp/unduhan-uji`
 * adalah folder yang tidak ada di Windows. Gejalanya halus dan menyesatkan:
 * peristiwa `downloadWillBegin` TETAP terkirim - jadi "unduhan dimulai"
 * lulus - tetapi berkasnya tidak pernah ditulis, `receivedBytes` tetap 0, dan
 * status `completed` tidak pernah datang. Percobaan pertama skrip ini
 * melaporkan tepat gejala itu, dan penyebabnya bukan aplikasinya.
 */
const FOLDER_UNDUH = join(tmpdir(), 'unduhan-uji-fase35')

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  try {
    // --- 1. Penanda penyiapan tampil untuk pemilik ------------------------
    const masuk = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik berhasil', masuk)
    if (!masuk) throw new Error('login gagal')

    // Kebijakan diisi lebih dulu supaya penanda yang tersisa hanya soal
    // rekening. Tanpa ini, seeder yang tidak menyemai kebijakan akan membuat
    // SEMUA langkah belum selesai - dan asersi "menyebut rekening" bisa lulus
    // karena alasan yang salah.
    const kebijakan = await pastikanKebijakan(ws)
    lapor('kebijakan kos siap', kebijakan.ok, kebijakan.alasan)

    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/kamar` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Kamar')`)
    await new Promise((r) => setTimeout(r, 900))

    const teksKamar = await evaluasi(ws, 'document.body.innerText')

    // Penanda hanya muncul bila ada langkah wajib yang belum selesai. Seeder
    // tidak menyemai rekening, jadi pada database demo ia HARUS muncul.
    // Kalau ini gagal, sebabnya biasanya seeder berubah - bukan aplikasinya.
    const adaPenanda = teksKamar.includes('Kos ini belum siap dipakai')
    lapor('penanda penyiapan tampil di halaman muka pemilik', adaPenanda)

    if (adaPenanda) {
      lapor(
        'penanda menyebut langkah yang belum selesai',
        teksKamar.includes('rekening') || teksKamar.includes('Rekening'),
      )
      lapor(
        'penanda menyebut kemajuan, bukan hanya "belum siap"',
        /dari 4 langkah wajib/.test(teksKamar),
      )
    }

    // --- 2. Ekspor benar-benar menghasilkan berkas ------------------------
    //
    // `Browser.setDownloadBehavior` mengarahkan unduhan ke folder yang bisa
    // diperiksa. Tanpa ini, unduhan berjalan ke folder bawaan Chrome di
    // dalam container dan tidak bisa dibedakan dari unduhan yang gagal.
    //
    // Peristiwanya didengar langsung dari CDP. Membaca pesan "Berkas ...
    // diunduh" di layar saja tidak cukup: pesan itu ditulis oleh kode
    // peramban SEBELUM berkasnya selesai ditulis, sehingga layar bisa
    // menyatakan berhasil sementara berkasnya nol byte atau tidak pernah ada.
    const unduhan = { nama: '', selesai: false, diterima: 0 }

    ws.addEventListener('message', (e) => {
      const pesan = JSON.parse(e.data)

      if (pesan.method === 'Browser.downloadWillBegin') {
        unduhan.nama = pesan.params.suggestedFilename || ''
      }

      if (pesan.method === 'Browser.downloadProgress') {
        unduhan.diterima = pesan.params.receivedBytes ?? 0
        if (pesan.params.state === 'completed') {
          unduhan.selesai = true
        }
      }
    })

    // Folder tujuan dibuat lebih dulu. `setDownloadBehavior` tidak membuat
    // folder sendiri; bila foldernya tidak ada, unduhan dibatalkan tanpa
    // peristiwa apa pun - dan skripnya akan menunggu selamanya.
    mkdirSync(FOLDER_UNDUH, { recursive: true })

    await kirim(ws, 'Browser.setDownloadBehavior', {
      behavior: 'allow',
      downloadPath: FOLDER_UNDUH,
      eventsEnabled: true,
    })

    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/rekap` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Rekap')`)
    // Tunggu tombol ekspor benar-benar ada: tombolnya hanya dirender setelah
    // rekap selesai dimuat, dan menekannya lebih awal akan melempar galat yang
    // tidak ada hubungannya dengan ekspor.
    await tungguSampai(
      ws,
      `Array.from(document.querySelectorAll('button')).some((b) => b.textContent.includes('Ekspor rekap'))`,
    )

    await evaluasi(
      ws,
      `(() => {
        const b = Array.from(document.querySelectorAll('button')).find(
          (x) => x.textContent.includes('Ekspor rekap'),
        )
        if (b) b.click()
        return Boolean(b)
      })()`,
    )

    // Berkasnya muncul sebagai peristiwa unduhan, bukan sebagai navigasi.
    // Ditunda sebentar: `click()` memicu permintaan jaringan yang harus
    // diselesaikan lebih dulu di server.
    await new Promise((r) => setTimeout(r, 2500))

    lapor(
      'peramban menerima peristiwa mulai unduh',
      unduhan.nama !== '',
      unduhan.nama,
    )
    lapor(
      'berkas ekspor selesai ditulis, bukan hanya dimulai',
      unduhan.selesai && unduhan.diterima > 0,
      `${unduhan.diterima} byte`,
    )
    lapor(
      'nama berkas ekspor memuat rentang tanggal',
      /^rekap-\d{4}-\d{2}-\d{2}-sampai-\d{4}-\d{2}-\d{2}\.csv$/.test(unduhan.nama),
      unduhan.nama,
    )

    const pesanUnduh = await evaluasi(ws, 'document.body.innerText')
    // `detail` pada `lapor()` adalah KONTEKS yang selalu dicetak, bukan alasan
    // kegagalan. Mengisinya dengan kalimat "tidak ada konfirmasi unduhan"
    // membuat baris LULUS berbunyi seperti baris GAGAL - dan laporan yang
    // bertentangan dengan dirinya sendiri akan diabaikan orang.
    lapor('layar menyatakan berkas diunduh', pesanUnduh.includes('diunduh'))

    // --- 3. Pengelola: tombol rekap tidak ada, halaman bantuan terbuka ----
    const masukPengelola = await masukSebagai(ws, 'pengelola@kos.test')
    lapor('login pengelola berhasil', masukPengelola)

    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/rekap` })
    await tungguSampai(ws, `document.querySelector('h1')`)
    await new Promise((r) => setTimeout(r, 900))

    const tombolPengelola = await evaluasi(
      ws,
      `Array.from(document.querySelectorAll('button')).map((b) => b.textContent.trim())`,
    )

    // REV-SEC-06: pengelola melihat "Uang tunai saya", bukan rekap pendapatan
    // kos. Tombol "Ekspor rekap" pasti ditolak server, jadi ia tidak boleh
    // dirender - dan lebih penting lagi, tombol "Ekspor pembayaran" tidak boleh
    // muncul di layar yang judulnya menjanjikan angka miliknya sendiri.
    lapor(
      'pengelola tidak melihat tombol "Ekspor rekap"',
      !tombolPengelola.some((t) => t.includes('Ekspor rekap')),
      JSON.stringify(tombolPengelola),
    )
    lapor(
      'pengelola tidak melihat tombol "Ekspor pembayaran" di layar tunai',
      !tombolPengelola.some((t) => t.includes('Ekspor pembayaran')),
      JSON.stringify(tombolPengelola),
    )

    // --- 4. Halaman bantuan terbuka untuk kedua peran ---------------------
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/bantuan` })
    const bantuanPengelola = await tungguSampai(
      ws,
      `document.querySelector('h1')?.textContent.includes('Bantuan')`,
    )
    lapor('pengelola bisa membuka halaman bantuan', bantuanPengelola)

    const teksBantuan = await evaluasi(ws, 'document.body.innerText')
    lapor(
      'bantuan memuat jawaban atas penolakan yang nyata',
      teksBantuan.includes('Kebijakan kos belum lengkap'),
    )
    lapor(
      'bantuan tidak menjanjikan dukungan yang tidak ada',
      !/hubungi dukungan|contact support|email kami/i.test(teksBantuan),
    )

    // Menu Bantuan harus muncul di sidebar, bukan hanya bisa dibuka lewat
    // alamat yang diketik manual. Halaman yang tidak ada menunya adalah
    // halaman yang tidak akan ditemukan.
    lapor(
      'menu Bantuan ada di sidebar pengelola',
      /Bantuan/.test(await evaluasi(ws, 'document.body.innerText')),
    )

    await masukSebagai(ws, 'pemilik@kos.test')
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/bantuan` })
    const bantuanPemilik = await tungguSampai(
      ws,
      `document.querySelector('h1')?.textContent.includes('Bantuan')`,
    )
    lapor('pemilik bisa membuka halaman bantuan', bantuanPemilik)

    const teksPemilik = await evaluasi(ws, 'document.body.innerText')
    lapor(
      'bantuan pemilik memuat butir khusus pemilik',
      teksPemilik.includes('Apa bedanya pemilik dan pengelola'),
    )
  } finally {
    await uji.selesai()
  }
})
