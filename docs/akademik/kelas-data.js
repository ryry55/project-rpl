'use strict';

/**
 * Model kelas untuk diagram kelas — DITURUNKAN, bukan diketik.
 * ------------------------------------------------------------
 * Sumber kebenarannya dua berkas yang sudah ada dan sudah dijaga:
 *
 *   `docs/erd/skema.json`      potret skema PostgreSQL (kolom, tipe, kunci).
 *   `api/app/Models/*.php`     kelas Eloquent beserta metodenya.
 *
 * Kenapa tidak diketik tangan: diagram kelas yang ditulis dari ingatan akan
 * menyimpang dari basis data pada perubahan pertama, dan penyimpangannya tidak
 * menimbulkan galat di mana pun — ia hanya muncul sebagai gambar yang salah,
 * biasanya saat dosen membandingkannya dengan aplikasi.
 *
 * ATURAN YANG DIJAGA BERKAS INI
 *
 *   1. Setiap kelas harus menunjuk tabel yang benar-benar ada di `skema.json`.
 *      Kelas tanpa tabel = kelas karangan, dan build berhenti.
 *   2. Atribut diambil dari kolom tabel itu. Tidak ada atribut tambahan.
 *   3. Operasi diambil dari metode `public` model, KECUALI metode relasi
 *      (`belongsTo`, `hasMany`, `hasOne`, `belongsToMany`). Relasi sudah
 *      tergambar sebagai garis asosiasi; mencantumkannya lagi sebagai operasi
 *      membuat diagram berbohong dua kali.
 *   4. Asosiasi diambil dari kunci asing di `skema.json`, bukan dari pasangan
 *      metode relasi. Kunci asing adalah fakta basis data; metode relasi adalah
 *      tafsir penulis kode.
 */

const fs = require('fs');
const path = require('path');

const AKAR = path.resolve(__dirname, '..', '..');
const SKEMA = require(path.join(AKAR, 'docs', 'erd', 'skema.json'));
const DIR_MODEL = path.join(AKAR, 'api', 'app', 'Models');

/* ------------------------------------------------------------ kelompok */

/**
 * Pengelompokan lembar. Diagram kelas 22 kotak tidak terbaca dalam satu
 * halaman, jadi ia dipecah tiga menurut pekerjaannya — pola yang sama dengan
 * ERD L2 a/b/c. `kunci` dipakai sebagai nama berkas.
 */
const KELOMPOK = [
  {
    kunci: 'a',
    nama: 'Akun, akses & kepatuhan',
    ket: 'Siapa yang boleh masuk, apa yang boleh ia lakukan, dan persetujuan data pribadinya.',
  },
  {
    kunci: 'b',
    nama: 'Kamar, sewa & kebijakan',
    ket: 'Apa yang disewakan, dengan tarif apa, dan aturan yang mengikatnya.',
  },
  {
    kunci: 'c',
    nama: 'Tagihan, pembayaran & catatan',
    ket: 'Uang yang harus masuk, uang yang sudah masuk, dan jejak siapa mengubah apa.',
  },
];

/** Kelas → kelompok. Setiap model wajib ada di sini; yang terlewat gagal build. */
const ANGGOTA = {
  a: ['User', 'Penghuni', 'PengelolaKewenangan', 'PersetujuanPdp', 'PengaturanNotifikasi', 'TokenAkun'],
  b: ['Kamar', 'Fasilitas', 'TarifSewa', 'KomponenBiaya', 'Kontrak', 'KebijakanKos', 'AturanKos'],
  c: [
    'Tagihan',
    'TagihanRincian',
    'Pembayaran',
    'VerifikasiPembayaran',
    'TransaksiGateway',
    'Rekening',
    'UsulanPerubahan',
    'LaporanKerusakan',
    'LogAudit',
  ],
};

/* ------------------------------------------------------------- baca kode */

const RELASI = /\$this->(belongsToMany|belongsTo|hasMany|hasOne|morphMany|morphOne|morphTo)\s*\(/;

function bacaModel(berkas) {
  const isi = fs.readFileSync(berkas, 'utf8');
  const kelas = (isi.match(/class\s+(\w+)\s+extends/) || [])[1];
  if (!kelas) return null;

  const tabel = (isi.match(/protected\s+\$table\s*=\s*'([^']+)'/) || [])[1] || null;

  // Badan metode dipotong dengan pencocokan kurawal, bukan regex satu baris:
  // metode relasi sering ditulis tiga baris dan isinya menentukan apakah ia
  // relasi atau bukan.
  const operasi = [];
  const pola = /public\s+function\s+(\w+)\s*\([^)]*\)\s*(?::\s*[\w?\\|]+\s*)?\{/g;
  let m;
  while ((m = pola.exec(isi)) !== null) {
    const mulai = pola.lastIndex;
    let dalam = 1;
    let i = mulai;
    while (i < isi.length && dalam > 0) {
      if (isi[i] === '{') dalam += 1;
      else if (isi[i] === '}') dalam -= 1;
      i += 1;
    }
    const badan = isi.slice(mulai, i - 1);
    if (!RELASI.test(badan)) operasi.push(m[1]);
    pola.lastIndex = i;
  }

  return { kelas, berkas: path.basename(berkas), tabel, operasi };
}

const MODEL = fs
  .readdirSync(DIR_MODEL)
  .filter((f) => f.endsWith('.php'))
  .map((f) => bacaModel(path.join(DIR_MODEL, f)))
  .filter(Boolean);

/** Laravel memakai `users` untuk model `User`; selebihnya `$table` eksplisit. */
const tabelKelas = new Map(MODEL.map((m) => [m.kelas, m.tabel || m.kelas.toLowerCase() + 's']));
const kelasTabel = new Map(MODEL.map((m) => [tabelKelas.get(m.kelas), m.kelas]));

/* ---------------------------------------------------------------- tipe */

const TIPE = {
  bigint: 'integer',
  'bigint unsigned': 'integer',
  smallint: 'smallint',
  integer: 'integer',
  'character varying': 'varchar',
  'character': 'char',
  text: 'text',
  boolean: 'boolean',
  date: 'date',
  'timestamp without time zone': 'timestamp',
  'timestamp with time zone': 'timestamptz',
  numeric: 'decimal',
  jsonb: 'jsonb',
  json: 'json',
  uuid: 'uuid',
  bytea: 'bytea',
};

function atribut(kolom, tabel) {
  const tipe = TIPE[kolom.tipe] || kolom.tipe;
  const panjang = kolom.panjang ? `(${kolom.panjang})` : '';
  const kunci = [];
  if (tabel.kunci_primer.includes(kolom.nama)) kunci.push('PK');
  if (tabel.asing.some((a) => a.kolom === kolom.nama)) kunci.push('FK');
  if (tabel.unik.some((u) => u.length === 1 && u[0] === kolom.nama)) kunci.push('UQ');
  return {
    nama: kolom.nama,
    tipe: tipe + panjang,
    kunci: kunci.join(' '),
    bolehNull: kolom.boleh_null,
  };
}

/* --------------------------------------------------------------- susun */

const masalah = [];
const KELAS = [];

for (const [grup, daftar] of Object.entries(ANGGOTA)) {
  for (const nama of daftar) {
    const model = MODEL.find((m) => m.kelas === nama);
    if (!model) {
      masalah.push(`kelas "${nama}" tidak punya berkas di api/app/Models`);
      continue;
    }
    const tabel = SKEMA.tabel.find((t) => t.nama === tabelKelas.get(nama));
    if (!tabel) {
      masalah.push(`kelas "${nama}" menunjuk tabel "${tabelKelas.get(nama)}" yang tidak ada di skema.json`);
      continue;
    }
    KELAS.push({
      grup,
      nama,
      tabel: tabel.nama,
      atribut: tabel.kolom.map((k) => atribut(k, tabel)),
      operasi: model.operasi,
    });
  }
}

// Setiap model harus masuk tepat satu kelompok. Model baru yang terlewat akan
// hilang dari diagram tanpa suara, dan itu jenis kesalahan yang paling mahal:
// diagramnya tetap terlihat lengkap.
const terdaftar = new Set(Object.values(ANGGOTA).flat());
for (const m of MODEL) {
  if (!terdaftar.has(m.kelas)) masalah.push(`model "${m.kelas}" belum masuk kelompok mana pun`);
}

/* ------------------------------------------------------------ asosiasi */

/**
 * Satu kunci asing menjadi satu asosiasi. Arah panah mengikuti arah baca:
 * `tagihan.kontrak_id` berarti Tagihan menunjuk Kontrak.
 *
 * `banyak` di sisi sumber selalu `*` (satu kontrak punya banyak tagihan).
 * Di sisi tujuan `0..1` bila kolomnya boleh null, `1` bila wajib.
 */
const ASOSIASI = [];
for (const tabel of SKEMA.tabel) {
  const dari = kelasTabel.get(tabel.nama);
  if (!dari) continue;
  for (const asing of tabel.asing) {
    const ke = kelasTabel.get(asing.ke_tabel);
    if (!ke) continue;
    const kolom = tabel.kolom.find((k) => k.nama === asing.kolom);
    ASOSIASI.push({
      dari,
      ke,
      kolom: asing.kolom,
      banyak: '*',
      satu: kolom && kolom.boleh_null ? '0..1' : '1',
    });
  }
}

module.exports = { KELOMPOK, KELAS, ASOSIASI, MODEL, tabelKelas, masalah };
