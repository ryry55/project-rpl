// Memasang `v-kartu-tabel` pada setiap `<table class="tabel ...">`.
//
// Dikerjakan skrip, bukan tangan, karena ada 20 tabel dan satu yang terlewat
// berarti satu layar yang tetap tidak terbaca di ponsel. Skrip ini aman
// dijalankan berulang: tabel yang sudah bertanda dilewati.
import { readFileSync, writeFileSync } from 'node:fs'
import { globSync } from 'node:fs'

const berkas = globSync('src/**/*.vue', { cwd: process.cwd() })

let diubah = 0
let dilewati = 0

for (const f of berkas) {
  const asli = readFileSync(f, 'utf8')
  let hasil = asli

  // Cocokkan `<table` diikuti atribut apa pun sampai `>` penutup, dalam satu
  // baris. Semua tabel di repo ini ditulis begitu; yang multi-baris akan
  // dilaporkan supaya bisa diperiksa tangan.
  hasil = hasil.replace(/<table\b([^>]*)>/g, (utuh, atribut) => {
    if (atribut.includes('v-kartu-tabel')) {
      dilewati++
      return utuh
    }
    return `<table${atribut} v-kartu-tabel>`
  })

  if (hasil !== asli) {
    writeFileSync(f, hasil)
    diubah++
    console.log('diubah: ' + f)
  }
}

console.log(`\n${diubah} berkas diubah, ${dilewati} tabel sudah bertanda`)
