// Pemeriksa cakupan kontrak: setiap rute nyata harus muncul di TEPAT satu baris
// tabel, dan jumlah per bagian harus berjumlah total.
//
// Kenapa hanya baris tabel, bukan setiap backtick di dalam prosa: sebuah path
// sering disebut lagi di penjelasan ("`GET /rekap?dari=&sampai=`"), dan
// menghitung penyebutan itu membuat endpoint tampak ganda padahal tabelnya
// benar. Yang mendefinisikan kontrak adalah baris tabelnya.
const fs = require('fs')
const path = require('path')

// Akar repo dicari dari lokasi berkas ini, bukan dari direktori kerja: skrip
// ini harus bisa dijalankan dari mana saja tanpa gagal dengan pesan
// "berkas tidak ditemukan" yang tidak menjelaskan apa pun.
const AKAR = path.resolve(__dirname, '..', '..')

const teks = fs.readFileSync(path.join(AKAR, 'docs/produk/6_TOOLS_CONTRACTS.md'), 'utf8')
const rute = JSON.parse(fs.readFileSync(path.join(AKAR, 'docs/akademik/rute-api.json'), 'utf8'))

function norm(p) {
  return String(p)
    .trim()
    .replace(/^\/?api\//i, '')
    .replace(/^\//, '')
    .replace(/\{[^}]*\}/g, '{}')
    .replace(/\/$/, '')
    .toLowerCase()
}

const bagian = []
let kini = null
for (const b of teks.split(/\r?\n/)) {
  const h = b.match(/^## (\d+)\. (.+)/)
  if (h) {
    kini = { nomor: Number(h[1]), judul: h[2], baris: [] }
    bagian.push(kini)
    continue
  }
  if (!kini) continue
  const m = b.match(/^\|\s*((?:`(?:GET|POST|PUT|PATCH|DELETE)`\s*)+)\|\s*`([^`]+)`/)
  if (!m) continue
  kini.baris.push({
    metode: [...m[1].matchAll(/`([A-Z]+)`/g)].map((x) => x[1]),
    path: m[2],
    nomorBaris: teks.split(/\r?\n/).indexOf(b) + 1,
  })
}

function cocok(r, b) {
  const metodeRute = r.method.split('|')[0].trim()
  if (!b.metode.includes(metodeRute)) return false
  const pola = b.path.replace(/^\/?api\//i, '').replace(/^\//, '')
  const kandidat = []
  if (pola.includes('[')) {
    const dasar = pola.replace(/\[.*$/, '')
    kandidat.push(norm(dasar), norm(dasar + '/{}'))
  } else kandidat.push(norm(pola))
  return kandidat.includes(norm(r.uri))
}

// Peta rute -> bagian tempat ia tercantum di tabel.
const peta = new Map()
for (const bg of bagian) {
  if (bg.nomor > 8) continue
  for (const r of rute) {
    if (bg.baris.some((b) => cocok(r, b))) {
      const kunci = `${r.method.split('|')[0].trim()} ${norm(r.uri)}`
      if (!peta.has(kunci)) peta.set(kunci, [])
      peta.get(kunci).push(bg.nomor)
    }
  }
}

console.log('=== CAKUPAN ===')
console.log(`rute nyata      : ${rute.length}`)
console.log(`rute di tabel   : ${peta.size}`)

const ganda = [...peta.entries()].filter(([, v]) => v.length > 1)
console.log(`tercatat ganda  : ${ganda.length}`)
for (const [k, v] of ganda) console.log(`   ${k}  -> bagian ${v.join(', ')}`)

const belum = rute.filter((r) => {
  const kunci = `${r.method.split('|')[0].trim()} ${norm(r.uri)}`
  if (peta.has(kunci)) return false
  // §8.0.1: satu-satunya endpoint yang sengaja tidak berbentuk baris tabel -
  // pemanggilnya gateway, bukan pengguna. Ia tetap tercatat di dokumen, di
  // subbagian tersendiri, jadi bukan "belum tercatat".
  return !(kunci === 'POST webhook/midtrans' && /webhook\/midtrans/.test(teks))
})
console.log(`belum tercatat  : ${belum.length}`)
for (const r of belum) console.log(`   ${r.method.split('|')[0].trim()} ${r.uri}`)

console.log('')
console.log('=== JUDUL BAGIAN ===')
let jumlahUnik = 0
let judulSalah = false
for (const bg of bagian) {
  if (bg.nomor > 8 || bg.baris.length === 0) continue
  let unik = [...peta.entries()].filter(([, v]) => v.includes(bg.nomor)).length

  // Bagian 8 juga memuat `POST /api/webhook/midtrans` di §8.0.1. Endpoint itu
  // sengaja tidak berbentuk baris tabel - pemanggilnya gateway, bukan pengguna,
  // dan gerbangnya tanda tangan SHA512 - jadi ia tidak akan pernah muncul di
  // peta. Menghitungnya di sini membuat judul "(10 endpoint)" bisa dipercaya.
  let tambahan = 0
  if (bg.nomor === 8 && /webhook\/midtrans/.test(teks)) {
    tambahan = 1
  }

  const klaim = (bg.judul.match(/\((\d+) endpoint\)/) || [])[1]
  const status = klaim
    ? Number(klaim) === unik + tambahan
      ? 'OK'
      : `SALAH (tulis ${klaim}, harus ${unik + tambahan})`
    : '(tanpa angka)'
  if (status.startsWith('SALAH')) judulSalah = true
  console.log(`bagian ${bg.nomor}: ${unik + tambahan}  ${status}`)
  jumlahUnik += unik + tambahan
}
console.log(`jumlah per bagian: ${jumlahUnik}`)
console.log(`rute nyata       : ${rute.length}`)

// Baris total §9 tidak pernah diperiksa loop di atas (ia berhenti di bagian 8),
// dan karena itu "Total 80" sempat lolos padahal bagian-bagiannya berjumlah 85.
const total = (teks.match(/\|\s*\*\*Total\*\*\s*\|\s*\*\*(\d+)\*\*/) || [])[1]
const totalOk = total !== undefined && Number(total) === rute.length
console.log(`baris total §9   : ${total ?? '(tidak ada)'} ${totalOk ? 'OK' : `SALAH (harus ${rute.length})`}`)

const gagal = ganda.length > 0 || belum.length > 0 || judulSalah || jumlahUnik !== rute.length || !totalOk
console.log(gagal ? 'BEDA' : 'COCOK')

// Exit code, bukan hanya cetakan: `periksa.ps1`/`periksa.sh` menilai langkah ini
// dari kode keluar. Tanpa ini, gerbang selalu melaporkan LULUS berapa pun
// temuannya - pagar yang terlihat terpasang tetapi tidak menahan apa pun.
if (gagal) process.exitCode = 1
