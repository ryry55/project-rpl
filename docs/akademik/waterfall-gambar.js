'use strict';

/**
 * Diagram tahapan model Waterfall (Gambar II.1).
 *
 * Lima tahap digambar menurun, dengan panah balik putus-putus dari tahap
 * berikutnya ke tahap sebelumnya. Panah balik itu bukan hiasan: ia satu-satunya
 * hal yang membedakan Waterfall dari sekadar daftar pekerjaan berurutan, dan
 * ia pula yang menjadi alasan model ini disebut kaku.
 */

const fs = require('fs');
const path = require('path');

const FONT = "'Segoe UI', 'Inter', 'Helvetica Neue', Arial, sans-serif";

const C = {
  bg: '#ffffff',
  ink: '#0f172a',
  sub: '#475569',
  isi: '#eff6ff',
  tepi: '#1d4ed8',
  balik: '#b45309',
  garis: '#334155',
};

const TAHAP = [
  ['Analisis Kebutuhan', 'kebutuhan fungsional, nonfungsional, aturan bisnis'],
  ['Desain Sistem', 'skema basis data, UML, rancangan antarmuka'],
  ['Implementasi', 'API Laravel, antarmuka Vue, basis data'],
  ['Pengujian', 'tes unit, integrasi, peramban, aksesibilitas, beban'],
  ['Pemeliharaan', 'perbaikan temuan dan amandemen aturan'],
];

const lebar = 1000;
const atas = 120;
const tinggiKotak = 86;
const jarak = 56;
const lebarKotak = 460;
const x = 190;

const tinggi = atas + TAHAP.length * (tinggiKotak + jarak) + 60;

if (tinggi > 1000) throw new Error('kanvas terlalu tinggi untuk halaman: ' + tinggi);

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

function kotak([judul, isi], i) {
  const y = atas + i * (tinggiKotak + jarak);
  return (
    `<rect x="${x}" y="${y}" width="${lebarKotak}" height="${tinggiKotak}" rx="10" fill="${C.isi}" stroke="${C.tepi}" stroke-width="1.8"/>` +
    `<text x="${x + lebarKotak / 2}" y="${y + 34}" font-family="${FONT}" font-size="17" font-weight="700" fill="${C.ink}" text-anchor="middle">${esc(`${i + 1}. ${judul}`)}</text>` +
    `<text x="${x + lebarKotak / 2}" y="${y + 60}" font-family="${FONT}" font-size="12.5" fill="${C.sub}" text-anchor="middle">${esc(isi)}</text>`
  );
}

function panahTurun(i) {
  const y1 = atas + i * (tinggiKotak + jarak) + tinggiKotak;
  const y2 = y1 + jarak - 6;
  return `<line x1="${x + lebarKotak / 2}" y1="${y1}" x2="${x + lebarKotak / 2}" y2="${y2}" stroke="${C.garis}" stroke-width="1.8" marker-end="url(#kepala)"/>`;
}

function panahBalik(i) {
  // Dari tepi kiri kotak berikutnya, ke kiri, naik, lalu masuk ke tepi kiri
  // kotak sebelumnya. Titik sambung sengaja di badan kotak, bukan di celah:
  // panah yang berhenti di ruang kosong terbaca seperti garis yang lupa
  // ditempelkan.
  const y1 = atas + (i + 1) * (tinggiKotak + jarak) + 26;
  const y2 = atas + i * (tinggiKotak + jarak) + tinggiKotak - 22;
  const xKiri = x - 46;
  return (
    `<path d="M ${x} ${y1} H ${xKiri} V ${y2} H ${x - 6}" fill="none" stroke="${C.balik}" stroke-width="1.4" stroke-dasharray="5 4" marker-end="url(#kepalaBalik)"/>`
  );
}

function gambar() {
  const bagian = TAHAP.map(kotak);
  const turun = TAHAP.slice(0, -1).map((_, i) => panahTurun(i));
  const balik = TAHAP.slice(1).map((_, i) => panahBalik(i));

  return `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="${lebar}" height="${tinggi}" viewBox="0 0 ${lebar} ${tinggi}" font-family="${FONT}">
  <defs>
    <marker id="kepala" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
      <path d="M 0 0 L 10 5 L 0 10 z" fill="${C.garis}"/>
    </marker>
    <marker id="kepalaBalik" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
      <path d="M 0 0 L 10 5 L 0 10 z" fill="${C.balik}"/>
    </marker>
  </defs>
  <rect width="100%" height="100%" fill="${C.bg}"/>
  <text x="60" y="52" font-size="23" font-weight="700" fill="${C.ink}">TAHAPAN MODEL WATERFALL</text>
  <text x="60" y="76" font-size="13" fill="${C.sub}">Sistem Informasi Operasional Huni - Gambar II.1</text>
  ${turun.join('\n  ')}
  ${balik.join('\n  ')}
  ${bagian.join('\n  ')}
  <text x="${(lebar) / 2}" y="${tinggi - 22}" font-family="${FONT}" font-size="12.5" fill="${C.sub}" text-anchor="middle">Garis putus-putus: kembali ke tahap sebelumnya bila ditemukan kesalahan pada tahap berikutnya.</text>
</svg>
`;
}

function utama() {
  const isi = gambar();
  fs.writeFileSync(path.join(__dirname, 'waterfall.svg'), isi, 'utf8');
  console.log(`ditulis: waterfall.svg (${isi.length} byte)`);
}

if (require.main === module) utama();

module.exports = { gambar };
