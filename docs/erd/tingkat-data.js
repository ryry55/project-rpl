'use strict';

/**
 * Sumber ERD berjenjang — Sistem Informasi Operasional Kos-Kosan
 * -------------------------------------------------------------------------
 * Empat tingkat, satu basis data. Yang bertambah dari tingkat ke tingkat adalah
 * RINCIAN, bukan kebenaran: semuanya dibangun dari `skema.json` yang sama,
 * sehingga mustahil ada tingkat yang menyebut relasi yang tidak ada.
 *
 *   L0  ERD konseptual   entitas + relasi, tanpa atribut
 *   L1  ERD logikal      + atribut kunci, kunci asing sudah diselesaikan
 *   L2  ERD fisikal      + seluruh kolom dan tipenya
 *   L3  LRS              notasi relasional formal
 *
 * KENAPA L0 DAN L1 TIDAK MEMUAT SELURUH 24 TABEL
 *
 * ERD konseptual menjawab "benda apa saja yang terlibat", bukan "tabel apa saja
 * yang ada". Tabel teknis seperti `token_akun`, `notifications`, dan `log_audit`
 * tidak punya makna bagi pemilik kos — ia lahir dari cara sistem dibuat, bukan
 * dari cara kos dijalankan. Menjejalkannya ke tingkat konseptual membuat
 * diagram yang seharusnya bisa dijelaskan dalam satu menit menjadi tidak
 * terbaca.
 *
 * Karena itu L0/L1 memuat 11 entitas inti, dan sisanya DICATAT di
 * `alasan_dikecualikan` - bukan dihilangkan diam-diam. Pemeriksa menolak build
 * bila ada tabel yang tidak digambar dan tidak tercatat, atau bila tabel yang
 * dikecualikan ternyata tetap digambar.
 *
 * PERBEDAAN L0 DAN L1 YANG DISENGAJA
 *
 * `kamar_fasilitas` adalah tabel penghubung: di basis data ia ada, tetapi
 * secara konseptual ia BUKAN entitas — ia hanya cara menyimpan hubungan
 * banyak-ke-banyak antara kamar dan fasilitas. Karena itu:
 *
 *   L0  menggambar `kamar —memiliki— fasilitas` sebagai satu relasi M:N;
 *   L1  menggambar `kamar_fasilitas` sebagai entitas, karena pada tingkat
 *       logikal hubungan M:N memang harus sudah diselesaikan.
 *
 * Itu bukan ketidakkonsistenan, melainkan justru proses yang diminta dosen:
 * konseptual -> logikal.
 *
 * Relasi dan kardinalitas TIDAK ditulis di sini. Ketiganya diturunkan dari
 * `skema.json` oleh `chen.js`. Yang ditulis tangan hanya tata letak, kata kerja
 * pada belah ketupat, dan daftar tabel yang belum muncul di tingkat itu.
 */

/**
 * Relasi konseptual yang TIDAK punya kolom kunci asing, karena di basis data
 * hubungan itu disimpan di tabel penghubung. Setiap entri wajib menyebut tabel
 * penghubungnya, dan build gagal bila tabel itu ternyata juga digambar di
 * tingkat yang sama - sebab itu berarti hubungannya tergambar dua kali.
 */
const RELASI_KONSEPTUAL = {
  'kamar|fasilitas': { label: 'memiliki', lewat: 'kamar_fasilitas' },
};

/* ============================================================ LEVEL 0 */

/**
 * ERD konseptual. Tanpa atribut sama sekali: `kunci: []` membuat `chen.js`
 * tidak menggambar elips. Belah ketupat tetap ada, karena relasi adalah isi
 * utama tingkat ini.
 */
const L0 = {
  kanvas: { w: 2200, h: 1460 },
  judul: 'ERD Level 0 — Konseptual',
  sub: 'Entitas dan hubungannya saja · tanpa atribut · 11 entitas inti · notasi Chen',
  huruf: { entitas: 27, judul: 32, sub: 17, relasi: 16, kard: 20 },
  entitas: [
    { nama: 'tarif_sewa', x: 340, y: 270, kunci: [] },
    { nama: 'fasilitas', x: 700, y: 250, kunci: [] },
    { nama: 'kamar', x: 380, y: 640, kunci: [] },
    { nama: 'komponen_biaya', x: 340, y: 1010, kunci: [] },
    { nama: 'kebijakan_kos', x: 760, y: 1010, kunci: [] },
    { nama: 'kontrak', x: 1220, y: 620, kunci: [] },
    { nama: 'penghuni', x: 1180, y: 1000, kunci: [] },
    { nama: 'users', x: 700, y: 1260, kunci: [] },
    // `usulan_perubahan` digeser menjauh dari `users`. Pada posisi lamanya
    // (x 340) ujung kanan kotaknya hanya berselisih ~50 px dari belah ketupat
    // `mengusulkan`, sehingga tidak ada lorong yang cukup untuk label
    // kardinalitas — build menolaknya, dan penolakan itu benar.
    { nama: 'usulan_perubahan', x: 240, y: 1290, kunci: [] },
    { nama: 'tagihan', x: 1720, y: 620, kunci: [] },
    { nama: 'pembayaran', x: 1720, y: 1010, kunci: [] },
  ],
  relasi: [
    { anak: 'kontrak', kolom: 'kamar_id', label: 'dihuni' },
    { anak: 'kontrak', kolom: 'penghuni_id', label: 'dimiliki' },
    { anak: 'tagihan', kolom: 'kontrak_id', label: 'diterbitkan' },
    { anak: 'pembayaran', kolom: 'tagihan_id', label: 'dibayar' },
    { anak: 'tarif_sewa', kolom: 'kamar_id', label: 'bertarif' },
    { anak: 'komponen_biaya', kolom: 'kamar_id', label: 'berkomponen' },
    { anak: 'kebijakan_kos', kolom: 'kamar_id', label: 'berkebijakan' },
    { anak: 'users', kolom: 'penghuni_id', label: 'berakun' },
    { anak: 'usulan_perubahan', kolom: 'diajukan_oleh', label: 'mengusulkan' },
  ],
  relasi_konseptual: [{ kiri: 'kamar', kanan: 'fasilitas', label: 'memiliki', lewat: 'kamar_fasilitas' }],
  tidak_digambar: [
    { tabel: 'users', kolom: 'pemilik_id', alasan: 'relasi diri akun pengelola ke pemiliknya; bukan bagian alur kos, digambar di level 2' },
    { tabel: 'usulan_perubahan', kolom: 'diputuskan_oleh', alasan: 'pelaku keputusan; kembar dengan diajukan_oleh pada tingkat konseptual' },
    { tabel: 'pembayaran', kolom: 'pembalik_id', alasan: 'pembatalan pembayaran; rincian teknis, digambar sebagai relasi diri di level 1' },
    { tabel: 'pembayaran', kolom: 'dicatat_oleh', alasan: 'pelaku pencatatan (aktor audit), bukan bagian struktur data' },
    { tabel: 'pembayaran', kolom: 'diterima_oleh', alasan: 'pelaku penerimaan tunai (aktor audit), bukan bagian struktur data' },
    { tabel: 'tagihan', kolom: 'dibatalkan_oleh', alasan: 'pelaku pembatalan (aktor audit), bukan bagian struktur data' },
    { tabel: 'tagihan', kolom: 'denda_dimaafkan_oleh', alasan: 'pelaku pemaafan denda (aktor audit), bukan bagian struktur data' },
  ],
  dikecualikan: [
    'kamar_fasilitas',
    'tagihan_rincian',
    'verifikasi_pembayaran',
    'transaksi_gateway',
    'rekening',
    'aturan_kos',
    'pengelola_kewenangan',
    'log_audit',
    'pengaturan_notifikasi',
    'persetujuan_pdp',
    'token_akun',
    'notifications',
    'laporan_kerusakan',
  ],
  alasan_dikecualikan:
    'Tabel teknis, tabel penghubung, dan rincian keadaan (mis. laporan kerusakan): yang terakhir bermakna bagi ' +
    'pemilik tetapi tidak mengubah bentuk hubungan antarentitas. Semuanya muncul mulai level 2 (fisikal).',
};

/* ============================================================ LEVEL 1 */

/**
 * ERD logikal. Entitas yang sama, ditambah `kamar_fasilitas` karena hubungan
 * banyak-ke-banyak kamar-fasilitas sudah diselesaikan menjadi entitas.
 *
 * Atribut yang digambar hanya kunci primer dan satu pembeda; kolom selebihnya
 * ada di level 2 dan LRS. Menggambar 209 kolom sebagai elips mustahil dibaca,
 * dan itu bukan penghematan — ERD Chen yang baik memang tidak menampilkan
 * seluruh kolom.
 */
const L1 = {
  kanvas: { w: 2200, h: 1900 },
  judul: 'ERD Level 1 — Logikal',
  sub: 'Entitas + atribut kunci · kunci asing diselesaikan · 12 entitas · notasi Chen',
  huruf: { entitas: 30, judul: 34, sub: 18, relasi: 17, kard: 21, atribut: 19 },
  entitas: [
    { nama: 'tarif_sewa', x: 340, y: 300, kunci: ['id', 'nilai'] },
    { nama: 'fasilitas', x: 760, y: 270, kunci: ['id', 'nama'] },
    { nama: 'kamar_fasilitas', x: 1220, y: 300, kunci: [] },
    { nama: 'kamar', x: 380, y: 720, kunci: ['id', 'nomor'] },
    { nama: 'komponen_biaya', x: 340, y: 1150, kunci: ['id', 'nilai'] },
    { nama: 'kebijakan_kos', x: 800, y: 1150, kunci: ['id', 'tipe'] },
    { nama: 'kontrak', x: 1250, y: 700, kunci: ['id', 'tgl_masuk'] },
    { nama: 'penghuni', x: 1220, y: 1150, kunci: ['id', 'email'] },
    { nama: 'users', x: 740, y: 1520, kunci: ['id', 'email'] },
    // Alasan yang sama seperti L0: dijauhkan dari `users` supaya lorong relasi
    // cukup lebar untuk label kardinalitas.
    { nama: 'usulan_perubahan', x: 240, y: 1560, kunci: ['id', 'status'] },
    { nama: 'tagihan', x: 1780, y: 700, kunci: ['id', 'periode'] },
    { nama: 'pembayaran', x: 1780, y: 1150, kunci: ['id', 'jumlah'] },
  ],
  relasi: [
    { anak: 'kontrak', kolom: 'kamar_id', label: 'dihuni' },
    { anak: 'kontrak', kolom: 'penghuni_id', label: 'dimiliki' },
    { anak: 'tagihan', kolom: 'kontrak_id', label: 'diterbitkan' },
    { anak: 'pembayaran', kolom: 'tagihan_id', label: 'dibayar' },
    { anak: 'tarif_sewa', kolom: 'kamar_id', label: 'bertarif' },
    { anak: 'komponen_biaya', kolom: 'kamar_id', label: 'berkomponen' },
    { anak: 'kebijakan_kos', kolom: 'kamar_id', label: 'berkebijakan' },
    { anak: 'kamar_fasilitas', kolom: 'kamar_id', label: 'berfasilitas' },
    { anak: 'kamar_fasilitas', kolom: 'fasilitas_id', label: 'berupa' },
    { anak: 'pembayaran', kolom: 'pembalik_id', label: 'membalik' },
    { anak: 'users', kolom: 'penghuni_id', label: 'berakun' },
    { anak: 'usulan_perubahan', kolom: 'diajukan_oleh', label: 'mengusulkan' },
  ],
  relasi_konseptual: [],
  tidak_digambar: [
    { tabel: 'users', kolom: 'pemilik_id', alasan: 'relasi diri akun pengelola ke pemiliknya; bukan bagian alur kos, digambar di level 2' },
    { tabel: 'usulan_perubahan', kolom: 'diputuskan_oleh', alasan: 'pelaku keputusan; kembar dengan diajukan_oleh pada tingkat ini' },
    { tabel: 'pembayaran', kolom: 'dicatat_oleh', alasan: 'pelaku pencatatan (aktor audit), bukan bagian struktur data' },
    { tabel: 'pembayaran', kolom: 'diterima_oleh', alasan: 'pelaku penerimaan tunai (aktor audit), bukan bagian struktur data' },
    { tabel: 'tagihan', kolom: 'dibatalkan_oleh', alasan: 'pelaku pembatalan (aktor audit), bukan bagian struktur data' },
    { tabel: 'tagihan', kolom: 'denda_dimaafkan_oleh', alasan: 'pelaku pemaafan denda (aktor audit), bukan bagian struktur data' },
  ],
  dikecualikan: [
    'tagihan_rincian',
    'verifikasi_pembayaran',
    'transaksi_gateway',
    'rekening',
    'aturan_kos',
    'pengelola_kewenangan',
    'log_audit',
    'pengaturan_notifikasi',
    'persetujuan_pdp',
    'token_akun',
    'notifications',
    'laporan_kerusakan',
  ],
  alasan_dikecualikan:
    'Tabel teknis, tabel rincian, dan rincian keadaan (laporan kerusakan) yang tidak mengubah bentuk hubungan ' +
    'antarentitas. Semuanya muncul mulai level 2 (fisikal), dan seluruh kolomnya ada di LRS.',
};

/* ==================================================== LEVEL 2 dan 3 */

/**
 * Lembar level 2 (fisikal) dan level 3 (LRS).
 *
 * LEVEL 2 DIPECAH MENJADI TIGA LEMBAR; LEVEL 3 CUKUP SATU. Keduanya diputuskan
 * oleh pengukuran, bukan selera.
 *
 * Percobaan pertama memakai SATU lembar berisi seluruh 24 tabel untuk KEDUANYA.
 * Hasilnya ditolak oleh penjaga keterbacaan: setelah dipaskan ke satu halaman
 * A4, hurufnya hanya 1,94 mm - di bawah ambang 2,52 mm. Itu bukan soal skala
 * ekspor; 209 kolom memang tidak muat di A4.
 *
 * Penyelesaiannya berbeda untuk kedua tingkat, dan keduanya dari pengukuran:
 *
 *   - level 2 (fisikal): dipecah menjadi TIGA lembar A4, karena yang harus
 *     terbaca adalah setiap kolom beserta tipenya - teksnya paling banyak;
 *   - level 3 (LRS)    : tetap SATU lembar, tetapi di kertas A3 tegak. Seluruh
 *     209 kolom terbaca di sana (2,74 mm), sedangkan A4 terbaik hanya 1,94 mm.
 *     Lembar A3 itu diserahkan sebagai lampiran, bukan ditempel ke badan
 *     laporan - mengecilkannya ke lebar A4 mengembalikan angkanya ke 1,94 mm.
 *
 * Jadi yang menentukan bukan jumlah tabel, melainkan berapa kolom yang digambar
 * dan sebesar apa kertasnya. Tabel 24 sel (2-6 kolom x A4/A3 tegak-miring) ada
 * di README ERD, dan diukur ulang oleh `UKUR_KERTAS=1`.
 *
 * Tingkat 2 tetap dipecah menurut ALUR DATA: tiap lembar adalah satu rantai
 * yang benar-benar saling terhubung, bukan satu topik yang kebetulan mirip.
 * `users` sengaja ditaruh bersama tabel-tabel yang menunjuknya, supaya garisnya
 * bisa ditarik dan tidak menjadi kunci asing lintas lembar.
 *
 *   1. Akun & tata kelola  akun, wewenang, usulan, jejak   9 tabel
 *   2. Kamar & kebijakan   kamar, kerusakan, tarif, aturan  9 tabel
 *   3. Uang                kontrak -> tagihan -> bayar      6 tabel
 *
 * Jumlah kolom TIAP lembar tidak dipatok di sini: diukur oleh `pecahTerbaca`
 * di build-tingkat.js, karena bentuk lembar menentukan mana yang lebih dulu
 * menyentuh tepi halaman - lebar atau tinggi.
 *
 * Kunci asing yang menyeberang lembar TIDAK bisa ditarik garisnya - tabel
 * tujuannya ada di lembar lain. Karena itu ia DIHITUNG dari skema dan ditulis
 * di legenda tiap lembar, bukan didaftarkan tangan: daftar yang diketik akan
 * basi, dan daftar yang basi tidak memberi tanda apa pun.
 */

/** Tiga modul, menurut alur data. Urutan tabel mengikuti alur pemakaian. */
const MODUL = [
  {
    kode: 'a',
    nama: 'Akun & Tata Kelola',
    tabel: [
      'users',
      'penghuni',
      'pengelola_kewenangan',
      'pengaturan_notifikasi',
      'persetujuan_pdp',
      'token_akun',
      'usulan_perubahan',
      'log_audit',
      'notifications',
    ],
  },
  {
    kode: 'b',
    nama: 'Kamar, Tarif & Kebijakan',
    tabel: [
      'kamar',
      'laporan_kerusakan',
      'fasilitas',
      'kamar_fasilitas',
      'tarif_sewa',
      'komponen_biaya',
      'kebijakan_kos',
      'aturan_kos',
      'rekening',
    ],
  },
  {
    kode: 'c',
    nama: 'Kontrak, Tagihan & Pembayaran',
    tabel: [
      'kontrak',
      'tagihan',
      'tagihan_rincian',
      'pembayaran',
      'verifikasi_pembayaran',
      'transaksi_gateway',
    ],
  },
];

/** Jumlah kolom per lembar; ditentukan pengukuran, bukan selera. */
const KOLOM_LEMBAR = 3;

/** Lembar yang tetap terlalu padat pada 3 kolom memakai 2 kolom. */
const KOLOM_OVERRIDE = { b: 2, c: 2 };

const CATATAN_FISIKAL =
  'Kunci asing yang menyeberang ke lembar lain tidak bisa ditarik garisnya; tujuannya disebut di legenda.';

/** Susun definisi satu lembar level 2 (fisikal). */
function lembarFisikal(m) {
  return {
    kode: `L2-${m.kode}`,
    berkas: `02-L2-fisikal-${m.kode}.svg`,
    judul: `ERD Level 2 — Fisikal (${m.kode.toUpperCase()})`,
    sub: `${m.nama} · ${m.tabel.length} tabel · seluruh kolom dan tipenya · PostgreSQL 16`,
    mode: 'fisikal',
    jumlahKolom: KOLOM_OVERRIDE[m.kode] ?? KOLOM_LEMBAR,
    tabel: m.tabel,
    catatan: CATATAN_FISIKAL,
  };
}

module.exports = {
  L0,
  L1,
  MODUL,
  KOLOM_LEMBAR,
  lembarFisikal,
  RELASI_KONSEPTUAL,
};
