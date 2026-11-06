#!/usr/bin/env node
'use strict';

/**
 * Penjaga diagram kelas dan diagram urutan.
 * ---------------------------------------------------------------
 * Dua belas diagram ini diserahkan ke dosen, dan kesalahannya tidak berbunyi:
 * garis yang menumpuk tetap terlihat seperti garis, dan gambar yang dibuat
 * dari data lama tetap terlihat seperti gambar. Karena itu yang diperiksa di
 * sini bukan "apakah bisa digambar", melainkan:
 *
 *   1. pagar tata letak BENAR-BENAR MENOLAK bila datanya salah — dibuktikan
 *      dengan merusak data di dalam berkas ini, bukan dengan mempercayai pagar;
 *   2. gambar yang di-commit SAMA PERSIS dengan hasil penggambaran data
 *      sekarang, sehingga "data diubah, gambar lupa dibangun" tertangkap;
 *   3. setiap PNG ada, sah, dan berukuran tepat 2x SVG-nya. Ekspor pernah
 *      menghasilkan 4x dengan gambar mengecil di pojok tanpa satu pun galat.
 *
 * Yang TIDAK diperiksa: apakah gambarnya enak dilihat. Itu tugas mata.
 *
 * Jalankan: node docs/uji/cek-diagram.js
 */

const fs = require('fs');
const path = require('path');
const { createRequire } = require('module');

const AKAR = path.resolve(__dirname, '..', '..');
const AKADEMIK = path.join(AKAR, 'docs', 'akademik');

const { KELOMPOK } = require('../akademik/kelas-data');
const { gambarKelas, segmenTembus } = require('../akademik/kelas-gambar');
const { URUTAN } = require('../akademik/urutan-data');
const { gambarUrutan, periksa } = require('../akademik/urutan-gambar');

const gagal = [];

/* ------------------------------------------- 0. pagar harus bisa menolak */

// Membaca ulang berkas penggambar dengan satu baris diubah. Ini menguji pagar
// yang dikirim, bukan salinannya. `require`-nya harus disandarkan pada berkas
// aslinya, kalau tidak `./kelas-data` dicari dari folder docs/uji.
const perluAsli = createRequire(path.join(AKAR, 'docs', 'akademik', 'kelas-gambar.js'));

function muat(sumber) {
  const modul = { exports: {} };
  // eslint-disable-next-line no-new-func
  new Function('require', 'module', 'exports', '__dirname', sumber)(
    perluAsli,
    modul,
    modul.exports,
    path.join(AKAR, 'docs', 'akademik')
  );
  return modul.exports;
}

const sumberKelas = fs.readFileSync(path.join(AKADEMIK, 'kelas-gambar.js'), 'utf8');
const barisJangkar = 'const frac = n === 1 ? 0.5 : 0.2 + (0.6 * i) / (n - 1);';

function kendali(nama, lulus, pesan) {
  if (!lulus) gagal.push(`kontrol negatif "${nama}" tidak menolak: ${pesan}`);
}

if (!sumberKelas.includes(barisJangkar)) {
  gagal.push('kontrol negatif tidak bisa dijalankan: baris pembagi jangkar tidak ditemukan di kelas-gambar.js');
} else {
  const rusak = muat(sumberKelas.replace(barisJangkar, 'const frac = 0.5;'));
  const hasil = rusak.gambarKelas('c');
  kendali('ujung garis berimpit', hasil.masalah.some((m) => m.includes('berimpit')), 'semua ujung ditaruh di satu titik');
}

// Pagar "garis menembus kotak" diuji langsung pada fungsinya: jalur yang
// memotong persegi harus tertangkap, jalur yang lewat di luarnya tidak.
kendali(
  'garis menembus kotak',
  segmenTembus('M0 100 H500', { x1: 100, y1: 50, x2: 200, y2: 150 }) === true,
  'jalur mendatar yang memotong persegi tidak dikenali'
);
kendali(
  'garis di luar kotak',
  segmenTembus('M0 100 H500', { x1: 100, y1: 200, x2: 200, y2: 250 }) === false,
  'jalur yang lewat di atas persegi dituduh menembus'
);
kendali(
  'garis vertikal menembus kotak',
  segmenTembus('M100 0 V300', { x1: 50, y1: 100, x2: 150, y2: 200 }) === true,
  'jalur tegak yang memotong persegi tidak dikenali'
);

// Diagram urutan: satu skenario palsu dengan rute dan kelas karangan harus
// ditolak. Bila tidak, seluruh pemeriksaan nama di berkas itu tidak berarti.
URUTAN.push({
  kode: 'UC-99',
  judul: 'Uji pagar',
  aktor: 'Tidak Ada',
  endpoint: 'api.tidak-ada',
  balik: [1],
  peserta: [
    ['aktor', 'Tidak Ada', 'aktor'],
    ['ctrl', 'KelasKarangan', 'kendali'],
  ],
  langkah: [['aktor', 'ctrl', 'x()']],
});
try {
  const tolak = periksa();
  kendali(
    'rute dan kelas karangan',
    tolak.some((m) => m.includes('tidak ada di rute-api.json')) &&
      tolak.some((m) => m.includes('tidak ada di api/app')),
    'rute atau kelas yang tidak ada lolos'
  );
} finally {
  URUTAN.pop();
}

/* ------------------------------------------------ 1. data dan gambar */

const GAMBAR = [];
for (const k of KELOMPOK) {
  GAMBAR.push([`kelas-${k.kunci}.svg`, gambarKelas(k.kunci)]);
}

const tolakUrutan = periksa();
for (const m of tolakUrutan) gagal.push(`urutan: ${m}`);

if (!tolakUrutan.length) {
  for (const u of URUTAN) GAMBAR.push([`urutan-${u.kode}.svg`, gambarUrutan(u)]);
}

const ukuranPng = (buf) => ({ w: buf.readUInt32BE(16), h: buf.readUInt32BE(20) });

for (const [nama, hasil] of GAMBAR) {
  for (const m of hasil.masalah || []) gagal.push(`${nama}: ${m}`);

  const jalur = path.join(AKADEMIK, nama);
  if (!hasil.svg) {
    gagal.push(`${nama} tidak bisa digambar dari data sekarang`);
    continue;
  }
  if (!fs.existsSync(jalur)) {
    gagal.push(`${nama} tidak ada — jalankan "node docs/akademik/build-diagram.js"`);
  } else if (fs.readFileSync(jalur, 'utf8') !== hasil.svg) {
    gagal.push(`${nama} BEDA dari hasil penggambaran data sekarang — jalankan "node docs/akademik/build-diagram.js"`);
  }

  const png = path.join(AKADEMIK, nama.replace(/\.svg$/, '.png'));
  if (!fs.existsSync(png)) {
    gagal.push(`${nama.replace(/\.svg$/, '.png')} tidak ada — jalankan "docs/akademik/export-png.ps1"`);
    continue;
  }
  const buf = fs.readFileSync(png);
  if (!(buf.length >= 24 && buf.toString('ascii', 1, 4) === 'PNG')) {
    gagal.push(`${nama.replace(/\.svg$/, '.png')} bukan PNG yang sah`);
    continue;
  }
  const { w, h } = ukuranPng(buf);
  if (w !== hasil.kanvas.w * 2 || h !== hasil.kanvas.h * 2) {
    gagal.push(
      `${nama.replace(/\.svg$/, '.png')} berukuran ${w}x${h}, seharusnya ${hasil.kanvas.w * 2}x${hasil.kanvas.h * 2}`
    );
  }
}

/* ----------------------------------------------------------- kesimpulan */

if (gagal.length) {
  console.error('Diagram kelas/urutan tidak konsisten:');
  for (const g of gagal) console.error('  - ' + g);
  process.exit(1);
}

console.log(
  `Diagram konsisten: ${KELOMPOK.length} lembar kelas, ${URUTAN.length} diagram urutan, ` +
    'pagar tata letak terbukti bisa menolak, SVG dan PNG sama dengan data sekarang.'
);
