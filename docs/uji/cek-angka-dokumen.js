// Bandingkan angka yang DIKLAIM di dokumen dengan angka yang NYATA.
//
// Ini bukan pemeriksa gaya: setiap baris di sini adalah kalimat yang akan
// dibaca dosen atau anggota tim, dan angka yang salah di dokumen akademik
// adalah kesalahan yang tidak bisa dibantah ("di dokumen tertulis 71").
//
// Jalankan dari mana saja: `node docs/uji/cek-angka-dokumen.js`. Akar repo
// dicari dari lokasi berkas ini, bukan dari direktori kerja - kalau memakai
// `process.cwd()`, skrip ini hanya bekerja bila dijalankan dari akar repo, dan
// kegagalannya muncul sebagai "folder tidak ditemukan" yang tidak menjelaskan
// apa pun.
const fs = require('fs')
const path = require('path')

const AKAR = path.resolve(__dirname, '..', '..')

function hitungMigrasi() {
  return fs.readdirSync(path.join(AKAR, 'api/database/migrations')).filter((f) => f.endsWith('.php')).length
}
function hitungModel() {
  return fs.readdirSync(path.join(AKAR, 'api/app/Models')).filter((f) => f.endsWith('.php')).length
}
function hitungSkrip() {
  // `_cdp.mjs` adalah pustaka bersama, bukan skrip uji. Menghitungnya membuat
  // angka dokumen dan angka `periksa` berbeda satu selamanya.
  return fs
    .readdirSync(path.join(AKAR, 'docs/uji'))
    .filter((f) => f.endsWith('.mjs') && !f.startsWith('_')).length
}
function hitungBerkas(relatif, ekstensi) {
  const akar = path.join(AKAR, relatif)
  const hasil = []
  const jelajah = (dir) => {
    for (const entri of fs.readdirSync(dir, { withFileTypes: true })) {
      const p = path.join(dir, entri.name)
      if (entri.isDirectory()) jelajah(p)
      else if (entri.name.endsWith(ekstensi)) hasil.push(p)
    }
  }
  jelajah(akar)
  return hasil.length
}
function hitungBaris(relatif) {
  return fs.readFileSync(path.join(AKAR, relatif), 'utf8').split(/\r?\n/).length - 1
}
function potret(nama) {
  return JSON.parse(fs.readFileSync(path.join(AKAR, 'docs/akademik', nama), 'utf8'))
}

const nyata = {
  endpoint: potret('rute-api.json').length,
  'tabel domain': potret('tabel-domain.json').length,
  migrasi: hitungMigrasi(),
  model: hitungModel(),
  'skrip uji': hitungSkrip(),
  'berkas mjs': fs.readdirSync(path.join(AKAR, 'docs/uji')).filter((f) => f.endsWith('.mjs')).length,
  'kelas domain': hitungBerkas('api/app/Domain', '.php'),
  pengendali: hitungBerkas('api/app/Http/Controllers', '.php'),
  view: hitungBerkas('web/src/views', '.vue'),
  'berkas tes': hitungBerkas('api/tests', '.php'),
  'baris style.css': hitungBaris('web/src/style.css'),
}

// Tinggi huruf lembar LRS disimpan oleh `build-tingkat.js` di manifest, bukan
// diukur ulang di sini: mengukur ulang berarti membangun seluruh LRS (24 tabel,
// 209 kolom) pada tiap pemeriksaan dokumen.
const manifestErd = JSON.parse(
  fs.readFileSync(path.join(AKAR, 'docs/erd/manifest.json'), 'utf8')
)
const lrs = manifestErd.tingkat.find((t) => t.nama === 'lrs')
nyata['huruf A3 (mm)'] = lrs && lrs.huruf_mm ? lrs.huruf_mm : 0
nyata['huruf A4 (mm)'] = lrs && lrs.huruf_a4_mm ? lrs.huruf_a4_mm : 0

// Angka yang diklaim, beserta berkas dan pola yang menuliskannya.
//
// `semua: true` memeriksa SETIAP kemunculan pola di berkas itu, bukan hanya
// yang pertama. Sebelum ini, satu berkas yang menyebut angka yang sama tiga
// kali hanya diperiksa sekali, dan dua sebutan yang basi lolos tanpa suara.
//
// Jumlah tes dan asersi TIDAK diperiksa di sini: keduanya hanya diketahui dari
// keluaran phpunit, bukan dari bentuk berkas, dan menjalankan seluruh tes di
// dalam pemeriksa dokumen akan membuat pemeriksaan ini lebih lambat daripada
// gerbangnya sendiri. Angka itu disegarkan manual sesudah `periksa` selesai;
// lihat catatan di `docs/serah-terima.md`.
const KLAIM = [
  { berkas: 'docs/produk/KONTEKS_PROYEK.md', pola: /\|\s*Endpoint API\s*\|\s*\*\*(\d+)\*\*/i, kunci: 'endpoint' },
  { berkas: 'docs/produk/KONTEKS_PROYEK.md', pola: /\|\s*Tabel \(di luar infrastruktur Laravel\)\s*\|\s*\*\*(\d+)\*\*/i, kunci: 'tabel domain' },
  { berkas: 'docs/produk/KONTEKS_PROYEK.md', pola: /\|\s*Migrasi\s*\|\s*\*\*(\d+)\*\*/i, kunci: 'migrasi' },
  { berkas: 'docs/produk/KONTEKS_PROYEK.md', pola: /\|\s*Model Eloquent\s*\|\s*\*\*(\d+)\*\*/i, kunci: 'model' },
  { berkas: 'docs/produk/KONTEKS_PROYEK.md', pola: /\((\d+) asersi\) di \*\*(\d+)\*\* berkas/i, kunci: 'berkas tes', indeks: 2 },
  { berkas: 'docs/produk/KONTEKS_PROYEK.md', pola: /\|\s*Skrip browser\s*\|\s*\*\*(\d+)\*\*/i, kunci: 'berkas mjs' },
  { berkas: 'docs/produk/KONTEKS_PROYEK.md', pola: /\((\d+) terdaftar di gate/i, kunci: 'skrip uji' },
  { berkas: 'docs/serah-terima.md', pola: /\|\s*Migrasi \/ endpoint \/ tabel domain\s*\|\s*\*\*(\d+)\*\* \/ \*\*(\d+)\*\* \/ \*\*(\d+)\*\*/i, kunci: 'endpoint', indeks: 2 },
  { berkas: 'docs/serah-terima.md', pola: /\|\s*Migrasi \/ endpoint \/ tabel domain\s*\|\s*\*\*(\d+)\*\* \/ \*\*(\d+)\*\* \/ \*\*(\d+)\*\*/i, kunci: 'migrasi', indeks: 1 },
  { berkas: 'docs/serah-terima.md', pola: /\|\s*Migrasi \/ endpoint \/ tabel domain\s*\|\s*\*\*(\d+)\*\* \/ \*\*(\d+)\*\* \/ \*\*(\d+)\*\*/i, kunci: 'tabel domain', indeks: 3 },
  { berkas: 'docs/serah-terima.md', pola: /\*\*(\d+) lulus \/ \d+ asersi\*\*/i, kunci: null },
  { berkas: 'docs/serah-terima.md', pola: /\*\*(\d+)\*\* \(\d+ browser \+ 2 balapan\)/i, kunci: 'skrip uji' },
  { berkas: 'SPEC.md', pola: /\*\*(\d+) endpoint\*\*/i, kunci: 'endpoint', semua: true },

  // Berkas yang dulu tidak diperiksa sama sekali, padahal dibaca dosen.
  { berkas: 'README.md', pola: /(\d+) skrip uji browser/i, kunci: 'skrip uji' },
  { berkas: 'docs/uji/README.md', pola: /(\d+) skrip browser/i, kunci: 'skrip uji' },
  { berkas: 'docs/demo-sidang.md', pola: /(\d+) skrip \//i, kunci: 'skrip uji' },
  { berkas: 'docs/produk/7_TESTING_EVALS.md', pola: /(\d+) skrip/gi, kunci: 'skrip uji', semua: true },
  { berkas: 'docs/produk/7_TESTING_EVALS.md', pola: /(\d+) berkas/gi, kunci: 'berkas tes', semua: true },
  { berkas: 'docs/akademik/README.md', pola: /(\d+) endpoint/gi, kunci: 'endpoint', semua: true },
  { berkas: 'docs/akademik/README.md', pola: /(\d+) tabel(?: domain|,)/gi, kunci: 'tabel domain', semua: true },
  { berkas: 'docs/produk/3_ARCHITECTURE.md', pola: /(\d+) kelas, 11 folder/i, kunci: 'kelas domain' },
  { berkas: 'docs/produk/3_ARCHITECTURE.md', pola: /(\d+) kelas di `app\/Domain\/`/i, kunci: 'kelas domain' },
  { berkas: 'docs/produk/3_ARCHITECTURE.md', pola: /(\d+) pengendali/i, kunci: 'pengendali', semua: true },
  { berkas: 'docs/produk/3_ARCHITECTURE.md', pola: /(\d+) berkas tes/i, kunci: 'berkas tes' },
  { berkas: 'docs/produk/3_ARCHITECTURE.md', pola: /(\d+) view \(/i, kunci: 'view' },
  { berkas: 'docs/produk/3_ARCHITECTURE.md', pola: /(\d+) baris - seluruh design system/i, kunci: 'baris style.css' },
  { berkas: 'docs/produk/3_ARCHITECTURE.md', pola: /(\d+)\s+migrasi/i, kunci: 'migrasi' },
  { berkas: 'docs/produk/3_ARCHITECTURE.md', pola: /(\d+) model - hanya/i, kunci: 'model' },

  // Tinggi huruf LRS pada kertas A3: satu-satunya sel tabel bentuk yang dipakai
  // untuk memutuskan, jadi ia yang dijaga. Angka ini pernah tertinggal (2,87
  // tertulis, 2,74 terukur) karena hanya diketik di prosa.
  { berkas: 'docs/erd/README.md', pola: /hasilnya \*\*(\d+,\d+) mm\*\*/i, kunci: 'huruf A3 (mm)' },
  { berkas: 'docs/akademik/README.md', pola: /terukur \*\*(\d+,\d+) mm\*\*/i, kunci: 'huruf A3 (mm)' },
  { berkas: 'docs/akademik/build-bab4.js', pola: /hurufnya (\d+,\d+) mm/i, kunci: 'huruf A3 (mm)' },
  {
    berkas: 'docs/akademik/Bab_4_Implementasi_dan_Pengujian.md',
    pola: /hurufnya (\d+,\d+) mm/i,
    kunci: 'huruf A3 (mm)',
  },
  {
    berkas: 'docs/erd/README.md',
    pola: /Seluruh varian A4 gagal\*\*; yang terbaik (\d+,\d+) mm/i,
    kunci: 'huruf A4 (mm)',
  },
  { berkas: 'docs/akademik/README.md', pola: /kembali\s+(\d+,\d+) mm/i, kunci: 'huruf A4 (mm)' },
  {
    berkas: 'docs/akademik/Bab_4_Implementasi_dan_Pengujian.md',
    pola: /yang terbaik hanya (\d+,\d+) mm/i,
    kunci: 'huruf A4 (mm)',
  },
]

let adaBeda = false
const baris = []

for (const k of KLAIM) {
  const p = path.join(AKAR, k.berkas)
  if (!fs.existsSync(p)) continue
  const isi = fs.readFileSync(p, 'utf8')
  const pola = new RegExp(k.pola.source, k.pola.flags.includes('g') ? k.pola.flags : k.pola.flags + 'g')
  const semuaCocok = [...isi.matchAll(pola)]
  const cocok = k.semua ? semuaCocok : semuaCocok.slice(0, 1)
  if (cocok.length === 0) continue
  const target = k.kunci ? nyata[k.kunci] : null
  for (const m of cocok) {
    const klaim = Number(String(m[k.indeks || 1]).replace(',', '.'))
    const status = target === null ? '?' : klaim === target ? 'OK' : 'BEDA'
    if (status === 'BEDA') adaBeda = true
    baris.push({
      berkas: k.berkas,
      kunci: k.kunci || '(jumlah tes)',
      klaim,
      nyata: target === null ? '-' : target,
      status,
    })
  }
}

console.log('KEADAAN NYATA SEKARANG')
for (const [k, v] of Object.entries(nyata)) console.log(`  ${k.padEnd(14)} ${v}`)
console.log('')
console.log('YANG TERTULIS DI DOKUMEN')
const lebar = (s, n) => String(s).padEnd(n).slice(0, n)
console.log('  ' + lebar('berkas', 34) + lebar('hal', 14) + lebar('klaim', 7) + lebar('nyata', 7) + 'status')
for (const b of baris) {
  console.log('  ' + lebar(b.berkas, 34) + lebar(b.kunci, 14) + lebar(b.klaim, 7) + lebar(b.nyata, 7) + b.status)
}
console.log('')
console.log(adaBeda ? 'ADA ANGKA YANG BEDA' : 'SEMUA ANGKA COCOK')

// Exit code, bukan hanya cetakan. Gerbang menilai langkah ini dari kode keluar;
// sebelum ini skrip selalu keluar 0, sehingga `periksa.ps1`/`periksa.sh` dan CI
// melaporkan "angka dokumen cocok" berapa pun temuannya.
if (adaBeda) process.exitCode = 1
