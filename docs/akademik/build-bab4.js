#!/usr/bin/env node
'use strict';

/**
 * Membangun dokumen Bab 4 — Implementasi dan Pengujian.
 *
 * Susunannya mengikuti laporan referensi mata kuliah Sistem Basis Data:
 * normalisasi NF0 → 1NF → 2NF → 3NF, lalu ERD, lalu LRS, lalu spesifikasi
 * data, lalu pendukung. Ini berbeda dari dokumen akademik lain di repo yang
 * memakai template tujuh bab; keduanya dipertahankan karena keduanya diminta.
 *
 * Angka TIDAK diketik. Jumlah tabel, kolom, dan kunci asing dibaca dari
 * `docs/erd/skema.json`, sehingga dokumen ini tidak bisa menyimpang dari
 * basis data tanpa ketahuan.
 *
 * Pakai: node docs/akademik/build-bab4.js
 */

const fs = require('fs');
const path = require('path');
const {
  NORMALISASI,
  TABEL_AKHIR,
  GAMBAR,
  SPESIFIKASI,
  PERANGKAT,
} = require('./bab4-data');

const AKAR = path.resolve(__dirname, '..', '..');
const SKEMA = JSON.parse(
  fs.readFileSync(path.join(AKAR, 'docs', 'erd', 'skema.json'), 'utf8'),
);
const KELUARAN = path.join(__dirname, 'Bab_4_Implementasi_dan_Pengujian.md');

const tabelSkema = new Set(SKEMA.tabel.map((t) => t.nama));
const jumlahKolom = SKEMA.tabel.reduce((n, t) => n + t.kolom.length, 0);
const jumlahFk = SKEMA.tabel.reduce((n, t) => n + t.asing.length, 0);

const galat = [];

/* ------------------------------------------------------------ pemeriksaan */

// Setiap tabel yang disebut di dokumen harus benar-benar ada di basis data.
// Tanpa pemeriksaan ini, dokumen bisa menjelaskan tabel yang sudah dihapus.
const disebut = new Set();
for (const kelompok of TABEL_AKHIR) {
  for (const nama of kelompok.tabel) disebut.add(nama);
}
for (const s of SPESIFIKASI) {
  const nama = s.tabel.replace(/^t_/, '');
  disebut.add(nama);
}
for (const nama of disebut) {
  if (!tabelSkema.has(nama)) {
    galat.push(`dokumen menyebut tabel "${nama}" yang tidak ada di skema.json`);
  }
}

// Seluruh 24 tabel harus terbagi habis ke dalam kelompok; tabel yang lupa
// dikelompokkan akan hilang dari dokumen tanpa ada yang menyadarinya.
const semuaDisebut = new Set();
for (const kelompok of TABEL_AKHIR) {
  for (const nama of kelompok.tabel) {
    if (semuaDisebut.has(nama)) galat.push(`tabel "${nama}" muncul di dua kelompok`);
    semuaDisebut.add(nama);
  }
}
for (const t of SKEMA.tabel) {
  if (!semuaDisebut.has(t.nama)) {
    galat.push(`tabel "${t.nama}" tidak masuk kelompok mana pun di TABEL_AKHIR`);
  }
}

// Setiap kolom pada tabel spesifikasi harus ada di tabelnya, dan sebaliknya
// setiap baris contoh harus sepanjang kepala kolomnya.
for (const s of SPESIFIKASI) {
  const nama = s.tabel.replace(/^t_/, '');
  const t = SKEMA.tabel.find((x) => x.nama === nama);
  if (!t) continue;

  const kolomNyata = new Set(t.kolom.map((k) => k.nama));
  for (const k of s.kolom) {
    if (!kolomNyata.has(k)) {
      galat.push(`${s.tabel}: dokumen menyebut kolom "${k}" yang tidak ada`);
    }
  }
  s.baris.forEach((b, i) => {
    if (b.length !== s.kolom.length) {
      galat.push(
        `${s.tabel}: baris contoh ke-${i + 1} punya ${b.length} sel, ` +
          `kepala kolomnya ${s.kolom.length}`,
      );
    }
  });
}

// Gambar harus benar-benar ada; dokumen yang menunjuk berkas hilang akan
// menghasilkan PDF dengan kotak kosong.
for (const g of GAMBAR) {
  const jalur = path.resolve(__dirname, g.berkas);
  if (!fs.existsSync(jalur)) {
    galat.push(`gambar tidak ditemukan: ${g.berkas}`);
  }
}

// Gambar tahap normalisasi dibuat oleh skrip lain. Kalau skrip itu belum
// dijalankan, dokumen akan menunjuk berkas yang tidak ada.
for (const n of NORMALISASI) {
  const nama = `04-${n.nomor}nf.svg`;
  if (!fs.existsSync(path.join(__dirname, nama))) {
    galat.push(
      `gambar tahap ${n.nomor} tidak ada (${nama}) — jalankan ` +
        `"node docs/akademik/build-normalisasi.js" lebih dulu`,
    );
  }
}

if (galat.length) {
  console.error('Bab 4 TIDAK dibangun:');
  for (const g of galat) console.error('  - ' + g);
  process.exit(1);
}

/* -------------------------------------------------------------- penulisan */

const baris = [];

/** Gambar beserta caption.
 *
 * Caption DITULIS sebagai baris miring sendiri, tidak mengandalkan `alt`:
 * pandoc dalam mode gfm tidak menghasilkan <figcaption> untuk gambar seperti
 * ini (sudah diperiksa — HTML-nya hanya berisi <img>), sehingga keterangan
 * gambar hilang sama sekali bila hanya mengandalkan `alt`. Sebelumnya caption
 * tercetak dua kali karena `alt` DAN baris miring dipakai bersamaan; sekarang
 * hanya baris miring.
 *
 * Caption diletakkan SEBELUM gambar, mengikuti laporan referensi.
 */
const tulisGambar = (berkas, keterangan, catatan) => {
  baris.push(`*${keterangan}*`);
  baris.push('');
  baris.push(`![](${berkas})`);
  baris.push('');
  if (catatan) {
    baris.push(`> ${catatan}`);
    baris.push('');
  }
};

/**
 * Gambar yang TIDAK ditempel di badan laporan A4, melainkan diserahkan sebagai
 * lampiran berukuran lain.
 *
 * Dipakai untuk LRS. Seluruh 209 kolom hanya terbaca pada A3 tegak (2,74 mm);
 * bila gambar A3 itu ditempel ke halaman A4, ia diperkecil dan hurufnya jatuh
 * ke 2,03 mm — di bawah ambang 2,52 mm. Menempelkannya di sini justru
 * menghasilkan gambar yang tidak terbaca, padahal lampirannya terbaca.
 *
 * Karena itu yang ditulis di badan laporan adalah keterangan gambarnya beserta
 * ukuran lembar lampirannya, supaya pembaca tahu ke mana harus melihat dan
 * mengapa gambar itu tidak ada di halaman ini.
 */
const tulisLampiran = (keterangan, ukuran, berkas, catatan) => {
  baris.push(`*${keterangan}*`);
  baris.push('');
  baris.push(
    `> **Lembar terpisah ${ukuran}.** Gambar ini diserahkan sebagai lampiran ` +
      `(\`${berkas}\`), bukan ditempel di halaman ini. ` +
      `Alasannya diukur, bukan dipilih: seluruh ${jumlahKolom} kolom hanya mencapai ` +
      `2,74 mm tinggi huruf pada A3 tegak, sedangkan pada A4 yang terbaik hanya ` +
      `2,03 mm — di bawah ambang baca 2,52 mm. Bila lembar A3 diperkecil ke lebar ` +
      `A4, hasilnya persis sama dengan menggambarnya di A4 sejak awal.`,
  );
  baris.push('');
  if (catatan) {
    baris.push(`> ${catatan}`);
    baris.push('');
  }
};

const tabel = (kepala, isi) => {
  baris.push('| ' + kepala.join(' | ') + ' |');
  baris.push('|' + kepala.map(() => '---').join('|') + '|');
  for (const r of isi) baris.push('| ' + r.join(' | ') + ' |');
  baris.push('');
};

baris.push('# BAB IV');
baris.push('');
baris.push('## IMPLEMENTASI DAN PENGUJIAN');
baris.push('');
baris.push('### 4.1. Desain');
baris.push('');
baris.push('#### 4.1.1. Basis Data');
baris.push('');
baris.push(
  `Berikut adalah perancangan basis data menggunakan metode Normalisasi. ` +
    `Hasil akhirnya adalah ${SKEMA.jumlah_tabel} tabel, ${jumlahKolom} kolom, ` +
    `dan ${jumlahFk} kunci asing, yang seluruhnya dipakai aplikasi — bukan ` +
    `rancangan di atas kertas.`,
);
baris.push('');

for (const n of NORMALISASI) {
  baris.push(`${n.nomor}. ${n.judul}`);
  baris.push('');
  baris.push(n.paragraf);
  baris.push('');
  tulisGambar(`04-${n.nomor}nf.svg`, n.gambar);
  baris.push('Catatan perbaikan pada tahap ini:');
  baris.push('');
  for (const m of n.masalah) baris.push(`- ${m}`);
  baris.push('');
}

// ERD dan LRS: penomoran gambar melanjutkan nomor normalisasi, sama seperti
// laporan referensi (4.5 ERD, 4.6 LRS).
baris.push('**Entity Relationship Diagram (ERD)**');
baris.push('');
baris.push(
  'ERD menggambarkan entitas beserta relasinya memakai notasi Chen: persegi ' +
    'panjang untuk entitas, elips untuk atribut, belah ketupat untuk relasi. ' +
    'Yang digambar adalah tingkat logikal — dua belas entitas inti dengan atribut ' +
    'kuncinya. Tabel teknis seperti token aktivitas, jejak audit, dan kotak masuk ' +
    'notifikasi tidak digambar karena tidak menambah pemahaman konsep.',
);
baris.push('');

const gambarErd = GAMBAR.filter((g) => !g.keterangan.includes('LRS'));
const gambarLrs = GAMBAR.filter((g) => g.keterangan.includes('LRS'));

for (const g of gambarErd) tulisGambar(g.berkas, g.keterangan, g.catatan);

baris.push('**Logical Record Structure (LRS)**');
baris.push('');
baris.push(
  'LRS adalah kelanjutan ERD: entitas dan relasinya diubah menjadi struktur ' +
    'tabel dengan kolom, kunci primer, dan kunci asing. Kunci primer digarisbawahi ' +
    'dan kunci asing bertanda bintang.',
);
baris.push('');
baris.push(
  'LRS digambar dalam satu bagan yang memuat seluruh atribut — seluruh ' +
    `${jumlahKolom} kolom dari ${SKEMA.jumlah_tabel} tabel, bukan hanya kolom kunci. ` +
    'Kunci primer digarisbawahi, kunci ' +
    'asing bertanda bintang dan dihubungkan panah ke tabel yang dituju.',
);
baris.push('');
// shortcut: angka 2,74 mm adalah hasil ukur `build-tingkat.js` untuk 209 kolom,
// diketik di sini karena build itu tidak menyimpan hasil ukurnya ke berkas.
// Ukur ulang (jalankan build-tingkat.js) setiap kali jumlah kolom berubah.
baris.push(
  'Bagan itu diserahkan sebagai lampiran A3 terpisah. Ini keputusan yang diambil ' +
    'setelah mengukur seluruh kemungkinan, bukan karena gambar A4 tidak dicoba: ' +
    'pada ambang baca 2,52 mm, varian dua sampai enam kolom di A4 tegak maupun ' +
    'miring semuanya gagal, dan yang terbaik hanya 2,03 mm. Yang menentukan bukan ' +
    'jumlah piksel, melainkan nisbah tinggi huruf terhadap ukuran kertas. Pada A3 ' +
    'tegak dengan empat kolom, hurufnya 2,74 mm — satu-satunya bentuk yang memuat ' +
    'semua atribut tanpa mengorbankan keterbacaan. Atribut dan tipe setiap tabel ' +
    'juga ada di Tabel 4.1 sampai Tabel 4.9.',
);
baris.push('');

for (const g of gambarLrs) tulisLampiran(g.keterangan, 'A3 (297 × 420 mm)', g.berkas, g.catatan);

baris.push(`Seluruh ${SKEMA.jumlah_tabel} tabel hasil normalisasi:`);
baris.push('');
tabel(
  ['Kelompok', 'Tabel'],
  TABEL_AKHIR.map((k) => [k.kelompok, k.tabel.map((t) => '`' + t + '`').join(', ')]),
);

baris.push('#### 4.1.2. Spesifikasi Data');
baris.push('');
baris.push(
  'Berdasarkan analisis masalah di atas, sistem yang dibangun membutuhkan ' +
    'struktur data sebagai berikut. Tabel di bawah memakai data contoh untuk ' +
    'memperlihatkan bentuk isinya; nama kolom dan tipenya diambil langsung dari ' +
    'basis data yang berjalan.',
);
baris.push('');

SPESIFIKASI.forEach((s, i) => {
  baris.push(`${i + 1}. ${s.judul}`);
  baris.push('');
  baris.push(s.paragraf);
  baris.push('');
  tabel(s.kolom, s.baris);
  baris.push(`*Tabel 4.${i + 1} ${s.judul.replace(/^Entitas /, '').replace(/ \(t_\w+\)$/, '')}*`);
  baris.push('');
});

baris.push('### 4.2. Pendukung');
baris.push('');
baris.push('#### 4.2.1. Spesifikasi Hardware dan Software');
baris.push('');
baris.push(
  'Untuk mengimplementasikan rancangan basis data ini, dibutuhkan spesifikasi ' +
    'minimal sebagai berikut:',
);
baris.push('');
baris.push('A. Perangkat Keras (Hardware)');
baris.push('');
for (const k of PERANGKAT.keras) baris.push(`- ${k}`);
baris.push('');
baris.push('B. Perangkat Lunak (Software)');
baris.push('');
for (const l of PERANGKAT.lunak) baris.push(`- ${l}`);
baris.push('');

fs.writeFileSync(KELUARAN, baris.join('\n'), 'utf8');

console.log(`ditulis: docs/akademik/${path.basename(KELUARAN)}`);
console.log(`  ${SKEMA.jumlah_tabel} tabel, ${jumlahKolom} kolom, ${jumlahFk} kunci asing`);
console.log(
  `  ${NORMALISASI.length} tahap normalisasi, ${GAMBAR.length} gambar, ` +
    `${SPESIFIKASI.length} tabel spesifikasi data`,
);
