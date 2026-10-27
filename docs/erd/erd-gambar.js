'use strict';

/**
 * Penyaji ERD notasi Chen — dipakai oleh `build.js`.
 * -------------------------------------------------------------------------
 * Berkas ini hanya tahu cara MENGGAMBAR. Relasi, kardinalitas, dan partisipasi
 * dibaca dari `skema.json` (hasil `ambil-skema.js`), bukan dari `erd-data.js`.
 * `erd-data.js` hanya menyumbang tata letak dan kata kerja pada belah ketupat.
 *
 * Kenapa kardinalitas diturunkan, bukan diketik:
 *
 *   kardinalitas sisi anak = "1" bila kolom kunci asingnya punya indeks unik,
 *                            "N" bila tidak;
 *   partisipasi sisi anak  = wajib bila kolomnya NOT NULL, opsional bila boleh
 *                            null;
 *   kardinalitas sisi induk selalu "1".
 *
 * Kalau angka-angka itu diketik tangan, satu perubahan migrasi (mis. menambah
 * indeks unik baru) akan membuat gambar berbohong tanpa ada yang gagal. Dengan
 * cara ini, gambar ikut berubah sendiri.
 *
 * TIGA KALI TATA LETAK INI SALAH, DAN SETIAP KALINYA DIPERBAIKI DI AKARNYA:
 *
 *   1. Belah ketupat dipatok pada 62% jarak anak->induk. Empat bertumpuk.
 *      Diperbaiki dengan mencari posisi berskor terendah.
 *   2. Semua elips atribut ditaruh di bawah entitas. Garis yang mengarah ke
 *      bawah memotong elips, dan pemeriksa tidak menangkapnya karena hanya
 *      membandingkan belah ketupat dengan KOTAK.
 *   3. Elips ditaruh di sisi berlawanan arah garis KELUAR. Masih salah: garis
 *      MASUK juga memotongnya, dan elips entitas yang berdekatan saling
 *      menimpa.
 *
 * Versi ini tidak lagi menebak sisi. Urutannya:
 *
 *   a. tempatkan belah ketupat (menghindari kotak dan belah ketupat lain);
 *   b. hitung seluruh segmen garis yang sudah pasti;
 *   c. untuk tiap entitas, COBA KEEMPAT SISI dan pilih yang skornya terendah,
 *      dengan skor = tumpang tindih kotak + tumpang tindih belah ketupat +
 *      tumpang tindih elips lain + garis yang memotong elips;
 *   d. gambar, lalu periksa ulang keenam hal di bawah.
 *
 * Pemeriksa yang dijalankan setiap build:
 *   1. setiap kunci asing pada entitas yang digambar HARUS tergambar atau
 *      terdaftar di `tidak_digambar` beserta alasannya;
 *   2. setiap relasi yang digambar harus benar-benar ada di basis data;
 *   3. tidak ada garis relasi yang menembus kotak entitas;
 *   4. tidak ada garis relasi yang memotong elips atribut;
 *   5. tidak ada belah ketupat yang menimpa kotak, elips, atau belah ketupat lain;
 *   6. tidak ada elips atribut yang menimpa kotak, elips lain, atau belah ketupat.
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

/* ------------------------------------------------------------- utilitas */

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

/** Seluruh kunci asing pada sebuah tabel, menurut basis data. */
function asingDari(tabel) {
  const t = petaTabel.get(tabel);
  return t ? t.asing : [];
}

/* ------------------------------------------------------------- bentuk */

function kotakEntitas(e) {
  const w = Math.max(148, e.nama.length * 10.6 + 40);
  return { nama: e.nama, x: e.x, y: e.y, w, h: 62, cx: e.x, cy: e.y, hw: w / 2, hh: 31 };
}

/**
 * Belah ketupat dibuat sekecil mungkin sambil tetap memuat labelnya.
 * Ukuran yang kelewat besar adalah penyebab utama tumpang tindih pada diagram
 * 23 entitas: yang bertabrakan bukan garisnya, melainkan kotak kosong di dalam
 * belah ketupat.
 */
function ukuranBelahKetupat(label) {
  const hw = Math.max(74, label.length * 6.6 + 30) / 2;
  return { hw, hh: 25 };
}

function ukuranElips(teks) {
  const w = Math.max(74, teks.length * 7.2 + 22);
  return { hw: w / 2, hh: 16 };
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

/** Titik potong garis dari pusat belah ketupat menuju sebuah titik. */
function tepiBelahKetupat(cx, cy, hw, hh, tx, ty) {
  const dx = tx - cx;
  const dy = ty - cy;
  if (dx === 0 && dy === 0) return { x: cx, y: cy };
  const s = 1 / (Math.abs(dx) / hw + Math.abs(dy) / hh);
  return { x: cx + dx * s, y: cy + dy * s };
}

/** Titik potong garis dari pusat elips menuju sebuah titik. */
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

/** Potong-potong segmen menjadi titik-titik untuk pemeriksaan. */
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

/** Apakah garis memotong sebuah elips. */
function garisMemotongElips(x1, y1, x2, y2, el) {
  for (const [px, py] of titikSegmen(x1, y1, x2, y2, 160)) {
    const dx = (px - el.x) / (el.hw + 3);
    const dy = (py - el.y) / (el.hh + 3);
    if (dx * dx + dy * dy <= 1) return true;
  }
  return false;
}

/**
 * Bangun deskripsi relasi dari kunci asing, lalu periksa kelengkapannya.
 */
function susunRelasi(definisi) {
  const masalah = [];
  const namaEntitas = new Set(definisi.entitas.map((e) => e.nama));
  const terdaftar = new Set(definisi.tidak_digambar.map((x) => `${x.tabel}.${x.kolom}`));

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

  // TIDAK BOLEH ada kunci asing yang hilang diam-diam.
  for (const e of definisi.entitas) {
    for (const fk of asingDari(e.nama)) {
      const kunci = `${e.nama}.${fk.kolom}`;
      const digambar = relasi.some((r) => r.anak === e.nama && r.kolom === fk.kolom);
      if (!digambar && !terdaftar.has(kunci)) {
        masalah.push(
          `kunci asing ${kunci} -> ${fk.ke_tabel}.${fk.ke_kolom} tidak digambar dan tidak terdaftar di tidak_digambar`
        );
      }
    }
  }

  for (const x of definisi.tidak_digambar) {
    if (!asingDari(x.tabel).some((f) => f.kolom === x.kolom)) {
      masalah.push(`tidak_digambar menyebut ${x.tabel}.${x.kolom}, yang bukan kunci asing`);
    }
    if (!x.alasan || x.alasan.trim().length < 10) {
      masalah.push(`tidak_digambar ${x.tabel}.${x.kolom} tidak punya alasan yang cukup`);
    }
  }

  return { relasi, masalah };
}

/* ------------------------------------------------- penempatan belah ketupat */

/** Skor seberapa buruk sebuah posisi; makin besar, makin banyak yang tertimpa. */
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

/**
 * Cari posisi terbaik untuk sebuah belah ketupat di sepanjang garis anak->induk,
 * dengan kemungkinan digeser tegak lurus.
 */
function pilihPosisi(anak, induk, uk, kotak, sudah) {
  const vx = induk.x - anak.x;
  const vy = induk.y - anak.y;
  const panjang = Math.hypot(vx, vy) || 1;
  const nx = -vy / panjang;
  const ny = vx / panjang;

  let terbaik = null;
  let skorTerbaik = Infinity;

  // Urutan kandidat tetap agar build reproducible. Nilai tengah diutamakan
  // karena belah ketupat di tengah garis paling mudah dibaca.
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

/** Hitung posisi elips untuk satu entitas pada satu sisi. */
function elipsPadaSisi(b, daftar, sisi) {
  return daftar.map((d, i) => {
    const uk = ukuranElips(d.kolom);
    const geser = (i - (daftar.length - 1) / 2) * (uk.hw * 2 + 24);
    const jarak = 52;

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

/**
 * Skor satu sisi untuk menaruh elips. Makin kecil makin baik.
 *
 * Bobotnya dipilih dari cacat yang benar-benar terjadi:
 *   - menimpa kotak entitas   -> paling parah (teks tertutup), bobot 400
 *   - garis memotong elips    -> parah (nama kolom tidak terbaca), bobot 60
 *   - menimpa belah ketupat   -> parah, bobot 60
 *   - menimpa elips lain      -> sedang, bobot 50
 *   - jauh dari entitasnya    -> ringan, supaya tetap rapi
 */
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
    // Garis penghubung entitas -> elips juga tidak boleh menembus kotak lain.
    const p1 = tepiKotak(b, el.x, el.y);
    const p2 = tepiElips(el.hw, el.hh, el.x, el.y, b.x, b.y);
    if (garisMenembusKotak(p1.x, p1.y, p2.x, p2.y, kotak, [b.nama], 2)) skor += 40;
  }
  return skor;
}

/**
 * Tentukan seluruh elips: untuk tiap entitas, coba keempat sisi, pilih yang
 * skornya terendah, lalu masukkan hasilnya ke daftar supaya entitas berikutnya
 * ikut menghindarinya.
 */
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

    // Atribut yang digambar: kunci primer + satu pembeda. Kolom kunci asing
    // TIDAK digambar sebagai elips - dalam notasi Chen ia diwakili oleh belah
    // ketupat, dan menggambarnya dua kali membuat diagram terbaca seolah ada
    // kolom yang tidak ada.
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

/* ------------------------------------------------------------- gambar */

function gambar(definisi) {
  const masalah = [];
  const { relasi, masalah: masalahRelasi } = susunRelasi(definisi);
  masalah.push(...masalahRelasi);

  const kotak = definisi.entitas.map(kotakEntitas);
  const petaKotak = new Map(kotak.map((b) => [b.nama, b]));

  // --- a. tempatkan belah ketupat, menghindari kotak dan sesamanya ---
  const tempat = [];
  for (const r of relasi) {
    const anak = petaKotak.get(r.anak);
    const induk = petaKotak.get(r.induk);
    if (!anak || !induk) continue;
    const uk = ukuranBelahKetupat(r.label);

    if (r.diriSendiri) {
      tempat.push({ ...r, x: anak.x + anak.hw + 140, y: anak.y, uk, skor: 0 });
      continue;
    }

    const pos = pilihPosisi(anak, induk, uk, kotak, tempat);
    tempat.push({ ...r, x: pos.x, y: pos.y, uk, skor: pos.skor });
  }

  // --- b. hitung seluruh segmen garis yang sudah pasti ---
  const segmen = [];
  for (const d of tempat) {
    const anak = petaKotak.get(d.anak);
    const induk = petaKotak.get(d.induk);
    if (!anak || !induk) continue;
    const { hw, hh } = d.uk;

    if (d.diriSendiri) {
      const a1 = { x: anak.x + anak.hw, y: anak.y - 15 };
      const a2 = { x: anak.x + anak.hw, y: anak.y + 15 };
      segmen.push({ x1: a1.x, y1: a1.y, x2: d.x, y2: d.y, anak: d.anak, induk: d.induk });
      segmen.push({ x1: a2.x, y1: a2.y, x2: d.x, y2: d.y, anak: d.anak, induk: d.induk });
      continue;
    }

    const a = tepiKotak(anak, d.x, d.y);
    const b = tepiKotak(induk, d.x, d.y);
    const pa = tepiBelahKetupat(d.x, d.y, hw, hh, a.x, a.y);
    const pb = tepiBelahKetupat(d.x, d.y, hw, hh, b.x, b.y);
    segmen.push({ x1: a.x, y1: a.y, x2: pa.x, y2: pa.y, anak: d.anak, induk: d.induk });
    segmen.push({ x1: pb.x, y1: pb.y, x2: b.x, y2: b.y, anak: d.anak, induk: d.induk });
  }

  // --- c. tentukan sisi atribut untuk tiap entitas ---
  const penempatan = tempatkanAtribut(definisi, kotak, petaKotak, tempat, segmen);
  const semuaElips = penempatan.flatMap((p) => p.elips);

  // --- d. gambar ---
  const isi = [];
  isi.push(teks(60, 56, definisi.judul, 26, 700, C.ink, 'start'));
  isi.push(teks(60, 88, definisi.sub, 14, 400, C.sub, 'start'));

  // Garis relasi lebih dulu, supaya berada di bawah simbol.
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
      isi.push(teks(d.x, d.y, d.label, 10.5, 600, C.relStroke));
      isi.push(teks(a1.x + 14, a1.y - 12, d.kardAnak, 12.5, 700, d.kardAnak === 'N' ? C.wajib : C.opsional));
      isi.push(teks(a2.x + 14, a2.y + 14, d.kardInduk, 12.5, 700, C.opsional));
      continue;
    }

    const a = tepiKotak(anak, d.x, d.y);
    const b = tepiKotak(induk, d.x, d.y);
    const pa = tepiBelahKetupat(d.x, d.y, hw, hh, a.x, a.y);
    const pb = tepiBelahKetupat(d.x, d.y, hw, hh, b.x, b.y);

    isi.push(garis(a.x, a.y, pa.x, pa.y));
    if (d.wajibAnak) {
      // Garis ganda menandakan partisipasi total: kolomnya NOT NULL.
      const len = Math.hypot(pa.x - a.x, pa.y - a.y) || 1;
      const nx = (-(pa.y - a.y) / len) * 3.2;
      const ny = ((pa.x - a.x) / len) * 3.2;
      isi.push(garis(a.x + nx, a.y + ny, pa.x + nx, pa.y + ny));
    }
    isi.push(garis(pb.x, pb.y, b.x, b.y));

    // Kardinalitas digeser tegak lurus agar tidak menimpa garisnya sendiri.
    const lenA = Math.hypot(pa.x - a.x, pa.y - a.y) || 1;
    isi.push(
      teks(
        a.x + (pa.x - a.x) * 0.24 + (-(pa.y - a.y) / lenA) * 14,
        a.y + (pa.y - a.y) * 0.24 + ((pa.x - a.x) / lenA) * 14,
        d.kardAnak,
        12.5,
        700,
        d.kardAnak === 'N' ? C.wajib : C.opsional
      )
    );

    const lenB = Math.hypot(b.x - pb.x, b.y - pb.y) || 1;
    isi.push(
      teks(
        b.x + (pb.x - b.x) * 0.24 + (-(b.y - pb.y) / lenB) * 14,
        b.y + (pb.y - b.y) * 0.24 + ((b.x - pb.x) / lenB) * 14,
        d.kardInduk,
        12.5,
        700,
        C.opsional
      )
    );

    isi.push(
      `<polygon points="${r1(d.x - hw)},${r1(d.y)} ${r1(d.x)},${r1(d.y - hh)} ${r1(d.x + hw)},${r1(d.y)} ${r1(d.x)},${r1(d.y + hh)}" fill="${C.relFill}" stroke="${C.relStroke}" stroke-width="2"/>`
    );
    isi.push(teks(d.x, d.y, d.label, 10.5, 600, C.relStroke));

    const tembus = garisMenembusKotak(a.x, a.y, pa.x, pa.y, kotak, [d.anak, d.induk]);
    if (tembus) masalah.push(`garis ${d.anak}.${d.kolom} menembus kotak "${tembus}"`);
    const tembus2 = garisMenembusKotak(pb.x, pb.y, b.x, b.y, kotak, [d.anak, d.induk]);
    if (tembus2) masalah.push(`garis ${d.anak}.${d.kolom} (sisi induk) menembus kotak "${tembus2}"`);
  }

  // Kotak entitas.
  for (const b of kotak) {
    isi.push(
      `<rect x="${r1(b.x - b.hw)}" y="${r1(b.y - b.hh)}" width="${r1(b.w)}" height="${r1(b.h)}" rx="8" fill="${C.entFill}" stroke="${C.entStroke}" stroke-width="2.5"/>`
    );
    isi.push(teks(b.x, b.y, b.nama, 15, 700, C.entStroke));
  }

  // Elips atribut.
  for (const p of penempatan) {
    const b = petaKotak.get(p.entitas);
    if (!b) continue;
    for (const el of p.elips) {
      isi.push(
        `<ellipse cx="${r1(el.x)}" cy="${r1(el.y)}" rx="${r1(el.hw)}" ry="${r1(el.hh)}" fill="${C.atrFill}" stroke="${C.atrStroke}" stroke-width="1.6"/>`
      );
      isi.push(teks(el.x, el.y, el.kolom, 11.5, 600, C.sub, 'middle', el.kunciPrimer));

      const p1 = tepiKotak(b, el.x, el.y);
      const p2 = tepiElips(el.hw, el.hh, el.x, el.y, b.x, b.y);
      isi.push(garis(p1.x, p1.y, p2.x, p2.y, { warna: C.atrStroke, tebal: 1.2 }));
    }
  }

  // --- e. periksa ulang keenam hal ---
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

  // --- f. legenda ---
  //
  // Legenda dihitung, bukan dipatok: jumlah barisnya bergantung pada berapa
  // tabel yang benar-benar berdiri sendiri. Versi sebelumnya memakai tinggi
  // tetap 88px dengan teks "atribut · kunci digaris bawahi" selebar 22
  // karakter - teks itu menabrak simbol relasi di sebelahnya.
  //
  // "Berdiri sendiri" berarti TIDAK punya kunci asing keluar DAN tidak dirujuk
  // siapa pun. Versi pertama hanya memeriksa yang keluar, sehingga `kamar` dan
  // `penghuni` - dua tabel yang justru paling banyak dirujuk - ikut disebut
  // berdiri sendiri. Itu klaim yang salah dan tidak ada yang menangkapnya.
  const tanpaRelasi = definisi.entitas
    .map((e) => e.nama)
    .filter((nama) => {
      const keluar = asingDari(nama).length;
      const masuk = definisi.entitas.some((lain) =>
        asingDari(lain.nama).some((fk) => fk.ke_tabel === nama)
      );
      return keluar === 0 && !masuk;
    })
    .sort();

  const barisLegenda = tanpaRelasi.length > 0 ? 3 : 2;
  const tinggiLegenda = 26 + barisLegenda * 26 + 10;
  const ly = definisi.kanvas.h - tinggiLegenda - 42;

  isi.push(
    `<rect x="60" y="${ly}" width="900" height="${tinggiLegenda}" rx="10" fill="#fafafa" stroke="${C.legend}" stroke-width="1"/>`
  );

  const yBaris1 = ly + 30;
  isi.push(`<rect x="84" y="${yBaris1 - 12}" width="50" height="24" rx="5" fill="${C.entFill}" stroke="${C.entStroke}" stroke-width="2"/>`);
  isi.push(teks(146, yBaris1, 'entitas', 12, 500, C.sub, 'start'));

  isi.push(`<ellipse cx="266" cy="${yBaris1}" rx="25" ry="12" fill="${C.atrFill}" stroke="${C.atrStroke}" stroke-width="1.6"/>`);
  isi.push(teks(302, yBaris1, 'atribut · kunci digaris bawahi', 12, 500, C.sub, 'start'));

  isi.push(`<polygon points="612,${yBaris1 - 16} 652,${yBaris1} 612,${yBaris1 + 16} 572,${yBaris1}" fill="${C.relFill}" stroke="${C.relStroke}" stroke-width="2"/>`);
  isi.push(teks(666, yBaris1, 'relasi', 12, 500, C.sub, 'start'));

  isi.push(
    teks(84, ly + 58, '1 = tepat satu · N = banyak · garis ganda = partisipasi wajib (kolom NOT NULL)', 12, 400, C.sub, 'start')
  );

  if (tanpaRelasi.length > 0) {
    isi.push(
      teks(84, ly + 84, `tanpa kunci asing (berdiri sendiri): ${tanpaRelasi.join(' · ')}`, 12, 400, C.sub, 'start')
    );
  }

  const svg = [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${definisi.kanvas.w}" height="${definisi.kanvas.h}" viewBox="0 0 ${definisi.kanvas.w} ${definisi.kanvas.h}" font-family="${FONT}">`,
    `<title>${esc(definisi.judul)}</title>`,
    `<rect width="${definisi.kanvas.w}" height="${definisi.kanvas.h}" fill="${C.bg}"/>`,
    ...isi,
    '</svg>',
    '',
  ].join('\n');

  return { svg, masalah, jumlahRelasi: relasi.length };
}

module.exports = { gambar, susunRelasi, petaTabel };
