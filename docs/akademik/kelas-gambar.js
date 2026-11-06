'use strict';

/**
 * Penggambar Diagram Kelas
 * ------------------------
 * Isinya tidak diketik: `kelas-data.js` menurunkannya dari `docs/erd/skema.json`
 * dan `api/app/Models/*.php`. Berkas ini hanya menata dan menggambar.
 *
 * TIGA KEPUTUSAN TATA LETAK, DAN SEBABNYA
 *
 *   1. Kotak ditumpuk per kolom (kolom terpendek dulu), bukan diletakkan di
 *      koordinat tulisan tangan. Dua puluh dua kotak dengan tinggi berbeda
 *      akan bertumpuk begitu satu kolom atribut ditambahkan, dan yang melihat
 *      gambar tidak akan tahu bahwa itu salah.
 *
 *   2. Garis asosiasi TIDAK PERNAH menembus kotak. Kotak satu kolom tidak
 *      pernah bertumpuk secara vertikal, jadi keluar dari sisi kotak menuju
 *      selokan di sebelahnya pasti aman. Kolom yang bersebelahan memakai
 *      selokan bersama (bentuk Z); kolom yang berjauhan memutar lewat lorong
 *      atas yang sengaja dikosongkan.
 *
 *   3. Setiap ujung garis, dan setiap garis yang memakai selokan atau lorong
 *      yang sama, MENDAPAT TEMPAT SENDIRI. Versi pertama berkas ini menaruh
 *      semua garis pada satu titik dan satu jalur; tiga asosiasi berbeda di
 *      lembar c menumpuk sempurna dan terbaca sebagai satu garis. Gambar yang
 *      menyembunyikan dua dari tiga hubungan lebih berbahaya daripada gambar
 *      yang jelek, karena tidak ada tandanya.
 *
 *   4. Asosiasi ke kelas di lembar lain digambar sebagai stub bertuliskan
 *      "lihat lembar x", bukan garis yang menggantung ke tepi kertas.
 */

const { KELOMPOK, KELAS, ASOSIASI } = require('./kelas-data');
const { hurufMm, AMBANG_MM } = require('../erd/papan');

const FONT = "'Segoe UI', 'Inter', 'Helvetica Neue', Arial, sans-serif";

const C = {
  bg: '#ffffff',
  ink: '#0f172a',
  sub: '#475569',
  redup: '#94a3b8',
  garis: '#cbd5e1',
  asosiasi: '#334155',
  kepala: '#eef2ff',
  kepalaTepi: '#3730a3',
  kotakTepi: '#94a3b8',
  catatan: '#64748b',
};

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const r1 = (n) => Math.round(n * 10) / 10;

/** Lebar huruf rata-rata untuk font tanpa kait; sedikit berlebih lebih aman. */
const lebarTeks = (s, size) => String(s).length * size * 0.55;

/* ------------------------------------------------------------- geometri */

const KANVAS_W = 1250;
const MARGIN = 40;
const KOLOM = 3;
const KOTAK_W = 380;
const SELOKAN = 15; // jarak antar kolom, sekaligus jalur garis
const KEPALA_H = 34;
const BARIS_H = 17;
const HURUF_ATRIBUT = 11;
const HURUF_OPERASI = 11;
const HURUF_KEPALA = 15;
const ATAS = 158; // di bawah judul dan lorong
const LORONG_ATAS = 96; // jalur horizontal untuk kolom yang berjauhan
const JARAK_LORONG = 14; // jarak antar lorong paralel
const JUMLAH_LORONG = 4;

const tinggiKotak = (k) =>
  KEPALA_H + k.atribut.length * BARIS_H + 9 + Math.max(k.operasi.length, 1) * BARIS_H + 12;

/* ----------------------------------------------------------------- SVG */

function teks(x, y, isi, size, weight, warna, anchor) {
  return `<text x="${r1(x)}" y="${r1(y)}" text-anchor="${
    anchor || 'start'
  }" font-size="${size}" font-weight="${weight}" fill="${warna}">${esc(isi)}</text>`;
}

function kepalaKotak(k, x, y, w) {
  return [
    `<rect x="${x}" y="${y}" width="${w}" height="${KEPALA_H}" rx="6" fill="${C.kepala}"/>`,
    `<rect x="${x}" y="${y + KEPALA_H - 6}" width="${w}" height="6" fill="${C.kepala}"/>`,
    teks(x + 12, y + 22, k.nama, HURUF_KEPALA, 700, C.kepalaTepi),
    teks(x + w - 12, y + 21, '«Entity»', 9.5, 500, C.redup, 'end'),
  ].join('\n');
}

function kotakKelas(k, x, y, w) {
  const h = tinggiKotak(k);
  const keluar = [];
  keluar.push(
    `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="6" fill="${C.bg}" stroke="${C.kotakTepi}" stroke-width="1.2"/>`
  );
  keluar.push(kepalaKotak(k, x, y, w));

  let barisY = y + KEPALA_H + 13;
  k.atribut.forEach((a) => {
    // Tanda kunci di kiri, nama di tengah, tipe rata kanan — pola yang sama
    // dengan notasi UML dan dengan ERD repo ini.
    keluar.push(teks(x + 10, barisY, a.kunci ? `«${a.kunci}»` : '', 9.5, 600, C.kepalaTepi));
    keluar.push(teks(x + 62, barisY, a.nama, HURUF_ATRIBUT, a.kunci ? 600 : 400, C.ink));
    keluar.push(teks(x + w - 10, barisY, a.tipe, HURUF_ATRIBUT, 400, C.sub, 'end'));
    barisY += BARIS_H;
  });

  const setelahAtribut = y + KEPALA_H + k.atribut.length * BARIS_H;
  keluar.push(
    `<line x1="${x}" y1="${setelahAtribut + 4}" x2="${x + w}" y2="${setelahAtribut + 4}" stroke="${C.garis}" stroke-width="1"/>`
  );

  barisY = setelahAtribut + 21;
  if (k.operasi.length === 0) {
    keluar.push(teks(x + 10, barisY, '(tidak ada operasi)', HURUF_OPERASI, 400, C.redup));
  } else {
    k.operasi.forEach((o) => {
      keluar.push(teks(x + 10, barisY, `${o}()`, HURUF_OPERASI, 400, C.ink));
      barisY += BARIS_H;
    });
  }

  return keluar.join('\n');
}

/* --------------------------------------------------------------- tata letak */

/**
 * Tumpuk kotak ke kolom terpendek. Kelas diurutkan menurun tinggi dulu supaya
 * kolom rata: menyusun dari yang terpendek membuat kolom terakhir menjulang.
 */
function tataLetak(daftar) {
  const kolom = Array.from({ length: KOLOM }, () => ({ tinggi: 0, isi: [] }));
  const urut = [...daftar].sort((a, b) => tinggiKotak(b) - tinggiKotak(a));
  for (const k of urut) {
    const target = kolom.reduce((a, b) => (b.tinggi < a.tinggi ? b : a));
    target.isi.push({ ...k, y: ATAS + target.tinggi });
    target.tinggi += tinggiKotak(k) + 22;
  }
  const xKolom = Array.from({ length: KOLOM }, (_, i) => MARGIN + i * (KOTAK_W + SELOKAN));
  for (const [i, k] of kolom.entries()) {
    for (const kotak of k.isi) kotak.x = xKolom[i];
  }
  const tinggi = Math.max(...kolom.map((k) => k.tinggi));
  return {
    kotak: kolom.flatMap((k) => k.isi),
    xKolom,
    kanvasH: ATAS + tinggi + 150,
  };
}

/* -------------------------------------------------------------- asosiasi */

/** Titik di tepi kotak. `frac` menyusuri sisi itu, sehingga tiap ujung garis
 *  bisa mendapat tempat sendiri tanpa menembus kotak mana pun. */
function titikTepi(kotak, sisi, frac) {
  const h = tinggiKotak(kotak);
  if (sisi === 'kiri') return [kotak.x, kotak.y + h * frac];
  if (sisi === 'kanan') return [kotak.x + KOTAK_W, kotak.y + h * frac];
  if (sisi === 'atas') return [kotak.x + KOTAK_W * frac, kotak.y];
  return [kotak.x + KOTAK_W * frac, kotak.y + h];
}

const ARAH_MASUK = { kiri: 'kanan', kanan: 'kiri', atas: 'bawah', bawah: 'atas' };

function kepalaPanah(x, y, arah) {
  const d = 7;
  const w = d / 1.7;
  if (arah === 'kiri') return `M${r1(x)} ${r1(y)} l${d} ${-w} M${r1(x)} ${r1(y)} l${d} ${w}`;
  if (arah === 'kanan') return `M${r1(x)} ${r1(y)} l${-d} ${-w} M${r1(x)} ${r1(y)} l${-d} ${w}`;
  if (arah === 'atas') return `M${r1(x)} ${r1(y)} l${-w} ${d} M${r1(x)} ${r1(y)} l${w} ${d}`;
  return `M${r1(x)} ${r1(y)} l${-w} ${-d} M${r1(x)} ${r1(y)} l${w} ${-d}`;
}

/**
 * Menentukan rute tiap asosiasi SEBELUM menggambar, karena dua hal harus
 * dibagi bersama: tempat ujung garis di tepi kotak, dan jalur di selokan atau
 * lorong. Tanpa pembagian ini semua garis menumpuk di satu titik.
 */
function rencanakan(dalam, kotak, xKolom) {
  const perKolom = xKolom.map((_, i) =>
    kotak.filter((k) => k.x === xKolom[i]).sort((a, b) => a.y - b.y)
  );
  const ujung = new Map(); // "nama|sisi" -> daftar asosiasi
  const selokan = new Map(); // "x" -> daftar asosiasi (untuk digeser)
  const lorong = [];

  const daftarUjung = (kunci) => {
    if (!ujung.has(kunci)) ujung.set(kunci, []);
    return ujung.get(kunci);
  };

  for (const a of dalam) {
    a._iD = xKolom.indexOf(a._dari.x);
    a._iK = xKolom.indexOf(a._ke.x);

    if (a._iD === a._iK) {
      const kol = perKolom[a._iD];
      const sD = kol.indexOf(a._dari);
      const sK = kol.indexOf(a._ke);
      if (Math.abs(sD - sK) === 1) {
        // Bersebelahan langsung: cukup garis pendek di celah 22 px antar kotak.
        const turun = sK > sD;
        a._rute = 'lurus';
        a._sisiD = turun ? 'bawah' : 'atas';
        a._sisiK = turun ? 'atas' : 'bawah';
        daftarUjung(`${a._dari.nama}|${a._sisiD}`).push(a);
        daftarUjung(`${a._ke.nama}|${a._sisiK}`).push(a);
      } else {
        // Ada kotak lain di antaranya: keluar lewat selokan samping kolom itu.
        const sisi = a._iD === KOLOM - 1 ? 'kiri' : 'kanan';
        a._rute = 'kurung';
        a._sisiD = sisi;
        a._sisiK = sisi;
        a._sel1 = a._iD === KOLOM - 1 ? a._dari.x - SELOKAN / 2 : a._dari.x + KOTAK_W + SELOKAN / 2;
        a._sel2 = a._sel1;
        daftarUjung(`${a._dari.nama}|${sisi}`).push(a);
        daftarUjung(`${a._ke.nama}|${sisi}`).push(a);
        if (!selokan.has(a._sel1)) selokan.set(a._sel1, []);
        selokan.get(a._sel1).push(a);
      }
      a._arah = null;
      continue;
    }

    const keKanan = a._iK > a._iD;
    a._sisiD = keKanan ? 'kanan' : 'kiri';
    a._sisiK = keKanan ? 'kiri' : 'kanan';
    daftarUjung(`${a._dari.nama}|${a._sisiD}`).push(a);
    daftarUjung(`${a._ke.nama}|${a._sisiK}`).push(a);

    a._sel1 = keKanan ? a._dari.x + KOTAK_W + SELOKAN / 2 : a._dari.x - SELOKAN / 2;
    a._sel2 = keKanan ? a._ke.x - SELOKAN / 2 : a._ke.x + KOTAK_W + SELOKAN / 2;

    if (Math.abs(a._iK - a._iD) === 1) {
      a._rute = 'z';
    } else {
      a._rute = 'lorong';
      a._lorong = lorong.length % JUMLAH_LORONG;
      lorong.push(a);
    }
    if (!selokan.has(a._sel1)) selokan.set(a._sel1, []);
    selokan.get(a._sel1).push(a);
    if (a._sel2 !== a._sel1) {
      if (!selokan.has(a._sel2)) selokan.set(a._sel2, []);
      selokan.get(a._sel2).push(a);
    }
  }

  // Ujung garis dibagi rata di sepanjang sisi kotak.
  for (const [kunci, daftar] of ujung) {
    const n = daftar.length;
    daftar.forEach((a, i) => {
      const frac = n === 1 ? 0.5 : 0.2 + (0.6 * i) / (n - 1);
      if (kunci.startsWith(a._dari.nama + '|')) a._fracD = frac;
      else a._fracK = frac;
    });
  }

  // Jalur di selokan digeser sedikit supaya garis yang sejalur tidak berimpit.
  const pemakai = new Map();
  for (const a of dalam) {
    if (a._rute === 'lurus') continue;
    for (const slot of [1, 2]) {
      const x = slot === 1 ? a._sel1 : a._sel2;
      if (x === undefined) continue;
      if (slot === 2 && a._sel2 === a._sel1) continue;
      if (!pemakai.has(x)) pemakai.set(x, []);
      pemakai.get(x).push({ a, slot });
    }
  }
  for (const [, daftar] of pemakai) {
    const n = daftar.length;
    const jarak = n === 1 ? 0 : Math.min(4, 10 / (n - 1));
    daftar.forEach(({ a, slot }, i) => {
      const geser = (i - (n - 1) / 2) * jarak;
      if (slot === 1) a._geser1 = geser;
      else a._geser2 = geser;
    });
  }

  return { lorong };
}

function garisAsosiasi(a) {
  const p1 = titikTepi(a._dari, a._sisiD, a._fracD);
  const p2 = titikTepi(a._ke, a._sisiK, a._fracK);
  const keluar = [];
  let d;

  if (a._rute === 'lurus') {
    const tengah = (p1[1] + p2[1]) / 2;
    d =
      p1[0] === p2[0]
        ? `M${r1(p1[0])} ${r1(p1[1])} V${r1(p2[1])}`
        : `M${r1(p1[0])} ${r1(p1[1])} V${r1(tengah)} H${r1(p2[0])} V${r1(p2[1])}`;
  } else if (a._rute === 'kurung') {
    const s = a._sel1 + (a._geser1 || 0);
    d = `M${r1(p1[0])} ${r1(p1[1])} H${r1(s)} V${r1(p2[1])} H${r1(p2[0])}`;
  } else if (a._rute === 'z') {
    const s1 = a._sel1 + (a._geser1 || 0);
    // Selokan bersama: satu jalur vertikal saja, bukan dua yang berimpit.
    d = `M${r1(p1[0])} ${r1(p1[1])} H${r1(s1)} V${r1(p2[1])} H${r1(p2[0])}`;
  } else {
    const y = LORONG_ATAS + a._lorong * JARAK_LORONG;
    const s1 = a._sel1 + (a._geser1 || 0);
    const s2 = a._sel2 + (a._geser2 || 0);
    d = `M${r1(p1[0])} ${r1(p1[1])} H${r1(s1)} V${y} H${r1(s2)} V${r1(p2[1])} H${r1(p2[0])}`;
  }

  keluar.push(
    `<path d="${d}" fill="none" stroke="${C.asosiasi}" stroke-width="1.2" stroke-linejoin="round"/>`
  );
  keluar.push(
    `<path d="${kepalaPanah(p2[0], p2[1], ARAH_MASUK[a._sisiK])}" fill="none" stroke="${C.asosiasi}" stroke-width="1.2"/>`
  );

  // Multiplisitas: di luar garis, di sisi ujungnya masing-masing.
  const geserKiri = a._sisiD === 'kiri' || a._sisiD === 'atas' || a._sisiD === 'bawah';
  keluar.push(
    teks(p1[0] + (geserKiri ? 6 : -6), p1[1] - 5, a.banyak, 10, 600, C.sub, geserKiri ? 'start' : 'end'),
    teks(p2[0] + (a._sisiK === 'kiri' ? -6 : 6), p2[1] - 5, a.satu, 10, 600, C.sub, a._sisiK === 'kiri' ? 'end' : 'start')
  );

  return { svg: keluar.join('\n'), d, p1, p2 };
}

/* ---------------------------------------------------------------- gambar */

function gambarKelas(grup) {
  const info = KELOMPOK.find((k) => k.kunci === grup);
  const daftar = KELAS.filter((k) => k.grup === grup);
  const { kotak, xKolom, kanvasH: kanvasDasar } = tataLetak(daftar);
  const masalah = [];

  const asosiasi = ASOSIASI.filter((a) => a.dari !== a.ke).map((a) => ({
    ...a,
    _dari: kotak.find((k) => k.nama === a.dari),
    _ke: kotak.find((k) => k.nama === a.ke),
  }));

  const dalam = asosiasi.filter((a) => a._dari && a._ke);
  const keluar = asosiasi.filter((a) => a._dari !== undefined && a._ke === undefined);

  // Daftar stub lintas lembar butuh satu baris per tiga asosiasi. Tanpa
  // penambahan tinggi ini barisnya menabrak keterangan notasi di kaki gambar —
  // dan yang terbaca adalah dua baris teks saling menimpa.
  const barisStub = keluar.length ? Math.ceil(keluar.length / 3) : 0;
  const kanvasH = kanvasDasar + Math.max(0, barisStub - 1) * 17;

  rencanakan(dalam, kotak, xKolom);

  const lewatLorong = dalam.filter((a) => a._rute === 'lorong').length;
  if (lewatLorong > JUMLAH_LORONG) {
    masalah.push(`${lewatLorong} garis memakai lorong atas, hanya tersedia ${JUMLAH_LORONG} jalur — garis akan berimpit`);
  }

  // Ujung garis yang menempel di titik yang sama menyembunyikan hubungan lain
  // tanpa jejak apa pun di gambar. Diperiksa, bukan diasumsikan.
  const titik = [];
  for (const a of dalam) {
    titik.push({ nama: `${a.dari}→${a.ke}`, x: titikTepi(a._dari, a._sisiD, a._fracD)[0], y: titikTepi(a._dari, a._sisiD, a._fracD)[1] });
    titik.push({ nama: `${a.dari}→${a.ke}`, x: titikTepi(a._ke, a._sisiK, a._fracK)[0], y: titikTepi(a._ke, a._sisiK, a._fracK)[1] });
  }
  for (let i = 0; i < titik.length; i++) {
    for (let j = i + 1; j < titik.length; j++) {
      if (Math.abs(titik[i].x - titik[j].x) < 2 && Math.abs(titik[i].y - titik[j].y) < 2) {
        masalah.push(`ujung garis ${titik[i].nama} dan ${titik[j].nama} berimpit`);
      }
    }
  }

  const svg = [];
  svg.push(
    `<svg xmlns="http://www.w3.org/2000/svg" width="${KANVAS_W}" height="${r1(kanvasH)}" viewBox="0 0 ${KANVAS_W} ${r1(kanvasH)}" font-family="${FONT}">`
  );
  svg.push(`<rect width="${KANVAS_W}" height="${r1(kanvasH)}" fill="${C.bg}"/>`);
  svg.push(
    `<title>Diagram Kelas ${esc(info.nama)}</title>`,
    teks(MARGIN, 42, `Diagram Kelas — ${info.nama}`, 26, 700, C.ink),
    teks(MARGIN, 68, info.ket, 13, 400, C.sub),
    teks(KANVAS_W - MARGIN, 42, `lembar ${grup}`, 13, 600, C.redup, 'end')
  );

  // Asosiasi digambar lebih dulu supaya garis yang menyeberang berada di
  // belakang kotak, bukan di atas teks atribut.
  for (const a of dalam) {
    const g = garisAsosiasi(a);
    svg.push(g.svg);
    if (g.p1[0] < 0 || g.p1[0] > KANVAS_W || g.p2[0] < 0 || g.p2[0] > KANVAS_W) {
      masalah.push(`${a.dari}→${a.ke}: ujung garis keluar kanvas`);
    }
    // Garis yang menembus kotak akan menutupi nama atribut. Kotak tidak pernah
    // bertumpuk, jadi cukup diperiksa terhadap kotak yang BUKAN ujungnya.
    for (const k of kotak) {
      if (k === a._dari || k === a._ke) continue;
      const h = tinggiKotak(k);
      const kotakArea = { x1: k.x, y1: k.y, x2: k.x + KOTAK_W, y2: k.y + h };
      const kena = segmenTembus(g.d, kotakArea);
      if (kena) masalah.push(`${a.dari}→${a.ke}: garis menembus kotak ${k.nama}`);
    }
  }

  for (const k of kotak) svg.push(kotakKelas(k, k.x, k.y, KOTAK_W));

  // Stub asosiasi lintas lembar, dikumpulkan di kaki gambar.
  if (keluar.length) {
    const y0 = r1(kanvasH) - 96 - Math.max(0, barisStub - 1) * 17;
    svg.push(`<line x1="${MARGIN}" y1="${y0}" x2="${KANVAS_W - MARGIN}" y2="${y0}" stroke="${C.garis}"/>`);
    svg.push(
      teks(
        MARGIN,
        y0 + 22,
        `Asosiasi ke lembar lain (${keluar.length}) — tidak digambar sebagai garis supaya tidak menggantung di tepi kertas:`,
        11.5,
        600,
        C.catatan
      )
    );
    const baris = keluar.map((a) => `${a.dari} → ${a.ke}  (${a.kolom}, ${a.banyak} : ${a.satu})`);
    for (let i = 0; i < baris.length; i++) {
      svg.push(
        teks(MARGIN + (i % 3) * 380, y0 + 42 + Math.floor(i / 3) * 17, baris[i], 11, 400, C.sub)
      );
    }
  }

  // Keterangan notasi.
  const yn = r1(kanvasH) - 34;
  svg.push(
    teks(
      MARGIN,
      yn,
      `«PK» kunci primer · «FK» kunci asing · «UQ» unik · * banyak · 1 tepat satu · 0..1 boleh kosong · ` +
        `huruf ${hurufMm(HURUF_ATRIBUT, { w: KANVAS_W, h: kanvasH }, 'A3').toFixed(2)} mm di A3 (ambang ${AMBANG_MM} mm)`,
      10.5,
      400,
      C.catatan
    )
  );

  const mm = hurufMm(HURUF_ATRIBUT, { w: KANVAS_W, h: kanvasH }, 'A3');
  if (mm < AMBANG_MM) {
    masalah.push(
      `huruf hanya ${mm.toFixed(2)} mm di A3 (ambang ${AMBANG_MM} mm) — perkecil kanvas atau pecah lembarnya`
    );
  }

  // Atribut yang tidak muat akan melimpah keluar kotak tanpa galat apa pun.
  for (const k of kotak) {
    for (const a of k.atribut) {
      const w = lebarTeks(a.nama, HURUF_ATRIBUT) + lebarTeks(a.tipe, HURUF_ATRIBUT) + 72;
      if (w > KOTAK_W - 20) masalah.push(`${k.nama}.${a.nama} melimpah keluar kotak (${Math.round(w)} px)`);
    }
  }

  svg.push('</svg>');
  return { svg: svg.join('\n'), kanvas: { w: KANVAS_W, h: Math.round(kanvasH) }, hurufMm: mm, masalah };
}

/**
 * Apakah jalur `d` (hanya M/H/V, seperti yang dihasilkan berkas ini) memotong
 * persegi panjang itu? Dipakai sebagai pagar, bukan sebagai penggambar.
 *
 * Setiap perintah dibaca beserta jumlah argumennya sendiri. Versi pertama
 * mengira semua perintah punya dua angka, sehingga `H` dan `V` bergeser satu
 * argumen dan pagar ini memeriksa segmen yang tidak pernah digambar.
 */
function segmenTembus(d, kotakArea) {
  const perintah = d.match(/([MHV])([^MHV]*)/g) || [];
  let x = NaN;
  let y = NaN;
  for (const bagian of perintah) {
    const kode = bagian[0];
    const angka = (bagian.slice(1).match(/-?\d+(?:\.\d+)?/g) || []).map(Number);
    if (kode === 'M') {
      [x, y] = angka;
    } else if (kode === 'H') {
      const nx = angka[0];
      if (y > kotakArea.y1 && y < kotakArea.y2 && Math.max(x, nx) > kotakArea.x1 && Math.min(x, nx) < kotakArea.x2) {
        return true;
      }
      x = nx;
    } else if (kode === 'V') {
      const ny = angka[0];
      if (x > kotakArea.x1 && x < kotakArea.x2 && Math.max(y, ny) > kotakArea.y1 && Math.min(y, ny) < kotakArea.y2) {
        return true;
      }
      y = ny;
    }
  }
  return false;
}

module.exports = { gambarKelas, segmenTembus, KANVAS_W };

if (require.main === module) {
  let gagal = 0;
  for (const g of KELOMPOK) {
    const h = gambarKelas(g.kunci);
    console.log(
      `${g.kunci}  ${String(h.kanvas.w).padStart(5)}x${String(h.kanvas.h).padEnd(5)}  huruf ${h.hurufMm.toFixed(2)} mm`
    );
    for (const m of h.masalah) {
      console.log('   MASALAH: ' + m);
      gagal += 1;
    }
  }
  process.exit(gagal === 0 ? 0 : 1);
}
