// Verifikasi end-to-end T19 lewat browser sungguhan.
//
// Yang dibuktikan: aturan kos dapat dikelola pemilik, dan yang aktif tampil di
// portal penghuni. Yang paling penting: aturan yang dinonaktifkan TIDAK sampai
// ke peramban penghuni. Menyaring di layar bukan hal yang sama - datanya tetap
// terkirim dan tetap bisa dibaca lewat jaringan.
//
// Pakai: node docs/uji/e2e-t19.mjs
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

const JUDUL_AKTIF = 'Aturan Uji Aktif T19'
const JUDUL_NONAKTIF = 'Aturan Uji Nonaktif T19'

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

    // --- Halaman Aturan ----------------------------------------------------
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/aturan` })
    const terbuka = await tungguSampai(
      ws,
      `document.querySelector('h1')?.textContent.includes('Aturan')`,
    )
    lapor('halaman Aturan kos terbuka', terbuka)

    const adaMenu = await tungguSampai(
      ws,
      `[...document.querySelectorAll('nav a')].some(a => a.textContent.includes('Aturan kos'))`,
      6000,
    )
    lapor('menu Aturan kos ada di navigasi pemilik', adaMenu)

    // Lihat catatan di e2e-t18.mjs: yang ditunggu isinya, bukan jumlah <tr>,
    // karena tabel merender satu baris kosong sebelum data tiba.
    const barisSiap = await tungguSampai(
      ws,
      `[...document.querySelectorAll('table.tabel tbody tr')]
         .filter(tr => tr.textContent.trim().length > 0).length >= 4`,
      15000,
    )
    const barisAwal = await evaluasi(
      ws,
      `[...document.querySelectorAll('table.tabel tbody tr')]
         .filter(tr => tr.textContent.trim().length > 0).length`,
    )
    lapor('tabel aturan terisi dari seeder', Boolean(barisSiap), `${barisAwal} baris`)

    // --- Tambah aturan lewat formulir -------------------------------------
    const tambah = async (judul, aktif) =>
      evaluasi(
        ws,
        `
        (async () => {
          const tombol = [...document.querySelectorAll('button')]
            .find(b => /tambah aturan/i.test(b.textContent))
          if (!tombol) return 'tombol tambah tidak ada'
          tombol.click()
          await new Promise(r => setTimeout(r, 300))

          const set = (sel, v) => {
            const el = document.querySelector(sel)
            if (!el) return false
            el.value = v
            el.dispatchEvent(new Event('input', { bubbles: true }))
            return true
          }

          if (!set('#judul-aturan', ${JSON.stringify(judul)})) return 'medan judul tidak ada'
          set('#isi-aturan', 'Isi aturan uji dari skrip E2E.')
          set('#kategori-aturan', 'keamanan')
          set('#sanksi-aturan', 'Teguran lisan')

          const aktif = document.querySelector('#aktif-aturan')
          if (!aktif) return 'kotak aktif tidak ada'
          if (aktif.checked !== ${aktif}) {
            aktif.checked = ${aktif}
            aktif.dispatchEvent(new Event('change', { bubbles: true }))
          }

          document.querySelector('form').dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }))
          await new Promise(r => setTimeout(r, 1200))
          return document.querySelector('.tabel')?.textContent || ''
        })()
      `,
      )

    const hasilAktif = await tambah(JUDUL_AKTIF, true)
    lapor(
      'aturan aktif tersimpan lewat formulir',
      typeof hasilAktif === 'string' && hasilAktif.includes(JUDUL_AKTIF),
      typeof hasilAktif === 'string' ? '' : String(hasilAktif),
    )

    const hasilNonaktif = await tambah(JUDUL_NONAKTIF, false)
    lapor(
      'aturan nonaktif tersimpan lewat formulir',
      typeof hasilNonaktif === 'string' && hasilNonaktif.includes(JUDUL_NONAKTIF),
      typeof hasilNonaktif === 'string' ? '' : String(hasilNonaktif),
    )

    const daftarApi = await panggilApi(ws, '/aturan')
    const aturanAktif = (daftarApi.data?.data || []).find((a) => a.judul === JUDUL_AKTIF)
    const aturanNonaktif = (daftarApi.data?.data || []).find((a) => a.judul === JUDUL_NONAKTIF)

    lapor('aturan aktif tercatat aktif di API', aturanAktif?.aktif === true, String(aturanAktif?.aktif))
    lapor(
      'aturan nonaktif tercatat tidak aktif di API',
      aturanNonaktif?.aktif === false,
      String(aturanNonaktif?.aktif),
    )

    // Kategori dari meta harus muncul sebagai saran di formulir, bukan daftar
    // yang ditulis ulang di Vue.
    const saran = await evaluasi(
      ws,
      `
      (async () => {
        const tombol = [...document.querySelectorAll('button')]
          .find(b => /tambah aturan/i.test(b.textContent))
        if (!tombol) return []
        tombol.click()
        await new Promise(r => setTimeout(r, 400))
        const opsi = [...document.querySelectorAll('#daftar-kategori option')].map(o => o.value)
        const batal = [...document.querySelectorAll('button')].find(b => /^batal$/i.test(b.textContent.trim()))
        if (batal) batal.click()
        return opsi
      })()
    `,
    )
    lapor(
      'pilihan kategori datang dari server',
      Array.isArray(saran) && saran.includes('keamanan') && saran.includes('pembayaran'),
      JSON.stringify(saran),
    )

    // --- Portal penghuni ---------------------------------------------------
    // Aturan harus tampil di portal penghuni yang sebenarnya, bukan hanya di
    // jawaban API. Dipakai akun dari seeder.
    await sesiKeluar(ws)
    const masukPenghuni = await masukSebagai(ws, 'penghuni@kos.test')
    lapor('login penghuni berhasil', masukPenghuni)
    if (!masukPenghuni) throw new Error('login penghuni gagal')

    await kirim(ws, 'Page.navigate', { url: `${APP}/penghuni` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Tagihan')`)

    const bagianAturan = await tungguSampai(
      ws,
      `document.querySelector('section.aturan')`,
      8000,
    )
    lapor('portal penghuni menampilkan bagian aturan', bagianAturan)

    const teksPortal = await evaluasi(
      ws,
      `document.querySelector('section.aturan')?.textContent || ''`,
    )
    lapor(
      'aturan aktif tampil di portal penghuni',
      teksMemuat(teksPortal, JUDUL_AKTIF),
      teksMemuat(teksPortal, JUDUL_AKTIF) ? '' : 'judul tidak ditemukan',
    )
    lapor(
      'aturan nonaktif TIDAK tampil di portal penghuni',
      !teksMemuat(teksPortal, JUDUL_NONAKTIF),
      teksMemuat(teksPortal, JUDUL_NONAKTIF) ? 'bocor ke portal' : '',
    )

    // Bukti lebih kuat: isi halaman tidak memuat judul itu sama sekali, bukan
    // hanya tidak terlihat. Inilah bedanya menyaring di server dan di layar.
    const seluruhHalaman = await evaluasi(ws, `document.body.innerHTML`)
    lapor(
      'aturan nonaktif tidak ada di HTML portal sama sekali',
      !String(seluruhHalaman).includes(JUDUL_NONAKTIF),
      String(seluruhHalaman).includes(JUDUL_NONAKTIF) ? 'masih ada di DOM' : '',
    )

    // Sanksi dan kategori ikut tampil, karena keduanya bagian dari jawaban
    // "apa akibatnya kalau dilanggar".
    lapor(
      'sanksi aturan tampil di portal penghuni',
      teksMemuat(teksPortal, 'Teguran lisan'),
      '',
    )

    await tangkapLayar(ws, 'T19-portal-penghuni')
    lapor('tangkapan layar portal penghuni tersimpan', true)

    // --- Menyembunyikan aturan lewat sakelar -------------------------------
    await sesiKeluar(ws)
    const masukLagi = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik kembali berhasil', masukLagi)

    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/aturan` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Aturan')`)
    // Judul muncul sebelum tabelnya terisi. Menunggu `h1` saja membuat
    // pemeriksaan di bawah berlomba dengan pemuatan data, dan di runner yang
    // lambat barisnya belum ada saat dicari ("baris tidak ada").
    await tungguSampai(
      ws,
      `[...document.querySelectorAll('table.tabel tbody tr')].some(tr => tr.textContent.includes(${JSON.stringify(JUDUL_AKTIF)}))`,
      20000,
    )

    const disembunyikan = await evaluasi(
      ws,
      `
      (async () => {
        const cari = () => [...document.querySelectorAll('table.tabel tbody tr')]
          .find(tr => tr.textContent.includes(${JSON.stringify(JUDUL_AKTIF)}))
        const baris = cari()
        if (!baris) return 'baris tidak ada'
        const tombol = [...baris.querySelectorAll('button')]
          .find(b => /sembunyikan/i.test(b.textContent))
        if (!tombol) return 'tombol sembunyikan tidak ada'
        tombol.click()
        // Tunggu PERUBAHANNYA, bukan durasi tetap. Server butuh waktu
        // menjawab lalu Vue merender ulang; di laptop 1400 ms cukup, di
        // runner yang lebih lambat tidak - dan pemeriksaan di bawah lalu
        // membaca baris yang belum berubah.
        const batas = Date.now() + 15000
        while (Date.now() < batas) {
          const kini = cari()
          if (kini && /nonaktif/i.test(kini.textContent)) return kini.textContent
          await new Promise(r => setTimeout(r, 150))
        }
        const akhir = cari()
        return akhir ? akhir.textContent : ''
      })()
    `,
    )
    // Lencana menampilkan kalimat ("Nonaktif"); yang diuji keadaannya.
    lapor(
      'tombol Sembunyikan mengubah status di tabel',
      teksMemuat(String(disembunyikan).toLowerCase(), 'nonaktif'),
      String(disembunyikan),
    )

    const cekApi = await panggilApi(ws, '/aturan')
    const setelahSembunyi = (cekApi.data?.data || []).find((a) => a.judul === JUDUL_AKTIF)
    lapor(
      'aturan benar-benar nonaktif di API',
      setelahSembunyi?.aktif === false,
      String(setelahSembunyi?.aktif),
    )

    // --- Hapus aturan ------------------------------------------------------
    // Aturan boleh dihapus karena tidak append-only. Kebijakan tidak boleh -
    // itu diuji di tempat lain.
    const hapus = await panggilApi(ws, `/aturan/${aturanNonaktif.id}`, 'DELETE')
    lapor('aturan dapat dihapus', hapus.status === 200, String(hapus.status))

    const sisa = await panggilApi(ws, '/aturan')
    lapor(
      'aturan yang dihapus hilang dari daftar',
      !(sisa.data?.data || []).some((a) => a.judul === JUDUL_NONAKTIF),
      JSON.stringify((sisa.data?.data || []).length),
    )

    // --- Tangkapan layar ---------------------------------------------------
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/aturan` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Aturan')`)
    await tangkapLayar(ws, 'T19-aturan')
    lapor('tangkapan layar tersimpan di docs/uji', true)
  } finally {
    await uji.selesai()
  }
})

/** Keluar lewat tombol di layar, bukan dengan menghapus token langsung. */
async function sesiKeluar(ws) {
  await evaluasi(
    ws,
    `
    (async () => {
      const tombol = [...document.querySelectorAll('button')].find(b => /^keluar$/i.test(b.textContent.trim()))
      if (tombol) {
        tombol.click()
        await new Promise(r => setTimeout(r, 1200))
        return true
      }
      localStorage.removeItem('kos.token')
      return false
    })()
  `,
  )
  await tungguSampai(ws, `location.pathname.includes('/masuk')`, 8000)
}
