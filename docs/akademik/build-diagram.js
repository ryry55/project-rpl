#!/usr/bin/env node
'use strict';

/**
 * Penulis berkas SVG diagram kelas dan diagram urutan.
 * ----------------------------------------------------
 * Jalankan:
 *     node docs/akademik/build-diagram.js
 *
 * Menghasilkan (17 berkas):
 *     kelas-a.svg  kelas-b.svg  kelas-c.svg
 *     urutan-UC-01.svg ... urutan-UC-14.svg
 *
 * Berkas ini sengaja TIDAK berisi pemeriksaan apa pun. Pemeriksaannya ada di
 * `kelas-data.js` (tiap kelas harus menunjuk tabel nyata), `urutan-gambar.js`
 * (rute dan kelas harus nyata), dan masing-masing penggambar (keterbacaan).
 * Kalau data ditolak, penggambar mengembalikan `svg: null` — dan di sini
 * berkas lama dibiarkan apa adanya, bukan ditimpa gambar setengah jadi.
 *
 * PNG-nya dibuat oleh `export-png.ps1`, yang membaca lebar/tinggi dari atribut
 * `width`/`height` tiap SVG. Tidak ada angka kanvas yang dipatok di dua tempat,
 * jadi tidak ada dua angka yang bisa berselisih.
 */

const fs = require('fs');
const path = require('path');

const { KELOMPOK } = require('./kelas-data');
const { gambarKelas } = require('./kelas-gambar');
const { URUTAN } = require('./urutan-data');
const { gambarUrutan, periksa } = require('./urutan-gambar');

const OUT = __dirname;

let gagal = 0;

function laporkan(h) {
  for (const m of h.masalah || []) {
    console.error('   MASALAH: ' + m);
    gagal += 1;
  }
}

function tulis(nama, h) {
  if (!h.svg) {
    console.error(`tidak ditulis: ${nama} (data ditolak)`);
    gagal += 1;
    laporkan(h);
    return;
  }
  fs.writeFileSync(path.join(OUT, nama), h.svg, 'utf8');
  console.log(
    `ditulis: ${nama.padEnd(20)} ${String(h.kanvas.w).padStart(4)}x${String(h.kanvas.h).padEnd(4)} ` +
      `huruf ${h.hurufMm.toFixed(2)} mm di A3`
  );
  laporkan(h);
}

for (const k of KELOMPOK) tulis(`kelas-${k.kunci}.svg`, gambarKelas(k.kunci));

const tolak = periksa();
if (tolak.length) {
  for (const m of tolak) console.error('data ditolak:', m);
  gagal += tolak.length;
} else {
  for (const u of URUTAN) tulis(`urutan-${u.kode}.svg`, gambarUrutan(u));
}

console.log(`\nmasalah: ${gagal}`);
if (gagal) process.exitCode = 1;
