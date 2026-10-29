#!/usr/bin/env node
'use strict';

/**
 * Penjaga DFD — semantik dan kebasian gambar.
 * -------------------------------------------------------------------------
 * `docs/dfd/build.js` sudah memeriksa black hole, miracle, grey hole, dan
 * ketidakseimbangan aliran antar tingkat, dan ia MENOLAK menulis bila ada yang
 * salah. Tetapi ia tidak pernah dipanggil gerbang: sampai Oktober 2026 satu-
 * satunya cara mengetahui DFD masih sehat adalah menjalankannya dengan tangan.
 * Akibatnya delapan temuan audit DFD berstatus "selesai" hanya bersandar pada
 * pembacaan manusia, dan regresi berikutnya tidak akan berbunyi.
 *
 * Pemeriksa ini menutup dua hal yang bisa dibuktikan tanpa mata:
 *
 *   1. `periksaSemantik()` bersih — setiap aliran punya rute di kedua tingkat,
 *      tidak ada rute yatim, setiap penyimpanan dibaca DAN ditulis, setiap
 *      proses punya masukan dan keluaran, dan aliran batas sistem seimbang;
 *   2. berkas SVG yang di-commit SAMA PERSIS dengan hasil penggambaran data
 *      sekarang. Ini yang menangkap "data diubah, gambar lupa dibangun" —
 *      persis kelas kesalahan yang dulu menghasilkan PDF dan PNG basi.
 *
 * Yang TIDAK diperiksa: apakah gambarnya enak dilihat. Itu tugas mata.
 *
 * Jalankan: node docs/uji/cek-dfd.js
 */

const fs = require('fs');
const path = require('path');

const AKAR = path.resolve(__dirname, '..', '..');
const DFD = path.join(AKAR, 'docs', 'dfd');

const { gambarKonteks, gambarLevel1, periksaSemantik } = require('../dfd/dfd-gambar');

const gagal = [];

/* ------------------------------------------------------------- 1. semantik */

const { masalah, jumlah } = periksaSemantik();
for (const m of masalah) gagal.push(`semantik: ${m}`);

/* ------------------------------------------------- 2. gambar tidak basi */

const GAMBAR = [
  ['01-dfd-konteks.svg', () => gambarKonteks('gane')],
  ['02-dfd-level-1.svg', () => gambarLevel1('gane')],
  ['01-dfd-konteks-yourdon.svg', () => gambarKonteks('yourdon')],
  ['02-dfd-level-1-yourdon.svg', () => gambarLevel1('yourdon')],
];

for (const [nama, bangun] of GAMBAR) {
  const jalur = path.join(DFD, nama);
  const { svg, masalah: masalahTata } = bangun();

  for (const m of masalahTata) gagal.push(`${nama}: ${m}`);

  if (!fs.existsSync(jalur)) {
    gagal.push(`${nama} tidak ada — jalankan "node docs/dfd/build.js" dan "node docs/dfd/build-yourdon.js"`);
    continue;
  }

  if (fs.readFileSync(jalur, 'utf8') !== svg) {
    gagal.push(
      `${nama} BEDA dari hasil penggambaran data sekarang — ` +
        'jalankan "node docs/dfd/build.js" lalu "node docs/dfd/build-yourdon.js"',
    );
  }

  // PNG pasangannya ikut diperiksa keberadaannya. Ukuran pikselnya tidak bisa
  // dibandingkan dengan SVG seperti di ERD, karena ekspor DFD memakai lebar
  // kanvas dari `dfd-data.js`, bukan atribut SVG. Yang bisa dipastikan: berkas
  // ada dan benar-benar PNG.
  const png = path.join(DFD, nama.replace(/\.svg$/, '.png'));
  if (!fs.existsSync(png)) {
    gagal.push(`${nama.replace(/\.svg$/, '.png')} tidak ada — jalankan "docs/dfd/export-png.ps1"`);
  } else {
    const buf = fs.readFileSync(png);
    if (!(buf.length >= 24 && buf.toString('ascii', 1, 4) === 'PNG')) {
      gagal.push(`${nama.replace(/\.svg$/, '.png')} bukan PNG yang sah`);
    }
  }
}

/* ----------------------------------------------------------- kesimpulan */

if (gagal.length) {
  console.error('DFD tidak konsisten:');
  for (const g of gagal) console.error('  - ' + g);
  process.exit(1);
}

console.log(
  `DFD konsisten: ${jumlah.total} aliran batas (${jumlah.masuk} masuk, ${jumlah.keluar} keluar), ` +
    `semantik bersih, 4 SVG sama dengan hasil penggambaran data.`,
);
