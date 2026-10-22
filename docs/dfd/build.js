#!/usr/bin/env node
'use strict';

/**
 * Generator DFD (notasi Gane-Sarson) — Sistem Informasi Operasional Kos-Kosan
 * ---------------------------------------------------------------------------
 * Sumber editable = berkas ini. Ubah koordinat / teks / aliran lalu jalankan:
 *     node build.js
 *
 * Menghasilkan:
 *     01-dfd-konteks.svg
 *     02-dfd-level-1.svg
 *
 * Skrip ini juga memeriksa sendiri bahwa tidak ada garis aliran yang menembus
 * kotak entitas / proses / penyimpanan data. Bila ada, proses build gagal.
 */

const fs = require('fs');
const path = require('path');

const OUT = __dirname;

/* ------------------------------------------------------------------ tokens */

const C = {
  bg: '#ffffff',
  ink: '#0f172a',
  sub: '#475569',
  flow: '#334155',
  entityFill: '#eef2ff',
  entityStroke: '#3730a3',
  entityShadow: '#c7d2fe',
  procFill: '#ffffff',
  procStroke: '#1e293b',
  procBand: '#e2e8f0',
  storeFill: '#f8fafc',
  storeStroke: '#475569',
  storeBand: '#e2e8f0',
  legend: '#94a3b8',
};

const FONT = "'Segoe UI', 'Inter', 'Helvetica Neue', Arial, sans-serif";
const STORE_ID_W = 56;

/* ----------------------------------------------------------------- helpers */

const esc = (s) =>
  String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const asLines = (v) => (Array.isArray(v) ? v : [v]);

function head(w, h, title) {
  return [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" font-family="${FONT}">`,
    '<defs>',
    '<marker id="arrow" viewBox="0 0 10 10" refX="9.2" refY="5" markerWidth="6.5" markerHeight="6.5" orient="auto-start-reverse">',
    `<path d="M0,0 L10,5 L0,10 Z" fill="${C.flow}"/>`,
    '</marker>',
    '</defs>',
    `<rect width="${w}" height="${h}" fill="${C.bg}"/>`,
    `<title>${esc(title)}</title>`,
  ].join('\n');
}

const tail = () => '</svg>\n';

function textBlock(cx, cy, lines, size, weight, color, anchor) {
  const arr = asLines(lines);
  const lh = size * 1.32;
  const top = cy - ((arr.length - 1) * lh) / 2;
  return arr
    .map(
      (ln, i) =>
        `<text x="${cx}" y="${top + i * lh}" text-anchor="${anchor || 'middle'}" dominant-baseline="central" font-size="${size}" font-weight="${weight}" fill="${color}">${esc(ln)}</text>`
    )
    .join('\n');
}

function entity(b) {
  const d = 7;
  return [
    `<rect x="${b.x + d}" y="${b.y + d}" width="${b.w}" height="${b.h}" fill="${C.entityShadow}"/>`,
    `<rect x="${b.x}" y="${b.y}" width="${b.w}" height="${b.h}" fill="${C.entityFill}" stroke="${C.entityStroke}" stroke-width="2"/>`,
    textBlock(b.x + b.w / 2, b.y + b.h / 2, b.label, 15, 700, C.ink),
  ].join('\n');
}

function process(b) {
  const r = 14;
  const band = b.band || 34;
  const bandPath = [
    `M${b.x + r} ${b.y}`,
    `H${b.x + b.w - r}`,
    `A${r} ${r} 0 0 1 ${b.x + b.w} ${b.y + r}`,
    `V${b.y + band}`,
    `H${b.x}`,
    `V${b.y + r}`,
    `A${r} ${r} 0 0 1 ${b.x + r} ${b.y}`,
    'Z',
  ].join(' ');
  return [
    `<rect x="${b.x}" y="${b.y}" width="${b.w}" height="${b.h}" rx="${r}" ry="${r}" fill="${C.procFill}" stroke="${C.procStroke}" stroke-width="2"/>`,
    `<path d="${bandPath}" fill="${C.procBand}"/>`,
    `<path d="M${b.x} ${b.y + band} H${b.x + b.w}" stroke="${C.procStroke}" stroke-width="1.2"/>`,
    textBlock(b.x + b.w / 2, b.y + band / 2, b.id, 15, 700, C.ink),
    textBlock(b.x + b.w / 2, b.y + band + (b.h - band) / 2, b.name, 14, 600, C.ink),
  ].join('\n');
}

function store(b) {
  const tx = b.x + STORE_ID_W + 12;
  return [
    `<rect x="${b.x}" y="${b.y}" width="${STORE_ID_W}" height="${b.h}" fill="${C.storeBand}" opacity="0.8"/>`,
    `<rect x="${tx - 8}" y="${b.y + 1}" width="${b.w - STORE_ID_W - 5}" height="${b.h - 2}" fill="${C.storeFill}"/>`,
    `<path d="M${b.x} ${b.y} H${b.x + b.w} M${b.x} ${b.y} V${b.y + b.h} M${b.x} ${b.y + b.h} H${b.x + b.w}" fill="none" stroke="${C.storeStroke}" stroke-width="2"/>`,
    `<line x1="${b.x + STORE_ID_W}" y1="${b.y}" x2="${b.x + STORE_ID_W}" y2="${b.y + b.h}" stroke="${C.storeStroke}" stroke-width="1.4"/>`,
    textBlock(b.x + STORE_ID_W / 2, b.y + b.h / 2, b.id, 14, 700, C.ink),
    textBlock(tx, b.y + b.h / 2, b.name, 13, 500, C.ink, 'start'),
  ].join('\n');
}

function flowLine(f) {
  const d = f.points
    .map((p, i) => `${i === 0 ? 'M' : 'L'}${p[0]} ${p[1]}`)
    .join(' ');
  return `<path d="${d}" fill="none" stroke="${C.flow}" stroke-width="1.6" marker-end="url(#arrow)" stroke-linejoin="round" stroke-linecap="round"/>`;
}

function flowLabel(f, size) {
  if (!f.at) return '';
  size = size || 12.5;
  const arr = asLines(f.label);
  const wMax = Math.max(...arr.map((l) => l.length)) * size * 0.545;
  const w = wMax + 14;
  const h = arr.length * size * 1.34 + 7;
  return [
    `<rect x="${f.at[0] - w / 2}" y="${f.at[1] - h / 2}" width="${w}" height="${h}" rx="4" fill="${C.bg}" opacity="0.96"/>`,
    textBlock(f.at[0], f.at[1], arr, size, 500, C.sub),
  ].join('\n');
}

function title(text, sub) {
  const out = [
    `<text x="60" y="60" font-size="26" font-weight="700" fill="${C.ink}">${esc(text)}</text>`,
  ];
  if (sub) {
    out.push(
      `<text x="60" y="88" font-size="14" font-weight="400" fill="${C.sub}">${esc(sub)}</text>`
    );
  }
  return out.join('\n');
}

/* ---------------------------------------------------------------- validator */

/**
 * Pastikan tidak ada segmen aliran yang memotong badan kotak.
 * Titik awal/akhir yang tepat di tepi kotak dianggap sah.
 */
function assertNoCrossing(boxes, flows, diagram) {
  const EPS = 0.5;
  const inside = (px, py, b) =>
    px > b.x + EPS &&
    px < b.x + b.w - EPS &&
    py > b.y + EPS &&
    py < b.y + b.h - EPS;

  const problems = [];

  for (const f of flows) {
    const tag = asLines(f.label).join(' ');
    for (let i = 0; i < f.points.length - 1; i++) {
      const [x1, y1] = f.points[i];
      const [x2, y2] = f.points[i + 1];
      // titik tengah tiap segmen harus di luar semua kotak
      const samples = 40;
      for (let s = 1; s < samples; s++) {
        const t = s / samples;
        const px = x1 + (x2 - x1) * t;
        const py = y1 + (y2 - y1) * t;
        for (const b of boxes) {
          if (inside(px, py, b)) {
            problems.push(
              `${diagram}: aliran "${tag}" menembus kotak ${b.tag} pada (${Math.round(px)}, ${Math.round(py)})`
            );
          }
        }
      }
    }
  }

  // buang duplikat
  return [...new Set(problems)];
}

/* ==========================================================================
   GAMBAR 1 — DFD KONTEKS (DFD NOL / LEVEL 0)
   ========================================================================== */

function dfdKonteks() {
  const W = 1600;
  const H = 900;

  const E1 = { x: 60, y: 250, w: 250, h: 400, tag: 'PEMILIK KOS', label: ['PEMILIK', 'KOS'] };
  const E2 = { x: 1290, y: 250, w: 250, h: 400, tag: 'PENGHUNI', label: ['PENGHUNI'] };
  const P0 = {
    x: 600,
    y: 210,
    w: 400,
    h: 480,
    id: '0.0',
    tag: 'PROSES 0.0',
    name: ['SISTEM INFORMASI', 'OPERASIONAL', 'KOS-KOSAN'],
    band: 46,
  };

  const LX = 310;
  const RX = 1290;
  const PLX = 600;
  const PRX = 1000;

  const flows = [
    { points: [[LX, 290], [PLX, 290]], label: ['data kamar &', 'tarif sewa'], at: [455, 290] },
    { points: [[LX, 370], [PLX, 370]], label: ['komponen biaya &', 'parameter denda'], at: [455, 370] },
    { points: [[LX, 450], [PLX, 450]], label: ['konfirmasi', 'setoran uang'], at: [455, 450] },
    { points: [[PLX, 530], [LX, 530]], label: ['rekap pendapatan', '& hunian'], at: [455, 530] },
    { points: [[PLX, 610], [LX, 610]], label: ['daftar tunggakan', '& peringatan'], at: [455, 610] },
    { points: [[RX, 290], [PRX, 290]], label: ['pendaftaran sewa'], at: [1145, 290] },
    { points: [[RX, 370], [PRX, 370]], label: ['bukti pembayaran'], at: [1145, 370] },
    { points: [[PRX, 450], [RX, 450]], label: ['konfirmasi sewa', '& kontrak'], at: [1145, 450] },
    { points: [[PRX, 530], [RX, 530]], label: ['tagihan bulanan', '+ denda'], at: [1145, 530] },
    { points: [[PRX, 610], [RX, 610]], label: ['kuitansi &', 'status pelunasan'], at: [1145, 610] },
  ];

  const problems = assertNoCrossing(
    [E1, E2, P0].map((b) => ({ ...b, tag: b.tag })),
    flows,
    'DFD Konteks'
  );

  const notes = [
    `<text x="60" y="812" font-size="12.5" fill="${C.sub}">Notasi Gane-Sarson — entitas eksternal (kotak), proses (persegi sudut tumpul), aliran data (panah berlabel).</text>`,
    `<text x="60" y="836" font-size="12.5" fill="${C.sub}">Proses 0.0 mewakili seluruh sistem sebagai satu simpul. Rincian aliran data: docs/dfd/README.md</text>`,
    `<text x="60" y="860" font-size="12.5" fill="${C.sub}">2 entitas eksternal  ·  10 aliran data utama (5 masuk, 5 keluar)  ·  belum ada penyimpanan data pada level ini.</text>`,
  ].join('\n');

  const svg = [
    head(W, H, 'DFD Konteks (DFD Nol) — Sistem Informasi Operasional Kos-Kosan'),
    title(
      'DFD KONTEKS (DFD NOL / LEVEL 0)',
      'Sistem Informasi Operasional Kos-Kosan  ·  notasi Gane-Sarson'
    ),
    entity(E1),
    entity(E2),
    process(P0),
    flows.map(flowLine).join('\n'),
    flows.map((f) => flowLabel(f)).join('\n'),
    notes,
    tail(),
  ].join('\n');

  return { svg, problems };
}

/* ==========================================================================
   GAMBAR 2 — DFD LEVEL 1
   ========================================================================== */

function dfdLevel1() {
  const W = 2000;
  const H = 1450;

  /* ---- entitas eksternal ---- */
  const E1 = { x: 140, y: 150, w: 240, h: 100, tag: 'PEMILIK KOS', label: ['PEMILIK KOS'] };
  const E2 = { x: 1580, y: 150, w: 240, h: 100, tag: 'PENGHUNI', label: ['PENGHUNI'] };

  /* ---- proses level 1 ---- */
  const P1 = { x: 140, y: 560, w: 240, h: 150, id: '1.0', tag: 'PROSES 1.0', name: ['Kelola Kamar', '& Penghuni'] };
  const P2 = { x: 470, y: 560, w: 240, h: 150, id: '2.0', tag: 'PROSES 2.0', name: ['Kelola Tarif, Biaya', 'Tambahan &', 'Parameter Denda'] };
  const P3 = { x: 800, y: 560, w: 240, h: 150, id: '3.0', tag: 'PROSES 3.0', name: ['Terbitkan Tagihan', 'Bulanan'] };
  const P4 = { x: 1160, y: 560, w: 240, h: 150, id: '4.0', tag: 'PROSES 4.0', name: ['Catat Pembayaran,', 'Denda & Tunggakan'] };
  const P5 = { x: 720, y: 1180, w: 560, h: 120, id: '5.0', tag: 'PROSES 5.0', name: ['Laporan & Rekap'] };

  /* ---- penyimpanan data ---- */
  const D1 = { x: 120, y: 880, w: 230, h: 84, id: 'D1', tag: 'D1 Kamar', name: ['Kamar'] };
  const D2 = { x: 400, y: 880, w: 230, h: 84, id: 'D2', tag: 'D2 Penghuni', name: ['Penghuni & Kontrak'] };
  const D3 = { x: 680, y: 880, w: 230, h: 84, id: 'D3', tag: 'D3 Tarif', name: ['Tarif & Komponen'] };
  const D4 = { x: 960, y: 880, w: 230, h: 84, id: 'D4', tag: 'D4 Tagihan', name: ['Tagihan'] };
  const D5 = { x: 1240, y: 880, w: 230, h: 84, id: 'D5', tag: 'D5 Pembayaran', name: ['Pembayaran'] };
  const D6 = { x: 1560, y: 880, w: 230, h: 84, id: 'D6', tag: 'D6 Log Audit', name: ['Log Audit'] };

  /* ---- aliran data ---- */
  const flows = [
    /* ===== entitas → proses ===== */
    { points: [[240, 250], [240, 560]], label: ['data kamar &', 'tarif sewa'], at: [240, 400] },
    { points: [[340, 250], [340, 310], [590, 310], [590, 560]], label: ['komponen biaya &', 'parameter denda'], at: [465, 310] },
    { points: [[380, 190], [1280, 190], [1280, 560]], label: ['konfirmasi setoran uang'], at: [830, 190] },
    { points: [[1600, 250], [1600, 360], [320, 360], [320, 560]], label: ['pendaftaran sewa'], at: [960, 360] },
    { points: [[1660, 250], [1660, 420], [1330, 420], [1330, 560]], label: ['bukti pembayaran'], at: [1495, 420] },

    /* ===== proses → entitas ===== */
    { points: [[140, 580], [110, 580], [110, 120], [1700, 120], [1700, 150]], label: ['konfirmasi sewa & kontrak'], at: [900, 120] },
    { points: [[880, 560], [880, 330], [1780, 330], [1780, 250]], label: ['tagihan bulanan', '+ denda'], at: [1340, 330] },
    { points: [[1400, 640], [1840, 640], [1840, 210], [1820, 210]], label: ['kuitansi &', 'status pelunasan'], at: [1840, 470] },
    { points: [[720, 1240], [80, 1240], [80, 200], [140, 200]], label: ['rekap pendapatan, hunian', '& daftar tunggakan'], at: [400, 1240] },

    /* ===== proses → penyimpanan ===== */
    { points: [[230, 710], [230, 880]], label: ['data kamar'], at: [230, 795] },
    { points: [[350, 710], [350, 800], [500, 800], [500, 880]], label: ['kontrak sewa'], at: [425, 800] },
    { points: [[640, 710], [640, 800], [780, 800], [780, 880]], label: ['tarif & komponen'], at: [710, 800] },
    { points: [[1000, 710], [1000, 880]], label: ['tagihan'], at: [1000, 795] },
    { points: [[1280, 710], [1280, 880]], label: ['pembayaran'], at: [1280, 795] },
    { points: [[1400, 680], [1620, 680], [1620, 880]], label: ['log audit'], at: [1620, 780] },

    /* ===== penyimpanan → proses ===== */
    { points: [[560, 880], [560, 760], [860, 760], [860, 710]], label: ['kontrak aktif'], at: [710, 760] },
    { points: [[840, 880], [840, 740], [960, 740], [960, 710]], label: ['tarif berlaku'], at: [900, 740] },
    { points: [[1100, 880], [1100, 760], [1240, 760], [1240, 710]], label: ['tagihan belum lunas'], at: [1170, 760] },

    /* ===== penyimpanan → proses 5.0 ===== */
    { points: [[200, 964], [200, 1100], [800, 1100], [800, 1180]], label: ['data kamar'], at: [500, 1100] },
    { points: [[450, 964], [450, 1060], [880, 1060], [880, 1180]], label: ['kontrak'], at: [665, 1060] },
    { points: [[1050, 964], [1050, 1180]], label: ['tagihan'], at: [1050, 1072] },
    { points: [[1330, 964], [1330, 1080], [1240, 1080], [1240, 1180]], label: ['pembayaran'], at: [1287, 1080] },
  ];

  const boxes = [E1, E2, P1, P2, P3, P4, P5, D1, D2, D3, D4, D5, D6].map((b) => ({
    ...b,
    tag: b.tag,
  }));

  const problems = assertNoCrossing(boxes, flows, 'DFD Level 1');

  /* ---- legenda ---- */
  const lx = 1440;
  const ly = 1250;
  const legend = [
    `<rect x="${lx}" y="${ly}" width="${520}" height="118" rx="10" fill="#fafafa" stroke="${C.legend}" stroke-width="1"/>`,
    `<text x="${lx + 18}" y="${ly + 26}" font-size="12.5" font-weight="700" fill="${C.ink}">KETERANGAN SIMBOL</text>`,
    `<rect x="${lx + 18}" y="${ly + 40}" width="72" height="26" fill="${C.entityFill}" stroke="${C.entityStroke}" stroke-width="2"/>`,
    `<text x="${lx + 102}" y="${ly + 53}" font-size="12" fill="${C.sub}" dominant-baseline="central">Entitas eksternal</text>`,
    `<rect x="${lx + 18}" y="${ly + 74}" width="72" height="26" rx="8" fill="${C.procFill}" stroke="${C.procStroke}" stroke-width="2"/>`,
    `<path d="M${lx + 18} ${ly + 85} H${lx + 90}" stroke="${C.procStroke}" stroke-width="1.2"/>`,
    `<text x="${lx + 102}" y="${ly + 87}" font-size="12" fill="${C.sub}" dominant-baseline="central">Proses</text>`,
    `<path d="M${lx + 280} ${ly + 40} H${lx + 352} M${lx + 280} ${ly + 40} V${ly + 66} M${lx + 280} ${ly + 66} H${lx + 352}" fill="none" stroke="${C.storeStroke}" stroke-width="2"/>`,
    `<line x1="${lx + 306}" y1="${ly + 40}" x2="${lx + 306}" y2="${ly + 66}" stroke="${C.storeStroke}" stroke-width="1.4"/>`,
    `<text x="${lx + 364}" y="${ly + 53}" font-size="12" fill="${C.sub}" dominant-baseline="central">Penyimpanan data</text>`,
    `<path d="M${lx + 280} ${ly + 87} H${lx + 344}" fill="none" stroke="${C.flow}" stroke-width="1.6" marker-end="url(#arrow)"/>`,
    `<text x="${lx + 364}" y="${ly + 87}" font-size="12" fill="${C.sub}" dominant-baseline="central">Aliran data</text>`,
  ].join('\n');

  const notes = [
    `<text x="60" y="1300" font-size="12.5" fill="${C.sub}">5 proses level 1  ·  6 penyimpanan data  ·  2 entitas eksternal  ·  23 aliran data</text>`,
    `<text x="60" y="1326" font-size="12.5" fill="${C.sub}">Rincian tiap aliran (asal, tujuan, struktur data): docs/dfd/README.md</text>`,
    `<text x="60" y="1352" font-size="12.5" fill="${C.sub}">Dekomposisi proses 3.0 dan 4.0 ke level 2: docs/dfd/03-dfd-level-2.md</text>`,
  ].join('\n');

  const svg = [
    head(W, H, 'DFD Level 1 — Sistem Informasi Operasional Kos-Kosan'),
    title(
      'DFD LEVEL 1',
      'Sistem Informasi Operasional Kos-Kosan  ·  5 proses  ·  6 penyimpanan data  ·  notasi Gane-Sarson'
    ),
    entity(E1),
    entity(E2),
    process(P1),
    process(P2),
    process(P3),
    process(P4),
    process(P5),
    store(D1),
    store(D2),
    store(D3),
    store(D4),
    store(D5),
    store(D6),
    flows.map(flowLine).join('\n'),
    flows.map((f) => flowLabel(f)).join('\n'),
    legend,
    notes,
    tail(),
  ].join('\n');

  return { svg, problems };
}

/* ------------------------------------------------------------------- output */

const results = [
  ['01-dfd-konteks.svg', dfdKonteks()],
  ['02-dfd-level-1.svg', dfdLevel1()],
];

let failed = false;

for (const [name, { svg, problems }] of results) {
  fs.writeFileSync(path.join(OUT, name), svg, 'utf8');
  console.log('ditulis:', name, `(${svg.length} byte)`);
  if (problems.length) {
    failed = true;
    console.error(`  ${problems.length} masalah tata letak:`);
    for (const p of problems) console.error('   -', p);
  } else {
    console.log('  tata letak: OK (tidak ada garis menembus kotak)');
  }
}

if (failed) process.exitCode = 1;
