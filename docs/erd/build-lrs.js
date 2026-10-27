#!/usr/bin/env node
'use strict';

/**
 * Generator LRS — Sistem Informasi Operasional Kos-Kosan
 * -------------------------------------------------------------------------
 * LRS (Logical Record Structure) adalah bentuk logis basis data: tabel apa
 * saja yang ada, kolom apa saja isinya, mana kunci primer, dan ke mana kunci
 * asing menunjuk. Ia menjawab pertanyaan yang tidak dijawab ERD: "kolomnya
 * apa saja" — karena ERD Chen hanya menggambar kunci.
 *
 * Sumber:
 *     skema.json   — diambil dari basis data yang berjalan
 *     lrs-data.js  — makna tabel/kolom + urutan penyajian
 *
 * Menghasilkan:
 *     03-lrs.md    — dokumen LRS lengkap
 *
 * Build GAGAL bila:
 *   - ada tabel di basis data yang tidak punya keterangan;
 *   - ada keterangan yang menyebut tabel/kolom yang sudah tidak ada;
 *   - ada kolom yang tidak muncul di dokumen (penjaga kelengkapan).
 *
 * Jalankan: node docs/erd/build-lrs.js
 */

const fs = require('fs');
const path = require('path');

const S = require('./skema.json');
const { KETERANGAN_TABEL, KETERANGAN_KOLOM, URUTAN_TABEL } = require('./lrs-data');

const OUT = path.join(__dirname, '03-lrs.md');

/* ------------------------------------------------------------- pemeriksa */

const masalah = [];
const petaTabel = new Map(S.tabel.map((t) => [t.nama, t]));

// Setiap tabel WAJIB punya keterangan. Tanpa ini, tabel baru akan muncul di
// dokumen tanpa penjelasan apa pun dan tidak ada yang menyadarinya.
for (const t of S.tabel) {
  if (!KETERANGAN_TABEL[t.nama]) {
    masalah.push(`tabel "${t.nama}" belum diberi keterangan di lrs-data.js`);
  }
}

// Keterangan yang menyebut tabel/kolom yang tidak ada akan menghasilkan
// dokumen yang menjelaskan sesuatu yang sudah dihapus.
for (const kunci of Object.keys(KETERANGAN_KOLOM)) {
  const [tabel, kolom] = kunci.split('.');
  const t = petaTabel.get(tabel);
  if (!t) {
    masalah.push(`keterangan kolom "${kunci}" menyebut tabel "${tabel}" yang tidak ada`);
  } else if (!t.kolom.some((k) => k.nama === kolom)) {
    masalah.push(`keterangan kolom "${kunci}" menyebut kolom "${kolom}" yang tidak ada di tabel "${tabel}"`);
  }
}

if (masalah.length) {
  console.error('LRS tidak dibangun:');
  for (const m of masalah) console.error(' -', m);
  process.exit(1);
}

/* --------------------------------------------------------------- utilitas */

/** Tipe kolom dalam bentuk yang enak dibaca manusia. */
function tipeRapi(k) {
  if (k.tipe === 'character varying') return k.panjang ? `varchar(${k.panjang})` : 'varchar';
  if (k.tipe === 'numeric') {
    return k.presisi ? `numeric(${k.presisi},${k.skala ?? 0})` : 'numeric';
  }
  if (k.tipe === 'timestamp without time zone') return 'timestamp';
  if (k.tipe === 'timestamp with time zone') return 'timestamptz';
  if (k.tipe === 'double precision') return 'double';
  return k.tipe;
}

/** Tabel diurutkan sesuai URUTAN_TABEL, sisanya di belakang urut abjad. */
const urutanAkhir = [
  ...URUTAN_TABEL.filter((n) => petaTabel.has(n)),
  ...S.tabel.map((t) => t.nama).filter((n) => !URUTAN_TABEL.includes(n)).sort(),
];

/** Nomor untuk daftar bernomor markdown. */
const daftar = (baris) => baris.map((b, i) => `${i + 1}. ${b}`).join('\n');

/**
 * Satu tabel dalam notasi relasional formal.
 * Kunci primer digaris bawahi, kunci asing ditandai bintang.
 */
function notasiFormal(t) {
  const fk = new Set(t.asing.map((a) => a.kolom));
  const bagian = t.kolom.map((k) => {
    const nama = k.nama;
    let teks = nama;
    if (t.kunci_primer.includes(nama)) teks = `<u>${teks}</u>`;
    if (fk.has(nama) && !t.kunci_primer.includes(nama)) teks = `${teks}*`;
    return teks;
  });
  return `${t.nama} = (${bagian.join(', ')})`;
}

/* ------------------------------------------------------------------ isi */

const baris = [];

baris.push('# 3. LRS — Logical Record Structure');
baris.push('');
baris.push('> Dokumen ini menjawab: **bentuk logis data yang sebenarnya** — tabel apa');
baris.push('> saja yang ada, kolom apa saja isinya, mana kunci primer, dan ke mana kunci');
baris.push('> asing menunjuk.');
baris.push('>');
baris.push('> Seluruh isi di bawah dihasilkan dari basis data yang berjalan');
baris.push('> (`information_schema`, `pg_constraint`, `pg_indexes`), bukan diketik. Kalau');
baris.push('> dokumen ini berbeda dengan migrasi, migrasi yang benar — dan dokumen ini');
baris.push('> yang harus dibangun ulang dengan `node docs/erd/build-lrs.js`.');
baris.push('');
baris.push(`Versi 1.0 · ${S.jumlah_tabel} tabel · ${S.tabel.reduce((n, t) => n + t.kolom.length, 0)} kolom · PostgreSQL 16`);
baris.push('');

/* --- 1. hubungan dengan ERD --- */
baris.push('## 1. Hubungan dengan ERD');
baris.push('');
baris.push('ERD dan LRS bukan dua dokumen yang saling menggantikan, melainkan dua');
baris.push('potret dari basis data yang sama:');
baris.push('');
baris.push('| | ERD (notasi Chen) | LRS (dokumen ini) |');
baris.push('|---|---|---|');
baris.push('| Menjawab | entitas apa saja, dan bagaimana hubungannya | kolom apa saja, dan mana kuncinya |');
baris.push('| Menggambar | kotak, belah ketupat, elips | daftar kolom beserta tipe dan kunci |');
baris.push('| Kunci asing | diwakili belah ketupat, kolomnya tidak digambar | ditulis lengkap dengan tanda bintang |');
baris.push('| Dipakai untuk | menjelaskan alur data saat sidang | membuktikan bahwa data bisa disimpan tanpa duplikasi |');
baris.push('');
baris.push('Keduanya dibangun dari satu berkas yang sama, `skema.json`, sehingga mustahil');
baris.push('menyebut tabel atau relasi yang berbeda satu sama lain.');
baris.push('');

/* --- 2. notasi --- */
baris.push('## 2. Notasi');
baris.push('');
baris.push('Pada daftar di §3, setiap tabel ditulis dalam bentuk relasional formal:');
baris.push('');
baris.push('```');
baris.push('nama_tabel = (kolom_pk, kolom_biasa, kolom_fk*)');
baris.push('```');
baris.push('');
baris.push('- **<u>digaris bawahi</u>** — kunci primer (primary key): penanda unik satu baris;');
baris.push('- **\\*** — kunci asing (foreign key): nilainya harus ada di tabel lain;');
baris.push('- tanpa tanda — kolom biasa.');
baris.push('');
baris.push('Pada rincian di §4, setiap kolom memuat tipe, status null, dan kuncinya.');
baris.push('');

/* --- 3. daftar LRS --- */
baris.push('## 3. Daftar LRS');
baris.push('');
baris.push('Notasi formal seluruh tabel, dalam urutan yang mengikuti alur pemakaian:');
baris.push('dari akun, ke kamar, ke uang, lalu ke jejak dan pengaturan.');
baris.push('');
baris.push('```');
for (const nama of urutanAkhir) baris.push(notasiFormal(petaTabel.get(nama)));
baris.push('```');
baris.push('');

/* --- 4. rincian per tabel --- */
baris.push('## 4. Rincian Tabel');
baris.push('');
baris.push('Setiap tabel diuraikan lengkap: tipe kolom, boleh kosong atau tidak, dan');
baris.push('kuncinya. Keterangan kolom hanya ditulis bila namanya tidak menjelaskan');
baris.push('dirinya sendiri — kolom yang jelas tidak diberi keterangan berulang.');
baris.push('');

urutanAkhir.forEach((nama, i) => {
  const t = petaTabel.get(nama);
  const fk = new Map(t.asing.map((a) => [a.kolom, a]));
  const unikTunggal = new Set(t.unik.filter((u) => u.kolom.length === 1).map((u) => u.kolom[0]));

  baris.push(`### 4.${i + 1} ${t.nama}`);
  baris.push('');
  baris.push(KETERANGAN_TABEL[t.nama]);
  baris.push('');
  baris.push('| Kolom | Tipe | Null | Kunci | Keterangan |');
  baris.push('|---|---|---|---|---|');

  for (const k of t.kolom) {
    let kunci = [];
    if (t.kunci_primer.includes(k.nama)) kunci.push('PK');
    if (fk.has(k.nama)) kunci.push('FK');
    if (unikTunggal.has(k.nama) && !t.kunci_primer.includes(k.nama)) kunci.push('UQ');

    let ket = KETERANGAN_KOLOM[`${nama}.${k.nama}`] || '';
    if (fk.has(k.nama)) {
      const a = fk.get(k.nama);
      const menunjuk = `→ \`${a.ke_tabel}.${a.ke_kolom}\``;
      ket = ket ? `${ket} · ${menunjuk}` : menunjuk;
    }
    // Karakter pipa pada keterangan akan merusak tabel markdown.
    ket = ket.replace(/\|/g, '\\|');

    baris.push(
      `| \`${k.nama}\` | ${tipeRapi(k)} | ${k.boleh_null ? 'boleh' : 'wajib'} | ${kunci.join(' ') || '—'} | ${ket} |`
    );
  }
  baris.push('');

  // Kunci unik gabungan: tidak kelihatan sebagai kolom, padahal penting.
  const unikGabungan = t.unik.filter((u) => u.kolom.length > 1);
  if (unikGabungan.length) {
    baris.push('Kunci unik gabungan:');
    baris.push('');
    for (const u of unikGabungan) {
      baris.push(`- (${u.kolom.map((c) => `\`${c}\``).join(', ')}) — kombinasi ini tidak boleh berulang`);
    }
    baris.push('');
  }

  // Indeks unik parsial: aturan bisnis yang hanya bisa ditegakkan basis data.
  if (t.unik_parsial.length) {
    baris.push('Indeks unik parsial — aturan bisnis yang ditegakkan basis data:');
    baris.push('');
    for (const p of t.unik_parsial) {
      baris.push(`- \`${p.nama}\`: ${p.definisi}`);
    }
    baris.push('');
  }
});

/* --- 5. relasi --- */
baris.push('## 5. Relasi Antar Tabel');
baris.push('');
baris.push('Seluruh 26 kunci asing di basis data, dikelompokkan menurut tabel asalnya.');
baris.push('Arah panah menunjukkan arah rujukan: kolom di kiri harus ada di tabel kanan.');
baris.push('');
baris.push('| # | Dari | Kolom | Ke | Wajib | Arti kardinalitas |');
baris.push('|---|---|---|---|---|---|');

let no = 0;
for (const nama of urutanAkhir) {
  const t = petaTabel.get(nama);
  for (const a of t.asing) {
    no++;
    const kol = t.kolom.find((k) => k.nama === a.kolom);
    const unik = t.unik.some((u) => u.kolom.length === 1 && u.kolom[0] === a.kolom);
    const diriSendiri = a.ke_tabel === nama;
    let kard;
    if (diriSendiri) {
      // "satu X hanya punya satu X" tidak bermakna. Yang benar: kolom ini
      // menunjuk baris lain pada tabel yang sama, dan sifatnya ditentukan
      // oleh ada tidaknya indeks unik.
      kard = unik
        ? `relasi diri: satu ${nama} menunjuk tepat satu ${nama} lain`
        : `relasi diri: satu ${nama} boleh dirujuk banyak ${nama} lain`;
    } else {
      kard = unik
        ? `satu ${a.ke_tabel} hanya punya satu ${nama}`
        : `satu ${a.ke_tabel} boleh punya banyak ${nama}`;
    }
    baris.push(
      `| ${no} | \`${nama}\` | \`${a.kolom}\` | \`${a.ke_tabel}.${a.ke_kolom}\` | ${kol && !kol.boleh_null ? 'wajib' : 'boleh kosong'} | ${kard} |`
    );
  }
}
baris.push('');
baris.push(`Jumlah kunci asing: **${no}**.`);
baris.push('');

/* --- 6. tabel tanpa relasi --- */
const berdiriSendiri = S.tabel
  .map((t) => t.nama)
  .filter((nama) => {
    const keluar = petaTabel.get(nama).asing.length;
    const masuk = S.tabel.some((lain) => lain.asing.some((a) => a.ke_tabel === nama));
    return keluar === 0 && !masuk;
  })
  .sort();

baris.push('## 6. Tabel Tanpa Relasi');
baris.push('');
if (berdiriSendiri.length) {
  baris.push('Tabel berikut tidak punya kunci asing keluar maupun masuk. Ini bukan');
  baris.push('kelalaian: isinya memang tidak terikat pada baris tabel lain.');
  baris.push('');
  for (const nama of berdiriSendiri) {
    baris.push(`- \`${nama}\` — ${KETERANGAN_TABEL[nama]}`);
  }
} else {
  baris.push('Tidak ada tabel yang berdiri sendiri; seluruh tabel terhubung.');
}
baris.push('');

/* --- 7. apa yang tidak terlihat di kolom --- */
baris.push('## 7. Aturan yang Tidak Terlihat dari Daftar Kolom');
baris.push('');
baris.push('Sebagian aturan penting tidak berbentuk kolom, sehingga tidak akan terlihat');
baris.push('di §3 dan §4. Aturan-aturan itu ditegakkan basis data, bukan aplikasi:');
baris.push('');

const aturanParsial = [];
for (const t of S.tabel) {
  for (const p of t.unik_parsial) {
    aturanParsial.push(`| \`${p.nama}\` | indeks unik parsial pada \`${t.nama}\` |`);
  }
}

if (aturanParsial.length) {
  baris.push('### 7.1 Indeks unik parsial');
  baris.push('');
  baris.push('Keunikan yang hanya berlaku untuk sebagian baris — inilah yang mencegah');
  baris.push('satu kontrak punya dua tagihan hidup pada periode yang sama (R-02):');
  baris.push('');
  baris.push('| Nama indeks | Letak |');
  baris.push('|---|---|');
  baris.push(...aturanParsial);
  baris.push('');
  baris.push('Definisi lengkapnya ada pada rincian tiap tabel di §4.');
  baris.push('');
}

// Tabel yang menolak UPDATE dan DELETE. Daftarnya dibaca dari `pg_trigger`,
// bukan diketik: kalau suatu saat `tagihan` ikut dijaga trigger, dokumen ini
// akan menyebutnya tanpa ada yang perlu ingat memperbarui.
const terjaga = S.tabel
  .filter((t) => t.trigger.length > 0)
  .map((t) => t.nama)
  .sort();

if (terjaga.length) {
  const jumlahTrigger = S.tabel.reduce((n, t) => n + t.trigger.length, 0);
  baris.push('### 7.2 Tabel yang hanya boleh ditambah (append-only)');
  baris.push('');
  baris.push(
    `Terdapat **${terjaga.length} tabel** yang menolak \`UPDATE\` dan \`DELETE\` lewat trigger basis data: ` +
      terjaga.map((n) => `\`${n}\``).join(', ') +
      '.'
  );
  baris.push('');
  if (jumlahTrigger > terjaga.length) {
    baris.push(
      `Jumlah trigger **${jumlahTrigger}**, lebih banyak daripada jumlah tabelnya, karena ` +
        'satu tabel bisa punya lebih dari satu penjaga.'
    );
    baris.push('');
  }
  baris.push('Baris pada tabel-tabel itu hanya bisa ditambah, sehingga riwayat tarif dan');
  baris.push('pembayaran tidak bisa ditimpa diam-diam. Koreksi dilakukan dengan menambah');
  baris.push('baris baru, bukan mengubah yang lama.');
  baris.push('');
}

// `tagihan` sengaja dibahas terpisah karena justru TIDAK boleh append-only:
// statusnya harus bisa berubah. Ini mudah disalahpahami sebagai kelalaian.
if (!terjaga.includes('tagihan')) {
  baris.push(
    '`tagihan` **tidak** termasuk daftar itu: statusnya memang harus bisa berubah ' +
      'dari `belum_bayar` menjadi `lunas`. Pembatalan pun hanya mengubah status, tidak ' +
      'pernah menghapus baris.'
  );
  baris.push('');
}

/* --- 8. cara membuktikan --- */
baris.push('## 8. Cara Membuktikan Dokumen Ini Benar');
baris.push('');
baris.push('```bash');
baris.push('node docs/erd/ambil-skema.js      # ambil ulang skema dari basis data');
baris.push('node docs/erd/build-lrs.js        # bangun ulang dokumen ini');
baris.push('node docs/erd/build-tingkat.js    # bangun ulang ERD berjenjang dari skema yang sama');
baris.push('```');
baris.push('');
baris.push('Ketiga perintah gagal dengan kode keluar bukan nol bila ada yang tidak');
baris.push('konsisten, sehingga dokumen tidak bisa diam-diam menyimpang dari basis data.');
baris.push('');

fs.writeFileSync(OUT, baris.join('\n'), 'utf8');

console.log(`ditulis: ${path.relative(path.join(__dirname, '..', '..'), OUT)}`);
console.log(`  ${urutanAkhir.length} tabel, ${S.tabel.reduce((n, t) => n + t.kolom.length, 0)} kolom, ${no} kunci asing`);
