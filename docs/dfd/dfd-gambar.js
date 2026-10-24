'use strict';

/**
 * Penyaji gambar DFD — dipakai bersama oleh build.js (Gane-Sarson) dan
 * build-yourdon.js (Yourdon/DeMarco).
 * -------------------------------------------------------------------------
 * Berkas ini hanya tahu cara MENGGAMBAR. Seluruh isi diagram dibaca dari
 * `dfd-data.js`, sehingga kedua notasi mustahil menampilkan aliran yang
 * berbeda: yang berbeda hanyalah bentuk simbol dan konvensi penomoran.
 *
 * Selain menggambar, berkas ini memeriksa empat hal yang tidak bisa diperiksa
 * dengan mata pada gambar sebesar ini:
 *
 *   1. tidak ada garis aliran yang menembus kotak / lingkaran;
 *   2. setiap penyimpanan data ditulis DAN dibaca (bukan black hole / miracle);
 *   3. setiap proses punya masukan dan keluaran (bukan black hole / grey hole);
 *   4. setiap aliran batas sistem punya rute di kedua tingkat, sehingga
 *      jumlah aliran level 0 dan level 1 tidak mungkin berbeda.
 *
 * Pemeriksaan 2-4 itulah yang sebenarnya hilang sebelumnya: cacat DFD yang
 * dilaporkan bukan kesalahan menggambar, melainkan tidak adanya penjaga.
 */

const D = require('./dfd-data');

const FONT = "'Segoe UI', 'Inter', 'Helvetica Neue', Arial, sans-serif";
const STORE_ID_W = 56;

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

/* ------------------------------------------------------------- utilitas */

const esc = (s) =>
  String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const asLines = (v) => (Array.isArray(v) ? v : [v]);

const r1 = (n) => Math.round(n * 10) / 10;

function head(w, h, judul) {
  return [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" font-family="${FONT}">`,
    '<defs>',
    '<marker id="arrow" viewBox="0 0 10 10" refX="9.2" refY="5" markerWidth="6.5" markerHeight="6.5" orient="auto-start-reverse">',
    `<path d="M0,0 L10,5 L0,10 Z" fill="${C.flow}"/>`,
    '</marker>',
    '</defs>',
    `<rect width="${w}" height="${h}" fill="${C.bg}"/>`,
    `<title>${esc(judul)}</title>`,
  ].join('\n');
}

const tail = () => '</svg>\n';

function textBlock(cx, cy, lines, size, weight, color, anchor) {
  const arr = asLines(lines);
  const lh = size * 1.32;
  const atas = cy - ((arr.length - 1) * lh) / 2;
  return arr
    .map(
      (ln, i) =>
        `<text x="${r1(cx)}" y="${r1(atas + i * lh)}" text-anchor="${anchor || 'middle'}" dominant-baseline="central" font-size="${size}" font-weight="${weight}" fill="${color}">${esc(ln)}</text>`
    )
    .join('\n');
}

function judul(text, sub) {
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

/* ---------------------------------------------------------------- simbol */

function entitas(b) {
  const d = 7;
  return [
    `<rect x="${b.x + d}" y="${b.y + d}" width="${b.w}" height="${b.h}" fill="${C.entityShadow}"/>`,
    `<rect x="${b.x}" y="${b.y}" width="${b.w}" height="${b.h}" fill="${C.entityFill}" stroke="${C.entityStroke}" stroke-width="2"/>`,
    textBlock(b.x + b.w / 2, b.y + b.h / 2, b.label, 15, 700, C.ink),
  ].join('\n');
}

/** Proses Gane-Sarson — persegi sudut tumpul dengan pita nomor di atas. */
function prosesGane(b) {
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
    textBlock(b.x + b.w / 2, b.y + band + (b.h - band) / 2, b.nama, b.ukuranNama || 14, 600, C.ink),
  ].join('\n');
}

/**
 * Proses Yourdon — ELIPS, bukan lingkaran sempurna.
 *
 * Yourdon/DeMarco aslinya memakai lingkaran. Elips dipakai di sini karena nama
 * proses kos-kosan tidak muat di dalam lingkaran seukuran apa pun yang masih
 * menyisakan ruang untuk panah: memaksakan lingkaran berarti mengecilkan huruf
 * sampai tidak terbaca, atau memendekkan nama sampai kehilangan arti. Elips
 * tetap membedakan proses dari entitas (kotak) dan penyimpanan (dua garis),
 * yaitu satu-satunya hal yang penting dari bentuknya.
 *
 * Konsekuensinya disebutkan di README bagian notasi, supaya tidak terlihat
 * seperti ketidaktahuan.
 */
function prosesYourdon(l) {
  const numY = l.cy - l.ry * 0.40;
  const nameY = l.cy + l.ry * 0.22;
  return [
    `<ellipse cx="${l.cx}" cy="${l.cy}" rx="${l.rx}" ry="${l.ry}" fill="${C.procFill}" stroke="${C.procStroke}" stroke-width="2"/>`,
    textBlock(l.cx, numY, l.id, 15, 700, C.ink),
    textBlock(l.cx, nameY, l.nama, l.ukuranNama || 11.5, 600, C.ink),
  ].join('\n');
}

/** Penyimpanan Gane-Sarson — kotak terbuka di sisi kanan. */
function storeGane(b) {
  const tx = b.x + STORE_ID_W + 12;
  return [
    `<rect x="${b.x}" y="${b.y}" width="${STORE_ID_W}" height="${b.h}" fill="${C.storeBand}" opacity="0.8"/>`,
    `<rect x="${tx - 8}" y="${b.y + 1}" width="${b.w - STORE_ID_W - 5}" height="${b.h - 2}" fill="${C.storeFill}"/>`,
    `<path d="M${b.x} ${b.y} H${b.x + b.w} M${b.x} ${b.y} V${b.y + b.h} M${b.x} ${b.y + b.h} H${b.x + b.w}" fill="none" stroke="${C.storeStroke}" stroke-width="2"/>`,
    `<line x1="${b.x + STORE_ID_W}" y1="${b.y}" x2="${b.x + STORE_ID_W}" y2="${b.y + b.h}" stroke="${C.storeStroke}" stroke-width="1.4"/>`,
    textBlock(b.x + STORE_ID_W / 2, b.y + b.h / 2, b.id, 14, 700, C.ink),
    textBlock(tx, b.y + b.h / 2, b.nama, 13, 500, C.ink, 'start'),
  ].join('\n');
}

/** Penyimpanan Yourdon — dua garis sejajar, terbuka di kedua ujung. */
function storeYourdon(b) {
  const yTop = b.y;
  const yBot = b.y + b.h;
  const xDiv = b.x + STORE_ID_W;
  return [
    `<rect x="${b.x}" y="${yTop + 1}" width="${b.w}" height="${b.h - 2}" fill="${C.storeFill}"/>`,
    `<line x1="${b.x}" y1="${yTop}" x2="${b.x + b.w}" y2="${yTop}" stroke="${C.storeStroke}" stroke-width="2"/>`,
    `<line x1="${b.x}" y1="${yBot}" x2="${b.x + b.w}" y2="${yBot}" stroke="${C.storeStroke}" stroke-width="2"/>`,
    `<line x1="${xDiv}" y1="${yTop}" x2="${xDiv}" y2="${yBot}" stroke="${C.storeStroke}" stroke-width="1.4"/>`,
    textBlock(b.x + STORE_ID_W / 2, (yTop + yBot) / 2, b.id, 13, 700, C.ink),
    textBlock(xDiv + 10, (yTop + yBot) / 2, b.nama, 12.5, 500, C.ink, 'start'),
  ].join('\n');
}

function garisAliran(titik) {
  const d = titik.map((p, i) => `${i === 0 ? 'M' : 'L'}${r1(p[0])} ${r1(p[1])}`).join(' ');
  return `<path d="${d}" fill="none" stroke="${C.flow}" stroke-width="1.6" marker-end="url(#arrow)" stroke-linejoin="round" stroke-linecap="round"/>`;
}

function labelAliran(rute, label, ukuran) {
  if (!rute.at) return '';
  const size = ukuran || 12.5;
  const arr = asLines(label);
  const wMax = Math.max(...arr.map((l) => l.length)) * size * 0.545;
  const w = wMax + 14;
  const h = arr.length * size * 1.34 + 7;
  return [
    `<rect x="${r1(rute.at[0] - w / 2)}" y="${r1(rute.at[1] - h / 2)}" width="${r1(w)}" height="${r1(h)}" rx="4" fill="${C.bg}" opacity="0.96"/>`,
    textBlock(rute.at[0], rute.at[1], arr, size, 500, C.sub),
  ].join('\n');
}

/* ----------------------------------------------- penyesuaian tepi elips */

/**
 * Geser titik ujung rute dari tepi kotak ke tepi elips yang terterap di
 * dalamnya. Dipakai hanya pada notasi Yourdon, yang menggambar proses sebagai
 * elips; tanpa ini panah berhenti di ruang kosong di luar elips.
 *
 * Menyusuri ruas terakhir dari titik di luar menuju titik di tepi kotak, lalu
 * berhenti di titik pertama yang sudah menyentuh elips.
 */
function tepiElips(elips, dariLuar, keTepiKotak) {
  const dx = keTepiKotak[0] - dariLuar[0];
  const dy = keTepiKotak[1] - dariLuar[1];
  const diDalam = (x, y) => {
    const nx = (x - elips.cx) / elips.rx;
    const ny = (y - elips.cy) / elips.ry;
    return nx * nx + ny * ny <= 1;
  };
  for (let t = 0; t <= 1; t += 0.002) {
    const x = dariLuar[0] + dx * t;
    const y = dariLuar[1] + dy * t;
    if (diDalam(x, y)) return [r1(x), r1(y)];
  }
  return keTepiKotak;
}

function ruteTergambar(notasi, rute, asal, tujuan) {
  const titik = rute.titik.map((p) => [p[0], p[1]]);
  if (notasi !== 'yourdon') return titik;
  if (asal.elips) titik[0] = tepiElips(asal.elips, titik[1], titik[0]);
  if (tujuan.elips) {
    const n = titik.length;
    titik[n - 1] = tepiElips(tujuan.elips, titik[n - 2], titik[n - 1]);
  }
  return titik;
}

/* --------------------------------------------------------------- periksa */

const EPS = 0.6;

const diDalamKotak = (px, py, b) =>
  px > b.x + EPS && px < b.x + b.w - EPS && py > b.y + EPS && py < b.y + b.h - EPS;

function periksaTembus(kotak, elips, rute, diagram) {
  const masalah = [];
  const diDalamElips = (px, py, l) => {
    const nx = (px - l.cx) / l.rx;
    const ny = (py - l.cy) / l.ry;
    return nx * nx + ny * ny < 1 - EPS / Math.max(l.rx, l.ry);
  };

  // Setiap aliran wajib punya label terbaca. Tanpa pemeriksaan ini, panah
  // tanpa keterangan bisa lolos: gambar tetap "rapi" tetapi tidak bisa dibaca,
  // dan itu persis cacat yang pernah terjadi — `at` tidak disalin sehingga
  // labelAliran mengembalikan string kosong untuk semua aliran.
  for (const f of rute) {
    if (!f.at) {
      masalah.push(
        `${diagram}: aliran "${asLines(f.label).join(' ')}" tidak punya titik label (at)`
      );
    }
    if (!f.label || asLines(f.label).join('').trim() === '') {
      masalah.push(`${diagram}: ada aliran tanpa teks label`);
    }
  }

  for (const f of rute) {
    const tag = asLines(f.label).join(' ');
    for (let i = 0; i < f.titik.length - 1; i++) {
      const [x1, y1] = f.titik[i];
      const [x2, y2] = f.titik[i + 1];
      for (let s = 1; s < 40; s++) {
        const t = s / 40;
        const px = x1 + (x2 - x1) * t;
        const py = y1 + (y2 - y1) * t;
        for (const b of kotak) {
          if (diDalamKotak(px, py, b)) {
            masalah.push(
              `${diagram}: aliran "${tag}" menembus kotak ${b.tag} di (${Math.round(px)}, ${Math.round(py)})`
            );
          }
        }
        for (const l of elips) {
          if (diDalamElips(px, py, l)) {
            masalah.push(
              `${diagram}: aliran "${tag}" menembus elips ${l.tag} di (${Math.round(px)}, ${Math.round(py)})`
            );
          }
        }
      }
    }
  }
  return [...new Set(masalah)];
}

/**
 * Periksa kelengkapan semantik. Inilah penjaga yang mencegah cacat DFD
 * berulang: black hole, miracle, grey hole, dan ketidakseimbangan antar
 * tingkat dideteksi dari data, bukan dari memandang gambar.
 */
function periksaSemantik() {
  const masalah = [];

  const kunciKonteks = new Set(Object.keys(D.RUTE_KONTEKS));
  const kunciLevel1 = new Set(Object.keys(D.RUTE_LEVEL1));

  // 1. setiap aliran punya rute di tingkat tempat ia digambar
  for (const a of D.ALIRAN) {
    if (a.jenis === 'masuk' || a.jenis === 'keluar') {
      if (!kunciKonteks.has(a.kunci)) {
        masalah.push(`aliran batas ${a.kode} (${a.label.join(' ')}) tidak punya rute di DFD konteks`);
      }
      if (!kunciLevel1.has(a.kunci)) {
        masalah.push(`aliran batas ${a.kode} (${a.label.join(' ')}) tidak punya rute di DFD level 1`);
      }
    } else if (!kunciLevel1.has(a.kunci)) {
      masalah.push(`aliran internal ${a.kode} tidak punya rute di DFD level 1`);
    }
  }

  // 2. tidak ada rute yatim
  const kunciAliran = new Set(D.ALIRAN.map((a) => a.kunci));
  for (const k of kunciKonteks) {
    if (!kunciAliran.has(k)) masalah.push(`rute konteks "${k}" tidak punya aliran`);
  }
  for (const k of kunciLevel1) {
    if (!kunciAliran.has(k)) masalah.push(`rute level 1 "${k}" tidak punya aliran`);
  }

  // 3. setiap penyimpanan data ditulis dan dibaca
  for (const s of D.STORE) {
    const tulis = D.ALIRAN.filter((a) => a.ke === s.kunci).length;
    const baca = D.ALIRAN.filter((a) => a.dari === s.kunci).length;
    if (tulis === 0) masalah.push(`penyimpanan ${s.id} tidak pernah ditulis (miracle)`);
    if (baca === 0) masalah.push(`penyimpanan ${s.id} tidak pernah dibaca (black hole)`);
  }

  // 4. setiap proses punya masukan dan keluaran
  for (const p of D.PROSES) {
    const masuk = D.ALIRAN.filter((a) => a.ke === p.kunci).length;
    const keluar = D.ALIRAN.filter((a) => a.dari === p.kunci).length;
    if (masuk === 0) masalah.push(`proses ${p.id} tidak punya masukan (black hole)`);
    if (keluar === 0) masalah.push(`proses ${p.id} tidak punya keluaran (black hole)`);
  }

  // 5. setiap entitas terhubung
  for (const e of D.ENTITAS) {
    const n =
      D.ALIRAN.filter((a) => a.dari === e.id).length +
      D.ALIRAN.filter((a) => a.ke === e.id).length;
    if (n === 0) masalah.push(`entitas ${e.id} tidak punya aliran`);
  }

  // 6. keseimbangan level 0 dan level 1: jumlah aliran batas harus sama
  const batas = D.ALIRAN.filter((a) => a.jenis === 'masuk' || a.jenis === 'keluar');
  const masuk = batas.filter((a) => a.jenis === 'masuk').length;
  const keluar = batas.filter((a) => a.jenis === 'keluar').length;
  const diKonteks = batas.filter((a) => kunciKonteks.has(a.kunci)).length;
  if (diKonteks !== batas.length) {
    masalah.push(
      `tidak seimbang: ${batas.length} aliran batas tetapi ${diKonteks} digambar di konteks`
    );
  }

  return { masalah, jumlah: { masuk, keluar, total: batas.length } };
}

/**
 * Periksa bahwa arah setiap rute benar: titik pertama menempel pada kotak
 * `dari` dan titik terakhir menempel pada kotak `ke`.
 *
 * Inilah penjaga untuk cacat panah terbalik. Cacat itu sebelumnya lolos karena
 * rute hanya sekumpulan koordinat tanpa makna: gambar akan tetap "tidak
 * menembus kotak" meskipun panahnya menunjuk arah sebaliknya.
 */
function periksaArah(kotakMenurutKunci, aliran, rute, diagram) {
  const masalah = [];
  const diTepi = (p, b) => {
    const [x, y] = p;
    const diSisiX = (Math.abs(x - b.x) < 1.5 || Math.abs(x - (b.x + b.w)) < 1.5) &&
      y >= b.y - 1.5 && y <= b.y + b.h + 1.5;
    const diSisiY = (Math.abs(y - b.y) < 1.5 || Math.abs(y - (b.y + b.h)) < 1.5) &&
      x >= b.x - 1.5 && x <= b.x + b.w + 1.5;
    return diSisiX || diSisiY;
  };

  for (const a of aliran) {
    const r = rute[a.kunci];
    if (!r) continue;
    const asal = kotakMenurutKunci[a.dari];
    const tujuan = kotakMenurutKunci[a.ke];
    const awal = r.titik[0];
    const akhir = r.titik[r.titik.length - 1];

    if (asal && !diTepi(awal, asal)) {
      masalah.push(
        `${diagram}: aliran ${a.kode} "${asLines(a.label).join(' ')}" tidak berawal di tepi ${a.dari}`
      );
    }
    if (tujuan && !diTepi(akhir, tujuan)) {
      masalah.push(
        `${diagram}: aliran ${a.kode} "${asLines(a.label).join(' ')}" tidak berakhir di tepi ${a.ke}`
      );
    }
  }
  return [...new Set(masalah)];
}

/**
 * Periksa bahwa tidak ada dua label aliran yang saling menimpa.
 *
 * Cacat ini tidak terlihat dari kode dan tidak terdeteksi oleh pemeriksaan
 * kotak: gambar tetap "tidak menembus kotak" meskipun tulisan saling tindih
 * sampai tidak terbaca. Karena itu ukuran kotak label dihitung dengan rumus
 * yang sama persis dengan `labelAliran`, lalu dibandingkan berpasangan.
 */
function periksaLabel(titikLabel, diagram) {
  const masalah = [];
  const kotak = titikLabel.map((f) => {
    const arr = asLines(f.label);
    const size = 12.5;
    const wMax = Math.max(...arr.map((l) => l.length)) * size * 0.545;
    const w = wMax + 14;
    const h = arr.length * size * 1.34 + 7;
    return { x: f.at[0] - w / 2, y: f.at[1] - h / 2, w, h, teks: arr.join(' ') };
  });

  for (let i = 0; i < kotak.length; i++) {
    for (let j = i + 1; j < kotak.length; j++) {
      const a = kotak[i];
      const b = kotak[j];
      const beririsan =
        a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
      if (beririsan) {
        masalah.push(`${diagram}: label "${a.teks}" menimpa label "${b.teks}"`);
      }
    }
  }
  return masalah;
}

/* --------------------------------------------------------------- konteks */

function gambarKonteks(notasi) {
  const { w: W, h: H } = D.KANVAS.konteks;
  const gane = notasi === 'gane';

  const kotak = D.KOTAK_KONTEKS;
  const entitasKotak = {
    E1: { ...kotak.E1, tag: 'E1 PEMILIK KOS', label: D.ENTITAS[0].nama },
    E2: { ...kotak.E2, tag: 'E2 PENGELOLA', label: D.ENTITAS[1].nama },
    E3: { ...kotak.E3, tag: 'E3 PENGHUNI', label: D.ENTITAS[2].nama },
  };

  const p0 = {
    ...kotak.P0,
    id: gane ? '0.0' : '0',
    tag: 'PROSES 0.0',
    nama: ['SISTEM INFORMASI', 'OPERASIONAL', 'KOS-KOSAN'],
    band: 46,
    ukuranNama: 17,
  };

  const elipsP0 = gane
    ? null
    : {
        cx: p0.x + p0.w / 2,
        cy: p0.y + p0.h / 2,
        rx: p0.w / 2,
        ry: p0.h / 2,
        id: p0.id,
        tag: p0.tag,
        nama: p0.nama,
        ukuranNama: 15,
      };

  const daftarAliran = D.ALIRAN.filter((a) => a.jenis !== 'internal');
  const ruteGambar = daftarAliran.map((a) => ({
    label: a.label,
    at: D.RUTE_KONTEKS[a.kunci].at,
    titik: ruteTergambar(
      notasi,
      D.RUTE_KONTEKS[a.kunci],
      a.dari === 'P0' ? { elips: elipsP0 } : {},
      a.ke === 'P0' ? { elips: elipsP0 } : {}
    ),
  }));

  const kotakPeriksa = Object.values(entitasKotak);
  const elipsPeriksa = elipsP0
    ? [elipsP0]
    : [{ ...p0, cx: p0.x + p0.w / 2, cy: p0.y + p0.h / 2, rx: 0, ry: 0 }];

  const masalah = [
    ...periksaTembus(kotakPeriksa, elipsPeriksa, ruteGambar, 'DFD Konteks'),
    ...periksaArah(
      { E1: entitasKotak.E1, E2: entitasKotak.E2, E3: entitasKotak.E3, P0: p0 },
      daftarAliran,
      D.RUTE_KONTEKS,
      'DFD Konteks'
    ),
    ...periksaLabel(ruteGambar, 'DFD Konteks'),
  ];

  const legenda = [
    `<rect x="700" y="740" width="520" height="132" rx="10" fill="#fafafa" stroke="${C.legend}" stroke-width="1"/>`,
    `<text x="718" y="766" font-size="12.5" font-weight="700" fill="${C.ink}">KETERANGAN SIMBOL${gane ? '' : ' (YOURDON / DeMARCO)'}</text>`,
    `<rect x="718" y="780" width="72" height="26" fill="${C.entityFill}" stroke="${C.entityStroke}" stroke-width="2"/>`,
    `<text x="802" y="793" font-size="12" fill="${C.sub}" dominant-baseline="central">Entitas eksternal</text>`,
    gane
      ? [
          `<rect x="718" y="816" width="72" height="26" rx="8" fill="${C.procFill}" stroke="${C.procStroke}" stroke-width="2"/>`,
          `<path d="M718 829 H790" stroke="${C.procStroke}" stroke-width="1.2"/>`,
          `<text x="802" y="829" font-size="12" fill="${C.sub}" dominant-baseline="central">Proses</text>`,
        ].join('\n')
      : [
          `<circle cx="754" cy="829" r="13" fill="${C.procFill}" stroke="${C.procStroke}" stroke-width="2"/>`,
          `<text x="802" y="829" font-size="12" fill="${C.sub}" dominant-baseline="central">Proses (nomor + nama)</text>`,
        ].join('\n'),
    `<path d="M718 866 H782" fill="none" stroke="${C.flow}" stroke-width="1.6" marker-end="url(#arrow)"/>`,
    `<text x="802" y="866" font-size="12" fill="${C.sub}" dominant-baseline="central">Aliran data (berlabel)</text>`,
  ].join('\n');

  const jumlah = periksaSemantik().jumlah;
  const catatan = [
    `<text x="60" y="904" font-size="12.5" fill="${C.sub}">Notasi ${gane ? 'Gane-Sarson' : 'Yourdon / DeMarco'} — entitas eksternal (kotak), proses (${gane ? 'persegi sudut tumpul' : 'lingkaran'}), aliran data (panah berlabel).</text>`,
    `<text x="60" y="930" font-size="12.5" fill="${C.sub}">Proses ${p0.id} mewakili seluruh sistem sebagai satu simpul. Rincian tiap aliran: docs/dfd/README.md</text>`,
    `<text x="60" y="956" font-size="12.5" fill="${C.sub}">${D.ENTITAS.length} entitas eksternal  ·  ${jumlah.total} aliran data utama (${jumlah.masuk} masuk, ${jumlah.keluar} keluar)  ·  belum ada penyimpanan data pada level ini.</text>`,
  ].join('\n');

  const isi = [
    head(W, H, `DFD Konteks (DFD Nol) — Sistem Informasi Operasional Kos-Kosan (${notasi})`),
    judul(
      'DFD KONTEKS (DFD NOL / LEVEL 0)',
      `Sistem Informasi Operasional Kos-Kosan  ·  ${D.ENTITAS.length} entitas  ·  notasi ${gane ? 'Gane-Sarson' : 'Yourdon/DeMarco'}`
    ),
    entitas(entitasKotak.E1),
    entitas(entitasKotak.E2),
    entitas(entitasKotak.E3),
    gane ? prosesGane(p0) : prosesYourdon(elipsP0),
    ruteGambar.map((f) => garisAliran(f.titik)).join('\n'),
    ruteGambar.map((f, i) => labelAliran(f, daftarAliran[i].label)).join('\n'),
    legenda,
    catatan,
    tail(),
  ].join('\n');

  return { svg: isi, masalah };
}

/* --------------------------------------------------------------- level 1 */

/**
 * Margin atas untuk DFD Level 1.
 *
 * Koordinat di dfd-data.js disusun rapat agar mudah dibaca sebagai satu
 * kesatuan; judul gambar menempati y 60-88. Tanpa margin ini, lajur kabel
 * teratas (y 70-100) bertabrakan dengan teks judul. Menggeser seluruh isi
 * satu kali di sini jauh lebih aman daripada menambah 130 pada 40 koordinat
 * dan berisiko meninggalkan satu titik yang terlewat.
 */
const GESER_LEVEL1 = 130;

const geserKotak = (b, dy) => ({ ...b, y: b.y + dy });

const geserRute = (r, dy) => ({
  titik: r.titik.map(([x, y]) => [x, y + dy]),
  at: [r.at[0], r.at[1] + dy],
});

function gambarLevel1(notasi) {
  const dy = GESER_LEVEL1;
  const { w: W, h: H0 } = D.KANVAS.level1;
  const H = H0 + dy;
  const gane = notasi === 'gane';

  const kotak = Object.fromEntries(
    Object.entries(D.KOTAK_LEVEL1).map(([k, v]) => [k, geserKotak(v, dy)])
  );

  const entitasKotak = {};
  const petaEntitas = { E1: 'PEMILIK KOS', E2: 'PENGELOLA', E3: 'PENGHUNI' };
  for (const e of D.ENTITAS) {
    entitasKotak[e.id] = {
      ...kotak[e.id],
      tag: `${e.id} ${petaEntitas[e.id]}`,
      label: e.nama,
    };
  }

  const prosesKotak = {};
  const prosesElips = {};
  for (const p of D.PROSES) {
    const b = kotak[p.kunci];
    const id = gane ? p.id : p.id.replace(/\.0$/, '');
    const nama = gane ? p.nama : p.namaYourdon;
    const ukuranNama = gane ? 14 : p.namaYourdon.join('').length > 14 ? 10.5 : 11.5;
    prosesKotak[p.kunci] = {
      ...b,
      id,
      tag: `PROSES ${p.id}`,
      nama,
      ukuranNama,
    };
    prosesElips[p.kunci] = {
      cx: b.x + b.w / 2,
      cy: b.y + b.h / 2,
      rx: b.w / 2,
      ry: b.h / 2,
      id,
      tag: `PROSES ${p.id}`,
      nama,
      ukuranNama,
    };
  }

  const storeKotak = {};
  for (const s of D.STORE) {
    storeKotak[s.kunci] = { ...kotak[s.kunci], id: s.id, tag: `${s.id} ${s.ringkas}`, nama: s.nama };
  }

  const ruteLevel = Object.fromEntries(
    Object.entries(D.RUTE_LEVEL1).map(([k, v]) => [k, geserRute(v, dy)])
  );

  const ruteGambar = D.ALIRAN.map((a) => {
    const asalElips = prosesElips[a.dari];
    const tujuanElips = prosesElips[a.ke];
    return {
      label: a.label,
      at: ruteLevel[a.kunci].at,
      titik: ruteTergambar(
        notasi,
        ruteLevel[a.kunci],
        asalElips ? { elips: asalElips } : {},
        tujuanElips ? { elips: tujuanElips } : {}
      ),
    };
  });

  const kotakPeriksa = [
    ...Object.values(entitasKotak),
    ...Object.values(storeKotak),
    ...(gane ? Object.values(prosesKotak) : []),
  ];
  const elipsPeriksa = gane ? [] : Object.values(prosesElips);

  const masalah = [
    ...periksaTembus(kotakPeriksa, elipsPeriksa, ruteGambar, `DFD Level 1 (${notasi})`),
    ...periksaArah(
      {
        ...entitasKotak,
        ...storeKotak,
        ...prosesKotak,
      },
      D.ALIRAN,
      ruteLevel,
      `DFD Level 1 (${notasi})`
    ),
    ...periksaLabel(ruteGambar, `DFD Level 1 (${notasi})`),
  ];

  /* ---- legenda ---- */
  const lx = 1500;
  const ly = 1560;
  const legenda = [
    `<rect x="${lx}" y="${ly}" width="560" height="190" rx="10" fill="#fafafa" stroke="${C.legend}" stroke-width="1"/>`,
    `<text x="${lx + 18}" y="${ly + 26}" font-size="12.5" font-weight="700" fill="${C.ink}">KETERANGAN SIMBOL${gane ? '' : ' (YOURDON / DeMARCO)'}</text>`,
    `<rect x="${lx + 20}" y="${ly + 42}" width="66" height="26" fill="${C.entityFill}" stroke="${C.entityStroke}" stroke-width="2"/>`,
    `<text x="${lx + 100}" y="${ly + 55}" font-size="12" fill="${C.sub}" dominant-baseline="central">Entitas eksternal</text>`,
    gane
      ? [
          `<rect x="${lx + 20}" y="${ly + 82}" width="66" height="26" rx="8" fill="${C.procFill}" stroke="${C.procStroke}" stroke-width="2"/>`,
          `<path d="M${lx + 20} ${ly + 95} H${lx + 86}" stroke="${C.procStroke}" stroke-width="1.2"/>`,
          `<text x="${lx + 100}" y="${ly + 95}" font-size="12" fill="${C.sub}" dominant-baseline="central">Proses</text>`,
        ].join('\n')
      : [
          `<circle cx="${lx + 53}" cy="${ly + 95}" r="15" fill="${C.procFill}" stroke="${C.procStroke}" stroke-width="2"/>`,
          `<text x="${lx + 100}" y="${ly + 95}" font-size="12" fill="${C.sub}" dominant-baseline="central">Proses (nomor + nama)</text>`,
        ].join('\n'),
    gane
      ? [
          `<path d="M${lx + 20} ${ly + 122} H${lx + 86} M${lx + 20} ${ly + 122} V${ly + 148} M${lx + 20} ${ly + 148} H${lx + 86}" fill="none" stroke="${C.storeStroke}" stroke-width="2"/>`,
          `<line x1="${lx + 46}" y1="${ly + 122}" x2="${lx + 46}" y2="${ly + 148}" stroke="${C.storeStroke}" stroke-width="1.4"/>`,
          `<text x="${lx + 100}" y="${ly + 135}" font-size="12" fill="${C.sub}" dominant-baseline="central">Penyimpanan data</text>`,
        ].join('\n')
      : [
          `<line x1="${lx + 20}" y1="${ly + 122}" x2="${lx + 86}" y2="${ly + 122}" stroke="${C.storeStroke}" stroke-width="2"/>`,
          `<line x1="${lx + 20}" y1="${ly + 148}" x2="${lx + 86}" y2="${ly + 148}" stroke="${C.storeStroke}" stroke-width="2"/>`,
          `<line x1="${lx + 46}" y1="${ly + 122}" x2="${lx + 46}" y2="${ly + 148}" stroke="${C.storeStroke}" stroke-width="1.4"/>`,
          `<text x="${lx + 100}" y="${ly + 135}" font-size="12" fill="${C.sub}" dominant-baseline="central">Penyimpanan data</text>`,
        ].join('\n'),
    `<path d="M${lx + 20} ${ly + 172} H${lx + 84}" fill="none" stroke="${C.flow}" stroke-width="1.6" marker-end="url(#arrow)"/>`,
    `<text x="${lx + 100}" y="${ly + 172}" font-size="12" fill="${C.sub}" dominant-baseline="central">Aliran data</text>`,
  ].join('\n');

  const jumlahAliran = D.ALIRAN.length;
  const catatan = [
    `<text x="60" y="${H - 70}" font-size="12.5" fill="${C.sub}">${D.PROSES.length} proses level 1  ·  ${D.STORE.length} penyimpanan data  ·  ${D.ENTITAS.length} entitas eksternal  ·  ${jumlahAliran} aliran data</text>`,
    `<text x="60" y="${H - 44}" font-size="12.5" fill="${C.sub}">Rincian tiap aliran (asal, tujuan, struktur data): docs/dfd/README.md</text>`,
    `<text x="60" y="${H - 18}" font-size="12.5" fill="${C.sub}">Dekomposisi proses 3.0 dan 4.0 ke level 2: docs/dfd/03-dfd-level-2.md</text>`,
  ].join('\n');

  const bagian = [
    head(W, H, `DFD Level 1 — Sistem Informasi Operasional Kos-Kosan (${notasi})`),
    judul(
      'DFD LEVEL 1',
      `Sistem Informasi Operasional Kos-Kosan  ·  ${D.PROSES.length} proses  ·  ${D.STORE.length} penyimpanan data  ·  notasi ${gane ? 'Gane-Sarson' : 'Yourdon/DeMarco'}`
    ),
    Object.values(entitasKotak).map(entitas).join('\n'),
    gane
      ? Object.values(prosesKotak).map(prosesGane).join('\n')
      : Object.values(prosesElips).map(prosesYourdon).join('\n'),
    gane
      ? Object.values(storeKotak).map(storeGane).join('\n')
      : Object.values(storeKotak).map(storeYourdon).join('\n'),
    ruteGambar.map((f) => garisAliran(f.titik)).join('\n'),
    ruteGambar.map((f) => labelAliran(f, f.label)).join('\n'),
    legenda,
    catatan,
    tail(),
  ].join('\n');

  return { svg: bagian, masalah };
}

module.exports = {
  gambarKonteks,
  gambarLevel1,
  periksaSemantik,
  C,
};
