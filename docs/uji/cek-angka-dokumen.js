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
  return fs.readdirSync(path.join(AKAR, 'docs/uji')).filter((f) => f.endsWith('.mjs')).length
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
}

// Angka yang diklaim, beserta berkas dan pola yang menuliskannya.
const KLAIM = [
  { berkas: 'docs/produk/KONTEKS_PROYEK.md', pola: /\|\s*Endpoint API\s*\|\s*\*\*(\d+)\*\*/i, kunci: 'endpoint' },
  { berkas: 'docs/produk/KONTEKS_PROYEK.md', pola: /\|\s*Tabel \(di luar infrastruktur Laravel\)\s*\|\s*\*\*(\d+)\*\*/i, kunci: 'tabel domain' },
  { berkas: 'docs/produk/KONTEKS_PROYEK.md', pola: /\|\s*Migrasi\s*\|\s*\*\*(\d+)\*\*/i, kunci: 'migrasi' },
  { berkas: 'docs/produk/KONTEKS_PROYEK.md', pola: /\|\s*Model Eloquent\s*\|\s*\*\*(\d+)\*\*/i, kunci: 'model' },
  { berkas: 'docs/produk/KONTEKS_PROYEK.md', pola: /\|\s*Skrip browser\s*\|\s*\*\*(\d+)\*\*/i, kunci: 'skrip uji' },
  { berkas: 'docs/serah-terima.md', pola: /\*\*(\d+)\*\* \/ \*\*23\*\* \(total 32 di skema/i, kunci: 'endpoint' },
  { berkas: 'docs/serah-terima.md', pola: /\*\*(\d+)\*\* lulus \/ 3522 asersi/i, kunci: null },
  { berkas: 'docs/serah-terima.md', pola: /\*\*(\d+)\*\* \(24 browser \+ 2 balapan\)/i, kunci: 'skrip uji' },
  { berkas: 'SPEC.md', pola: /(\d+) endpoint/i, kunci: 'endpoint' },
]

let adaBeda = false
const baris = []

for (const k of KLAIM) {
  const p = path.join(AKAR, k.berkas)
  if (!fs.existsSync(p)) continue
  const isi = fs.readFileSync(p, 'utf8')
  const m = isi.match(k.pola)
  if (!m) continue
  const klaim = Number(m[1])
  const target = k.kunci ? nyata[k.kunci] : null
  const cocok = target === null ? '?' : klaim === target ? 'OK' : 'BEDA'
  if (cocok === 'BEDA') adaBeda = true
  baris.push({
    berkas: k.berkas,
    kunci: k.kunci || '(jumlah tes)',
    klaim,
    nyata: target === null ? '-' : target,
    status: cocok,
  })
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
