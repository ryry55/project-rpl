'use strict';

/**
 * Penyaji ERD notasi Chen — dipakai untuk level konseptual dan logikal.
 * -------------------------------------------------------------------------
 * Versi ini adalah pengganti `erd-gambar.js` yang lama. Yang berubah bukan
 * gambarannya, melainkan UKURANNYA:
 *
 *   Semua ukuran huruf, kotak, belah ketupat, dan elips sekarang dihitung dari
 *   satu angka di `definisi.huruf`. Versi lama memakai angka mati (huruf 15px,
 *   kanvas 2000-3000px), sehingga nisbah huruf terhadap lebar gambar hanya
 *   0,575% - gambar terlihat "buram" begitu ditempel selebar halaman, padahal
 *   berkasnya 4000px. Menambah skala ekspor tidak menolong: nisbahnya tetap.
 *
 *   Sekarang nisbah itu dijaga di atas 1,2%, dan lembar yang terlalu padat
 *   dipecah - bukan diperkecil hurufnya.
 *
 * Kardinalitas dan partisipasi TETAP diturunkan dari `skema.json`, bukan
 * diketik, dengan aturan yang sama seperti versi lama:
 *
 *   kardinalitas sisi anak = "1" bila kolom kunci asing punya indeks unik
 *                            tunggal, "N" bila tidak;
 *   partisipasi sisi anak  = wajib bila kolomnya NOT NULL;
 *   kardinalitas sisi induk selalu "1".
 *
 * Pemeriksa yang dijalankan setiap build:
 *   1. setiap kunci asing pada entitas yang digambar HARUS tergambar atau
 *      terdaftar di `tidak_digambar` beserta alasannya;
 *   2. setiap relasi yang digambar harus benar-benar ada di basis data;
 *   3. tidak ada garis relasi yang menembus kotak entitas;
 *   4. tidak ada garis relasi yang memotong elips atribut;
 *   5. tidak ada belah ketupat yang menimpa kotak, elips, atau belah ketupat;
 *   6. tidak ada elips atribut yang menimpa kotak, elips lain, atau belah
 *      ketupat;
 *   7. huruf tidak boleh lebih kecil dari ambang keterbacaan (lihat NISBAH_MIN).
 */

const S = require('./skema.json');

const FONT = "'Segoe UI', 'Inter', 'Helvetica Neue', Arial, sans-serif";

const C = {
  bg: '#ffffff',
  ink: '#0f172a',
  sub: '#475569',
  line: '#334155',
  entFill: '#eef2ff',
  entStroke: '#3730a3',
  relFill: '#fef3c7',
  relStroke: '#b45309',
  atrFill: '#ffffff',
  atrStroke: '#64748b',
  wajib: '#b91c1c',
  opsional: '#0f766e',
  legend: '#94a3b8',
};

/**
 * Ambang keterbacaan, diukur dengan cara yang benar.
 *
 * BUKAN "ukuran huruf dibagi lebar kanvas". Cara itu menganggap gambar selalu
 * dipaskan selebar halaman, sehingga lembar tinggi-sempit dinilai jauh lebih
 * baik daripada kenyataannya - padahal lembar seperti itu justru dibatasi oleh
 * TINGGI halaman, dan hurufnya menyusut sampai tidak terbaca.
 *
 * Yang dihitung: huruf efektif setelah gambar dipaskan ke SATU halaman A4
 * potret - mana yang lebih dulu menyentuh tepi, lebar atau tinggi. Angka itu
 * lalu dibandingkan dengan lebar halaman.
 *
 * 0,012 berarti huruf setinggi 1,2% lebar halaman A4, sekitar 2,1 mm atau
 * setara 6 pt. Di bawah itu gambar masih terbaca di layar tetapi menjadi bubur
 * saat dicetak, dan itu persis keluhan yang memicu penulisan ulang ini.
 *
 * Skala ekspor 2x TIDAK menolong sama sekali: ia hanya menambah piksel, dan
 * nisbahnya tetap.
 */
const RASIO_A4 = 1.4142; // tinggi / lebar halaman A4 potret
const NISBAH_MIN = 0.012;

/** Huruf efektif bila gambar dipaskan ke satu halaman A4, sebagai rasio lebar. */
function hurufEfektif(ukuranHuruf, kanvas) {
  const skala = Math.min(1 / kanvas.w, RASIO_A4 / kanvas.h);
  return ukuranHuruf * skala;
}

/** Huruf baku; setiap level boleh menimpanya lewat `definisi.huruf`. */
const HURUF_BAKU = {
  judul: 30,
  sub: 16,
  entitas: 26,
  relasi: 15,
  atribut: 17,
  kard: 19,
  legenda: 15,
  tinggiKotak: 1.7, // kelipatan ukuran huruf entitas
  jarakAtribut: 46,
  padKotak: 26,
  lebarMinKotak: 150,
};

/** Lebar satu karakter relatif terhadap ukuran huruf, untuk font sans-serif. */
const LEBAR_HURUF = 0.6;

/**
 * Posisi baris legenda, dalam kelipatan ukuran huruf legenda. Dipakai bersama
 * oleh penghitung tinggi kotak dan oleh penggambar, supaya keduanya mustahil
 * berbeda. Sebelumnya masing-masing memakai angkanya sendiri dan baris terakhir
 * keluar dari kotak.
 */
const JARAK_AWAL_LEGENDA = 4.1;
const JARAK_BARIS_LEGENDA = 2.2;

const esc = (s) =>
  String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const r1 = (n) => Math.round(n * 10) / 10;

const petaTabel = new Map(S.tabel.map((t) => [t.nama, t]));

function kolomDari(tabel, kolom) {
  const t = petaTabel.get(tabel);
  if (!t) return null;
  return t.kolom.find((k) => k.nama === kolom) || null;
}

/** Apakah kolom ini punya indeks unik tunggal (bukan parsial, bukan gabungan)? */
function unikTunggal(tabel, kolom) {
  const t = petaTabel.get(tabel);
  if (!t) return false;
  return t.unik.some((u) => u.kolom.length === 1 && u.kolom[0] === kolom);
}

function asingDari(tabel) {
  const t = petaTabel.get(tabel);
  return t ? t.asing : [];
}

/* ------------------------------------------------------------- bentuk */

/** Huruf yang sedang dipakai build ini. Diisi ulang oleh `gambar()`. */
let H = HURUF_BAKU;
/** Definisi level yang sedang digambar. */
let D = null;

function lebarTeks(isi, ukuran) {
  return String(isi).length * LEBAR_HURUF * ukuran;
}

function kotakEntitas(e) {
  const w = Math.max(
    H.lebarMinKotak,
    lebarTeks(e.nama, H.entitas) + H.padKotak * 2
  );
  const h = H.entitas * H.tinggiKotak;
  return { nama: e.nama, x: e.x, y: e.y, w, h, cx: e.x, cy: e.y, hw: w / 2, hh: h / 2 };
}

function ukuranBelahKetupat(label) {
  const hw = Math.max(lebarTeks(label, H.relasi) + H.relasi * 2.2, H.relasi * 3.4) / 2;
  return { hw, hh: H.relasi * 1.45 };
}

function ukuranElips(teks) {
  const w = Math.max(lebarTeks(teks, H.atribut) + H.atribut * 1.8, H.atribut * 3.4);
  return { hw: w / 2, hh: H.atribut * 0.95 };
}

/** Titik potong garis dari pusat kotak menuju sebuah titik, di tepi kotak. */
function tepiKotak(b, tx, ty) {
  const dx = tx - b.cx;
  const dy = ty - b.cy;
  if (dx === 0 && dy === 0) return { x: b.cx, y: b.cy };
  const sx = dx === 0 ? Infinity : b.hw / Math.abs(dx);
  const sy = dy === 0 ? Infinity : b.hh / Math.abs(dy);
  const s = Math.min(sx, sy);
  return { x: b.cx + dx * s, y: b.cy + dy * s };
}

function tepiBelahKetupat(cx, cy, hw, hh, tx, ty) {
  const dx = tx - cx;
  const dy = ty - cy;
  if (dx === 0 && dy === 0) return { x: cx, y: cy };
  const s = 1 / (Math.abs(dx) / hw + Math.abs(dy) / hh);
  return { x: cx + dx * s, y: cy + dy * s };
}

function tepiElips(hw, hh, cx, cy, tx, ty) {
  const dx = tx - cx;
  const dy = ty - cy;
  if (dx === 0 && dy === 0) return { x: cx, y: cy };
  const s = 1 / Math.sqrt((dx * dx) / (hw * hw) + (dy * dy) / (hh * hh));
  return { x: cx + dx * s, y: cy + dy * s };
}

function garis(x1, y1, x2, y2, opsi = {}) {
  const warna = opsi.warna || C.line;
  const tebal = opsi.tebal || 2;
  return `<line x1="${r1(x1)}" y1="${r1(y1)}" x2="${r1(x2)}" y2="${r1(y2)}" stroke="${warna}" stroke-width="${tebal}" stroke-linecap="round"/>`;
}

function teks(cx, cy, isi, ukuran, tebal, warna, jangkar = 'middle', garisBawah = false) {
  return `<text x="${r1(cx)}" y="${r1(cy)}" text-anchor="${jangkar}" dominant-baseline="central" font-size="${ukuran}" font-weight="${tebal}"${garisBawah ? ' text-decoration="underline"' : ''} fill="${warna}">${esc(isi)}</text>`;
}

/* ------------------------------------------------------- pemeriksa */

function titikSegmen(x1, y1, x2, y2, langkah) {
  const titik = [];
  for (let i = 1; i < langkah; i++) {
    const t = i / langkah;
    titik.push([x1 + (x2 - x1) * t, y1 + (y2 - y1) * t]);
  }
  return titik;
}

function garisMenembusKotak(x1, y1, x2, y2, kotak, abaikan, margin = 2) {
  for (const [px, py] of titikSegmen(x1, y1, x2, y2, 240)) {
    for (const b of kotak) {
      if (abaikan.includes(b.nama)) continue;
      if (
        px > b.x - b.hw - margin &&
        px < b.x + b.hw + margin &&
        py > b.y - b.hh - margin &&
        py < b.y + b.hh + margin
      ) {
        return b.nama;
      }
    }
  }
  return null;
}

function garisMemotongElips(x1, y1, x2, y2, el) {
  for (const [px, py] of titikSegmen(x1, y1, x2, y2, 160)) {
    const dx = (px - el.x) / (el.hw + 3);
    const dy = (py - el.y) / (el.hh + 3);
    if (dx * dx + dy * dy <= 1) return true;
  }
  return false;
}

/** Jarak titik ke ruas garis; dipakai agar label tidak menempel garis lain. */
function jarakTitikSegmen(px, py, x1, y1, x2, y2) {
  const dx = x2 - x1;
  const dy = y2 - y1;
  const panjang2 = dx * dx + dy * dy;
  if (panjang2 === 0) return Math.hypot(px - x1, py - y1);
  let t = ((px - x1) * dx + (py - y1) * dy) / panjang2;
  t = Math.max(0, Math.min(1, t));
  return Math.hypot(px - (x1 + t * dx), py - (y1 + t * dy));
}

/**
 * Apakah titik berada di dalam (atau pada) belah ketupat berpusat (cx,cy)?
 * Dipakai untuk label: kotak pembatas belah ketupat jauh lebih besar daripada
 * bentuknya, sehingga pemeriksa berbasis kotak membiarkan label menempel di
 * sudut belah ketupat.
 */
function diDalamBelahKetupat(px, py, cx, cy, hw, hh) {
  if (hw <= 0 || hh <= 0) return false;
  return Math.abs(px - cx) / hw + Math.abs(py - cy) / hh <= 1;
}

/* ------------------------------------------------- susun relasi */

function susunRelasi(definisi) {
  const masalah = [];
  const namaEntitas = new Set(definisi.entitas.map((e) => e.nama));
  const terdaftar = new Set((definisi.tidak_digambar || []).map((x) => `${x.tabel}.${x.kolom}`));

  for (const e of definisi.entitas) {
    if (!petaTabel.has(e.nama)) masalah.push(`entitas "${e.nama}" tidak ada di basis data`);
  }

  const relasi = [];
  for (const r of definisi.relasi) {
    const fk = asingDari(r.anak).find((f) => f.kolom === r.kolom);
    if (!fk) {
      masalah.push(`relasi ${r.anak}.${r.kolom} tidak ada di basis data`);
      continue;
    }
    if (!namaEntitas.has(fk.ke_tabel)) {
      masalah.push(
        `relasi ${r.anak}.${r.kolom} digambar, tetapi induknya "${fk.ke_tabel}" tidak ada di daftar entitas`
      );
      continue;
    }
    const kol = kolomDari(r.anak, r.kolom);
    relasi.push({
      anak: r.anak,
      induk: fk.ke_tabel,
      kolom: r.kolom,
      label: r.label,
      kardAnak: unikTunggal(r.anak, r.kolom) ? '1' : 'N',
      kardInduk: '1',
      wajibAnak: kol ? !kol.boleh_null : false,
      diriSendiri: fk.ke_tabel === r.anak,
    });
  }

  /**
   * Relasi konseptual: hubungan banyak-ke-banyak yang di basis data disimpan
   * di tabel penghubung, sehingga tidak punya kolom kunci asing di salah satu
   * entitasnya. Pada tingkat konseptual hubungan ini digambar sebagai SATU
   * belah ketupat berkardinalitas N:N.
   *
   * Dua penjagaan, keduanya perlu:
   *
   *   1. tabel penghubungnya TIDAK boleh ikut digambar di tingkat yang sama.
   *      Kalau ikut, hubungannya tergambar dua kali - sekali sebagai
   *      belah ketupat, sekali sebagai entitas - dan pembaca akan mengira ada
   *      dua hubungan berbeda.
   *   2. kedua entitas ujungnya harus benar-benar ada.
   */
  for (const k of definisi.relasi_konseptual || []) {
    if (!namaEntitas.has(k.kiri)) {
      masalah.push(`relasi konseptual "${k.label}" menyebut "${k.kiri}" yang tidak digambar`);
      continue;
    }
    if (!namaEntitas.has(k.kanan)) {
      masalah.push(`relasi konseptual "${k.label}" menyebut "${k.kanan}" yang tidak digambar`);
      continue;
    }
    if (namaEntitas.has(k.lewat)) {
      masalah.push(
        `relasi konseptual "${k.label}" memakai tabel penghubung "${k.lewat}", ` +
          `tetapi tabel itu juga digambar sebagai entitas — hubungannya akan tergambar dua kali`
      );
      continue;
    }
    relasi.push({
      anak: k.kiri,
      induk: k.kanan,
      kolom: `lewat ${k.lewat}`,
      label: k.label,
      kardAnak: 'N',
      kardInduk: 'N',
      wajibAnak: false,
      diriSendiri: false,
      konseptual: true,
    });
  }

  // TIDAK BOLEH ada kunci asing yang hilang diam-diam.
  const dikecualikan = new Set(definisi.dikecualikan || []);
  for (const e of definisi.entitas) {
    for (const fk of asingDari(e.nama)) {
      const kunci = `${e.nama}.${fk.kolom}`;
      const digambar = relasi.some((r) => r.anak === e.nama && r.kolom === fk.kolom);
      if (digambar || terdaftar.has(kunci)) continue;
      // Entitas yang induknya tidak digambar di level ini memang tidak bisa
      // ditarik garisnya. Daftar `dikecualikan` menyebut tabel yang belum
      // muncul di level ini; build gagal bila tabel itu ternyata digambar.
      if (dikecualikan.has(fk.ke_tabel) && !namaEntitas.has(fk.ke_tabel)) continue;
      if (dikecualikan.has(e.nama)) continue;
      masalah.push(
        `kunci asing ${kunci} -> ${fk.ke_tabel}.${fk.ke_kolom} tidak digambar dan tidak terdaftar di tidak_digambar`
      );
    }
  }

  for (const x of definisi.tidak_digambar || []) {
    if (!asingDari(x.tabel).some((f) => f.kolom === x.kolom)) {
      masalah.push(`tidak_digambar menyebut ${x.tabel}.${x.kolom}, yang bukan kunci asing`);
    }
    if (!x.alasan || x.alasan.trim().length < 10) {
      masalah.push(`tidak_digambar ${x.tabel}.${x.kolom} tidak punya alasan yang cukup`);
    }
  }

  // Tabel yang dikecualikan harus benar-benar tidak digambar, dan alasannya
  // harus disebut. Tanpa pemeriksaan ini, `dikecualikan` menjadi tempat
  // menyembunyikan tabel yang sebenarnya lupa digambar.
  for (const nama of dikecualikan) {
    if (namaEntitas.has(nama)) {
      masalah.push(`"${nama}" dikecualikan, tetapi entitasnya tetap digambar`);
    }
    if (!petaTabel.has(nama)) {
      masalah.push(`"${nama}" dikecualikan, tetapi tidak ada di basis data`);
    }
  }
  const alasanKecuali = definisi.alasan_dikecualikan || '';
  if (dikecualikan.size > 0 && alasanKecuali.trim().length < 30) {
    masalah.push('ada tabel dikecualikan, tetapi `alasan_dikecualikan` tidak menjelaskan apa pun');
  }

  return { relasi, masalah };
}

/* ------------------------------------------------- penempatan belah ketupat */

function skorPosisi(dx, dy, uk, kotak, sudah) {
  let skor = 0;
  for (const b of kotak) {
    const ox = Math.max(0, uk.hw + b.hw + 12 - Math.abs(dx - b.x));
    const oy = Math.max(0, uk.hh + b.hh + 12 - Math.abs(dy - b.y));
    skor += ox * oy * 4;
  }
  for (const d of sudah) {
    const ox = Math.max(0, uk.hw + d.uk.hw + 12 - Math.abs(dx - d.x));
    const oy = Math.max(0, uk.hh + d.uk.hh + 12 - Math.abs(dy - d.y));
    skor += ox * oy * 12;
  }
  return skor;
}

function pilihPosisi(anak, induk, uk, kotak, sudah) {
  const vx = induk.x - anak.x;
  const vy = induk.y - anak.y;
  const panjang = Math.hypot(vx, vy) || 1;
  const nx = -vy / panjang;
  const ny = vx / panjang;

  let terbaik = null;
  let skorTerbaik = Infinity;

  for (const geser of [0, 45, -45, 90, -90, 135, -135, 180, -180]) {
    for (const t of [0.5, 0.44, 0.56, 0.38, 0.62, 0.32, 0.68, 0.26, 0.74, 0.2, 0.8]) {
      const dx = anak.x + vx * t + nx * geser;
      const dy = anak.y + vy * t + ny * geser;
      const skor = skorPosisi(dx, dy, uk, kotak, sudah) + Math.abs(geser) * 0.5 + Math.abs(t - 0.5) * 40;
      if (terbaik === null || skor < skorTerbaik) {
        skorTerbaik = skor;
        terbaik = { x: dx, y: dy, skor };
      }
    }
  }
  return terbaik;
}

/* ------------------------------------------------- penempatan atribut */

const SISI = ['bawah', 'atas', 'kanan', 'kiri'];

function elipsPadaSisi(b, daftar, sisi) {
  return daftar.map((d, i) => {
    const uk = ukuranElips(d.kolom);
    const geser = (i - (daftar.length - 1) / 2) * (uk.hw * 2 + H.atribut * 1.6);
    const jarak = H.jarakAtribut;

    let x;
    let y;
    if (sisi === 'bawah') {
      x = b.x + geser;
      y = b.y + b.hh + jarak;
    } else if (sisi === 'atas') {
      x = b.x + geser;
      y = b.y - b.hh - jarak;
    } else if (sisi === 'kiri') {
      x = b.x - b.hw - jarak - uk.hw;
      y = b.y + geser;
    } else {
      x = b.x + b.hw + jarak + uk.hw;
      y = b.y + geser;
    }
    return { nama: `${b.nama}.${d.kolom}`, kolom: d.kolom, kunciPrimer: d.kunciPrimer, x, y, hw: uk.hw, hh: uk.hh };
  });
}

const tumpang = (a, b, j = 4) =>
  Math.abs(a.x - b.x) < a.hw + b.hw + j && Math.abs(a.y - b.y) < a.hh + b.hh + j;

function skorSisi(b, elips, kotak, tempat, segmen, semuaElips) {
  let skor = 0;
  for (const el of elips) {
    for (const k of kotak) {
      if (tumpang(el, k, 6)) skor += 400;
    }
    for (const d of tempat) {
      if (tumpang(el, d.uk, 6)) skor += 60;
    }
    for (const lain of semuaElips) {
      if (tumpang(el, lain, 6)) skor += 50;
    }
    for (const s of segmen) {
      if (garisMemotongElips(s.x1, s.y1, s.x2, s.y2, el)) skor += 60;
    }
    const p1 = tepiKotak(b, el.x, el.y);
    const p2 = tepiElips(el.hw, el.hh, el.x, el.y, b.x, b.y);
    if (garisMenembusKotak(p1.x, p1.y, p2.x, p2.y, kotak, [b.nama], 2)) skor += 40;
  }
  return skor;
}

function tempatkanAtribut(definisi, kotak, petaKotak, tempat, segmen) {
  const hasil = [];
  const elipsTerpasang = [];

  // Entitas dengan garis terbanyak didahulukan: ia paling sulit, dan
  // keputusannya paling memengaruhi entitas lain.
  const urutan = [...definisi.entitas].sort((a, c) => {
    const na = segmen.filter((s) => s.anak === a.nama || s.induk === a.nama).length;
    const nc = segmen.filter((s) => s.anak === c.nama || s.induk === c.nama).length;
    return nc - na;
  });

  for (const e of urutan) {
    const b = petaKotak.get(e.nama);
    if (!b) continue;
    const t = petaTabel.get(e.nama);
    if (!t) continue;

    // `kunci: []` berarti level ini sengaja tidak menggambar atribut sama
    // sekali (ERD konseptual). Kunci asing TIDAK digambar sebagai elips:
    // dalam notasi Chen ia sudah diwakili belah ketupat.
    let daftar = e.kunci
      ? e.kunci.map((k) => ({ kolom: k, kunciPrimer: t.kunci_primer.includes(k) }))
      : [...t.kunci_primer].map((k) => ({ kolom: k, kunciPrimer: true }));

    if (!e.kunci) {
      const unik = t.unik.find((u) => u.kolom.length === 1 && !t.kunci_primer.includes(u.kolom[0]));
      if (unik) daftar.push({ kolom: unik.kolom[0], kunciPrimer: false });
    }

    let terbaik = null;
    let skorTerbaik = Infinity;
    for (const sisi of SISI) {
      const calon = elipsPadaSisi(b, daftar, sisi);
      const skor = skorSisi(b, calon, kotak, tempat, segmen, elipsTerpasang);
      if (terbaik === null || skor < skorTerbaik) {
        skorTerbaik = skor;
        terbaik = calon;
      }
    }

    for (const el of terbaik) elipsTerpasang.push(el);
    hasil.push({ entitas: e.nama, elips: terbaik, skor: skorTerbaik });
  }

  return hasil;
}

/* -------------------------------------------------------------- legenda */

/**
 * Hitung baris legenda. Dipisah menjadi fungsi karena hasilnya dipakai DUA
 * kali: sekali untuk mencadangkan ruang di kanvas, sekali untuk menggambar.
 *
 * Kalau dihitung di tempat menggambar saja, kanvas tidak tahu legenda butuh
 * berapa baris, dan baris terakhir akan terpotong di tepi bawah. Itu pernah
 * terjadi ketika legenda bertambah dari 2 baris menjadi 3.
 */
function hitungLegenda(definisi, relasi) {
  const baris = [];
  baris.push('1 = tepat satu · N = banyak · garis ganda = partisipasi wajib (kolom NOT NULL)');

  // Relasi konseptual (M:N yang di basis data disimpan di tabel penghubung)
  // WAJIB dijelaskan. Tanpa keterangan, pembaca akan mengira belah ketupat itu
  // punya kolom kunci asing - padahal justru sebaliknya, ia ada karena belum
  // punya kolom.
  const konseptual = (definisi.relasi_konseptual || []).map(
    (k) => `${k.kiri} —${k.label}— ${k.kanan} (lewat tabel ${k.lewat})`
  );
  if (konseptual.length > 0) {
    baris.push(`relasi banyak-ke-banyak: ${konseptual.join(' · ')}`);
  }

  // "Berdiri sendiri" harus berarti TIDAK ADA GARIS SAMA SEKALI di gambar ini,
  // bukan "tidak punya kunci asing di basis data". Versi sebelumnya memakai
  // kunci asing, sehingga `fasilitas` disebut berdiri sendiri padahal ia
  // terhubung lewat relasi konseptual `memiliki` - legenda yang membantah
  // gambarnya sendiri, dan tidak ada yang menangkapnya.
  const tersentuh = new Set();
  for (const r of relasi) {
    tersentuh.add(r.anak);
    tersentuh.add(r.induk);
  }
  const tanpaRelasi = definisi.entitas
    .map((e) => e.nama)
    .filter((nama) => !tersentuh.has(nama))
    .sort();
  if (tanpaRelasi.length > 0) {
    baris.push(`tanpa garis di gambar ini: ${tanpaRelasi.join(' · ')}`);
  }

  // Tinggi kotak legenda DITURUNKAN dari posisi baris terakhir, bukan dihitung
  // dengan rumus sendiri. Sebelumnya keduanya memakai pengali berbeda
  // (2,1 untuk tinggi, 2,2 untuk jarak baris), sehingga baris terakhir keluar
  // dari kotaknya begitu legenda bertambah dari 2 menjadi 3 baris - dan tidak
  // ada pemeriksa yang menangkapnya karena teks bukan kotak.
  const tinggi = H.legenda * (JARAK_AWAL_LEGENDA + (baris.length - 1) * JARAK_BARIS_LEGENDA + 1.2);

  return { baris, tinggi, tanpaRelasi };
}

/* ------------------------------------------------------------- gambar */

function gambar(definisi) {
  const masalah = [];

  H = { ...HURUF_BAKU, ...(definisi.huruf || {}) };
  D = definisi;

  const { relasi, masalah: masalahRelasi } = susunRelasi(definisi);
  masalah.push(...masalahRelasi);

  const kotak = definisi.entitas.map(kotakEntitas);
  const petaKotak = new Map(kotak.map((b) => [b.nama, b]));

  // --- a. tempatkan belah ketupat ---
  const tempat = [];
  for (const r of relasi) {
    const anak = petaKotak.get(r.anak);
    const induk = petaKotak.get(r.induk);
    if (!anak || !induk) continue;
    const uk = ukuranBelahKetupat(r.label);

    if (r.diriSendiri) {
      tempat.push({ ...r, x: anak.x + anak.hw + 150, y: anak.y, uk, skor: 0 });
      continue;
    }

    const pos = pilihPosisi(anak, induk, uk, kotak, tempat);
    tempat.push({ ...r, x: pos.x, y: pos.y, uk, skor: pos.skor });
  }

  // --- b. hitung segmen garis ---
  const segmen = [];
  for (const d of tempat) {
    const anak = petaKotak.get(d.anak);
    const induk = petaKotak.get(d.induk);
    if (!anak || !induk) continue;
    const { hw, hh } = d.uk;

    if (d.diriSendiri) {
      const a1 = { x: anak.x + anak.hw, y: anak.y - 15 };
      const a2 = { x: anak.x + anak.hw, y: anak.y + 15 };
      const milik = `${d.anak}.${d.kolom}`;
      segmen.push({ x1: a1.x, y1: a1.y, x2: d.x, y2: d.y, anak: d.anak, induk: d.induk, milik });
      segmen.push({ x1: a2.x, y1: a2.y, x2: d.x, y2: d.y, anak: d.anak, induk: d.induk, milik });
      continue;
    }

    const a = tepiKotak(anak, d.x, d.y);
    const b = tepiKotak(induk, d.x, d.y);
    const pa = tepiBelahKetupat(d.x, d.y, hw, hh, a.x, a.y);
    const pb = tepiBelahKetupat(d.x, d.y, hw, hh, b.x, b.y);
    const milik = `${d.anak}.${d.kolom}`;
    segmen.push({ x1: a.x, y1: a.y, x2: pa.x, y2: pa.y, anak: d.anak, induk: d.induk, milik });
    segmen.push({ x1: pb.x, y1: pb.y, x2: b.x, y2: b.y, anak: d.anak, induk: d.induk, milik });
  }

  // --- c. tentukan sisi atribut ---
  const penempatan = tempatkanAtribut(definisi, kotak, petaKotak, tempat, segmen);
  const semuaElips = penempatan.flatMap((p) => p.elips);

  // Legenda dihitung lebih dulu: jumlah barisnya menentukan berapa ruang yang
  // harus dicadangkan di bawah kanvas.
  const legenda = hitungLegenda(definisi, relasi);

  // --- c2. kanvas otomatis ---
  //
  // Ukuran kanvas dihitung dari simbol yang benar-benar tergambar, bukan
  // dipatok di berkas data. Versi lama mematoknya, dan akibatnya setiap kali
  // tata letak diubah, kanvas harus ikut diubah - kalau lupa, gambarnya
  // terpotong tanpa ada yang gagal. Sekarang kanvas mengikuti isinya.
  if (definisi.kanvas.auto) {
    let maxX = 0;
    let maxY = 0;
    let minX = Infinity;
    let minY = Infinity;

    const catat = (x, y, hw, hh) => {
      maxX = Math.max(maxX, x + hw);
      maxY = Math.max(maxY, y + hh);
      minX = Math.min(minX, x - hw);
      minY = Math.min(minY, y - hh);
    };

    for (const b of kotak) catat(b.x, b.y, b.hw, b.hh);
    for (const el of semuaElips) catat(el.x, el.y, el.hw, el.hh);
    for (const d of tempat) catat(d.x, d.y, d.uk.hw, d.uk.hh);

    const tepi = 70;
    const atas = Math.max(minY, 70 + H.judul * 2.8);

    // Geser seluruh simbol agar tidak ada yang menempel di tepi kiri/atas.
    const geserX = Math.max(0, tepi - minX);
    const geserY = Math.max(0, atas - minY);
    if (geserX > 0 || geserY > 0) {
      for (const b of kotak) {
        b.x += geserX;
        b.y += geserY;
        b.cx = b.x;
        b.cy = b.y;
      }
      for (const el of semuaElips) {
        el.x += geserX;
        el.y += geserY;
      }
      for (const d of tempat) {
        d.x += geserX;
        d.y += geserY;
      }
      for (const s of segmen) {
        s.x1 += geserX;
        s.y1 += geserY;
        s.x2 += geserX;
        s.y2 += geserY;
      }
      maxX += geserX;
      maxY += geserY;
    }

    definisi.kanvas.w = Math.ceil(maxX + tepi);
    definisi.kanvas.h = Math.ceil(maxY + tepi + legenda.tinggi + 60);
  }

  // --- d. gambar ---
  const isi = [];
  isi.push(teks(70, 62, definisi.judul, H.judul, 700, C.ink, 'start'));
  isi.push(teks(70, 62 + H.sub * 1.9, definisi.sub, H.sub, 400, C.sub, 'start'));

  /**
   * Label kardinalitas DIKUMPULKAN dulu, baru digambar.
   *
   * Versi sebelumnya menaruh label di 24% jarak kotak->belah ketupat, selalu
   * di sisi yang sama. Dua akibatnya, keduanya terlihat di gambar:
   *
   *   1. bila jaraknya pendek, 24% itu masih menempel di tepi kotak, dan
   *      labelnya tercetak di atas elips atribut yang kebetulan di sisi itu;
   *   2. bila dua relasi keluar dari kotak yang sama, labelnya jatuh di titik
   *      yang hampir sama dan tercetak bertumpuk.
   *
   * Karena itu label dikumpulkan, digeser menjauhi simbol lain, lalu DIPERIKSA.
   * Teks bukan kotak, jadi tidak satu pun pemeriksa tumpang-tindih di bawah yang
   * menangkapnya tanpa pemeriksaan khusus.
   */
  const labelKard = [];

  const taruhLabel = (calon, isiLabel, warna) => {
    const ukuran = H.kard;
    const hw = (lebarTeks(isiLabel, ukuran) + 6) / 2;
    const hh = ukuran * 0.62;

    // Label tidak boleh menempel garis milik RELASI LAIN. Tanpa syarat ini,
    // label `N` pada relasi diri tercetak tepat di atas garis gandanya sendiri —
    // dan pemeriksa di bawah, yang hanya membandingkan label dengan kotak dan
    // belah ketupat, tidak pernah menangkapnya.
    const bebas = (c) => {
      const kotakLabel = { x: c.x, y: c.y, hw, hh };
      for (const s of segmen) {
        if (s.milik === c.milik) continue;
        if (jarakTitikSegmen(c.x, c.y, s.x1, s.y1, s.x2, s.y2) < hh * 1.6) return false;
      }
      for (const k of kotak) {
        if (tumpang(kotakLabel, k, 2)) return false;
      }
      for (const el of semuaElips) {
        if (tumpang(kotakLabel, el, 2)) return false;
      }
      for (const d of tempat) {
        // Kotak pembatas belah ketupat jauh lebih besar daripada bentuknya, jadi
        // `tumpang` saja tidak cukup: label bisa "lolos" tepat di sudut
        // belah ketupat dan tetap tercetak menempel di atasnya. Yang diperiksa
        // di sini bentuk belah ketupatnya, diperlebar setengah lebar label —
        // cukup untuk menjauhkan glyph dari tepi, tanpa menuntut lorong selebar
        // label penuh (lorong relasi memang sempit).
        if (diDalamBelahKetupat(c.x, c.y, d.x, d.y, d.uk.hw + hw * 0.6, d.uk.hh + hh * 0.6)) {
          return false;
        }
      }
      for (const l of labelKard) {
        if (tumpang(kotakLabel, l, 2)) return false;
      }
      return true;
    };

    const pilih = calon.find(bebas);
    if (!pilih) {
      masalah.push(
        `tidak ada tempat untuk label kardinalitas "${isiLabel}" pada relasi ${calon[0]?.milik} — tata letak terlalu padat`
      );
      return;
    }
    labelKard.push({ x: pilih.x, y: pilih.y, isi: isiLabel, warna, hw, hh, milik: pilih.milik });
  };

  /**
   * @param milik kunci relasi `${anak}.${kolom}` pemilik label ini. Dipakai agar
   *   label boleh berdekatan dengan garis relasinya SENDIRI — pada relasi diri,
   *   dua garisnya memang berdekatan dan tidak mungkin dijauhkan — tetapi tidak
   *   boleh menempel garis relasi lain.
   */
  const calonLabel = (px, py, qx, qy, arah, milik) => {
    const dx = qx - px;
    const dy = qy - py;
    const panjang = Math.hypot(dx, dy) || 1;
    const nx = -dy / panjang;
    const ny = dx / panjang;
    const daftar = [];

    for (const t of [0.5, 0.42, 0.58, 0.34, 0.66]) {
      // Jarak tegak lurus TIDAK boleh lebih kecil dari tinggi label, kalau tidak
      // label akan menempel garisnya sendiri. Batas bawahnya karena itu
      // mengikuti H.kard, bukan panjang segmen.
      const dasar = Math.max(H.kard * 1.35, Math.min(H.kard * 2.2, panjang * 0.22));
      for (const kali of [1, 1.45, 2]) {
        const geser = arah * dasar * kali;
        daftar.push({ x: px + dx * t + nx * geser, y: py + dy * t + ny * geser, milik });
      }
    }

    // Calon tambahan: digeser MENJAUHI ujung `p` sepanjang sumbu x lalu
    // diturunkan/dinaikkan. Diperlukan pada relasi diri, tempat kedua garis
    // keluar dari titik yang sama sehingga seluruh calon tegak lurus jatuh di
    // dalam belah ketupat.
    const ux = dx / panjang;
    const uy = dy / panjang;
    for (const geserTegak of [H.kard * 1.5, H.kard * 2.2]) {
      for (const geserSusur of [-H.kard * 1.2, H.kard * 1.2, 0]) {
        daftar.push({
          x: px + dx * 0.5 + nx * arah * geserTegak + ux * geserSusur,
          y: py + dy * 0.5 + ny * arah * geserTegak + uy * geserSusur,
          milik,
        });
      }
    }
    return daftar;
  };

  for (const d of tempat) {
    const anak = petaKotak.get(d.anak);
    const induk = petaKotak.get(d.induk);
    const { hw, hh } = d.uk;

    if (d.diriSendiri) {
      const a1 = { x: anak.x + anak.hw, y: anak.y - 15 };
      const a2 = { x: anak.x + anak.hw, y: anak.y + 15 };
      const b1 = tepiBelahKetupat(d.x, d.y, hw, hh, a1.x, a1.y);
      const b2 = tepiBelahKetupat(d.x, d.y, hw, hh, a2.x, a2.y);
      isi.push(garis(a1.x, a1.y, b1.x, b1.y));
      isi.push(garis(a2.x, a2.y, b2.x, b2.y));
      isi.push(
        `<polygon points="${r1(d.x - hw)},${r1(d.y)} ${r1(d.x)},${r1(d.y - hh)} ${r1(d.x + hw)},${r1(d.y)} ${r1(d.x)},${r1(d.y + hh)}" fill="${C.relFill}" stroke="${C.relStroke}" stroke-width="2"/>`
      );
      isi.push(teks(d.x, d.y, d.label, H.relasi, 600, C.relStroke));
      const milik = `${d.anak}.${d.kolom}`;
      taruhLabel(
        calonLabel(a1.x, a1.y, b1.x, b1.y, 1, milik),
        d.kardAnak,
        d.kardAnak === 'N' ? C.wajib : C.opsional
      );
      taruhLabel(calonLabel(a2.x, a2.y, b2.x, b2.y, -1, milik), d.kardInduk, C.opsional);
      continue;
    }

    const a = tepiKotak(anak, d.x, d.y);
    const b = tepiKotak(induk, d.x, d.y);
    const pa = tepiBelahKetupat(d.x, d.y, hw, hh, a.x, a.y);
    const pb = tepiBelahKetupat(d.x, d.y, hw, hh, b.x, b.y);

    isi.push(garis(a.x, a.y, pa.x, pa.y));
    if (d.wajibAnak) {
      const len = Math.hypot(pa.x - a.x, pa.y - a.y) || 1;
      const nx = (-(pa.y - a.y) / len) * 4.2;
      const ny = ((pa.x - a.x) / len) * 4.2;
      isi.push(garis(a.x + nx, a.y + ny, pa.x + nx, pa.y + ny));
    }
    isi.push(garis(pb.x, pb.y, b.x, b.y));

    {
      const milik = `${d.anak}.${d.kolom}`;
      taruhLabel(
        calonLabel(a.x, a.y, pa.x, pa.y, 1, milik),
        d.kardAnak,
        d.kardAnak === 'N' ? C.wajib : C.opsional
      );
    }

    {
      const milik = `${d.anak}.${d.kolom}`;
      taruhLabel(calonLabel(b.x, b.y, pb.x, pb.y, 1, milik), d.kardInduk, C.opsional);
    }

    isi.push(
      `<polygon points="${r1(d.x - hw)},${r1(d.y)} ${r1(d.x)},${r1(d.y - hh)} ${r1(d.x + hw)},${r1(d.y)} ${r1(d.x)},${r1(d.y + hh)}" fill="${C.relFill}" stroke="${C.relStroke}" stroke-width="2"/>`
    );
    isi.push(teks(d.x, d.y, d.label, H.relasi, 600, C.relStroke));

    const tembus = garisMenembusKotak(a.x, a.y, pa.x, pa.y, kotak, [d.anak, d.induk]);
    if (tembus) masalah.push(`garis ${d.anak}.${d.kolom} menembus kotak "${tembus}"`);
    const tembus2 = garisMenembusKotak(pb.x, pb.y, b.x, b.y, kotak, [d.anak, d.induk]);
    if (tembus2) masalah.push(`garis ${d.anak}.${d.kolom} (sisi induk) menembus kotak "${tembus2}"`);
  }

  for (const b of kotak) {
    isi.push(
      `<rect x="${r1(b.x - b.hw)}" y="${r1(b.y - b.hh)}" width="${r1(b.w)}" height="${r1(b.h)}" rx="8" fill="${C.entFill}" stroke="${C.entStroke}" stroke-width="2.5"/>`
    );
    isi.push(teks(b.x, b.y, b.nama, H.entitas, 700, C.entStroke));
  }

  for (const p of penempatan) {
    const b = petaKotak.get(p.entitas);
    if (!b) continue;
    for (const el of p.elips) {
      isi.push(
        `<ellipse cx="${r1(el.x)}" cy="${r1(el.y)}" rx="${r1(el.hw)}" ry="${r1(el.hh)}" fill="${C.atrFill}" stroke="${C.atrStroke}" stroke-width="1.8"/>`
      );
      isi.push(teks(el.x, el.y, el.kolom, H.atribut, 600, C.sub, 'middle', el.kunciPrimer));

      const p1 = tepiKotak(b, el.x, el.y);
      const p2 = tepiElips(el.hw, el.hh, el.x, el.y, b.x, b.y);
      isi.push(garis(p1.x, p1.y, p2.x, p2.y, { warna: C.atrStroke, tebal: 1.3 }));
    }
  }

  // --- d2. label kardinalitas ---
  //
  // Label digeser menjauhi kotak, elips, belah ketupat, dan label lain. Lalu
  // DIPERIKSA: bila masih bertumpuk, build gagal. Sebelumnya label hanya
  // diletakkan pada satu rumus tetap dan tidak pernah diperiksa, sehingga label
  // `N` di `tarif_sewa` tercetak di atas elips `id` tanpa ada yang gagal.
  for (const l of labelKard) {
    for (let putaran = 0; putaran < 40; putaran++) {
      let geser = null;

      for (const k of kotak) {
        if (tumpang(l, k, 3)) {
          geser = { x: 0, y: k.y > l.y ? -(l.hh + 10) : l.hh + 10 };
          break;
        }
      }
      if (!geser) {
        for (const el of semuaElips) {
          if (tumpang(l, el, 3)) {
            geser = { x: el.x > l.x ? -(l.hw + 8) : l.hw + 8, y: 0 };
            break;
          }
        }
      }
      if (!geser) {
        for (const d of tempat) {
          if (tumpang(l, d.uk, 3)) {
            geser = { x: 0, y: d.y > l.y ? -(l.hh + 8) : l.hh + 8 };
            break;
          }
        }
      }
      if (!geser) {
        for (const lain of labelKard) {
          if (lain === l) continue;
          if (tumpang(l, lain, 4)) {
            geser = { x: 0, y: l.hh + lain.hh + 6 };
            break;
          }
        }
      }
      if (!geser) break;
      l.x += geser.x;
      l.y += geser.y;
    }
  }

  for (const l of labelKard) {
    isi.push(teks(l.x, l.y, l.isi, H.kard, 700, l.warna));
  }

  // --- e. periksa ulang ---
  //
  // Pemeriksa label: teks bukan kotak, jadi pemeriksa tumpang-tindih di bawah
  // tidak menangkapnya. Tanpa blok ini, label kardinalitas yang tercetak di atas
  // elips atribut atau di atas label lain lolos tanpa tanda apa pun - dan
  // hasilnya terbaca sebagai satu glyph rusak di tengah diagram.
  for (let i = 0; i < labelKard.length; i++) {
    const l = labelKard[i];
    for (const k of kotak) {
      if (tumpang(l, k, 2)) masalah.push(`label kardinalitas "${l.isi}" menimpa kotak "${k.nama}"`);
    }
    for (const el of semuaElips) {
      if (tumpang(l, el, 2)) masalah.push(`label kardinalitas "${l.isi}" menimpa atribut ${el.nama}`);
    }
    for (const d of tempat) {
      // Kotak pembatas belah ketupat jauh lebih besar daripada bentuknya, jadi
      // label bisa menempel di sudutnya tanpa terdeteksi `tumpang`. Bentuk
      // belah ketupatnya yang diperiksa, diperlebar selebar label.
      if (diDalamBelahKetupat(l.x, l.y, d.x, d.y, d.uk.hw + l.hw, d.uk.hh + l.hh)) {
        masalah.push(`label kardinalitas "${l.isi}" menimpa belah ketupat ${d.anak}.${d.kolom}`);
      }
    }
    for (let j = i + 1; j < labelKard.length; j++) {
      if (tumpang(labelKard[i], labelKard[j], 2)) {
        masalah.push(
          `label kardinalitas "${labelKard[i].isi}" dan "${labelKard[j].isi}" bertumpuk ` +
            `di (${Math.round(labelKard[i].x)},${Math.round(labelKard[i].y)})`
        );
      }
    }
    if (
      l.x - l.hw < 0 ||
      l.y - l.hh < 0 ||
      l.x + l.hw > definisi.kanvas.w ||
      l.y + l.hh > definisi.kanvas.h
    ) {
      masalah.push(`label kardinalitas "${l.isi}" keluar kanvas`);
    }
  }

  for (const d of tempat) {
    for (const k of kotak) {
      if (tumpang(d.uk, k, 2)) masalah.push(`belah ketupat ${d.anak}.${d.kolom} menimpa kotak "${k.nama}"`);
    }
    for (const el of semuaElips) {
      if (tumpang(d.uk, el, 2)) masalah.push(`belah ketupat ${d.anak}.${d.kolom} menimpa atribut ${el.nama}`);
    }
  }
  for (let i = 0; i < tempat.length; i++) {
    for (let j = i + 1; j < tempat.length; j++) {
      if (tumpang(tempat[i].uk, tempat[j].uk, 2)) {
        masalah.push(`belah ketupat ${tempat[i].anak}.${tempat[i].kolom} menimpa ${tempat[j].anak}.${tempat[j].kolom}`);
      }
    }
  }
  for (const el of semuaElips) {
    for (const k of kotak) {
      if (tumpang(el, k, 2)) masalah.push(`atribut ${el.nama} menimpa kotak "${k.nama}"`);
    }
  }
  for (let i = 0; i < semuaElips.length; i++) {
    for (let j = i + 1; j < semuaElips.length; j++) {
      if (tumpang(semuaElips[i], semuaElips[j], 2)) {
        masalah.push(`atribut ${semuaElips[i].nama} menimpa ${semuaElips[j].nama}`);
      }
    }
  }
  for (const s of segmen) {
    for (const el of semuaElips) {
      if (garisMemotongElips(s.x1, s.y1, s.x2, s.y2, el)) {
        masalah.push(`garis ${s.anak} memotong atribut ${el.nama}`);
      }
    }
  }

  // Label kardinalitas tidak boleh menempel garis mana pun. Pemeriksa di atas
  // hanya membandingkan label dengan kotak, elips, dan belah ketupat; garis
  // tidak pernah dibandingkan, sehingga label `N` pada relasi diri tercetak
  // tepat di atas garis gandanya dan lolos tanpa tanda apa pun.
  for (const l of labelKard) {
    for (const s of segmen) {
      if (s.milik === l.milik) continue;
      const jarak = jarakTitikSegmen(l.x, l.y, s.x1, s.y1, s.x2, s.y2);
      if (jarak < l.hh * 1.2) {
        masalah.push(
          `label kardinalitas "${l.isi}" menempel garis ${s.anak}.${s.induk} ` +
            `(jarak ${Math.round(jarak)} px)`
        );
      }
    }
  }

  // --- f. keterbacaan ---
  const nisbah = hurufEfektif(H.entitas, definisi.kanvas);
  if (nisbah < NISBAH_MIN) {
    masalah.push(
      `huruf efektif hanya ${(nisbah * 100).toFixed(2)}% lebar halaman A4 ` +
        `(minimal ${(NISBAH_MIN * 100).toFixed(1)}%) — pecah lembarnya; ` +
        `menambah skala ekspor tidak mengubah nisbah`
    );
  }

  // --- g. legenda ---
  const tinggiLegenda = legenda.tinggi;

  // Lebar baris pertama (baris simbol) dihitung dulu supaya kotak legenda tidak
  // dibuat lebih sempit daripada isinya. Versi lama hanya mengukur baris teks,
  // sehingga baris simbol bisa melewati tepi kanan kotak.
  const lebarEntitasKotak = H.legenda * 3.2;
  const jxElips = 94 + lebarEntitasKotak + 14 + lebarTeks('entitas', H.legenda) + H.legenda * 4;
  const jxDiam =
    jxElips + H.legenda * 2.1 + lebarTeks('atribut · kunci digaris bawahi', H.legenda) + H.legenda * 4;
  const lebarBarisSimbol = jxDiam + H.legenda * 1.1 * 1.7 + 14 + lebarTeks('relasi', H.legenda) - 70;

  const barisTerpanjang = legenda.baris.reduce(
    (n, b) => Math.max(n, lebarTeks(b, H.legenda) + 24),
    lebarBarisSimbol
  );
  const ly = definisi.kanvas.h - tinggiLegenda - 46;
  const lebarLegenda = Math.min(definisi.kanvas.w - 140, Math.max(barisTerpanjang, 900) + 40);

  isi.push(
    `<rect x="70" y="${ly}" width="${r1(lebarLegenda)}" height="${r1(tinggiLegenda)}" rx="10" fill="#fafafa" stroke="${C.legend}" stroke-width="1"/>`
  );

  const yBaris1 = ly + H.legenda * 1.9;
  isi.push(`<rect x="94" y="${r1(yBaris1 - H.legenda * 0.78)}" width="${r1(lebarEntitasKotak)}" height="${r1(H.legenda * 1.56)}" rx="5" fill="${C.entFill}" stroke="${C.entStroke}" stroke-width="2"/>`);
  isi.push(teks(94 + lebarEntitasKotak + 14, yBaris1, 'entitas', H.legenda, 500, C.sub, 'start'));

  isi.push(`<ellipse cx="${r1(jxElips)}" cy="${r1(yBaris1)}" rx="${r1(H.legenda * 1.7)}" ry="${r1(H.legenda * 0.8)}" fill="${C.atrFill}" stroke="${C.atrStroke}" stroke-width="1.8"/>`);
  isi.push(teks(jxElips + H.legenda * 2.1, yBaris1, 'atribut · kunci digaris bawahi', H.legenda, 500, C.sub, 'start'));

  const dr = H.legenda * 1.1;
  isi.push(`<polygon points="${r1(jxDiam)},${r1(yBaris1 - dr)} ${r1(jxDiam + dr * 1.7)},${r1(yBaris1)} ${r1(jxDiam)},${r1(yBaris1 + dr)} ${r1(jxDiam - dr * 1.7)},${r1(yBaris1)}" fill="${C.relFill}" stroke="${C.relStroke}" stroke-width="2"/>`);
  isi.push(teks(jxDiam + dr * 1.7 + 14, yBaris1, 'relasi', H.legenda, 500, C.sub, 'start'));

  legenda.baris.forEach((b, i) => {
    isi.push(
      teks(
        94,
        ly + H.legenda * (JARAK_AWAL_LEGENDA + i * JARAK_BARIS_LEGENDA),
        b,
        H.legenda,
        400,
        C.sub,
        'start'
      )
    );
  });

  /**
   * Pemeriksa legenda — tiga hal yang dulu lolos tanpa tanda apa pun:
   *
   *   1. baris terakhir keluar dari kotak legenda (pernah terjadi saat legenda
   *      bertambah dari 2 ke 3 baris);
   *   2. baris terpanjang lebih lebar dari kotaknya, sehingga teksnya terpotong
   *      di tepi kanan;
   *   3. kotak legenda keluar dari kanvas.
   *
   * Teks bukan kotak, jadi tidak satu pun pemeriksa tumpang-tindih di atas yang
   * menangkapnya. Ketiganya harus diperiksa dengan hitungan eksplisit.
   */
  const bawahTeksTerakhir =
    ly + H.legenda * (JARAK_AWAL_LEGENDA + (legenda.baris.length - 1) * JARAK_BARIS_LEGENDA) + H.legenda * 0.7;
  if (bawahTeksTerakhir > ly + tinggiLegenda) {
    masalah.push(
      `baris terakhir legenda keluar dari kotaknya ` +
        `(${Math.round(bawahTeksTerakhir)} > ${Math.round(ly + tinggiLegenda)}) — tambah tinggi kotak legenda`
    );
  }
  if (barisTerpanjang + 24 > lebarLegenda) {
    masalah.push(
      `baris legenda terpanjang (${Math.round(barisTerpanjang)} px) lebih lebar dari kotaknya ` +
        `(${Math.round(lebarLegenda)} px) — teksnya akan terpotong`
    );
  }
  if (ly < 0 || ly + tinggiLegenda > definisi.kanvas.h) {
    masalah.push('kotak legenda keluar dari kanvas');
  }

  const svg = [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${definisi.kanvas.w}" height="${definisi.kanvas.h}" viewBox="0 0 ${definisi.kanvas.w} ${definisi.kanvas.h}" font-family="${FONT}">`,
    `<title>${esc(definisi.judul)}</title>`,
    `<rect width="${definisi.kanvas.w}" height="${definisi.kanvas.h}" fill="${C.bg}"/>`,
    ...isi,
    '</svg>',
    '',
  ].join('\n');

  return { svg, masalah, jumlahRelasi: relasi.length, nisbah };
}

module.exports = { gambar, susunRelasi, petaTabel, NISBAH_MIN };
