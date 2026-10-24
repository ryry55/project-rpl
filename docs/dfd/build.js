#!/usr/bin/env node
'use strict';

/**
 * Generator DFD notasi GANE-SARSON — Sistem Informasi Operasional Kos-Kosan
 * -------------------------------------------------------------------------
 * Sumber editable:
 *     dfd-data.js    — entitas, proses, penyimpanan, aliran, rute, kamus data
 *     dfd-gambar.js  — cara menggambar + pemeriksa tata letak & semantik
 *
 * Berkas ini hanya memilih notasi dan menulis hasilnya ke cakram. Jalankan:
 *     node build.js
 *
 * Menghasilkan:
 *     01-dfd-konteks.svg
 *     02-dfd-level-1.svg
 *
 * Build GAGAL bila ada garis aliran yang menembus kotak, atau bila ada
 * penyimpanan data yang tidak pernah dibaca, proses tanpa masukan, atau
 * aliran batas sistem yang tidak seimbang antara level 0 dan level 1.
 */

const fs = require('fs');
const path = require('path');

const { gambarKonteks, gambarLevel1, periksaSemantik } = require('./dfd-gambar');

const OUT = __dirname;

const hasil = [
  ['01-dfd-konteks.svg', gambarKonteks('gane')],
  ['02-dfd-level-1.svg', gambarLevel1('gane')],
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
    console.log('  tata letak: OK (tidak ada garis menembus kotak)');
  }
}

const { masalah: masalahSemantik, jumlah } = periksaSemantik();
if (masalahSemantik.length) {
  gagal = true;
  console.error(`  ${masalahSemantik.length} masalah semantik:`);
  for (const m of masalahSemantik) console.error('   -', m);
} else {
  console.log(
    `  semantik: OK (${jumlah.total} aliran batas: ${jumlah.masuk} masuk, ${jumlah.keluar} keluar; ` +
      'semua penyimpanan dibaca & ditulis, semua proses punya masukan & keluaran)'
  );
}

if (gagal) process.exitCode = 1;
