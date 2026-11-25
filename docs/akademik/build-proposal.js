'use strict';

/**
 * Membangun PROPOSAL PERANCANGAN SISTEM (Huni) sebagai DOCX.
 *
 * Kerangkanya mengikuti `DRAFT PROPOSAL RPL.docx`: BAB I Pendahuluan,
 * BAB II Landasan Teori, BAB III Perancangan dan Pemodelan Sistem,
 * BAB IV Desain Antarmuka, BAB V Kesimpulan.
 *
 * Isi diambil dari repo, bukan dikarang:
 *   - 14 use case + deskripsinya  : docs/akademik/usecase-data.js
 *   - aturan bisnis R-01..R-09    : SPEC.md
 *   - teknologi & arsitektur      : docs/produk/3_ARCHITECTURE.md
 *   - gambar                      : docs/akademik/*.png, docs/uji/*.png
 *
 * Jalankan (butuh paket `docx`, tidak di-commit ke repo):
 *   npm install docx@9 --prefix %TEMP%\docxbuild
 *   set NODE_PATH=%TEMP%\docxbuild\node_modules
 *   node docs/akademik/build-proposal.js
 *
 * Hasil: dokumen/11-proposal/Proposal_RPL_Huni.docx
 */

const fs = require('fs');
const path = require('path');
const {
  Document, Packer, Paragraph, TextRun, HeadingLevel, AlignmentType,
  ImageRun, Table, TableRow, TableCell, WidthType, BorderStyle,
  PageBreak, Footer, PageNumber, PageOrientation,
} = require('docx');

const AKAR = path.join(__dirname, '..', '..');
const KELUAR = path.join(AKAR, 'dokumen', '11-proposal', 'Proposal_RPL_Huni.docx');

const D = require('./usecase-data');

/* --------------------------------------------------------------- konstanta */

const JUDUL = 'PERANCANGAN SISTEM INFORMASI OPERASIONAL KOS BERBASIS WEB MENGGUNAKAN LARAVEL DAN VUE.JS';

const NAMA_SISTEM = 'Huni';
const FONT = 'Times New Roman';

/* ---------------------------------------------------------------- utilitas */

function ukuranPng(jalur) {
  const b = fs.readFileSync(jalur);
  if (b[0] !== 0x89 || b.toString('ascii', 1, 4) !== 'PNG') throw new Error('bukan PNG: ' + jalur);
  return { lebar: b.readUInt32BE(16), tinggi: b.readUInt32BE(20) };
}

function p(teks, o = {}) {
  return new Paragraph({
    alignment: o.rata || AlignmentType.JUSTIFIED,
    spacing: { after: o.setelah ?? 140, line: o.spasi ?? 276 },
    indent: o.indentasi ? { firstLine: 567 } : undefined,
    children: [
      new TextRun({
        text: teks,
        bold: o.tebal,
        italics: o.miring,
        size: o.ukuran ?? 24,
        font: o.font || FONT,
      }),
    ],
  });
}

const h1 = (teks) =>
  new Paragraph({
    heading: HeadingLevel.HEADING_1,
    alignment: AlignmentType.CENTER,
    spacing: { before: 120, after: 240 },
    children: [new TextRun({ text: teks, bold: true, size: 28, font: FONT })],
  });

const h2 = (teks) =>
  new Paragraph({
    heading: HeadingLevel.HEADING_2,
    spacing: { before: 220, after: 120 },
    children: [new TextRun({ text: teks, bold: true, size: 26, font: FONT })],
  });

const h3 = (teks) =>
  new Paragraph({
    heading: HeadingLevel.HEADING_3,
    spacing: { before: 180, after: 100 },
    children: [new TextRun({ text: teks, bold: true, size: 24, font: FONT })],
  });

const butir = (teks) =>
  new Paragraph({
    alignment: AlignmentType.JUSTIFIED,
    spacing: { after: 70, line: 264 },
    indent: { left: 567, hanging: 284 },
    children: [new TextRun({ text: '\u2022  ' + teks, size: 24, font: FONT })],
  });

const nomor = (teks) =>
  new Paragraph({
    alignment: AlignmentType.JUSTIFIED,
    spacing: { after: 70, line: 264 },
    indent: { left: 567, hanging: 284 },
    children: [new TextRun({ text: teks, size: 24, font: FONT })],
  });

const kosong = (n = 1) => Array.from({ length: n }, () => new Paragraph({ children: [] }));

const tengah = (teks, o = {}) =>
  new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { after: o.setelah ?? 120 },
    children: [new TextRun({ text: teks, bold: o.tebal, italics: o.miring, size: o.ukuran ?? 24, font: FONT })],
  });

/** Tabel dengan kepala berwarna dan lebar kolom persen. */
function tabel(kepala, isi, lebar) {
  const b = { style: BorderStyle.SINGLE, size: 4, color: '808080' };
  const bingkai = { top: b, bottom: b, left: b, right: b };

  const sel = (teks, i, tebal = false, isiKepala = false) =>
    new TableCell({
      borders: bingkai,
      width: { size: lebar[i], type: WidthType.PERCENTAGE },
      shading: isiKepala ? { fill: 'E8EDF3' } : undefined,
      margins: { top: 60, bottom: 60, left: 90, right: 90 },
      children: [
        new Paragraph({
          children: [new TextRun({ text: String(teks), bold: tebal, size: 21, font: FONT })],
        }),
      ],
    });

  return new Table({
    width: { size: 100, type: WidthType.PERCENTAGE },
    rows: [
      new TableRow({ tableHeader: true, children: kepala.map((t, i) => sel(t, i, true, true)) }),
      ...isi.map((r) => new TableRow({ children: r.map((t, i) => sel(t, i)) })),
    ],
  });
}

/** Gambar + keterangan bernomor, diskalakan ke lebar teks. */
function gambar(nama, folder, keterangan, lebarCm = 14.5) {
  const jalur = path.join(AKAR, folder, nama);
  if (!fs.existsSync(jalur)) throw new Error('gambar tidak ada: ' + jalur);

  const { lebar, tinggi } = ukuranPng(jalur);
  const lebarPx = Math.round((lebarCm / 2.54) * 96);
  const tinggiPx = Math.round((tinggi / lebar) * lebarPx);

  return [
    new Paragraph({
      alignment: AlignmentType.CENTER,
      spacing: { before: 120, after: 60 },
      children: [new ImageRun({ data: fs.readFileSync(jalur), transformation: { width: lebarPx, height: tinggiPx } })],
    }),
    new Paragraph({
      alignment: AlignmentType.CENTER,
      spacing: { after: 200 },
      children: [new TextRun({ text: keterangan, italics: true, size: 20, font: FONT })],
    }),
  ];
}

/* ------------------------------------------------------------ daftar isi */

const ISI = [
  ['BAB I PENDAHULUAN', '5'],
  ['1.1 Latar Belakang', '5'],
  ['1.2 Rumusan Masalah', '5'],
  ['1.3 Tujuan Pengembangan Sistem', '6'],
  ['1.4 Manfaat Pengembangan Sistem', '6'],
  ['1.5 Ruang Lingkup Sistem', '6'],
  ['BAB II LANDASAN TEORI', '8'],
  ['2.1 Konsep Perangkat Lunak', '8'],
  ['2.2 Rekayasa Perangkat Lunak', '8'],
  ['2.3 Model Pengembangan Perangkat Lunak', '8'],
  ['BAB III PERANCANGAN DAN PEMODELAN SISTEM', '10'],
  ['3.1 Pemilihan Model Pengembangan Perangkat Lunak', '10'],
  ['3.2 Analisis Kebutuhan Sistem', '10'],
  ['3.3 Identifikasi Pengguna Sistem', '12'],
  ['3.4 Gambaran Umum Sistem yang Diusulkan', '12'],
  ['3.5 Perancangan Proses Sistem Menggunakan UML', '13'],
  ['3.6 Teknologi yang Digunakan', '24'],
  ['BAB IV DESAIN ANTARMUKA', '25'],
  ['4.1 Desain Antarmuka Aplikasi', '25'],
  ['4.2 Desain Arsitektur Sistem', '28'],
  ['4.3 Kesesuaian Desain dengan Kebutuhan Sistem', '31'],
  ['BAB V KESIMPULAN', '32'],
  ['DAFTAR PUSTAKA', '33'],
];

const DAFTAR_TABEL = [
  ['Tabel III.1', 'Kebutuhan Fungsional Sistem', '11'],
  ['Tabel III.2', 'Kebutuhan Nonfungsional Sistem', '12'],
  ['Tabel III.3', 'Aktor dan Hak Akses', '12'],
  ['Tabel III.4', 'Kebutuhan Fungsional per Aktor', '12'],
  ['Tabel III.5', 'Deskripsi Use Case UC-07 Terbitkan & Kelola Tagihan', '14'],
  ['Tabel III.6', 'Deskripsi Use Case UC-08 Catat Pembayaran Sewa', '14'],
  ['Tabel III.7', 'Teknologi yang Digunakan', '24'],
  ['Tabel IV.1', 'Kesesuaian Kebutuhan Fungsional dengan Rancangan Antarmuka', '31'],
  ['Tabel IV.2', 'Kesesuaian Kebutuhan Nonfungsional dengan Bukti Pengujian', '31'],
];

const DAFTAR_GAMBAR = [
  ['Gambar II.1', 'Tahapan Model Waterfall', '9'],
  ['Gambar III.1', 'Use Case Diagram Sistem Informasi Operasional Huni', '15'],
  ['Gambar III.2', 'Activity Diagram Terbit Tagihan', '16'],
  ['Gambar III.3', 'Activity Diagram Catat Pembayaran', '17'],
  ['Gambar III.4', 'Activity Diagram Perhitungan Denda', '18'],
  ['Gambar III.5', 'Sequence Diagram Terbitkan Tagihan (UC-07)', '19'],
  ['Gambar III.6', 'Sequence Diagram Catat Pembayaran (UC-08)', '20'],
  ['Gambar III.7', 'Class Diagram Lembar A - Akun, Penghuni, Kamar', '21'],
  ['Gambar III.8', 'Class Diagram Lembar B - Kontrak dan Tagihan', '22'],
  ['Gambar III.9', 'Class Diagram Lembar C - Pembayaran dan Operasional', '23'],
  ['Gambar IV.1', 'Halaman Masuk', '25'],
  ['Gambar IV.2', 'Halaman Tagihan', '25'],
  ['Gambar IV.3', 'Panel Terbit Tagihan Massal', '26'],
  ['Gambar IV.4', 'Rincian Tagihan dan Perhitungan Denda', '26'],
  ['Gambar IV.5', 'Halaman Tunggakan per Kelompok Umur', '27'],
  ['Gambar IV.6', 'Halaman Rekap Pendapatan', '27'],
  ['Gambar IV.7', 'Portal Penghuni', '28'],
  ['Gambar IV.8', 'Halaman Verifikasi Pembayaran', '28'],
  ['Gambar IV.9', 'Arsitektur Sistem', '30'],
];

function daftar(judul2, baris) {
  // Dua bentuk baris:
  //   [teks, halaman]              -> daftar isi
  //   [nomor, keterangan, halaman] -> daftar tabel / gambar
  return [
    h1(judul2),
    ...baris.map((r) => {
      const [kiri, tengah, kanan] = r.length === 3 ? r : [r[0], null, r[1]];
      const teks = tengah ? kiri + '. ' + tengah : kiri;
      return new Paragraph({
        spacing: { after: 60 },
        tabStops: [{ type: 'right', position: 9000, leader: 'dot' }],
        children: [
          new TextRun({ text: teks, size: 22, font: FONT }),
          new TextRun({ text: '\t' + (kanan || ''), size: 22, font: FONT }),
        ],
      });
    }),
  ];
}


const DAFTAR_PUSTAKA = [
  p('Sommerville, I. (2016). Software Engineering (10th ed.). Pearson Education.', { rata: AlignmentType.LEFT, setelah: 100 }),
  p('Pressman, R. S., & Maxim, B. R. (2020). Software Engineering: A Practitioner\u2019s Approach (9th ed.). McGraw-Hill Education.', { rata: AlignmentType.LEFT, setelah: 100 }),
  p('Fowler, M. (2004). UML Distilled: A Brief Guide to the Standard Object Modeling Language (3rd ed.). Addison-Wesley.', { rata: AlignmentType.LEFT, setelah: 100 }),
  p('Boehm, B. W. (1988). A Spiral Model of Software Development and Enhancement. Computer, 21(5), 61-72.', { rata: AlignmentType.LEFT, setelah: 100 }),
  p('Laravel. (2026). Laravel 12.x Documentation. Diambil dari https://laravel.com/docs', { rata: AlignmentType.LEFT, setelah: 100 }),
  p('Vue.js. (2026). Vue.js Documentation. Diambil dari https://vuejs.org/guide/', { rata: AlignmentType.LEFT, setelah: 100 }),
  p('PostgreSQL Global Development Group. (2026). PostgreSQL 17 Documentation. Diambil dari https://www.postgresql.org/docs/', { rata: AlignmentType.LEFT, setelah: 100 }),
  p('OWASP Foundation. (2025). OWASP Application Security Verification Standard. Diambil dari https://owasp.org/www-project-application-security-verification-standard/', { rata: AlignmentType.LEFT, setelah: 100 }),
];

/* ------------------------------------------------------------------ isi */

const FR = [
  ['FR-01', 'Sistem dapat melakukan autentikasi tiga peran: pemilik, pengelola, dan penghuni'],
  ['FR-02', 'Sistem dapat memulihkan kata sandi lewat surel dan memaksa ganti sandi akun baru'],
  ['FR-03', 'Pemilik dapat mendaftarkan kamar, fasilitas, dan aturan kos'],
  ['FR-04', 'Pemilik dapat mencatat laporan kerusakan dan menandai kamar sedang diperbaiki'],
  ['FR-05', 'Pemilik dapat mendaftarkan penghuni dan membuat kontrak atas satu kamar kosong'],
  ['FR-06', 'Pemilik dapat mengakhiri kontrak dan mengembalikan status kamar'],
  ['FR-07', 'Pemilik dapat menetapkan tarif sewa per kamar beserta riwayatnya'],
  ['FR-08', 'Pemilik dapat menetapkan komponen biaya tambahan per kamar'],
  ['FR-09', 'Pemilik dapat menetapkan kebijakan kos: satuan sewa, jatuh tempo, denda, pola tagihan, tanggal terbit, dan nama kos'],
  ['FR-10', 'Sistem menolak penerbitan tagihan bila kebijakan wajib belum lengkap'],
  ['FR-11', 'Sistem dapat menghitung sewa prorata untuk penghuni yang masuk di tengah bulan'],
  ['FR-12', 'Pemilik dapat menerbitkan tagihan satuan maupun massal untuk banyak kontrak'],
  ['FR-13', 'Pemilik dapat membatalkan tagihan yang salah dan memaafkan denda'],
  ['FR-14', 'Sistem dapat menghitung denda harian dengan masa tenggang dan plafon'],
  ['FR-15', 'Sistem membekukan denda saat pokok tagihan lunas'],
  ['FR-16', 'Pemilik dan pengelola dapat mencatat pembayaran beserta metode dan penerima uang'],
  ['FR-17', 'Sistem menurunkan status tagihan dari jumlah seluruh pembayaran'],
  ['FR-18', 'Sistem menolak pembayaran yang melebihi sisa tagihan'],
  ['FR-19', 'Pengelola dapat menarik kembali pembayaran yang salah lewat baris pembalik'],
  ['FR-20', 'Pemilik dapat memberi wewenang terbatas kepada pengelola'],
  ['FR-21', 'Pengelola dapat mengusulkan perubahan tarif atau kebijakan; pemilik yang memutuskan'],
  ['FR-22', 'Sistem menampilkan rekap pendapatan per metode bayar'],
  ['FR-23', 'Sistem menampilkan tunggakan menurut umur piutang (0-30, 31-60, di atas 60 hari)'],
  ['FR-24', 'Pemilik dapat mengekspor rekap, tunggakan, dan jejak audit ke berkas CSV'],
  ['FR-25', 'Penghuni dapat melihat kontrak, tagihan, denda, aturan, dan rekening tujuan transfer miliknya sendiri'],
  ['FR-26', 'Penghuni dapat membuka tautan bayar daring dan mengirim laporan kerusakan beserta foto'],
  ['FR-27', 'Sistem mencatat setiap perubahan angka ke jejak audit yang tidak dapat diubah'],
  ['FR-28', 'Sistem menyediakan pemeriksaan kesehatan layanan dan basis data'],
];

const NFR = [
  ['NFR-01', 'Halaman daftar tagihan tampil dalam waktu wajar pada 390 tagihan', 'Performance'],
  ['NFR-02', 'Jumlah kueri daftar tidak tumbuh bersama jumlah baris (tanpa N+1)', 'Performance'],
  ['NFR-03', 'Antarmuka dapat dipakai pada peramban desktop dan layar sempit', 'Usability'],
  ['NFR-04', 'Kata sandi disimpan sebagai hash dan tidak pernah dicatat di log', 'Security'],
  ['NFR-05', 'Penghuni hanya dapat melihat data miliknya sendiri; akses ke data orang lain menjawab 404', 'Security'],
  ['NFR-06', 'Uang tidak dapat diubah atau dihapus: tabel pembayaran append-only di tingkat basis data', 'Integrity'],
  ['NFR-07', 'Sesi dan pembatas percobaan masuk disimpan di basis data sehingga bertahan saat layanan restart', 'Reliability'],
  ['NFR-08', 'Sistem menyediakan pencadangan basis data yang dapat dipulihkan', 'Reliability'],
  ['NFR-09', 'Tampilan menggunakan satu bahasa dan satu istilah untuk satu keadaan (mis. "belum bayar")', 'Usability'],
  ['NFR-10', 'Kesalahan validasi dijelaskan di layar dan diumumkan pembaca layar', 'Accessibility'],
  ['NFR-11', 'Respons API tidak disimpan di cache peramban (no-store)', 'Security'],
  ['NFR-12', 'Sistem dapat dijalankan ulang dari nol dengan satu perintah (Docker Compose)', 'Maintainability'],
];

const AKTOR = D.AKTOR.map((a) => [a.nama, a.peran || a.deskripsi || '']);
const UC = D.USE_CASE;

const TEKNOLOGI = [
  ['Bahasa pemrograman', 'PHP 8.3 (sisi server), JavaScript (sisi klien)'],
  ['Kerangka kerja API', 'Laravel 12 dengan Sanctum untuk token API'],
  ['Kerangka kerja antarmuka', 'Vue 3 dengan Vite 6'],
  ['Basis data', 'PostgreSQL 16 (demo), PostgreSQL 17 di Supabase (produksi)'],
  ['Pengujian', 'PHPUnit (927 tes, 4.177 asersi), Vitest (19 tes), 34 skrip uji peramban'],
  ['Penjalan lokal', 'Docker Compose: nginx, PHP-FPM, PostgreSQL, Mailpit'],
  ['Penyebaran', 'Vercel Container Runtime + Supabase'],
  ['Kendali versi', 'Git, GitHub, GitHub Actions'],
];

/* ------------------------------------------------------------------ dokumen */

async function utama() {
  const marginPotret = { top: 1134, right: 1134, bottom: 1134, left: 1418 };
  const marginLanskap = { top: 1134, right: 1134, bottom: 1134, left: 1134 };

  const kaki = new Footer({
    children: [
      new Paragraph({
        alignment: AlignmentType.CENTER,
        children: [new TextRun({ children: [PageNumber.CURRENT], size: 20, font: FONT })],
      }),
    ],
  });

  /* ------------------------------------------------------- bagian portrait */

  const depan = [
    ...kosong(3),
    tengah('PROPOSAL PERANCANGAN SISTEM', { tebal: true, ukuran: 32, setelah: 300 }),
    tengah(JUDUL, { tebal: true, ukuran: 30, setelah: 600 }),
    ...kosong(4),
    tengah('Disusun untuk Memenuhi Tugas Mata Kuliah Rekayasa Perangkat Lunak', { setelah: 400 }),
    tengah('Disusun oleh Kelompok [Nomor Kelompok]', { tebal: true, setelah: 200 }),
    ...[1, 2, 3, 4].map((n) => tengah(`[Nama Mahasiswa ${n}]  ([NIM])`, { setelah: 60 })),
    ...kosong(4),
    tengah('PROGRAM STUDI [NAMA PROGRAM STUDI]', { tebal: true }),
    tengah('FAKULTAS TEKNIK DAN INFORMATIKA', { tebal: true }),
    tengah('UNIVERSITAS BINA SARANA INFORMATIKA', { tebal: true }),
    tengah('2026', { tebal: true }),
    new Paragraph({ children: [new PageBreak()] }),

    ...daftar('DAFTAR ISI', ISI),
    new Paragraph({ children: [new PageBreak()] }),
    ...daftar('DAFTAR TABEL', DAFTAR_TABEL),
    new Paragraph({ children: [new PageBreak()] }),
    ...daftar('DAFTAR GAMBAR', DAFTAR_GAMBAR),
    new Paragraph({ children: [new PageBreak()] }),

    /* ------------------------------------------------------------- BAB I */
    h1('BAB I PENDAHULUAN'),

    h2('1.1 Latar Belakang'),
    p(
      'Usaha kos-kosan adalah salah satu bentuk usaha jasa yang paling dekat dengan kehidupan mahasiswa dan pekerja di kota besar. Satu rumah kos dengan tiga puluh kamar dapat melayani puluhan penghuni sekaligus, dengan siklus keuangan yang berulang setiap bulan: menerbitkan tagihan sewa, menagih penghuni yang terlambat, mencatat pembayaran sebagian, dan menyusun laporan pemasukan. Meskipun sederhana bila dilihat per penghuni, pekerjaan itu menjadi rumit ketika jumlah penghuni bertambah, karena setiap tagihan memiliki tanggal jatuh tempo, tarif, dan riwayat pembayaran sendiri.',
      { indentasi: true }
    ),
    p(
      'Pada praktiknya, banyak pengelola kos masih menjalankan proses ini secara manual: mencatat pembayaran di buku tulis, mengirim pengingat lewat aplikasi pesan, dan menghitung denda dengan kalkulator. Cara itu bertahan bukan karena paling baik, melainkan karena paling murah. Namun ia menyimpan empat masalah yang baru terasa ketika pemilik mulai bertanya. Pertama, catatan uang dan catatan tagihan berada di dua tempat berbeda, sehingga angka pemasukan di buku tidak selalu sama dengan jumlah uang di rekening. Kedua, denda dihitung ulang dengan cara yang berbeda oleh orang yang berbeda, dan hasilnya sulit dipertanggungjawabkan ketika penghuni mempertanyakannya. Ketiga, tidak ada riwayat ketika pemilik ingin menelusuri siapa yang mengubah tarif, kapan, dan dari angka berapa. Keempat, laporan bulanan harus disusun ulang dari awal setiap kali dibutuhkan.',
      { indentasi: true }
    ),
    p(
      'Penghuni juga merasakan akibatnya. Mereka sering tidak tahu sisa tagihannya, tidak menerima rincian denda, dan harus menanyakan nomor rekening tujuan transfer setiap bulan. Kesalahan yang paling mahal adalah kesalahan yang tidak terlihat: pembayaran yang sudah masuk tetapi belum dicatat, atau tagihan yang sudah dibayar tetapi masih ditagih. Keadaan seperti itu tidak dapat diselesaikan dengan menambah kerajinan, karena masalahnya bukan pada orang yang mencatat, melainkan pada tidak adanya satu catatan yang menjadi acuan bersama.',
      { indentasi: true }
    ),
    p(
      `Aplikasi ${NAMA_SISTEM} dikembangkan untuk menutup celah tersebut. ${NAMA_SISTEM} adalah sistem informasi operasional kos berbasis web yang mengelola kamar, penghuni, kontrak sewa, tarif dan kebijakan, tagihan bulanan, pembayaran, sampai laporan pendapatan dan tunggakan dalam satu basis data. Karena berbasis web, sistem dapat dibuka dari laptop pemilik maupun dari telepon penghuni tanpa pemasangan aplikasi tambahan. Antarmukanya dipisahkan menjadi dua: layar pengelola untuk pekerjaan administratif, dan portal penghuni yang hanya menampilkan data milik penghuni yang sedang masuk.`,
      { indentasi: true }
    ),
    p(
      'Satu keputusan yang membedakan sistem ini dari aplikasi pencatatan biasa adalah perlakuan terhadap uang. Sistem tidak menyimpan "status tagihan" sebagai angka yang ditambah dan dikurangi. Status selalu diturunkan dari jumlah seluruh pembayaran yang tercatat, dan tabel pembayaran bersifat tambah-saja: baris lama tidak boleh diubah atau dihapus, kesalahan ditarik dengan baris pembalik yang menunjuk baris aslinya. Aturan ini membuat pertanyaan "dulu pernah dicatat Rp 500.000, lalu ke mana?" selalu dapat dijawab, dan membuat angka pemasukan tidak dapat "dirapikan" dengan menyunting riwayat.',
      { indentasi: true }
    ),

    h2('1.2 Rumusan Masalah'),
    p('Berdasarkan latar belakang di atas, rumusan masalah dalam perancangan ini adalah:', { indentasi: true }),
    nomor('1.  Bagaimana merancang satu catatan yang menjadi acuan bersama antara tagihan, pembayaran, dan laporan keuangan kos?'),
    nomor('2.  Bagaimana merancang perhitungan denda yang konsisten, dapat dipertanggungjawabkan, dan berhenti pada batas yang disepakati?'),
    nomor('3.  Bagaimana membatasi wewenang pengelola tanpa menghilangkan kemampuannya menjalankan pekerjaan harian?'),
    nomor('4.  Bagaimana menyajikan informasi tagihan kepada penghuni tanpa membuka data penghuni lain?'),

    h2('1.3 Tujuan Pengembangan Sistem'),
    p('Tujuan yang ingin dicapai melalui pengembangan sistem ini adalah:', { indentasi: true }),
    nomor('1.  Menyediakan satu basis data operasional kos yang memuat kamar, penghuni, kontrak, tagihan, dan pembayaran.'),
    nomor('2.  Mengotomatiskan penerbitan tagihan bulanan, termasuk perhitungan sewa prorata dan denda.'),
    nomor('3.  Menyediakan mekanisme pencatatan pembayaran yang dapat diaudit dan tidak dapat diubah diam-diam.'),
    nomor('4.  Menyediakan laporan pendapatan, tunggakan, dan jejak audit yang dapat dipakai mengambil keputusan.'),
    nomor('5.  Menyediakan portal penghuni yang membatasi akses pada data milik penghuni itu sendiri.'),

    h2('1.4 Manfaat Pengembangan Sistem'),
    p(
      'Manfaat bagi pemilik kos adalah laporan pemasukan dan tunggakan yang dapat dipercaya, karena angka pada laporan dan angka pada rekening berasal dari catatan yang sama. Manfaat bagi pengelola adalah pekerjaan harian yang lebih ringkas: mencatat pembayaran, menerbitkan tagihan massal, dan menjawab pertanyaan penghuni tanpa membuka buku catatan.',
      { indentasi: true }
    ),
    p(
      'Manfaat bagi penghuni adalah kejelasan: rincian tagihan, sisa yang harus dibayar, denda yang berjalan, dan nomor rekening tujuan transfer dapat dilihat sendiri tanpa harus bertanya. Manfaat bagi penulis adalah pengalaman menerapkan rekayasa perangkat lunak pada masalah nyata, mulai dari analisis kebutuhan, pemodelan, implementasi, sampai pengujian.',
      { indentasi: true }
    ),

    h2('1.5 Ruang Lingkup Sistem'),
    p('Ruang lingkup sistem dibatasi sebagai berikut:', { indentasi: true }),
    p('a.  Fitur yang termasuk dalam lingkup:', { tebal: true, setelah: 80 }),
    butir('Autentikasi tiga peran (pemilik, pengelola, penghuni) dengan pemulihan kata sandi lewat surel.'),
    butir('Pengelolaan kamar, fasilitas, aturan kos, dan laporan kerusakan.'),
    butir('Pengelolaan penghuni, kontrak sewa, tarif, dan komponen biaya.'),
    butir('Kebijakan kos: satuan sewa, jatuh tempo, denda, pola tagihan, tanggal terbit, dan nama kos.'),
    butir('Penerbitan tagihan satuan dan massal, pembatalan tagihan, serta pemaafan denda.'),
    butir('Pencatatan pembayaran, verifikasi, dan penarikan pembayaran yang salah.'),
    butir('Kewenangan pengelola dan alur usulan perubahan yang diputuskan pemilik.'),
    butir('Rekap pendapatan, tunggakan menurut umur piutang, jejak audit, dan ekspor CSV.'),
    butir('Portal penghuni dan pembayaran daring melalui tautan gateway.'),
    p('b.  Yang tidak termasuk dalam lingkup:', { tebal: true, setelah: 80, sebelum: 120 }),
    butir('Pengelolaan beberapa properti kos dalam satu akun (sistem dirancang untuk satu kos).'),
    butir('Penggajian karyawan, pembukuan pajak, dan akuntansi lengkap.'),
    butir('Aplikasi mobile native; antarmuka web dibuat responsif dan dapat dipasang sebagai PWA.'),
    butir('Deposit atau uang jaminan sewa (tidak dicatat sebagai uang masuk, tidak mengurangi tagihan).'),
    butir('Refund otomatis untuk pembayaran yang masuk ke tagihan yang sudah dibatalkan; sistem mencatatnya sebagai "perlu tindakan".'),
    new Paragraph({ children: [new PageBreak()] }),

    /* ------------------------------------------------------------ BAB II */
    h1('BAB II LANDASAN TEORI'),

    h2('2.1 Konsep Perangkat Lunak'),
    p(
      'Perangkat lunak adalah kumpulan instruksi yang dieksekusi komputer untuk menghasilkan fungsi tertentu, ditambah data dan dokumentasi yang menyertainya. Sommerville (2016) menekankan bahwa perangkat lunak bukan sekadar program: ia adalah produk yang dikembangkan, dipelihara, dan berubah sepanjang waktu. Perangkat lunak berbeda dari perangkat keras karena tidak "aus", tetapi justru cenderung bertambah rumit setiap kali diubah [1].',
      { indentasi: true }
    ),
    p(
      'Karakteristik perangkat lunak yang relevan dengan perancangan ini ada empat. Pertama, perangkat lunak bersifat logis: ia tidak berwujud, sehingga kesalahannya hanya terlihat melalui perilaku. Kedua, perangkat lunak harus dapat dipelihara: perubahan tarif atau kebijakan kos tidak boleh menuntut penulisan ulang sistem. Ketiga, perangkat lunak harus dapat diuji: klaim bahwa suatu aturan berjalan benar harus dibuktikan, bukan diyakini. Keempat, perangkat lunak dibangun untuk pengguna tertentu, sehingga antarmuka dan istilahnya mengikuti cara kerja mereka, bukan cara kerja pembuatnya.',
      { indentasi: true }
    ),
    p(
      'Fungsi perangkat lunak dalam konteks usaha kos adalah menggantikan pencatatan manual dengan catatan yang konsisten, menyediakan perhitungan yang seragam, dan menyajikan informasi kepada pihak yang berhak melihatnya. Ketiga fungsi itu menjadi dasar perancangan pada bab berikutnya.',
      { indentasi: true }
    ),

    h2('2.2 Rekayasa Perangkat Lunak'),
    p(
      'Rekayasa perangkat lunak adalah penerapan pendekatan yang sistematis, disiplin, dan terukur untuk pengembangan, pengoperasian, dan pemeliharaan perangkat lunak (Pressman & Maxim, 2020). Tujuannya bukan menghasilkan kode sebanyak mungkin, melainkan menghasilkan perangkat lunak yang benar, dapat dipelihara, dan sesuai kebutuhan pada biaya yang wajar [2].',
      { indentasi: true }
    ),
    p('Prinsip yang dipegang dalam perancangan ini adalah sebagai berikut:', { indentasi: true }),
    butir('Kebutuhan menjadi dasar, bukan selera teknis. Fitur yang tidak menjawab kebutuhan tidak dikerjakan.'),
    butir('Kesederhanaan diutamakan. Struktur yang paling sedikit memenuhi kebutuhan dipilih, agar mudah dijelaskan dan diuji.'),
    butir('Setiap klaim dibuktikan. Aturan uang tidak cukup "diperiksa dengan mata"; ia diuji otomatis dan hasilnya tercatat.'),
    butir('Perubahan dijaga riwayatnya. Data keuangan bersifat tambah-saja, dan setiap perubahan angka memiliki jejak audit.'),
    p(
      'Tahapan rekayasa perangkat lunak yang dipakai meliputi analisis kebutuhan, perancangan, implementasi, pengujian, dan pemeliharaan. Pada proyek ini kelima tahap dijalankan berurutan, masing-masing dengan keluaran yang menjadi masukan tahap berikutnya, sebagaimana dijelaskan pada subbab 3.1.',
      { indentasi: true }
    ),

    h2('2.3 Model Pengembangan Perangkat Lunak'),
    p('Beberapa model pengembangan yang umum dipakai [1][2] beserta karakteristiknya:', { indentasi: true }),
    tabel(
      ['Model', 'Karakteristik', 'Kelebihan', 'Kekurangan'],
      [
        ['Waterfall', 'Tahap berurutan, kembali ke tahap sebelumnya mahal', 'Sederhana, mudah didokumentasikan', 'Perubahan kebutuhan di tengah jalan sulit ditampung'],
        ['Prototype', 'Purwarupa dibuat, dinilai pengguna, lalu diperbaiki', 'Kebutuhan yang samar menjadi jelas lebih cepat', 'Risiko purwarupa dianggap produk jadi'],
        ['RAD', 'Pengembangan cepat dengan komponen siap pakai dan keterlibatan pengguna intensif', 'Waktu pengembangan singkat', 'Menuntut keterlibatan pengguna yang tinggi'],
        ['Agile', 'Siklus pendek, hasil tiap siklus dapat dinilai, perubahan diterima', 'Cocok untuk kebutuhan yang berkembang dan tim kecil', 'Menuntut disiplin pengujian agar tidak menjadi tambal-sulam'],
      ],
      [16, 30, 27, 27]
    ),
    p(
      'Perbandingan di atas menjadi dasar pemilihan model pada subbab 3.1. Model yang dipilih harus sesuai dengan keadaan proyek: kebutuhan dapat ditetapkan di muka, lingkupnya tetap, dan setiap tahap dituntut menghasilkan dokumen yang dapat diperiksa sebelum tahap berikutnya dimulai.',
      { indentasi: true }
    ),
    ...gambar('waterfall.png', 'docs/akademik', 'Gambar II.1 Tahapan Model Waterfall', 15),
    new Paragraph({ children: [new PageBreak()] }),

    /* ----------------------------------------------------------- BAB III */
    h1('BAB III PERANCANGAN DAN PEMODELAN SISTEM'),

    h2('3.1 Pemilihan Model Pengembangan Perangkat Lunak'),
    p(
      'Model yang dipilih adalah Waterfall. Alasannya tiga. Pertama, kebutuhan sistem ini dapat ditetapkan di muka: objeknya satu kos dengan aturan yang ditentukan pemilik, sehingga daftar kebutuhan fungsional dan nonfungsional dapat diselesaikan dan disetujui sebelum penulisan kode dimulai. Kedua, lingkupnya tetap dan tidak menunggu umpan balik pasar; yang berubah selama pengerjaan adalah penemuan kesalahan dan penyempurnaan aturan, bukan penambahan fitur baru, sehingga perubahan itu dicatat sebagai amandemen kontrak kebutuhan, bukan sebagai siklus baru. Ketiga, setiap tahap menghasilkan dokumen yang menjadi masukan tahap berikutnya, dan itu cocok dengan kebutuhan akademik yang menuntut jejak perancangan yang dapat diperiksa: analisis kebutuhan, desain, implementasi, pengujian, dan pemeliharaan.',
      { indentasi: true }
    ),
    p('Konsekuensi yang harus diterima adalah sifatnya yang kaku: kesalahan yang baru ditemukan pada tahap pengujian menuntut penelusuran kembali ke tahap desain. Karena itu setiap tahap diberi gerbang yang harus lulus sebelum tahap berikutnya dimulai, dan perubahan setelah gerbang dilampirkan sebagai amandemen bernomor agar tidak ada aturan yang berubah tanpa jejak.', { indentasi: true }),
    p('Tahapan pengembangan beserta keluarannya adalah sebagai berikut.', { indentasi: true }),
    tabel(
      ['Tahap', 'Aktivitas dan keluaran', 'Gerbang'],
      [
        ['1. Analisis kebutuhan', 'Wawancara pemilik kos; 28 kebutuhan fungsional, 12 kebutuhan nonfungsional, dan 9 aturan bisnis', 'Kebutuhan disetujui pemilik; tidak ada fitur tanpa nomor kebutuhan'],
        ['2. Desain sistem', 'Skema basis data, use case, activity, sequence, dan class diagram, rancangan antarmuka', 'Setiap kelas menunjuk tabel yang ada; setiap use case menunjuk endpoint nyata'],
        ['3. Implementasi', 'API Laravel, antarmuka Vue 3, dan basis data PostgreSQL', 'Build berhasil; tes unit dan integrasi hijau'],
        ['4. Pengujian', '927 tes PHP, 19 tes Vitest, 34 skrip uji peramban, uji aksesibilitas, dan uji beban', 'Seluruh uji lulus; tidak ada temuan terbuka'],
        ['5. Pemeliharaan', 'Perbaikan temuan audit dan penyesuaian aturan (mis. perlakuan uang pada tagihan yang dibatalkan)', 'Setiap perubahan dicatat sebagai amandemen dan diuji ulang'],
      ],
      [18, 55, 27]
    ),

    h2('3.2 Analisis Kebutuhan Sistem'),
    p(
      'Kebutuhan sistem dibagi menjadi kebutuhan fungsional dan nonfungsional. Kebutuhan fungsional menyatakan apa yang harus dapat dilakukan sistem; kebutuhan nonfungsional menyatakan batas mutu yang harus dipenuhi.',
      { indentasi: true }
    ),
    h3('a. Kebutuhan Fungsional'),
    tabel(['ID', 'Functional Requirement'], FR, [12, 88]),
    p('Tabel III.1 Kebutuhan Fungsional Sistem', { miring: true, ukuran: 20, rata: AlignmentType.CENTER }),
    h3('b. Kebutuhan Nonfungsional'),
    tabel(['ID', 'Non-Functional Requirement', 'Jenis'], NFR, [12, 68, 20]),
    p('Tabel III.2 Kebutuhan Nonfungsional Sistem', { miring: true, ukuran: 20, rata: AlignmentType.CENTER }),

    h2('3.3 Identifikasi Pengguna Sistem'),
    p(
      'Sistem ini melayani tiga aktor dengan batas wewenang yang berbeda. Perbedaan itu bukan sekadar label: ia menentukan menu yang muncul, endpoint yang boleh dipanggil, dan data yang boleh dibaca.',
      { indentasi: true }
    ),
    tabel(
      ['Aktor', 'Peran dan hak akses'],
      [
        ['Pemilik Kos', 'Pemegang keputusan tertinggi. Menetapkan tarif, kebijakan kos, dan wewenang pengelola; menyetujui usulan perubahan; melihat seluruh data dan jejak audit.'],
        ['Pengelola', 'Menjalankan pekerjaan harian: kamar, penghuni, kontrak, tagihan, pembayaran, dan laporan. Wewenangnya diberikan satu per satu; mengubah tarif dan kebijakan hanya dapat diusulkan, bukan dilakukan langsung.'],
        ['Penghuni', 'Melihat kontrak, tagihan, denda, aturan, dan rekening tujuan miliknya sendiri; membuka tautan bayar daring; mengirim laporan kerusakan beserta foto.'],
      ],
      [22, 78]
    ),
    p('Tabel III.3 Aktor dan Hak Akses', { miring: true, ukuran: 20, rata: AlignmentType.CENTER }),
    p(
      'Pembagian use case menurut aktor diturunkan dari matriks pada dokumen perancangan, bukan dari ingatan penulis. Setiap use case menunjuk endpoint nyata pada API, sehingga tidak ada fungsi yang hanya ada di diagram.',
      { indentasi: true }
    ),
    tabel(
      ['Aktor', 'Use case yang dilayani'],
      [
        ['Pemilik Kos', 'UC-01 s.d. UC-12, UC-14'],
        ['Pengelola', 'UC-01 s.d. UC-04, UC-07 s.d. UC-11, UC-14'],
        ['Penghuni', 'UC-01, UC-11, UC-13'],
      ],
      [22, 78]
    ),
    p('Tabel III.4 Kebutuhan Fungsional per Aktor', { miring: true, ukuran: 20, rata: AlignmentType.CENTER }),

    h2('3.4 Gambaran Umum Sistem yang Diusulkan'),
    p(
      `${NAMA_SISTEM} bekerja sebagai satu layanan web: antarmuka Vue 3 dan API Laravel dilayani dari satu alamat, sehingga tidak ada perbedaan alamat antara halaman dan data. Pengguna membuka peramban, masuk dengan peran masing-masing, lalu melihat menu yang sesuai. Tidak ada pemasangan aplikasi tambahan; pada telepon, aplikasi dapat ditambahkan ke layar utama sebagai PWA.`,
      { indentasi: true }
    ),
    p('Alur utama sistem adalah sebagai berikut:', { indentasi: true }),
    nomor('1.  Pemilik mengisi kebijakan kos. Selama tiga kebijakan wajib belum lengkap, sistem menolak menerbitkan tagihan.'),
    nomor('2.  Pemilik atau pengelola mencatat kamar, penghuni, dan kontrak. Tarif yang berlaku disimpan sebagai riwayat, bukan sebagai satu angka yang ditimpa.'),
    nomor('3.  Tagihan diterbitkan untuk satu kontrak atau sekaligus untuk banyak kontrak. Pokok dihitung dari tarif dan, bila penghuni masuk di tengah bulan, diprorata.'),
    nomor('4.  Denda dihitung setiap kali tagihan dibaca, memakai bentuk denda yang dibekukan saat tagihan terbit. Denda berhenti pada plafon dan berhenti bertambah saat pokok lunas.'),
    nomor('5.  Pembayaran dicatat beserta metode dan penerima uang. Status tagihan diturunkan dari jumlah seluruh pembayaran.'),
    nomor('6.  Laporan menyajikan pendapatan per metode, tunggakan menurut umur piutang, dan jejak audit setiap perubahan angka.'),
    p('Aturan bisnis yang mengikat sistem dirangkum pada tabel berikut. Aturan-aturan ini yang diuji otomatis, bukan sekadar ditulis.', { indentasi: true }),
    tabel(
      ['Kode', 'Aturan'],
      [
        ['R-01', 'Sewa dihitung prorata bila penghuni masuk setelah tanggal 1; hari masuk ikut dihitung.'],
        ['R-02', 'Tagihan tidak boleh terbit bila satuan sewa, jatuh tempo, atau denda belum ditetapkan; sistem tidak menebak angka uang.'],
        ['R-03', 'Denda dihitung dari pokok yang dibekukan, memiliki masa tenggang, dan berhenti pada plafon.'],
        ['R-04', 'Tunggakan dikelompokkan menurut umur piutang: 0-30, 31-60, dan di atas 60 hari.'],
        ['R-05', 'Deposit tidak dicatat: uang yang tidak pernah mengurangi tagihan lebih buruk daripada kolom yang tidak ada.'],
        ['R-06', 'Tagihan memakai tarif dan kebijakan yang berlaku saat terbit, bukan kebijakan terkini.'],
        ['R-07', 'Data penghuni hanya dapat dibaca oleh dirinya sendiri; akses ke data orang lain menjawab 404.'],
        ['R-08', 'Pembayaran bersifat tambah-saja; koreksi dilakukan dengan baris pembalik, bukan dengan mengubah baris lama.'],
        ['R-09', 'Pemilik dapat membatalkan tagihan yang salah; pembatalan tidak menghapus riwayatnya.'],
      ],
      [10, 90]
    ),

    h2('3.5 Perancangan Proses Sistem Menggunakan UML'),
    p(
      'Pemodelan menggunakan empat diagram UML [3]. Seluruh gambar ditempatkan pada halaman memanjang (landscape) setelah subbab ini agar teks di dalamnya tetap terbaca; penjelasan setiap diagram mengikuti urutan gambarnya.',
      { indentasi: true }
    ),
    h3('3.5.1 Use Case Diagram'),
    p(
      'Diagram use case (Gambar III.1) menggambarkan empat belas fungsi utama dan tiga aktor yang menjalankannya. Batas sistem digambarkan sebagai satu kotak, karena seluruh fungsi berada dalam satu aplikasi dengan satu basis data. Setiap use case menunjuk endpoint nyata pada API; build dokumen menolak use case yang tidak memiliki endpoint.',
      { indentasi: true }
    ),
    p('Dua use case yang paling menentukan kebenaran angka dijelaskan secara rinci.', { indentasi: true }),
    tabel(
      ['Elemen', 'Keterangan'],
      [
        ['Nama use case', 'UC-07 Terbitkan & Kelola Tagihan Bulanan'],
        ['Aktor', 'Pemilik Kos, Pengelola'],
        ['Prakondisi', 'Kontrak aktif tersedia; kebijakan wajib sudah lengkap.'],
        ['Alur utama', 'Aktor memilih kontrak dan periode; sistem menghitung pokok (prorata bila perlu); sistem membekukan tarif dan kebijakan; sistem menyimpan tagihan, rincian, dan audit dalam satu transaksi.'],
        ['Alur alternatif', 'Kebijakan belum lengkap: sistem menjawab 422 dan menyebut tipe kebijakan yang kurang. Tagihan periode itu sudah ada: sistem menolak penerbitan ganda.'],
        ['Pascakondisi', 'Tagihan berstatus belum bayar dan tampil pada daftar dengan total serta jatuh tempo.'],
      ],
      [24, 76]
    ),
    p('Tabel III.5 Deskripsi Use Case UC-07 Terbitkan & Kelola Tagihan', { miring: true, ukuran: 20, rata: AlignmentType.CENTER }),
    tabel(
      ['Elemen', 'Keterangan'],
      [
        ['Nama use case', 'UC-08 Catat Pembayaran Sewa'],
        ['Aktor', 'Pemilik Kos, Pengelola'],
        ['Prakondisi', 'Tagihan yang belum lunas tersedia.'],
        ['Alur utama', 'Aktor mengisi nominal, tanggal, metode, dan penerima uang; sistem menolak nominal yang melebihi sisa; sistem menyimpan baris pembayaran; sistem menjumlahkan ulang seluruh pembayaran dan menurunkan status tagihan.'],
        ['Alur alternatif', 'Nominal melebihi sisa: sistem menjawab 422 dan menyebut sisa yang boleh dibayar. Salah input: pembayaran ditarik lewat baris pembalik, bukan diubah.'],
        ['Pascakondisi', 'Status tagihan menjadi sebagian atau lunas; rekap pendapatan ikut berubah.'],
      ],
      [24, 76]
    ),
    p('Tabel III.6 Deskripsi Use Case UC-08 Catat Pembayaran Sewa', { miring: true, ukuran: 20, rata: AlignmentType.CENTER }),

    h3('3.5.2 Activity Diagram'),
    p(
      'Activity diagram menggambarkan alur kerja sistem pada tiga proses yang memuat keputusan nyata (Gambar III.2 sampai III.4). Pada penerbitan tagihan, keputusan pertama adalah kelengkapan kebijakan; bila kebijakan belum lengkap, alur berhenti dan sistem menyebut tipe yang kurang, bukan menerbitkan tagihan dengan angka tebakan. Setelah pokok dihitung, sistem membekukan tarif dan kebijakan yang dipakai, sehingga kenaikan tarif bulan depan tidak mengubah tagihan bulan ini.',
      { indentasi: true }
    ),
    p(
      'Pada pencatatan pembayaran, sistem lebih dulu menolak nominal yang melebihi sisa tagihan. Sesudah baris pembayaran tersimpan, sistem tidak menambah status sedikit-sedikit, melainkan menjumlahkan ulang seluruh pembayaran; cara ini membuat pembalikan pembayaran otomatis mengembalikan status tagihan tanpa aturan tambahan. Pada perhitungan denda, kebijakan yang dipakai adalah kebijakan yang dibekukan pada tagihan, bukan kebijakan terkini. Denda berhenti bertambah ketika plafon tercapai dan dibekukan pada tanggal pokok lunas.',
      { indentasi: true }
    ),

    h3('3.5.3 Sequence Diagram'),
    p(
      'Sequence diagram (Gambar III.5 dan III.6) menggambarkan urutan pesan antara aktor, antarmuka, layanan, dan basis data. Diagram ini memakai nama kelas dan endpoint yang benar-benar ada pada kode, sehingga diagram dan implementasi tidak dapat menyimpang tanpa ketahuan.',
      { indentasi: true }
    ),

    h3('3.5.4 Class Diagram'),
    p(
      'Class diagram diturunkan dari skema basis data dan model Eloquent, bukan digambar ulang dengan tangan. Setiap kelas menunjuk tabel yang benar-benar ada; build menolak kelas yang menunjuk tabel hantu. Karena jumlah kelasnya banyak, diagram dipecah menjadi tiga lembar (Gambar III.7 sampai III.9) agar teks di dalamnya tetap terbaca.',
      { indentasi: true }
    ),

];

  const isiTengah = [    h2('3.6 Teknologi yang Digunakan'),
    p(
      'Teknologi dipilih dengan dua pertimbangan: harus dapat dijalankan di laptop untuk demonstrasi, dan harus dapat disebarkan ke layanan gratis tanpa mengubah arsitektur.',
      { indentasi: true }
    ),
    tabel(['Komponen', 'Teknologi'], TEKNOLOGI, [28, 72]),
    p('Tabel III.7 Teknologi yang Digunakan', { miring: true, ukuran: 20, rata: AlignmentType.CENTER }),
    new Paragraph({ children: [new PageBreak()] }),

    /* ------------------------------------------------------------ BAB IV */
    h1('BAB IV DESAIN ANTARMUKA'),

    h2('4.1 Desain Antarmuka Aplikasi'),
    p(
      'Antarmuka disusun dari enam jenis halaman yang diminta: halaman masuk, dasbor, pengelolaan data, fitur utama, laporan, dan portal pengguna. Tangkapan layar berikut diambil dari aplikasi yang berjalan, bukan dari purwarupa gambar.',
      { indentasi: true }
    ),
    ...gambar('nama-kos-masuk.png', 'docs/uji', 'Gambar IV.1 Halaman Masuk', 14),
    p(
      'Halaman masuk menampilkan nama kos yang ditetapkan pemilik, bukan nama generik. Ini bukan hiasan: penghuni yang membuka tautan aktivasi harus yakin bahwa ia berada di aplikasi kos yang benar.',
      { indentasi: true }
    ),
    ...gambar('T3-tagihan.png', 'docs/uji', 'Gambar IV.2 Halaman Tagihan', 14),
    p(
      'Halaman tagihan menampilkan ringkasan pokok, denda, dan jumlah yang belum masuk, lalu daftar tagihan per periode. Tombol terbit tidak muncul bila kebijakan belum lengkap; yang muncul adalah penjelasan dan tautan menuju halaman kebijakan.',
      { indentasi: true }
    ),
    ...gambar('T4-terbit-massal.png', 'docs/uji', 'Gambar IV.3 Panel Terbit Tagihan Massal', 14),
    p(
      'Penerbitan massal memilih kontrak yang sudah aktif dan belum memiliki tagihan pada periode itu. Panel ini menampilkan hasil per kontrak, sehingga kegagalan satu kontrak tidak menyembunyikan keberhasilan kontrak lain.',
      { indentasi: true }
    ),
    ...gambar('T6-denda.png', 'docs/uji', 'Gambar IV.4 Rincian Tagihan dan Perhitungan Denda', 14),
    p(
      'Rincian tagihan memuat kendali simulasi tanggal untuk keperluan demonstrasi. Denda dihitung pada tanggal yang dipilih, dan angka yang sama dipakai daftar tagihan, halaman tunggakan, serta kuitansi.',
      { indentasi: true }
    ),
    ...gambar('T7-tunggakan.png', 'docs/uji', 'Gambar IV.5 Halaman Tunggakan per Kelompok Umur', 14),
    ...gambar('T21-rekap-pendapatan.png', 'docs/uji', 'Gambar IV.6 Halaman Rekap Pendapatan', 14),
    p(
      'Rekap pendapatan menghitung uang yang benar-benar diterima, yaitu jumlah pembayaran. Denda yang masih tertera pada tagihan tidak dihitung sebagai pendapatan, karena ia baru menjadi pendapatan pada hari dibayar.',
      { indentasi: true }
    ),
    ...gambar('T9-portal-penghuni.png', 'docs/uji', 'Gambar IV.7 Portal Penghuni', 14),
    p(
      'Portal penghuni hanya memuat data milik penghuni yang sedang masuk. Membuka tagihan penghuni lain lewat perubahan alamat menjawab 404, bukan 403, agar keberadaan tagihan orang lain tidak dapat dipetakan.',
      { indentasi: true }
    ),
    ...gambar('T20-verifikasi-pembayaran.png', 'docs/uji', 'Gambar IV.8 Halaman Verifikasi Pembayaran', 14),

    h2('4.2 Desain Arsitektur Sistem'),
    p(
      'Arsitektur sistem terdiri atas tiga lapisan: antarmuka, layanan aplikasi, dan basis data. Antarmuka dan layanan dilayani dari satu alamat sehingga tidak ada permintaan lintas situs. Lapisan layanan memisahkan pengendali HTTP, aturan domain, dan akses data, sehingga aturan uang tidak tersebar di pengendali.',
      { indentasi: true }
    ),
    p(
      'Pada lingkungan demo, seluruh layanan berjalan sebagai container di satu laptop dan hanya port loopback yang dibuka. Pada lingkungan produksi, satu container memuat antarmuka dan API, sedangkan basis data berada di layanan terpisah dan hanya menerima koneksi ber-TLS. Perbedaan itu hanya pada tempat menjalankan, bukan pada kode aplikasi. Gambar IV.9 menampilkan kedua lingkungan tersebut.',
      { indentasi: true }
    ),

];

  const isiAkhir = [    h2('4.3 Kesesuaian Desain dengan Kebutuhan Sistem'),
    p('Tabel berikut menghubungkan kebutuhan fungsional utama dengan rancangan antarmuka yang memenuhinya.', { indentasi: true }),
    tabel(
      ['Kebutuhan', 'Rancangan yang memenuhi'],
      [
        ['FR-09, FR-10', 'Halaman Kebijakan dan panel penjelasan pada halaman Tagihan'],
        ['FR-11, FR-12', 'Panel Terbit Tagihan dan Terbit Massal dengan hasil per kontrak'],
        ['FR-14, FR-15', 'Rincian tagihan dengan denda berjalan dan batas plafon'],
        ['FR-16 s.d. FR-18', 'Formulir catat pembayaran dengan metode, penerima, dan penolakan nominal berlebih'],
        ['FR-19', 'Tombol Tarik Kembali pada baris pembayaran, dengan alasan wajib'],
        ['FR-22, FR-23', 'Halaman Rekap Pendapatan dan Tunggakan'],
        ['FR-25, FR-26', 'Portal Penghuni beserta tautan bayar dan laporan kerusakan'],
      ],
      [24, 76]
    ),
    p('Tabel IV.1 Kesesuaian Kebutuhan Fungsional dengan Rancangan Antarmuka', { miring: true, ukuran: 20, rata: AlignmentType.CENTER }),
    p('Kebutuhan nonfungsional dibuktikan dengan pengujian, bukan dengan klaim. Tabel berikut menyebut buktinya.', { indentasi: true }),
    tabel(
      ['Kebutuhan', 'Bukti'],
      [
        ['NFR-02', 'Tes jumlah kueri: daftar 2 dan 4 tagihan menghasilkan jumlah kueri yang sama.'],
        ['NFR-04', 'Kata sandi disimpan sebagai hash; log permintaan tidak memuat kata sandi maupun token.'],
        ['NFR-05', 'Tes isolasi: akses ke tagihan penghuni lain menjawab 404.'],
        ['NFR-06', 'Trigger basis data menolak UPDATE dan DELETE pada tabel pembayaran.'],
        ['NFR-10', 'Uji aksesibilitas axe-core pada 21 halaman, termasuk pesan galat dan panel rincian.'],
        ['NFR-11', 'Tes header: jawaban API memakai Cache-Control no-store.'],
        ['NFR-12', 'Perintah tunggal docker compose up membangun dan menjalankan seluruh layanan.'],
      ],
      [24, 76]
    ),
    p('Tabel IV.2 Kesesuaian Kebutuhan Nonfungsional dengan Bukti Pengujian', { miring: true, ukuran: 20, rata: AlignmentType.CENTER }),
    new Paragraph({ children: [new PageBreak()] }),

    /* ------------------------------------------------------------- BAB V */
    h1('BAB V KESIMPULAN'),
    p(
      `${NAMA_SISTEM} dirancang sebagai sistem informasi operasional kos berbasis web yang menyatukan kamar, penghuni, kontrak, tagihan, pembayaran, dan laporan dalam satu basis data. Perancangan ini menjawab empat masalah yang sebelumnya tersembunyi di pencatatan manual: tidak adanya acuan bersama antara tagihan dan uang masuk, denda yang dihitung dengan cara berbeda, wewenang pengelola yang tidak terbatas, dan ketiadaan riwayat perubahan angka.`,
      { indentasi: true }
    ),
    p(
      'Model Waterfall dipilih karena kebutuhan sistem ini dapat ditetapkan di muka dan lingkupnya tetap, sehingga setiap tahap dapat diselesaikan dan diperiksa sebelum tahap berikutnya dimulai. Kekakuan model itu diimbangi dua hal: gerbang pengujian pada setiap tahap, dan pencatatan setiap perubahan aturan sebagai amandemen bernomor, sehingga tidak ada aturan yang berubah tanpa jejak.',
      { indentasi: true }
    ),
    p(
      'Hasil analisis kebutuhan dituangkan dalam dua puluh delapan kebutuhan fungsional dan dua belas kebutuhan nonfungsional. Pemodelan UML menghasilkan satu use case diagram dengan empat belas use case, tiga activity diagram untuk proses yang memuat keputusan, dua sequence diagram yang memakai kelas dan endpoint nyata, serta class diagram tiga lembar yang diturunkan dari skema basis data. Rancangan antarmuka mencakup halaman masuk, tagihan, penerbitan massal, rincian denda, tunggakan, rekap pendapatan, portal penghuni, dan verifikasi pembayaran, sedangkan arsitektur sistem memisahkan antarmuka, layanan aplikasi, dan basis data.',
      { indentasi: true }
    ),
    p(
      'Untuk pengembangan berikutnya disarankan tiga hal. Pertama, menambahkan pengujian beban pada lingkungan produksi, bukan hanya di laptop, agar batas kapasitas diketahui sebelum dipakai penghuni sungguhan. Kedua, melengkapi pengiriman surel melalui penyedia yang domainnya sudah terverifikasi, karena tautan aktivasi saat ini hanya tercatat di log pada lingkungan produksi. Ketiga, menambahkan penjadwalan penerbitan tagihan otomatis di lingkungan produksi, agar tagihan bulanan tidak bergantung pada orang yang membuka aplikasi.',
      { indentasi: true }
    ),
    new Paragraph({ children: [new PageBreak()] }),

    /* ----------------------------------------------------- daftar pustaka */
    h1('DAFTAR PUSTAKA'),
    ...DAFTAR_PUSTAKA,];

  /* ------------------------------------------------------ bagian landscape */

  const judulGambar = (nomor, teks) =>
    new Paragraph({
      alignment: AlignmentType.CENTER,
      spacing: { before: 60, after: 40 },
      children: [new TextRun({ text: `${nomor} ${teks}`, bold: true, size: 22, font: FONT })],
    });

  const halamanGambar = (nama, folder, nomor, keterangan) => {
    const jalur = path.join(AKAR, folder, nama);
    const { lebar, tinggi } = ukuranPng(jalur);
    // Batas kotak gambar pada A4 landscape: 24 cm lebar, 16 cm tinggi. Skala
    // diambil dari sisi yang paling membatasi; tanpa itu gambar yang tinggi
    // (class diagram lembar C: 27,5 cm) terpotong Word dan halamannya kosong.
    const maksW = 24;
    const maksH = 14.5;
    const skalaCm = Math.min(maksW, (maksH * lebar) / tinggi);
    const lebarPx = Math.round((skalaCm / 2.54) * 96);
    const tinggiPx = Math.round((tinggi / lebar) * lebarPx);
    return [
      judulGambar(nomor, keterangan),
      new Paragraph({
        alignment: AlignmentType.CENTER,
        spacing: { after: 40 },
        children: [new ImageRun({ data: fs.readFileSync(jalur), transformation: { width: lebarPx, height: tinggiPx } })],
      }),
    ];
  };

  const pisah = () => new Paragraph({ children: [new PageBreak()] });

  const uml = [
    ...halamanGambar('01-use-case.png', 'docs/akademik', 'Gambar III.1', 'Use Case Diagram Sistem Informasi Operasional Huni'),
    pisah(),
    ...halamanGambar('aktivitas-terbit-tagihan.png', 'docs/akademik', 'Gambar III.2', 'Activity Diagram Terbit Tagihan'),
    pisah(),
    ...halamanGambar('aktivitas-catat-pembayaran.png', 'docs/akademik', 'Gambar III.3', 'Activity Diagram Catat Pembayaran'),
    pisah(),
    ...halamanGambar('aktivitas-hitung-denda.png', 'docs/akademik', 'Gambar III.4', 'Activity Diagram Perhitungan Denda'),
    pisah(),
    ...halamanGambar('urutan-UC-07.png', 'docs/akademik', 'Gambar III.5', 'Sequence Diagram Terbitkan Tagihan (UC-07)'),
    pisah(),
    ...halamanGambar('urutan-UC-08.png', 'docs/akademik', 'Gambar III.6', 'Sequence Diagram Catat Pembayaran (UC-08)'),
    pisah(),
    ...halamanGambar('kelas-a.png', 'docs/akademik', 'Gambar III.7', 'Class Diagram Lembar A - Akun, Penghuni, Kamar'),
    pisah(),
    ...halamanGambar('kelas-b.png', 'docs/akademik', 'Gambar III.8', 'Class Diagram Lembar B - Kontrak dan Tagihan'),
    pisah(),
    ...halamanGambar('kelas-c.png', 'docs/akademik', 'Gambar III.9', 'Class Diagram Lembar C - Pembayaran dan Operasional'),
  ];

  const arsitektur = [
    ...halamanGambar('arsitektur-sistem.png', 'docs/akademik', 'Gambar IV.9', 'Arsitektur Sistem'),
  ];

  const doc = new Document({
    creator: 'Huni',
    title: 'Proposal Perancangan Sistem Informasi Operasional Kos',
    styles: { default: { document: { run: { font: FONT, size: 24 } } } },
    sections: [
      {
        properties: { page: { size: { orientation: PageOrientation.PORTRAIT }, margin: marginPotret } },
        footers: { default: kaki },
        children: depan,
      },
      {
        properties: { page: { size: { orientation: PageOrientation.LANDSCAPE }, margin: marginLanskap } },
        footers: { default: kaki },
        children: uml,
      },
      {
        properties: { page: { size: { orientation: PageOrientation.PORTRAIT }, margin: marginPotret } },
        footers: { default: kaki },
        children: isiTengah,
      },
      {
        properties: { page: { size: { orientation: PageOrientation.LANDSCAPE }, margin: marginLanskap } },
        footers: { default: kaki },
        children: arsitektur,
      },
      {
        properties: { page: { size: { orientation: PageOrientation.PORTRAIT }, margin: marginPotret } },
        footers: { default: kaki },
        children: isiAkhir,
      },
    ],
  });

  fs.mkdirSync(path.dirname(KELUAR), { recursive: true });
  const buf = await Packer.toBuffer(doc);
  fs.writeFileSync(KELUAR, buf);
  console.log('ditulis: ' + KELUAR + ' (' + Math.round(buf.length / 1024) + ' KB)');
}

utama().catch((e) => {
  console.error('GAGAL: ' + e.message);
  process.exit(1);
});
