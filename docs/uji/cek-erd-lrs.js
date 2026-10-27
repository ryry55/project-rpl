#!/usr/bin/env node
'use strict';

/**
 * Penjaga dokumen ERD dan LRS — memastikan keduanya masih cocok dengan
 * basis data, dan tidak diam-diam menyimpang.
 * -------------------------------------------------------------------------
 * Dokumen akademik di proyek ini pernah basi: `rute-api.json` tertinggal
 * empat endpoint, dan tidak ada yang gagal. ERD dan LRS menghadapi risiko yang
 * sama, hanya saja akibatnya lebih buruk: gambar yang salah terlihat persis
 * seperti gambar yang benar.
 *
 * Karena itu pemeriksa ini tidak membaca gambar, melainkan membandingkan
 * EMPAT hal yang bisa dibuktikan:
 *
 *   1. `skema.json` masih cocok dengan basis data yang berjalan — kalau ada
 *      migrasi baru, berkas ini harus diambil ulang;
 *   2. setiap tabel di basis data muncul di LRS, dan tidak ada tabel di LRS
 *      yang sudah tidak ada di basis data;
 *   3. setiap kunci asing muncul di tabel relasi LRS, dengan tujuan yang sama;
 *   4. setiap dokumen hasil build ada dan tidak kosong.
 *
 * Yang TIDAK diperiksa di sini: apakah gambarnya bagus. Itu tugas mata, dan
 * `build.js` sudah menolak tumpang tindih simbol.
 *
 * Jalankan: node docs/uji/cek-erd-lrs.js
 */

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const AKAR = path.resolve(__dirname, '..', '..');
const gagal = [];

function lapor(pesan) {
  gagal.push(pesan);
}

/* --------------------------------------------------- 1. skema vs basis data */

const KECUALI = [
  'cache',
  'cache_locks',
  'failed_jobs',
  'job_batches',
  'jobs',
  'migrations',
  'password_reset_tokens',
  'personal_access_tokens',
  'sessions',
];
const daftarKecuali = KECUALI.map((t) => `'${t}'`).join(', ');

function tanya(sql) {
  const keluaran = execFileSync(
    'docker',
    ['compose', 'exec', '-T', 'db', 'psql', '-U', 'kos', '-d', 'kos', '-t', '-A', '-f', '-'],
    { input: sql, cwd: AKAR, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }
  );
  return JSON.parse(keluaran.trim());
}

let hidup;
try {
  hidup = tanya(`
    select coalesce(json_agg(x order by x.tabel, x.kolom), '[]'::json)
    from (
      select c.table_name as tabel, c.column_name as kolom
      from information_schema.columns c
      join information_schema.tables t
        on t.table_schema = c.table_schema
       and t.table_name = c.table_name
       and t.table_type = 'BASE TABLE'
      where c.table_schema = 'public'
        and c.table_name not in (${daftarKecuali})
    ) x;
  `);
} catch (e) {
  console.error('Tidak bisa membaca basis data. Apakah container `db` hidup?');
  console.error('  ' + String(e.message).split('\n')[0]);
  process.exit(1);
}

const skema = require('../erd/skema.json');

const kolomHidup = new Set(hidup.map((k) => `${k.tabel}.${k.kolom}`));
const kolomSkema = new Set();
for (const t of skema.tabel) {
  for (const k of t.kolom) kolomSkema.add(`${t.nama}.${k.nama}`);
}

for (const k of kolomHidup) {
  if (!kolomSkema.has(k)) {
    lapor(`kolom ${k} ada di basis data tetapi belum ada di skema.json — jalankan "node docs/erd/ambil-skema.js"`);
  }
}
for (const k of kolomSkema) {
  if (!kolomHidup.has(k)) {
    lapor(`kolom ${k} ada di skema.json tetapi sudah tidak ada di basis data`);
  }
}

const tabelHidup = new Set(hidup.map((k) => k.tabel));
const tabelSkema = new Set(skema.tabel.map((t) => t.nama));
for (const t of tabelHidup) {
  if (!tabelSkema.has(t)) lapor(`tabel ${t} ada di basis data tetapi belum ada di skema.json`);
}
for (const t of tabelSkema) {
  if (!tabelHidup.has(t)) lapor(`tabel ${t} ada di skema.json tetapi sudah tidak ada di basis data`);
}

/* ------------------------------------------------------- 2. LRS lengkap */

const jalurLrs = path.join(AKAR, 'docs', 'erd', '03-lrs.md');
if (!fs.existsSync(jalurLrs)) {
  lapor('docs/erd/03-lrs.md tidak ada — jalankan "node docs/erd/build-lrs.js"');
} else {
  const isi = fs.readFileSync(jalurLrs, 'utf8');

  // Setiap tabel harus muncul sebagai judul rincian "### 4.N nama_tabel".
  for (const t of tabelSkema) {
    if (!isi.includes(`### 4.`) || !new RegExp(`^### 4\\.\\d+ ${t}$`, 'm').test(isi)) {
      lapor(`tabel ${t} tidak punya bagian rincian di LRS`);
    }
  }

  // Setiap kunci asing harus muncul di tabel relasi §5.
  let jumlahFk = 0;
  for (const t of skema.tabel) {
    for (const a of t.asing) {
      jumlahFk++;
      const pola = new RegExp(
        '\\| `' + t.nama + '` \\| `' + a.kolom + '` \\| `' + a.ke_tabel + '\\.' + a.ke_kolom + '` \\|'
      );
      if (!pola.test(isi)) {
        lapor(`kunci asing ${t.nama}.${a.kolom} -> ${a.ke_tabel}.${a.ke_kolom} tidak ada di tabel relasi LRS`);
      }
    }
  }

  // Angka yang ditulis di dokumen harus sama dengan yang dihitung.
  if (!isi.includes(`Jumlah kunci asing: **${jumlahFk}**`)) {
    lapor(`LRS tidak menyebut jumlah kunci asing yang benar (${jumlahFk})`);
  }
  if (!isi.includes(`${skema.jumlah_tabel} tabel`)) {
    lapor(`LRS tidak menyebut jumlah tabel yang benar (${skema.jumlah_tabel})`);
  }
}

/* --------------------------------------------------- 3. hasil build ERD */

for (const nama of ['01-erd-inti.svg', '02-erd-lengkap.svg']) {
  const jalur = path.join(AKAR, 'docs', 'erd', nama);
  if (!fs.existsSync(jalur)) {
    lapor(`docs/erd/${nama} tidak ada — jalankan "node docs/erd/build.js"`);
    continue;
  }
  const isi = fs.readFileSync(jalur, 'utf8');
  if (!isi.includes('<svg')) lapor(`docs/erd/${nama} tidak berisi SVG`);
  if (isi.length < 5000) lapor(`docs/erd/${nama} terlalu kecil (${isi.length} byte) — mungkin build gagal separuh`);
}

/* ------------------------------------------------------------ kesimpulan */

if (gagal.length) {
  console.error('ERD/LRS tidak konsisten:');
  for (const g of gagal) console.error('  - ' + g);
  process.exit(1);
}

const jumlahKolom = [...kolomSkema].length;
const jumlahFk = skema.tabel.reduce((n, t) => n + t.asing.length, 0);
console.log(
  `ERD/LRS konsisten dengan basis data: ${skema.jumlah_tabel} tabel, ` +
    `${jumlahKolom} kolom, ${jumlahFk} kunci asing.`
);
