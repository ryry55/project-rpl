'use strict';

/**
 * Penggambar + pemeriksa Diagram Urutan
 * -------------------------------------
 * Menerima `urutan-data.js`, lalu MEMERIKSA sebelum menggambar:
 *
 *   1. tepat 14 diagram, kodenya sama persis dengan `usecase-data.js`;
 *   2. `endpoint` ada di `rute-api.json` dan memang milik use case itu;
 *   3. setiap peserta berkategori kendali/layanan/model benar-benar kelas yang
 *      dideklarasikan di `api/app`;
 *   4. setiap langkah menunjuk peserta yang ada, dan `balik` menunjuk langkah
 *      yang ada;
 *   5. langkah pertama berasal dari aktor dan langkah terakhir kembali kepadanya.
 *
 * Pemeriksaan (2) dan (3) adalah alasan berkas ini ada. Diagram urutan yang
 * menyebut kelas atau rute yang tidak ada tetap terlihat rapi — dan justru
 * karena itu berbahaya: tidak ada yang memeriksanya sampai ada yang membuka
 * kodenya. Di sini build berhenti.
 */

const fs = require('fs');
const path = require('path');

const { URUTAN } = require('./urutan-data');
const { USE_CASE } = require('./usecase-data');
const RUTE = require('./rute-api.json');
const { hurufMm, AMBANG_MM } = require('../erd/papan');

const AKAR = path.resolve(__dirname, '..', '..');
const DIR_APP = path.join(AKAR, 'api', 'app');

const FONT = "'Segoe UI', 'Inter', 'Helvetica Neue', Arial, sans-serif";

const C = {
  bg: '#ffffff',
  ink: '#0f172a',
  sub: '#475569',
  redup: '#94a3b8',
  garis: '#cbd5e1',
  garisAktif: '#334155',
  kepala: '#eef2ff',
  kepalaTepi: '#3730a3',
  aktorIsi: '#fffbeb',
  aktorTepi: '#b45309',
};

const HURUF_LABEL = 12;
const HURUF_PESERTA = 12.5;
const LEBAR_MAKS = 1400; // batas agar huruf tetap >= AMBANG_MM di A3
const JARAK_MIN = 126;
const JARAK_PESAN = 40;
const ATAS_PESERTA = 132;

const esc = (s) =>
  String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const r1 = (n) => Math.round(n * 10) / 10;
const lebarTeks = (s, size) => String(s).length * size * 0.55;

/* --------------------------------------------------------------- periksa */

/** Semua kelas yang benar-benar dideklarasikan di api/app. */
function kumpulanKelas() {
  const hasil = new Set();
  const jelajah = (dir) => {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      const p = path.join(dir, e.name);
      if (e.isDirectory()) jelajah(p);
      else if (e.name.endsWith('.php')) {
        const isi = fs.readFileSync(p, 'utf8');
        // `final class PengaturanKanal` dan `abstract class ...` juga kelas.
        // Pola `^class` saja membuat lima kelas nyata dianggap tidak ada, dan
        // pagar ini lalu menolak diagram yang sebenarnya benar.
        const m = isi.match(/^[ \t]*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m);
        if (m) hasil.add(m[1]);
      }
    }
  };
  jelajah(DIR_APP);
  return hasil;
}

const KELAS = kumpulanKelas();
const namaRute = new Set(RUTE.map((r) => r.name));
const ucData = new Map(USE_CASE.map((u) => [u.kode, u]));

function periksa() {
  const masalah = [];

  if (URUTAN.length !== 14) masalah.push(`ada ${URUTAN.length} diagram, seharusnya 14`);

  const kode = new Set();
  for (const u of URUTAN) {
    if (kode.has(u.kode)) masalah.push(`${u.kode} digambar dua kali`);
    kode.add(u.kode);

    const uc = ucData.get(u.kode);
    if (!uc) {
      masalah.push(`${u.kode} tidak ada di usecase-data.js`);
    } else if (!uc.endpoint.includes(u.endpoint)) {
      masalah.push(`${u.kode}: "${u.endpoint}" bukan anggota use case itu`);
    }
    if (!namaRute.has(u.endpoint)) masalah.push(`${u.kode}: rute "${u.endpoint}" tidak ada di rute-api.json`);

    const id = new Set(u.peserta.map((p) => p[0]));
    if (id.size !== u.peserta.length) masalah.push(`${u.kode}: ada id peserta ganda`);

    for (const [pid, nama, jenis] of u.peserta) {
      if (['kendali', 'layanan', 'model'].includes(jenis) && !KELAS.has(nama)) {
        masalah.push(`${u.kode}: kelas "${nama}" tidak ada di api/app`);
      }
      if (jenis === 'aktor' && u.peserta.filter((p) => p[2] === 'aktor').length !== 1) {
        masalah.push(`${u.kode}: harus tepat satu aktor`);
      }
      if (!pid) masalah.push(`${u.kode}: peserta tanpa id`);
    }

    const nomorBalik = new Set(u.balik || []);
    for (const n of nomorBalik) {
      if (!Number.isInteger(n) || n < 1 || n > u.langkah.length) {
        masalah.push(`${u.kode}: balik menunjuk langkah ${n} yang tidak ada`);
      }
    }
    if (nomorBalik.has(1)) masalah.push(`${u.kode}: langkah pertama tidak boleh panah balik`);
    if (u.langkah.length && !nomorBalik.has(u.langkah.length)) {
      masalah.push(`${u.kode}: langkah terakhir harus panah balik ke aktor`);
    }

    u.langkah.forEach(([dari, ke], i) => {
      if (!id.has(dari)) masalah.push(`${u.kode} langkah ${i + 1}: peserta "${dari}" tidak ada`);
      if (!id.has(ke)) masalah.push(`${u.kode} langkah ${i + 1}: peserta "${ke}" tidak ada`);
    });

    const aktorId = u.peserta.find((p) => p[2] === 'aktor')[0];
    if (u.langkah.length) {
      if (u.langkah[0][0] !== aktorId) masalah.push(`${u.kode}: langkah pertama bukan dari aktor`);
      if (u.langkah[u.langkah.length - 1][1] !== aktorId) {
        masalah.push(`${u.kode}: langkah terakhir tidak kembali ke aktor`);
      }
    }
  }

  for (const uc of USE_CASE) if (!kode.has(uc.kode)) masalah.push(`${uc.kode} belum punya diagram`);

  return masalah;
}

/* ---------------------------------------------------------------- gambar */

function bungkus(isi, lebarMaks) {
  if (lebarTeks(isi, HURUF_LABEL) <= lebarMaks) return [isi];
  const kata = isi.split(' ');
  let terbaik = [isi];
  for (let i = 1; i < kata.length; i++) {
    const a = kata.slice(0, i).join(' ');
    const b = kata.slice(i).join(' ');
    const w = Math.max(lebarTeks(a, HURUF_LABEL), lebarTeks(b, HURUF_LABEL));
    if (w < lebarTeks(terbaik.join(' '), HURUF_LABEL)) terbaik = [a, b];
  }
  return terbaik;
}

function gambarUrutan(u) {
  const idx = new Map(u.peserta.map(([id], i) => [id, i]));
  const lebarKotak = u.peserta.map(([, nama, jenis]) =>
    Math.max(lebarTeks(nama, HURUF_PESERTA) + 28, jenis === 'aktor' ? 110 : 118)
  );

  // Celah antar garis hidup: cukup lebar untuk label terpanjang yang melintasinya.
  const celah = Array.from({ length: u.peserta.length - 1 }, () => JARAK_MIN);
  const label = [];
  for (const [dari, ke, isi] of u.langkah) {
    const a = idx.get(dari);
    const b = idx.get(ke);
    if (a === b) continue;
    const kiri = Math.min(a, b);
    const kanan = Math.max(a, b);
    let garis = bungkus(isi, (kanan - kiri) * JARAK_MIN - 24);
    const lebar = Math.max(...garis.map((g) => lebarTeks(g, HURUF_LABEL))) + 26;
    const perCelah = lebar / (kanan - kiri);
    for (let k = kiri; k < kanan; k++) celah[k] = Math.max(celah[k], perCelah);
    label.push(garis);
  }

  // Setelah celah diketahui, bungkus ulang label yang masih lebih lebar dari
  // bentangannya — supaya teks tidak menimpa label tetangga.
  u.langkah.forEach(([dari, ke, isi], i) => {
    const a = idx.get(dari);
    const b = idx.get(ke);
    if (a === b) return;
    const kiri = Math.min(a, b);
    const kanan = Math.max(a, b);
    let bentang = 0;
    for (let k = kiri; k < kanan; k++) bentang += celah[k];
    label[i] = bungkus(isi, bentang - 30);
  });

  const x = [];
  let kursor = 40 + lebarKotak[0] / 2;
  x.push(kursor);
  for (let i = 1; i < u.peserta.length; i++) {
    kursor += Math.max(celah[i - 1], (lebarKotak[i - 1] + lebarKotak[i]) / 2 + 12);
    x.push(kursor);
  }
  const kanvasW = Math.round(x[x.length - 1] + lebarKotak[lebarKotak.length - 1] / 2 + 40);
  const pesanAtas = ATAS_PESERTA + 92;
  const kanvasH = Math.round(pesanAtas + u.langkah.length * JARAK_PESAN + 96);

  const garasi = [];
  garasi.push(
    `<svg xmlns="http://www.w3.org/2000/svg" width="${kanvasW}" height="${kanvasH}" viewBox="0 0 ${kanvasW} ${kanvasH}" font-family="${FONT}">`
  );
  garasi.push(`<rect width="${kanvasW}" height="${kanvasH}" fill="${C.bg}"/>`);
  garasi.push(`<title>${esc(`${u.kode} — ${u.judul}`)}</title>`);

  const t = (px, py, isi, size, weight, warna, anchor) =>
    `<text x="${r1(px)}" y="${r1(py)}" text-anchor="${
      anchor || 'middle'
    }" font-size="${size}" font-weight="${weight}" fill="${warna}">${esc(isi)}</text>`;

  garasi.push(t(40, 44, `${u.kode} — ${u.judul}`, 24, 700, C.ink, 'start'));
  garasi.push(t(40, 70, `Aktor: ${u.aktor}`, 13, 500, C.sub, 'start'));
  garasi.push(t(kanvasW - 40, 44, 'Diagram Urutan', 13, 600, C.redup, 'end'));

  // Kepala peserta + garis hidup.
  u.peserta.forEach(([id, nama, jenis], i) => {
    const w = lebarKotak[i];
    const kiri = x[i] - w / 2;
    const aktor = jenis === 'aktor';
    garasi.push(
      `<rect x="${r1(kiri)}" y="${ATAS_PESERTA}" width="${r1(w)}" height="46" rx="6" fill="${
        aktor ? C.aktorIsi : C.kepala
      }" stroke="${aktor ? C.aktorTepi : C.kepalaTepi}" stroke-width="1.3"/>`
    );
    garasi.push(t(x[i], ATAS_PESERTA + 20, nama, HURUF_PESERTA, 600, aktor ? C.aktorTepi : C.kepalaTepi));
    garasi.push(t(x[i], ATAS_PESERTA + 37, JENIS[jenis], 9.5, 500, C.redup));
    garasi.push(
      `<line x1="${r1(x[i])}" y1="${ATAS_PESERTA + 46}" x2="${r1(x[i])}" y2="${kanvasH - 62}" stroke="${
        C.garis
      }" stroke-width="1.2" stroke-dasharray="5 5"/>`
    );
  });

  // Pesan.
  u.langkah.forEach(([dari, ke, isi], i) => {
    const a = idx.get(dari);
    const b = idx.get(ke);
    const y = pesanAtas + i * JARAK_PESAN;
    const balik = (u.balik || []).includes(i + 1);
    const kiriKeKanan = b > a;
    const x1 = x[a];
    const x2 = x[b] + (kiriKeKanan ? -1 : 1) * 0;

    if (a === b) {
      garasi.push(
        `<path d="M${r1(x1)} ${r1(y)} h44 v18 h-44" fill="none" stroke="${C.garisAktif}" stroke-width="1.4"/>`,
        `<path d="M${r1(x1)} ${r1(y + 18)} l7 -5 M${r1(x1)} ${r1(y + 18)} l7 5" fill="none" stroke="${C.garisAktif}" stroke-width="1.4"/>`
      );
      garasi.push(t(x1 + 52, y + 2, `${i + 1}. ${label[i].join(' ')}`, HURUF_LABEL, 400, C.ink, 'start'));
      return;
    }

    garasi.push(
      `<line x1="${r1(x1)}" y1="${r1(y)}" x2="${r1(x2)}" y2="${r1(y)}" stroke="${
        C.garisAktif
      }" stroke-width="1.4"${balik ? ' stroke-dasharray="5 4"' : ''}/>`
    );
    const ujung = kiriKeKanan ? x2 - 9 : x2 + 9;
    const arah = kiriKeKanan ? 1 : -1;
    garasi.push(
      balik
        ? `<path d="M${r1(x2)} ${r1(y)} l${-9 * arah} -5 M${r1(x2)} ${r1(y)} l${-9 * arah} 5" fill="none" stroke="${C.garisAktif}" stroke-width="1.4"/>`
        : `<path d="M${r1(x2)} ${r1(y)} l${-10 * arah} -5.5 l0 11 z" fill="${C.garisAktif}"/>`
    );

    // Label dua baris diletakkan di atas panah, tidak menimpa garis.
    label[i].forEach((baris, k) => {
      const ly = y - 8 - (label[i].length - 1 - k) * 14;
      const teks = k === 0 ? `${i + 1}. ${baris}` : baris;
      garasi.push(t((x1 + x2) / 2, ly, teks, HURUF_LABEL, 400, C.ink));
    });
  });

  // Catatan kaki.
  const yn = kanvasH - 34;
  garasi.push(`<line x1="40" y1="${yn - 22}" x2="${kanvasW - 40}" y2="${yn - 22}" stroke="${C.garis}"/>`);
  const catatan = bungkusKata(u.catatan, kanvasW - 80, 10.5);
  catatan.slice(0, 2).forEach((baris, i) => {
    garasi.push(t(40, yn + i * 15, baris, 10.5, 400, C.sub, 'start'));
  });

  garasi.push('</svg>');

  const mm = hurufMm(HURUF_LABEL, { w: kanvasW, h: kanvasH }, 'A3');
  const masalah = [];
  if (mm < AMBANG_MM) {
    masalah.push(`huruf ${mm.toFixed(2)} mm di A3 (ambang ${AMBANG_MM} mm) — kanvas ${kanvasW} px terlalu lebar`);
  }
  if (kanvasW > LEBAR_MAKS) {
    masalah.push(`kanvas ${kanvasW} px melebihi batas ${LEBAR_MAKS} px`);
  }

  return { svg: garasi.join('\n'), kanvas: { w: kanvasW, h: kanvasH }, hurufMm: mm, masalah };
}

const JENIS = {
  aktor: 'aktor',
  batas: 'batas sistem',
  kendali: 'kendali',
  layanan: 'layanan domain',
  model: 'model / entitas',
  data: 'penyimpanan',
};

/** Bungkus per kata untuk catatan kaki (tanpa memotong kata). */
function bungkusKata(isi, lebarMaks, size) {
  const kata = String(isi).split(' ');
  const baris = [];
  let kini = '';
  for (const k of kata) {
    const coba = kini ? `${kini} ${k}` : k;
    if (lebarTeks(coba, size) > lebarMaks && kini) {
      baris.push(kini);
      kini = k;
    } else kini = coba;
  }
  if (kini) baris.push(kini);
  return baris;
}

module.exports = { gambarUrutan, periksa };

if (require.main === module) {
  const masalah = periksa();
  if (masalah.length) {
    console.log('PERIKSA GAGAL:');
    for (const m of masalah) console.log('  - ' + m);
    process.exit(1);
  }
  let gagal = 0;
  for (const u of URUTAN) {
    const h = gambarUrutan(u);
    console.log(
      `${u.kode}  ${String(h.kanvas.w).padStart(4)}x${String(h.kanvas.h).padEnd(4)}  huruf ${h.hurufMm.toFixed(2)} mm`
    );
    for (const m of h.masalah) {
      console.log('   MASALAH: ' + m);
      gagal += 1;
    }
  }
  console.log(`\n${URUTAN.length} diagram, gagal: ${gagal}`);
  process.exit(gagal === 0 ? 0 : 1);
}
