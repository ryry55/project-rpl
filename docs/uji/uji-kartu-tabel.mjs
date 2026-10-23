// Uji kartu tabel: di layar sempit, tabel 4+ kolom berubah menjadi kartu.
//
// Kenapa skrip ini ada.
//
// `uji-tata-letak.mjs` memastikan tidak ada yang meluber mendatar, tetapi ia
// sengaja MELEWATI elemen di dalam `.tabel-bungkus` - karena bungkus itulah
// yang memang menggulir. Konsekuensinya, tabel yang masih berupa tabel di layar
// 360px selalu lolos: ia tidak meluber, ia hanya harus digeser ke samping.
//
// Skrip ini menutup celah itu. Yang diperiksa:
//
//   1. Tabel 4+ kolom bertanda `tabel-kartu` di layar sempit.
//   2. Setiap sel punya `data-label` yang sama dengan `<th>` kolomnya. Tanpa
//      ini kartu menampilkan angka tanpa nama - lebih buruk daripada tabel.
//   3. `thead` tidak terlihat, dan setiap sel menjadi satu baris label-nilai.
//   4. Di layar lebar tabelnya kembali normal. Kalau tidak, desktop ikut
//      berubah - dan itu bukan yang diminta.
//
// Pakai: node docs/uji/uji-kartu-tabel.mjs
// Prasyarat: sama dengan uji-tata-letak.mjs.

import {
  APP,
  mulaiUji,
  masukSebagai,
  panggilApi,
  evaluasi,
  tungguSampai,
  kirim,
  jalankan,
  pastikanKebijakan,
} from './_cdp.mjs'

/**
 * Halaman pemilik yang punya tabel 4+ kolom.
 *
 * Judulnya dipakai untuk menunggu halaman benar-benar berganti: `Page.navigate`
 * selesai sebelum Vue selesai memuat, dan mengukur tabel milik halaman
 * sebelumnya akan menghasilkan laporan yang salah alamat.
 */
const HALAMAN = [
  ['/pemilik/tagihan', 'Tagihan'],
  ['/pemilik/kamar', 'Kamar'],
  ['/pemilik/penghuni', 'Penghuni'],
  ['/pemilik/kontrak', 'Kontrak'],
  ['/pemilik/audit', 'Jejak audit'],
]

const LEBAR_SEMPIT = [360, 390]

function pasangLebar(ws, lebar) {
  return kirim(ws, 'Emulation.setDeviceMetricsOverride', {
    width: lebar,
    height: 900,
    deviceScaleFactor: 1,
    mobile: true,
  })
}

function hapusLebar(ws) {
  return kirim(ws, 'Emulation.clearDeviceMetricsOverride')
}

/**
 * Membaca keadaan tabel pertama di halaman.
 *
 * Ditulis sebagai ekspresi yang dikirim ke peramban, bukan sebagai fungsi
 * Node: hanya peramban yang bisa melihat `getComputedStyle` dan lebar nyata.
 */
const BACA_TABEL = `
  (() => {
    const tabel = document.querySelector('table.tabel')
    if (!tabel) return null

    const judul = Array.from(tabel.querySelectorAll('thead th')).map(
      (th) => (th.textContent || '').trim(),
    )
    // Baris "kosong" memakai satu sel ber-colspan dan bukan data.
    const baris = Array.from(tabel.querySelectorAll('tbody tr')).filter((tr) => {
      const sel = tr.querySelectorAll('td')
      return !(sel.length === 1 && sel[0].hasAttribute('colspan'))
    })

    const pertama = baris[0]
    const sel = pertama ? Array.from(pertama.querySelectorAll('td')) : []
    const thead = tabel.querySelector('thead')
    const r = thead ? thead.getBoundingClientRect() : null

    const batas = window.innerWidth
    const pelaku = []
    document.querySelectorAll('body *').forEach((el) => {
      const rr = el.getBoundingClientRect()
      if (rr.right > batas + 1 && !el.closest('.tabel-bungkus')) {
        pelaku.push(el.tagName + '.' + String(el.className || '').split(' ')[0])
      }
    })

    return {
      kolom: judul.length,
      dilipat: tabel.classList.contains('tabel-kartu'),
      judul,
      label: sel.map((td) => td.getAttribute('data-label')),
      tampilanSel: sel.length ? getComputedStyle(sel[0]).display : '',
      theadTerlihat: r ? r.width > 2 && r.height > 2 : false,
      adaBaris: baris.length > 0,
      lebar: batas,
      dokumen: document.documentElement.scrollWidth,
      luber: document.documentElement.scrollWidth > batas + 1,
      pelaku: [...new Set(pelaku)].slice(0, 3),
    }
  })()
`

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  try {
    const masuk = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik berhasil', masuk)
    if (!masuk) throw new Error('login gagal')

    // Tabel hanya berisi kalau ada data. Tanpa ini, seluruh pemeriksaan label
    // dilewati dan skrip hijau karena tidak ada yang diukur.
    const kebijakan = await pastikanKebijakan(ws)
    lapor('kebijakan kos siap', kebijakan.ok, kebijakan.alasan)

    // Nomor kamar dibuat unik per jalannya uji. Nomor tetap akan gagal pada
    // jalan kedua (unique index), dan kegagalan itu tidak ada hubungannya
    // dengan tata letak - tetapi akan dilaporkan seolah-olah tata letaknya
    // rusak, karena data uji tidak siap.
    const cap = Date.now().toString(36).slice(-4).toUpperCase()
    const kamar = await panggilApi(ws, '/kamar', 'POST', {
      nomor: `KT-${cap}`,
      tipe: 'Uji Kartu Tabel',
    })
    const kamarId = kamar.data?.data?.id
    if (kamarId) {
      await panggilApi(ws, '/tarif', 'POST', {
        kamar_id: kamarId,
        nilai: 1500000,
        berlaku_dari: '2026-01-01',
      })
      const penghuni = await panggilApi(ws, '/penghuni', 'POST', {
        nama: 'Penghuni Kartu Tabel Dengan Nama Cukup Panjang',
        email: `kartu-tabel-${cap}@kos.test`,
      })
      const kontrak = await panggilApi(ws, '/kontrak', 'POST', {
        kamar_id: kamarId,
        penghuni_id: penghuni.data?.data?.id,
        tgl_masuk: '2026-01-01',
        deposit: 0,
      })
      if (kontrak.data?.data?.id) {
        await panggilApi(ws, '/tagihan', 'POST', {
          kontrak_id: kontrak.data.data.id,
          periode: '2026-02-01',
        })
      }
    }
    lapor('data uji kartu tabel siap', Boolean(kamarId), String(kamarId))

    for (const lebar of LEBAR_SEMPIT) {
      for (const [jalur, judul] of HALAMAN) {
        await kirim(ws, 'Page.navigate', { url: `${APP}${jalur}` })
        await tungguSampai(
          ws,
          `document.querySelector('h1')?.textContent.includes(${JSON.stringify(judul)})`,
        )
        // Emulasi dilepas saat halaman baru dimuat, jadi dipasang lagi.
        await pasangLebar(ws, lebar)
        // Tunggu baris pertama benar-benar ada: tabel kosong tidak punya sel
        // untuk diperiksa labelnya.
        await tungguSampai(ws, `document.querySelectorAll('table.tabel tbody tr').length > 0`)
        await new Promise((r) => setTimeout(r, 250))

        const k = await evaluasi(ws, BACA_TABEL)
        const awalan = `${lebar}px ${judul}`

        if (!k) {
          lapor(`${awalan}: tabel ditemukan`, false, 'tidak ada table.tabel')
          continue
        }

        // Asersi pertama menjaga sisanya bermakna: kalau emulasi gagal dan
        // halaman ditata pada lebar desktop, semua pemeriksaan kartu akan
        // gagal untuk alasan yang salah alamat.
        lapor(`${awalan}: lebar emulasi berlaku`, k.lebar === lebar, `innerWidth ${k.lebar}`)

        if (k.kolom < 4) {
          lapor(`${awalan}: tabel sempit tidak dilipat`, !k.dilipat, `${k.kolom} kolom`)
          continue
        }

        lapor(`${awalan}: tabel 4+ kolom dilipat menjadi kartu`, k.dilipat)

        if (!k.adaBaris) {
          lapor(`${awalan}: ada baris data untuk diperiksa`, false, 'tabel kosong')
          continue
        }

        // Kolom aksi punya `<th>` kosong: di sana memang tidak ada label untuk
        // diturunkan, dan selnya dibiarkan tanpa `data-label`. Yang diperiksa
        // adalah kolom yang PUNYA judul.
        const labelBenar =
          k.label.length === k.kolom &&
          k.judul.every((j, i) => (j ? k.label[i] === j : k.label[i] === null))
        lapor(
          `${awalan}: setiap sel berlabel sesuai judul kolomnya`,
          labelBenar,
          labelBenar ? '' : `sel=${JSON.stringify(k.label)} judul=${JSON.stringify(k.judul)}`,
        )

        lapor(`${awalan}: thead disembunyikan`, !k.theadTerlihat)
        lapor(
          `${awalan}: sel menjadi baris label-nilai`,
          k.tampilanSel === 'flex',
          `display: ${k.tampilanSel}`,
        )
        lapor(
          `${awalan}: kartu tidak meluber mendatar`,
          !k.luber,
          k.luber ? `dokumen ${k.dokumen} > ${k.lebar} (${k.pelaku.join(', ')})` : '',
        )
      }
    }

    // --- Layar lebar: harus kembali menjadi tabel biasa ---------------------
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tagihan` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Tagihan')`)
    await hapusLebar(ws)
    await tungguSampai(ws, `document.querySelectorAll('table.tabel tbody tr').length > 0`)
    await new Promise((r) => setTimeout(r, 250))

    const lebar = await evaluasi(ws, BACA_TABEL)
    lapor('desktop: thead terlihat kembali', Boolean(lebar?.theadTerlihat))
    lapor(
      'desktop: sel bukan flex, tabel normal lagi',
      Boolean(lebar) && lebar.tampilanSel !== 'flex',
      lebar ? `display: ${lebar.tampilanSel}` : '',
    )
  } finally {
    await hapusLebar(ws).catch(() => {})
    await uji.selesai()
  }
})
