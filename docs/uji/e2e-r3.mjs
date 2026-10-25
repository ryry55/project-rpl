// Verifikasi end-to-end R3 (REV-FIN-02, REV-FIN-07, REV-UI-03, REV-UI-05).
//
// Empat temuan, empat alur yang dibuktikan lewat layar sungguhan:
//
//   1. **Pembatalan tagihan (FIN-02).** Tagihan yang salah terbit bisa
//      dibatalkan tanpa dihapus, alasannya tercatat, dan periodenya dibebaskan
//      sehingga bisa diterbitkan ulang. Tagihan yang sudah menerima uang
//      ditolak - jalan yang benar adalah menarik pembayarannya lebih dulu.
//   2. **Pemaafan denda (FIN-07).** Pemilik bisa memaafkan sebagian atau
//      seluruh denda; dendanya tidak hilang dari layar, ia berhenti ditagih.
//      Pengelola tidak bisa melakukannya.
//   3. **Bagikan tagihan (UI-03).** Tombol WhatsApp menyusun tautan `wa.me`
//      dengan nomor yang sudah dinormalkan dan teks yang memuat angka dari
//      server, dan tombol salin menyalin teks yang sama.
//   4. **Rekening tujuan pembayaran (UI-05).** Pemilik mengelola rekening,
//      penghuni melihat yang aktif saja, dan pengelola tidak bisa menulisnya.
//
// Pakai: node docs/uji/e2e-r3.mjs
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
  aktifkanAkunApi,
  lewatiPersetujuanPdp,
  teksMemuat,
} from './_cdp.mjs'

const PERIODE = '2026-11-01'
const POKOK = 1_500_000
const SANDI_PENGHUNI = 'sandi-r3-penghuni'

/**
 * Tanggal simulasi untuk seluruh bagian yang menyentuh denda dan tanggal bayar.
 *
 * Tanpa ini, skrip bergantung pada hari ia dijalankan: denda baru berjalan
 * setelah jatuh tempo + tenggang, sehingga uji yang lulus hari ini akan gagal
 * bulan depan - dan kegagalannya terbaca seperti kerusakan pada pemaafan denda.
 *
 * 5 Maret 2027 dipilih karena tagihan Februari (jatuh tempo 15 Feb) sudah
 * melewati tenggang 7 hari, sehingga dendanya benar-benar berjalan.
 */
const SIMULASI = '2027-03-05'
const HEADER_SIMULASI = { 'X-Simulasi-Tanggal': SIMULASI }

/** Batas bawah waktu surat; surat lama dari percobaan sebelumnya harus diabaikan. */
const SEJAK = new Date()

/**
 * Menyiapkan kamar, tarif, penghuni, kontrak, dan satu tagihan.
 *
 * Idempoten seperti skrip E2E lain: `periksa.ps1` menjalankan ini berulang tanpa
 * `migrate:fresh` lebih dulu, dan kegagalan "nomor kamar sudah dipakai" tidak
 * mengatakan apa pun tentang R3.
 */
async function siapkanData(ws) {
  const kebijakan = await pastikanKebijakan(ws)
  if (!kebijakan.ok) {
    return { ok: false, alasan: `kebijakan: ${kebijakan.alasan}` }
  }

  const kamarAda = await panggilApi(ws, '/kamar')
  if (kamarAda.status !== 200) {
    return { ok: false, alasan: `GET /kamar gagal (${kamarAda.status})` }
  }

  let kamar = (kamarAda.data?.data || []).find((k) => k.nomor === 'R3-A')
  if (!kamar) {
    const buat = await panggilApi(ws, '/kamar', 'POST', { nomor: 'R3-A', tipe: 'Uji R3' })
    if (buat.status !== 201) {
      return { ok: false, alasan: `POST /kamar gagal (${buat.status})` }
    }
    kamar = buat.data.data
  }

  if (!kamar.tarif_terakhir) {
    const buatTarif = await panggilApi(ws, '/tarif', 'POST', {
      kamar_id: kamar.id,
      nilai: POKOK,
      berlaku_dari: '2026-01-01',
    })
    if (buatTarif.status !== 201 && buatTarif.status !== 422) {
      return { ok: false, alasan: `POST /tarif gagal (${buatTarif.status})` }
    }
  }

  const semuaPenghuni = await panggilApi(ws, '/penghuni')
  let penghuni = (semuaPenghuni.data?.data || []).find((p) => p.email === 'r3@kos.test')
  if (!penghuni) {
    // Nomor HP sengaja diisi: tombol WhatsApp (UI-03) tidak bisa diuji tanpa
    // tujuan, dan nomor yang tidak tercatat adalah cabang yang berbeda.
    const buat = await panggilApi(ws, '/penghuni', 'POST', {
      nama: 'R3 Penghuni',
      email: 'r3@kos.test',
      no_hp: '081234567890',
    })
    if (buat.status !== 201) {
      return { ok: false, alasan: `POST /penghuni gagal (${buat.status})` }
    }
    penghuni = buat.data.data
  }

  const semuaKontrak = await panggilApi(ws, '/kontrak')
  let kontrak = (semuaKontrak.data?.data || []).find((k) => k.kamar_id === kamar.id)
  if (!kontrak) {
    const buat = await panggilApi(ws, '/kontrak', 'POST', {
      kamar_id: kamar.id,
      penghuni_id: penghuni.id,
      tgl_masuk: '2026-10-15',
    })
    if (buat.status !== 201) {
      return { ok: false, alasan: `POST /kontrak gagal (${buat.status})` }
    }
    kontrak = buat.data.data
  }

  return { ok: true, kamar, penghuni, kontrak }
}

/**
 * Terbitkan satu tagihan untuk periode tertentu.
 *
 * 409 diterima sebagai "sudah ada": skrip ini dijalankan berulang, dan tagihan
 * yang sudah terbit dari percobaan sebelumnya sama baiknya dengan yang baru.
 */
async function terbitkan(ws, kontrakId, periode) {
  const hasil = await panggilApi(ws, '/tagihan', 'POST', {
    kontrak_id: kontrakId,
    periode,
  })

  if (hasil.status === 201) {
    return { ok: true, id: hasil.data.data.id }
  }

  if (hasil.status === 409) {
    // Dicari lewat daftar, bukan dibiarkan: skrip butuh idnya.
    const daftar = await panggilApi(ws, `/tagihan?kontrak_id=${kontrakId}`)
    const ada = (daftar.data?.data || []).find((t) => t.periode === periode)
    return ada ? { ok: true, id: ada.id } : { ok: false, alasan: '409 tetapi tagihan tidak ditemukan' }
  }

  return { ok: false, alasan: `POST /tagihan gagal (${hasil.status})` }
}

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  const masuk = await masukSebagai(ws, 'pemilik@kos.test')
  lapor('login pemilik', masuk, await evaluasi(ws, 'location.pathname'))
  if (!masuk) throw new Error('login pemilik gagal')

  const data = await siapkanData(ws)
  lapor('data uji disiapkan', data.ok, data.alasan || '')
  if (!data.ok) throw new Error('data uji gagal disiapkan: ' + data.alasan)

  // ======================================================================
  // 1. Pembatalan tagihan (REV-FIN-02)
  // ======================================================================
  //
  // Periode khusus supaya pembatalan tidak mengganggu tagihan yang dipakai
  // bagian lain skrip ini.
  const terbit = await terbitkan(ws, data.kontrak.id, '2026-12-01')
  lapor('tagihan Desember terbit', terbit.ok, terbit.alasan || '')
  if (!terbit.ok) throw new Error('tagihan tidak bisa terbit: ' + terbit.alasan)

  // Alasan terlalu pendek ditolak server. Diuji lewat API, bukan lewat layar:
  // yang dibuktikan adalah aturannya, dan formulir layar hanya meneruskan.
  const pendek = await panggilApi(ws, `/tagihan/${terbit.id}/batal`, 'POST', { alasan: 'x' })
  lapor(
    'alasan pembatalan terlalu pendek ditolak 422',
    pendek.status === 422 && Boolean(pendek.data?.errors?.alasan),
    `status ${pendek.status}`,
  )

  const batal = await panggilApi(ws, `/tagihan/${terbit.id}/batal`, 'POST', {
    alasan: 'Kamar keliru, seharusnya R3-B',
  })
  lapor(
    'pembatalan tagihan diterima',
    batal.status === 200 && batal.data?.data?.status === 'dibatalkan',
    `status ${batal.status} / ${batal.data?.data?.status}`,
  )
  lapor(
    'alasan pembatalan tersimpan dan terbaca kembali',
    batal.data?.data?.alasan_batal === 'Kamar keliru, seharusnya R3-B',
    String(batal.data?.data?.alasan_batal),
  )
  lapor(
    'pelaku pembatalan tercatat',
    Boolean(batal.data?.data?.dibatalkan_oleh_nama),
    String(batal.data?.data?.dibatalkan_oleh_nama),
  )

  // Barisnya tidak dihapus: yang berubah hanya status. Menghapus akan membuat
  // nomor tagihan yang pernah disebut ke penghuni lenyap.
  const sesudahBatal = await panggilApi(ws, `/tagihan/${terbit.id}`)
  lapor(
    'tagihan yang dibatalkan masih bisa dibaca lengkap',
    sesudahBatal.status === 200 && Number(sesudahBatal.data?.data?.pokok) === POKOK,
    `status ${sesudahBatal.status}`,
  )

  // Periode yang sama bisa diterbitkan ulang: itulah gunanya pembatalan.
  const ulang = await panggilApi(ws, '/tagihan', 'POST', {
    kontrak_id: data.kontrak.id,
    periode: '2026-12-01',
  })
  lapor(
    'periode yang dibatalkan bisa diterbitkan ulang',
    ulang.status === 201,
    `status ${ulang.status}`,
  )

  // Dua tagihan HIDUP untuk satu periode tetap ditolak. Tanpa pemeriksaan ini,
  // "bisa diterbitkan ulang" bisa berubah menjadi "bisa diterbitkan berkali-kali".
  const dobel = await panggilApi(ws, '/tagihan', 'POST', {
    kontrak_id: data.kontrak.id,
    periode: '2026-12-01',
  })
  lapor(
    'dua tagihan hidup untuk satu periode tetap ditolak 409',
    dobel.status === 409,
    `status ${dobel.status}`,
  )

  // ======================================================================
  // 2. Pembayaran menutup jalan pembatalan
  // ======================================================================
  const terbitBayar = await terbitkan(ws, data.kontrak.id, '2027-01-01')
  if (!terbitBayar.ok) throw new Error('tagihan Januari tidak bisa terbit')

  const bayar = await panggilApi(
    ws,
    `/tagihan/${terbitBayar.id}/pembayaran`,
    'POST',
    {
      jumlah: 100_000,
      tanggal_pembayaran: '2027-01-05',
      metode: 'transfer',
    },
    HEADER_SIMULASI,
  )
  lapor('pembayaran sebagian tercatat', bayar.status === 201, `status ${bayar.status}`)

  const batalBerbayar = await panggilApi(
    ws,
    `/tagihan/${terbitBayar.id}/batal`,
    'POST',
    { alasan: 'Mencoba membatalkan tagihan yang sudah dibayar' },
    HEADER_SIMULASI,
  )
  lapor(
    'tagihan yang sudah menerima uang tidak bisa dibatalkan (422)',
    batalBerbayar.status === 422,
    `status ${batalBerbayar.status}`,
  )

  // ======================================================================
  // 3. Pemaafan denda (REV-FIN-07)
  // ======================================================================
  //
  // Denda baru berjalan setelah jatuh tempo + tenggang. Tanggal simulasi
  // dipakai supaya angkanya bisa dihitung, bukan bergantung pada hari ini.
  const terbitDenda = await terbitkan(ws, data.kontrak.id, '2027-02-01')
  if (!terbitDenda.ok) throw new Error('tagihan Februari tidak bisa terbit')

  const denganDenda = await panggilApi(
    ws,
    `/tagihan/${terbitDenda.id}`,
    'GET',
    null,
    HEADER_SIMULASI,
  )
  const dendaAwal = Number(denganDenda.data?.data?.denda || 0)
  lapor('denda berjalan pada tanggal simulasi', dendaAwal > 0, `denda ${dendaAwal}`)

  // Alasan wajib saat ada yang dimaafkan: denda yang tiba-tiba nol adalah hal
  // pertama yang ditanyakan penghuni.
  const tanpaAlasan = await panggilApi(
    ws,
    `/tagihan/${terbitDenda.id}/maaf-denda`,
    'POST',
    { jumlah: dendaAwal },
    HEADER_SIMULASI,
  )
  lapor(
    'pemaafan tanpa alasan ditolak 422',
    tanpaAlasan.status === 422 && Boolean(tanpaAlasan.data?.errors?.alasan),
    `status ${tanpaAlasan.status}`,
  )

  const maaf = await panggilApi(
    ws,
    `/tagihan/${terbitDenda.id}/maaf-denda`,
    'POST',
    { jumlah: dendaAwal, alasan: 'Penghuni sakit, dimaafkan pemilik' },
    HEADER_SIMULASI,
  )
  lapor('pemaafan denda diterima', maaf.status === 200, `status ${maaf.status}`)
  lapor(
    'denda setelah pemaafan menjadi nol',
    Number(maaf.data?.data?.denda || 0) === 0,
    String(maaf.data?.data?.denda),
  )
  // Yang penting: pemaafan TIDAK menghapus fakta bahwa dendanya pernah ada.
  lapor(
    'denda sebelum pemaafan tetap terbaca',
    Number(maaf.data?.data?.denda_ringkas?.denda_sebelum_maaf || 0) === dendaAwal,
    String(maaf.data?.data?.denda_ringkas?.denda_sebelum_maaf),
  )

  // ======================================================================
  // 4. Rekening tujuan pembayaran (REV-UI-05)
  // ======================================================================
  const tambahRekening = await panggilApi(ws, '/rekening', 'POST', {
    jenis: 'transfer',
    nama: 'BCA',
    nomor: '1234567890',
    atas_nama: 'Pemilik Kos',
    catatan: 'Konfirmasi lewat WhatsApp.',
  })
  lapor('pemilik menambah rekening', tambahRekening.status === 201, `status ${tambahRekening.status}`)

  const rekeningId = tambahRekening.data?.data?.id

  const rekeningLama = await panggilApi(ws, '/rekening', 'POST', {
    jenis: 'qris',
    nama: 'QRIS BCA',
    aktif: false,
  })
  lapor('rekening nonaktif bisa disimpan', rekeningLama.status === 201, `status ${rekeningLama.status}`)

  const daftarRekening = await panggilApi(ws, '/rekening')
  lapor(
    'pemilik melihat rekening aktif dan nonaktif',
    daftarRekening.status === 200 && (daftarRekening.data?.data || []).length >= 2,
    `${(daftarRekening.data?.data || []).length} baris`,
  )

  // --- Layar pemilik: halaman Rekening bisa dibuka dan menampilkan nomornya ---
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/rekening` })
  await tungguSampai(ws, `document.querySelector('#app')?.children.length > 0`, 15000)
  await tungguSampai(ws, `document.body.innerText.includes('1234567890')`, 8000)

  const teksRekening = await evaluasi(ws, `document.body.innerText`)
  lapor('halaman Rekening menampilkan nomor rekening', teksMemuat(teksRekening, '1234567890'))
  lapor('halaman Rekening menampilkan nama bank', teksMemuat(teksRekening, 'BCA'))
  await tangkapLayar(ws, 'r3-rekening-pemilik')

  // --- Pengelola tidak boleh menulis rekening ---
  //
  // Ini penjagaan terpenting di R3. Pengelola yang bisa mengganti nomor
  // rekening bisa mengarahkan setoran penghuni ke rekeningnya sendiri, lalu
  // menandai tagihannya lunas dengan wewenang `catat_pembayaran` yang memang
  // ia punya. Tidak ada pemeriksaan lain yang menangkapnya.
  const keluarPemilik = await panggilApi(ws, '/logout', 'POST')
  lapor('pemilik keluar', keluarPemilik.status === 200 || keluarPemilik.status === 204, '')

  const masukPengelola = await masukSebagai(ws, 'pengelola@kos.test')
  lapor('login pengelola', masukPengelola, await evaluasi(ws, 'location.pathname'))
  if (!masukPengelola) throw new Error('login pengelola gagal')

  const pengelolaTulis = await panggilApi(ws, '/rekening', 'POST', {
    jenis: 'transfer',
    nama: 'Rekening Pengelola',
    nomor: '9999999999',
  })
  lapor(
    'pengelola ditolak menambah rekening (403)',
    pengelolaTulis.status === 403,
    `status ${pengelolaTulis.status}`,
  )

  const pengelolaUbah = await panggilApi(ws, `/rekening/${rekeningId}`, 'PUT', {
    nama: 'BCA',
    nomor: '9999999999',
  })
  lapor(
    'pengelola ditolak mengubah nomor rekening (403)',
    pengelolaUbah.status === 403,
    `status ${pengelolaUbah.status}`,
  )

  const pengelolaBaca = await panggilApi(ws, '/rekening')
  lapor(
    'pengelola tetap boleh membaca rekening',
    pengelolaBaca.status === 200,
    `status ${pengelolaBaca.status}`,
  )

  // Pengelola juga ditolak memaafkan denda: memaafkan berarti melepaskan uang
  // pemilik, dan tidak ada kebutuhan operasional yang menuntutnya.
  const pengelolaMaaf = await panggilApi(
    ws,
    `/tagihan/${terbitBayar.id}/maaf-denda`,
    'POST',
    { jumlah: 1000, alasan: 'Coba-coba' },
    HEADER_SIMULASI,
  )
  lapor(
    'pengelola ditolak memaafkan denda (403)',
    pengelolaMaaf.status === 403,
    `status ${pengelolaMaaf.status}`,
  )

  // Halaman Rekening tidak boleh bisa dibuka pengelola lewat URL langsung.
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/rekening` })
  await new Promise((r) => setTimeout(r, 1200))
  const jalurPengelola = await evaluasi(ws, 'location.pathname')
  lapor(
    'pengelola dialihkan dari halaman Rekening',
    jalurPengelola !== '/pemilik/rekening',
    jalurPengelola,
  )

  // ======================================================================
  // 5. Portal penghuni: rekening aktif saja, dan tombol bagikan (UI-03)
  // ======================================================================
  const keluarPengelola = await panggilApi(ws, '/logout', 'POST')
  lapor('pengelola keluar', keluarPengelola.status === 200 || keluarPengelola.status === 204, '')

  // Akun penghuni diaktifkan lewat tautan email, persis seperti penghuni
  // sungguhan: pemilik tidak pernah tahu sandi siapa pun (T27).
  const aktif = await aktifkanAkunApi('r3@kos.test', SANDI_PENGHUNI, SEJAK)
  lapor('penghuni mengaktifkan akun lewat email', aktif.status === 200, `status ${aktif.status}`)

  const masukPenghuni = await masukSebagai(ws, 'r3@kos.test', SANDI_PENGHUNI)
  lapor('login penghuni', masukPenghuni, await evaluasi(ws, 'location.pathname'))
  if (!masukPenghuni) throw new Error('login penghuni gagal')

  const lolosPdp = await lewatiPersetujuanPdp(ws)
  lapor('gerbang persetujuan PDP dilewati', lolosPdp, await evaluasi(ws, 'location.pathname'))

  const rekeningPenghuni = await panggilApi(ws, '/penghuni/rekening')
  const barisPenghuni = rekeningPenghuni.data?.data || []
  lapor(
    'penghuni hanya menerima rekening aktif',
    rekeningPenghuni.status === 200 && barisPenghuni.every((r) => r.aktif === true),
    `${barisPenghuni.length} baris`,
  )
  lapor(
    'rekening nonaktif tidak sampai ke penghuni',
    !barisPenghuni.some((r) => r.nama === 'QRIS BCA'),
    barisPenghuni.map((r) => r.nama).join(', '),
  )
  lapor(
    'portal penghuni tidak mengirim meta pengelolaan',
    rekeningPenghuni.data?.meta === undefined,
    String(rekeningPenghuni.data?.meta),
  )

  // Penghuni ditolak membaca daftar lengkap: ia memuat rekening nonaktif.
  const rekeningPenuh = await panggilApi(ws, '/rekening')
  lapor(
    'penghuni ditolak membaca daftar rekening lengkap (403)',
    rekeningPenuh.status === 403,
    `status ${rekeningPenuh.status}`,
  )

  // --- Kartu "Cara membayar" tampil di portal ---
  await kirim(ws, 'Page.navigate', { url: `${APP}/penghuni` })
  await tungguSampai(ws, `document.querySelector('#app')?.children.length > 0`, 15000)
  await tungguSampai(ws, `document.body.innerText.includes('Cara membayar')`, 8000)

  const teksPortal = await evaluasi(ws, `document.body.innerText`)
  lapor('portal menampilkan kartu "Cara membayar"', teksMemuat(teksPortal, 'Cara membayar'))
  lapor('portal menampilkan nomor rekening', teksMemuat(teksPortal, '1234567890'))
  lapor('portal menampilkan atas nama', teksMemuat(teksPortal, 'a.n. Pemilik Kos'))
  lapor(
    'portal tidak menampilkan rekening nonaktif',
    !teksMemuat(teksPortal, 'QRIS BCA'),
  )
  await tangkapLayar(ws, 'r3-portal-penghuni')

  // ======================================================================
  // 6. Tombol bagikan di layar pemilik (REV-UI-03)
  // ======================================================================
  const keluarPenghuni = await panggilApi(ws, '/logout', 'POST')
  lapor('penghuni keluar', keluarPenghuni.status === 200 || keluarPenghuni.status === 204, '')

  const masukUlang = await masukSebagai(ws, 'pemilik@kos.test')
  lapor('pemilik masuk lagi', masukUlang, await evaluasi(ws, 'location.pathname'))
  if (!masukUlang) throw new Error('login ulang pemilik gagal')

  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tagihan` })
  await tungguSampai(ws, `document.querySelector('#app')?.children.length > 0`, 15000)
  // Menunggu `tbody tr` apa pun tidak cukup: baris "Memuat tagihan..." juga
  // berupa `tr`, jadi penantian ini puas sebelum data tiba dan pencarian baris
  // Februari di bawahnya lalu gagal. Tunggu baris yang benar-benar dicari.
  await tungguSampai(
    ws,
    `[...document.querySelectorAll('tbody tr')].some((tr) => /Feb 2027/.test(tr.textContent))`,
    15000,
  )

  // Tagihan Februari dibuka: satu-satunya yang punya denda dan sudah dimaafkan,
  // sehingga teks yang disusun memuat cabang pemaafan sekaligus.
  //
  // Dicari lewat teks periode yang benar-benar dirender (`1 Feb 2027`), bukan
  // lewat id: id tidak pernah tampil di layar, dan memilih baris pertama akan
  // membuka tagihan yang salah begitu urutan daftar berubah.
  const terbuka = await evaluasi(ws, `
    (() => {
      const baris = [...document.querySelectorAll('tbody tr')]
      const target = baris.find((tr) => /Feb 2027/.test(tr.textContent))
      if (!target) return false
      const tombol = [...target.querySelectorAll('button')]
        .find((b) => /rincian/i.test(b.textContent))
      if (!tombol) return false
      tombol.click()
      return true
    })()
  `)
  lapor('panel rincian tagihan Februari dibuka', terbuka)
  await tungguSampai(ws, `document.querySelector('.bagikan')`, 10000)

  const bagikan = await evaluasi(ws, `
    (() => {
      const kotak = document.querySelector('.bagikan')
      if (!kotak) return null
      const tautan = kotak.querySelector('a')
      const tombol = [...kotak.querySelectorAll('button')]
        .find((b) => /salin/i.test(b.textContent))
      return {
        adaTautan: Boolean(tautan),
        href: tautan ? tautan.getAttribute('href') : '',
        adaTombolSalin: Boolean(tombol),
      }
    })()
  `)

  lapor('tombol WhatsApp tersedia', Boolean(bagikan?.adaTautan), JSON.stringify(bagikan))
  lapor(
    'tautan WhatsApp memakai nomor yang sudah dinormalkan',
    String(bagikan?.href || '').startsWith('https://wa.me/6281234567890'),
    String(bagikan?.href || '').slice(0, 60),
  )
  // Teksnya harus memuat angka dari server, bukan hasil hitung ulang di layar.
  lapor(
    'teks WhatsApp memuat periode dan total dari server',
    /Februari%202027/.test(String(bagikan?.href || '')) &&
      /Total/.test(decodeURIComponent(String(bagikan?.href || ''))),
    decodeURIComponent(String(bagikan?.href || '')).slice(0, 120),
  )
  lapor('tombol salin rincian tersedia', Boolean(bagikan?.adaTombolSalin))

  // Tombol salin benar-benar menyalin: papan klip dibaca kembali.
  //
  // Dua hal harus disiapkan lebih dulu, dan keduanya soal peramban uji, bukan
  // soal aplikasi. Chrome menolak `navigator.clipboard` untuk dokumen yang
  // tidak difokuskan ("Document is not focused") - tab uji dibuat di latar
  // belakang - dan menolak menulis tanpa izin papan klip. Tanpa keduanya,
  // kegagalannya terbaca seperti tombol yang rusak padahal tombolnya benar.
  await kirim(ws, 'Emulation.setFocusEmulationEnabled', { enabled: true }).catch(() => {})
  await kirim(ws, 'Browser.grantPermissions', {
    origin: APP,
    permissions: ['clipboardReadWrite', 'clipboardSanitizedWrite'],
  }).catch(() => {})

  const salin = await evaluasi(ws, `
    (async () => {
      const kotak = document.querySelector('.bagikan')
      const tombol = [...kotak.querySelectorAll('button')]
        .find((b) => /salin/i.test(b.textContent))
      tombol.click()
      await new Promise((r) => setTimeout(r, 500))
      let isi = ''
      try { isi = await navigator.clipboard.readText() } catch { isi = '' }
      return { isi, label: tombol.textContent.trim() }
    })()
  `)
  lapor(
    'tombol salin menyalin teks tagihan',
    String(salin?.isi || '').includes('Total') && String(salin?.isi || '').includes('1.500.000'),
    String(salin?.isi || '').slice(0, 80),
  )
  lapor(
    'tombol salin memberi kabar "Tersalin"',
    /tersalin/i.test(String(salin?.label || '')),
    String(salin?.label),
  )
  await tangkapLayar(ws, 'r3-bagikan-tagihan')

  await uji.selesai()
})
