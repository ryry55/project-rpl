// Verifikasi end-to-end tombol pembalik pembayaran (REV-FIN-01) lewat layar.
//
// Endpoint `POST /pembayaran/{id}/pembalik` sudah ada dan teruji di backend
// sejak R2, tetapi sampai fase terakhir **tidak ada tombolnya**: satu-satunya
// cara menarik pembayaran yang salah catat adalah memanggil API dengan tangan.
// Skrip ini membuktikan tombolnya ada, bekerja, dan bahwa layar tidak lagi
// menawarkannya untuk baris yang sudah ditarik.
//
// Yang dibuktikan:
//   1. Tombol "Tarik kembali" muncul untuk pembayaran yang belum ditarik.
//   2. Menekannya membuka formulir yang menolak alasan kosong - server
//      menjawab 422 dan formulir tetap terbuka.
//   3. Dengan alasan terisi, penarikan tercatat, sisa tagihan kembali, dan
//      baris negatif muncul di riwayat.
//   4. Tombol untuk baris itu hilang, dan baris pembaliknya sendiri tidak
//      menawarkan penarikan lagi.
//   5. Tagihan yang sudah menerima uang kembali bisa dibatalkan - inilah
//      gunanya: pembatalan tadinya buntu karena uangnya menempel.
//
// Pakai: node docs/uji/uji-pembalik.mjs
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

const SIMULASI = '2026-11-10'
const PERIODE = '2026-11-01'
const POKOK = 1_500_000
const DIBAYAR = 600_000
const HEADER_SIMULASI = { 'X-Simulasi-Tanggal': SIMULASI }

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
  if (!kebijakan.ok) throw new Error('kebijakan kos gagal disiapkan: ' + kebijakan.alasan)

  // --- Siapkan kamar, kontrak, tagihan, dan satu pembayaran ----------------
  //
  // Dibuat lewat API, bukan lewat layar: yang sedang diuji adalah tombol
  // penarikan, bukan pembuatan tagihan. Membuatnya lewat layar akan menambah
  // dua puluh langkah yang sudah dibuktikan skrip lain.
  const siap = await evaluasi(ws, `
    (async () => {
      const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
        'X-Simulasi-Tanggal': '${SIMULASI}',
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

      const kamar = await panggil('/kamar', 'POST', { nomor: 'PB-A', tipe: 'Uji Pembalik' })
      if (kamar.status !== 201) return { galat: 'kamar: ' + JSON.stringify(kamar.data) }

      const tarif = await panggil('/tarif', 'POST', {
        kamar_id: kamar.data.data.id, nilai: ${POKOK}, berlaku_dari: '2026-01-01',
      })
      if (tarif.status !== 201) return { galat: 'tarif: ' + JSON.stringify(tarif.data) }

      const penghuni = await panggil('/penghuni', 'POST', {
        nama: 'Bayu Pembalik', email: 'pb-a@kos.test',
      })
      if (penghuni.status !== 201) return { galat: 'penghuni: ' + JSON.stringify(penghuni.data) }

      const kontrak = await panggil('/kontrak', 'POST', {
        kamar_id: kamar.data.data.id,
        penghuni_id: penghuni.data.data.id,
        tgl_masuk: '2026-01-15',
      })
      if (kontrak.status !== 201) return { galat: 'kontrak: ' + JSON.stringify(kontrak.data) }

      const tagihan = await panggil('/tagihan', 'POST', {
        kontrak_id: kontrak.data.data.id, periode: '${PERIODE}',
      })
      if (tagihan.status !== 201) return { galat: 'tagihan: ' + JSON.stringify(tagihan.data) }

      const bayar = await panggil('/tagihan/' + tagihan.data.data.id + '/pembayaran', 'POST', {
        jumlah: ${DIBAYAR}, tanggal_pembayaran: '${SIMULASI}', metode: 'transfer',
      })
      if (bayar.status !== 201) return { galat: 'pembayaran: ' + JSON.stringify(bayar.data) }

      return {
        tagihanId: tagihan.data.data.id,
        pembayaranId: bayar.data.data.pembayaran.id,
        sisa: bayar.data.data.sisa,
      }
    })()
  `)
  lapor('tagihan dan pembayaran uji siap', !siap.galat, siap.galat || `tagihan #${siap.tagihanId}`)
  if (siap.galat) throw new Error('data uji gagal disiapkan: ' + siap.galat)

  lapor(
    'sisa tagihan setelah pembayaran sebagian',
    Number(siap.sisa) === POKOK - DIBAYAR,
    `${siap.sisa} (diharapkan ${POKOK - DIBAYAR})`,
  )

  // --- Buka rincian tagihan ------------------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tagihan` })
  await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Tagihan')`)

  // Baris "belum ada" JUGA berupa `<tr>` di dalam `<tbody>` (BarisKosong.vue),
  // jadi menunggu `tbody tr` saja akan lolos sebelum datanya tiba - dan skrip
  // lalu melaporkan "baris tagihan tidak ditemukan" padahal tagihannya ada.
  // Yang ditunggu harus baris yang benar-benar memuat nama penghuninya.
  const tabelSiap = await tungguSampai(
    ws,
    `[...document.querySelectorAll('.tabel tbody tr')]
       .some(tr => tr.textContent.includes('Bayu Pembalik'))`,
  )
  lapor('daftar tagihan memuat baris uji', Boolean(tabelSiap), String(tabelSiap))

  const rincian = await evaluasi(ws, `
    (async () => {
      const baris = [...document.querySelectorAll('.tabel tbody tr')]
        .find(tr => tr.textContent.includes('Bayu Pembalik'))
      if (!baris) return { galat: 'baris tagihan tidak ditemukan' }
      baris.querySelector('button').click()
      await new Promise(r => setTimeout(r, 1400))
      const panel = [...document.querySelectorAll('.kartu.panel')]
        .find(p => /Bayu Pembalik/.test(p.textContent) && p.querySelector('.blok-bayar'))
      if (!panel) return { galat: 'panel rincian tidak muncul' }

      const tombol = [...panel.querySelectorAll('button')]
        .find(b => /tarik kembali/i.test(b.textContent))

      return {
        adaTombol: Boolean(tombol),
        teksPanel: panel.textContent.replace(/\\s+/g, ' ').trim().slice(0, 140),
      }
    })()
  `)
  lapor(
    'tombol "Tarik kembali" muncul untuk pembayaran yang belum ditarik',
    !rincian.galat && rincian.adaTombol === true,
    rincian.galat || rincian.teksPanel,
  )

  // --- Alasan kosong ditolak ----------------------------------------------
  //
  // Server menolak 422, dan formulir harus TETAP TERBUKA. Formulir yang
  // menutup diri saat gagal membuat pemilik mengira penarikannya berhasil.
  const kosong = await evaluasi(ws, `
    (async () => {
      const tombol = [...document.querySelectorAll('.blok-bayar button')]
        .find(b => /tarik kembali/i.test(b.textContent))
      if (!tombol) return { galat: 'tombol tidak ada' }
      tombol.click()
      await new Promise(r => setTimeout(r, 500))

      const form = document.querySelector('.form-balik')
      if (!form) return { galat: 'form penarikan tidak muncul' }

      // Alasan dikosongkan; atribut required dilewati dengan mengirim
      // submit langsung, supaya yang diuji adalah penolakan SERVER, bukan
      // validasi bawaan peramban.
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
      await new Promise(r => setTimeout(r, 1800))

      return {
        formMasihTerbuka: Boolean(document.querySelector('.form-balik')),
        adaPesan: Boolean(document.querySelector('.form-balik .pesan-galat, .form-balik [class*="galat"]')),
        teks: document.querySelector('.form-balik')?.textContent.replace(/\\s+/g, ' ').trim().slice(0, 120) || '',
      }
    })()
  `)
  lapor(
    'penarikan tanpa alasan ditolak dan formulir tetap terbuka',
    !kosong.galat && kosong.formMasihTerbuka === true,
    kosong.galat || kosong.teks,
  )

  // --- Tarik dengan alasan -------------------------------------------------
  const tarik = await evaluasi(ws, `
    (async () => {
      const form = document.querySelector('.form-balik')
      if (!form) return { galat: 'form penarikan tidak terbuka' }

      const alasan = document.querySelector('#balik-alasan')
      if (!alasan) return { galat: 'medan alasan tidak ada' }
      alasan.value = 'Salah catat: uang ini milik penghuni kamar sebelah'
      alasan.dispatchEvent(new Event('input', { bubbles: true }))
      await new Promise(r => setTimeout(r, 200))

      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
      await new Promise(r => setTimeout(r, 2600))

      const panel = [...document.querySelectorAll('.kartu.panel')]
        .find(p => /Bayu Pembalik/.test(p.textContent) && p.querySelector('.blok-bayar'))
      if (!panel) return { galat: 'panel rincian hilang setelah penarikan' }

      const teks = panel.textContent.replace(/\\s+/g, ' ').trim()
      const tombol = [...panel.querySelectorAll('button')]
        .find(b => /tarik kembali/i.test(b.textContent))

      return {
        teks,
        adaTombolLagi: Boolean(tombol),
        adaKonfirmasi: /ditarik kembali/i.test(teks),
        adaBarisNegatif: /-\\s*Rp\\s*600\\.000/.test(teks),
      }
    })()
  `)
  lapor(
    'penarikan dengan alasan tercatat dan dikonfirmasi di layar',
    !tarik.galat && tarik.adaKonfirmasi === true,
    tarik.galat || tarik.teks?.slice(0, 160),
  )
  lapor(
    'baris negatif muncul di riwayat pembayaran',
    !tarik.galat && tarik.adaBarisNegatif === true,
    tarik.galat || (tarik.adaBarisNegatif ? 'ada' : 'tidak ditemukan'),
  )
  lapor(
    'tombol penarikan hilang untuk baris yang sudah ditarik',
    !tarik.galat && tarik.adaTombolLagi === false,
    tarik.galat || `tombol=${tarik.adaTombolLagi}`,
  )

  await tangkapLayar(ws, 'PB-pembalik')

  // --- Sisa tagihan kembali, dan pembatalan jadi mungkin -------------------
  //
  // Inilah gunanya penarikan: sebelum uangnya ditarik, pembatalan tagihan
  // ditolak 422 karena uang yang sudah masuk akan menempel pada apa pun.
  const sesudah = await panggilApi(ws, `/tagihan/${siap.tagihanId}`, 'GET', null, HEADER_SIMULASI)
  const barisPembayaran = sesudah.data?.data?.pembayaran || []
  const totalDibayar = barisPembayaran.reduce((j, p) => j + Number(p.jumlah), 0)
  lapor(
    'jumlah pembayaran bersih kembali nol setelah penarikan',
    totalDibayar === 0,
    `total ${totalDibayar} dari ${barisPembayaran.length} baris`,
  )
  lapor(
    'riwayat memuat dua baris: pembayaran dan pembaliknya',
    barisPembayaran.length === 2 &&
      barisPembayaran.some((p) => p.adalah_pembalik) &&
      barisPembayaran.some((p) => !p.adalah_pembalik),
    barisPembayaran.map((p) => `${p.jumlah}${p.adalah_pembalik ? '(balik)' : ''}`).join(', '),
  )

  const batal = await panggilApi(
    ws,
    `/tagihan/${siap.tagihanId}/batal`,
    'POST',
    { alasan: 'Salah terbit; dibuat ulang setelah pembayaran ditarik' },
    HEADER_SIMULASI,
  )
  lapor(
    'tagihan bisa dibatalkan setelah pembayarannya ditarik',
    batal.status === 200,
    `status ${batal.status}`,
  )

  const gagal = await uji.selesai()
  if (gagal.length) {
    console.error('\nLangkah gagal: ' + gagal.map((g) => g.nama).join('; '))
  }
}

jalankan(main)
