'use strict';

/**
 * Diagram arsitektur untuk proposal (Gambar IV.2).
 *
 * Menggambar dua lingkungan yang benar-benar dipakai repo ini:
 *
 *   demo      - empat container: web (nginx + SPA), api-web, api (PHP-FPM),
 *               db (PostgreSQL), semuanya di satu laptop.
 *   produksi  - satu container (nginx + PHP-FPM + SPA) di Vercel Container
 *               Runtime, dengan PostgreSQL Supabase di luar.
 *
 * Satu gambar, dua lajur. Dipisah karena menyatukannya menghasilkan kotak
 * "server" yang tidak menjelaskan apa pun: yang membedakan keduanya justru
 * letak batas kepercayaan (mana yang di dalam jaringan, mana yang lewat
 * internet).
 *
 * Pagar: setiap kotak harus berada di dalam kanvas, dan tidak boleh ada dua
 * kotak yang beririsan. Kesalahan tata letak seperti itu tidak menimbulkan
 * galat apa pun - hanya gambar yang salah - jadi diperiksa mesin.
 */

const fs = require('fs');
const path = require('path');

const FONT = "'Segoe UI', 'Inter', 'Helvetica Neue', Arial, sans-serif";

const C = {
  bg: '#ffffff',
  ink: '#0f172a',
  sub: '#475569',
  garis: '#334155',
  isiKotak: '#ffffff',
  tepiKotak: '#1e293b',
  klien: '#eff6ff',
  klienTepi: '#1d4ed8',
  server: '#f0fdf4',
  serverTepi: '#15803d',
  data: '#fef3c7',
  dataTepi: '#b45309',
  luar: '#f8fafc',
  luarTepi: '#94a3b8',
};

const lebar = 1100;
const tinggi = 760;

const kotak = [
  // Lajur demo (kiri)
  { x: 40, y: 150, w: 470, h: 340, jenis: 'luar', judul: 'LINGKUNGAN DEMO (satu laptop, Docker Compose)' },
  { x: 70, y: 200, w: 200, h: 70, jenis: 'klien', judul: 'Peramban', isi: 'SPA Vue 3' },
  { x: 300, y: 200, w: 180, h: 70, jenis: 'server', judul: 'web (nginx)', isi: 'SPA + proxy /api' },
  { x: 300, y: 300, w: 180, h: 70, jenis: 'server', judul: 'api (PHP-FPM)', isi: 'Laravel 12' },
  { x: 300, y: 400, w: 180, h: 70, jenis: 'data', judul: 'db', isi: 'PostgreSQL 16' },
  { x: 70, y: 300, w: 200, h: 70, jenis: 'luar', judul: 'Mailpit', isi: 'penangkap surel' },

  // Lajur produksi (kanan)
  { x: 570, y: 150, w: 490, h: 340, jenis: 'luar', judul: 'PRODUKSI (Vercel + Supabase)' },
  { x: 600, y: 200, w: 200, h: 70, jenis: 'klien', judul: 'Peramban / PWA', isi: 'HTTPS satu origin' },
  { x: 830, y: 200, w: 200, h: 70, jenis: 'server', judul: 'Container Vercel', isi: 'nginx + PHP-FPM + SPA' },
  { x: 830, y: 320, w: 200, h: 70, jenis: 'data', judul: 'Supabase', isi: 'PostgreSQL 17 (TLS)' },
];

const catatan = [
  { x: 70, y: 530, w: 990, h: 78, teks: 'Batas kepercayaan: di demo, hanya port 127.0.0.1 yang dibuka ke host. Di produksi, seluruh trafik masuk lewat HTTPS Vercel; basis data hanya menerima koneksi TLS dari container aplikasi (sslmode=require).' },
  { x: 70, y: 630, w: 990, h: 60, teks: 'Satu origin pada kedua lingkungan: SPA dan API dilayani dari host yang sama, sehingga tidak ada CORS dan token sesi tidak menyeberang situs.' },
];

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

function bungkus(teks, maks) {
  const kata = String(teks).split(' ');
  const hasil = [];
  let kini = '';
  for (const k of kata) {
    if ((kini + ' ' + k).trim().length > maks) {
      if (kini) hasil.push(kini);
      kini = k;
    } else {
      kini = (kini + ' ' + k).trim();
    }
  }
  if (kini) hasil.push(kini);
  return hasil;
}

function periksa() {
  for (const k of [...kotak, ...catatan]) {
    if (k.x < 0 || k.y < 0 || k.x + k.w > lebar || k.y + k.h > tinggi) {
      throw new Error(`kotak keluar kanvas: ${k.judul || k.teks.slice(0, 30)}`);
    }
  }
  const semua = [...kotak, ...catatan];
  for (let i = 0; i < semua.length; i++) {
    for (let j = i + 1; j < semua.length; j++) {
      const a = semua[i];
      const b = semua[j];
      if (a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h) {
        // Bingkai lajur memang memuat kotak di dalamnya; itu bukan irisan.
        const aBingkai = a.jenis === 'luar' && a.w > 400;
        const bBingkai = b.jenis === 'luar' && b.w > 400;
        if (aBingkai || bBingkai) continue;
        throw new Error(`dua kotak beririsan: ${a.judul || '?'} dan ${b.judul || '?'}`);
      }
    }
  }
}

function kotakSvg(k) {
  const warnaIsi = {
    klien: C.klien,
    server: C.server,
    data: C.data,
    luar: C.luar,
  }[k.jenis];
  const warnaTepi = {
    klien: C.klienTepi,
    server: C.serverTepi,
    data: C.dataTepi,
    luar: C.luarTepi,
  }[k.jenis];

  const putus = k.jenis === 'luar' && k.w > 400 ? ' stroke-dasharray="6 5"' : '';

  if (k.jenis === 'luar' && k.w > 400) {
    return (
      `<rect x="${k.x}" y="${k.y}" width="${k.w}" height="${k.h}" rx="12" fill="${warnaIsi}" stroke="${warnaTepi}" stroke-width="1.4"${putus}/>` +
      `<text x="${k.x + 16}" y="${k.y + 24}" font-family="${FONT}" font-size="12" font-weight="700" fill="${C.sub}" letter-spacing="0.4">${esc(k.judul)}</text>`
    );
  }

  const barisJudul = bungkus(k.judul, 22);
  const barisIsi = bungkus(k.isi, 24);
  const tengahX = k.x + k.w / 2;
  const mulaiY = k.y + k.h / 2 - ((barisJudul.length + barisIsi.length) * 8);

  return (
    `<rect x="${k.x}" y="${k.y}" width="${k.w}" height="${k.h}" rx="8" fill="${warnaIsi}" stroke="${warnaTepi}" stroke-width="1.6"/>` +
    barisJudul
      .map(
        (b, i) =>
          `<text x="${tengahX}" y="${mulaiY + i * 17}" font-family="${FONT}" font-size="13" font-weight="700" fill="${C.ink}" text-anchor="middle">${esc(b)}</text>`
      )
      .join('') +
    barisIsi
      .map(
        (b, i) =>
          `<text x="${tengahX}" y="${mulaiY + barisJudul.length * 17 + i * 16 + 4}" font-family="${FONT}" font-size="12" fill="${C.sub}" text-anchor="middle">${esc(b)}</text>`
      )
      .join('')
  );
}

function panah(x1, y1, x2, y2, teks) {
  const bagian = [
    `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${C.garis}" stroke-width="1.5" marker-end="url(#kepala)"/>`,
  ];
  if (teks) {
    // Label diletakkan di sisi garis, bukan di tengahnya: teks yang dicoret
    // panah terbaca seperti gambar yang rusak.
    const tegak = Math.abs(x1 - x2) < 4;
    const mx = tegak ? x1 + 8 : (x1 + x2) / 2;
    const my = tegak ? (y1 + y2) / 2 + 4 : (y1 + y2) / 2 - 8;
    bagian.push(
      `<text x="${mx}" y="${my}" font-family="${FONT}" font-size="11" fill="${C.sub}" text-anchor="${tegak ? 'start' : 'middle'}">${esc(teks)}</text>`
    );
  }
  return bagian.join('');
}

function gambar() {
  periksa();

  const panahSvg = [
    // demo
    panah(270, 235, 296, 235, 'HTTP'),
    panah(390, 270, 390, 296, '/api'),
    panah(390, 370, 390, 396, 'SQL'),
    panah(296, 335, 274, 335, 'SMTP'),
    // produksi
    panah(800, 235, 826, 235, 'HTTPS'),
    panah(930, 270, 930, 316, 'TLS'),
  ].join('\n  ');

  return `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="${lebar}" height="${tinggi}" viewBox="0 0 ${lebar} ${tinggi}" font-family="${FONT}">
  <defs>
    <marker id="kepala" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
      <path d="M 0 0 L 10 5 L 0 10 z" fill="${C.garis}"/>
    </marker>
  </defs>
  <rect width="100%" height="100%" fill="${C.bg}"/>
  <text x="60" y="52" font-size="24" font-weight="700" fill="${C.ink}">ARSITEKTUR SISTEM</text>
  <text x="60" y="78" font-size="13" fill="${C.sub}">Huni - dua lingkungan yang dijalankan: demo di laptop, produksi di Vercel + Supabase</text>
  <text x="60" y="108" font-size="13" fill="${C.sub}">Satu origin pada keduanya: SPA dan API di host yang sama; basis data di balik TLS.</text>
  ${kotak.map(kotakSvg).join('\n  ')}
  ${panahSvg}
  ${catatan
    .map(
      (c) =>
        `<rect x="${c.x}" y="${c.y}" width="${c.w}" height="${c.h}" rx="6" fill="#ffffff" stroke="${C.luarTepi}" stroke-width="1" stroke-dasharray="4 3"/>` +
        bungkus(c.teks, 110)
          .map(
            (b, i) =>
              `<text x="${c.x + 14}" y="${c.y + 24 + i * 17}" font-family="${FONT}" font-size="12" fill="${C.sub}">${esc(b)}</text>`
          )
          .join('')
    )
    .join('\n  ')}
</svg>
`;
}

function utama() {
  const tujuan = path.join(__dirname, 'arsitektur-sistem.svg');
  const isi = gambar();
  fs.writeFileSync(tujuan, isi, 'utf8');
  console.log(`ditulis: arsitektur-sistem.svg (${isi.length} byte)`);
}

if (require.main === module) utama();

module.exports = { gambar };
