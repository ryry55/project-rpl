#!/usr/bin/env node
'use strict';

/**
 * Pembangun ERD berjenjang + LRS — Sistem Informasi Operasional Kos-Kosan
 * -------------------------------------------------------------------------
 * Menghasilkan empat tingkat dari satu sumber (`skema.json`):
 *
 *   L0  erd/00-L0-konseptual.svg          entitas + relasi, tanpa atribut
 *   L1  erd/01-L1-logikal.svg             + atribut kunci
 *   L2  erd/02-L2-fisikal-{a,b,c}.svg     + seluruh kolom dan tipe
 *   L3  erd/03-L3-lrs-{a,b,c}.svg         notasi relasional (LRS)
 *
 * Build GAGAL bila:
 *   - ada kunci asing yang tidak digambar dan tidak tercatat beserta alasannya;
 *   - ada relasi yang digambar tetapi tidak ada di basis data;
 *   - ada simbol yang saling menimpa atau garis yang menembus kotak;
 *   - huruf lebih kecil dari ambang keterbacaan;
 *   - ada tabel basis data yang TIDAK muncul di salah satu tingkat mana pun,
 *     dan tidak tercatat sebagai dikecualikan beserta alasannya.
 *
 * Penjaga terakhir itu yang paling penting. Tanpa ia, "tabel yang lupa
 * digambar" dan "tabel yang sengaja tidak digambar" terlihat persis sama dari
 * luar — dan salah satunya adalah kesalahan.
 */

const fs = require('fs');
const path = require('path');

const S = require('./skema.json');
const { L0, L1, MODUL, lembarFisikal } = require('./tingkat-data');
const { gambar } = require('./chen');
const { gambarPapan, hurufEfektif, HURUF_BAKU } = require('./papan');

const OUT = __dirname;

// Huruf acuan untuk mengukur keterbacaan, dan jumlah kolom yang boleh dicoba.
const HURUF_UJI = HURUF_BAKU.kolom;
const PILIHAN_KOLOM = [2, 3, 4, 5];

const petaTabelSkema = new Map(S.tabel.map((t) => [t.nama, t]));
const masalah = [];

/**
 * Pecah daftar tabel menjadi beberapa lembar sampai tiap lembar terbaca.
 *
 * KENAPA OTOMATIS, BUKAN DITENTUKAN TANGAN
 *
 * Luas teks total tetap: 201 kolom tidak berubah berapa pun pembagiannya.
 * Akibatnya ukuran huruf efektif hampir invarian terhadap tata letak — setiap
 * percobaan tangan mendarat di 1,1-1,5% dan gagal di ambang yang sama.
 * Satu-satunya tuas yang benar-benar mengubahnya adalah JUMLAH LEMBAR.
 *
 * Karena itu jumlah lembar tidak ditebak: tiap lembar diuji, dan bila hurufnya
 * di bawah ambang, lembar itu dibelah dua lalu diuji lagi. Yang menentukan
 * batasnya adalah pengukuran, bukan selera penulis.
 *
 * Jumlah kolom juga ikut diuji (2-5), karena ia menentukan bentuk lembar:
 * lembar tinggi-sempit dibatasi TINGGI halaman, lembar lebar-pendek dibatasi
 * LEBAR. Untuk satu lembar yang sama, 3 kolom bisa lulus sementara 2 kolom
 * gagal - dan sebaliknya.
 *
 * Fungsi ini ada di sini, bukan di `tingkat-data.js`, karena ia memanggil
 * `gambarPapan` — menaruhnya di berkas data akan membuat impor melingkar.
 */
function ukurTerbaca(tabel, mode, pilihanKolom) {
  let terbaik = null;
  for (const kolom of pilihanKolom) {
    const uji = gambarPapan({ judul: 'uji', sub: 'uji', mode, jumlahKolom: kolom, tabel, catatan: '' });
    const ef = hurufEfektif(HURUF_UJI, uji.kanvas);
    const hanyaKeterbacaan = uji.masalah.filter((m) => m.includes('huruf efektif'));
    const lulus = hanyaKeterbacaan.length === 0;
    if (lulus && (terbaik === null || ef > terbaik.ef)) {
      terbaik = { kolom, ef, kanvas: uji.kanvas };
    }
  }
  return terbaik;
}

function pecahTerbaca(tabel, mode, pilihanKolom, kedalaman = 0) {
  if (tabel.length <= 2 || kedalaman > 5) return [{ tabel, kolom: pilihanKolom[0] }];

  const hasil = ukurTerbaca(tabel, mode, pilihanKolom);
  if (hasil) return [{ tabel, kolom: hasil.kolom }];

  // Belah pada titik yang paling sedikit memutus hubungan, bukan di tengah.
  //
  // Membelah di tengah membagi daftar menurut URUTAN PENULISAN, bukan menurut
  // keterhubungan - dan hasilnya lembar berisi 3 tabel tanpa satu pun relasi.
  // Fungsi ini memilih titik potong yang memutus kunci asing paling sedikit.
  //
  // Bila ada beberapa titik dengan jumlah putus yang sama, dipilih yang paling
  // TIDAK seimbang. Alasannya: rantai seperti kontrak -> tagihan -> pembayaran
  // -> verifikasi paling baik dipotong di ujungnya, sehingga satu lembar berisi
  // rantai utuh dan sisanya sedikit - bukan dua lembar yang keduanya terpotong
  // dan kehilangan garis penghubungnya.
  let titikTerbaik = Math.ceil(tabel.length / 2);
  let putusTerbaik = Infinity;
  let timpangTerbaik = 0;

  for (let i = 2; i <= tabel.length - 2; i++) {
    const kiri = new Set(tabel.slice(0, i));
    const kanan = new Set(tabel.slice(i));
    let putus = 0;
    for (const nama of tabel) {
      const t = petaTabelSkema.get(nama);
      if (!t) continue;
      for (const a of t.asing) {
        if (a.ke_tabel === nama) continue;
        const asalKiri = kiri.has(nama);
        const tujuanKiri = kiri.has(a.ke_tabel);
        if (asalKiri !== tujuanKiri && (kanan.has(a.ke_tabel) || kanan.has(nama))) putus++;
      }
    }
    const timpang = Math.abs(i - (tabel.length - i));
    if (putus < putusTerbaik || (putus === putusTerbaik && timpang > timpangTerbaik)) {
      putusTerbaik = putus;
      titikTerbaik = i;
      timpangTerbaik = timpang;
    }
  }

  return [
    ...pecahTerbaca(tabel.slice(0, titikTerbaik), mode, pilihanKolom, kedalaman + 1),
    ...pecahTerbaca(tabel.slice(titikTerbaik), mode, pilihanKolom, kedalaman + 1),
  ];
}

function tulis(berkas, svg, ringkas) {
  fs.writeFileSync(path.join(OUT, berkas), svg, 'utf8');
  const kb = (svg.length / 1024).toFixed(1);
  console.log(`  ${berkas.padEnd(28)} ${ringkas.padEnd(34)} ${kb} KB`);
}

/* ------------------------------------------------ 1. tingkat 0 dan 1 (Chen) */

console.log('Tingkat konseptual dan logikal (notasi Chen):');

for (const [berkas, definisi] of [
  ['00-L0-konseptual.svg', L0],
  ['01-L1-logikal.svg', L1],
]) {
  definisi.kanvas = { ...definisi.kanvas, auto: true };
  const { svg, masalah: m, jumlahRelasi, nisbah } = gambar(definisi);
  for (const x of m) masalah.push(`${berkas}: ${x}`);
  tulis(
    berkas,
    svg,
    `${definisi.entitas.length} entitas, ${jumlahRelasi} relasi, huruf ${(nisbah * 100).toFixed(2)}%`
  );
}

/* ------------------------------------------- 2. tingkat 2 dan 3 (papan tabel) */

console.log('');
console.log('Tingkat fisikal (seluruh kolom):');

// Tiap modul dipecah lagi bila hurufnya di bawah ambang. Kode lembar mengikuti
// hasil pemecahan: a, a1, a2, b, ... Jumlah kolom tiap lembar ikut hasil ukur.
const lembarL2 = MODUL.flatMap((m) => {
  const potongan = pecahTerbaca(m.tabel, 'fisikal', PILIHAN_KOLOM);
  return potongan.map(({ tabel, kolom }, i) => ({
    ...lembarFisikal(m),
    kode: potongan.length > 1 ? `L2-${m.kode}${i + 1}` : `L2-${m.kode}`,
    berkas: `02-L2-fisikal-${m.kode}${potongan.length > 1 ? i + 1 : ''}.svg`,
    judul:
      potongan.length > 1
        ? `ERD Level 2 — Fisikal (${m.kode.toUpperCase()}${i + 1})`
        : `ERD Level 2 — Fisikal (${m.kode.toUpperCase()})`,
    sub: `${m.nama}${potongan.length > 1 ? ` · bagian ${i + 1} dari ${potongan.length}` : ''} · ${tabel.length} tabel · seluruh kolom dan tipenya`,
    jumlahKolom: kolom,
    tabel,
  }));
});

for (const definisi of lembarL2) {
  const { svg, masalah: m, kanvas, jumlahRelasi, jumlahTabel, nisbah } = gambarPapan(definisi);
  for (const x of m) masalah.push(`${definisi.berkas}: ${x}`);
  tulis(
    definisi.berkas,
    svg,
    `${jumlahTabel} tabel, ${jumlahRelasi} relasi, ${kanvas.w}x${kanvas.h}, huruf ${(nisbah * 100).toFixed(2)}%`
  );
}

console.log('');
console.log('Tingkat LRS (notasi relasional, kolom kunci):');

// LRS SENGAJA SATU LEMBAR berisi seluruh 23 tabel, mengikuti bentuk laporan
// referensi. Bisa begitu karena yang digambar hanya kolom kunci (PK + FK) —
// 49 kolom, bukan 201. Diukur: 1,38% lebar A4 pada 3 kolom, di atas ambang
// 1,2%. Dengan seluruh kolom, satu lembar hanya mencapai 0,97% dan TIDAK
// terbaca, jadi bentuk itulah yang dipakai. Atribut lengkap tetap ada di
// tingkat 2 dan di 03-lrs.md.
const semuaTabelLrs = MODUL.flatMap((m) => m.tabel);

const lembarL3 = [
  {
    kode: 'L3',
    berkas: '03-L3-lrs.svg',
    judul: 'LRS — Logical Record Structure',
    sub: `${semuaTabelLrs.length} tabel · hanya kolom kunci · kunci primer digaris bawahi · kunci asing bertanda bintang`,
    mode: 'lrs-kunci',
    jumlahKolom: 3,
    tabel: semuaTabelLrs,
    catatan:
      'Bentuk LRS: hanya kolom kunci yang digambar (49 dari 201 kolom). Atribut lengkap ada di tingkat 2 dan di 03-lrs.md.',
  },
];

for (const definisi of lembarL3) {
  const { svg, masalah: m, kanvas, jumlahRelasi, jumlahTabel, nisbah } = gambarPapan(definisi);
  for (const x of m) masalah.push(`${definisi.berkas}: ${x}`);
  tulis(
    definisi.berkas,
    svg,
    `${jumlahTabel} tabel, ${jumlahRelasi} relasi, ${kanvas.w}x${kanvas.h}, huruf ${(nisbah * 100).toFixed(2)}%`
  );
}

/* -------------------------------------- 3. penjaga kelengkapan seluruh tingkat */

console.log('');
console.log('Pemeriksaan kelengkapan:');

const semuaTabel = new Set(S.tabel.map((t) => t.nama));

// --- 3a. tingkat 2 dan 3 harus memuat SELURUH tabel basis data ---
const diL2 = new Set(lembarL2.flatMap((d) => d.tabel));
const diL3 = new Set(lembarL3.flatMap((d) => d.tabel));

for (const t of semuaTabel) {
  if (!diL2.has(t)) masalah.push(`tabel "${t}" tidak muncul di tingkat 2 (fisikal)`);
  if (!diL3.has(t)) masalah.push(`tabel "${t}" tidak muncul di tingkat 3 (LRS)`);
}
for (const t of diL2) {
  if (!semuaTabel.has(t)) masalah.push(`tingkat 2 menyebut tabel "${t}" yang tidak ada di basis data`);
}
for (const t of diL3) {
  if (!semuaTabel.has(t)) masalah.push(`tingkat 3 menyebut tabel "${t}" yang tidak ada di basis data`);
}

// Tabel yang muncul dua kali akan tergambar dua kali.
for (const [nama, daftar] of [
  ['tingkat 2', lembarL2],
  ['tingkat 3', lembarL3],
]) {
  const hitung = new Map();
  for (const d of daftar) {
    for (const t of d.tabel) hitung.set(t, (hitung.get(t) || 0) + 1);
  }
  for (const [t, n] of hitung) {
    if (n > 1) masalah.push(`tabel "${t}" muncul ${n} kali di ${nama} — akan tergambar dua kali`);
  }
}

// --- 3b. tingkat 0 dan 1: yang tidak digambar harus tercatat ---
for (const [nama, definisi] of [
  ['L0', L0],
  ['L1', L1],
]) {
  const digambar = new Set(definisi.entitas.map((e) => e.nama));
  const dikecualikan = new Set(definisi.dikecualikan || []);

  for (const t of semuaTabel) {
    if (digambar.has(t) || dikecualikan.has(t)) continue;
    masalah.push(
      `tabel "${t}" tidak digambar di ${nama} dan tidak terdaftar di \`dikecualikan\` — ` +
        `lupa digambar atau sengaja, keduanya harus dinyatakan`
    );
  }
  for (const t of digambar) {
    if (!semuaTabel.has(t)) masalah.push(`${nama} menggambar entitas "${t}" yang tidak ada di basis data`);
  }
  for (const t of dikecualikan) {
    if (digambar.has(t)) masalah.push(`${nama} mengecualikan "${t}", tetapi entitasnya tetap digambar`);
    if (!semuaTabel.has(t)) masalah.push(`${nama} mengecualikan "${t}" yang tidak ada di basis data`);
  }
}

// --- 3c. tidak ada tabel yang hilang dari SELURUH tingkat ---
//
// Ini penjaga inti dari berkas ini. Tabel yang lolos dari L0, L1, dan L2
// berarti dokumennya tidak lengkap - dan kelengkapan itu persis yang ditanyakan
// penguji.
const tampilDiMana = new Map();
for (const d of [L0, L1]) {
  for (const e of d.entitas) {
    if (!tampilDiMana.has(e.nama)) tampilDiMana.set(e.nama, []);
    tampilDiMana.get(e.nama).push(d === L0 ? 'L0' : 'L1');
  }
}
for (const t of diL2) {
  if (!tampilDiMana.has(t)) tampilDiMana.set(t, []);
  tampilDiMana.get(t).push('L2');
}

for (const t of semuaTabel) {
  if (!tampilDiMana.has(t)) {
    masalah.push(`tabel "${t}" tidak muncul di tingkat mana pun — dokumennya tidak lengkap`);
  }
}

/* ------------------------------------------------------------ kesimpulan */

console.log('');
if (masalah.length) {
  console.error(`GAGAL — ${masalah.length} masalah:`);
  for (const m of masalah) console.error('  -', m);
  process.exit(1);
}

/**
 * Manifest hasil build.
 *
 * Ditulis hanya setelah build LULUS, dan sengaja memuat daftar berkas beserta
 * isinya. Tujuannya supaya `docs/uji/cek-erd-lrs.js` tidak perlu mematok nama
 * berkas: nama lembar tingkat 2 dan 3 ditentukan oleh pemecahan otomatis, jadi
 * daftar yang diketik di pemeriksa akan basi setiap kali pemecahan berubah —
 * dan daftar yang basi membuat pemeriksa memeriksa berkas yang tidak ada.
 */
const manifest = {
  dibuat: new Date().toISOString(),
  tabel: S.jumlah_tabel,
  kolom: S.tabel.reduce((n, t) => n + t.kolom.length, 0),
  kunci_asing: S.tabel.reduce((n, t) => n + t.asing.length, 0),
  tingkat: [
    { tingkat: 0, nama: 'konseptual', berkas: ['00-L0-konseptual.svg'], entitas: L0.entitas.length },
    { tingkat: 1, nama: 'logikal', berkas: ['01-L1-logikal.svg'], entitas: L1.entitas.length },
    {
      tingkat: 2,
      nama: 'fisikal',
      berkas: lembarL2.map((d) => d.berkas),
      tabel: lembarL2.flatMap((d) => d.tabel).sort(),
    },
    {
      tingkat: 3,
      nama: 'lrs',
      berkas: lembarL3.map((d) => d.berkas),
      tabel: lembarL3.flatMap((d) => d.tabel).sort(),
    },
  ],
};
fs.writeFileSync(path.join(OUT, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n', 'utf8');

const jumlahKolom = S.tabel.reduce((n, t) => n + t.kolom.length, 0);
const jumlahFk = S.tabel.reduce((n, t) => n + t.asing.length, 0);
console.log('LULUS — seluruh tingkat konsisten dengan basis data.');
console.log(`  ${S.jumlah_tabel} tabel · ${jumlahKolom} kolom · ${jumlahFk} kunci asing`);
console.log(`  L0 ${L0.entitas.length} entitas · L1 ${L1.entitas.length} entitas`);
console.log(`  L2 ${lembarL2.length} lembar · L3 ${lembarL3.length} lembar`);
