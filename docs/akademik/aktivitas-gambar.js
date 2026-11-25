'use strict';

/**
 * Penggambar + pemeriksa Diagram Aktivitas
 * ---------------------------------------
 * Menerima `aktivitas-data.js` lalu menggambar satu SVG per alur. Bentuknya
 * mengikuti notasi UML seperlunya: bulatan hitam (mulai), kotak bersudut bulat
 * (aksi), belah ketupat (keputusan), dan bulatan bergaris ganda (selesai).
 *
 * Kenapa ada pemeriksa di dalam penggambar, bukan hanya gambar yang "terlihat
 * baik": dua kesalahan tata letak paling mudah terjadi justru yang tidak
 * menimbulkan galat - kotak yang menimpa kotak lain, dan panah yang berhenti
 * di luar kanvas. Keduanya hanya terlihat kalau ada yang membandingkan
 * koordinat, dan yang membandingkan harus mesin.
 *
 * Yang diperiksa sebelum satu berkas pun ditulis:
 *   1. setiap alur punya tepat satu `mulai` dan satu `selesai`;
 *   2. tidak ada dua kotak yang beririsan;
 *   3. seluruh isi berada di dalam kanvas;
 *   4. tidak ada simpul `keputusan` yang lupa menyebut cabang `ya`/`tidak`.
 */

const fs = require('fs');
const path = require('path');
const { ALUR, UKURAN } = require('./aktivitas-data');

const FONT = "'Segoe UI', 'Inter', 'Helvetica Neue', Arial, sans-serif";

const C = {
  bg: '#ffffff',
  ink: '#0f172a',
  sub: '#475569',
  garis: '#1e293b',
  aksiIsi: '#ffffff',
  aksiTepi: '#1e293b',
  keputusanIsi: '#fff7ed',
  keputusanTepi: '#b45309',
  catatanIsi: '#f8fafc',
  catatanTepi: '#94a3b8',
  catatanTeks: '#475569',
  mulai: '#0f172a',
  selesaiIsi: '#ffffff',
  selesaiTepi: '#0f172a',
  labelYa: '#15803d',
  labelTidak: '#b91c1c',
};

const esc = (s) =>
  String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** Bungkus teks per kira-kira `maks` karakter tanpa memotong kata. */
function bungkus(teks, maks) {
  const kata = String(teks).split(' ');
  const baris = [];
  let kini = '';
  for (const k of kata) {
    if ((kini + ' ' + k).trim().length > maks) {
      if (kini) baris.push(kini);
      kini = k;
    } else {
      kini = (kini + ' ' + k).trim();
    }
  }
  if (kini) baris.push(kini);
  return baris;
}

/** Tinggi kotak mengikuti jumlah baris teksnya. */
function tinggiSimpul(s, lebarTeks) {
  const baris = bungkus(s.teks, lebarTeks);
  if (s.jenis === 'catatan') return baris.length * 18 + 26;
  if (s.jenis === 'keputusan') return Math.max(96, baris.length * 18 + 46);
  return Math.max(UKURAN.tinggiKotak, baris.length * 18 + 28);
}

/** Tata letak: satu kolom, tiap simpul ditumpuk dengan jarak tetap. */
function tataLetak(alur) {
  const { kolomUtama, lebarKotak, jarakBaris, atas } = UKURAN;
  const posisi = [];
  let y = atas;

  for (const s of alur.simpul) {
    const lebarCatatan = 300;
    const lebar = s.jenis === 'catatan' ? lebarCatatan : lebarKotak;
    const x =
      s.jenis === 'catatan'
        ? kolomUtama + lebarKotak + 46
        : kolomUtama + (lebarKotak - lebar) / 2;
    const h = tinggiSimpul(s, s.jenis === 'catatan' ? 38 : 46);

    posisi.push({ ...s, x, y, w: lebar, h });

    // Catatan menempel di samping aksi sebelumnya: tidak memakai baris sendiri.
    if (s.jenis !== 'catatan') y += h + jarakBaris;
  }

  const bawah = Math.max(...posisi.map((p) => p.y + p.h));
  return { posisi, tinggiKanvas: bawah + 90 };
}

function bentuk(p) {
  const tengahX = UKURAN.kolomUtama + UKURAN.lebarKotak / 2;

  if (p.jenis === 'mulai') {
    return {
      svg: `<circle cx="${tengahX}" cy="${p.y + 20}" r="16" fill="${C.mulai}"/>`,
      bawah: p.y + 36,
    };
  }

  if (p.jenis === 'selesai') {
    return {
      svg:
        `<circle cx="${tengahX}" cy="${p.y + 22}" r="20" fill="${C.selesaiIsi}" stroke="${C.selesaiTepi}" stroke-width="2"/>` +
        `<circle cx="${tengahX}" cy="${p.y + 22}" r="12" fill="${C.mulai}"/>`,
      bawah: p.y + 42,
    };
  }

  if (p.jenis === 'keputusan') {
    const cx = p.x + p.w / 2;
    const cy = p.y + p.h / 2;
    const rx = p.w / 2;
    const ry = p.h / 2;
    const baris = bungkus(p.teks, 34);
    const teksSvg = baris
      .map(
        (b, i) =>
          `<text x="${cx}" y="${cy + 5 - (baris.length - 1) * 9 + i * 18}" font-family="${FONT}" font-size="13" font-weight="600" fill="${C.ink}" text-anchor="middle">${esc(b)}</text>`
      )
      .join('');
    return {
      svg:
        `<polygon points="${cx},${p.y} ${p.x + p.w},${cy} ${cx},${p.y + p.h} ${p.x},${cy}" fill="${C.keputusanIsi}" stroke="${C.keputusanTepi}" stroke-width="1.6"/>` +
        teksSvg,
      bawah: p.y + p.h,
      cx,
      cy,
    };
  }

  // aksi dan catatan sama-sama kotak bersudut bulat; bedanya warna dan garis tepi
  const isi = p.jenis === 'catatan' ? C.catatanIsi : C.aksiIsi;
  const tepi = p.jenis === 'catatan' ? C.catatanTepi : C.aksiTepi;
  const putus = p.jenis === 'catatan' ? ' stroke-dasharray="5 4"' : '';
  const warna = p.jenis === 'catatan' ? C.catatanTeks : C.ink;
  const baris = bungkus(p.teks, p.jenis === 'catatan' ? 38 : 46);
  const mulaiTeks = p.y + p.h / 2 - (baris.length - 1) * 9 + 5;
  const teksSvg = baris
    .map(
      (b, i) =>
        `<text x="${p.x + p.w / 2}" y="${mulaiTeks + i * 18}" font-family="${FONT}" font-size="13" fill="${warna}" text-anchor="middle">${esc(b)}</text>`
    )
    .join('');

  return {
    svg:
      `<rect x="${p.x}" y="${p.y}" width="${p.w}" height="${p.h}" rx="8" fill="${isi}" stroke="${tepi}" stroke-width="1.6"${putus}/>` +
      teksSvg,
    bawah: p.y + p.h,
  };
}

/** Panah lurus ke bawah antar simpul utama (catatan tidak ikut alur). */
function panah(x1, y1, x2, y2) {
  return `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${C.garis}" stroke-width="1.6" marker-end="url(#kepala)"/>`;
}

/** Periksa data sebelum menggambar. Melempar Error agar build berhenti. */
function periksa(alur, tata) {
  const utama = tata.posisi.filter((p) => p.jenis !== 'catatan');

  const mulai = utama.filter((p) => p.jenis === 'mulai').length;
  const selesai = utama.filter((p) => p.jenis === 'selesai').length;
  if (mulai !== 1 || selesai !== 1) {
    throw new Error(`${alur.berkas}: butuh tepat satu mulai dan satu selesai (dapat ${mulai}/${selesai})`);
  }

  for (const p of tata.posisi) {
    if (p.x < 0 || p.y < 0 || p.x + p.w > UKURAN.lebarKanvas || p.y + p.h > tata.tinggiKanvas) {
      throw new Error(`${alur.berkas}: simpul keluar kanvas -> "${p.teks}"`);
    }
  }

  // Irisan kotak: hanya antar kotak utama dan catatan yang bersebelahan.
  for (let i = 0; i < tata.posisi.length; i++) {
    for (let j = i + 1; j < tata.posisi.length; j++) {
      const a = tata.posisi[i];
      const b = tata.posisi[j];
      const tumpang =
        a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
      if (tumpang) {
        throw new Error(`${alur.berkas}: dua simpul beririsan -> "${a.teks}" dan "${b.teks}"`);
      }
    }
  }

  for (const p of tata.posisi) {
    if (p.jenis === 'keputusan' && (!p.ya || !p.tidak)) {
      throw new Error(`${alur.berkas}: keputusan tanpa cabang ya/tidak -> "${p.teks}"`);
    }
  }
}

function gambar(alur) {
  const tata = tataLetak(alur);
  periksa(alur, tata);

  const tengahX = UKURAN.kolomUtama + UKURAN.lebarKotak / 2;
  const utama = tata.posisi.filter((p) => p.jenis !== 'catatan');
  const bagian = [];
  const panahSvg = [];

  // Panah antar simpul utama, berurutan.
  for (let i = 0; i < utama.length - 1; i++) {
    const a = utama[i];
    const b = utama[i + 1];
    const dari = bentuk(a).bawah;
    panahSvg.push(panah(tengahX, dari, tengahX, b.y - 2));
  }

  // Label cabang ya/tidak: ditempel di sisi belah ketupat, masing-masing
  // dengan panah pendek keluar. Tanpa panahnya, label "tidak" terbaca seolah
  // belah ketupatnya berakhir di situ.
  for (let i = 0; i < utama.length; i++) {
    const p = utama[i];
    if (p.jenis !== 'keputusan') continue;
    const cy = p.y + p.h / 2;
    const cx = p.x + p.w / 2;
    panahSvg.push(
      `<line x1="${p.x + p.w}" y1="${cy}" x2="${p.x + p.w + 46}" y2="${cy}" stroke="${C.labelYa}" stroke-width="1.4" marker-end="url(#kepalaHijau)"/>`
    );
    panahSvg.push(
      `<line x1="${p.x}" y1="${cy}" x2="${p.x - 46}" y2="${cy}" stroke="${C.labelTidak}" stroke-width="1.4" marker-end="url(#kepalaMerah)"/>`
    );
    panahSvg.push(
      `<text x="${p.x + p.w + 52}" y="${cy - 8}" font-family="${FONT}" font-size="12" font-weight="700" fill="${C.labelYa}">ya</text>`
    );
    panahSvg.push(
      `<text x="${p.x - 76}" y="${cy - 8}" font-family="${FONT}" font-size="12" font-weight="700" fill="${C.labelTidak}">tidak</text>`
    );
    void cx;
  }

  for (const p of tata.posisi) bagian.push(bentuk(p).svg);

  // Garis penghubung catatan ke aksi terdekat di kirinya.
  for (const p of tata.posisi) {
    if (p.jenis !== 'catatan') continue;
    const kiri = tata.posisi
      .filter((q) => q.jenis !== 'catatan' && q.y <= p.y + p.h && q.y + q.h >= p.y)
      .sort((a, b) => b.y - a.y)[0];
    if (kiri) {
      panahSvg.push(
        `<line x1="${p.x}" y1="${p.y + p.h / 2}" x2="${kiri.x + kiri.w}" y2="${p.y + p.h / 2}" stroke="${C.catatanTepi}" stroke-width="1.2" stroke-dasharray="4 3"/>`
      );
    }
  }

  return `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="${UKURAN.lebarKanvas}" height="${tata.tinggiKanvas}" viewBox="0 0 ${UKURAN.lebarKanvas} ${tata.tinggiKanvas}" font-family="${FONT}">
  <defs>
    <marker id="kepala" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
      <path d="M 0 0 L 10 5 L 0 10 z" fill="${C.garis}"/>
    </marker>
    <marker id="kepalaHijau" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
      <path d="M 0 0 L 10 5 L 0 10 z" fill="${C.labelYa}"/>
    </marker>
    <marker id="kepalaMerah" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">
      <path d="M 0 0 L 10 5 L 0 10 z" fill="${C.labelTidak}"/>
    </marker>
  </defs>
  <rect width="100%" height="100%" fill="${C.bg}"/>
  <text x="60" y="52" font-size="24" font-weight="700" fill="${C.ink}">${esc(alur.judul.toUpperCase())}</text>
  <text x="60" y="76" font-size="13" fill="${C.sub}">Sistem Informasi Operasional Huni  -  Gambar ${esc(alur.nomor)}</text>
  ${panahSvg.join('\n  ')}
  ${bagian.join('\n  ')}
</svg>
`;
}

function utama() {
  const akar = path.join(__dirname);
  const ditulis = [];
  for (const alur of ALUR) {
    const svg = gambar(alur);
    const tujuan = path.join(akar, `${alur.berkas}.svg`);
    fs.writeFileSync(tujuan, svg, 'utf8');
    ditulis.push(`${alur.berkas}.svg (${svg.length} byte)`);
  }
  console.log('Diagram aktivitas ditulis:');
  for (const d of ditulis) console.log('  ' + d);
}

if (require.main === module) utama();

module.exports = { gambar, periksa, tataLetak, ALUR };
