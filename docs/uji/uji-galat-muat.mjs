// Uji regresi REV-UI-04 - "belum ada" tidak boleh muncul saat pemuatan gagal.
//
// Cara kerja: blokade endpoint daftar lewat CDP, buka layarnya, lalu pastikan
// (a) pesan galat TAMPIL, dan (b) baris "Belum ada ..." TIDAK tampil. Tanpa (b),
// layar memberi tahu pengguna bahwa datanya kosong padahal yang terjadi adalah
// gagal ambil - dua keadaan yang sangat berbeda, dan yang kedua bisa menyembunyikan
// tagihan yang sudah jatuh tempo.
//
// Kontrol negatif: blokade dicabut, layar dimuat ulang, pesan galat harus HILANG.
// Ini membuktikan blokade tadi benar-benar mengenai endpoint yang dimaksud;
// tanpa itu asersi (a) bisa lulus karena halaman tidak pernah dimuat.
//
// Kontrol "Belum ada muncul saat kosong" sengaja TIDAK dipakai: seeder mengisi
// aturan, fasilitas, pengelola, dan log audit, jadi tidak semua layar kosong.
// Asersi yang mengandaikan semua layar kosong akan gagal sendiri - persis jebakan
// "asersi bergantung keadaan awal" yang sudah tercatat di AGENTS.md.
//
// Catatan pelaksanaan: `Fetch.enable` dipasang dengan pola SPESIFIK per layar,
// bukan `*`. Pola `*` menahan seluruh permintaan (termasuk dokumen dan bundel JS)
// sehingga halaman tidak pernah selesai dimuat dan hasilnya menyesatkan. Satu
// pendengar dipasang sekali untuk seluruh skrip; memasangnya per kasus memicu
// MaxListenersExceededWarning dan membuat penangan lama tetap hidup.
//
// Pakai: node docs/uji/uji-galat-muat.mjs
// Prasyarat: docker compose up, Chrome --remote-debugging-port=9222,
// database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini TIDAK mengubah database: seluruh kegagalan disimulasikan di peramban.

import { APP, kirim, evaluasi, tungguSampai, masukSebagai, mulaiUji, jalankan } from './_cdp.mjs'

// Pola URL mengikuti endpoint yang benar-benar dipanggil tiap layar. Salah pola
// berarti tidak ada yang diblokade, dan asersi galat lulus secara palsu.
const KASUS = [
  { nama: 'DashboardPenghuni', jalur: '/penghuni', pola: '*/api/penghuni/tagihan*', akun: 'penghuni@kos.test' },
  { nama: 'AturanView', jalur: '/pemilik/aturan', pola: '*/api/aturan*', akun: 'pemilik@kos.test' },
  { nama: 'KamarView', jalur: '/pemilik/kamar', pola: '*/api/kamar*', akun: 'pemilik@kos.test' },
  { nama: 'PenghuniView', jalur: '/pemilik/penghuni', pola: '*/api/penghuni*', akun: 'pemilik@kos.test' },
  { nama: 'KontrakView', jalur: '/pemilik/kontrak', pola: '*/api/kontrak*', akun: 'pemilik@kos.test' },
  { nama: 'FasilitasView', jalur: '/pemilik/fasilitas', pola: '*/api/fasilitas*', akun: 'pemilik@kos.test' },
  { nama: 'PengelolaView', jalur: '/pemilik/pengelola', pola: '*/api/pengelola*', akun: 'pemilik@kos.test' },
  { nama: 'RekeningView', jalur: '/pemilik/rekening', pola: '*/api/rekening*', akun: 'pemilik@kos.test' },
  { nama: 'AuditLogView', jalur: '/pemilik/audit', pola: '*/api/audit-log*', akun: 'pemilik@kos.test' },
  { nama: 'RekapView', jalur: '/pemilik/rekap', pola: '*/api/rekap*', akun: 'pemilik@kos.test' },
]

const PESAN_GALAT = /gagal|galat|kesalahan|tidak dapat|error/i
const PESAN_KOSONG = /Belum ada/i

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  // Satu pendengar untuk seluruh skrip. Hanya permintaan yang cocok dengan pola
  // aktif yang ditahan; sisanya tidak pernah masuk ke sini karena `Fetch.enable`
  // memang hanya menahan pola itu.
  ws.addEventListener('message', (e) => {
    const pesan = JSON.parse(e.data)
    if (pesan.method !== 'Fetch.requestPaused') return
    kirim(ws, 'Fetch.fulfillRequest', {
      requestId: pesan.params.requestId,
      responseCode: 500,
      responseHeaders: [
        { name: 'Content-Type', value: 'application/json' },
        { name: 'Access-Control-Allow-Origin', value: '*' },
      ],
      body: Buffer.from(JSON.stringify({ pesan: 'gagal disimulasikan' })).toString('base64'),
    }).catch(() => {})
  })

  for (const kasus of KASUS) {
    // --- Dengan blokade ----------------------------------------------------
    await kirim(ws, 'Fetch.enable', {
      patterns: [{ urlPattern: kasus.pola, requestStage: 'Request' }],
    })

    await masukSebagai(ws, kasus.akun)
    await kirim(ws, 'Page.navigate', { url: `${APP}${kasus.jalur}` })
    await tungguSampai(ws, `document.body !== null && document.body.innerText.length > 20`, 12000)
    await new Promise((r) => setTimeout(r, 900))

    const teks = await evaluasi(ws, 'document.body.innerText')
    lapor(`${kasus.nama}: pesan galat tampil`, PESAN_GALAT.test(teks), 'pesan galat tidak muncul')
    lapor(
      `${kasus.nama}: tidak menyatakan "Belum ada"`,
      !PESAN_KOSONG.test(teks),
      'layar menyatakan data kosong padahal gagal muat',
    )

    // --- Kontrol negatif ---------------------------------------------------
    // Tanpa blokade, pesan galat harus hilang. Ini membuktikan blokade tadi
    // benar-benar mengenai endpoint yang dimaksud, bukan gagal karena sebab lain.
    //
    // `Fetch.disable` berlaku sesudah semua permintaan yang sedang tertahan
    // dilepas. Tanpa jeda ini, navigasi berikutnya bisa berjalan saat dokumen
    // lama sudah dibuang tetapi dokumen baru belum ada, dan `document.body`
    // bernilai null.
    await kirim(ws, 'Fetch.disable')
    await new Promise((r) => setTimeout(r, 400))
    await tungguSampai(ws, `document.body !== null`, 12000)
    await kirim(ws, 'Page.navigate', { url: `${APP}${kasus.jalur}` })
    await tungguSampai(ws, `document.body !== null && document.body.innerText.length > 20`, 12000)
    await new Promise((r) => setTimeout(r, 900))

    const teks2 = await evaluasi(ws, 'document.body ? document.body.innerText : ""')
    lapor(
      `${kasus.nama}: kontrol - galat hilang saat tidak diblokade`,
      !PESAN_GALAT.test(teks2),
      'pesan galat masih tampil tanpa blokade: blokade mungkin tidak bekerja, atau galat berasal dari hal lain',
    )
  }

  await uji.selesai()
})
