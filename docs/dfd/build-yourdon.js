#!/usr/bin/env node
'use strict';

/**
 * Generator DFD notasi YOURDON / DeMARCO — Sistem Informasi Operasional Kos-Kosan
 * ---------------------------------------------------------------------------
 * Data yang dimodelkan identik dengan versi Gane-Sarson (build.js).
 * Yang berbeda hanya simbol dan konvensi penomoran:
 *
 *   unsur            Gane-Sarson              Yourdon / DeMarco
 *   ---------------  -----------------------  --------------------------
 *   entitas          kotak                    kotak (sama)
 *   proses           persegi sudut tumpul     LINGKARAN
 *   penyimpanan      kotak terbuka kanan      DUA GARIS SEJAJAR
 *   nomor proses     1.0, 2.0, ...            1, 2, ...
 *   nomor konteks    0.0                      0
 *
 * Jalankan:  node build-yourdon.js
 *
 * Menghasilkan:
 *     01-dfd-konteks-yourdon.svg
 *     02-dfd-level-1-yourdon.svg
 *
 * Validator bawaan memastikan tidak ada aliran yang menembus kotak maupun
 * lingkaran proses. Build gagal bila ada pelanggaran.
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
  procBand: '#f1f5f9',
  storeStroke: '#475569',
  storeFill: '#f8fafc',
  legend: '#94a3b8',
};

const FONT = "'Segoe UI', 'Inter', 'Helvetica Neue', Arial, sans-serif";
const STORE_ID_W = 52;

const rad = (deg) => (deg * Math.PI) / 180;

/** Titik pada lingkaran. 0° = kanan, 90° = bawah, 180° = kiri, 270° = atas. */
const onCircle = (c, deg) => [
  c.cx + c.r * Math.cos(rad(deg)),
  c.cy + c.r * Math.sin(rad(deg)),
];

/* ----------------------------------------------------------------- helpers */

const esc = (s) =>
  String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const asLines = (v) => (Array.isArray(v) ? v : [v]);

const r1 = (n) => Math.round(n * 10) / 10;

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
  const lh = size * 1.3;
  const top = cy - ((arr.length - 1) * lh) / 2;
  return arr
    .map(
      (ln, i) =>
        `<text x="${cx}" y="${r1(top + i * lh)}" text-anchor="${anchor || 'middle'}" dominant-baseline="central" font-size="${size}" font-weight="${weight}" fill="${color}">${esc(ln)}</text>`
    )
    .join('\n');
}

/** Entitas eksternal — kotak dengan bayangan. */
function entity(b) {
  const d = 7;
  return [
    `<rect x="${b.x + d}" y="${b.y + d}" width="${b.w}" height="${b.h}" fill="${C.entityShadow}"/>`,
    `<rect x="${b.x}" y="${b.y}" width="${b.w}" height="${b.h}" fill="${C.entityFill}" stroke="${C.entityStroke}" stroke-width="2"/>`,
    textBlock(b.x + b.w / 2, b.y + b.h / 2, b.label, 15, 700, C.ink),
  ].join('\n');
}

/** Proses Yourdon — LINGKARAN, nomor di atas, nama di bawah. */
function circleProcess(c) {
  const numY = c.cy - c.r * 0.42;
  const nameY = c.cy + c.r * 0.16;
  return [
    `<circle cx="${c.cx}" cy="${c.cy}" r="${c.r}" fill="${C.procFill}" stroke="${C.procStroke}" stroke-width="2"/>`,
    textBlock(c.cx, numY, c.id, 15, 700, C.ink),
    textBlock(c.cx, nameY, c.name, c.nameSize || 11.5, 600, C.ink),
  ].join('\n');
}

/** Penyimpanan Yourdon — DUA GARIS SEJAJAR, terbuka di kedua ujung. */
function store(b) {
  const yTop = b.y;
  const yBot = b.y + b.h;
  const xDiv = b.x + STORE_ID_W;
  return [
    // area nama diberi latar sangat tipis agar terbaca
    `<rect x="${b.x}" y="${yTop + 1}" width="${b.w}" height="${b.h - 2}" fill="${C.storeFill}"/>`,
    // dua garis sejajar
    `<line x1="${b.x}" y1="${yTop}" x2="${b.x + b.w}" y2="${yTop}" stroke="${C.storeStroke}" stroke-width="2"/>`,
    `<line x1="${b.x}" y1="${yBot}" x2="${b.x + b.w}" y2="${yBot}" stroke="${C.storeStroke}" stroke-width="2"/>`,
    // pemisah id | nama
    `<line x1="${xDiv}" y1="${yTop}" x2="${xDiv}" y2="${yBot}" stroke="${C.storeStroke}" stroke-width="1.4"/>`,
    textBlock(b.x + STORE_ID_W / 2, (yTop + yBot) / 2, b.id, 13, 700, C.ink),
    textBlock(xDiv + 10, (yTop + yBot) / 2, b.name, 12.5, 500, C.ink, 'start'),
  ].join('\n');
}

function flowLine(f) {
  const d = f.points
    .map((p, i) => `${i === 0 ? 'M' : 'L'}${r1(p[0])} ${r1(p[1])}`)
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
    `<rect x="${r1(f.at[0] - w / 2)}" y="${r1(f.at[1] - h / 2)}" width="${r1(w)}" height="${r1(h)}" rx="4" fill="${C.bg}" opacity="0.96"/>`,
    textBlock(f.at[0], f.at[1], arr, size, 500, C.sub),
  ].join('\n');
}

function title(text, sub) {
  return [
    `<text x="60" y="52" font-size="26" font-weight="700" fill="${C.ink}">${esc(text)}</text>`,
    `<text x="60" y="78" font-size="14" font-weight="400" fill="${C.sub}">${esc(sub)}</text>`,
  ].join('\n');
}

/* ---------------------------------------------------------------- validator */

/** Jarak titik ke segmen garis. */
function distPointSeg(px, py, x1, y1, x2, y2) {
  const dx = x2 - x1;
  const dy = y2 - y1;
  const len2 = dx * dx + dy * dy;
  if (len2 === 0) return Math.hypot(px - x1, py - y1);
  let t = ((px - x1) * dx + (py - y1) * dy) / len2;
  t = Math.max(0, Math.min(1, t));
  return Math.hypot(px - (x1 + t * dx), py - (y1 + t * dy));
}

function assertNoCrossing(rects, circles, flows, diagram) {
  const EPS = 1.2;
  const problems = [];

  const insideRect = (px, py, b) =>
    px > b.x + EPS && px < b.x + b.w - EPS && py > b.y + EPS && py < b.y + b.h - EPS;

  for (const f of flows) {
    const tag = asLines(f.label).join(' ');
    for (let i = 0; i < f.points.length - 1; i++) {
      const [x1, y1] = f.points[i];
      const [x2, y2] = f.points[i + 1];
      const N = 60;
      for (let s = 1; s < N; s++) {
        const t = s / N;
        const px = x1 + (x2 - x1) * t;
        const py = y1 + (y2 - y1) * t;
        for (const b of rects) {
          if (insideRect(px, py, b)) {
            problems.push(
              `${diagram}: "${tag}" menembus kotak ${b.tag} di (${Math.round(px)}, ${Math.round(py)})`
            );
          }
        }
        for (const c of circles) {
          if (Math.hypot(px - c.cx, py - c.cy) < c.r - EPS) {
            problems.push(
              `${diagram}: "${tag}" menembus lingkaran ${c.tag} di (${Math.round(px)}, ${Math.round(py)})`
            );
          }
        }
      }
    }
  }

  // juga pastikan setiap titik akhir aliran menyentuh sesuatu (kotak/lingkaran)
  // atau minimal berada di area gambar — tidak wajib, jadi dilewati.

  return [...new Set(problems)];
}

/* ==========================================================================
   GAMBAR 1 — DFD KONTEKS (YOURDON)
   ========================================================================== */

function dfdKonteks() {
  const W = 1600;
  const H = 680;

  const SYS = { cx: 800, cy: 330, r: 135, tag: 'PROSES 0', id: '0' };

  const E1 = { x: 80, y: 205, w: 240, h: 230, tag: 'PEMILIK KOS', label: ['PEMILIK', 'KOS'] };
  const E2 = { x: 1280, y: 205, w: 240, h: 230, tag: 'PENGHUNI', label: ['PENGHUNI'] };

  /** Titik pada lingkaran untuk aliran horizontal pada ketinggian y. */
  const edgeAtY = (y) => {
    const dy = y - SYS.cy;
    const dx = Math.sqrt(Math.max(0, SYS.r * SYS.r - dy * dy));
    return dx;
  };

  const ys = [230, 280, 330, 380, 430];

  const left = [
    { label: ['data kamar &', 'tarif sewa'], dir: 'in' },
    { label: ['komponen biaya &', 'parameter denda'], dir: 'in' },
    { label: ['konfirmasi', 'setoran uang'], dir: 'in' },
    { label: ['rekap pendapatan', '& hunian'], dir: 'out' },
    { label: ['daftar tunggakan', '& peringatan'], dir: 'out' },
  ];

  const right = [
    { label: ['pendaftaran sewa'], dir: 'in' },
    { label: ['bukti pembayaran'], dir: 'in' },
    { label: ['konfirmasi sewa', '& kontrak'], dir: 'out' },
    { label: ['tagihan bulanan', '+ denda'], dir: 'out' },
    { label: ['kuitansi &', 'status pelunasan'], dir: 'out' },
  ];

  const flows = [];

  left.forEach((f, i) => {
    const y = ys[i];
    const dx = edgeAtY(y);
    const xStart = E1.x + E1.w; // 320
    const xEdge = SYS.cx - dx;
    const midX = (xStart + xEdge) / 2;
    flows.push({
      points: f.dir === 'in' ? [[xStart, y], [xEdge, y]] : [[xEdge, y], [xStart, y]],
      label: f.label,
      at: [midX, y],
    });
  });

  right.forEach((f, i) => {
    const y = ys[i];
    const dx = edgeAtY(y);
    const xStart = E2.x; // 1280
    const xEdge = SYS.cx + dx;
    const midX = (xStart + xEdge) / 2;
    flows.push({
      points: f.dir === 'in' ? [[xStart, y], [xEdge, y]] : [[xEdge, y], [xStart, y]],
      label: f.label,
      at: [midX, y],
    });
  });

  const problems = assertNoCrossing([E1, E2], [SYS], flows, 'DFD Konteks (Yourdon)');

  const notes = [
    `<text x="80" y="560" font-size="12.5" fill="${C.sub}">Notasi Yourdon / DeMarco — entitas eksternal (kotak), proses (lingkaran), aliran data (panah berlabel).</text>`,
    `<text x="80" y="584" font-size="12.5" fill="${C.sub}">Proses 0 mewakili seluruh sistem sebagai satu simpul. Rincian aliran data: docs/dfd/README.md</text>`,
    `<text x="80" y="608" font-size="12.5" fill="${C.sub}">2 entitas eksternal  ·  10 aliran data utama (5 masuk, 5 keluar)  ·  belum ada penyimpanan data pada level ini.</text>`,
  ].join('\n');

  const svg = [
    head(W, H, 'DFD Konteks (DFD Nol) notasi Yourdon — Sistem Informasi Operasional Kos-Kosan'),
    title(
      'DFD KONTEKS (DFD NOL / LEVEL 0) — NOTASI YOURDON / DeMARCO',
      'Sistem Informasi Operasional Kos-Kosan  ·  proses digambar sebagai lingkaran'
    ),
    entity(E1),
    entity(E2),
    `<circle cx="${SYS.cx}" cy="${SYS.cy}" r="${SYS.r}" fill="${C.procFill}" stroke="${C.procStroke}" stroke-width="2"/>`,
    textBlock(SYS.cx, SYS.cy - 62, '0', 16, 700, C.ink),
    textBlock(SYS.cx, SYS.cy + 6, ['SISTEM INFORMASI', 'OPERASIONAL', 'KOS-KOSAN'], 13.5, 600, C.ink),
    flows.map(flowLine).join('\n'),
    flows.map((f) => flowLabel(f)).join('\n'),
    notes,
    tail(),
  ].join('\n');

  return { svg, problems };
}

/* ==========================================================================
   GAMBAR 2 — DFD LEVEL 1 (YOURDON)
   ========================================================================== */

function dfdLevel1() {
  const W = 2000;
  const H = 1400;

  const R = 76;

  /* ---- entitas eksternal ---- */
  const E1 = { x: 130, y: 180, w: 240, h: 100, tag: 'PEMILIK KOS', label: ['PEMILIK KOS'] };
  const E2 = { x: 1600, y: 180, w: 240, h: 100, tag: 'PENGHUNI', label: ['PENGHUNI'] };

  /* ---- proses (lingkaran) ---- */
  const C1 = { cx: 250, cy: 520, r: R, id: '1', tag: 'PROSES 1', name: ['Kelola Kamar', '& Penghuni'] };
  const C2 = { cx: 560, cy: 520, r: R, id: '2', tag: 'PROSES 2', name: ['Kelola Tarif,', 'Biaya &', 'Parameter Denda'], nameSize: 11 };
  const C3 = { cx: 870, cy: 520, r: R, id: '3', tag: 'PROSES 3', name: ['Terbitkan', 'Tagihan', 'Bulanan'] };
  const C4 = { cx: 1200, cy: 520, r: R, id: '4', tag: 'PROSES 4', name: ['Catat', 'Pembayaran,', 'Denda &', 'Tunggakan'], nameSize: 10.5 };
  const C5 = { cx: 760, cy: 1140, r: R, id: '5', tag: 'PROSES 5', name: ['Laporan', '& Rekap'] };

  /* ---- penyimpanan data (dua garis sejajar) ---- */
  const D1 = { x: 150, y: 800, w: 200, h: 58, id: 'D1', tag: 'D1 Kamar', name: ['Kamar'] };
  const D2 = { x: 390, y: 800, w: 200, h: 58, id: 'D2', tag: 'D2 Penghuni', name: ['Penghuni &', 'Kontrak'] };
  const D3 = { x: 660, y: 800, w: 200, h: 58, id: 'D3', tag: 'D3 Tarif', name: ['Tarif &', 'Komponen'] };
  const D4 = { x: 920, y: 800, w: 200, h: 58, id: 'D4', tag: 'D4 Tagihan', name: ['Tagihan'] };
  const D5 = { x: 1170, y: 800, w: 200, h: 58, id: 'D5', tag: 'D5 Pembayaran', name: ['Pembayaran'] };
  const D6 = { x: 1460, y: 800, w: 200, h: 58, id: 'D6', tag: 'D6 Log Audit', name: ['Log Audit'] };

  /* ---- aliran ---- */
  const flows = [
    /* ===== entitas → proses ===== */
    // data kamar & tarif sewa : Pemilik → 1
    { points: [[250, 280], onCircle(C1, 270)], label: ['data kamar &', 'tarif sewa'], at: [250, 360] },
    // komponen biaya & parameter denda : Pemilik → 2
    { points: [[330, 280], [330, 340], [560, 340], onCircle(C2, 270)], label: ['komponen biaya &', 'parameter denda'], at: [445, 340] },
    // konfirmasi setoran uang : Pemilik → 4
    { points: [[370, 230], [392, 230], [392, 130], [1200, 130], onCircle(C4, 270)], label: ['konfirmasi setoran uang'], at: [700, 130] },
    // pendaftaran sewa : Penghuni → 1
    { points: [[1600, 210], [1520, 210], [1520, 105], [90, 105], [90, 520], onCircle(C1, 180)], label: ['pendaftaran sewa'], at: [360, 105] },
    // bukti pembayaran : Penghuni → 4
    { points: [[1650, 280], [1650, 420], [1276, 420], onCircle(C4, 0)], label: ['bukti pembayaran'], at: [1463, 420] },

    /* ===== proses → entitas ===== */
    // konfirmasi sewa & kontrak : 1 → Penghuni
    { points: [onCircle(C1, 135), [196, 620], [112, 620], [112, 155], [1700, 155], [1700, 180]], label: ['konfirmasi sewa & kontrak'], at: [1250, 155] },
    // tagihan bulanan + denda : 3 → Penghuni
    { points: [onCircle(C3, 270), [870, 310], [1780, 310], [1780, 280]], label: ['tagihan bulanan', '+ denda'], at: [1330, 310] },
    // kuitansi & status pelunasan : 4 → Penghuni
    { points: [onCircle(C4, 20), [1860, 546], [1860, 235], [1840, 235]], label: ['kuitansi &', 'status pelunasan'], at: [1860, 400] },
    // rekap pendapatan & tunggakan : 5 → Pemilik
    { points: [onCircle(C5, 180), [45, 1140], [45, 235], [130, 235]], label: ['rekap pendapatan, hunian', '& daftar tunggakan'], at: [420, 1140] },

    /* ===== proses → penyimpanan ===== */
    // 1 → D1
    { points: [onCircle(C1, 90), [250, 800]], label: ['data kamar'], at: [250, 700] },
    // 1 → D2
    { points: [onCircle(C1, 45), [304, 660], [490, 660], [490, 800]], label: ['kontrak sewa'], at: [397, 660] },
    // 2 → D3
    { points: [onCircle(C2, 90), [560, 700], [760, 700], [760, 800]], label: ['tarif & komponen'], at: [660, 700] },
    // 3 → D4
    { points: [onCircle(C3, 90), [870, 680], [1020, 680], [1020, 800]], label: ['tagihan'], at: [945, 680] },
    // 4 → D5
    { points: [onCircle(C4, 90), [1200, 700], [1270, 700], [1270, 800]], label: ['pembayaran'], at: [1235, 700] },
    // 4 → D6
    { points: [onCircle(C4, 70), [1226, 620], [1560, 620], [1560, 800]], label: ['log audit'], at: [1420, 620] },

    /* ===== penyimpanan → proses ===== */
    // D2 → 3 (kontrak aktif)
    { points: [[490, 800], [490, 740], [794, 740], onCircle(C3, 180)], label: ['kontrak aktif'], at: [642, 740] },
    // D3 → 3 (tarif berlaku)
    { points: [[760, 800], [760, 770], [832, 770], onCircle(C3, 120)], label: ['tarif berlaku'], at: [796, 770] },
    // D4 → 4 (tagihan belum lunas)
    { points: [[1020, 800], [1020, 740], [1124, 740], onCircle(C4, 180)], label: ['tagihan belum lunas'], at: [1072, 740] },

    /* ===== penyimpanan → proses 5 ===== */
    { points: [[250, 858], [250, 1040], [688.6, 1040], onCircle(C5, 200)], label: ['data kamar'], at: [470, 1040] },
    { points: [[490, 858], [490, 1000], [734, 1000], onCircle(C5, 250)], label: ['kontrak'], at: [612, 1000] },
    { points: [[1020, 858], [1020, 980], [786, 980], onCircle(C5, 290)], label: ['tagihan'], at: [903, 980] },
    { points: [[1270, 858], [1270, 1020], [831.4, 1020], onCircle(C5, 340)], label: ['pembayaran'], at: [1050, 1020] },
  ];

  const problems = assertNoCrossing(
    [E1, E2, D1, D2, D3, D4, D5, D6],
    [C1, C2, C3, C4, C5],
    flows,
    'DFD Level 1 (Yourdon)'
  );

  /* ---- legenda ---- */
  const lx = 1360;
  const ly = 1120;
  const legend = [
    `<rect x="${lx}" y="${ly}" width="580" height="180" rx="10" fill="#fafafa" stroke="${C.legend}" stroke-width="1"/>`,
    `<text x="${lx + 18}" y="${ly + 26}" font-size="12.5" font-weight="700" fill="${C.ink}">KETERANGAN SIMBOL (YOURDON / DeMARCO)</text>`,
    // entitas
    `<rect x="${lx + 20}" y="${ly + 42}" width="66" height="26" fill="${C.entityFill}" stroke="${C.entityStroke}" stroke-width="2"/>`,
    `<text x="${lx + 100}" y="${ly + 55}" font-size="12" fill="${C.sub}" dominant-baseline="central">Entitas eksternal</text>`,
    // proses (lingkaran)
    `<circle cx="${lx + 53}" cy="${ly + 92}" r="15" fill="${C.procFill}" stroke="${C.procStroke}" stroke-width="2"/>`,
    `<text x="${lx + 100}" y="${ly + 92}" font-size="12" fill="${C.sub}" dominant-baseline="central">Proses (nomor + nama)</text>`,
    // store (dua garis)
    `<line x1="${lx + 20}" y1="${ly + 122}" x2="${lx + 86}" y2="${ly + 122}" stroke="${C.storeStroke}" stroke-width="2"/>`,
    `<line x1="${lx + 20}" y1="${ly + 148}" x2="${lx + 86}" y2="${ly + 148}" stroke="${C.storeStroke}" stroke-width="2"/>`,
    `<line x1="${lx + 45}" y1="${ly + 122}" x2="${lx + 45}" y2="${ly + 148}" stroke="${C.storeStroke}" stroke-width="1.4"/>`,
    `<text x="${lx + 100}" y="${ly + 135}" font-size="12" fill="${C.sub}" dominant-baseline="central">Penyimpanan data (dua garis sejajar)</text>`,
    // aliran
    `<path d="M${lx + 20} ${ly + 166} H${lx + 82}" fill="none" stroke="${C.flow}" stroke-width="1.6" marker-end="url(#arrow)"/>`,
    `<text x="${lx + 100}" y="${ly + 166}" font-size="12" fill="${C.sub}" dominant-baseline="central">Aliran data</text>`,
  ].join('\n');

  const notes = [
    `<text x="60" y="1266" font-size="12.5" fill="${C.sub}">Keterangan proses: 1 Kelola Kamar &amp; Penghuni · 2 Kelola Tarif, Biaya Tambahan &amp; Parameter Denda</text>`,
    `<text x="60" y="1292" font-size="12.5" fill="${C.sub}">3 Terbitkan Tagihan Bulanan · 4 Catat Pembayaran, Denda &amp; Tunggakan · 5 Laporan &amp; Rekap</text>`,
    `<text x="60" y="1318" font-size="12.5" fill="${C.sub}">5 proses · 6 penyimpanan data · 2 entitas eksternal · 23 aliran data · dekomposisi level 2: docs/dfd/03-dfd-level-2.md</text>`,
  ].join('\n');

  const svg = [
    head(W, H, 'DFD Level 1 notasi Yourdon — Sistem Informasi Operasional Kos-Kosan'),
    title(
      'DFD LEVEL 1 — NOTASI YOURDON / DeMARCO',
      'Sistem Informasi Operasional Kos-Kosan  ·  5 proses (lingkaran)  ·  6 penyimpanan data (dua garis sejajar)'
    ),
    entity(E1),
    entity(E2),
    circleProcess(C1),
    circleProcess(C2),
    circleProcess(C3),
    circleProcess(C4),
    circleProcess(C5),
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
  ['01-dfd-konteks-yourdon.svg', dfdKonteks()],
  ['02-dfd-level-1-yourdon.svg', dfdLevel1()],
];

let failed = false;

for (const [name, { svg, problems }] of results) {
  fs.writeFileSync(path.join(OUT, name), svg, 'utf8');
  console.log('ditulis:', name, `(${svg.length} byte)`);
  if (problems.length) {
    failed = true;
    console.error(`  ${problems.length} masalah tata letak:`);
    for (const p of problems.slice(0, 12)) console.error('   -', p);
  } else {
    console.log('  tata letak: OK (tidak ada garis menembus kotak/lingkaran)');
  }
}

if (failed) process.exitCode = 1;
