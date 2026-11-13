// Verifikasi end-to-end kredensial perangkat (K-16).
//
// Yang dibuktikan, dan kenapa masing-masing perlu:
//
//   1. **Sandi tidak ikut di daftar perangkat.** Ini bukan kerapian: daftar
//      dibuka setiap kali halaman diragukan isinya, dan sandi CCTV yang
//      melintas di sana terbaca siapa pun yang melihat layarnya. Tes PHP
//      memeriksa bentuk responsnya; skrip ini memeriksa respons yang BENAR-BENAR
//      dikirim ke peramban.
//
//   2. **Tombol "Lihat sandi" meminta sandi, bukan menampilkan yang sudah ada.**
//      Kalau sandinya sudah terkirim bersama daftar, tombolnya akan bekerja
//      tanpa satu pun permintaan - dan tidak ada yang bisa membedakan keduanya
//      dari layar. Karena itu jumlah permintaan `/perangkat/{id}/sandi` dihitung.
//
//   3. **Penghuni hanya melihat WiFi, bukan CCTV.** Penyaringan di layar tidak
//      membuktikan apa pun: datanya tetap terkirim. Yang diperiksa adalah
//      respons `/penghuni/perangkat`, yang hanya memuat WiFi.
//
//   4. **Penghuni tanpa kontrak aktif tidak melihat apa-apa.** K-16 menyebut
//      "kos yang ia tinggali"; akun yang kontraknya sudah berakhir bukan lagi
//      penghuni kos ini, dan sandi WiFi yang masih berlaku bukan untuknya.
//
// Pakai: node docs/uji/e2e-perangkat.mjs
// Prasyarat: docker compose up sudah jalan, Chrome --remote-debugging-port=9222,
// dan database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo: menambah perangkat dan satu kontrak.

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

const SANDI_WIFI = 'melati-2026'
const NAMA_WIFI = 'WiFi Lantai 1'
const NAMA_CCTV = 'CCTV Gerbang'

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  try {
    // --- Siapkan perangkat ------------------------------------------------
    const masukPemilik = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik berhasil', masukPemilik)
    if (!masukPemilik) throw new Error('login pemilik gagal')

    // Dibersihkan lebih dulu: skrip ini dijalankan berulang tanpa
    // `migrate:fresh`, dan dua WiFi bernama sama membuat asersi "hanya satu"
    // gagal karena sebab yang tidak ada hubungannya dengan yang diuji.
    const lama = await panggilApi(ws, '/perangkat')
    for (const p of lama.data?.data || []) {
      if (p.nama === NAMA_WIFI || p.nama === NAMA_CCTV) {
        await panggilApi(ws, `/perangkat/${p.id}`, 'DELETE')
      }
    }

    const wifi = await panggilApi(ws, '/perangkat', 'POST', {
      jenis: 'wifi',
      nama: NAMA_WIFI,
      lokasi: 'Dekat tangga',
      nama_pengguna: 'KOS-LANTAI-1',
      sandi: SANDI_WIFI,
      aktif: true,
    })
    lapor('pemilik menambah WiFi', wifi.status === 201, `status ${wifi.status}`)
    if (wifi.status !== 201) throw new Error('gagal menambah WiFi')

    const cctv = await panggilApi(ws, '/perangkat', 'POST', {
      jenis: 'cctv',
      nama: NAMA_CCTV,
      sandi: 'cctv-gerbang',
      aktif: true,
    })
    lapor('pemilik menambah CCTV', cctv.status === 201, `status ${cctv.status}`)

    // --- Daftar tidak memuat sandi ---------------------------------------
    const daftar = await panggilApi(ws, '/perangkat')
    const baris = (daftar.data?.data || []).find((p) => p.nama === NAMA_WIFI)
    lapor(
      'daftar perangkat tidak memuat sandi',
      Boolean(baris) && !('sandi' in baris) && baris.ada_sandi === true,
      JSON.stringify(baris),
    )
    lapor(
      'respons daftar tidak memuat teks sandi sama sekali',
      !JSON.stringify(daftar.data).includes(SANDI_WIFI),
    )

    // --- Layar Perangkat: sandi diminta saat tombolnya ditekan -----------
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/perangkat` })
    await tungguSampai(ws, `document.querySelector('h1')`, 15000)

    const adaBaris = await tungguSampai(
      ws,
      `document.body.textContent.includes(${JSON.stringify(NAMA_WIFI)})`,
      15000,
    )
    lapor('layar Perangkat menampilkan WiFi yang baru dibuat', adaBaris)

    const tersamar = await evaluasi(
      ws,
      `document.body.textContent.includes('••••••••')`,
    )
    lapor('sandi tersamar sebelum diminta', Boolean(tersamar))

    // Hitung permintaan ke `/sandi`, supaya "tombol bekerja" tidak bisa lulus
    // hanya karena sandinya sudah ada di halaman.
    await evaluasi(
      ws,
      `(() => {
        window.__hitungSandi = 0
        const asli = window.fetch
        window.fetch = (...a) => {
          if (String(a[0]).includes('/sandi')) window.__hitungSandi += 1
          return asli(...a)
        }
        return true
      })()`,
    )

    await evaluasi(
      ws,
      `(() => {
        const tombol = [...document.querySelectorAll('button')]
          .find((b) => b.textContent.trim() === 'Lihat sandi')
        if (!tombol) return false
        tombol.click()
        return true
      })()`,
    )

    const tampil = await tungguSampai(
      ws,
      `document.body.textContent.includes(${JSON.stringify(SANDI_WIFI)})`,
      15000,
    )
    lapor('sandi tampil setelah tombol "Lihat sandi" ditekan', tampil)

    const dihitung = await evaluasi(ws, 'window.__hitungSandi')
    lapor(
      'sandi diminta ke server, bukan sudah ada di halaman',
      Number(dihitung) >= 1,
      `permintaan: ${dihitung}`,
    )

    await tangkapLayar(ws, 'perangkat-pemilik')

    await evaluasi(
      ws,
      `(() => {
        const tombol = [...document.querySelectorAll('button')]
          .find((b) => b.textContent.trim() === 'Sembunyikan')
        if (!tombol) return false
        tombol.click()
        return true
      })()`,
    )
    const tersamarLagi = await tungguSampai(
      ws,
      `!document.body.textContent.includes(${JSON.stringify(SANDI_WIFI)})`,
      10000,
    )
    lapor('sandi bisa disembunyikan lagi', tersamarLagi)

    // --- Penghuni tanpa kontrak aktif ------------------------------------
    const masukPenghuni = await masukSebagai(ws, 'penghuni@kos.test')
    lapor('login penghuni berhasil', masukPenghuni)
    if (!masukPenghuni) throw new Error('login penghuni gagal')

    await lewatiPersetujuanPdp(ws)

    const tanpaKontrak = await panggilApi(ws, '/penghuni/perangkat')
    lapor(
      'penghuni tanpa kontrak aktif tidak menerima perangkat',
      tanpaKontrak.status === 200 && (tanpaKontrak.data?.data || []).length === 0,
      JSON.stringify(tanpaKontrak.data),
    )

    // --- Beri kontrak, lalu buka portal ----------------------------------
    const masukLagi = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik untuk membuat kontrak berhasil', masukLagi)

    const kamar = await panggilApi(ws, '/kamar')
    let kosong = (kamar.data?.data || []).find((k) => k.status_huni === 'kosong')

    if (!kosong) {
      const buat = await panggilApi(ws, '/kamar', 'POST', {
        nomor: 'UJI-K16',
        tipe: 'Standar',
      })
      kosong = buat.data?.data
    }

    const daftarPenghuni = await panggilApi(ws, '/penghuni')
    const budi = (daftarPenghuni.data?.data || []).find((p) => p.email === 'penghuni@kos.test')
    lapor('akun penghuni demo terhubung ke data penghuni', Boolean(budi))

    const kontrak = await panggilApi(ws, '/kontrak', 'POST', {
      kamar_id: kosong.id,
      penghuni_id: budi.id,
      tgl_masuk: '2026-10-01',
      tarif: 1500000,
    })
    lapor(
      'kontrak aktif penghuni tersedia',
      kontrak.status === 201 || kontrak.status === 422,
      `status ${kontrak.status}`,
    )

    // --- Portal penghuni: hanya WiFi -------------------------------------
    const masukPenghuniLagi = await masukSebagai(ws, 'penghuni@kos.test')
    lapor('login penghuni untuk melihat WiFi berhasil', masukPenghuniLagi)

    await lewatiPersetujuanPdp(ws)

    const portal = await panggilApi(ws, '/penghuni/perangkat')
    const namaTerlihat = (portal.data?.data || []).map((p) => p.nama)
    lapor(
      'penghuni menerima WiFi',
      namaTerlihat.includes(NAMA_WIFI),
      JSON.stringify(namaTerlihat),
    )
    lapor(
      'penghuni tidak menerima CCTV',
      !namaTerlihat.includes(NAMA_CCTV),
      JSON.stringify(namaTerlihat),
    )
    lapor(
      'respons portal tidak memuat sandi CCTV',
      !JSON.stringify(portal.data).includes('cctv-gerbang'),
    )

    await kirim(ws, 'Page.navigate', { url: `${APP}/penghuni` })
    await tungguSampai(ws, `document.querySelector('h1')`, 15000)

    // Judul dirender sebelum data tiba; tunggu panel yang hanya muncul setelah
    // perangkat termuat.
    const adaPanel = await tungguSampai(
      ws,
      `document.body.textContent.includes(${JSON.stringify(NAMA_WIFI)})`,
      15000,
    )
    lapor('portal penghuni menampilkan kartu WiFi', adaPanel)

    const sandiTersamar = await evaluasi(
      ws,
      `document.body.textContent.includes('••••••••')`,
    )
    lapor('sandi WiFi tersamar di portal sebelum diminta', Boolean(sandiTersamar))

    await evaluasi(
      ws,
      `(() => {
        const panel = [...document.querySelectorAll('section')]
          .find((s) => s.textContent.includes('WiFi kos'))
        if (!panel) return false
        const tombol = [...panel.querySelectorAll('button')]
          .find((b) => b.textContent.trim() === 'Tampilkan')
        if (!tombol) return false
        tombol.click()
        return true
      })()`,
    )

    const sandiPortal = await tungguSampai(
      ws,
      `document.body.textContent.includes(${JSON.stringify(SANDI_WIFI)})`,
      15000,
    )
    lapor('penghuni bisa menampilkan sandi WiFi', sandiPortal)

    await tangkapLayar(ws, 'perangkat-portal')
  } finally {
    const gagal = await uji.selesai()
    if (gagal.length) {
      console.error(`\n${gagal.length} langkah gagal:`)
      for (const g of gagal) console.error(` - ${g.nama}${g.detail ? ': ' + g.detail : ''}`)
    }
  }
})
