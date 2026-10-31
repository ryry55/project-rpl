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

/**
 * Ukuran kertas dalam milimeter — satu-satunya acuan keterbacaan yang jujur.
 *
 * Sebelumnya pemeriksa ini memakai `RASIO_A4` yang dipatok mati, sehingga
 * SETIAP lembar diukur seolah-olah akan dicetak di A4. Akibatnya lembar yang
 * dirancang untuk A3 selalu ditolak, dan penolakan itu terbaca seolah-olah
 * gambarnya tidak mungkin dibuat — padahal yang salah cuma acuannya.
 */
const KERTAS = {
  A4: { w: 210, h: 297 },
  A3: { w: 297, h: 420 },
};

/**
 * Ambang baca: tinggi huruf minimum di kertas, dalam milimeter.
 *
 * 2,52 mm sama persis dengan 1,2% lebar A4 (210 mm) yang dipakai sebelumnya —
 * jadi ambangnya tidak dilonggarkan, hanya satuannya diganti menjadi satuan
 * yang bisa dibayangkan.
 */
const AMBANG_MM = 2.52;

/**
 * Ambang lama dalam nisbah lebar A4 — diturunkan, bukan ditulis ulang.
 *
 * Ada pemakai lama (dan dokumen) yang menyebut "1,2% lebar A4". Menyimpannya
 * sebagai turunan AMBANG_MM membuat keduanya mustahil berbeda: kalau ambang
 * milimeter diubah, angka persennya ikut berubah.
 */
const NISBAH_MIN = AMBANG_MM / KERTAS.A4.w;

/** Tinggi huruf di kertas (mm) bila gambar dipaskan ke satu lembar kertas. */
function hurufMm(ukuranHuruf, kanvas, namaKertas = 'A4') {
  const k = KERTAS[namaKertas];
  if (!k) throw new Error(`ukuran kertas "${namaKertas}" tidak dikenal`);
  const skala = Math.min(k.w / kanvas.w, k.h / kanvas.h);
  return ukuranHuruf * skala;
}

/** Nisbah huruf terhadap lebar A4 — dipertahankan untuk pemakai lama. */
function hurufEfektif(ukuranHuruf, kanvas) {
  return hurufMm(ukuranHuruf, kanvas, 'A4') / KERTAS.A4.w;
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

/* ------------------------------------------------- ukuran kepala panah */

/**
 * Ukuran kepala panah LRS. Didefinisikan SEKALI, lalu dipakai oleh perutean
 * (untuk menentukan jarak minimum antar titik labuh) dan oleh penggambaran.
 *
 * Dipisah ke fungsi, bukan konstanta, karena ukurannya bergantung pada
 * `H.kolom` — dan `H` berubah bila ukuran huruf disesuaikan. Menyalin angkanya
 * di dua tempat adalah persis kesalahan yang membuat dua kepala panah saling
 * menimpa: perutean mengira 16 px sudah cukup, padahal panahnya 21 px.
 */
function panjangKepalaPanah() {
  return H.kolom * 1.15;
}
function lebarKepalaPanah() {
  return H.kolom * 0.55;
}

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

  // Mode `lrs-kunci` menggambar bentuk LRS klasik: hanya kunci primer dan
  // kunci asing. Bentuk inilah yang membuat 23 tabel bisa muat dalam SATU
  // lembar dengan huruf 1,38% lebar A4 — sedangkan seluruh 201 kolom hanya
  // mencapai 0,97% pada 4 kolom, di bawah ambang keterbacaan. Yang digambar
  // adalah struktur relasionalnya, bukan seluruh atribut; atribut lengkapnya
  // ada di tingkat 2 (fisikal) dan di 03-lrs.md.
  const hanyaKunci = mode === 'lrs-kunci';
  const kolomTampil = hanyaKunci
    ? t.kolom.filter((k) => t.kunci_primer.includes(k.nama) || fkPeta.has(k.nama))
    : t.kolom;

  const baris = kolomTampil.map((k) => {
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

/**
 * Apakah titik (px,py) boleh dihuni jalur?
 *
 * `lubang` adalah daftar "mulut" pada kotak yang boleh ditembus: titik labuh
 * tempat garis masuk ke kotaknya sendiri. Tanpa ini, perutean yang menghormati
 * kotak induk akan menolak SETIAP jalur — karena setiap jalur harus berakhir
 * tepat di tepi kotak induknya sendiri.
 */
function kenaKotak(px, py, b, margin, lubang) {
  if (!titikDiKotak(px, py, b, margin)) return false;
  for (const l of lubang) {
    if (Math.abs(px - l.x) <= l.r && Math.abs(py - l.y) <= l.r) return false;
  }
  return true;
}

function segmenBebas(x1, y1, x2, y2, kotak, lubang = []) {
  const langkah = Math.max(8, Math.ceil(Math.hypot(x2 - x1, y2 - y1) / 5));
  for (let i = 1; i < langkah; i++) {
    const t = i / langkah;
    const px = x1 + (x2 - x1) * t;
    const py = y1 + (y2 - y1) * t;
    for (const b of kotak) {
      if (kenaKotak(px, py, b, 4, lubang)) return false;
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
function buatKisi(kotak, kanvasW, kanvasH, tambahan = { xs: [], ys: [] }) {
  const xs = new Set([H.tepi * 0.4, kanvasW - H.tepi * 0.4]);
  const ys = new Set([H.tepi * 0.4, kanvasH - 40]);

  // Titik sambung yang dihitung di luar kisi (mis. titik induk yang digeser
  // agar dua kunci asing tidak berangkat dari titik yang sama) harus ikut
  // masuk. Tanpa ini perutean menganggapnya tidak ada dan jalurnya gagal.
  for (const x of tambahan.xs) xs.add(x);
  for (const y of tambahan.ys) ys.add(y);

  for (const b of kotak) {
    xs.add(b.x);
    ys.add(b.y);
    xs.add(b.x - b.hw - 22);
    xs.add(b.x + b.hw + 22);
    ys.add(b.y - b.hh - 22);
    ys.add(b.y + b.hh + 22);

    // Titik sambung tiap baris kolom. Garis kunci asing berangkat dari baris
    // kolomnya sendiri (lihat `hubungkan`), jadi koordinat itu HARUS ada di
    // kisi. Kalau tidak, perutean gagal dan dua garis ke tabel yang sama
    // kembali berimpit di titik tengah sisi.
    for (let i = 0; i < b.baris.length; i++) {
      const y = b.y - b.hh + b.tinggiKepala + (i + 0.5) * b.tinggiBaris;
      ys.add(y);
    }
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

/** Panjang bagian dua segmen ortogonal yang berimpit. */
function panjangBerimpitSegmen(x1, y1, x2, y2, sudah) {
  let total = 0;
  const horizontal = Math.abs(y1 - y2) < 0.5;
  for (const lain of sudah) {
    for (let k = 1; k < lain.length; k++) {
      const a1 = lain[k - 1];
      const a2 = lain[k];
      const lainHorizontal = Math.abs(a1.y - a2.y) < 0.5;
      if (horizontal !== lainHorizontal) continue;
      if (horizontal) {
        if (Math.abs(y1 - a1.y) > 0.5) continue;
        const kiri = Math.max(Math.min(x1, x2), Math.min(a1.x, a2.x));
        const kanan = Math.min(Math.max(x1, x2), Math.max(a1.x, a2.x));
        if (kanan > kiri) total += kanan - kiri;
      } else {
        if (Math.abs(x1 - a1.x) > 0.5) continue;
        const atas = Math.max(Math.min(y1, y2), Math.min(a1.y, a2.y));
        const bawah = Math.min(Math.max(y1, y2), Math.max(a1.y, a2.y));
        if (bawah > atas) total += bawah - atas;
      }
    }
  }
  return total;
}

/** Dijkstra pada kisi; mengembalikan daftar titik belok, atau null. */
function cariJalur(kisi, mulai, selesai, kotak, sudah = [], lubang = []) {
  const { xs, ys } = kisi;
  // Titik sambung hasil penggeseran ke baris kolom bisa TIDAK ada di kisi,
  // karena kisi hanya memuat koordinat tepi kotak. Tanpa pemeriksaan ini,
  // indexOf mengembalikan -1 dan jalur dianggap gagal — lalu pemanggil
  // memakai titik tengah sisi, dan dua garis ke tabel yang sama berimpit.
  if (!xs.includes(mulai.x) || !ys.includes(mulai.y) || !xs.includes(selesai.x) || !ys.includes(selesai.y)) {
    return null;
  }
  const ix = xs.indexOf(mulai.x);
  const iy = ys.indexOf(mulai.y);
  const jx = xs.indexOf(selesai.x);
  const jy = ys.indexOf(selesai.y);
  if (ix < 0 || iy < 0 || jx < 0 || jy < 0) return null;

  const kunci = (i, j) => i * ys.length + j;
  const bebas = (i, j) => {
    for (const b of kotak) {
      if (kenaKotak(xs[i], ys[j], b, 3, lubang)) return false;
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
      if (!segmenBebas(xs[i], ys[j], xs[ni], ys[nj], kotak, lubang)) continue;

      const biayaDasar = Math.abs(xs[ni] - xs[i]) + Math.abs(ys[nj] - ys[j]);

      // Biaya berimpit dimasukkan KE DALAM pencarian, bukan hanya dihitung
      // sesudahnya. Tanpa ini, Dijkstra menemukan jalur TERPENDEK dan tidak
      // tahu bahwa jalur itu sudah dipakai kunci asing lain — dua kunci asing
      // ke tabel yang sama lalu mendapat jalur identik dan terbaca sebagai
      // satu hubungan. Penalti di luar pencarian tidak menolong: kandidat
      // jalurnya memang hanya satu yang terpendek.
      const berimpit = panjangBerimpitSegmen(xs[i], ys[j], xs[ni], ys[nj], sudah);
      const biaya = biayaDasar + berimpit * 90;

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
  // Toleransi 3 px, BUKAN 0,5 px. Dua garis yang berjarak 1,2 px tercetak
  // sebagai satu garis di kertas — dan pemeriksa (`cek-erd-lrs.js`) memakai
  // ambang 3 px yang sama. Dengan 0,5 px di sini, perutean menganggap keduanya
  // aman dan pemeriksa menolaknya: dua alat dengan dua definisi "berimpit".
  const RAPAT = 3;
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
        if (Math.abs(a1.y - b1.y) > RAPAT) continue;
        const kiri = Math.max(Math.min(a1.x, a2.x), Math.min(b1.x, b2.x));
        const kanan = Math.min(Math.max(a1.x, a2.x), Math.max(b1.x, b2.x));
        if (kanan > kiri) total += kanan - kiri;
      } else {
        if (Math.abs(a1.x - b1.x) > RAPAT) continue;
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
  const dx = bx - ax;  const dy = by - ay;
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
function hubungkan(
  anak,
  induk,
  kisi,
  kotak,
  sudah = [],
  kolomAnak = null,
  kolomInduk = null,
  labuhInduk = []
) {
  let terbaik = null;
  let panjangTerbaik = Infinity;

  // Kotak anak dan induk TIDAK diabaikan lagi sebagai penghalang.
  //
  // Sebelumnya keduanya dilewati (`abaikan = [anak.nama, induk.nama]`), dan
  // akibatnya Dijkstra bebas berjalan menembus badan kotak induk lalu keluar
  // lewat tepi atasnya. Pada lembar LRS yang padat, tiga kunci asing ke `users`
  // memakai jalan pintas itu: garisnya menembus judul `users` dan kepala
  // panahnya tercetak di atas huruf judul. Validator lama tidak menangkapnya
  // karena ia pun melewati kedua kotak itu saat memeriksa.
  //
  // Gantinya: sebuah "mulut" kecil di titik labuh, supaya garis tetap bisa
  // masuk ke kotaknya sendiri dari luar — dan hanya di situ.
  const lubangUntuk = (...titik) => titik.map((t) => ({ x: t.x, y: t.y, r: H.kolom * 1.1 }));

  // Titik sambung di BARIS kolomnya, bukan di tengah sisi.
  //
  // Satu tabel bisa punya dua kunci asing ke tabel yang sama (misalnya
  // `pembayaran.dicatat_oleh` dan `diterima_oleh`, keduanya ke `users`). Bila
  // keduanya berangkat dari titik tengah sisi yang sama, garisnya berimpit dan
  // terbaca sebagai SATU hubungan. Menambatkan garis ke baris kolom membuat
  // kedua garis berangkat dari ketinggian berbeda, sehingga tidak pernah
  // bertumpuk.
  //
  // `selisih` dipakai untuk menggeser titik sambung ke baris kolom itu. Nol
  // berarti tetap di tengah sisi (perilaku lama, untuk tabel yang tidak punya
  // kolom itu di daftar tampil).
  const selisihBaris = (kotak, mode, kolom) => {
    if (!kolom || !mode) return 0;
    const i = mode.baris.findIndex((b) => b.nama === kolom);
    if (i < 0) return 0;
    // Titik tengah kotak berada di antara baris; baris ke-i berjarak
    // (i + 1.5) * tinggiBaris dari puncak kotak.
    const puncak = kotak.y - kotak.hh;
    const yBaris = puncak + kotak.tinggiKepala + (i + 0.5) * kotak.tinggiBaris;
    return yBaris - kotak.y;
  };

  const dAnak = selisihBaris(anak, anak, kolomAnak);
  const dInduk = selisihBaris(induk, induk, kolomInduk);

  // --- ukuran kepala panah: SATU sumber untuk perutean dan penggambaran ---
  //
  // Sebelumnya ukuran ini hanya ada di tempat penggambaran, sedangkan perutean
  // memakai angka sendiri: jarak labuh H.kolom * 0,85 = 16,1 px, padahal tinggi
  // kepala panah 2 * 0,55 * H.kolom = 20,9 px. Jarak minimum titik labuh lebih
  // KECIL daripada panahnya sendiri, jadi dua panah yang "sudah cukup jauh"
  // menurut perutean tetap saling menimpa saat digambar — terlihat sebagai satu
  // kepala panah bertakik pada tepi kanan `users`.
  const TINGGI_KEPALA = lebarKepalaPanah() * 2;

  // Jarak minimum antar titik labuh pada kotak induk yang sama. Dua garis yang
  // berlabuh lebih dekat dari ini terbaca sebagai satu garis, dan panahnya
  // bertumpuk menjadi satu kepala panah yang tebal.
  //
  // Diturunkan dari tinggi kepala panah, bukan diketik terpisah: dengan begitu
  // mengubah ukuran panah tidak bisa lagi membuat titik labuh saling menimpa.
  const JARAK_LABUH = Math.ceil(TINGGI_KEPALA * 1.25);

  // Koordinat titik labuh harus ada di kisi; kalau tidak, perutean menolaknya
  // dan jatuh ke perilaku lama.
  const pastikanDiKisi = (x, y) => {
    if (!kisi.xs.includes(x)) {
      kisi.xs.push(x);
      kisi.xs.sort((a, b) => a - b);
    }
    if (!kisi.ys.includes(y)) {
      kisi.ys.push(y);
      kisi.ys.sort((a, b) => a - b);
    }
  };

  const batasY = induk.hh - 8;
  const batasX = induk.hw - 8;

  // Titik labuh yang DITAWARKAN pada tiap sisi, bukan satu titik tetap.
  //
  // Tanpa ini, penalti titik labuh tidak ada gunanya: semua jalur yang memilih
  // sisi yang sama berlabuh di koordinat yang persis sama, sehingga hukumannya
  // bernilai sama untuk semua kandidat dan tidak mengubah pilihan apa pun.
  // Dengan beberapa tawaran di sepanjang sisi, jalur bisa memilih titik yang
  // masih longgar — dan penyebarannya muncul dari keputusan perutean sendiri.
  const tawaran = (si) => {
    const daftar = [];
    for (const geser of [0, -1, 1, -2, 2, -3, 3]) {
      if (si.ny === 0) {
        const y = si.y(induk) + Math.max(-batasY, Math.min(batasY, dInduk + geser * JARAK_LABUH * 0.55));
        if (Math.abs(y - si.y(induk)) <= batasY) daftar.push({ x: si.x(induk), y });
      } else {
        const x = si.x(induk) + Math.max(-batasX, Math.min(batasX, geser * JARAK_LABUH * 0.55));
        if (Math.abs(x - si.x(induk)) <= batasX) daftar.push({ x, y: si.y(induk) });
      }
    }
    return daftar;
  };

  for (const sa of SISI) {
    for (const si of SISI) {
      const a = { x: sa.x(anak), y: sa.y(anak) };
      if (sa.ny === 0 && dAnak) a.y += dAnak;

      for (const b of tawaran(si)) {
        pastikanDiKisi(b.x, b.y);
        const keluar = { x: a.x + sa.nx * 22, y: a.y + sa.ny * 22 };
        const masuk = { x: b.x + si.nx * 22, y: b.y + si.ny * 22 };
        pastikanDiKisi(masuk.x, masuk.y);

        // Mulut hanya di kedua titik labuh jalur ini. Titik lain pada kedua
        // kotak tetap penghalang, sehingga jalur tidak bisa memotong badannya.
        const lubang = lubangUntuk(a, b);

        if (!segmenBebas(a.x, a.y, keluar.x, keluar.y, kotak, lubang)) continue;

        const jalur = cariJalur(kisi, keluar, masuk, kotak, sudah, lubang);
        if (!jalur) continue;

        if (!segmenBebas(masuk.x, masuk.y, b.x, b.y, kotak, lubang)) continue;

        const titik = rapatkan([a, ...jalur, b]);
        let panjang = 0;
        for (let i = 1; i < titik.length; i++) {
          panjang += Math.abs(titik[i].x - titik[i - 1].x) + Math.abs(titik[i].y - titik[i - 1].y);
        }
        // Hukuman kecil untuk jalur berbelok banyak.
        panjang += titik.length * 10;

        // Hukuman untuk berimpit dengan jalur lain. Angkanya jauh lebih besar
        // daripada panjang wajar mana pun, supaya jalur dipindahkan walau harus
        // memutar: garis yang menimpa garis lain adalah cacat, sedangkan garis
        // yang memutar hanya kurang rapi.
        //
        // Hukumannya KUADRATIK, bukan sebanding. Dengan penalti sebanding, dua
        // jalur yang terpaksa berbagi koridor sempit akan tetap memilih koridor
        // itu karena memutar lebih mahal — dan hasilnya dua garis yang terbaca
        // sebagai satu hubungan. Kuadratik membuat berbagi koridor panjang
        // menjadi mahal tanpa batas, sehingga jalur memilih memutar.
        let berimpit = 0;
        for (const lain of sudah) berimpit += panjangBerimpit(titik, lain);
        panjang += berimpit * berimpit * 0.5 + berimpit * 60;

        // Hukuman titik labuh yang terlalu dekat dengan titik labuh lain pada
        // kotak induk yang sama. Tanpa ini, sembilan kunci asing ke `users`
        // boleh berlabuh di satu titik: garisnya berimpit di ujung dan
        // panahnya menumpuk jadi satu kepala panah tebal — terbaca sebagai
        // satu hubungan, padahal ada sembilan.
        for (const l of labuhInduk) {
          if (l.kotak !== induk.nama) continue;
          const dekat = Math.abs(l.x - b.x) + Math.abs(l.y - b.y);
          if (dekat < JARAK_LABUH) {
            panjang += (JARAK_LABUH - dekat) * 120;
          }
        }

        if (panjang < panjangTerbaik) {
          panjangTerbaik = panjang;
          terbaik = { titik };
        }
      }
    }
  }

  if (terbaik) {
    const ujung = terbaik.titik[terbaik.titik.length - 1];
    labuhInduk.push({ kotak: induk.nama, x: ujung.x, y: ujung.y });
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
      relasi.push({ anak: k.nama, induk: a.ke_tabel, kolom: a.kolom, keKolom: a.ke_kolom });
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

  // Kardinalitas hanya digambar pada notasi yang memang memakainya.
  //
  // LRS adalah notasi relasional: hubungan dinyatakan oleh kunci asing
  // bertanda bintang yang menunjuk tabel induk, bukan oleh angka `1`/`N`.
  // Angka itu milik notasi Chen (tingkat 0 dan 1). Pada lembar LRS, `users`
  // menerima sembilan kunci asing; menambahkan delapan belas label di
  // sekelilingnya membuat gambar penuh angka yang tidak menambah informasi
  // apa pun, dan sebagian tidak mendapat tempat sama sekali.
  //
  // Didefinisikan sebelum legenda dibangun, karena legenda juga memakainya.
  const pakaiLabel = definisi.mode === 'fisikal';

  const barisLegenda = [];
  barisLegenda.push('PK = kunci primer · FK = kunci asing · UQ = unik · garis = hubungan kunci asing');
  if (pakaiLabel) {
    barisLegenda.push('1 = tepat satu · N = banyak · angka di sisi anak menunjukkan berapa baris yang boleh menunjuk');
  }
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

  // Beberapa kunci asing bisa menunjuk kolom induk yang SAMA. Yang paling
  // sering: sembilan kunci asing menuju `users.id` — `pembayaran.dicatat_oleh`,
  // `tagihan.dibatalkan_oleh`, `verifikasi_pembayaran.diverifikasi_oleh`, dan
  // seterusnya. Bila semuanya berlabuh di satu titik, garisnya berimpit sejak
  // titik pangkal, panahnya menumpuk, dan pembaca melihat satu hubungan
  // padahal ada sembilan.
  //
  // Penyebaran yang dipaksakan (membagi rata tinggi kotak) tidak cukup: sisi
  // yang dipilih tiap jalur berbeda-beda, jadi dua jalur yang kebetulan
  // memilih sisi berbeda tetap bisa berlabuh di titik yang sama. Yang dipakai
  // di sini adalah daftar titik labuh yang SUDAH terpakai, dan `hubungkan`
  // menghukum titik labuh yang terlalu dekat dengan salah satunya. Dengan
  // begitu penyebarannya muncul dari pemilihan jalur itu sendiri, bukan dari
  // angka yang ditebak.
  const labuhInduk = [];

  const kisi = buatKisi(kotak, kanvas.w, kanvas.h);
  const petaKotak = new Map(kotak.map((k) => [k.nama, k]));
  const jalur = [];

  for (const r of relasi) {
    const anak = petaKotak.get(r.anak);
    const induk = petaKotak.get(r.induk);
    if (!anak || !induk || anak === induk) continue;
    // Titik sambung induk memakai kolom yang DIRUJUK (mis. `id`), anak memakai
    // kolom kunci asingnya sendiri. Keduanya sudah ada di data skema.
    const hasil = hubungkan(
      anak,
      induk,
      kisi,
      kotak,
      jalur.map((j) => j.titik),
      r.kolom,
      r.keKolom,
      labuhInduk
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

  // `pakaiLabel` sudah didefinisikan di atas (legenda memakainya juga).

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
          // Label boleh berdekatan dengan garis lain, asal TIDAK MENYENTUHNYA.
          // Syarat lama (1,5x tinggi label) terlalu keras: pada lembar padat
          // semua calon label jadi tertolak, dan label sisi induk hilang sama
          // sekali — lebih buruk daripada label yang berdekatan.
          if (jarak < hh * 0.75) return false;
        }
      }
      return true;
    };

    const pilih = calon.find(bebas);
    if (!pilih) return false;
    label.push({ x: pilih.x, y: pilih.y, isi, warna, seg: pilih.seg, hw, hh });
    return true;
  };

  // `pakaiLabel` bernilai salah pada LRS (lihat alasannya di atas). Pemeriksaan
  // ditaruh di sini, bukan sebagai `break` di dalam loop, supaya maksudnya
  // terbaca: label memang tidak dipakai sejak awal, bukan dihentikan.
  if (pakaiLabel) {
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
  }

  for (const l of label) {
    isi.push(teksTengah(l.x, l.y, l.isi, H.kolom * 1.05, 700, l.warna));
  }

  // --- panah arah hubungan (khusus LRS) ---
  //
  // Digambar SESUDAH kotak, bukan sebagai `marker-end` pada jalurnya.
  // Dengan `marker-end`, kepala panah menempel tepat di tepi kotak induk —
  // dan karena kotak digambar sesudah garis, kepala panah itu tertimbun
  // kotak dan tidak terlihat sama sekali. Yang tergambar hanya ujung garis
  // polos, sehingga arah hubungan tidak terbaca.
  //
  // Kepala panah diletakkan sedikit di luar tepi kotak, mengikuti arah segmen
  // TERAKHIR jalurnya, sehingga tetap benar walau jalurnya berbelok.
  //
  // Digambar untuk SEMUA lembar LRS, bukan hanya mode kolom-kunci: begitu label
  // `1`/`N` tidak dipakai, panahlah satu-satunya penanda arah hubungan.
  if (!pakaiLabel) {
    const panjangKepala = panjangKepalaPanah();
    const lebarKepala = lebarKepalaPanah();
    for (const j of jalur) {
      const n = j.titik.length;
      if (n < 2) continue;
      const ujung = j.titik[n - 1];
      const sebelumnya = j.titik[n - 2];
      const dx = ujung.x - sebelumnya.x;
      const dy = ujung.y - sebelumnya.y;
      const panjang = Math.abs(dx) + Math.abs(dy);
      if (panjang < 1) continue;
      const ux = dx / panjang;
      const uy = dy / panjang;
      // Pangkal kepala panah, mundur dari tepi kotak.
      const px = ujung.x - ux * panjangKepala;
      const py = ujung.y - uy * panjangKepala;
      // Dua titik sayap, tegak lurus arah.
      const sx = -uy * lebarKepala;
      const sy = ux * lebarKepala;
      isi.push(
        `<path d="M ${r1(ujung.x)} ${r1(ujung.y)} L ${r1(px + sx)} ${r1(py + sy)} L ${r1(px - sx)} ${r1(py - sy)} Z" fill="${C.garis}"/>`
      );
    }
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

  // Garis diperiksa terhadap SELURUH kotak, termasuk kotak anak dan induknya
  // sendiri.
  //
  // Versi sebelumnya melewati keduanya (`[j.anak, j.induk]`), meniru cara
  // perutean lama. Akibatnya persis cacat yang paling merusak lolos tanpa
  // terdeteksi: garis yang menembus badan kotak induk lalu keluar lewat tepi
  // atas, dengan kepala panah tercetak di atas judul tabel. Pemeriksa tidak
  // boleh memakai kekecualian yang sama dengan yang dipakai perutean untuk
  // menyembunyikan cacat.
  //
  // Diperiksa HANYA bagian dalam kotak, dengan penyusutan 2 px: titik labuh
  // memang menempel di tepi, dan itu bukan pelanggaran.
  for (const j of jalur) {
    let menembus = null;
    for (let i = 1; i < j.titik.length && !menembus; i++) {
      const a1 = j.titik[i - 1];
      const a2 = j.titik[i];
      const langkah = Math.max(8, Math.ceil(Math.hypot(a2.x - a1.x, a2.y - a1.y) / 4));
      for (const k of kotak) {
        for (let s = 0; s <= langkah; s++) {
          const px = a1.x + ((a2.x - a1.x) * s) / langkah;
          const py = a1.y + ((a2.y - a1.y) * s) / langkah;
          const dalam =
            px > k.x - k.hw + 2 &&
            px < k.x + k.hw - 2 &&
            py > k.y - k.hh + 2 &&
            py < k.y + k.hh - 2;
          if (dalam) {
            menembus = k.nama;
            break;
          }
        }
        if (menembus) break;
      }
    }
    if (menembus) masalah.push(`garis ${j.anak}.${j.kolom} menembus kotak "${menembus}"`);
  }

  // Kepala panah diperiksa terpisah, karena ia digambar SESUDAH kotak: cacat
  // yang paling terlihat bukan garisnya, melainkan segitiga yang tercetak di
  // atas huruf judul tabel. Pemeriksaan garis di atas tidak menangkapnya kalau
  // garisnya sendiri berhenti tepat di tepi.
  if (!pakaiLabel) {
    const panjangKepala = panjangKepalaPanah();
    const lebarKepala = lebarKepalaPanah();
    for (const j of jalur) {
      const n = j.titik.length;
      if (n < 2) continue;
      const ujung = j.titik[n - 1];
      const sebelumnya = j.titik[n - 2];
      const dx = ujung.x - sebelumnya.x;
      const dy = ujung.y - sebelumnya.y;
      const panjang = Math.abs(dx) + Math.abs(dy);
      if (panjang < 1) continue;
      const ux = dx / panjang;
      const uy = dy / panjang;
      const px = ujung.x - ux * panjangKepala;
      const py = ujung.y - uy * panjangKepala;
      const sx = -uy * lebarKepala;
      const sy = ux * lebarKepala;
      const sudut = [
        { x: ujung.x, y: ujung.y },
        { x: px + sx, y: py + sy },
        { x: px - sx, y: py - sy },
      ];
      for (const k of kotak) {
        // Ujung panah menyentuh tepi kotak induknya sendiri — itu wajar.
        const dalam = sudut.filter(
          (t) =>
            t.x > k.x - k.hw + 1 &&
            t.x < k.x + k.hw - 1 &&
            t.y > k.y - k.hh + 1 &&
            t.y < k.y + k.hh - 1
        );
        if (dalam.length > 1) {
          masalah.push(
            `kepala panah ${j.anak}.${j.kolom} tercetak di dalam kotak "${k.nama}" ` +
              `(${dalam.length} titik) — akan menutupi huruf`
          );
        }
      }
    }

    // Dua kepala panah yang berlabuh terlalu dekat akan saling menimpa dan
    // terbaca sebagai satu panah bertakik. Diperiksa dengan ukuran kepala panah
    // yang SAMA dengan yang dipakai perutean, sehingga tidak mungkin perutean
    // menganggap cukup sementara hasil gambarnya bertumpuk.
    //
    // Hanya panah pada SISI KOTAK YANG SAMA yang dibandingkan: dua panah pada
    // kotak berbeda boleh berdekatan tanpa saling menimpa, karena arah
    // panahnya berbeda.
    const jarakMin = lebarKepalaPanah() * 2 * 1.05;
    const sisiDari = (t) => {
      for (const k of kotak) {
        const diKiri = Math.abs(t.x - (k.x - k.hw)) < 1.5;
        const diKanan = Math.abs(t.x - (k.x + k.hw)) < 1.5;
        const diAtas = Math.abs(t.y - (k.y - k.hh)) < 1.5;
        const diBawah = Math.abs(t.y - (k.y + k.hh)) < 1.5;
        if (diKiri && t.y >= k.y - k.hh - 1 && t.y <= k.y + k.hh + 1) return `${k.nama}:kiri`;
        if (diKanan && t.y >= k.y - k.hh - 1 && t.y <= k.y + k.hh + 1) return `${k.nama}:kanan`;
        if (diAtas && t.x >= k.x - k.hw - 1 && t.x <= k.x + k.hw + 1) return `${k.nama}:atas`;
        if (diBawah && t.x >= k.x - k.hw - 1 && t.x <= k.x + k.hw + 1) return `${k.nama}:bawah`;
      }
      return null;
    };

    const ujungPanah = jalur.map((j) => ({
      anak: j.anak,
      kolom: j.kolom,
      t: j.titik[j.titik.length - 1],
      sisi: sisiDari(j.titik[j.titik.length - 1]),
    }));

    for (let i = 0; i < ujungPanah.length; i++) {
      for (let j2 = i + 1; j2 < ujungPanah.length; j2++) {
        const a = ujungPanah[i];
        const b = ujungPanah[j2];
        if (!a.sisi || a.sisi !== b.sisi) continue;
        const dekat = Math.abs(a.t.x - b.t.x) + Math.abs(a.t.y - b.t.y);
        if (dekat < jarakMin) {
          masalah.push(
            `kepala panah ${a.anak}.${a.kolom} dan ${b.anak}.${b.kolom} bertumpuk ` +
              `di sisi ${a.sisi} (jarak ${Math.round(dekat)} px, minimum ${Math.round(jarakMin)})`
          );
        }
      }
    }
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

  // Keterbacaan diukur pada kertas yang MEMANG akan dipakai lembar ini, bukan
  // pada A4. Lembar LRS dirancang untuk A3 dan diserahkan terpisah, jadi
  // mengukurnya sebagai A4 akan menolaknya secara keliru.
  const namaKertas = definisi.kertas || 'A4';
  const mm = hurufMm(H.kolom * 1.0, kanvas, namaKertas);
  if (mm > 0 && mm < AMBANG_MM) {
    masalah.push(
      `huruf hanya ${mm.toFixed(2)} mm di kertas ${namaKertas} ` +
        `(minimal ${AMBANG_MM.toFixed(2)} mm) — lembar ini harus dipecah ` +
        `atau dicetak di kertas yang lebih besar`
    );
  }

  // Keterbacaan dalam nisbah lebar A4 tetap dicatat: angka inilah yang dipakai
  // antarmuka dan dokumen selama ini, dan menghilangkannya akan memutus
  // pembandingan dengan lembar-lembar sebelumnya.
  const nisbah = hurufEfektif(H.kolom, kanvas);

  const svg = [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${kanvas.w}" height="${kanvas.h}" viewBox="0 0 ${kanvas.w} ${kanvas.h}" font-family="${FONT}">`,
    `<title>${esc(definisi.judul)}</title>`,
    `<rect width="${kanvas.w}" height="${kanvas.h}" fill="${C.bg}"/>`,
    ...isi,
    '</svg>',
    '',
  ].join('\n');

  return {
    svg,
    masalah,
    kanvas,
    jumlahRelasi: relasi.length,
    jumlahTabel: kotak.length,
    nisbah,
    hurufMm: mm,
    kertas: namaKertas,
  };
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

module.exports = {
  gambarPapan,
  hurufEfektif,
  hurufMm,
  AMBANG_MM,
  KERTAS,
  NISBAH_MIN,
  HURUF_BAKU,
};
