'use strict';

/**
 * Penggambar + pemeriksa Diagram Use Case
 * ---------------------------------------
 * Menerima `usecase-data.js` dan `rute-api.json`, lalu:
 *
 *   1. memeriksa bahwa pemetaan use case ↔ endpoint benar-benar tertutup,
 *      yaitu tepat 14 use case, setiap use case punya minimal satu endpoint
 *      nyata, tidak ada endpoint yang dipakai dua use case, dan seluruh
 *      endpoint pada potret `route:list` terpakai habis tanpa sisa;
 *   2. memeriksa bahwa keanggotaan aktor yang ditulis di `MATRIKS` sama persis
 *      dengan yang diturunkan dari middleware, bukan dari ingatan penulis;
 *   3. menggambar diagramnya menjadi SVG.
 *
 * Pemeriksaan (1) dan (2) adalah alasan berkas ini ada. Diagram yang cantik
 * dengan satu endpoint hilang adalah diagram yang salah, dan kesalahannya
 * tidak terlihat dari gambar.
 */

const D = require('./usecase-data');

const FONT = "'Segoe UI', 'Inter', 'Helvetica Neue', Arial, sans-serif";

const C = {
  bg: '#ffffff',
  ink: '#0f172a',
  sub: '#475569',
  batas: '#94a3b8',
  batasIsi: '#fbfcfe',
  batasLabel: '#334155',
  ucIsi: '#ffffff',
  ucGaris: '#1e293b',
  ucKode: '#64748b',
  legenda: '#94a3b8',
};

/**
 * Satu warna per aktor, dan warnanya dipakai untuk garis asosiasi maupun
 * lambang aktornya.
 *
 * Ini bukan hiasan. Pengelola terhubung ke UC-14 di dasar diagram dan
 * penghuni terhubung ke UC-01 di puncak, sehingga kedua kipas garis itu
 * mau tidak mau bersilangan di lajur kanan. Tanpa warna, persilangan itu
 * membuat pembaca salah menelusuri garis; dengan warna, garis tetap bisa
 * diikuti sampai ujungnya.
 */
const WARNA_AKTOR = {
  pemilik: { garis: '#3730a3', isi: '#eef2ff', tepi: '#3730a3' },
  pengelola: { garis: '#0f766e', isi: '#ecfdf5', tepi: '#0f766e' },
  penghuni: { garis: '#b45309', isi: '#fffbeb', tepi: '#b45309' },
};

/* ------------------------------------------------------------- utilitas */

const esc = (s) =>
  String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const r1 = (n) => Math.round(n * 10) / 10;

/**
 * Lebar teks perkiraan. Dipakai hanya untuk memastikan label tidak melewati
 * elipsnya; 0,545 adalah lebar rata-rata huruf pada font tanpa kait (sans
 * serif) dibagi ukuran font. Perkiraan yang sedikit berlebih lebih aman
 * daripada label yang melimpah keluar elips.
 */
const lebarTeks = (s, size) => String(s).length * size * 0.545;

function head(w, h, judul) {
  return [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" font-family="${FONT}">`,
    `<rect width="${w}" height="${h}" fill="${C.bg}"/>`,
    `<title>${esc(judul)}</title>`,
  ].join('\n');
}

const tail = () => '</svg>\n';

function teks(x, y, isi, size, weight, warna, anchor) {
  return `<text x="${r1(x)}" y="${r1(y)}" text-anchor="${
    anchor || 'middle'
  }" dominant-baseline="central" font-size="${size}" font-weight="${weight}" fill="${warna}">${esc(
    isi
  )}</text>`;
}

/* --------------------------------------------------------------- lambang */

/**
 * Aktor digambar sebagai orang-orangan: kepala, badan, dua tangan, dua kaki.
 * Titik sambung garis asosiasi adalah ujung tangan yang menghadap sistem,
 * bukan pusat badan — supaya garis tidak menembus lambangnya sendiri.
 */
function aktor(a, pos) {
  const w = WARNA_AKTOR[a.kunci];
  const { x, y } = pos;
  return [
    `<circle cx="${x}" cy="${y - 42}" r="13" fill="${w.isi}" stroke="${w.tepi}" stroke-width="2"/>`,
    `<path d="M${x} ${y - 29} V${y + 8}" fill="none" stroke="${w.tepi}" stroke-width="2"/>`,
    `<path d="M${x - 22} ${y - 16} H${x + 22}" fill="none" stroke="${w.tepi}" stroke-width="2"/>`,
    `<path d="M${x} ${y + 8} L${x - 16} ${y + 38} M${x} ${y + 8} L${x + 16} ${y + 38}" fill="none" stroke="${w.tepi}" stroke-width="2"/>`,
    a.ringkas
      .map((baris, i) =>
        teks(x, y + 60 + i * 18, baris, i === 0 ? 13 : 11, i === 0 ? 700 : 500, i === 0 ? C.ink : C.sub)
      )
      .join('\n'),
  ].join('\n');
}

function elipsUC(e, kode) {
  const arr = [e.uc.ringkas];
  return [
    `<ellipse cx="${e.cx}" cy="${e.cy}" rx="${e.rx}" ry="${e.ry}" fill="${C.ucIsi}" stroke="${C.ucGaris}" stroke-width="1.8"/>`,
    teks(e.cx, e.cy - 9, kode, 10.5, 700, C.ucKode),
    teks(e.cx, e.cy + 9, arr[0], 13, 600, C.ink),
  ].join('\n');
}

/** Garis asosiasi aktor–use case. UML tidak memakai mata panah di sini. */
function asosiasi(dari, ke, warna) {
  return `<path d="M${r1(dari[0])} ${r1(dari[1])} L${r1(ke[0])} ${r1(ke[1])}" fill="none" stroke="${warna}" stroke-width="1.35" stroke-linecap="round" opacity="0.92"/>`;
}

/* --------------------------------------------------------------- periksa */

/**
 * Turunkan aktor yang boleh memakai sebuah endpoint dari middleware-nya.
 *
 *   `peran:pemilik,pengelola` → pemilik dan pengelola
 *   `peran:pemilik`           → pemilik saja
 *   `wewenang:apa pun`        → pemilik (selalu lolos) dan pengelola (yang
 *                               memegang wewenang itu) — lihat PastikanWewenang
 *   hanya `auth:sanctum`      → tidak membedakan aktor; lihat `tanpaGerbang`
 */
function aktorDariGerbang(middleware) {
  const hasil = new Set();
  for (const m of middleware) {
    const peran = /PastikanPeran:([\w,]+)$/.exec(m);
    if (peran) {
      for (const p of peran[1].split(',')) hasil.add(p);
      continue;
    }
    if (/PastikanWewenang:/.test(m)) {
      hasil.add('pemilik');
      hasil.add('pengelola');
    }
  }
  return hasil;
}

/**
 * Periksa kelengkapan pemetaan dan keanggotaan aktor.
 * Mengembalikan daftar masalah; daftar kosong berarti semua konsisten.
 */
function periksaData(rute) {
  const masalah = [];
  const namaRute = new Map(rute.map((r) => [r.name, r]));

  if (D.USE_CASE.length !== 14) {
    masalah.push(`jumlah use case ${D.USE_CASE.length}, seharusnya tepat 14`);
  }

  const kodeTerpakai = new Map();
  const ruteTerpakai = new Map();

  for (const uc of D.USE_CASE) {
    if (!uc.endpoint.length) {
      masalah.push(`${uc.kode} tidak menunjuk satu pun endpoint`);
    }
    if (kodeTerpakai.has(uc.kode)) {
      masalah.push(`kode use case ganda: ${uc.kode}`);
    }
    kodeTerpakai.set(uc.kode, uc);

    for (const nama of uc.endpoint) {
      if (!namaRute.has(nama)) {
        masalah.push(`${uc.kode}: endpoint "${nama}" tidak ada di rute-api.json`);
        continue;
      }
      if (ruteTerpakai.has(nama)) {
        masalah.push(
          `endpoint "${nama}" dipakai dua use case: ${ruteTerpakai.get(nama)} dan ${uc.kode}`
        );
      }
      ruteTerpakai.set(nama, uc.kode);
    }

    // Keanggotaan aktor: diturunkan dari middleware, bukan dari MATRIKS.
    const bergerbang = uc.endpoint.filter((n) => namaRute.has(n));
    const turunan = new Set();
    for (const nama of bergerbang) {
      for (const a of aktorDariGerbang(namaRute.get(nama).middleware)) turunan.add(a);
    }

    const dideklarasikan = new Set(
      D.MATRIKS.filter((m) => m.uc.includes(uc.kode)).map((m) => m.aktor)
    );

    if (turunan.size === 0) {
      // Tidak ada endpoint bergerbang sama sekali: seluruh endpoint hanya
      // memakai `auth:sanctum`, jadi SETIAP aktor yang sudah login dapat
      // memanggilnya. Keanggotaan karena itu wajib ketiga-tiganya, dan
      // alasan mengapa use case ini tidak punya gerbang harus tertulis —
      // supaya pembaca tahu ini keputusan, bukan gerbang yang terlupa.
      if (!uc.tanpaGerbang) {
        masalah.push(
          `${uc.kode} tidak punya endpoint bergerbang tetapi juga tidak menjelaskan ` +
            'alasannya (tambahkan `tanpaGerbang`)'
        );
      }
      for (const a of D.AKTOR) {
        if (!dideklarasikan.has(a.kunci)) {
          masalah.push(
            `${uc.kode}: endpointnya hanya auth:sanctum sehingga ${a.kunci} dapat ` +
              'memanggilnya, tetapi ia tidak tercantum di MATRIKS'
          );
        }
      }
    } else {
      for (const a of turunan) {
        if (!dideklarasikan.has(a)) {
          masalah.push(
            `${uc.kode}: ${a} dapat memanggil endpoint bergerbangnya tetapi tidak tercantum di MATRIKS`
          );
        }
      }
      for (const a of dideklarasikan) {
        if (!turunan.has(a)) {
          masalah.push(
            `${uc.kode}: ${a} tercantum di MATRIKS tetapi tidak dapat memanggil satu pun ` +
              'endpoint bergerbangnya'
          );
        }
      }
    }
  }

  const sisa = rute.filter((r) => !ruteTerpakai.has(r.name));
  if (sisa.length) {
    masalah.push(
      `${sisa.length} endpoint tidak menjadi dasar use case mana pun: ` +
        sisa.map((r) => r.name).join(', ')
    );
  }

  for (const a of D.AKTOR) {
    if (!D.MATRIKS.some((m) => m.aktor === a.kunci)) {
      masalah.push(`aktor ${a.kunci} tidak punya baris MATRIKS`);
    }
  }

  for (const m of D.MATRIKS) {
    for (const kode of m.uc) {
      if (!kodeTerpakai.has(kode)) masalah.push(`MATRIKS menyebut ${kode} yang tidak ada`);
    }
  }

  // Catatan kaki harus menunjuk use case yang benar-benar ada.
  for (const c of D.CATATAN_MATRIKS) {
    if (!kodeTerpakai.has(c.uc)) {
      masalah.push(`catatan kaki (${c.kode}) menunjuk ${c.uc} yang tidak ada`);
    }
  }

  return masalah;
}

/**
 * Periksa tata letak: label harus muat di dalam elips, garis asosiasi tidak
 * boleh menembus elips lain, dan tidak boleh ada dua elips yang bertumpuk.
 */
function periksaTataLetak(posisi, jangkar) {
  const masalah = [];
  const t = D.TATA_LETAK.elips;

  for (const e of posisi) {
    const lebar = lebarTeks(e.uc.ringkas, 13);
    const tersedia = 2 * e.rx * 0.88;
    if (lebar > tersedia) {
      masalah.push(
        `${e.uc.kode}: label "${e.uc.ringkas}" (${Math.round(lebar)} px) melampaui lebar elips ` +
          `(${Math.round(tersedia)} px) — pendekkan \`ringkas\``
      );
    }
  }

  for (let i = 1; i < posisi.length; i++) {
    const jarak = posisi[i].cy - posisi[i - 1].cy;
    if (jarak < 2 * t.ry + 6) {
      masalah.push(
        `${posisi[i].uc.kode} terlalu rapat dengan ${posisi[i - 1].uc.kode} ` +
          `(jarak ${jarak} px, minimal ${2 * t.ry + 6} px)`
      );
    }
  }

  const diDalam = (px, py, e) => {
    const nx = (px - e.cx) / e.rx;
    const ny = (py - e.cy) / e.ry;
    return nx * nx + ny * ny < 1;
  };

  for (const [aktorKunci, dari] of Object.entries(jangkar)) {
    const daftar = D.MATRIKS.find((m) => m.aktor === aktorKunci);
    if (!daftar) continue;
    for (const kode of daftar.uc) {
      const e = posisi.find((p) => p.uc.kode === kode);
      if (!e) continue; // sudah dilaporkan periksaData
      const ke = aktorKunci === 'pemilik' ? [e.cx - e.rx, e.cy] : [e.cx + e.rx, e.cy];
      for (let s = 1; s < 200; s++) {
        const f = s / 200;
        const px = dari[0] + (ke[0] - dari[0]) * f;
        const py = dari[1] + (ke[1] - dari[1]) * f;
        for (const lain of posisi) {
          if (lain.uc.kode === kode) continue;
          if (diDalam(px, py, lain)) {
            masalah.push(
              `garis ${aktorKunci}–${kode} menembus ${lain.uc.kode} di ` +
                `(${Math.round(px)}, ${Math.round(py)})`
            );
            s = 200;
            break;
          }
        }
      }
    }
  }

  return [...new Set(masalah)];
}

/* ---------------------------------------------------------------- gambar */

function gambarUseCase(rute) {
  const { kanvas, batas, elips } = D.TATA_LETAK;

  // Periksa data lebih dulu. Menggambar sebelum memeriksa membuat data yang
  // salah berakhir sebagai TypeError (elips tak ditemukan), bukan sebagai
  // pesan yang bisa dibaca.
  const masalahData = periksaData(rute);
  if (masalahData.length) return { svg: null, masalah: masalahData, posisi: [] };

  const posisi = D.USE_CASE.map((uc, i) => ({
    uc,
    cx: elips.cx,
    cy: elips.mulai + i * elips.jarak,
    rx: elips.rx,
    ry: elips.ry,
  }));

  const jangkar = {};
  for (const a of D.AKTOR) {
    const p = D.TATA_LETAK.aktor[a.kunci];
    jangkar[a.kunci] = [a.kunci === 'pemilik' ? p.x + 30 : p.x - 30, p.y - 16];
  }

  const garis = [];
  for (const a of D.AKTOR) {
    const daftar = D.MATRIKS.find((m) => m.aktor === a.kunci);
    if (!daftar) continue;
    for (const kode of daftar.uc) {
      const e = posisi.find((p) => p.uc.kode === kode);
      if (!e) continue; // sudah dilaporkan periksaData
      const ke = a.kunci === 'pemilik' ? [e.cx - e.rx, e.cy] : [e.cx + e.rx, e.cy];
      garis.push(asosiasi(jangkar[a.kunci], ke, WARNA_AKTOR[a.kunci].garis));
    }
  }

  const jumlahEndpoint = D.USE_CASE.reduce((n, uc) => n + uc.endpoint.length, 0);

  const legenda = [
    `<path d="M60 1164 H120" fill="none" stroke="${C.legenda}" stroke-width="1.35"/>`,
    teks(128, 1164, 'asosiasi aktor–use case', 11.5, 500, C.sub, 'start'),
    `<ellipse cx="356" cy="1164" rx="26" ry="13" fill="${C.ucIsi}" stroke="${C.ucGaris}" stroke-width="1.6"/>`,
    teks(390, 1164, 'use case', 11.5, 500, C.sub, 'start'),
    `<rect x="500" y="1151" width="26" height="26" fill="${C.batasIsi}" stroke="${C.batas}" stroke-width="1.4"/>`,
    teks(534, 1164, 'batas sistem', 11.5, 500, C.sub, 'start'),
  ].join('\n');

  const keterangan = [
    teks(
      60,
      1190,
      `${D.AKTOR.length} aktor  ·  ${D.USE_CASE.length} use case  ·  ${jumlahEndpoint} endpoint nyata  ·  ` +
        'setiap use case menunjuk minimal satu rute pada api/routes/api.php',
      11.5,
      400,
      C.sub,
      'start'
    ),
  ].join('\n');

  const isi = [
    head(kanvas.w, kanvas.h, 'Diagram Use Case — Sistem Informasi Operasional Kos-Kosan'),
    teks(60, 46, 'DIAGRAM USE CASE', 26, 700, C.ink, 'start'),
    teks(
      60,
      74,
      `Sistem Informasi Operasional Kos-Kosan  ·  ${D.AKTOR.length} aktor  ·  ${D.USE_CASE.length} use case`,
      14,
      400,
      C.sub,
      'start'
    ),
    `<rect x="${batas.x}" y="${batas.y}" width="${batas.w}" height="${batas.h}" rx="10" fill="${C.batasIsi}" stroke="${C.batas}" stroke-width="1.6"/>`,
    teks(batas.x + batas.w / 2, batas.y + 30, 'Sistem Informasi Operasional Kos-Kosan', 14, 700, C.batasLabel),
    garis.join('\n'),
    posisi.map((e) => elipsUC(e, e.uc.kode)).join('\n'),
    D.AKTOR.map((a) => aktor(a, D.TATA_LETAK.aktor[a.kunci])).join('\n'),
    legenda,
    keterangan,
    tail(),
  ].join('\n');

  const masalah = periksaTataLetak(posisi, jangkar);

  return { svg: isi, masalah, posisi };
}

module.exports = {
  gambarUseCase,
  periksaData,
  periksaTataLetak,
  aktorDariGerbang,
  WARNA_AKTOR,
};
