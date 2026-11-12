// Verifikasi end-to-end nama kos + pratinjau denda (K-17).
//
// Yang dibuktikan, dan kenapa masing-masing perlu:
//
//   1. **Nama kos benar-benar menggantikan "Kos-Kosan" di layar.** Menyimpan
//      baris kebijakan saja tidak membuktikan apa pun: nilai yang tersimpan
//      tetapi tidak pernah dibaca adalah konfigurasi yang menipu. Yang
//      diperiksa: bilah pemilik, layar masuk, dan judul tab.
//
//   2. **Layar masuk menampilkannya tanpa token.** Halaman itu dibuka sebelum
//      ada sesi, jadi ia hanya bisa memakai endpoint publik. Kalau endpoint-nya
//      lupa didaftarkan di luar `auth:sanctum`, layar masuk tetap berbunyi
//      "Kos-Kosan" dan tidak ada galat yang terlihat.
//
//   3. **Pratinjau denda memakai angka server.** 1%/hari, plafon 30%, tenggang
//      7 hari, pokok Rp 1.500.000, telat 10 hari → 3 hari kena denda → Rp
//      45.000. Angka ini dihitung `Kalkulator` yang sama dengan tagihan
//      sungguhan; pratinjau yang menghitung sendiri di peramban akan berbeda
//      satu rupiah pada pokok besar.
//
// Pakai: node docs/uji/e2e-nama-kos.mjs
// Prasyarat: docker compose up sudah jalan, Chrome --remote-debugging-port=9222,
// dan database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo: menambah kebijakan nama kos.

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
  pastikanKebijakan,
  teksMemuat,
} from './_cdp.mjs'

const NAMA = 'Kos Uji Melati'

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  try {
    const masuk = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik berhasil', masuk)
    if (!masuk) throw new Error('login pemilik gagal')

    const kebijakan = await pastikanKebijakan(ws)
    lapor('kebijakan wajib siap', kebijakan.ok, kebijakan.alasan)
    if (!kebijakan.ok) throw new Error('kebijakan gagal disiapkan')

    // --- Simpan nama kos --------------------------------------------------
    const simpan = await panggilApi(ws, '/kebijakan', 'POST', {
      tipe: 'nama_kos',
      nilai: NAMA,
      berlaku_dari: '2026-01-01',
    })
    lapor(
      'nama kos tersimpan sebagai kebijakan global',
      simpan.status === 201 || simpan.status === 422,
      `status ${simpan.status}`,
    )

    const identitas = await panggilApi(ws, '/identitas-kos')
    lapor(
      'endpoint publik mengembalikan nama kos',
      identitas.status === 200 && identitas.data?.data?.nama === NAMA,
      JSON.stringify(identitas.data),
    )

    // --- Layar Kebijakan: kartu, bilah, dan pratinjau ---------------------
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/kebijakan` })
    await tungguSampai(ws, `document.querySelector('h1')`, 15000)

    // Judul dirender sebelum data tiba; tunggu penanda yang hanya muncul
    // setelah meta kebijakan terisi.
    const adaNama = await tungguSampai(
      ws,
      `document.body.textContent.includes(${JSON.stringify(NAMA)})`,
      15000,
    )
    lapor('kartu nama kos menampilkan nilai yang berlaku', adaNama)

    const bilah = await evaluasi(
      ws,
      `document.querySelector('.merek-teks strong')?.textContent.trim() || ''`,
    )
    lapor('bilah pemilik memakai nama kos, bukan "Kos-Kosan"', bilah === NAMA, String(bilah))

    const judul = await evaluasi(ws, 'document.title')
    lapor('judul tab memakai nama kos', String(judul).includes(NAMA), String(judul))

    // Pratinjau dimuat sesudah meta; tunggu panelnya, bukan hanya halamannya.
    const adaPratinjau = await tungguSampai(
      ws,
      `document.body.textContent.includes('Pratinjau denda')`,
      15000,
    )
    const pratinjau = await evaluasi(
      ws,
      `document.body.textContent.replace(/\\s+/g, ' ')`,
    )
    lapor(
      'pratinjau denda menampilkan angka dari server',
      adaPratinjau && teksMemuat(pratinjau, '45.000'),
      (pratinjau || '').slice(0, 200),
    )
    lapor(
      'pratinjau menyebut plafon, bukan hanya denda berjalan',
      teksMemuat(pratinjau, '450.000'),
      (pratinjau || '').slice(0, 200),
    )

    await tangkapLayar(ws, 'nama-kos-kebijakan')

    // --- Layar masuk: tanpa token ----------------------------------------
    await evaluasi(ws, 'localStorage.clear(); true')
    await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
    await tungguSampai(ws, `document.querySelector('h1')`, 15000)

    const masukNama = await tungguSampai(
      ws,
      `document.body.textContent.includes(${JSON.stringify(NAMA)})`,
      15000,
    )
    lapor('layar masuk menampilkan nama kos tanpa token', masukNama)

    await tangkapLayar(ws, 'nama-kos-masuk')
  } finally {
    const gagal = await uji.selesai()
    if (gagal.length) {
      console.error(`\n${gagal.length} langkah gagal:`)
      for (const g of gagal) console.error(` - ${g.nama}${g.detail ? ': ' + g.detail : ''}`)
    }
  }
})
