#!/usr/bin/env node
'use strict';

/**
 * Generator DFD notasi YOURDON / DeMARCO — Sistem Informasi Operasional Kos-Kosan
 * -------------------------------------------------------------------------------
 * Membaca data yang sama persis dengan `build.js` (lihat `dfd-data.js`), lalu
 * menggambar dengan konvensi Yourdon: proses berupa lingkaran, penyimpanan data
 * berupa dua garis sejajar, dan nomor proses tanpa akhiran `.0` (1, 2, … bukan
 * 1.0, 2.0, …).
 *
 * Jalankan:
 *     node build-yourdon.js
 *
 * Menghasilkan:
 *     01-dfd-konteks-yourdon.svg
 *     02-dfd-level-1-yourdon.svg
 *
 * Karena kedua skrip membaca berkas data yang sama, isi kedua versi tidak bisa
 * menyimpang. Yang berbeda hanya bentuk simbol dan penomoran — persis seperti
 * yang dijanjikan README.
 */

const fs = require('fs');
const path = require('path');

const { gambarKonteks, gambarLevel1 } = require('./dfd-gambar');

const OUT = __dirname;

const hasil = [
  ['01-dfd-konteks-yourdon.svg', gambarKonteks('yourdon')],
  ['02-dfd-level-1-yourdon.svg', gambarLevel1('yourdon')],
];

let gagal = false;

for (const [nama, { svg, masalah }] of hasil) {
  fs.writeFileSync(path.join(OUT, nama), svg, 'utf8');
  console.log('ditulis:', nama, `(${svg.length} byte)`);
  if (masalah.length) {
    gagal = true;
    console.error(`  ${masalah.length} masalah tata letak:`);
    for (const m of masalah) console.error('   -', m);
  } else {
    console.log('  tata letak: OK (tidak ada garis menembus kotak/lingkaran)');
  }
}

if (gagal) process.exitCode = 1;
