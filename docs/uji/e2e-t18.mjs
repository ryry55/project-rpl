// Verifikasi end-to-end T18 lewat browser sungguhan.
//
// Yang dibuktikan: fasilitas dapat dikelola dari layarnya, dapat dicentang per
// kamar, dan - yang paling penting - mencentang fasilitas TIDAK mengubah uang
// sama sekali. Pemisahan "kamar ini punya apa" dari "kamar ini ditagih apa"
// adalah alasan tiket ini ada; kalau pemisahan itu bocor, biaya muncul tanpa
// ada yang menyetujuinya.
//
// Pakai: node docs/uji/e2e-t18.mjs
// Prasyarat: docker compose up sudah jalan, Chrome --remote-debugging-port=9222,
// APP_DEMO_MODE=true, dan database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo.

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

const NAMA_UJI = 'Fasilitas Uji T18'

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  try {
    const masuk = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik berhasil', masuk)
    if (!masuk) throw new Error('login gagal')

    const kebijakan = await pastikanKebijakan(ws)
    lapor('kebijakan kos siap', kebijakan.ok, kebijakan.alasan)
    if (!kebijakan.ok) throw new Error('kebijakan gagal disiapkan')

    // --- Halaman Fasilitas -------------------------------------------------
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/fasilitas` })
    const terbuka = await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Fasilitas')`)
    lapor('halaman Fasilitas terbuka', terbuka)

    const adaMenu = await tungguSampai(
      ws,
      `[...document.querySelectorAll('nav a')].some(a => a.textContent.includes('Fasilitas'))`,
      6000,
    )
    lapor('menu Fasilitas ada di navigasi pemilik', adaMenu)

    // Seeder menyemai enam fasilitas; daftarnya harus terbaca di tabel.
    const barisAwal = await evaluasi(
      ws,
      `document.querySelectorAll('table.tabel tbody tr').length`,
    )
    lapor('tabel fasilitas terisi dari seeder', barisAwal >= 6, `${barisAwal} baris`)

    // --- Tambah fasilitas lewat formulir ----------------------------------
    const ditambah = await evaluasi(
      ws,
      `
      (async () => {
        const tombol = [...document.querySelectorAll('button')]
          .find(b => /tambah fasilitas/i.test(b.textContent))
        if (!tombol) return 'tombol tambah tidak ada'
        tombol.click()
        await new Promise(r => setTimeout(r, 300))

        const nama = document.querySelector('#nama-fasilitas')
        if (!nama) return 'medan nama tidak ada'
        nama.value = ${JSON.stringify(NAMA_UJI)}
        nama.dispatchEvent(new Event('input', { bubbles: true }))

        const urutan = document.querySelector('#urutan-fasilitas')
        urutan.value = '99'
        urutan.dispatchEvent(new Event('input', { bubbles: true }))

        document.querySelector('form').dispatchEvent(
          new Event('submit', { bubbles: true, cancelable: true }))
        await new Promise(r => setTimeout(r, 1200))
        return document.querySelector('.tabel')?.textContent || ''
      })()
    `,
    )
    lapor(
      'fasilitas baru tersimpan lewat formulir',
      typeof ditambah === 'string' && ditambah.includes(NAMA_UJI),
      typeof ditambah === 'string' ? '' : String(ditambah),
    )

    // Nama duplikat harus ditolak dengan pesan yang terbaca, bukan diam-diam
    // membuat baris kedua yang tidak bisa dibedakan.
    const duplikat = await evaluasi(
      ws,
      `
      (async () => {
        const tombol = [...document.querySelectorAll('button')]
          .find(b => /tambah fasilitas/i.test(b.textContent))
        if (!tombol) return 'tombol tidak ada'
        tombol.click()
        await new Promise(r => setTimeout(r, 300))
        const nama = document.querySelector('#nama-fasilitas')
        nama.value = ${JSON.stringify(NAMA_UJI)}
        nama.dispatchEvent(new Event('input', { bubbles: true }))
        document.querySelector('form').dispatchEvent(
          new Event('submit', { bubbles: true, cancelable: true }))
        await new Promise(r => setTimeout(r, 900))
        return document.querySelector('.galat-bidang')?.textContent || ''
      })()
    `,
    )
    lapor(
      'nama fasilitas duplikat ditolak dengan pesan di medan',
      teksMemuat(String(duplikat), 'sudah ada'),
      String(duplikat),
    )

    // Tutup formulir yang masih terbuka.
    await evaluasi(
      ws,
      `
      (() => {
        const b = [...document.querySelectorAll('button')].find(x => /^batal$/i.test(x.textContent.trim()))
        if (b) b.click()
        return true
      })()
    `,
    )

    const daftarApi = await panggilApi(ws, '/fasilitas')
    const fasilitasUji = (daftarApi.data?.data || []).find((f) => f.nama === NAMA_UJI)
    lapor('fasilitas uji ada di API', Boolean(fasilitasUji), fasilitasUji ? `id ${fasilitasUji.id}` : 'tidak ada')
    if (!fasilitasUji) throw new Error('fasilitas uji tidak tersimpan')

    // --- Centang per kamar lewat layar Kamar ------------------------------
    const kamar = await panggilApi(ws, '/kamar', 'POST', { nomor: 'T18-A', tipe: 'Uji T18' })
    lapor('kamar uji dibuat', kamar.status === 201, String(kamar.status))
    const kamarId = kamar.data?.data?.id

    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/kamar` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Kamar')`)

    const dibuka = await evaluasi(
      ws,
      `
      (async () => {
        const baris = [...document.querySelectorAll('table.tabel tbody tr')]
          .find(tr => tr.textContent.includes('T18-A'))
        if (!baris) return 'baris kamar tidak ada'
        const tombol = [...baris.querySelectorAll('button')]
          .find(b => /fasilitas/i.test(b.textContent))
        if (!tombol) return 'tombol fasilitas tidak ada'
        tombol.click()
        await new Promise(r => setTimeout(r, 1200))
        return {
          panel: Boolean([...document.querySelectorAll('h2')].find(h => /fasilitas kamar t18-a/i.test(h.textContent))),
          jumlahCentang: document.querySelectorAll('.kisi-fasilitas input[type="checkbox"]').length,
        }
      })()
    `,
    )
    lapor(
      'panel fasilitas kamar terbuka dengan daftar centang',
      typeof dibuka === 'object' && dibuka.panel && dibuka.jumlahCentang >= 6,
      JSON.stringify(dibuka),
    )

    const dicentang = await evaluasi(
      ws,
      `
      (async () => {
        const kotak = [...document.querySelectorAll('.kisi-fasilitas input[type="checkbox"]')]
        const target = kotak.find(k => k.closest('label').textContent.includes(${JSON.stringify(NAMA_UJI)}))
        if (!target) return 'kotak tidak ada'
        target.checked = true
        target.dispatchEvent(new Event('change', { bubbles: true }))
        await new Promise(r => setTimeout(r, 200))
        const simpan = [...document.querySelectorAll('button')]
          .find(b => /simpan fasilitas/i.test(b.textContent))
        if (!simpan) return 'tombol simpan tidak ada'
        simpan.click()
        await new Promise(r => setTimeout(r, 1500))
        return document.querySelector('.pesan-sukses')?.textContent || 'tanpa pesan sukses'
      })()
    `,
    )
    lapor(
      'mencentang fasilitas tersimpan dan dikonfirmasi di layar',
      teksMemuat(String(dicentang), 'tersimpan'),
      String(dicentang),
    )

    // Bukti di API, bukan hanya di layar.
    const kamarSetelah = await panggilApi(ws, `/kamar/${kamarId}`)
    const fasilitasKamar = kamarSetelah.data?.data?.fasilitas || []
    lapor(
      'fasilitas kamar tercatat di API',
      fasilitasKamar.some((f) => f.nama === NAMA_UJI),
      JSON.stringify(fasilitasKamar.map((f) => f.nama)),
    )

    // Label fasilitas harus terlihat di tabel kamar, supaya pemilik tidak perlu
    // membuka panel untuk tahu kamar mana punya apa.
    const labelTabel = await evaluasi(
      ws,
      `
      (() => {
        const baris = [...document.querySelectorAll('table.tabel tbody tr')]
          .find(tr => tr.textContent.includes('T18-A'))
        return baris ? baris.textContent : ''
      })()
    `,
    )
    lapor('label fasilitas tampil di baris tabel kamar', teksMemuat(String(labelTabel), NAMA_UJI))

    // --- Pemisahan dari uang ---------------------------------------------
    // Inti tiket. Kamar ini belum punya komponen biaya, jadi mencentang
    // fasilitas tidak boleh membuat satu pun baris tagihan baru.
    const komponen = await panggilApi(ws, `/komponen-biaya?kamar_id=${kamarId}`)
    lapor(
      'mencentang fasilitas tidak membuat komponen biaya',
      (komponen.data?.data || []).length === 0,
      JSON.stringify((komponen.data?.data || []).length),
    )

    // Dan tagihan yang terbit tetap hanya berisi tarif, tanpa biaya tambahan
    // dari fasilitas.
    const tarif = await panggilApi(ws, '/tarif', 'POST', {
      kamar_id: kamarId,
      nilai: 1000000,
      berlaku_dari: '2026-01-01',
    })
    lapor('tarif kamar uji ditetapkan', tarif.status === 201, String(tarif.status))

    const penghuni = await panggilApi(ws, '/penghuni', 'POST', {
      nama: 'Fasilitas Uji',
      email: 'fasilitas-uji-t18@kos.test',
    })
    const penghuniId = penghuni.data?.data?.id
    lapor('penghuni uji dibuat', penghuni.status === 201, String(penghuni.status))

    const kontrak = await panggilApi(ws, '/kontrak', 'POST', {
      kamar_id: kamarId,
      penghuni_id: penghuniId,
      tgl_masuk: '2026-03-01',
      deposit: 0,
    })
    lapor('kontrak uji dibuat', kontrak.status === 201, String(kontrak.status))

    const tagihan = await panggilApi(ws, '/tagihan', 'POST', {
      kontrak_id: kontrak.data?.data?.id,
      periode: '2026-03-01',
    })
    lapor('tagihan kamar uji terbit', tagihan.status === 201, String(tagihan.status))

    const pokok = tagihan.data?.data?.pokok
    lapor(
      'pokok tagihan sama dengan tarif, tanpa tambahan dari fasilitas',
      pokok === 1000000,
      `pokok ${pokok}`,
    )

    const rincian = tagihan.data?.data?.rincian || []
    lapor(
      'rincian tagihan tidak memuat baris fasilitas',
      !rincian.some((r) => String(r.nama).includes(NAMA_UJI)),
      JSON.stringify(rincian.map((r) => r.nama)),
    )

    // --- Melepas centang ---------------------------------------------------
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/kamar` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Kamar')`)

    const dilepas = await evaluasi(
      ws,
      `
      (async () => {
        const baris = [...document.querySelectorAll('table.tabel tbody tr')]
          .find(tr => tr.textContent.includes('T18-A'))
        if (!baris) return 'baris tidak ada'
        const tombol = [...baris.querySelectorAll('button')].find(b => /fasilitas/i.test(b.textContent))
        if (!tombol) return 'tombol tidak ada'
        tombol.click()
        await new Promise(r => setTimeout(r, 1200))

        const kotak = [...document.querySelectorAll('.kisi-fasilitas input[type="checkbox"]')]
        const target = kotak.find(k => k.closest('label').textContent.includes(${JSON.stringify(NAMA_UJI)}))
        if (!target) return 'kotak tidak ada'
        // Harus sudah tercentang dari penyimpanan sebelumnya.
        const sebelum = target.checked
        target.checked = false
        target.dispatchEvent(new Event('change', { bubbles: true }))
        await new Promise(r => setTimeout(r, 200))
        const simpan = [...document.querySelectorAll('button')]
          .find(b => /simpan fasilitas/i.test(b.textContent))
        simpan.click()
        await new Promise(r => setTimeout(r, 1500))
        return { sebelum }
      })()
    `,
    )
    lapor(
      'centang terbaca kembali saat panel dibuka lagi',
      typeof dilepas === 'object' && dilepas.sebelum === true,
      JSON.stringify(dilepas),
    )

    const kamarAkhir = await panggilApi(ws, `/kamar/${kamarId}`)
    lapor(
      'melepas centang menghapus fasilitas dari kamar',
      (kamarSetelah && (kamarAkhir.data?.data?.fasilitas || []).length === 0) ||
        !(kamarAkhir.data?.data?.fasilitas || []).some((f) => f.nama === NAMA_UJI),
      JSON.stringify((kamarAkhir.data?.data?.fasilitas || []).map((f) => f.nama)),
    )

    // --- Hapus fasilitas ---------------------------------------------------
    const dihapus = await panggilApi(ws, `/fasilitas/${fasilitasUji.id}`, 'DELETE')
    lapor('fasilitas dapat dihapus', dihapus.status === 200, String(dihapus.status))

    const sisa = await panggilApi(ws, '/fasilitas')
    lapor(
      'fasilitas yang dihapus hilang dari daftar',
      !(sisa.data?.data || []).some((f) => f.nama === NAMA_UJI),
      JSON.stringify((sisa.data?.data || []).length),
    )

    // Tagihan yang sudah terbit tidak boleh berubah karena fasilitas dihapus.
    const tagihanAkhir = await panggilApi(ws, `/tagihan/${tagihan.data?.data?.id}`)
    lapor(
      'tagihan lama tidak berubah setelah fasilitas dihapus',
      tagihanAkhir.data?.data?.pokok === 1000000,
      `pokok ${tagihanAkhir.data?.data?.pokok}`,
    )

    // --- Tangkapan layar ---------------------------------------------------
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/fasilitas` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Fasilitas')`)
    await tangkapLayar(ws, 'T18-fasilitas')
    lapor('tangkapan layar tersimpan di docs/uji', true)
  } finally {
    await uji.selesai()
  }
})
