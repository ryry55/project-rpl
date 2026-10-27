#!/usr/bin/env node
'use strict';

/**
 * Generator ERD — Sistem Informasi Operasional Kos-Kosan
 * -------------------------------------------------------------------------
 * Sumber:
 *     skema.json     — skema NYATA dari basis data (lihat ambil-skema.js)
 *     erd-data.js    — tata letak + kata kerja relasi
 *     erd-gambar.js  — cara menggambar + pemeriksa
 *
 * Jalankan:
 *     node docs/erd/ambil-skema.js    (hanya bila skema berubah)
 *     node docs/erd/build.js
 *
 * Menghasilkan:
 *     01-erd-inti.svg        7 entitas, alur uang
 *     02-erd-lengkap.svg     23 tabel domain
 *
 * Build GAGAL bila ada kunci asing yang tidak digambar dan tidak terdaftar,
 * bila ada relasi yang tidak ada di basis data, atau bila ada simbol yang
 * saling menimpa.
 */

const fs = require('fs');
const path = require('path');

const { INTI, LENGKAP } = require('./erd-data');
const { gambar } = require('./erd-gambar');

const OUT = __dirname;

const hasil = [
  ['01-erd-inti.svg', INTI],
  ['02-erd-lengkap.svg', LENGKAP],
];

let gagal = false;

for (const [nama, definisi] of hasil) {
  const { svg, masalah, jumlahRelasi } = gambar(definisi);
  fs.writeFileSync(path.join(OUT, nama), svg, 'utf8');
  console.log(`ditulis: ${nama} (${definisi.entitas.length} entitas, ${jumlahRelasi} relasi, ${svg.length} byte)`);
  if (masalah.length) {
    gagal = true;
    console.error(`  ${masalah.length} masalah:`);
    for (const m of masalah) console.error('   -', m);
  } else {
    console.log('  tata letak: OK (tidak ada simbol bertumpuk, tidak ada garis menembus kotak)');
  }
}

if (gagal) process.exitCode = 1;
