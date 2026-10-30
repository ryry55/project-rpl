// Uji aksesibilitas otomatis dengan axe-core.
//
// Yang diperiksa bukan "apakah halaman tampak baik", melainkan aturan WCAG 2.0/2.1
// level A dan AA yang bisa diperiksa mesin: nama yang bisa dibaca pembaca layar,
// label medan, kontras teks, peran dan relasi ARIA, urutan judul, bahasa dokumen.
// Aturan yang butuh manusia (urutan fokus yang masuk akal, teks alternatif yang
// benar-benar menjelaskan gambarnya) tetap tidak tercakup - ini penjaga lantai,
// bukan pengganti pembacaan.
//
// Axe dipasang lewat `Page.addScriptToEvaluateOnNewDocument`, bukan lewat
// `evaluasi` sesudah navigasi: `Page.navigate` memuat ulang dokumen dan
// menghapus variabel yang ditanam sebelumnya, sehingga setiap halaman akan
// melaporkan "0 pelanggaran" karena `axe` tidak ada. Kesalahan yang sama sudah
// pernah terjadi pada perekam galat konsol (lihat AGENTS.md).
//
// Prasyarat sama dengan skrip lain: docker compose up, Chrome dengan
// --remote-debugging-port=9222, dan `npm ci` di web/ (axe-core ada di sana).
//
//   node docs/uji/uji-aksesibilitas.mjs
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { mulaiUji, masukSebagai, lewatiPersetujuanPdp, kirim, evaluasi, tungguSampai, APP } from './_cdp.mjs'

const AKAR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..')
const BERKAS_AXE = path.join(AKAR, 'web/node_modules/axe-core/axe.min.js')

if (!fs.existsSync(BERKAS_AXE)) {
  console.error(`axe-core tidak ditemukan di ${BERKAS_AXE}. Jalankan: cd web; npm ci`)
  process.exit(1)
}

const sumberAxe = fs.readFileSync(BERKAS_AXE, 'utf8')

// Tag WCAG 2.0 dan 2.1 level A/AA. Level AAA tidak dituntut: kontras 7:1 pada
// seluruh antarmuka akan memaksa palet buku besar ini menjadi hampir hitam-putih.
const TAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']

// Halaman yang dibuka. Jalur `/halaman-tidak-ditemukan` sengaja ikut: 404 adalah
// halaman yang paling jarang dilihat pengembang dan paling sering dilihat
// pengguna yang salah ketik.
const PUBLIK = ['/masuk', '/lupa-sandi', '/halaman-tidak-ditemukan']
const PEMILIK = [
  '/pemilik/kamar',
  '/pemilik/penghuni',
  '/pemilik/kontrak',
  '/pemilik/tagihan',
  '/pemilik/rekap',
  '/pemilik/tunggakan',
  '/pemilik/kebijakan',
  '/pemilik/rekening',
  '/pemilik/fasilitas',
  '/pemilik/aturan',
  '/pemilik/audit',
  '/pemilik/pengaturan-notifikasi',
  '/pemilik/pengelola',
  '/pemilik/bantuan',
  '/pemilik/persetujuan',
  '/data-pribadi',
]
const PENGHUNI = ['/penghuni', '/data-pribadi']

const uji = await mulaiUji()
const { ws } = uji

await kirim(ws, 'Page.addScriptToEvaluateOnNewDocument', { source: sumberAxe })

/** Jalankan axe pada dokumen yang sedang terbuka. */
function jalankanAxe() {
  return evaluasi(
    ws,
    `axe.run(document, { runOnly: { type: 'tag', values: ${JSON.stringify(TAG)} } })
      .then((r) => ({
        lulus: r.passes.length,
        pelanggaran: r.violations.map((v) => ({
          id: v.id,
          dampak: v.impact,
          jumlah: v.nodes.length,
          sasaran: (v.nodes[0].target || []).join(' '),
          sebab: String(v.nodes[0].failureSummary || '').split('\\n').slice(1, 3).join(' ').slice(0, 140),
        })),
      }))`,
  )
}

/** Satu halaman: buka, tunggu isinya benar-benar ada, lalu ukur. */
async function periksaHalaman(jalur, harusnya = jalur) {
  await kirim(ws, 'Page.navigate', { url: `${APP}${jalur}` })
  const siap = await tungguSampai(
    ws,
    `document.querySelector('h1') && document.body.textContent.trim().length > 40`,
    15000,
  )

  if (!siap) {
    uji.lapor(`${jalur} terbuka`, false, 'judul halaman tidak muncul dalam 15 detik')
    return 0
  }

  // Data dimuat sesudah judul dirender. Menunggu sebentar di sini bukan
  // pengganti syarat yang benar, melainkan syarat tambahan: tabel yang masih
  // kosong akan lolos pemeriksaan kontras dengan mudah, dan justru barisnya
  // yang perlu diukur.
  await new Promise((r) => setTimeout(r, 900))

  const posisi = await evaluasi(ws, 'location.pathname')
  const judul = await evaluasi(ws, `(document.querySelector('h1')?.textContent || '').trim().slice(0, 40)`)

  if (posisi !== harusnya) {
    uji.lapor(`${jalur} terbuka di jalurnya sendiri`, false, `mendarat di ${posisi}`)
    return 0
  }

  const hasil = await jalankanAxe()

  const ringkas = hasil.pelanggaran
    .map((p) => `${p.id} (${p.dampak}, ${p.jumlah}x) @ ${p.sasaran}`)
    .join('; ')

  uji.lapor(
    `${jalur} tanpa pelanggaran WCAG A/AA - ${judul}`,
    hasil.pelanggaran.length === 0,
    hasil.pelanggaran.length === 0
      ? `${hasil.lulus} aturan lulus`
      : `${hasil.pelanggaran.length} jenis: ${ringkas}`,
  )

  return hasil.pelanggaran.length
}

let total = 0
for (const jalur of PUBLIK) total += await periksaHalaman(jalur)

if (!(await masukSebagai(ws, 'pemilik@kos.test'))) {
  uji.lapor('masuk sebagai pemilik', false)
} else {
  await lewatiPersetujuanPdp(ws)
  for (const jalur of PEMILIK) total += await periksaHalaman(jalur)
}

if (!(await masukSebagai(ws, 'penghuni@kos.test'))) {
  uji.lapor('masuk sebagai penghuni', false)
} else {
  await lewatiPersetujuanPdp(ws)
  for (const jalur of PENGHUNI) total += await periksaHalaman(jalur)
}

// --- Kontrol negatif --------------------------------------------------------
//
// Tanpa ini, "0 pelanggaran di 21 halaman" sama saja dengan "axe tidak pernah
// berjalan". Empat cacat yang pasti dilaporkan disisipkan, diperiksa, lalu
// dibuang: tautan tanpa nama, gambar tanpa teks alternatif, medan tanpa label,
// dan teks berkontras rendah.
await masukSebagai(ws, 'pemilik@kos.test')
await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
await tungguSampai(ws, `document.querySelector('#email')`, 15000)

await evaluasi(
  ws,
  `(() => {
    const tambah = (el, id) => { el.id = id; document.body.appendChild(el); return el }
    const tautan = document.createElement('a')
    tautan.href = '#'
    tambah(tautan, 'cacat-tautan')
    const gambar = document.createElement('img')
    gambar.src = '/tidak-ada.png'
    tambah(gambar, 'cacat-gambar')
    const medan = document.createElement('input')
    medan.type = 'text'
    tambah(medan, 'cacat-label')
    const pucat = document.createElement('div')
    pucat.style.color = '#dddddd'
    pucat.style.background = '#ffffff'
    pucat.style.fontSize = '14px'
    pucat.textContent = 'teks berkontras rendah'
    tambah(pucat, 'cacat-kontras')
    return true
  })()`,
)

const denganCacat = await jalankanAxe()
const ditemukan = new Set(denganCacat.pelanggaran.map((p) => p.id))
const wajib = ['link-name', 'image-alt', 'label', 'color-contrast']
const tertangkap = wajib.filter((id) => ditemukan.has(id))

uji.lapor(
  'kontrol negatif: empat cacat yang disisipkan terdeteksi',
  tertangkap.length === wajib.length,
  tertangkap.length === wajib.length
    ? `${wajib.length}/${wajib.length} terdeteksi`
    : `terdeteksi ${tertangkap.join(', ') || 'tidak ada'}; hilang ${wajib.filter((id) => !ditemukan.has(id)).join(', ')}`,
)

await evaluasi(
  ws,
  `(() => {
    for (const id of ['cacat-tautan', 'cacat-gambar', 'cacat-label', 'cacat-kontras']) {
      document.getElementById(id)?.remove()
    }
    return true
  })()`,
)

const sesudahDibersihkan = await jalankanAxe()
uji.lapor(
  'kontrol negatif: halaman kembali bersih setelah cacatnya dibuang',
  sesudahDibersihkan.pelanggaran.length === 0,
  `${sesudahDibersihkan.pelanggaran.length} pelanggaran tersisa`,
)

if (total > 0) console.log(`\nTotal pelanggaran WCAG A/AA: ${total}`)

await uji.selesai()
