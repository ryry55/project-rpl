#!/usr/bin/env node
'use strict';

/**
 * Pembangun tabel README — Sistem Informasi Operasional Kos-Kosan
 * ---------------------------------------------------------------
 * Mengisi bagian di antara penanda
 *
 *     <!-- AWAL:nama-bagian -->
 *     <!-- AKHIR:nama-bagian -->
 *
 * di README.md dengan tabel yang dihasilkan dari `dfd-data.js`.
 *
 * Kenapa dibuat: cacat DFD yang dilaporkan audit sebagian besar berupa
 * KETIDAKCOCOKAN antara gambar dan dokumen — kamus data masih menyebut
 * `parameter_denda` yang sudah tidak ada, metode pembayaran masih hanya
 * `tunai|transfer`, dan satu aliran yang ada di level 0 hilang di level 1.
 * Selama tabel ditulis tangan, ketidakcocokan itu akan terjadi lagi setiap
 * kali data berubah. Sekarang tabel tidak punya sumber sendiri.
 *
 * Skrip ini MENOLAK menulis bila ada penanda yang hilang atau aliran yang
 * tidak punya rute, sehingga README tidak bisa diam-diam tertinggal.
 */

const fs = require('fs');
const path = require('path');

const D = require('./dfd-data');

const OUT = __dirname;
const BERKAS = path.join(OUT, 'README.md');

/* ------------------------------------------------------------- bantuan */

const namaEntitas = Object.fromEntries(D.ENTITAS.map((e) => [e.id, e.nama]));
const ringkasProses = Object.fromEntries(D.PROSES.map((p) => [p.kunci, p.id]));
const ringkasStore = Object.fromEntries(D.STORE.map((s) => [s.kunci, s.id]));

/** Ubah kunci internal (E1/P3/D4) menjadi sebutan yang terbaca manusia. */
function sebut(kunci, jenis) {
  if (jenis === 'entitas') return namaEntitas[kunci] || kunci;
  if (jenis === 'proses') return `Proses ${ringkasProses[kunci] || kunci}`;
  if (jenis === 'store') return `Store ${ringkasStore[kunci] || kunci}`;
  return kunci;
}

function sebutUjung(kunci) {
  if (namaEntitas[kunci]) return sebut(kunci, 'entitas');
  if (ringkasProses[kunci]) return sebut(kunci, 'proses');
  if (ringkasStore[kunci]) return sebut(kunci, 'store');
  return kunci;
}

/** Tabel markdown dari kepala dan baris. */
function tabel(kepala, baris) {
  const pisah = kepala.map(() => '---');
  const semua = [kepala, pisah, ...baris];
  return semua.map((r) => `| ${r.join(' | ')} |`).join('\n');
}

/** Teks satu baris, aman untuk sel tabel markdown. */
const sel = (s) => String(s).replace(/\|/g, '\\|').replace(/\n/g, ' ').trim();

const labelSatuBaris = (label) => sel([].concat(label).join(' '));

const tabelAliran = (daftar) => {
  if (daftar.length === 0) return '_tidak ada_';
  return tabel(
    ['Kode', 'Dari', 'Ke', 'Nama aliran', 'Struktur data'],
    daftar.map((a) => [
      `**${a.kode}**`,
      sebutUjung(a.dari),
      sebutUjung(a.ke),
      labelSatuBaris(a.label),
      `<code>${sel(a.struktur)}</code>`,
    ])
  );
};

/* -------------------------------------------------------------- bagian */

function bagianAliranKonteks() {
  const daftar = D.ALIRAN.filter((a) => a.jenis === 'masuk' || a.jenis === 'keluar');
  const masuk = daftar.filter((a) => a.jenis === 'masuk');
  const keluar = daftar.filter((a) => a.jenis === 'keluar');

  return [
    '**Aliran masuk** — dari entitas ke sistem:',
    '',
    tabelAliran(masuk),
    '',
    '**Aliran keluar** — dari sistem ke entitas:',
    '',
    tabelAliran(keluar),
    '',
    `Jumlahnya ${masuk.length} masuk dan ${keluar.length} keluar. Angka yang sama muncul di level 1,`,
    'karena setiap aliran batas sistem digambar persis sekali di masing-masing tingkat.',
  ].join('\n');
}

function bagianProses() {
  return tabel(
    ['No', 'Nama proses', 'Aturan yang dijalankan', 'Keterangan'],
    D.PROSES.map((p) => [
      `**${p.id}**`,
      sel(p.nama.join(' ')),
      p.aturan.length ? p.aturan.join(', ') : '—',
      sel(p.keterangan),
    ])
  );
}

function bagianStore() {
  return tabel(
    ['Kode', 'Penyimpanan', 'Atribut utama', 'Keterangan'],
    D.STORE.map((s) => [
      `**${s.id}**`,
      sel(s.nama.join(' ')),
      `<code>${sel(s.atribut)}</code>`,
      sel(s.isi),
    ])
  );
}

function bagianKamus() {
  const baris = D.KAMUS.map(([nama, isi]) => (nama ? `${nama} = ${isi}` : `      ${isi}`));
  return ['```', ...baris, '```'].join('\n');
}

function bagianAturan() {
  return tabel(
    ['Kode', 'Aturan', 'Proses', 'Yang diuji'],
    D.ATURAN.map((a) => [`**${a.kode}**`, sel(a.aturan), sel(a.proses), sel(a.uji)])
  );
}

/* --------------------------------------------------------------- isi */

const BAGIAN = {
  'aliran-konteks': bagianAliranKonteks,
  proses: bagianProses,
  store: bagianStore,
  'aliran-masuk': () => tabelAliran(D.ALIRAN.filter((a) => a.jenis === 'masuk')),
  'aliran-keluar': () => tabelAliran(D.ALIRAN.filter((a) => a.jenis === 'keluar')),
  'aliran-internal': () => tabelAliran(D.ALIRAN.filter((a) => a.jenis === 'internal')),
  kamus: bagianKamus,
  aturan: bagianAturan,
};

/* ------------------------------------------------------------ jalankan */

let teks = fs.readFileSync(BERKAS, 'utf8');
const masalah = [];

for (const [nama, buat] of Object.entries(BAGIAN)) {
  const awal = `<!-- AWAL:${nama} -->`;
  const akhir = `<!-- AKHIR:${nama} -->`;
  const iAwal = teks.indexOf(awal);
  const iAkhir = teks.indexOf(akhir);

  if (iAwal === -1 || iAkhir === -1 || iAkhir < iAwal) {
    masalah.push(`penanda "${nama}" tidak ditemukan atau tidak berpasangan di README.md`);
    continue;
  }

  const sebelum = teks.slice(0, iAwal + awal.length);
  const sesudah = teks.slice(iAkhir);
  teks = `${sebelum}\n\n${buat()}\n\n${sesudah}`;
}

// Penjaga tambahan: aliran batas sistem wajib punya rute di kedua tingkat,
// karena itulah yang membuat level 0 dan level 1 seimbang.
const batas = D.ALIRAN.filter((a) => a.jenis === 'masuk' || a.jenis === 'keluar');
for (const a of batas) {
  if (!D.RUTE_KONTEKS[a.kunci]) masalah.push(`aliran ${a.kode} tidak punya rute di DFD konteks`);
  if (!D.RUTE_LEVEL1[a.kunci]) masalah.push(`aliran ${a.kode} tidak punya rute di DFD level 1`);
}

if (masalah.length) {
  console.error(`${masalah.length} masalah, README TIDAK ditulis:`);
  for (const m of masalah) console.error(' -', m);
  process.exitCode = 1;
} else {
  fs.writeFileSync(BERKAS, teks, 'utf8');
  const n = (s) => D.ALIRAN.filter((a) => a.jenis === s).length;
  console.log(
    `README.md diperbarui: ${D.PROSES.length} proses, ${D.STORE.length} penyimpanan, ` +
      `${n('masuk')} aliran masuk, ${n('keluar')} aliran keluar, ${n('internal')} aliran internal, ` +
      `${D.KAMUS.length} baris kamus, ${D.ATURAN.length} aturan.`
  );
}
