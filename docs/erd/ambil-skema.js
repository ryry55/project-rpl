'use strict';

/**
 * Mengambil skema NYATA dari basis data yang berjalan.
 * -------------------------------------------------------------------------
 * Menghasilkan: skema.json
 *
 * Kenapa tidak diketik tangan. ERD dan LRS yang digambar dari ingatan akan
 * menyimpang dari basis data, dan penyimpangan itu tidak akan ketahuan sampai
 * ada yang membandingkan keduanya — biasanya dosen atau pemilik kos. Tiga
 * puluh dua tabel dengan ratusan kolom terlalu banyak untuk diketik ulang
 * dengan benar, dan kolom yang lupa diketik tidak akan menimbulkan galat di
 * mana pun; ia hanya akan muncul sebagai gambar yang salah.
 *
 * SQL dikirim lewat stdin (`psql -f -`), bukan sebagai argumen. Di Windows,
 * argumen yang memuat tanda kutip tunggal harus diloloskan dua lapis (cmd dan
 * PowerShell), dan hasilnya adalah galat sintaks SQL yang menyesatkan.
 *
 * Jalankan: node docs/erd/ambil-skema.js
 */

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const AKAR = path.resolve(__dirname, '..', '..');
const KELUARAN = path.join(__dirname, 'skema.json');

// Tabel milik Laravel, bukan milik domain kos-kosan. Ini daftar YANG
// DIKECUALIKAN, bukan yang disertakan: kalau Laravel menambah tabel
// infrastruktur baru, ia akan muncul di sini dan ketahuan — sebaliknya,
// daftar "yang disertakan" akan diam-diam melewatkan tabel domain baru.
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

/** Jalankan satu query dan kembalikan hasil JSON-nya. */
function tanya(sql) {
  const keluaran = execFileSync(
    'docker',
    ['compose', 'exec', '-T', 'db', 'psql', '-U', 'kos', '-d', 'kos', '-t', '-A', '-f', '-'],
    { input: sql, cwd: AKAR, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }
  );
  const teks = keluaran.trim();
  if (!teks) throw new Error('basis data tidak menjawab apa pun; apakah container `db` hidup?');
  return JSON.parse(teks);
}

const sqlKolom = `
select coalesce(json_agg(x order by x.tabel, x.urut), '[]'::json)
from (
  select c.table_name as tabel,
         c.column_name as kolom,
         c.data_type as tipe,
         (c.is_nullable = 'YES') as boleh_null,
         c.ordinal_position as urut,
         c.character_maximum_length as panjang,
         c.numeric_precision as presisi,
         c.numeric_scale as skala,
         c.column_default as bawaan
  from information_schema.columns c
  join information_schema.tables t
    on t.table_schema = c.table_schema
   and t.table_name = c.table_name
   and t.table_type = 'BASE TABLE'
  where c.table_schema = 'public'
    and c.table_name not in (${daftarKecuali})
) x;
`;

const sqlKunci = `
select coalesce(json_agg(x order by x.tabel, x.jenis, x.nama, x.urut), '[]'::json)
from (
  select tc.table_name as tabel,
         tc.constraint_type as jenis,
         tc.constraint_name as nama,
         tc.constraint_name as nama_kunci,
         kcu.column_name as kolom,
         kcu.ordinal_position as urut,
         kcu.ordinal_position as urut_kolom,
         null::text as ke_tabel,
         null::text as ke_kolom
  from information_schema.table_constraints tc
  join information_schema.key_column_usage kcu
    on kcu.constraint_name = tc.constraint_name
   and kcu.table_schema = tc.table_schema
  where tc.table_schema = 'public'
    and tc.constraint_type in ('PRIMARY KEY', 'UNIQUE')
    and tc.table_name not in (${daftarKecuali})

  union all

  -- Kunci asing diambil dari pg_constraint, bukan constraint_column_usage.
  -- Tabel constraint_column_usage tidak tahu kolom mana berpasangan dengan
  -- kolom mana, sehingga untuk kunci majemuk ia mengembalikan perkalian silang
  -- (3 kolom x 3 kolom = 9 baris). Untuk kunci tunggal hasilnya kebetulan
  -- benar, sehingga cacat ini tidak ketahuan sampai ada kunci gabungan.
  select c.relname as tabel,
         'FOREIGN KEY' as jenis,
         con.conname as nama,
         con.conname as nama_kunci,
         a.attname as kolom,
         k.ord as urut,
         k.ord as urut_kolom,
         f.relname as ke_tabel,
         fa.attname as ke_kolom
  from pg_constraint con
  join pg_class c on c.oid = con.conrelid
  join pg_namespace n on n.oid = c.relnamespace
  join pg_class f on f.oid = con.confrelid
  join lateral unnest(con.conkey, con.confkey) with ordinality as k(attnum, fattnum, ord) on true
  join pg_attribute a on a.attrelid = con.conrelid and a.attnum = k.attnum
  join pg_attribute fa on fa.attrelid = con.confrelid and fa.attnum = k.fattnum
  where n.nspname = 'public'
    and con.contype = 'f'
    and c.relname not in (${daftarKecuali})
) x;
`;

const sqlCheck = `
select coalesce(json_agg(x order by x.tabel, x.nama), '[]'::json)
from (
  select rel.relname as tabel,
         con.conname as nama,
         pg_get_constraintdef(con.oid) as definisi
  from pg_constraint con
  join pg_class rel on rel.oid = con.conrelid
  join pg_namespace n on n.oid = rel.relnamespace
  where n.nspname = 'public'
    and con.contype = 'c'
    and rel.relname not in (${daftarKecuali})
) x;
`;

// Indeks unik PARSIAL tidak muncul sebagai UNIQUE constraint di
// information_schema, padahal justru inilah yang menjaga R-02 (satu tagihan
// hidup per periode) dan satu kontrak aktif per kamar. Tanpa query ini, LRS
// akan kehilangan aturan yang paling penting.
const sqlParsial = `
select coalesce(json_agg(x order by x.tabel, x.nama), '[]'::json)
from (
  select tablename as tabel, indexname as nama, indexdef as definisi
  from pg_indexes
  where schemaname = 'public'
    and indexdef like '%UNIQUE%'
    and indexdef like '%WHERE%'
    and tablename not in (${daftarKecuali})
) x;
`;

// Trigger penjaga (append-only) tidak terlihat di daftar kolom mana pun, padahal
// ia justru yang membuat riwayat tarif dan pembayaran tidak bisa ditimpa. Kalau
// daftar ini diketik tangan di dokumen, satu migrasi baru akan membuat dokumen
// menjelaskan aturan yang sudah tidak berlaku.
const sqlTrigger = `
select coalesce(json_agg(x order by x.tabel, x.nama), '[]'::json)
from (
  select c.relname as tabel,
         t.tgname as nama,
         pg_get_triggerdef(t.oid) as definisi
  from pg_trigger t
  join pg_class c on c.oid = t.tgrelid
  join pg_namespace n on n.oid = c.relnamespace
  where n.nspname = 'public'
    and not t.tgisinternal
    and c.relname not in (${daftarKecuali})
) x;
`;

const kolom = tanya(sqlKolom);
const kunci = tanya(sqlKunci);
const check = tanya(sqlCheck);
const parsial = tanya(sqlParsial);
const trigger = tanya(sqlTrigger);

// Susun per tabel supaya pemakainya tidak perlu menyaring berulang kali.
const tabel = [...new Set(kolom.map((k) => k.tabel))].sort();
const skema = {
  diambil_pada: new Date().toISOString(),
  jumlah_tabel: tabel.length,
  tabel: tabel.map((nama) => ({
    nama,
    kolom: kolom
      .filter((k) => k.tabel === nama)
      .map((k) => ({
        nama: k.kolom,
        tipe: k.tipe,
        panjang: k.panjang,
        presisi: k.presisi,
        skala: k.skala,
        boleh_null: k.boleh_null,
        bawaan: k.bawaan,
      })),
    kunci_primer: [
      ...new Set(kunci.filter((k) => k.tabel === nama && k.jenis === 'PRIMARY KEY').map((k) => k.kolom)),
    ],
    unik: Object.values(
      kunci
        .filter((k) => k.tabel === nama && k.jenis === 'UNIQUE')
        .reduce((akumulasi, k) => {
          const id = k.nama;
          akumulasi[id] = akumulasi[id] || { nama: id, kolom: [] };
          akumulasi[id].kolom.push(k.kolom);
          return akumulasi;
        }, {})
    ),
    asing: kunci
      .filter((k) => k.tabel === nama && k.jenis === 'FOREIGN KEY')
      .map((k) => ({ kolom: k.kolom, ke_tabel: k.ke_tabel, ke_kolom: k.ke_kolom, nama: k.nama })),
    check: check.filter((c) => c.tabel === nama).map((c) => ({ nama: c.nama, definisi: c.definisi })),
    unik_parsial: parsial.filter((p) => p.tabel === nama).map((p) => ({ nama: p.nama, definisi: p.definisi })),
    trigger: trigger.filter((t) => t.tabel === nama).map((t) => ({ nama: t.nama, definisi: t.definisi })),
  })),
};

fs.writeFileSync(KELUARAN, JSON.stringify(skema, null, 2) + '\n', 'utf8');

console.log(`ditulis: ${path.relative(AKAR, KELUARAN)}`);
console.log(`  ${skema.jumlah_tabel} tabel, ${kolom.length} kolom`);
console.log(
  `  ${skema.tabel.reduce((n, t) => n + t.asing.length, 0)} kunci asing, ` +
    `${skema.tabel.reduce((n, t) => n + t.unik_parsial.length, 0)} indeks unik parsial, ` +
    `${skema.tabel.reduce((n, t) => n + t.trigger.length, 0)} trigger`
);
