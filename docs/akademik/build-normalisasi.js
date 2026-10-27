#!/usr/bin/env node
'use strict';

/**
 * Menggambar tabel tahap normalisasi (NF0, 1NF, 2NF, 3NF) sebagai SVG.
 *
 * Kenapa digambar, bukan sekadar tabel markdown: laporan referensi menampilkan
 * keempat tahap ini sebagai GAMBAR (Gambar 4.1–4.4). Kalau yang dipakai tabel
 * markdown, caption "Gambar 4.1" menunjuk sesuatu yang bukan gambar — dan
 * ketika ditempel ke Word, tabel itu kehilangan bentuknya.
 *
 * Tabel panjang (NF0, 1NF) dipecah per kelompok baris supaya tetap terbaca;
 * setiap potongan diberi judul bersambung ("NF0 (1 dari 2)").
 *
 * Pakai: node docs/akademik/build-normalisasi.js
 */

const fs = require('fs');
const path = require('path');
const { NORMALISASI } = require('./bab4-data');

const HURUF = 12;
const TINGGI_BARIS = 26;
const PADDING = 14;
const LEBAR_MIN_KOLOM = 62;

// Kolom yang isinya panjang diberi porsi lebih lebar.
const BOBOT = {
  'Nama Penghuni': 2.0,
  'Fasilitas Kamar': 3.2,
  Fasilitas: 2.6,
  'Atribut Non-Kunci': 3.0,
  'Kunci Asing': 2.0,
  'Kunci Primer': 1.6,
  'No. HP': 1.6,
  Tarif: 1.3,
  Dibayar: 1.3,
  Relasi: 2.0,
  Ketergantungan: 1.6,
};

const escape = (s) =>
  String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

/** Lebar kolom sebanding bobot, dengan lantai supaya kolom pendek tidak gepeng. */
function lebarKolom(kolom) {
  const totalBobot = kolom.reduce((n, k) => n + (BOBOT[k] || 1), 0);
  const LEBAR_TOTAL = 900;
  return kolom.map((k) => {
    const w = (LEBAR_TOTAL * (BOBOT[k] || 1)) / totalBobot;
    return Math.max(w, LEBAR_MIN_KOLOM);
  });
}

function gambarSatu(n, potongan, nomorPotongan, jumlahPotongan) {
  const kolom = n.kolom;
  const lebar = lebarKolom(kolom);
  const lebarTabel = lebar.reduce((a, b) => a + b, 0);

  const judul =
    jumlahPotongan > 1
      ? `${n.judul}  (${nomorPotongan} dari ${jumlahPotongan})`
      : n.judul;

  const tinggiJudul = 46;
  const tinggiTabel = TINGGI_BARIS * (potongan.length + 1);
  const lebarKanvas = Math.ceil(lebarTabel + PADDING * 2);
  const tinggiKanvas = Math.ceil(tinggiJudul + tinggiTabel + PADDING * 2 + 8);

  const out = [];
  out.push(
    `<svg xmlns="http://www.w3.org/2000/svg" width="${lebarKanvas}" height="${tinggiKanvas}" ` +
      `viewBox="0 0 ${lebarKanvas} ${tinggiKanvas}" font-family="Arial, Helvetica, sans-serif">`,
  );
  out.push(`<rect width="100%" height="100%" fill="#ffffff"/>`);

  // Judul tahap
  out.push(
    `<text x="${PADDING}" y="${PADDING + 16}" font-size="${HURUF + 4}" font-weight="bold" fill="#111">${escape(
      judul,
    )}</text>`,
  );

  const y0 = tinggiJudul;
  let x = PADDING;

  // Kepala kolom
  kolom.forEach((k, i) => {
    out.push(
      `<rect x="${x}" y="${y0}" width="${lebar[i]}" height="${TINGGI_BARIS}" ` +
        `fill="#e8eef7" stroke="#5a6b85" stroke-width="1"/>`,
    );
    out.push(
      `<text x="${x + 8}" y="${y0 + TINGGI_BARIS / 2 + 4}" font-size="${HURUF}" ` +
        `font-weight="bold" fill="#123">${escape(k)}</text>`,
    );
    x += lebar[i];
  });

  // Baris data
  potongan.forEach((r, ri) => {
    const y = y0 + TINGGI_BARIS * (ri + 1);
    let xk = PADDING;
    kolom.forEach((_, ci) => {
      const isi = r[ci] ?? '';
      out.push(
        `<rect x="${xk}" y="${y}" width="${lebar[ci]}" height="${TINGGI_BARIS}" ` +
          `fill="#ffffff" stroke="#a8b4c6" stroke-width="1"/>`,
      );
      // Teks yang kepanjangan dipotong dengan elipsis supaya tidak menembus sel.
      const maks = Math.floor((lebar[ci] - 14) / (HURUF * 0.52));
      const tampil = isi.length > maks ? isi.slice(0, Math.max(maks - 1, 1)) + '…' : isi;
      out.push(
        `<text x="${xk + 7}" y="${y + TINGGI_BARIS / 2 + 4}" font-size="${HURUF}" ` +
          `fill="#222">${escape(tampil)}</text>`,
      );
      xk += lebar[ci];
    });
  });

  out.push('</svg>');
  return out.join('\n');
}

/** Memecah baris supaya tinggi gambar tidak melebihi halaman A4. */
function pecah(barisArr, maks) {
  if (barisArr.length <= maks) return [barisArr];
  const hasil = [];
  for (let i = 0; i < barisArr.length; i += maks) hasil.push(barisArr.slice(i, i + maks));
  return hasil;
}

const MAKS_BARIS = 6;
let ditulis = 0;

for (const n of NORMALISASI) {
  const potongan = pecah(n.baris, MAKS_BARIS);
  potongan.forEach((p, i) => {
    const svg = gambarSatu(n, p, i + 1, potongan.length);
    const nama =
      potongan.length > 1
        ? `04-${n.nomor}nf-${i + 1}.svg`
        : `04-${n.nomor}nf.svg`;
    const jalur = path.join(__dirname, nama);
    fs.writeFileSync(jalur, svg, 'utf8');
    console.log(`  ${nama}  ${p.length} baris`);
    ditulis++;
  });
}

console.log(`\n${ditulis} berkas SVG ditulis untuk 4 tahap normalisasi.`);
