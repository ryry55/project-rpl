// Verifikasi end-to-end T20 dan T21 lewat browser sungguhan.
//
// Yang dibuktikan, berurutan:
//
//   1. Metode bayar wajib dipilih. Formulir yang menyimpan tanpa metode harus
//      ditolak, bukan diberi nilai bawaan - karena nilai bawaan "tunai" akan
//      mencatat setiap transfer sebagai uang tunai.
//   2. Pemegang uang hanya ditanyakan untuk tunai. Untuk transfer medannya
//      tidak muncul, karena jawabannya memang tidak ada.
//   3. Riwayat pembayaran menampilkan metode dan keadaan pemeriksaan, dan
//      "belum diperiksa" berbeda dari "ditolak".
//   4. Verifikasi bisa dilakukan dari layar, menolak tanpa alasan ditolak, dan
//      pemeriksaan ulang menambah baris tanpa mengubah jumlah uang.
//   5. Rekap mengelompokkan uang per metode, dan DENDA TIDAK masuk pendapatan.
//      Ini inti T21: angka "pendapatan" yang menyertakan denda belum dibayar
//      adalah angka yang salah, dan pemilik akan menyalahkan aplikasinya.
//   6. Rekap memisahkan tunai per pemegang, karena pertanyaan sebenarnya bagi
//      pemilik yang tidak tinggal di lokasi adalah "setoran siapa yang masih
//      di tangan pengelola".
//
// Pakai: node docs/uji/e2e-t20.mjs
// Prasyarat: docker compose up, Chrome --remote-debugging-port=9222,
// APP_DEMO_MODE=true, database sudah di-seed (migrate:fresh --seed).
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

/**
 * Menyiapkan kamar, tarif, penghuni, kontrak, dan satu tagihan.
 *
 * Idempoten: seluruhnya dicari dulu, dibuat hanya bila belum ada. `periksa.ps1`
 * menjalankan skrip ini berulang tanpa `migrate:fresh`, dan kegagalan "nomor
 * kamar sudah dipakai" tidak mengatakan apa pun tentang T20/T21.
 */
async function siapkanData(ws) {
  const kamarAda = await panggilApi(ws, '/kamar')
  if (kamarAda.status !== 200) {
    return { ok: false, alasan: `GET /kamar gagal (${kamarAda.status})` }
  }

  let kamar = (kamarAda.data?.data || []).find((k) => k.nomor === 'T20-A')
  if (!kamar) {
    const buat = await panggilApi(ws, '/kamar', 'POST', { nomor: 'T20-A', tipe: 'Uji T20' })
    if (buat.status !== 201) {
      return { ok: false, alasan: `POST /kamar gagal (${buat.status})` }
    }
    kamar = buat.data.data
  }

  // Tarif diperiksa dari `tarif_terakhir` di daftar kamar, bukan dari
  // `/tarif-berlaku`: endpoint itu menjawab bentuk lain dan ketiadaan tarif
  // tidak selalu terlihat sebagai nilai kosong.
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

  const penghuniAda = await panggilApi(ws, '/penghuni')
  let penghuni = (penghuniAda.data?.data || []).find((p) => p.email === 't20@kos.test')
  if (!penghuni) {
    const buat = await panggilApi(ws, '/penghuni', 'POST', {
      nama: 'Gita Dua Puluh',
      email: 't20@kos.test',
    })
    if (buat.status !== 201) {
      return { ok: false, alasan: `POST /penghuni gagal (${buat.status})` }
    }
    penghuni = buat.data.data
  }

  const kontrakAda = await panggilApi(ws, '/kontrak')
  let kontrak = (kontrakAda.data?.data || []).find(
    (k) => k.status === 'aktif' && k.kamar_id === kamar.id,
  )
  if (!kontrak) {
    const buat = await panggilApi(ws, '/kontrak', 'POST', {
      kamar_id: kamar.id,
      penghuni_id: penghuni.id,
      tgl_masuk: '2026-01-15',
    })
    if (buat.status !== 201) {
      return { ok: false, alasan: `POST /kontrak gagal (${buat.status})` }
    }
    kontrak = buat.data.data
  }

  // Tagihan: terbitkan bila belum ada. 409 berarti periode itu sudah punya
  // tagihan - keadaan yang sama baiknya untuk uji ini.
  const tagihanAda = await panggilApi(ws, `/tagihan?kontrak_id=${kontrak.id}`)
  let tagihan = (tagihanAda.data?.data || []).find((t) => t.periode === PERIODE)
  if (!tagihan) {
    const buat = await panggilApi(ws, '/tagihan', 'POST', {
      kontrak_id: kontrak.id,
      periode: PERIODE,
    })
    if (buat.status !== 201) {
      return { ok: false, alasan: `POST /tagihan gagal (${buat.status})` }
    }
    tagihan = buat.data.data
  }

  return { ok: true, tagihanId: tagihan.id, alasan: 'siap' }
}

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  // --- Login -------------------------------------------------------------
  const masuk = await masukSebagai(ws, 'pemilik@kos.test')
  lapor('login pemilik', masuk, await evaluasi(ws, 'location.pathname'))
  if (!masuk) {
    await uji.selesai()
    throw new Error('login gagal, sisa langkah tidak bisa dijalankan')
  }

  // Gerbang kebijakan (T16/T17): dinyatakan sekali di sini supaya langkah
  // berikutnya tidak gagal karena alasan yang tidak sedang diuji.
  const kebijakan = await pastikanKebijakan(ws)
  lapor('kebijakan kos siap', kebijakan.ok, kebijakan.alasan)
  if (!kebijakan.ok) {
    await uji.selesai()
    throw new Error('kebijakan kos gagal disiapkan: ' + kebijakan.alasan)
  }

  const data = await siapkanData(ws)
  lapor('tagihan uji siap', data.ok, data.alasan || `tagihan #${data.tagihanId}`)
  if (!data.ok) {
    await uji.selesai()
    throw new Error('data uji gagal disiapkan: ' + data.alasan)
  }

  // --- Halaman Tagihan dengan simulasi tanggal ---------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tagihan` })
  await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Tagihan')`)
  await tungguSampai(ws, `document.querySelector('.tabel tbody tr')`)

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

  // --- Buka rincian tagihan ---------------------------------------------
  const rincian = await evaluasi(ws, `
    (async () => {
      const baris = [...document.querySelectorAll('.tabel tbody tr')]
        .find(tr => tr.textContent.includes('Gita Dua Puluh'))
      if (!baris) return { galat: 'baris tagihan tidak ditemukan' }
      baris.querySelector('button').click()
      await new Promise(r => setTimeout(r, 1400))
      const panel = [...document.querySelectorAll('.kartu.panel')]
        .find(p => /Gita Dua Puluh/.test(p.textContent) && p.querySelector('.blok-bayar'))
      return { ada: Boolean(panel) }
    })()
  `)
  lapor('rincian tagihan terbuka', rincian.ada, rincian.galat || '')

  // --- Metode wajib: menyimpan tanpa metode harus ditolak ----------------
  const tanpaMetode = await evaluasi(ws, `
    (async () => {
      const tombol = [...document.querySelectorAll('.blok-bayar button')]
        .find(b => /catat pembayaran/i.test(b.textContent))
      if (!tombol) return { galat: 'tombol catat pembayaran tidak ada' }
      tombol.click()
      await new Promise(r => setTimeout(r, 500))

      const metode = document.querySelector('#bayar-metode')
      if (!metode) return { galat: 'medan metode tidak ada' }
      const nilaiAwal = metode.value

      const jumlah = document.querySelector('#bayar-jumlah')
      jumlah.value = '100000'
      jumlah.dispatchEvent(new Event('input', { bubbles: true }))
      const tanggalEl = document.querySelector('#bayar-tanggal')
      tanggalEl.value = '${SIMULASI}'
      tanggalEl.dispatchEvent(new Event('input', { bubbles: true }))
      await new Promise(r => setTimeout(r, 150))

      // Klik tombol simpan: browser menahan submit karena atribut required pada
      // select kosong, dan itulah yang seharusnya terjadi. Diperiksa sebagai
      // dua hal sekaligus: tidak ada baris yang tersimpan, dan formulirnya
      // tetap terbuka.
      const simpan = document.querySelector('.form-bayar button[type="submit"]')
      simpan.click()
      await new Promise(r => setTimeout(r, 1200))

      return {
        nilaiAwal,
        metodeValid: metode.checkValidity(),
        formMasihTerbuka: Boolean(document.querySelector('.form-bayar')),
      }
    })()
  `)
  lapor(
    'metode tidak diberi nilai bawaan',
    !tanpaMetode.galat && tanpaMetode.nilaiAwal === '',
    tanpaMetode.galat || `nilai awal "${tanpaMetode.nilaiAwal}"`,
  )
  lapor(
    'formulir menolak disimpan tanpa metode',
    !tanpaMetode.galat &&
      tanpaMetode.metodeValid === false &&
      tanpaMetode.formMasihTerbuka === true,
    tanpaMetode.galat ||
      `valid=${tanpaMetode.metodeValid} form=${tanpaMetode.formMasihTerbuka}`,
  )

  // --- Pemegang uang hanya untuk tunai ----------------------------------
  const pemegang = await evaluasi(ws, `
    (async () => {
      const pilih = (sel, v) => {
        const el = document.querySelector(sel)
        el.value = v
        el.dispatchEvent(new Event('change', { bubbles: true }))
        el.dispatchEvent(new Event('input', { bubbles: true }))
      }
      pilih('#bayar-metode', 'transfer')
      await new Promise(r => setTimeout(r, 300))
      const saatTransfer = Boolean(document.querySelector('#bayar-penerima'))

      pilih('#bayar-metode', 'tunai')
      await new Promise(r => setTimeout(r, 300))
      const saatTunai = Boolean(document.querySelector('#bayar-penerima'))

      return { saatTransfer, saatTunai }
    })()
  `)
  lapor(
    'pemegang uang hanya ditanyakan untuk tunai',
    pemegang.saatTransfer === false && pemegang.saatTunai === true,
    `transfer=${pemegang.saatTransfer} tunai=${pemegang.saatTunai}`,
  )

  // --- Catat pembayaran tunai dengan referensi --------------------------
  const tunai = await evaluasi(ws, `
    (async () => {
      const set = (sel, v, ev = 'input') => {
        const el = document.querySelector(sel)
        if (!el) return false
        el.value = v
        el.dispatchEvent(new Event(ev, { bubbles: true }))
        return true
      }
      set('#bayar-jumlah', '600000')
      set('#bayar-tanggal', '${SIMULASI}')
      set('#bayar-metode', 'tunai', 'change')
      await new Promise(r => setTimeout(r, 300))
      set('#bayar-referensi', 'TUNAI-001')
      await new Promise(r => setTimeout(r, 200))

      document.querySelector('.form-bayar').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      await new Promise(r => setTimeout(r, 2500))

      const panel = [...document.querySelectorAll('.kartu.panel')]
        .find(p => /Gita Dua Puluh/.test(p.textContent))
      const tabel = panel?.querySelector('.tabel-riwayat')
      return {
        sukses: Boolean(panel?.querySelector('.pesan-sukses')),
        baris: [...(tabel?.querySelectorAll('tbody tr') || [])]
          .map(tr => tr.textContent.replace(/\\s+/g, ' ').trim()),
      }
    })()
  `)
  lapor(
    'pembayaran tunai tersimpan',
    tunai.sukses && tunai.baris.length === 1,
    `sukses=${tunai.sukses} baris=${tunai.baris.length}`,
  )
  lapor(
    'riwayat menampilkan metode dan referensinya',
    (tunai.baris[0] || '').includes('Tunai') && (tunai.baris[0] || '').includes('TUNAI-001'),
    String(tunai.baris[0] || ''),
  )
  lapor(
    'riwayat menampilkan pemegang uang tunai',
    (tunai.baris[0] || '').includes('dipegang'),
    String(tunai.baris[0] || ''),
  )
  lapor(
    'pembayaran baru berstatus belum diperiksa',
    (tunai.baris[0] || '').includes('Belum diperiksa'),
    String(tunai.baris[0] || ''),
  )

  // --- Verifikasi: tolak tanpa alasan harus ditolak ----------------------
  const tolakKosong = await evaluasi(ws, `
    (async () => {
      const tombol = [...document.querySelectorAll('.tombol-verifikasi')][0]
      if (!tombol) return { galat: 'tombol verifikasi tidak ada' }
      tombol.click()
      await new Promise(r => setTimeout(r, 500))

      const pilih = document.querySelector('#verifikasi-keputusan')
      if (!pilih) return { galat: 'formulir verifikasi tidak muncul' }
      pilih.value = 'tolak'
      pilih.dispatchEvent(new Event('change', { bubbles: true }))
      await new Promise(r => setTimeout(r, 300))

      // Catatan dikosongkan, lalu disimpan. Atribut required pada medan catatan
      // menahan submit - dan itu memang yang harus terjadi.
      const catatan = document.querySelector('#verifikasi-catatan')
      catatan.value = ''
      catatan.dispatchEvent(new Event('input', { bubbles: true }))
      await new Promise(r => setTimeout(r, 200))

      document.querySelector('.verifikasi-form button[type="submit"]').click()
      await new Promise(r => setTimeout(r, 1200))

      return {
        catatanValid: catatan.checkValidity(),
        formMasihTerbuka: Boolean(document.querySelector('.verifikasi-form')),
      }
    })()
  `)
  lapor(
    'penolakan tanpa alasan ditahan di layar',
    !tolakKosong.galat &&
      tolakKosong.catatanValid === false &&
      tolakKosong.formMasihTerbuka === true,
    tolakKosong.galat ||
      `valid=${tolakKosong.catatanValid} form=${tolakKosong.formMasihTerbuka}`,
  )

  // --- Verifikasi: terima ------------------------------------------------
  const terima = await evaluasi(ws, `
    (async () => {
      const pilih = document.querySelector('#verifikasi-keputusan')
      if (!pilih) return { galat: 'formulir verifikasi sudah tertutup' }
      pilih.value = 'terima'
      pilih.dispatchEvent(new Event('change', { bubbles: true }))
      await new Promise(r => setTimeout(r, 300))

      const catatan = document.querySelector('#verifikasi-catatan')
      catatan.value = 'Cocok dengan setoran'
      catatan.dispatchEvent(new Event('input', { bubbles: true }))
      await new Promise(r => setTimeout(r, 200))

      document.querySelector('.verifikasi-form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      await new Promise(r => setTimeout(r, 2500))

      const panel = [...document.querySelectorAll('.kartu.panel')]
        .find(p => /Gita Dua Puluh/.test(p.textContent))
      const baris = panel?.querySelector('.tabel-riwayat tbody tr')
      return {
        teks: baris?.textContent.replace(/\\s+/g, ' ').trim() || '',
        formTertutup: !document.querySelector('.verifikasi-form'),
      }
    })()
  `)
  lapor(
    'verifikasi tersimpan dan formulirnya tertutup',
    terima.formTertutup && terima.teks.includes('Diterima'),
    terima.teks,
  )
  lapor(
    'catatan pemeriksaan ikut tampil',
    terima.teks.includes('Cocok dengan setoran'),
    terima.teks,
  )

  // --- Pemeriksaan ulang menambah baris, tidak mengubah uang -------------
  const sebelum = await panggilApi(ws, `/tagihan/${data.tagihanId}`)
  const jumlahSebelum = sebelum.data?.data?.pembayaran?.[0]?.jumlah
  const dibayarSebelum = sebelum.data?.data?.dibayar

  const periksaUlang = await evaluasi(ws, `
    (async () => {
      const tombol = [...document.querySelectorAll('.tombol-verifikasi')][0]
      if (!tombol) return { galat: 'tombol periksa ulang tidak ada' }
      const label = tombol.textContent.trim()
      tombol.click()
      await new Promise(r => setTimeout(r, 500))

      const pilih = document.querySelector('#verifikasi-keputusan')
      pilih.value = 'tolak'
      pilih.dispatchEvent(new Event('change', { bubbles: true }))
      await new Promise(r => setTimeout(r, 250))

      const catatan = document.querySelector('#verifikasi-catatan')
      catatan.value = 'Setoran bank tidak ditemukan'
      catatan.dispatchEvent(new Event('input', { bubbles: true }))
      await new Promise(r => setTimeout(r, 200))

      document.querySelector('.verifikasi-form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      await new Promise(r => setTimeout(r, 2500))

      const panel = [...document.querySelectorAll('.kartu.panel')]
        .find(p => /Gita Dua Puluh/.test(p.textContent))
      return {
        label,
        teks: panel?.querySelector('.tabel-riwayat tbody tr')
          ?.textContent.replace(/\\s+/g, ' ').trim() || '',
      }
    })()
  `)
  lapor(
    'tombol berubah menjadi periksa ulang setelah diperiksa',
    periksaUlang.label === 'Periksa ulang',
    periksaUlang.label,
  )
  lapor(
    'yang berlaku adalah pemeriksaan terakhir',
    periksaUlang.teks.includes('Ditolak'),
    periksaUlang.teks,
  )

  const sesudah = await panggilApi(ws, `/tagihan/${data.tagihanId}`)
  const jumlahSesudah = sesudah.data?.data?.pembayaran?.[0]?.jumlah
  const dibayarSesudah = sesudah.data?.data?.dibayar

  // Inti T20: pemeriksaan TIDAK mengubah angka uang. Kalau ia mengubahnya,
  // "verifikasi" menjadi cara menyunting pembayaran tanpa meninggalkan baris.
  lapor(
    'pemeriksaan tidak mengubah jumlah uang',
    jumlahSebelum === jumlahSesudah && dibayarSebelum === dibayarSesudah,
    `jumlah ${jumlahSebelum}->${jumlahSesudah}, dibayar ${dibayarSebelum}->${dibayarSesudah}`,
  )

  await tangkapLayar(ws, 'T20-verifikasi-pembayaran')

  // --- Rekap: denda bukan pendapatan ------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/rekap` })
  await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Rekap')`)
  await tungguSampai(ws, `document.querySelector('.kisi-metode')`, 15000)

  const rekapLayar = await evaluasi(ws, `
    (async () => {
      const set = (sel, v, ev = 'input') => {
        const el = document.querySelector(sel)
        if (!el) return false
        el.value = v
        el.dispatchEvent(new Event(ev, { bubbles: true }))
        return true
      }
      set('#rekap-dari', '2026-11-01')
      set('#rekap-sampai', '2026-11-30')
      await new Promise(r => setTimeout(r, 200))
      const tombol = [...document.querySelectorAll('button')]
        .find(b => /tampilkan/i.test(b.textContent))
      if (!tombol) return { galat: 'tombol tampilkan tidak ada' }
      tombol.click()
      await new Promise(r => setTimeout(r, 2500))

      const kartu = [...document.querySelectorAll('.kartu-metode')]
        .map(k => ({
          judul: k.querySelector('.metode-judul')?.textContent.trim() || '',
          angka: k.querySelector('.metode-angka')?.textContent.trim() || '',
        }))
      const piutang = document.querySelector('.panel-piutang')?.textContent
        ?.replace(/\\s+/g, ' ').trim() || ''
      const tunai = [...document.querySelectorAll('.tabel-tunai tbody tr')]
        .map(tr => tr.textContent.replace(/\\s+/g, ' ').trim())
      return {
        kartu,
        piutang,
        tunai,
        judul: document.querySelector('h1')?.textContent.trim() || '',
        jumlahBaris: document.querySelectorAll('.tabel-pembayaran tbody tr').length,
      }
    })()
  `)

  lapor(
    'rekap menampilkan empat kelompok metode',
    !rekapLayar.galat && rekapLayar.kartu.length === 4,
    rekapLayar.galat || JSON.stringify(rekapLayar.kartu.map((k) => k.judul)),
  )

  const kartuTunai = (rekapLayar.kartu || []).find((k) => k.judul === 'Tunai')
  lapor(
    'kelompok tunai memuat uang yang baru dicatat',
    Boolean(kartuTunai) && /600\.000/.test(kartuTunai.angka),
    String(kartuTunai?.angka || ''),
  )

  // Inti T21. Dua angka harus terlihat sebagai dua hal berbeda: pendapatan
  // (uang masuk) dan piutang (denda yang belum dibayar). Kalau denda
  // dijumlahkan diam-diam ke total, angka "piutang" tidak akan muncul dan
  // pemilik membandingkan rekap dengan rekeningnya tanpa penjelasan.
  lapor(
    'rekap memisahkan piutang dari pendapatan',
    /Piutang/i.test(rekapLayar.piutang) && /Denda belum dibayar/i.test(rekapLayar.piutang),
    rekapLayar.piutang.slice(0, 160),
  )

  // Nama pemegang dibaca dari sesi, bukan ditulis tetap di skrip: nama pemilik
  // berasal dari seeder, dan skrip yang menuliskannya akan gagal setiap kali
  // data contoh berubah - kegagalan yang tidak mengatakan apa pun tentang T21.
  const saya = await panggilApi(ws, '/me')
  const namaSaya = saya.data?.data?.nama || ''

  lapor(
    'rekap menjawab setoran tunai siapa yang masih di tangan',
    namaSaya !== '' && (rekapLayar.tunai || []).some((t) => t.includes(namaSaya)),
    `${namaSaya} | ${JSON.stringify(rekapLayar.tunai)}`,
  )

  lapor(
    'daftar pembayaran menyebut penghuni',
    rekapLayar.jumlahBaris > 0,
    `${rekapLayar.jumlahBaris} baris`,
  )

  // --- Angka API sama dengan angka layar --------------------------------
  const apiRekap = await panggilApi(ws, '/rekap?dari=2026-11-01&sampai=2026-11-30')
  const totalApi = apiRekap.data?.data?.total || 0
  const tunaiApi = (apiRekap.data?.data?.per_metode || []).find((m) => m.metode === 'tunai')

  lapor(
    'API: total rekap = jumlah pembayaran, bukan total tagihan',
    apiRekap.status === 200 && totalApi === 600_000,
    `total ${totalApi}`,
  )
  lapor(
    'API: kelompok tunai memuat 600.000',
    Boolean(tunaiApi) && tunaiApi.jumlah === 600_000 && tunaiApi.transaksi === 1,
    JSON.stringify(tunaiApi || {}),
  )

  // Denda tagihan yang belum dibayar harus muncul sebagai piutang, dan
  // TIDAK boleh menambah `total`.
  const piutangApi = apiRekap.data?.data?.piutang || {}
  lapor(
    'API: denda terpisah sebagai piutang',
    typeof piutangApi.denda_belum_dibayar === 'number' &&
      piutangApi.denda_belum_dibayar >= 0,
    JSON.stringify(piutangApi),
  )

  // Inti T21, diuji dengan denda yang benar-benar berjalan.
  //
  // Tanggal simulasi digeser ke 2026-12-20: tagihan periode November jatuh
  // tempo 15 November, jadi sudah lewat tenggang dan dendanya berjalan. Yang
  // harus terjadi: `piutang.denda_belum_dibayar` naik, dan `total` TETAP
  // sebesar uang yang benar-benar diterima. Rekap yang ikut menambahkan denda
  // ke total akan membuat pemilik membandingkan angkanya dengan rekening dan
  // menyimpulkan ada uang hilang.
  const rekapLewatTempo = await evaluasi(ws, `
    (async () => {
      const res = await fetch('${API}/rekap?dari=2026-11-01&sampai=2026-11-30', {
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
          'X-Simulasi-Tanggal': '2026-12-20',
        },
      })
      const isi = await res.json()
      return isi.data
    })()
  `)

  lapor(
    'denda yang berjalan TIDAK menambah pendapatan',
    rekapLewatTempo.total === 600_000 &&
      rekapLewatTempo.piutang.denda_belum_dibayar > 0,
    `total ${rekapLewatTempo.total}, denda ${rekapLewatTempo.piutang.denda_belum_dibayar}`,
  )
  lapor(
    'denda dilaporkan sebagai piutang terpisah',
    rekapLewatTempo.piutang.denda_belum_dibayar === 420_000,
    `denda ${rekapLewatTempo.piutang.denda_belum_dibayar} (diharapkan 420.000)`,
  )

  await tangkapLayar(ws, 'T21-rekap-pendapatan')

  // --- Pembalik pembayaran (REV-FIN-01) ---------------------------------
  //
  // Sampai R2 satu-satunya cara membetulkan salah catat adalah menyunting
  // barisnya, dan itu menghapus fakta bahwa angka salah pernah tercatat. Yang
  // dibuktikan di sini: baris negatif lahir, saldo tagihan kembali, dan rekap
  // memisahkan pembalik dari transaksi - sehingga "12 transaksi" tidak menjadi
  // "13 transaksi" hanya karena ada satu koreksi.
  const idPembayaran = await evaluasi(ws, `
    (async () => {
      const res = await fetch('${API}/pembayaran?dari=2026-11-01&sampai=2026-11-30', {
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
        },
      })
      const isi = await res.json()
      const baris = (isi.data || []).find((p) => !p.adalah_pembalik)
      return baris ? baris.id : null
    })()
  `)

  lapor('pembayaran yang akan dibalik ditemukan', Number.isInteger(idPembayaran), `id ${idPembayaran}`)

  const tanpaAlasan = await panggilApi(ws, `/pembayaran/${idPembayaran}/pembalik`, 'POST', { alasan: '' })
  lapor(
    'pembalikan tanpa alasan ditolak 422',
    tanpaAlasan.status === 422,
    `status ${tanpaAlasan.status}`,
  )

  // Pembalikan bertanggal HARI INI, bukan tanggal pembayaran asalnya - itu
  // keputusan yang disengaja (membalik bukan mengubah buku bulan lalu). Karena
  // pembayaran di atas dicatat pada tanggal simulasi 10 November, pembalikannya
  // juga digeser ke tanggal simulasi supaya keduanya jatuh di rentang yang sama.
  // Di produksi hal ini tidak perlu: pembayaran tidak pernah bertanggal masa
  // depan, jadi pembalikan selalu jatuh pada atau setelah tanggal asalnya.
  const balik = await panggilApi(
    ws,
    `/pembayaran/${idPembayaran}/pembalik`,
    'POST',
    { alasan: 'Salah ketik nominal, seharusnya 300.000' },
    { 'X-Simulasi-Tanggal': '2026-11-20' },
  )
  lapor(
    'pembalikan tersimpan sebagai baris negatif',
    balik.status === 201 && balik.data?.data?.pembalik?.jumlah === -600_000,
    `status ${balik.status}, jumlah ${balik.data?.data?.pembalik?.jumlah}`,
  )
  lapor(
    'status tagihan turun kembali setelah pembalikan',
    balik.data?.data?.status_tagihan === 'belum_bayar',
    `status ${balik.data?.data?.status_tagihan}`,
  )

  const balikLagi = await panggilApi(ws, `/pembayaran/${idPembayaran}/pembalik`, 'POST', {
    alasan: 'Coba dua kali',
  })
  lapor(
    'satu pembayaran hanya bisa dibalik sekali',
    balikLagi.status === 422,
    `status ${balikLagi.status}`,
  )

  const rekapSetelahBalik = await panggilApi(ws, '/rekap?dari=2026-11-01&sampai=2026-11-30')
  const dataSetelah = rekapSetelahBalik.data?.data || {}
  lapor(
    'pendapatan bersih kembali nol setelah pembalikan',
    dataSetelah.total === 0,
    `total ${dataSetelah.total}`,
  )
  lapor(
    'pembalik dilaporkan terpisah, bukan sebagai transaksi',
    dataSetelah.jumlah_transaksi === 1 &&
      dataSetelah.pembalik?.transaksi === 1 &&
      dataSetelah.pembalik?.jumlah === -600_000,
    `transaksi ${dataSetelah.jumlah_transaksi}, pembalik ${JSON.stringify(dataSetelah.pembalik)}`,
  )

  await uji.selesai()
})
