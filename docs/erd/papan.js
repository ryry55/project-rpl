'use strict';

/**
 * Penyaji papan tabel — dipakai untuk ERD level 2 (fisikal) dan level 3 (LRS).
 * -------------------------------------------------------------------------
 * Berbeda dari `chen.js` yang menggambar simbol Chen, berkas ini menggambar
 * tabel sebagai KOTAK BERISI DAFTAR KOLOM. Itu bentuk yang dipakai pada tingkat
 * fisikal dan LRS: yang dibaca bukan lagi "entitas apa berhubungan dengan apa",
 * melainkan "kolom apa saja yang dimiliki tabel ini".
 *
 * Tiga hal yang membuat versi ini tidak menjadi gambar yang tidak terbaca:
 *
 *   1. TATA LETAK OTOMATIS. Kotak disusun sebagai kolom koran: tabel masuk ke
 *      kolom terpendek lebih dulu. Tidak ada koordinat yang diketik, sehingga
 *      menambah tabel tidak pernah membuat kotak bertumpuk.
 *
 *   2. JALUR ORTOGONAL DI LORONG. Garis penghubung tidak ditarik lurus
 *      menembus kotak. Ia berjalan di lorong antarkotak, dicari dengan
 *      Dijkstra pada kisi lorong.
 *
 *   3. UKURAN HURUF DIJAGA. Seperti `chen.js`, nisbah huruf terhadap lebar
 *      kanvas tidak boleh turun di bawah ambang. Lembar yang terlalu padat
 *      harus dipecah, bukan diperkecil hurufnya.
 */

const S = require('./skema.json');

const FONT = "'Segoe UI', 'Inter', 'Helvetica Neue', Arial, sans-serif";

const C = {
  bg: '#ffffff',
  ink: '#0f172a',
  sub: '#475569',
  garis: '#334155',
  kepala: '#1e293b',
  kepalaTeks: '#ffffff',
  badan: '#ffffff',
  badanSelang: '#f8fafc',
  tepi: '#94a3b8',
  pk: '#b45309',
  fk: '#1d4ed8',
  uq: '#0f766e',
  legend: '#94a3b8',
};

/** Nisbah huruf terkecil terhadap lebar kanvas. Lihat catatan di `chen.js`. */
const RASIO_A4 = 1.4142;
const NISBAH_MIN = 0.012;

/** Huruf efektif bila gambar dipaskan ke satu halaman A4, sebagai rasio lebar. */
function hurufEfektif(ukuranHuruf, kanvas) {
  const skala = Math.min(1 / kanvas.w, RASIO_A4 / kanvas.h);
  return ukuranHuruf * skala;
}

const HURUF_BAKU = {
  judul: 34,
  sub: 18,
  kepalaTabel: 21,
  kolom: 19,
  legenda: 16,
  tinggiBaris: 1.7,
  tinggiKepala: 2.1,
  padKiri: 18,
  padKanan: 18,
  jarakKolom: 200,
  jarakBaris: 86,
  tepi: 80,
  legendaBaris: 2.05,
};

const LEBAR_HURUF = 0.6;

const esc = (s) =>
  String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const r1 = (n) => Math.round(n * 10) / 10;

const petaTabel = new Map(S.tabel.map((t) => [t.nama, t]));

/** Tipe kolom dalam bentuk ringkas untuk papan fisikal. */
function tipeRapi(k) {
  if (k.tipe === 'character varying') return k.panjang ? `varchar(${k.panjang})` : 'varchar';
  if (k.tipe === 'numeric') return k.presisi ? `numeric(${k.presisi},${k.skala ?? 0})` : 'numeric';
  if (k.tipe === 'timestamp without time zone') return 'timestamp';
  if (k.tipe === 'timestamp with time zone') return 'timestamptz';
  if (k.tipe === 'double precision') return 'double';
  if (k.tipe === 'character') return `char(${k.panjang ?? 1})`;
  return k.tipe;
}

/** Bungkus teks pada batas kata agar muat dalam lebar tertentu. */
function bungkusTeks(isi, ukuran, lebarMaks) {
  const kata = String(isi).split(' ');
  const baris = [];
  let sekarang = '';
  for (const k of kata) {
    const calon = sekarang ? `${sekarang} ${k}` : k;
    if (lebarTeks(calon, ukuran) > lebarMaks && sekarang) {
      baris.push(sekarang);
      sekarang = k;
    } else {
      sekarang = calon;
    }
  }
  if (sekarang) baris.push(sekarang);
  return baris;
}

/* ------------------------------------------------------------ pengukuran */

let H = HURUF_BAKU;

function lebarTeks(isi, ukuran) {
  return String(isi).length * LEBAR_HURUF * ukuran;
}

function ukurKotak(nama, mode) {
  const t = petaTabel.get(nama);
  if (!t) return null;

  const fkPeta = new Map(t.asing.map((a) => [a.kolom, a]));

  const baris = t.kolom.map((k) => {
    const pk = t.kunci_primer.includes(k.nama);
    const fk = fkPeta.get(k.nama);
    const uq = !pk && t.unik.some((u) => u.kolom.length === 1 && u.kolom[0] === k.nama);
    return {
      nama: k.nama,
      tipe: mode === 'fisikal' ? tipeRapi(k) : null,
      pk,
      fk: fk ? `${fk.ke_tabel}.${fk.ke_kolom}` : null,
      uq,
    };
  });

  const lebarTanda = Math.max(
    lebarTeks('PK', H.kolom * 0.74),
    lebarTeks('FK', H.kolom * 0.74),
    lebarTeks('UQ', H.kolom * 0.74)
  );

  let lebarTerbesar = lebarTeks(nama, H.kepalaTabel);
  for (const b of baris) {
    let w = H.padKiri + lebarTanda + 8 + lebarTeks(b.nama, H.kolom);
    if (b.tipe) w += H.padKanan * 2 + lebarTeks(b.tipe, H.kolom * 0.84);
    else w += H.padKanan;
    lebarTerbesar = Math.max(lebarTerbesar, w);
  }

  const tinggiKepala = H.kepalaTabel * H.tinggiKepala;
  const tinggiBaris = H.kolom * H.tinggiBaris;

  return {
    nama,
    baris,
    lebarTanda,
    w: Math.ceil(lebarTerbesar + H.padKiri * 0.6),
    h: Math.ceil(tinggiKepala + baris.length * tinggiBaris),
    tinggiKepala,
    tinggiBaris,
  };
}

/* --------------------------------------------------------- tata letak */

/**
 * Susun kotak sebagai kolom koran: tiap kotak masuk ke kolom yang paling
 * pendek saat itu. Tidak ada koordinat yang diketik, jadi kotak tidak mungkin
 * bertumpuk - dan menambah tabel cukup dengan menambah namanya di daftar.
 */
function tataLetak(kotak, jumlahKolom, ruangBawah) {
  const kolom = Array.from({ length: jumlahKolom }, () => ({ kotak: [], tinggi: 0, lebar: 0 }));

  // Yang tertinggi lebih dulu: kalau yang pendek dimasukkan dulu, yang tinggi
  // terakhir terpaksa membuat kolom baru dan menyisakan ruang kosong besar.
  const urut = [...kotak].sort((a, b) => b.h - a.h);

  for (const k of urut) {
    let terbaik = 0;
    for (let i = 1; i < kolom.length; i++) {
      if (kolom[i].tinggi < kolom[terbaik].tinggi) terbaik = i;
    }
    kolom[terbaik].kotak.push(k);
    kolom[terbaik].tinggi += k.h + H.jarakBaris;
    kolom[terbaik].lebar = Math.max(kolom[terbaik].lebar, k.w);
  }

  const xAwal = H.tepi;
  const yAwal = H.tepi + H.judul * 2.9;
  let x = xAwal;

  for (const k of kolom) {
    let y = yAwal;
    for (const b of k.kotak) {
      b.x = x + k.lebar / 2;
      b.y = y + b.h / 2;
      b.hw = b.w / 2;
      b.hh = b.h / 2;
      y += b.h + H.jarakBaris;
    }
    x += k.lebar + H.jarakKolom;
  }

  const kanvasW = Math.ceil(x - H.jarakKolom + H.tepi);
  let kanvasH = 0;
  for (const k of kolom) {
    for (const b of k.kotak) kanvasH = Math.max(kanvasH, b.y + b.hh);
  }

  return { kolom, kanvasW, kanvasH: Math.ceil(kanvasH + ruangBawah) };
}

/* ----------------------------------------------------------- perutean */

function titikDiKotak(px, py, b, margin = 2) {
  return (
    px > b.x - b.hw - margin &&
    px < b.x + b.hw + margin &&
    py > b.y - b.hh - margin &&
    py < b.y + b.hh + margin
  );
}

/** Dua kotak beririsan? Dipakai untuk menempatkan label kardinalitas. */
function tumpangKotak(a, b, margin = 0) {
  return (
    Math.abs(a.x - b.x) < a.hw + b.hw + margin && Math.abs(a.y - b.y) < a.hh + b.hh + margin
  );
}

function segmenBebas(x1, y1, x2, y2, kotak, abaikan) {
  const langkah = Math.max(8, Math.ceil(Math.hypot(x2 - x1, y2 - y1) / 5));
  for (let i = 1; i < langkah; i++) {
    const t = i / langkah;
    const px = x1 + (x2 - x1) * t;
    const py = y1 + (y2 - y1) * t;
    for (const b of kotak) {
      if (abaikan.includes(b.nama)) continue;
      if (titikDiKotak(px, py, b, 4)) return false;
    }
  }
  return true;
}

/** Tumpukan minimum sederhana; `Array.sort` di dalam loop terlalu lambat. */
class Tumpukan {
  constructor() {
    this.a = [];
  }
  get kosong() {
    return this.a.length === 0;
  }
  dorong(nilai, prioritas) {
    const a = this.a;
    a.push({ nilai, prioritas });
    let i = a.length - 1;
    while (i > 0) {
      const p = (i - 1) >> 1;
      if (a[p].prioritas <= a[i].prioritas) break;
      [a[p], a[i]] = [a[i], a[p]];
      i = p;
    }
  }
  ambil() {
    const a = this.a;
    const atas = a[0];
    const akhir = a.pop();
    if (a.length) {
      a[0] = akhir;
      let i = 0;
      for (;;) {
        const l = 2 * i + 1;
        const r = l + 1;
        let kecil = i;
        if (l < a.length && a[l].prioritas < a[kecil].prioritas) kecil = l;
        if (r < a.length && a[r].prioritas < a[kecil].prioritas) kecil = r;
        if (kecil === i) break;
        [a[kecil], a[i]] = [a[i], a[kecil]];
        i = kecil;
      }
    }
    return atas.nilai;
  }
}

/**
 * Kisi Hanan sederhana: garis x dan y di lorong antarkotak, ditambah garis
 * tepi tiap kotak. Titik potongnya menjadi simpul pencarian.
 */
function buatKisi(kotak, kanvasW, kanvasH) {
  const xs = new Set([H.tepi * 0.4, kanvasW - H.tepi * 0.4]);
  const ys = new Set([H.tepi * 0.4, kanvasH - 40]);

  for (const b of kotak) {
    xs.add(b.x);
    ys.add(b.y);
    xs.add(b.x - b.hw - 22);
    xs.add(b.x + b.hw + 22);
    ys.add(b.y - b.hh - 22);
    ys.add(b.y + b.hh + 22);
  }

  const isiCelah = (daftar) => {
    const urut = [...daftar].sort((a, b) => a - b);
    const tambah = [];
    for (let i = 0; i + 1 < urut.length; i++) {
      if (urut[i + 1] - urut[i] > 34) tambah.push((urut[i] + urut[i + 1]) / 2);
    }
    for (const v of tambah) daftar.add(v);
  };
  isiCelah(xs);
  isiCelah(ys);

  return { xs: [...xs].sort((a, b) => a - b), ys: [...ys].sort((a, b) => a - b) };
}

/** Dijkstra pada kisi; mengembalikan daftar titik belok, atau null. */
function cariJalur(kisi, mulai, selesai, kotak, abaikan) {
  const { xs, ys } = kisi;
  const ix = xs.indexOf(mulai.x);
  const iy = ys.indexOf(mulai.y);
  const jx = xs.indexOf(selesai.x);
  const jy = ys.indexOf(selesai.y);
  if (ix < 0 || iy < 0 || jx < 0 || jy < 0) return null;

  const kunci = (i, j) => i * ys.length + j;
  const bebas = (i, j) => {
    for (const b of kotak) {
      if (abaikan.includes(b.nama)) continue;
      if (titikDiKotak(xs[i], ys[j], b, 3)) return false;
    }
    return true;
  };

  if (!bebas(ix, iy) || !bebas(jx, jy)) return null;

  const jarak = new Map();
  const induk = new Map();
  const awalK = kunci(ix, iy);
  const akhirK = kunci(jx, jy);
  jarak.set(awalK, 0);

  const buka = new Tumpukan();
  buka.dorong([ix, iy], 0);

  while (!buka.kosong) {
    const [i, j] = buka.ambil();
    const k = kunci(i, j);
    if (k === akhirK) break;

    const dSekarang = jarak.get(k);
    if (dSekarang === undefined) continue;

    for (const [ni, nj] of [
      [i - 1, j],
      [i + 1, j],
      [i, j - 1],
      [i, j + 1],
    ]) {
      if (ni < 0 || nj < 0 || ni >= xs.length || nj >= ys.length) continue;
      if (!bebas(ni, nj)) continue;
      if (!segmenBebas(xs[i], ys[j], xs[ni], ys[nj], kotak, abaikan)) continue;

      const biaya = Math.abs(xs[ni] - xs[i]) + Math.abs(ys[nj] - ys[j]);
      const kn = kunci(ni, nj);
      const dn = dSekarang + biaya;
      if (dn < (jarak.get(kn) ?? Infinity)) {
        jarak.set(kn, dn);
        induk.set(kn, k);
        // Heuristik kecil saja: cukup mengarahkan, tidak mengubah keabsahan.
        const h = Math.abs(xs[ni] - xs[jx]) + Math.abs(ys[nj] - ys[jy]);
        buka.dorong([ni, nj], dn + h * 0.001);
      }
    }
  }

  if (!jarak.has(akhirK)) return null;

  const jalur = [];
  let kk = akhirK;
  while (kk !== undefined) {
    const i = Math.floor(kk / ys.length);
    const j = kk % ys.length;
    jalur.push({ x: xs[i], y: ys[j] });
    kk = induk.get(kk);
  }
  jalur.reverse();
  return jalur;
}

/** Rapatkan jalur ortogonal: buang titik yang segaris. */
function rapatkan(jalur) {
  const hasil = [];
  for (const p of jalur) {
    if (hasil.length >= 2) {
      const a = hasil[hasil.length - 2];
      const b = hasil[hasil.length - 1];
      if ((a.x === b.x && b.x === p.x) || (a.y === b.y && b.y === p.y)) {
        hasil[hasil.length - 1] = p;
        continue;
      }
    }
    hasil.push(p);
  }
  return hasil;
}

function pathDariJalur(titik) {
  if (titik.length < 2) return '';
  return 'M ' + titik.map((p) => `${r1(p.x)} ${r1(p.y)}`).join(' L ');
}

const SISI = [
  { nama: 'kiri', x: (b) => b.x - b.hw, y: (b) => b.y, nx: -1, ny: 0 },
  { nama: 'kanan', x: (b) => b.x + b.hw, y: (b) => b.y, nx: 1, ny: 0 },
  { nama: 'atas', x: (b) => b.x, y: (b) => b.y - b.hh, nx: 0, ny: -1 },
  { nama: 'bawah', x: (b) => b.x, y: (b) => b.y + b.hh, nx: 0, ny: 1 },
];

/** Panjang bagian dua jalur ortogonal yang berimpit. */
function panjangBerimpit(a, b) {
  let total = 0;
  for (let i = 1; i < a.length; i++) {
    const a1 = a[i - 1];
    const a2 = a[i];
    const aHorizontal = Math.abs(a1.y - a2.y) < 0.5;
    for (let j = 1; j < b.length; j++) {
      const b1 = b[j - 1];
      const b2 = b[j];
      const bHorizontal = Math.abs(b1.y - b2.y) < 0.5;
      if (aHorizontal !== bHorizontal) continue;
      if (aHorizontal) {
        if (Math.abs(a1.y - b1.y) > 0.5) continue;
        const kiri = Math.max(Math.min(a1.x, a2.x), Math.min(b1.x, b2.x));
        const kanan = Math.min(Math.max(a1.x, a2.x), Math.max(b1.x, b2.x));
        if (kanan > kiri) total += kanan - kiri;
      } else {
        if (Math.abs(a1.x - b1.x) > 0.5) continue;
        const atas = Math.max(Math.min(a1.y, a2.y), Math.min(b1.y, b2.y));
        const bawah = Math.min(Math.max(a1.y, a2.y), Math.max(b1.y, b2.y));
        if (bawah > atas) total += bawah - atas;
      }
    }
  }
  return total;
}

/** Jarak titik ke ruas garis. */
function jarakTitikGaris(px, py, ax, ay, bx, by) {
  const dx = bx - ax;
  const dy = by - ay;
  const panjang2 = dx * dx + dy * dy;
  if (panjang2 === 0) return Math.hypot(px - ax, py - ay);
  let t = ((px - ax) * dx + (py - ay) * dy) / panjang2;
  t = Math.max(0, Math.min(1, t));
  return Math.hypot(px - (ax + t * dx), py - (ay + t * dy));
}

/**
 * Cari jalur terbaik antara dua kotak: coba 4x4 pasangan sisi, pilih yang
 * total panjangnya paling pendek dan tidak menembus kotak lain.
 *
 * `sudah` berisi jalur yang sudah digambar. Tanpa itu, dua kunci asing antara
 * pasangan kotak yang sama akan menghasilkan jalur yang IDENTIK dan saling
 * menimpa, sehingga dua hubungan terbaca sebagai satu garis. Terjadi pada
 * `usulan_perubahan.diajukan_oleh` dan `diputuskan_oleh`, keduanya menunjuk
 * `users`. Overlap sekarang dihukum berat, bukan sekadar diabaikan.
 */
function hubungkan(anak, induk, kisi, kotak, sudah = []) {
  let terbaik = null;
  let panjangTerbaik = Infinity;
  const abaikan = [anak.nama, induk.nama];

  for (const sa of SISI) {
    for (const si of SISI) {
      const a = { x: sa.x(anak), y: sa.y(anak) };
      const b = { x: si.x(induk), y: si.y(induk) };
      const keluar = { x: a.x + sa.nx * 22, y: a.y + sa.ny * 22 };
      const masuk = { x: b.x + si.nx * 22, y: b.y + si.ny * 22 };

      if (!segmenBebas(a.x, a.y, keluar.x, keluar.y, kotak, abaikan)) continue;

      const jalur = cariJalur(kisi, keluar, masuk, kotak, abaikan);
      if (!jalur) continue;

      if (!segmenBebas(masuk.x, masuk.y, b.x, b.y, kotak, abaikan)) continue;

      const titik = rapatkan([a, ...jalur, b]);
      let panjang = 0;
      for (let i = 1; i < titik.length; i++) {
        panjang += Math.abs(titik[i].x - titik[i - 1].x) + Math.abs(titik[i].y - titik[i - 1].y);
      }
      // Hukuman kecil untuk jalur berbelok banyak.
      panjang += titik.length * 10;

      // Hukuman besar untuk berimpit dengan jalur lain. Angkanya jauh lebih
      // besar daripada panjang wajar mana pun, supaya jalur dipindahkan walau
      // harus memutar: garis yang menimpa garis lain adalah cacat, sedangkan
      // garis yang memutar hanya kurang rapi.
      let berimpit = 0;
      for (const lain of sudah) berimpit += panjangBerimpit(titik, lain);
      panjang += berimpit * 60;

      if (panjang < panjangTerbaik) {
        panjangTerbaik = panjang;
        terbaik = { titik };
      }
    }
  }

  return terbaik;
}

/* -------------------------------------------------------------- gambar */

/**
 * @param {object} definisi
 *   judul, sub, modul, mode ('fisikal' | 'lrs'), jumlahKolom, tabel[], catatan
 */
function gambarPapan(definisi) {
  H = { ...HURUF_BAKU, ...(definisi.huruf || {}) };

  const masalah = [];
  const kotak = [];

  for (const nama of definisi.tabel) {
    if (!petaTabel.has(nama)) {
      masalah.push(`tabel "${nama}" tidak ada di basis data`);
      continue;
    }
    kotak.push(ukurKotak(nama, definisi.mode));
  }

  const namaDalam = new Set(kotak.map((k) => k.nama));

  // Relasi di dalam lembar ini, dan kunci asing yang keluar lembar. Keduanya
  // dihitung dari skema, bukan diketik: daftar yang diketik akan basi, dan
  // daftar yang basi tidak memberi tanda apa pun.
  const relasi = [];
  const keluarLembar = [];
  const diriSendiri = [];
  for (const k of kotak) {
    const t = petaTabel.get(k.nama);
    for (const a of t.asing) {
      if (a.ke_tabel === k.nama) {
        diriSendiri.push(`${k.nama}.${a.kolom}`);
        continue;
      }
      if (!namaDalam.has(a.ke_tabel)) {
        keluarLembar.push(`${k.nama}.${a.kolom} → ${a.ke_tabel}`);
        continue;
      }
      relasi.push({ anak: k.nama, induk: a.ke_tabel, kolom: a.kolom });
    }
  }

  // --- legenda (dihitung lebih dulu agar ruangnya bisa dicadangkan) ---
  //
  // Baris legenda DIBUNGKUS, bukan dibiarkan memanjang. Sebelumnya baris
  // "kunci asing ke lembar lain: ..." memanjang melewati tepi kotak dan
  // terpotong di kanan - teks yang justru menjelaskan relasi lintas lembar jadi
  // tidak terbaca. Lebar bungkusnya dipatok, tidak mengikuti lebar kanvas,
  // supaya jumlah barisnya sudah diketahui SEBELUM tata letak dihitung.
  const LEBAR_BUNGKUS = 1040;

  const barisLegenda = [];
  barisLegenda.push('PK = kunci primer · FK = kunci asing · UQ = unik · garis = hubungan kunci asing');
  barisLegenda.push('1 = tepat satu · N = banyak · angka di sisi anak menunjukkan berapa baris yang boleh menunjuk');
  if (definisi.catatan) barisLegenda.push(definisi.catatan);
  if (diriSendiri.length) barisLegenda.push(`kunci asing menunjuk tabelnya sendiri: ${diriSendiri.join(' · ')}`);
  if (keluarLembar.length) {
    barisLegenda.push(`kunci asing ke lembar lain: ${keluarLembar.join(' · ')}`);
  }

  const legendaTerbungkus = barisLegenda.flatMap((b) => bungkusTeks(b, H.legenda, LEBAR_BUNGKUS));
  const tinggiLegenda = legendaTerbungkus.length * H.legenda * H.legendaBaris + 34;
  const ruangBawah = H.tepi + tinggiLegenda + 14;

  const tata = tataLetak(kotak, definisi.jumlahKolom || 3, ruangBawah);
  const kanvas = { w: tata.kanvasW, h: tata.kanvasH };

  // --- perutean ---
  const kisi = buatKisi(kotak, kanvas.w, kanvas.h);
  const petaKotak = new Map(kotak.map((k) => [k.nama, k]));
  const jalur = [];

  for (const r of relasi) {
    const anak = petaKotak.get(r.anak);
    const induk = petaKotak.get(r.induk);
    if (!anak || !induk || anak === induk) continue;
    const hasil = hubungkan(
      anak,
      induk,
      kisi,
      kotak,
      jalur.map((j) => j.titik)
    );
    if (!hasil) {
      masalah.push(`tidak ada jalur bebas untuk ${r.anak}.${r.kolom} -> ${r.induk}`);
      continue;
    }
    jalur.push({ ...r, titik: hasil.titik });
  }

  // --- gambar ---
  const isi = [];
  isi.push(teksKiri(H.tepi, H.tepi + H.judul * 0.6, definisi.judul, H.judul, 700, C.ink));
  isi.push(teksKiri(H.tepi, H.tepi + H.judul * 0.6 + H.sub * 2.1, definisi.sub, H.sub, 400, C.sub));

  for (const j of jalur) {
    isi.push(
      `<path d="${pathDariJalur(j.titik)}" fill="none" stroke="${C.garis}" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"/>`
    );
  }

  for (const k of kotak) {
    const x0 = k.x - k.hw;
    const y0 = k.y - k.hh;
    isi.push(
      `<rect x="${r1(x0)}" y="${r1(y0)}" width="${r1(k.w)}" height="${r1(k.h)}" rx="7" fill="${C.badan}" stroke="${C.tepi}" stroke-width="2"/>`
    );
    isi.push(
      `<path d="M ${r1(x0)} ${r1(y0 + k.tinggiKepala)} L ${r1(x0 + k.w)} ${r1(y0 + k.tinggiKepala)}" stroke="${C.tepi}" stroke-width="2"/>`
    );
    // Kepala: dua persegi panjang bertumpuk supaya sudut atasnya tetap bulat.
    isi.push(
      `<rect x="${r1(x0)}" y="${r1(y0)}" width="${r1(k.w)}" height="${r1(k.tinggiKepala)}" rx="7" fill="${C.kepala}"/>`
    );
    isi.push(
      `<rect x="${r1(x0)}" y="${r1(y0 + k.tinggiKepala - 9)}" width="${r1(k.w)}" height="9" fill="${C.kepala}"/>`
    );
    isi.push(teksTengah(k.x, y0 + k.tinggiKepala / 2, k.nama, H.kepalaTabel, 700, C.kepalaTeks));

    const yAwalBaris = y0 + k.tinggiKepala;
    k.baris.forEach((b, i) => {
      const yc = yAwalBaris + k.tinggiBaris * (i + 0.5);
      if (i % 2 === 1) {
        isi.push(
          `<rect x="${r1(x0 + 1)}" y="${r1(yc - k.tinggiBaris / 2)}" width="${r1(k.w - 2)}" height="${r1(k.tinggiBaris)}" fill="${C.badanSelang}"/>`
        );
      }

      let x = x0 + H.padKiri;
      if (b.pk) {
        isi.push(teksKiri(x, yc, 'PK', H.kolom * 0.72, 700, C.pk));
      } else if (b.fk) {
        isi.push(teksKiri(x, yc, 'FK', H.kolom * 0.72, 700, C.fk));
      } else if (b.uq) {
        isi.push(teksKiri(x, yc, 'UQ', H.kolom * 0.72, 700, C.uq));
      }
      x += k.lebarTanda + 8;

      const tinta = b.pk ? C.pk : b.fk ? C.fk : C.ink;
      isi.push(teksKiri(x, yc, b.nama, H.kolom, b.pk ? 700 : 500, tinta, b.pk));

      if (b.tipe) {
        isi.push(teksKanan(k.x + k.hw - H.padKanan, yc, b.tipe, H.kolom * 0.82, 400, C.sub));
      }
    });
  }

  // Kardinalitas di ujung garis.
  //
  // Label dikumpulkan dulu, baru digambar. Alasannya: dua garis dari tabel yang
  // sama sering berakhir di tepi kotak yang sama, sehingga labelnya jatuh di
  // titik identik dan tercetak bertumpuk - terbaca sebagai satu glyph rusak,
  // bukan dua label.
  //
  // Posisi label juga TIDAK dipatok pada 45% panjang segmen pertama. Segmen
  // pertama hanya sepanjang 22 px (jarak keluar dari tepi kotak), sehingga
  // 45%-nya masih menempel di tepi dan labelnya tercetak di atas kotak. Label
  // sekarang diletakkan di segmen TERPANJANG, yang selalu di lorong antarkotak.

  /**
   * Calon titik label, diambil dari SEMUA segmen jalurnya sendiri, diurutkan
   * menurut jarak sepanjang jalur DARI UJUNG YANG DIBERIKAN.
   *
   * Versi pertama hanya menyediakan SATU titik lalu mendorongnya berulang kali
   * saat bertabrakan; dorongan itu menumpuk dan label berakhir 185 px dari
   * garisnya. Versi kedua memakai segmen terpanjang saja, dan pada
   * `transaksi_gateway` segmen itu seluruhnya tertutup kotak lain sehingga tidak
   * ada tempat sama sekali.
   *
   * Versi ini menambahkan satu hal yang menentukan keterbacaan: label sisi anak
   * dipilih dari ujung anak, label sisi induk dari ujung induk. Sebelumnya
   * keduanya berebut segmen terpanjang yang sama, sehingga pada kotak dengan
   * banyak relasi keluar — seperti `users` — semua label berbaris di satu titik
   * dan tidak lagi jelas pasangan `1`/`N` mana milik garis mana.
   */
  const calonLabel = (titik) => {
    const segmen = [];
    let jarak = 0;
    for (let i = 1; i < titik.length; i++) {
      const a = titik[i - 1];
      const b = titik[i];
      const panjang = Math.abs(b.x - a.x) + Math.abs(b.y - a.y);
      if (panjang >= 8) {
        segmen.push({
          a,
          b,
          panjang,
          jarak,
          horizontal: Math.abs(a.y - b.y) < 1,
          vertical: Math.abs(a.x - b.x) < 1,
        });
      }
      jarak += panjang;
    }
    segmen.sort((x, y) => x.jarak - y.jarak);

    const geser = H.kolom * 0.9;
    const daftar = [];
    // Fraksi dipusatkan, lalu jarak tegak lurus ditambah bila sisi pertama
    // sudah terpakai. Urutan ini menjaga label tetap sedekat mungkin ke ujung
    // yang bersangkutan.
    for (const jarakTegak of [geser, geser * 1.7, geser * 2.4]) {
      for (const seg of segmen) {
        const { a, b, horizontal, vertical } = seg;
        for (const t of [0.5, 0.65, 0.35, 0.8, 0.2, 0.9, 0.1]) {
          if (horizontal) {
            const x = a.x + (b.x - a.x) * t;
            daftar.push({ x, y: a.y - jarakTegak, seg });
            daftar.push({ x, y: a.y + jarakTegak, seg });
          } else if (vertical) {
            const y = a.y + (b.y - a.y) * t;
            daftar.push({ x: a.x + jarakTegak, y, seg });
            daftar.push({ x: a.x - jarakTegak, y, seg });
          } else {
            daftar.push({ x: (a.x + b.x) / 2 + jarakTegak, y: (a.y + b.y) / 2 - jarakTegak, seg });
          }
        }
      }
    }
    return daftar;
  };

  const label = [];
  const taruhLabel = (calon, isi, warna, titikJalur) => {
    const hw = lebarTeks(isi, H.kolom * 1.05) / 2 + 6;
    const hh = H.kolom * 0.65;

    // Sama ketatnya dengan pemeriksa di bawah: label yang LOLOS di sini tidak
    // boleh lagi ditolak di sana. Versi sebelumnya memakai toleransi berbeda,
    // sehingga label "terpilih" tetap saja menimpa kotak.
    //
    // Selain kotak dan label lain, calon juga ditolak bila terlalu dekat dengan
    // garis milik RELASI LAIN. Tanpa syarat itu, label `1` milik satu relasi
    // boleh duduk persis di samping garis relasi tetangga, dan pembaca tidak
    // bisa lagi menentukan label mana milik garis mana — kasus yang terjadi di
    // bawah kotak `users`.
    const bebas = (c) => {
      const kotakLabel = { x: c.x, y: c.y, hw, hh };
      for (const k of kotak) {
        if (tumpangKotak(kotakLabel, k, 2)) return false;
      }
      for (const l of label) {
        if (tumpangKotak(kotakLabel, l, 2)) return false;
      }
      for (const lain of jalur) {
        if (lain.titik === titikJalur) continue;
        for (let i = 1; i < lain.titik.length; i++) {
          const p = lain.titik[i - 1];
          const q = lain.titik[i];
          const jarak = jarakTitikGaris(c.x, c.y, p.x, p.y, q.x, q.y);
          if (jarak < hh * 1.5) return false;
        }
      }
      return true;
    };

    const pilih = calon.find(bebas);
    if (!pilih) return false;
    label.push({ x: pilih.x, y: pilih.y, isi, warna, seg: pilih.seg, hw, hh });
    return true;
  };

  for (const j of jalur) {
    const t = petaTabel.get(j.anak);
    const unik = t.unik.some((u) => u.kolom.length === 1 && u.kolom[0] === j.kolom);
    if (!taruhLabel(calonLabel(j.titik), unik ? '1' : 'N', unik ? C.uq : C.pk, j.titik)) {
      masalah.push(`tidak ada tempat untuk label sisi anak ${j.anak}.${j.kolom}`);
    }

    // Sisi induk: balik jalurnya supaya segmen terpanjang di sisi induk yang
    // dipakai, bukan segmen yang sama dengan label anak.
    if (!taruhLabel(calonLabel([...j.titik].reverse()), '1', C.uq, j.titik)) {
      masalah.push(`tidak ada tempat untuk label sisi induk ${j.anak}.${j.kolom}`);
    }
  }

  for (const l of label) {
    isi.push(teksTengah(l.x, l.y, l.isi, H.kolom * 1.05, 700, l.warna));
  }

  // --- legenda ---
  const yLegenda = kanvas.h - H.tepi - tinggiLegenda + 34;
  isi.push(
    `<rect x="${H.tepi}" y="${r1(yLegenda - 30)}" width="${r1(Math.min(kanvas.w - H.tepi * 2, LEBAR_BUNGKUS + 60))}" height="${r1(tinggiLegenda)}" rx="9" fill="#fafafa" stroke="${C.legend}" stroke-width="1"/>`
  );
  legendaTerbungkus.forEach((b, i) => {
    isi.push(teksKiri(H.tepi + 24, yLegenda + i * H.legenda * H.legendaBaris, b, H.legenda, 400, C.sub));
  });

  // --- pemeriksa ---
  for (let i = 0; i < kotak.length; i++) {
    for (let j = i + 1; j < kotak.length; j++) {
      const a = kotak[i];
      const b = kotak[j];
      if (Math.abs(a.x - b.x) < a.hw + b.hw && Math.abs(a.y - b.y) < a.hh + b.hh) {
        masalah.push(`kotak "${a.nama}" bertumpuk dengan "${b.nama}"`);
      }
    }
  }

  for (const j of jalur) {
    let menembus = null;
    for (let i = 1; i < j.titik.length && !menembus; i++) {
      for (const k of kotak) {
        if (k.nama === j.anak || k.nama === j.induk) continue;
        if (!segmenBebas(j.titik[i - 1].x, j.titik[i - 1].y, j.titik[i].x, j.titik[i].y, kotak, [j.anak, j.induk])) {
          menembus = k.nama;
          break;
        }
      }
    }
    if (menembus) masalah.push(`garis ${j.anak}.${j.kolom} menembus kotak "${menembus}"`);
  }

  for (const k of kotak) {
    if (k.x - k.hw < 0 || k.y - k.hh < 0 || k.x + k.hw > kanvas.w || k.y + k.hh > kanvas.h) {
      masalah.push(`kotak "${k.nama}" keluar kanvas`);
    }
  }

  // Label kardinalitas juga diperiksa. Sebelumnya tidak: validator hanya
  // membandingkan kotak dan garis, sehingga dua label `N` yang tercetak di
  // titik yang sama lolos - dan hasilnya terbaca sebagai satu glyph rusak.
  for (let i = 0; i < label.length; i++) {
    for (let j = i + 1; j < label.length; j++) {
      const a = label[i];
      const b = label[j];
      if (Math.abs(a.x - b.x) < a.hw + b.hw && Math.abs(a.y - b.y) < a.hh + b.hh) {
        masalah.push(`label kardinalitas "${a.isi}" dan "${b.isi}" bertumpuk di (${Math.round(a.x)},${Math.round(a.y)})`);
      }
    }
    for (const k of kotak) {
      if (titikDiKotak(label[i].x, label[i].y, k, 2)) {
        masalah.push(`label kardinalitas "${label[i].isi}" menimpa kotak "${k.nama}"`);
      }
    }
  }

  // Dua relasi yang tergambar sebagai SATU garis.
  //
  // Pemeriksa di atas membandingkan label dan kotak, tetapi tidak pernah
  // membandingkan garis dengan garis. Akibatnya `usulan_perubahan.diajukan_oleh`
  // dan `diputuskan_oleh` — keduanya menunjuk `users` — tergambar bertumpuk
  // persis, dan hasilnya terlihat seperti satu hubungan yang punya empat label.
  // Gambar seperti itu salah, tetapi terlihat rapi.
  for (let i = 0; i < jalur.length; i++) {
    for (let j = i + 1; j < jalur.length; j++) {
      const a = jalur[i];
      const b = jalur[j];
      const samaPasangan =
        (a.anak === b.anak && a.induk === b.induk) || (a.anak === b.induk && a.induk === b.anak);
      if (!samaPasangan) continue;
      const berimpit = panjangBerimpit(a.titik, b.titik);
      if (berimpit > 12) {
        masalah.push(
          `garis ${a.anak}.${a.kolom} dan ${b.anak}.${b.kolom} berimpit ` +
            `sepanjang ${Math.round(berimpit)} px — terbaca sebagai satu hubungan`
        );
      }
    }
  }

  // Label harus tetap dekat dengan garisnya sendiri.
  for (const l of label) {
    if (!l.seg) continue;
    const { a, b } = l.seg;
    const j = jarakTitikGaris(l.x, l.y, a.x, a.y, b.x, b.y);
    if (j > H.kolom * 2.6) {
      masalah.push(
        `label kardinalitas "${l.isi}" melayang ${Math.round(j)} px dari garisnya ` +
          `(maksimum ${Math.round(H.kolom * 2.6)})`
      );
    }
  }

  const nisbah = hurufEfektif(H.kolom, kanvas);
  if (nisbah < NISBAH_MIN) {
    masalah.push(
      `huruf efektif hanya ${(nisbah * 100).toFixed(2)}% lebar halaman A4 ` +
        `(minimal ${(NISBAH_MIN * 100).toFixed(1)}%) — lembar ini harus dipecah`
    );
  }

  const svg = [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${kanvas.w}" height="${kanvas.h}" viewBox="0 0 ${kanvas.w} ${kanvas.h}" font-family="${FONT}">`,
    `<title>${esc(definisi.judul)}</title>`,
    `<rect width="${kanvas.w}" height="${kanvas.h}" fill="${C.bg}"/>`,
    ...isi,
    '</svg>',
    '',
  ].join('\n');

  return { svg, masalah, kanvas, jumlahRelasi: relasi.length, jumlahTabel: kotak.length, nisbah };
}

/* ------------------------------------------------------------ teks SVG */

function teksKiri(x, y, isi, ukuran, tebal, warna, garisBawah = false) {
  return `<text x="${r1(x)}" y="${r1(y)}" text-anchor="start" dominant-baseline="central" font-size="${ukuran}" font-weight="${tebal}"${garisBawah ? ' text-decoration="underline"' : ''} fill="${warna}">${esc(isi)}</text>`;
}

function teksKanan(x, y, isi, ukuran, tebal, warna) {
  return `<text x="${r1(x)}" y="${r1(y)}" text-anchor="end" dominant-baseline="central" font-size="${ukuran}" font-weight="${tebal}" fill="${warna}">${esc(isi)}</text>`;
}

function teksTengah(x, y, isi, ukuran, tebal, warna) {
  return `<text x="${r1(x)}" y="${r1(y)}" text-anchor="middle" dominant-baseline="central" font-size="${ukuran}" font-weight="${tebal}" fill="${warna}">${esc(isi)}</text>`;
}

module.exports = { gambarPapan, hurufEfektif, NISBAH_MIN, HURUF_BAKU };
