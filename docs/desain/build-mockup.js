/**
 * Mockup layar aplikasi Kos-Kosan — penggambar SVG.
 *
 * Jalankan: `node docs/desain/build-mockup.js`
 * Lalu:     `powershell -ExecutionPolicy Bypass -File docs/desain/export-png.ps1`
 *
 * **Kenapa SVG dan bukan alat desain.** FigCraft masih alpha dan tidak punya CLI
 * untuk Windows, jadi ia tidak bisa dijalankan tanpa tangan manusia. SVG bisa
 * dibangkitkan, diukur, dan dirender ulang setiap kali label berubah — dan
 * labelnya memang dibaca dari kode, bukan dikarang.
 *
 * **Aturan yang dijaga berkas ini:** seluruh tulisan di sini harus sama dengan
 * tulisan di aplikasi. Tulisan diambil dari view Vue-nya; kalau view berubah,
 * gambarnya harus ikut. `rancangan-antarmuka.md` mencatat dari mana.
 *
 * Token warna, huruf, dan ukuran di bawah disalin dari `DESIGN.md` dan
 * `web/src/style.css`. Jangan menambah warna baru di sini: palet ini sengaja
 * tanpa warna merek.
 */

const fs = require('fs');
const path = require('path');

const DI = __dirname;

/* ---------------------------------------------------------------- token */

const W = 1440;
const H = 900;

const C = {
  bg: '#f8f8f8',
  surface: '#ffffff',
  raised: '#f2f2f2',
  active: '#ececec',
  hover: '#fafafa',
  border: '#e6e6e6',
  rule: '#f0f0f0',
  control: '#d4d4d4',
  ink: '#0a0a0a',
  muted: '#6b6b6b',
  primary: '#141414',
  ok: '#2e7d32',
  okBg: '#dcfae5',
  warn: '#92400e',
  warnBg: '#fffbeb',
  bad: '#c4242a',
  badBg: '#ffedea',
};

// Nama keluarga huruf memakai kutip tunggal: nilai ini masuk ke atribut SVG
// yang dibungkus kutip ganda, dan kutip ganda di dalamnya memutus atributnya.
const FONT = "'Geist', 'Segoe UI', system-ui, sans-serif";
const MONO = "'Geist Mono', 'Cascadia Mono', ui-monospace, monospace";

/** Huruf disematkan supaya hasilnya sama di mesin mana pun. 28 KB, tak sepadan
 *  dengan bedanya bila jatuh ke Segoe UI. */
const fontFace = (nama, berkas, weight) => {
  const b64 = fs.readFileSync(path.join(DI, '..', '..', 'web', 'public', 'font', berkas)).toString('base64');
  return `@font-face{font-family:"${nama}";font-weight:${weight};src:url(data:font/woff2;base64,${b64}) format("woff2");}`;
};

/* ------------------------------------------------------------- penggambar */

const esc = (s) =>
  String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/** Teks satu baris. `y` adalah garis dasar, seperti SVG biasa. */
function t(x, y, isi, { size = 13, weight = 400, fill = C.ink, anchor = 'start', mono = false, opasitas = 1 } = {}) {
  return `<text x="${x}" y="${y}" font-family="${mono ? MONO : FONT}" font-size="${size}" font-weight="${weight}" fill="${fill}" text-anchor="${anchor}"${opasitas < 1 ? ` opacity="${opasitas}"` : ''}>${esc(isi)}</text>`;
}

function kotak(x, y, w, h, { fill = C.surface, stroke = C.border, r = 8, sw = 1 } = {}) {
  return `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${r}" fill="${fill}"${stroke ? ` stroke="${stroke}" stroke-width="${sw}"` : ''}/>`;
}

function garis(x1, y1, x2, y2, warna = C.rule, sw = 1) {
  return `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" stroke="${warna}" stroke-width="${sw}"/>`;
}

/** Tombol: `utama` = satu-satunya tombol gelap penuh di layar itu. */
function tombol(x, y, label, { utama = false, w = 0 } = {}) {
  const lebar = w || Math.round(label.length * 7.2 + 26);
  const h = 36;
  const latar = utama ? C.primary : C.surface;
  const tepi = utama ? C.primary : C.control;
  return [
    kotak(x, y, lebar, h, { fill: latar, stroke: tepi, r: 6 }),
    t(x + lebar / 2, y + h / 2 + 4.5, label, {
      size: 13,
      weight: 500,
      fill: utama ? '#ffffff' : C.ink,
      anchor: 'middle',
    }),
  ].join('');
}

function medan(x, y, w, label, isi, { placeholder = true } = {}) {
  return [
    t(x, y, label, { size: 12, weight: 500, fill: C.muted }),
    kotak(x, y + 8, w, 36, { r: 6, stroke: C.control }),
    t(x + 11, y + 32, isi, { size: 13, fill: placeholder ? C.muted : C.ink }),
  ].join('');
}

const BADGE = {
  terisi: [C.ok, C.okBg, 'terisi'],
  kosong: [C.muted, C.active, 'kosong'],
  dipesan: [C.warn, C.warnBg, 'dipesan'],
  perbaikan: [C.bad, C.badBg, 'perbaikan'],
  lunas: [C.ok, C.okBg, 'lunas'],
  sebagian: [C.warn, C.warnBg, 'sebagian'],
  'belum bayar': [C.warn, C.warnBg, 'belum bayar'],
  terlambat: [C.bad, C.badBg, 'terlambat'],
};

function badge(x, y, kunci) {
  const [warna, latar, label] = BADGE[kunci];
  const lebar = Math.round(label.length * 6.6 + 18);
  return [
    `<rect x="${x}" y="${y}" width="${lebar}" height="22" rx="999" fill="${latar}"/>`,
    t(x + lebar / 2, y + 15, label, { size: 12, weight: 500, fill: warna, anchor: 'middle' }),
  ].join('');
}

/** Perkiraan lebar teks 13px, cukup untuk merenggangkan tautan dalam satu sel.
 *  Huruf Geist proporsional, jadi ini tebakan - bukan angka yang diukur. */
const lebarTeks = (s) => Math.round(String(s).length * 6.9);

/**
 * Tabel. `kolom` = [{ label, w, kanan, mono }], lebar kolom dijumlahkan
 * lalu sisanya dibagi rata bila totalnya kurang dari `lebar`. Sebuah sel boleh
 * berupa array, dan isinya digambar sebagai beberapa tautan berjarak - dipakai
 * kolom Aksi yang di aplikasi berisi tiga tombol.
 */
function tabel(x, y, lebar, kolom, baris, { tinggiBaris = 44 } = {}) {
  const total = kolom.reduce((a, k) => a + k.w, 0);
  const sisa = lebar - total;
  const keluar = [];
  let cx = x;
  const posisi = kolom.map((k) => {
    const lebarKolom = k.w + Math.round(sisa / kolom.length);
    const pos = { ...k, x: cx, w: lebarKolom };
    cx += lebarKolom;
    return pos;
  });

  keluar.push(garis(x, y, x + lebar, y, C.border));
  posisi.forEach((k) => {
    const tx = k.kanan ? k.x + k.w - 10 : k.x + 10;
    keluar.push(t(tx, y - 11, k.label, { size: 12, weight: 500, fill: C.muted, anchor: k.kanan ? 'end' : 'start' }));
  });

  baris.forEach((sel, i) => {
    const ry = y + i * tinggiBaris;
    keluar.push(garis(x, ry, x + lebar, ry, C.rule));
    sel.forEach((nilai, j) => {
      const k = posisi[j];
      const tx = k.kanan ? k.x + k.w - 10 : k.x + 10;
      if (k.badge && BADGE[nilai]) {
        keluar.push(badge(k.x + 10, ry + 11, nilai));
      } else if (Array.isArray(nilai)) {
        const jarak = 18;
        const lebarIsi = nilai.reduce((a, s, n) => a + lebarTeks(s) + (n ? jarak : 0), 0);
        let lx = k.kanan ? k.x + k.w - 10 - lebarIsi : k.x + 10;
        nilai.forEach((s) => {
          keluar.push(t(lx, ry + 26, s, { size: 13, fill: C.ink }));
          lx += lebarTeks(s) + jarak;
        });
      } else {
        keluar.push(
          t(tx, ry + 26, nilai, { size: 13, fill: C.ink, anchor: k.kanan ? 'end' : 'start', mono: k.mono }),
        );
      }
    });
  });

  const akhir = y + baris.length * tinggiBaris;
  keluar.push(garis(x, akhir, x + lebar, akhir, C.border));
  return keluar.join('');
}

/* ------------------------------------------------------------------ chrome */

const NAV = [
  ['', ['Kamar', 'Penghuni', 'Kontrak']],
  ['Uang', ['Tagihan', 'Rekap', 'Tunggakan', 'Rekening']],
  ['Kos', ['Kebijakan', 'Fasilitas', 'Aturan kos']],
  ['Akses', ['Pengelola', 'Persetujuan', 'Jejak audit']],
];

function sidebar(aktif) {
  const s = [];
  s.push(kotak(0, 0, 244, H, { fill: C.surface, stroke: null, r: 0 }));
  s.push(garis(243.5, 0, 243.5, H, C.border));

  s.push(`<rect x="20" y="22" width="26" height="26" rx="6" fill="${C.active}"/>`);
  s.push(t(33, 40, 'K', { size: 12, weight: 600, anchor: 'middle' }));
  s.push(t(56, 40, 'Kos-Kosan', { size: 14, weight: 600 }));

  let y = 78;
  NAV.forEach(([grup, item]) => {
    if (grup) {
      s.push(t(20, y, grup, { size: 11, weight: 500, fill: C.muted }));
      y += 18;
    }
    item.forEach((nama) => {
      const on = nama === aktif;
      if (on) s.push(kotak(12, y - 15, 220, 30, { fill: C.active, stroke: null, r: 6 }));
      s.push(t(24, y + 5, nama, { size: 13, weight: on ? 500 : 400, fill: on ? C.ink : '#3d3d3d' }));
      y += 32;
    });
    y += 8;
  });
  return s.join('');
}

function bilahAtas() {
  return [
    kotak(244, 0, W - 244, 56, { fill: C.surface, stroke: null, r: 0 }),
    garis(244, 55.5, W, 55.5, C.border),
    t(1272, 33, 'Pemilik kos', { size: 13, fill: C.muted, anchor: 'end' }),
    `<circle cx="1292" cy="28" r="14" fill="${C.active}"/>`,
    t(1292, 33, 'P', { size: 12, weight: 600, anchor: 'middle' }),
    t(1320, 33, 'Keluar', { size: 13, weight: 500 }),
  ].join('');
}

function kepala(x, y, judul, keterangan, aksi = '') {
  return [
    t(x, y, judul, { size: 24, weight: 600 }),
    t(x, y + 22, keterangan, { size: 13, fill: C.muted }),
    aksi,
  ].join('');
}

/* ------------------------------------------------------------------ layar */

function layarMasuk() {
  const s = [];
  s.push(kotak(0, 0, W, H, { fill: C.bg, stroke: null, r: 0 }));

  const kx = (W - 380) / 2;
  const ky = 214;
  s.push(kotak(kx, ky, 380, 472, { fill: C.surface, r: 8 }));

  s.push(`<rect x="${kx + 32}" y="${ky + 34}" width="34" height="34" rx="8" fill="${C.active}"/>`);
  s.push(t(kx + 49, ky + 56, 'KOS', { size: 10, weight: 600, anchor: 'middle' }));
  s.push(t(kx + 32, ky + 104, 'Masuk', { size: 24, weight: 600 }));
  s.push(t(kx + 32, ky + 126, 'Sistem Informasi Operasional Kos-Kosan', { size: 12, fill: C.muted }));

  s.push(medan(kx + 32, ky + 166, 316, 'Email', 'nama@kos.test'));
  s.push(medan(kx + 32, ky + 246, 316, 'Kata sandi', '••••••••'));

  s.push(kotak(kx + 32, ky + 326, 316, 36, { fill: C.primary, stroke: C.primary, r: 6 }));
  s.push(t(kx + 190, ky + 349, 'Masuk', { size: 13, weight: 500, fill: '#ffffff', anchor: 'middle' }));
  s.push(t(kx + 190, ky + 390, 'Lupa kata sandi?', { size: 13, weight: 500, anchor: 'middle' }));

  s.push(garis(kx + 32, ky + 414, kx + 348, ky + 414, C.rule));
  s.push(t(kx + 190, ky + 440, 'Demo: pemilik@kos.test · penghuni@kos.test · password', { size: 11, fill: C.muted, anchor: 'middle' }));

  return s.join('');
}

function layarKamar() {
  const x = 272;
  const s = [sidebar('Kamar'), bilahAtas()];
  s.push(kepala(x, 116, 'Kamar', '12 kamar · 9 terisi · 2 kosong · 1 diperbaiki', tombol(1252, 92, 'Tambah kamar', { utama: true })));

  // Panduan penyiapan: tiga langkah, seperti di aplikasi.
  s.push(kotak(x, 152, 1100, 92, { r: 8 }));
  s.push(t(x + 20, 180, 'Menyiapkan kos', { size: 16, weight: 600 }));
  const langkah = [
    ['1', 'Isi kebijakan kos', 'sudah'],
    ['2', 'Tambahkan rekening', 'sudah'],
    ['3', 'Tambah kamar pertama', 'sekarang'],
  ];
  langkah.forEach(([n, isi, ket], i) => {
    const lx = x + 20 + i * 360;
    s.push(`<circle cx="${lx + 9}" cy="${214}" r="9" fill="${ket === 'sudah' ? C.okBg : C.active}"/>`);
    s.push(t(lx + 9, 218, n, { size: 11, weight: 600, anchor: 'middle', fill: ket === 'sudah' ? C.ok : C.ink }));
    s.push(t(lx + 26, 219, isi, { size: 13 }));
    s.push(t(lx + 230, 219, ket === 'sudah' ? 'selesai' : 'buka', { size: 12, fill: C.muted }));
  });

  const kolom = [
    { label: 'Nomor', w: 70 },
    { label: 'Tipe', w: 100 },
    { label: 'Fasilitas', w: 150 },
    { label: 'Status', w: 130, badge: true },
    { label: 'Penghuni', w: 150 },
    { label: 'Tarif terakhir', w: 140, kanan: true, mono: true },
    { label: 'Aksi', w: 320, kanan: true },
  ];
  const baris = [
    ['A1', 'Standar', 'AC, kasur', 'terisi', 'Siti Rahayu', 'Rp 1.500.000', ['Atur tarif', 'Fasilitas', 'Laporkan rusak']],
    ['A2', 'Standar', 'AC', 'terisi', 'Budi Santoso', 'Rp 1.500.000', ['Atur tarif', 'Fasilitas', 'Laporkan rusak']],
    ['A3', 'Standar', 'AC, kasur', 'perbaikan', '—', 'Rp 1.500.000', ['Atur tarif', 'Fasilitas', 'Selesai diperbaiki']],
    ['B1', 'Deluxe', 'AC, kamar mandi', 'kosong', '—', 'Rp 1.900.000', ['Atur tarif', 'Fasilitas', 'Laporkan rusak']],
    ['B2', 'Deluxe', 'AC, kamar mandi', 'dipesan', 'Rina Wati', 'Rp 1.900.000', ['Atur tarif', 'Fasilitas', 'Laporkan rusak']],
  ];
  s.push(kotak(x, 264, 1100, 8 + baris.length * 44 + 12, { r: 8 }));
  s.push(tabel(x + 16, 288, 1068, kolom, baris));

  return s.join('');
}

function layarKebijakan() {
  const x = 272;
  const s = [sidebar('Kebijakan'), bilahAtas()];
  s.push(
    kepala(
      x,
      116,
      'Kebijakan kos',
      'Cara kos ini menagih: satuan sewa dan jatuh tempo. Wajib ditetapkan sebelum tagihan bisa terbit.',
    ),
  );

  s.push(kotak(x, 152, 1100, 74, { r: 8 }));
  s.push(t(x + 20, 180, 'Berlaku untuk', { size: 13, weight: 600 }));
  s.push(t(x + 20, 200, 'Tanpa pilihan kamar, nilai di bawah menjadi default seluruh kos.', { size: 12, fill: C.muted }));
  s.push(kotak(x + 800, 168, 280, 36, { r: 6, stroke: C.control }));
  s.push(t(x + 812, 191, 'Semua kamar (default kos)', { size: 13 }));
  s.push(t(x + 1060, 191, '▾', { size: 12, fill: C.muted, anchor: 'middle' }));

  // `dipilih` harus cocok dengan "Nilai berlaku" di kartu yang sama: radio yang
  // menyala di pilihan lain membuat gambar bertentangan dengan dirinya sendiri.
  const kartu = [
    ['Satuan sewa', 'Bulanan', ['Bulanan', 'Harian'], 0],
    ['Jatuh tempo', 'Tanggal 5', ['Tanggal 1', 'Tanggal 5', 'Tanggal 10'], 1],
    ['Denda', '1% per hari, plafon 50%', ['Tanpa denda', '1% per hari', 'Nominal tetap'], 1],
  ];
  kartu.forEach(([judul, nilai, pilihan, dipilih], i) => {
    const kx = x + i * 372;
    s.push(kotak(kx, 242, 356, 286, { r: 8 }));
    s.push(t(kx + 20, 272, judul, { size: 16, weight: 600 }));
    s.push(t(kx + 20, 296, 'Nilai berlaku', { size: 12, fill: C.muted }));
    s.push(t(kx + 20, 320, nilai, { size: 15, weight: 500 }));
    s.push(t(kx + 20, 356, 'Pilihan', { size: 12, fill: C.muted }));
    pilihan.forEach((p, j) => {
      const py = 374 + j * 26;
      s.push(`<circle cx="${kx + 26}" cy="${py - 4}" r="6" fill="${j === dipilih ? C.primary : 'none'}" stroke="${j === dipilih ? C.primary : C.control}"/>`);
      s.push(t(kx + 40, py, p, { size: 13 }));
    });
    s.push(t(kx + 20, 470, 'Berlaku dari 1 Nov 2026', { size: 12, fill: C.muted }));
    s.push(tombol(kx + 20, 484, 'Simpan', { w: 88 }));
  });

  s.push(kotak(x, 546, 1100, 232, { r: 8 }));
  s.push(t(x + 20, 576, 'Riwayat kebijakan', { size: 16, weight: 600 }));
  s.push(
    t(x + 20, 598, 'Baris lama tidak pernah diubah. Menetapkan nilai baru menambah baris.', {
      size: 12,
      fill: C.muted,
    }),
  );
  s.push(
    tabel(
      x + 16,
      634,
      1068,
      [
        { label: 'Berlaku dari', w: 160 },
        { label: 'Jenis', w: 200 },
        { label: 'Nilai', w: 420 },
        { label: 'Untuk', w: 260 },
      ],
      [
        ['1 Nov 2026', 'Satuan sewa', 'Bulanan', 'Semua kamar'],
        ['1 Nov 2026', 'Jatuh tempo', 'Tanggal 5', 'Semua kamar'],
        ['1 Nov 2026', 'Denda', '1% per hari, plafon 50%', 'Semua kamar'],
      ],
    ),
  );

  return s.join('');
}

function layarTagihan() {
  const x = 244 + 28;
  // Lebar isi = 1440 - sidebar 244 - padding 56 = 1140. Angka 1240 di
  // `style.css` adalah BATAS atas (`.halaman-lebar`), bukan lebar yang dicapai
  // pada viewport 1440 - dan mockup yang memakai 1240 meluber keluar frame.
  const lebar = 1140;
  const kanan = x + lebar;
  const s = [sidebar('Tagihan'), bilahAtas()];
  s.push(
    kepala(
      x,
      116,
      'Tagihan',
      '14 tagihan · pokok Rp 21.000.000 · denda Rp 350.000 · belum masuk Rp 4.500.000',
      tombol(kanan - 176, 92, 'Terbitkan tagihan', { utama: true, w: 176 }) +
        tombol(kanan - 176 - 14 - 128, 92, 'Terbitkan semua', { w: 128 }),
    ),
  );

  // Lebar dipilih supaya "Rp 1.900.000" di kolom Sisa tidak menempel ke "Rp 0"
  // di kolom Dibayar. `kanan` + mono berarti kedua sel tumbuh ke kiri.
  const kolom = [
    { label: 'Periode', w: 95 },
    { label: 'Penghuni', w: 130 },
    { label: 'Kamar', w: 58 },
    { label: 'Pokok', w: 108, kanan: true, mono: true },
    { label: 'Denda', w: 96, kanan: true, mono: true },
    { label: 'Total', w: 108, kanan: true, mono: true },
    { label: 'Dibayar', w: 104, kanan: true, mono: true },
    { label: 'Sisa', w: 112, kanan: true, mono: true },
    { label: 'Jatuh tempo', w: 104 },
    { label: 'Status', w: 112, badge: true },
    { label: '', w: 76, kanan: true },
  ];
  const baris = [
    ['Nov 2026', 'Siti Rahayu', 'A1', 'Rp 1.500.000', '—', 'Rp 1.500.000', '—', 'Rp 0', '5 Nov 2026', 'lunas', 'Rincian'],
    ['Nov 2026', 'Budi Santoso', 'A2', 'Rp 1.500.000', 'Rp 150.000', 'Rp 1.650.000', 'Rp 800.000', 'Rp 850.000', '5 Nov 2026', 'sebagian', 'Rincian'],
    ['Nov 2026', 'Rina Wati', 'B2', 'Rp 1.900.000', '—', 'Rp 1.900.000', '—', 'Rp 1.900.000', '10 Nov 2026', 'belum bayar', 'Rincian'],
    ['Okt 2026', 'Agus Salim', 'C1', 'Rp 1.400.000', 'Rp 200.000', 'Rp 1.600.000', '—', 'Rp 1.600.000', '5 Okt 2026', 'terlambat', 'Rincian'],
    ['Okt 2026', 'Dewi Lestari', 'C2', 'Rp 1.400.000', '—', 'Rp 1.400.000', 'Rp 1.400.000', 'Rp 0', '5 Okt 2026', 'lunas', 'Rincian'],
  ];
  s.push(kotak(x, 152, lebar, 8 + baris.length * 44 + 12, { r: 8 }));
  s.push(tabel(x + 16, 176, lebar - 32, kolom, baris));

  return s.join('');
}

function layarPortal() {
  const x = 120;
  const s = [kotak(0, 0, W, H, { fill: C.bg, stroke: null, r: 0 })];
  s.push(kotak(0, 0, W, 56, { fill: C.surface, stroke: null, r: 0 }));
  s.push(garis(0, 55.5, W, 55.5, C.border));
  s.push(`<rect x="120" y="16" width="24" height="24" rx="6" fill="${C.active}"/>`);
  s.push(t(132, 33, 'K', { size: 11, weight: 600, anchor: 'middle' }));
  s.push(t(154, 33, 'Kos-Kosan', { size: 14, weight: 600 }));
  s.push(t(1224, 33, 'Siti Rahayu', { size: 13, fill: C.muted, anchor: 'end' }));
  s.push(t(1320, 33, 'Keluar', { size: 13, weight: 500, anchor: 'end' }));

  s.push(t(x, 116, 'Tagihan saya', { size: 24, weight: 600 }));
  s.push(t(x, 140, 'Rumah kos Anda dan apa yang harus dibayar.', { size: 13, fill: C.muted }));

  s.push(kotak(x, 164, 1200, 96, { r: 8 }));
  s.push(t(x + 24, 196, 'Belum dibayar', { size: 12, fill: C.muted }));
  s.push(t(x + 24, 232, 'Rp 4.500.000', { size: 28, weight: 600, mono: true }));
  s.push(t(x + 320, 196, 'Berikutnya jatuh tempo', { size: 12, fill: C.muted }));
  s.push(t(x + 320, 224, '5 Nov 2026', { size: 15, weight: 500 }));
  s.push(tombol(1086, 194, 'Bayar sekarang', { utama: true, w: 168 }));

  const kolom = [
    { label: 'Periode', w: 120 },
    { label: 'Pokok', w: 150, kanan: true, mono: true },
    { label: 'Denda', w: 110, kanan: true, mono: true },
    { label: 'Total', w: 150, kanan: true, mono: true },
    { label: 'Dibayar', w: 140, kanan: true, mono: true },
    { label: 'Sisa', w: 140, kanan: true, mono: true },
    { label: 'Jatuh tempo', w: 130 },
    { label: 'Status', w: 120, badge: true },
    { label: '', w: 60 },
  ];
  const baris = [
    ['Nov 2026', 'Rp 1.500.000', '—', 'Rp 1.500.000', 'Rp 0', 'Rp 1.500.000', '5 Nov 2026', 'belum bayar', ''],
    ['Okt 2026', 'Rp 1.500.000', 'Rp 150.000', 'Rp 1.650.000', 'Rp 800.000', 'Rp 850.000', '5 Okt 2026', 'sebagian', ''],
    ['Sep 2026', 'Rp 1.500.000', '—', 'Rp 1.500.000', 'Rp 1.500.000', 'Rp 0', '5 Sep 2026', 'lunas', ''],
  ];
  s.push(kotak(x, 280, 1200, 8 + baris.length * 44 + 14, { r: 8 }));
  s.push(tabel(x + 16, 304, 1168, kolom, baris));

  s.push(kotak(x, 452, 588, 236, { r: 8 }));
  s.push(t(x + 20, 482, 'Cara membayar', { size: 16, weight: 600 }));
  s.push(t(x + 20, 508, 'Transfer bank', { size: 13, weight: 500 }));
  s.push(t(x + 20, 528, 'BCA 1234567890 · a.n. Kos-Kosan', { size: 13, fill: C.muted }));
  s.push(t(x + 20, 560, 'QRIS', { size: 13, weight: 500 }));
  s.push(t(x + 20, 580, 'Pindai kode pada aplikasi bank Anda.', { size: 13, fill: C.muted }));
  s.push(kotak(x + 20, 604, 120, 60, { r: 6, stroke: C.control }));
  s.push(t(x + 80, 639, 'QRIS', { size: 11, fill: C.muted, anchor: 'middle' }));

  s.push(kotak(x + 612, 452, 588, 236, { r: 8 }));
  s.push(t(x + 632, 482, 'Aturan kos', { size: 16, weight: 600 }));
  ['Jam malam 22.00.', 'Tamu hanya sampai pukul 21.00.', 'Denda keterlambatan 1% per hari, plafon 50%.'].forEach((a, i) => {
    s.push(t(x + 632, 512 + i * 26, `•  ${a}`, { size: 13 }));
  });

  return s.join('');
}

function layarKerusakan() {
  const x = 272;
  const s = [sidebar('Kamar'), bilahAtas()];
  s.push(kepala(x, 116, 'Kamar', '12 kamar · 9 terisi · 2 kosong · 1 diperbaiki'));

  s.push(kotak(x, 152, 1100, 372, { r: 8 }));
  s.push(t(x + 24, 190, 'Laporkan kerusakan kamar A3', { size: 16, weight: 600 }));
  s.push(t(x + 24, 214, 'Kamar akan ditandai diperbaiki sampai laporannya ditutup. Sewa tetap', { size: 13, fill: C.muted }));
  s.push(t(x + 24, 232, 'berjalan; biaya perbaikan tidak masuk tagihan penghuni.', { size: 13, fill: C.muted }));
  s.push(tombol(x + 990, 176, 'Tutup', { w: 86 }));

  s.push(t(x + 24, 278, 'Apa yang rusak?', { size: 12, weight: 500, fill: C.muted }));
  s.push(kotak(x + 24, 290, 1052, 96, { r: 6, stroke: C.control }));
  s.push(t(x + 36, 314, 'Plafon kamar mandi bocor dan merembes ke lemari', { size: 13, fill: C.muted }));
  s.push(t(x + 36, 334, 'Sejak dua hari lalu, makin parah saat hujan.', { size: 13 }));
  s.push(`<rect x="${x + 36}" y="340" width="2" height="14" fill="${C.ink}"/>`);

  s.push(tombol(x + 24, 414, 'Laporkan kerusakan', { utama: true, w: 190 }));
  s.push(tombol(x + 228, 414, 'Batal', { w: 76 }));
  s.push(t(x + 24, 494, 'Laporan terbuka: satu per kamar. Menutup laporan mengembalikan', { size: 12, fill: C.muted }));
  s.push(t(x + 24, 512, 'status kamar menurut kontraknya, bukan ke nilai sebelumnya.', { size: 12, fill: C.muted }));

  return s.join('');
}

/* ------------------------------------------------------------------ tulis */

const LAYAR = {
  masuk: layarMasuk,
  kamar: layarKamar,
  kebijakan: layarKebijakan,
  tagihan: layarTagihan,
  'portal-penghuni': layarPortal,
  'laporan-kerusakan': layarKerusakan,
};

const gaya = `<defs><style>${fontFace('Geist', 'geist.woff2', '400 600')}${fontFace('Geist Mono', 'geist-mono.woff2', '400')}</style></defs>`;

let jumlah = 0;
for (const [nama, gambar] of Object.entries(LAYAR)) {
  const svg = [
    `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">`,
    gaya,
    `<rect width="${W}" height="${H}" fill="${C.bg}"/>`,
    gambar(),
    '</svg>',
  ].join('\n');

  fs.writeFileSync(path.join(DI, `${nama}.svg`), svg, 'utf8');
  jumlah += 1;
  console.log(`  ${nama}.svg`);
}

console.log(`\n${jumlah} layar ditulis ke docs/desain/`);
