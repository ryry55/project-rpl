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
 * LIMA hal yang bisa dibuktikan:
 *
 *   1. `skema.json` masih cocok dengan basis data yang berjalan — kalau ada
 *      migrasi baru, berkas ini harus diambil ulang;
 *   2. setiap tabel di basis data muncul di LRS, dan tidak ada tabel di LRS
 *      yang sudah tidak ada di basis data;
 *   3. setiap kunci asing muncul di tabel relasi LRS, dengan tujuan yang sama;
 *   4. `manifest.json` hasil build cocok dengan skema, dan setiap berkas SVG
 *      berjenjang yang dicatatnya benar-benar ada dan tidak terpotong;
 *   5. tingkat 2 dan tingkat 3 masing-masing memuat seluruh tabel domain,
 *      tidak kurang dan tidak ganda.
 *
 * Daftar berkas pada butir 4 TIDAK dipatok di sini, melainkan dibaca dari
 * manifest. Nama lembar tingkat 2 dan 3 ditentukan oleh pemecahan otomatis,
 * jadi daftar yang diketik tangan akan basi setiap kali tata letak berubah.
 *
 * Yang TIDAK diperiksa di sini: apakah gambarnya bagus. Itu tugas mata, dan
 * `build-tingkat.js` sudah menolak tumpang tindih simbol serta huruf yang
 * terlalu kecil.
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

// Kredensial dibaca dari environment dengan nilai cadangan yang sama dengan
// `.env.example`. Kalau dipatok `kos`, siapa pun yang memakai nama pengguna
// lain akan mendapat galat "tidak bisa membaca basis data" — pesan yang
// menyalahkan container, padahal container-nya sehat.
const DB_USER = process.env.DB_USERNAME || 'kos';
const DB_NAME = process.env.DB_DATABASE || 'kos';

function tanya(sql) {
  const keluaran = execFileSync(
    'docker',
    ['compose', 'exec', '-T', 'db', 'psql', '-U', DB_USER, '-d', DB_NAME, '-t', '-A', '-f', '-'],
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

/**
 * Daftar berkas TIDAK dipatok di sini.
 *
 * Nama lembar tingkat 2 dan 3 ditentukan oleh pemecahan otomatis di
 * `build-tingkat.js` — jumlahnya berubah bila tata letak berubah. Versi
 * sebelumnya mematok `01-erd-inti.svg` dan `02-erd-lengkap.svg`; ketika kedua
 * berkas itu diganti struktur berjenjang, pemeriksa ini tetap "lulus" hanya
 * karena gagal membaca nama yang salah, bukan karena dokumennya benar.
 *
 * Sekarang ia membaca `manifest.json` yang ditulis build. Bila manifest tidak
 * ada, itu berarti build belum dijalankan — dan itu kegagalan, bukan alasan
 * untuk diam.
 */
const jalurManifest = path.join(AKAR, 'docs', 'erd', 'manifest.json');

if (!fs.existsSync(jalurManifest)) {
  lapor('docs/erd/manifest.json tidak ada — jalankan "node docs/erd/build-tingkat.js"');
} else {
  let manifest;
  try {
    manifest = JSON.parse(fs.readFileSync(jalurManifest, 'utf8'));
  } catch (e) {
    lapor('docs/erd/manifest.json tidak bisa dibaca sebagai JSON');
  }

  if (manifest) {
    // Angka di manifest harus sama dengan yang dihitung dari skema. Tanpa
    // pemeriksaan ini, manifest bisa tertinggal dari skema tanpa ketahuan.
    const kolomSkemaHitung = skema.tabel.reduce((n, t) => n + t.kolom.length, 0);
    const fkSkemaHitung = skema.tabel.reduce((n, t) => n + t.asing.length, 0);
    if (manifest.tabel !== skema.jumlah_tabel) {
      lapor(`manifest menyebut ${manifest.tabel} tabel, skema punya ${skema.jumlah_tabel}`);
    }
    if (manifest.kolom !== kolomSkemaHitung) {
      lapor(`manifest menyebut ${manifest.kolom} kolom, skema punya ${kolomSkemaHitung}`);
    }
    if (manifest.kunci_asing !== fkSkemaHitung) {
      lapor(`manifest menyebut ${manifest.kunci_asing} kunci asing, skema punya ${fkSkemaHitung}`);
    }

    // Keempat tingkat harus ada.
    const tingkat = new Set((manifest.tingkat || []).map((t) => t.tingkat));
    for (const t of [0, 1, 2, 3]) {
      if (!tingkat.has(t)) lapor(`manifest tidak menyebut tingkat ${t}`);
    }

    // Setiap berkas yang dicatat harus benar-benar ada, berisi SVG, dan tidak
    // terpotong. Berkas SVG yang gagal separuh tetap punya awalan `<svg`, jadi
    // panjang minimum diperiksa juga.
    for (const t of manifest.tingkat || []) {
      for (const berkas of t.berkas || []) {
        const jalur = path.join(AKAR, 'docs', 'erd', berkas);
        if (!fs.existsSync(jalur)) {
          lapor(`docs/erd/${berkas} (tingkat ${t.tingkat}) tidak ada — jalankan "node docs/erd/build-tingkat.js"`);
          continue;
        }
        const isi = fs.readFileSync(jalur, 'utf8');
        if (!isi.includes('<svg')) lapor(`docs/erd/${berkas} tidak berisi SVG`);
        if (!isi.trimEnd().endsWith('</svg>')) lapor(`docs/erd/${berkas} terpotong (tidak diakhiri </svg>)`);
        if (isi.length < 4000) {
          lapor(`docs/erd/${berkas} terlalu kecil (${isi.length} byte) — mungkin build gagal separuh`);
        }

        // PNG pasangannya ikut diperiksa: ukuran pikselnya harus tepat 2x
        // atribut width/height SVG. Kelas kesalahan yang ditutup di sini nyata
        // dan tidak terlihat dari SVG mana pun: SVG disunting lalu build ulang,
        // tetapi PNG lupa diekspor ulang. Hasilnya gambar yang benar di repo
        // dan gambar lama di laporan - dan PNG lama terlihat persis seperti PNG
        // yang benar. Tangkapan layar Chrome yang terpotong juga lolos tanpa
        // pemeriksaan ini, karena berkasnya tetap terbentuk, hanya isinya salah.
        const png = path.join(AKAR, 'docs', 'erd', berkas.replace(/\.svg$/, '.png'));
        const ukuran = isi.match(/<svg[^>]*width="(\d+)"[^>]*height="(\d+)"/);
        if (!ukuran) {
          lapor(`docs/erd/${berkas} tidak punya atribut width/height — PNG-nya tidak bisa diperiksa`);
        } else if (!fs.existsSync(png)) {
          lapor(`docs/erd/${berkas.replace(/\.svg$/, '.png')} tidak ada — jalankan "docs/erd/export-png.ps1"`);
        } else {
          const buf = fs.readFileSync(png);
          const sah = buf.length >= 24 && buf.toString('ascii', 1, 4) === 'PNG';
          if (!sah) {
            lapor(`docs/erd/${berkas.replace(/\.svg$/, '.png')} bukan PNG yang sah`);
          } else {
            const harusW = Number(ukuran[1]) * 2;
            const harusH = Number(ukuran[2]) * 2;
            const w = buf.readUInt32BE(16);
            const h = buf.readUInt32BE(20);
            if (w !== harusW || h !== harusH) {
              lapor(
                `docs/erd/${berkas.replace(/\.svg$/, '.png')} berukuran ${w}x${h}, ` +
                  `padahal SVG-nya menuntut ${harusW}x${harusH} — ekspor ulang PNG-nya`,
              );
            }
          }
        }
      }
    }

    // Tingkat 2 dan 3 harus memuat SELURUH tabel domain, masing-masing sekali.
    for (const t of [2, 3]) {
      const catatan = (manifest.tingkat || []).find((x) => x.tingkat === t);
      if (!catatan) continue;
      const daftar = catatan.tabel || [];
      const unik = new Set(daftar);
      for (const nama of tabelSkema) {
        if (!unik.has(nama)) lapor(`tingkat ${t} tidak memuat tabel ${nama}`);
      }
      for (const nama of unik) {
        if (!tabelSkema.has(nama)) lapor(`tingkat ${t} menyebut tabel ${nama} yang tidak ada di skema`);
      }
      if (daftar.length !== unik.size) lapor(`tingkat ${t} memuat tabel ganda`);
    }
  }
}

/* --------------------------------- kepala panah dan garis berimpit (LRS) */

/**
 * Dua cacat yang lolos dari build meski semuanya "LULUS", dan hanya ketahuan
 * dengan mata pada PNG-nya:
 *
 *   1. kepala panah tercetak DI DALAM kotak tabel — menutupi huruf judul;
 *   2. dua kepala panah pada sisi yang sama saling menimpa — jumlah hubungan
 *      tidak lagi bisa dihitung dari gambar.
 *
 * Keduanya diperiksa langsung pada SVG hasil jadi, bukan pada niat perutean:
 * yang tercetak itulah yang dinilai dosen.
 */
function periksaPanahLrs() {
  const berkas = path.join(AKAR, 'docs', 'erd', '03-L3-lrs.svg');
  if (!fs.existsSync(berkas)) return;
  const isi = fs.readFileSync(berkas, 'utf8');

  const kotak = [];
  let m;
  const polaKotak = /<rect x="([\d.]+)" y="([\d.]+)" width="([\d.]+)" height="([\d.]+)"[^>]*fill="#ffffff" stroke="#94a3b8"/g;
  while ((m = polaKotak.exec(isi)) !== null) {
    kotak.push({ x: +m[1], y: +m[2], w: +m[3], h: +m[4] });
  }

  const judul = [];
  const polaJudul = /<text x="([\d.]+)" y="([\d.]+)" text-anchor="middle"[^>]*font-size="21"[^>]*font-weight="700"[^>]*>([^<]+)<\/text>/g;
  while ((m = polaJudul.exec(isi)) !== null) judul.push({ x: +m[1], y: +m[2], nama: m[3] });

  const namaKotak = (k) => {
    const t = judul.find((j) => j.x > k.x && j.x < k.x + k.w && j.y > k.y && j.y < k.y + k.h + 60);
    return t ? t.nama : '(tidak dikenal)';
  };

  const panah = [];
  const polaPanah = /<path d="M ([\d.]+) ([\d.]+) L ([\d.]+) ([\d.]+) L ([\d.]+) ([\d.]+) Z" fill="#334155"\/>/g;
  while ((m = polaPanah.exec(isi)) !== null) {
    panah.push([
      { x: +m[1], y: +m[2] },
      { x: +m[3], y: +m[4] },
      { x: +m[5], y: +m[6] },
    ]);
  }

  if (!panah.length) lapor('03-L3-lrs.svg tidak memuat satu pun kepala panah — arah hubungan hilang');

  // 1. Kepala panah tidak boleh berada di dalam kotak.
  for (const t of panah) {
    for (const k of kotak) {
      const dalam = t.filter(
        (p) => p.x > k.x + 1 && p.x < k.x + k.w - 1 && p.y > k.y + 1 && p.y < k.y + k.h - 1
      );
      if (dalam.length > 1) {
        lapor(`kepala panah di (${t[0].x},${t[0].y}) tercetak di dalam kotak "${namaKotak(k)}"`);
      }
    }
  }

  // 2. Dua kepala panah pada sisi kotak yang sama tidak boleh bertumpuk.
  const sisiDari = (p) => {
    for (const k of kotak) {
      if (Math.abs(p.x - k.x) < 1.5 && p.y >= k.y - 1 && p.y <= k.y + k.h + 1) return `${namaKotak(k)}:kiri`;
      if (Math.abs(p.x - (k.x + k.w)) < 1.5 && p.y >= k.y - 1 && p.y <= k.y + k.h + 1) return `${namaKotak(k)}:kanan`;
      if (Math.abs(p.y - k.y) < 1.5 && p.x >= k.x - 1 && p.x <= k.x + k.w + 1) return `${namaKotak(k)}:atas`;
      if (Math.abs(p.y - (k.y + k.h)) < 1.5 && p.x >= k.x - 1 && p.x <= k.x + k.w + 1) return `${namaKotak(k)}:bawah`;
    }
    return null;
  };

  const berlabel = panah.map((t) => ({ t: t[0], sisi: sisiDari(t[0]) }));
  const AMBANG = 20; // px; di bawah ini dua kepala panah terbaca menyatu
  for (let i = 0; i < berlabel.length; i++) {
    for (let j = i + 1; j < berlabel.length; j++) {
      const a = berlabel[i];
      const b = berlabel[j];
      if (!a.sisi || a.sisi !== b.sisi) continue;
      const dekat = Math.abs(a.t.x - b.t.x) + Math.abs(a.t.y - b.t.y);
      if (dekat < AMBANG) {
        lapor(`dua kepala panah bertumpuk di sisi ${a.sisi} (jarak ${Math.round(dekat)} px)`);
      }
    }
  }

  // 3. Garis sejajar yang berimpit membuat dua hubungan terbaca sebagai satu.
  const segmen = [];
  const polaJalur = /<path d="(M [^"]+)" fill="none" stroke="#334155"/g;
  while ((m = polaJalur.exec(isi)) !== null) {
    const angka = m[1].match(/-?[\d.]+/g).map(Number);
    const titik = [];
    for (let i = 0; i + 1 < angka.length; i += 2) titik.push({ x: angka[i], y: angka[i + 1] });
    for (let i = 1; i < titik.length; i++) {
      const a = titik[i - 1];
      const b = titik[i];
      if (Math.abs(a.x - b.x) > 0.5 || Math.abs(a.y - b.y) > 0.5) segmen.push({ a, b });
    }
  }

  for (let i = 0; i < segmen.length; i++) {
    for (let j = i + 1; j < segmen.length; j++) {
      const s = segmen[i];
      const t = segmen[j];
      const sH = Math.abs(s.a.y - s.b.y) < 0.5;
      const tH = Math.abs(t.a.y - t.b.y) < 0.5;
      if (sH !== tH) continue;
      if (sH) {
        if (Math.abs(s.a.y - t.a.y) >= 3) continue;
        const kiri = Math.max(Math.min(s.a.x, s.b.x), Math.min(t.a.x, t.b.x));
        const kanan = Math.min(Math.max(s.a.x, s.b.x), Math.max(t.a.x, t.b.x));
        if (kanan - kiri > 20) {
          lapor(`dua garis berimpit mendatar pada y=${s.a.y.toFixed(0)} sepanjang ${Math.round(kanan - kiri)} px`);
        }
      } else {
        if (Math.abs(s.a.x - t.a.x) >= 3) continue;
        const atas = Math.max(Math.min(s.a.y, s.b.y), Math.min(t.a.y, t.b.y));
        const bawah = Math.min(Math.max(s.a.y, s.b.y), Math.max(t.a.y, t.b.y));
        if (bawah - atas > 20) {
          lapor(`dua garis berimpit tegak pada x=${s.a.x.toFixed(0)} sepanjang ${Math.round(bawah - atas)} px`);
        }
      }
    }
  }
}

periksaPanahLrs();

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
