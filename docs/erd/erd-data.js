'use strict';

/**
 * Sumber ERD — Sistem Informasi Operasional Kos-Kosan
 * -------------------------------------------------------------------------
 * Berkas ini HANYA menyimpan dua hal yang tidak bisa dibaca dari basis data:
 *
 *   1. TATA LETAK  — di mana tiap entitas digambar.
 *   2. BAHASA      — kata kerja pada belah ketupat ("menempati", "menerbitkan").
 *
 * Relasi, kardinalitas, dan partisipasi TIDAK ditulis di sini. Ketiganya
 * diturunkan dari `skema.json` oleh `erd-gambar.js`:
 *
 *   - kardinalitas "N" ditentukan oleh apakah kolom kunci asing punya indeks
 *     unik (unik -> 1, tidak unik -> N);
 *   - partisipasi "wajib/opsional" ditentukan oleh `boleh_null`;
 *   - arah panah ditentukan oleh arah kunci asing.
 *
 * Akibatnya ERD tidak bisa menyimpang dari basis data tanpa build gagal:
 * setiap kunci asing harus digambar atau dideklarasikan di `TIDAK_DIGAMBAR`
 * beserta alasannya. Tidak ada kunci asing yang boleh hilang diam-diam.
 *
 * Tabel tanpa kunci asing (aturan_kos, log_audit, notifications, rekening)
 * memang digambar tanpa garis. Itu bukan kelalaian: keempatnya tidak punya
 * kunci asing di basis data, dan legenda menyebutkannya — daftarnya dihitung
 * dari `skema.json`, bukan diketik, sehingga tabel baru yang berdiri sendiri
 * otomatis ikut disebut.
 */

/* ------------------------------------------------------------------ INTI */

/**
 * ERD inti: tujuh entitas yang membentuk alur uang, dari kamar sampai
 * pembayaran. Tujuannya bukan kelengkapan, melainkan bisa dijelaskan lisan
 * dalam satu menit di depan penguji.
 *
 * Atribut digambar sebagai elips HANYA untuk kunci dan satu pembeda, karena
 * elips untuk 201 kolom mustahil dibaca. Kolom selebihnya ada di LRS — dan
 * itulah pembagian kerja antara ERD dan LRS.
 */
const INTI = {
  kanvas: { w: 2000, h: 1140 },
  judul: 'ERD Inti — Sistem Informasi Operasional Kos-Kosan',
  sub: 'Tujuh entitas alur uang · notasi Chen · atribut kunci saja (rincian kolom ada di LRS)',
  entitas: [
    { nama: 'kamar', x: 500, y: 280, kunci: ['id', 'nomor'] },
    { nama: 'penghuni', x: 500, y: 560, kunci: ['id', 'email'] },
    { nama: 'users', x: 500, y: 840, kunci: ['id', 'email'] },
    { nama: 'kontrak', x: 1050, y: 420, kunci: ['id', 'tgl_masuk'] },
    { nama: 'tagihan', x: 1600, y: 420, kunci: ['id', 'periode'] },
    { nama: 'tagihan_rincian', x: 1600, y: 700, kunci: ['id', 'nilai'] },
    { nama: 'pembayaran', x: 1050, y: 700, kunci: ['id', 'jumlah'] },
  ],
  relasi: [
    { anak: 'kontrak', kolom: 'kamar_id', label: 'dihuni' },
    { anak: 'kontrak', kolom: 'penghuni_id', label: 'dimiliki' },
    { anak: 'tagihan', kolom: 'kontrak_id', label: 'diterbitkan' },
    { anak: 'tagihan_rincian', kolom: 'tagihan_id', label: 'dirinci' },
    { anak: 'pembayaran', kolom: 'tagihan_id', label: 'dibayar' },
    { anak: 'users', kolom: 'penghuni_id', label: 'berakun' },
  ],
  // Kunci asing yang sengaja tidak digambar di ERD inti, dengan alasannya.
  tidak_digambar: [
    { tabel: 'pembayaran', kolom: 'pembalik_id', alasan: 'relasi diri untuk pembalik; ditampilkan di ERD lengkap' },
    { tabel: 'pembayaran', kolom: 'dicatat_oleh', alasan: 'rujukan aktor audit; bukan bagian alur uang' },
    { tabel: 'pembayaran', kolom: 'diterima_oleh', alasan: 'rujukan aktor audit; bukan bagian alur uang' },
    { tabel: 'tagihan', kolom: 'dibatalkan_oleh', alasan: 'rujukan aktor audit; bukan bagian alur uang' },
    { tabel: 'tagihan', kolom: 'denda_dimaafkan_oleh', alasan: 'rujukan aktor audit; bukan bagian alur uang' },
    { tabel: 'users', kolom: 'pemilik_id', alasan: 'relasi diri akun pengelola ke pemiliknya; bukan alur uang' },
  ],
};

/* -------------------------------------------------------------- LENGKAP */

/**
 * ERD lengkap: seluruh 23 tabel domain.
 *
 * Tata letak dibagi tiga pita:
 *   kiri   — kos, kamar, dan turunannya (kebijakan, komponen, tarif, fasilitas)
 *   tengah — alur uang: kontrak, tagihan, pembayaran, gateway, verifikasi
 *   kanan  — akun, wewenang, persetujuan, dan jejak audit
 *
 * `TIDAK_DIGAMBAR` bukan tempat menyembunyikan yang tidak muat. Setiap baris
 * di sana harus punya alasan yang benar, dan `erd-gambar.js` menolak build
 * bila ada kunci asing yang tidak tercatat di salah satu dari dua daftar.
 */
const LENGKAP = {
  kanvas: { w: 3000, h: 1820 },
  judul: 'ERD Lengkap — Sistem Informasi Operasional Kos-Kosan',
  sub: '23 tabel domain · notasi Chen · kunci asing diturunkan dari basis data, bukan diketik',
  entitas: [
    // --- kanan: akun, wewenang, persetujuan ---
    { nama: 'pengelola_kewenangan', x: 1480, y: 240 },
    { nama: 'users', x: 1940, y: 240 },
    { nama: 'pengaturan_notifikasi', x: 2420, y: 240 },
    { nama: 'usulan_perubahan', x: 1480, y: 520 },
    { nama: 'notifications', x: 1940, y: 520 },
    { nama: 'persetujuan_pdp', x: 2420, y: 520 },
    { nama: 'token_akun', x: 2420, y: 800 },
    { nama: 'log_audit', x: 2420, y: 1140 },
    { nama: 'rekening', x: 2420, y: 1460 },

    // --- tengah: alur uang ---
    { nama: 'penghuni', x: 1250, y: 480 },
    { nama: 'kontrak', x: 1560, y: 760 },
    { nama: 'tagihan', x: 1560, y: 1100 },
    { nama: 'tagihan_rincian', x: 2040, y: 1100 },
    { nama: 'pembayaran', x: 1560, y: 1440 },
    { nama: 'transaksi_gateway', x: 2040, y: 1440 },
    { nama: 'verifikasi_pembayaran', x: 1180, y: 1440 },

    // --- kiri: kos, kamar, turunannya ---
    { nama: 'kamar', x: 620, y: 800 },
    { nama: 'kebijakan_kos', x: 200, y: 560 },
    { nama: 'komponen_biaya', x: 620, y: 480 },
    { nama: 'tarif_sewa', x: 1040, y: 560 },
    { nama: 'kamar_fasilitas', x: 620, y: 1130 },
    { nama: 'fasilitas', x: 620, y: 1450 },
    { nama: 'aturan_kos', x: 200, y: 1450 },
  ],
  relasi: [
    { anak: 'kontrak', kolom: 'kamar_id', label: 'dihuni' },
    { anak: 'kontrak', kolom: 'penghuni_id', label: 'dimiliki' },
    { anak: 'tagihan', kolom: 'kontrak_id', label: 'diterbitkan' },
    { anak: 'tagihan_rincian', kolom: 'tagihan_id', label: 'dirinci' },
    { anak: 'pembayaran', kolom: 'tagihan_id', label: 'dibayar' },
    { anak: 'pembayaran', kolom: 'pembalik_id', label: 'membalik' },
    { anak: 'tarif_sewa', kolom: 'kamar_id', label: 'bertarif' },
    { anak: 'komponen_biaya', kolom: 'kamar_id', label: 'berkomponen' },
    { anak: 'kebijakan_kos', kolom: 'kamar_id', label: 'berkebijakan' },
    { anak: 'kamar_fasilitas', kolom: 'kamar_id', label: 'memiliki' },
    { anak: 'kamar_fasilitas', kolom: 'fasilitas_id', label: 'berupa' },
    { anak: 'transaksi_gateway', kolom: 'tagihan_id', label: 'ditagih' },
    { anak: 'verifikasi_pembayaran', kolom: 'pembayaran_id', label: 'diperiksa' },
    { anak: 'pengelola_kewenangan', kolom: 'user_id', label: 'berwenang' },
    { anak: 'pengaturan_notifikasi', kolom: 'user_id', label: 'berpreferensi' },
    { anak: 'persetujuan_pdp', kolom: 'user_id', label: 'menyetujui' },
    { anak: 'token_akun', kolom: 'user_id', label: 'bermilik' },
    { anak: 'usulan_perubahan', kolom: 'diajukan_oleh', label: 'mengusulkan' },
    { anak: 'users', kolom: 'penghuni_id', label: 'berakun' },
  ],
  /**
   * Tujuh kunci asing yang tidak digambar. Semuanya merujuk ke `users` sebagai
   * PELAKU, bukan sebagai bagian struktur data. Menggambarnya akan membuat
   * tujuh garis panjang melintasi seluruh kanvas ke satu kotak yang sama, dan
   * hasilnya bukan diagram yang lebih lengkap melainkan diagram yang tidak
   * terbaca. Kolomnya tetap ada, tetap disebut di LRS, dan tetap dicatat.
   */
  tidak_digambar: [
    { tabel: 'pembayaran', kolom: 'dicatat_oleh', alasan: 'pelaku pencatatan (aktor audit), bukan struktur' },
    { tabel: 'pembayaran', kolom: 'diterima_oleh', alasan: 'pelaku penerimaan tunai (aktor audit)' },
    { tabel: 'tagihan', kolom: 'dibatalkan_oleh', alasan: 'pelaku pembatalan (aktor audit)' },
    { tabel: 'tagihan', kolom: 'denda_dimaafkan_oleh', alasan: 'pelaku pemaafan denda (aktor audit)' },
    { tabel: 'verifikasi_pembayaran', kolom: 'diverifikasi_oleh', alasan: 'pelaku verifikasi (aktor audit)' },
    { tabel: 'usulan_perubahan', kolom: 'diputuskan_oleh', alasan: 'pelaku keputusan; kembar dengan diajukan_oleh' },
    { tabel: 'users', kolom: 'pemilik_id', alasan: 'relasi diri akun pengelola ke pemiliknya' },
  ],
};

module.exports = { INTI, LENGKAP };
