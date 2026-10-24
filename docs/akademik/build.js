#!/usr/bin/env node
'use strict';

/**
 * Generator dokumen akademik — Sistem Informasi Operasional Kos-Kosan
 * -------------------------------------------------------------------
 * Sumber editable:
 *     usecase-data.js   — aktor, 14 use case, matriks, catatan kaki, tata letak
 *     usecase-gambar.js — cara menggambar + pemeriksa kelengkapan & tata letak
 *     rute-api.json     — potret `php artisan route:list --json` (dibuat ulang,
 *                         lihat perintah di bagian bawah berkas ini)
 *
 * Jalankan:
 *     node docs/akademik/build.js
 *
 * Menghasilkan:
 *     01-use-case.svg
 *     02-dfd-konteks.svg
 *
 * Build GAGAL bila jumlah use case bukan 14, ada endpoint yang tidak dipakai
 * atau dipakai dua kali, keanggotaan aktor tidak cocok dengan middleware,
 * label melampaui elipsnya, atau garis asosiasi menembus elips lain.
 *
 * Membuat ulang rute-api.json (dari akar repo, layanan api harus hidup):
 *     cmd /c "docker compose exec -T api php artisan route:list --json --path=api > docs\akademik\rute-api.json 2>nul"
 *
 * Perintah itu sengaja memakai `cmd /c`. Pengalihan `>` milik PowerShell
 * menulis berkas sebagai UTF-16LE ber-BOM, dan `JSON.parse` menolaknya —
 * kegagalan yang sempat terjadi dan menghabiskan waktu.
 */

const fs = require('fs');
const path = require('path');

const { gambarUseCase } = require('./usecase-gambar');
const { gambarKonteksAkademik } = require('./dfd-konteks-gambar');

const OUT = __dirname;
const RUTE = JSON.parse(fs.readFileSync(path.join(OUT, 'rute-api.json'), 'utf8'));

const hasil = [
  ['01-use-case.svg', gambarUseCase(RUTE)],
  ['02-dfd-konteks.svg', gambarKonteksAkademik()],
];

let gagal = false;

for (const [nama, { svg, masalah, ringkas }] of hasil) {
  if (svg === null) {
    // Data ditolak sebelum sempat digambar; biarkan SVG lama apa adanya.
    gagal = true;
    console.error(`tidak ditulis: ${nama} (data ditolak)`);
    for (const m of masalah) console.error('   -', m);
    continue;
  }
  fs.writeFileSync(path.join(OUT, nama), svg, 'utf8');
  console.log('ditulis:', nama, `(${svg.length} byte)`);
  if (ringkas) console.log('  ' + ringkas);
  if (masalah.length) {
    gagal = true;
    console.error(`  ${masalah.length} masalah:`);
    for (const m of masalah) console.error('   -', m);
  } else {
    console.log('  periksa: OK');
  }
}

if (gagal) process.exitCode = 1;
