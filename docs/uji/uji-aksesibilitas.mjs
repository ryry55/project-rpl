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
import { mulaiUji, masukSebagai, lewatiPersetujuanPdp, pastikanKebijakan, panggilApi, kirim, evaluasi, tungguSampai, APP } from './_cdp.mjs'

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

/**
 * Satu KEADAAN, bukan satu halaman: buka jalurnya, jalankan tindakan yang
 * memunculkan keadaan itu, pastikan keadaannya benar-benar muncul, lalu ukur.
 *
 * Pemeriksaan "pastikan muncul" itu bukan formalitas. Tanpa itu, tindakan yang
 * gagal membuka formulir akan diukur sebagai halaman biasa - dan hasilnya
 * "0 pelanggaran" untuk keadaan yang tidak pernah ada. Kegagalan yang senyap,
 * persis seperti axe yang tidak tersuntik.
 */
async function periksaKeadaan(nama, jalur, aksi) {
  await kirim(ws, 'Page.navigate', { url: `${APP}${jalur}` })
  const siap = await tungguSampai(ws, `document.querySelector('h1')`, 15000)
  if (!siap) {
    uji.lapor(`${jalur} - ${nama}`, false, 'halaman tidak terbuka')
    return 0
  }
  await new Promise((r) => setTimeout(r, 700))

  const sebabGagal = await aksi()
  if (sebabGagal) {
    uji.lapor(`${jalur} - ${nama}`, false, sebabGagal)
    return 0
  }

  // Satu bingkai: peramban menata ulang sesudah DOM berubah, dan axe mengukur
  // posisi sesudah tata letak selesai.
  await new Promise((r) => setTimeout(r, 400))

  const hasil = await jalankanAxe()
  const ringkas = hasil.pelanggaran
    .map((p) => `${p.id} (${p.dampak}, ${p.jumlah}x) @ ${p.sasaran}`)
    .join('; ')

  uji.lapor(
    `${jalur} - ${nama} tanpa pelanggaran WCAG A/AA`,
    hasil.pelanggaran.length === 0,
    hasil.pelanggaran.length === 0
      ? `${hasil.lulus} aturan lulus`
      : `${hasil.pelanggaran.length} jenis: ${ringkas}`,
  )

  return hasil.pelanggaran.length
}

/** Klik tombol/tautan pertama yang teksnya memuat `label`. */
function klikTeks(label) {
  return evaluasi(
    ws,
    `(() => {
      const cocok = [...document.querySelectorAll('button, a')].find((el) =>
        (el.textContent || '').includes(${JSON.stringify(label)}),
      )
      if (!cocok) return false
      cocok.click()
      return true
    })()`,
  )
}

/**
 * Satu kamar, satu kontrak, dan satu tagihan supaya panel rincian bisa dibuka.
 * Idempoten: setiap sumber daya dicari dulu, baru dibuat bila belum ada.
 */
async function siapkanTagihan() {
  const kebijakan = await pastikanKebijakan(ws)
  if (!kebijakan.ok) return { ok: false, alasan: `kebijakan: ${kebijakan.alasan}` }

  const daftar = async (jalur) => {
    const r = await panggilApi(ws, jalur)
    return { status: r.status, baris: Array.isArray(r.data?.data) ? r.data.data : [] }
  }

  const kamarSemua = await daftar('/kamar')
  if (kamarSemua.status !== 200) return { ok: false, alasan: `GET /kamar ${kamarSemua.status}` }
  let kamar = kamarSemua.baris.find((k) => k.nomor === 'A11Y-01')
  if (!kamar) {
    const buat = await panggilApi(ws, '/kamar', 'POST', { nomor: 'A11Y-01', tipe: 'Uji aksesibilitas' })
    if (buat.status !== 201) return { ok: false, alasan: `POST /kamar ${buat.status}` }
    kamar = buat.data.data
  }

  const tarif = await daftar(`/tarif?kamar_id=${kamar.id}`)
  if (tarif.baris.length === 0) {
    const buat = await panggilApi(ws, '/tarif', 'POST', {
      kamar_id: kamar.id,
      nilai: 1500000,
      berlaku_dari: '2026-01-01',
    })
    if (buat.status !== 201) return { ok: false, alasan: `POST /tarif ${buat.status}` }
  }

  const penghuni = await daftar('/penghuni')
  if (penghuni.status !== 200) return { ok: false, alasan: `GET /penghuni ${penghuni.status}` }
  const siapa = penghuni.baris[0]
  if (!siapa) return { ok: false, alasan: 'tidak ada penghuni untuk dibuatkan kontrak' }

  const semuaKontrak = await daftar('/kontrak')
  let kontrak = semuaKontrak.baris.filter((k) => k.kamar_id === kamar.id)[0]
  if (!kontrak) {
    const buat = await panggilApi(ws, '/kontrak', 'POST', {
      kamar_id: kamar.id,
      penghuni_id: siapa.id,
      tgl_masuk: '2026-09-16',
    })
    if (buat.status !== 201) return { ok: false, alasan: `POST /kontrak ${buat.status}` }
    kontrak = buat.data.data
  }

  // 409 berarti tagihan periode itu sudah ada - sama baiknya dengan berhasil.
  const terbit = await panggilApi(ws, '/tagihan', 'POST', {
    kontrak_id: kontrak.id,
    periode: '2026-09-01',
  })
  if (terbit.status !== 201 && terbit.status !== 409) {
    return { ok: false, alasan: `POST /tagihan ${terbit.status}` }
  }

  return { ok: true, alasan: 'satu tagihan siap' }
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

// --- Keadaan yang tidak terlihat saat halaman pertama dibuka -----------------
//
// Seluruh pemeriksaan di atas mengukur halaman APA ADANYA. Dua keadaan yang
// paling sering salah kontras tidak pernah muncul di sana, karena keduanya
// baru lahir dari tindakan pengguna: formulir yang terbuka, dan pesan galat
// validasi. Pesan galat justru teks yang paling jarang dilihat pengembang -
// ia hanya muncul saat ada yang salah - dan paling sering memakai warna yang
// terlalu muda untuk dibaca.
await masukSebagai(ws, 'pemilik@kos.test')
await lewatiPersetujuanPdp(ws)

// Data: seeder hanya berisi akun dan satu penghuni, jadi satu tagihan harus
// dibuat lebih dulu. Semua langkah idempoten supaya skrip ini bisa dijalankan
// ulang tanpa `migrate:fresh`.
const siapData = await siapkanTagihan()
uji.lapor('data uji untuk keadaan formulir siap', siapData.ok, siapData.alasan)

if (siapData.ok) {
  total += await periksaKeadaan(
    'rincian tagihan terbuka',
    '/pemilik/tagihan',
    async () => {
      const diklik = await klikTeks('Rincian')
      if (!diklik) return 'tombol Rincian tidak ditemukan'
      const muncul = await tungguSampai(ws, `document.querySelector('.form-bayar, .blok-bayar')`, 10000)
      return muncul ? null : 'panel rincian tidak terbuka'
    },
  )

  total += await periksaKeadaan(
    'form catat pembayaran terbuka',
    '/pemilik/tagihan',
    async () => {
      if (!(await klikTeks('Rincian'))) return 'tombol Rincian tidak ditemukan'
      await tungguSampai(ws, `document.querySelector('.blok-bayar')`, 10000)
      const dibuka = await klikTeks('Catat pembayaran')
      if (!dibuka) return 'tombol Catat pembayaran tidak ditemukan'
      const muncul = await tungguSampai(ws, `document.querySelector('#bayar-jumlah')`, 8000)
      return muncul ? null : 'form pembayaran tidak terbuka'
    },
  )

  // Galat validasi dari server, bukan dari peramban: nominal yang melebihi sisa
  // ditolak 422, dan justru pesan itulah yang perlu diukur. `required` pada
  // medan akan menghentikan submit di peramban sebelum apa pun tampil.
  total += await periksaKeadaan(
    'galat validasi tampil di formulir',
    '/pemilik/tagihan',
    async () => {
      if (!(await klikTeks('Rincian'))) return 'tombol Rincian tidak ditemukan'
      await tungguSampai(ws, `document.querySelector('.blok-bayar')`, 10000)
      if (!(await klikTeks('Catat pembayaran'))) return 'tombol Catat pembayaran tidak ditemukan'
      await tungguSampai(ws, `document.querySelector('#bayar-jumlah')`, 8000)

      await evaluasi(
        ws,
        `(() => {
          const isi = (id, nilai) => {
            const el = document.getElementById(id)
            el.value = nilai
            el.dispatchEvent(new Event('input', { bubbles: true }))
          }
          isi('bayar-jumlah', '99999999')
          isi('bayar-tanggal', '2026-10-01')
          const metode = document.getElementById('bayar-metode')
          metode.value = 'tunai'
          metode.dispatchEvent(new Event('change', { bubbles: true }))
          return true
        })()`,
      )

      const tersubmit = await evaluasi(
        ws,
        `(() => {
          const form = document.querySelector('.form-bayar')
          if (!form) return false
          form.requestSubmit()
          return true
        })()`,
      )
      if (!tersubmit) return 'form pembayaran tidak ditemukan saat akan dikirim'

      const tampil = await tungguSampai(
        ws,
        `document.querySelector('.galat-bidang') || document.querySelector('.pesan-galat')`,
        10000,
      )
      if (!tampil) return 'pesan galat tidak muncul setelah pengiriman ditolak'

      // Muncul di layar belum cukup: pembaca layar hanya mengumumkannya bila
      // ada `role="alert"`. Sebelum 30 Oktober tidak satu pun pesan galat
      // memilikinya, karena `PesanBaris` menunggu pemanggil memintanya.
      const diumumkan = await evaluasi(ws, `Boolean(document.querySelector('[role="alert"]'))`)
      return diumumkan ? null : 'pesan galat muncul tetapi tidak punya role="alert"'
    },
  )
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
