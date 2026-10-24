#!/usr/bin/env node
'use strict';

/**
 * Uji bahwa pemeriksa di `usecase-gambar.js` benar-benar menolak data salah.
 * -------------------------------------------------------------------------
 * Pemeriksa yang selalu melaporkan "OK" tidak membuktikan apa pun. Skrip ini
 * merusak `usecase-data.js` dengan sengaja — satu kekeliruan per kasus — lalu
 * memastikan build GAGAL dengan pesan yang menyebut masalahnya.
 *
 * Jalankan:
 *     node docs/akademik/uji-validator.js
 *
 * Berkas data selalu dipulihkan: salinan cadangan ditulis lebih dulu, dan
 * pemulihan ada di `finally` supaya Ctrl+C pun tidak meninggalkan data rusak.
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const AKAR = path.resolve(__dirname, '../..');
const DATA = path.join(__dirname, 'usecase-data.js');
const CADANGAN = DATA + '.cadangan';

const asli = fs.readFileSync(DATA, 'utf8');
fs.writeFileSync(CADANGAN, asli, 'utf8');

function pulihkan() {
  fs.writeFileSync(DATA, asli, 'utf8');
  if (fs.existsSync(CADANGAN)) fs.unlinkSync(CADANGAN);
}

process.on('SIGINT', () => {
  pulihkan();
  process.exit(130);
});

/** Jalankan build dengan data yang sudah dirusak; kembalikan {gagal, pesan}. */
function coba(nama, rusak) {
  let keluaran = '';
  let gagal = false;
  try {
    fs.writeFileSync(DATA, rusak(asli), 'utf8');
    keluaran = execFileSync(process.execPath, [path.join(AKAR, 'docs/akademik/build.js')], {
      cwd: AKAR,
      encoding: 'utf8',
      stdio: 'pipe',
    });
  } catch (e) {
    gagal = true;
    keluaran = (e.stdout || '') + (e.stderr || '');
  } finally {
    fs.writeFileSync(DATA, asli, 'utf8');
  }
  const pesan = keluaran
    .split(/\r?\n/)
    .filter((l) => /^\s+- /.test(l))
    .map((l) => l.trim().replace(/^- /, ''))
    .slice(0, 2);
  return { nama, gagal, pesan };
}

const kasus = [];

// 1. Jumlah use case berkurang: buang objek UC-14 beserta komanya.
kasus.push(
  coba('13 use case', (s) => {
    const i = s.indexOf("kode: 'UC-14'");
    if (i < 0) throw new Error('penanda UC-14 tidak ditemukan');
    const mulai = s.lastIndexOf('  {', i); // awal objek UC-14
    const akhir = s.indexOf('\n];', i); // akhir array USE_CASE
    return s.slice(0, mulai) + s.slice(akhir + 1);
  })
);

// 2. Endpoint yang tidak ada di potret route:list.
kasus.push(coba('endpoint hantu', (s) => s.replace("'api.login',", "'api.login', 'api.tidak-ada',")));

// 3. Satu endpoint dipakai dua use case.
kasus.push(
  coba('endpoint dobel', (s) =>
    s.replace("'kebijakan.index', 'kebijakan.store'", "'kebijakan.index', 'kebijakan.store', 'api.me'")
  )
);

// 4. Aktor yang tidak sanggup memanggil satu pun endpoint bergerbang UC itu.
kasus.push(
  coba('aktor salah', (s) => s.replace("uc: ['UC-01', 'UC-11', 'UC-13'],", "uc: ['UC-01', 'UC-02', 'UC-11', 'UC-13'],"))
);

let lulus = 0;
for (const k of kasus) {
  const ok = k.gagal && k.pesan.length > 0;
  if (ok) lulus++;
  console.log((ok ? 'BENAR  ' : 'SALAH  ') + k.nama.padEnd(16) + (k.pesan[0] || '(tidak ada pesan)'));
}

// Data harus kembali persis seperti semula.
const utuh = fs.readFileSync(DATA, 'utf8') === asli;
if (!utuh) console.error('GAGAL: usecase-data.js tidak pulih seperti semula');
if (fs.existsSync(CADANGAN)) fs.unlinkSync(CADANGAN);

console.log('');
console.log(`${lulus}/${kasus.length} kasus gagal sebagaimana mestinya`);
process.exitCode = lulus === kasus.length && utuh ? 0 : 1;
