'use strict';

/**
 * Sumber LRS — bagian yang TIDAK bisa dibaca dari basis data.
 * -------------------------------------------------------------------------
 * Nama tabel, kolom, tipe, kunci primer, kunci asing, dan batasan semuanya
 * dibaca dari `skema.json`. Yang ditulis di sini hanya dua hal:
 *
 *   1. MAKNA    — satu baris penjelasan per tabel, dan makna kolom yang
 *                 namanya tidak menjelaskan dirinya sendiri.
 *   2. URUTAN   — urutan penyajian tabel agar enak dibaca, bukan urut abjad.
 *
 * Tidak ada satu pun nama kolom yang diketik di sini. Kalau kolom ditambah
 * atau dihapus di migrasi, LRS ikut berubah tanpa berkas ini disentuh — dan
 * kolom yang lupa diberi keterangan tetap muncul, hanya tanpa keterangan.
 * Sebaliknya, kalau keterangan di sini menyebut kolom yang sudah tidak ada,
 * build gagal.
 */

/** Penjelasan satu baris per tabel. Wajib ada untuk seluruh 23 tabel. */
const KETERANGAN_TABEL = {
  users:
    'Akun untuk masuk aplikasi. Kolom `peran` menentukan hak akses: pemilik, pengelola, atau penghuni.',
  penghuni:
    'Data orang yang menyewa kamar. Sengaja dipisah dari `users` supaya penghuni bisa dicatat sebelum punya akun.',
  kamar:
    'Unit kamar yang disewakan, beserta status huninya.',
  fasilitas:
    'Daftar fasilitas yang bisa dimiliki kamar, mis. kasur, AC, kamar mandi dalam.',
  kamar_fasilitas:
    'Tabel penghubung kamar dan fasilitas. Satu kamar punya banyak fasilitas, satu fasilitas dipakai banyak kamar.',
  tarif_sewa:
    'Tarif sewa per kamar yang berlaku mulai tanggal tertentu. Riwayat, bukan satu nilai yang ditimpa.',
  komponen_biaya:
    'Biaya tambahan per kamar (listrik, air, kebersihan) yang ikut ditagihkan setiap bulan.',
  kebijakan_kos:
    'Kebijakan kos: satuan sewa, jatuh tempo, dan aturan denda. Berlaku untuk seluruh kos bila `kamar_id` kosong, atau khusus satu kamar bila diisi.',
  kontrak:
    'Perjanjian sewa seorang penghuni pada satu kamar, lengkap dengan tanggal masuk dan statusnya.',
  tagihan:
    'Tagihan bulanan per kontrak: pokok, denda, total akhir, dan status pembayaran.',
  tagihan_rincian:
    'Rincian baris penyusun tagihan, supaya angka pokok bisa dipertanggungjawabkan.',
  pembayaran:
    'Pembayaran yang dicatat atas sebuah tagihan. Pembalik disimpan sebagai baris bernilai negatif yang menunjuk baris asalnya.',
  verifikasi_pembayaran:
    'Pemeriksaan bukti transfer oleh pengelola sebelum pembayaran diakui sah.',
  transaksi_gateway:
    'Transaksi Midtrans untuk pembayaran daring, termasuk `order_id` dan statusnya.',
  rekening:
    'Rekening tujuan pembayaran milik kos, termasuk gambar QRIS yang disimpan sebagai biner.',
  aturan_kos:
    'Teks aturan dan sanksi yang ditampilkan kepada penghuni.',
  log_audit:
    'Jejak setiap tindakan yang mengubah data, beserta pelaku dan waktunya.',
  usulan_perubahan:
    'Usulan perubahan tarif atau kebijakan oleh pengelola yang menunggu keputusan pemilik. Kedaluwarsa setelah 7 hari.',
  pengelola_kewenangan:
    'Wewenang terperinci yang diberikan pemilik kepada seorang pengelola.',
  pengaturan_notifikasi:
    'Preferensi kanal notifikasi per pengguna per peristiwa.',
  persetujuan_pdp:
    'Rekaman persetujuan pemrosesan data pribadi, beserta versi pernyataan yang disetujui.',
  token_akun:
    'Token sekali pakai untuk aktivasi akun dan atur ulang sandi. Disimpan sebagai hash, bukan teks asli.',
  notifications:
    'Notifikasi dalam aplikasi bawaan Laravel. Tidak punya kunci asing karena memakai relasi polimorfik.',
};

/**
 * Makna kolom yang namanya tidak menjelaskan dirinya sendiri.
 * Kolom yang tidak tercantum di sini tetap muncul di LRS, hanya tanpa keterangan.
 */
const KETERANGAN_KOLOM = {
  'users.peran': 'pemilik | pengelola | penghuni',
  'users.penghuni_id': 'diisi bila akun ini milik seorang penghuni',
  'users.pemilik_id': 'diisi bila akun ini pengelola yang terikat pada seorang pemilik',
  'users.wajib_ganti_sandi': 'true bila sandi dibuatkan pihak lain dan harus diganti saat pertama masuk',
  'penghuni.email': 'dipakai mengirim tautan aktivasi akun',
  'kamar.nomor': 'unik; dipakai penghuni mengenali kamarnya',
  'kamar.status_huni': 'kosong | terisi | dipesan',
  'tarif_sewa.nilai': 'rupiah bulat per satuan sewa yang berlaku',
  'tarif_sewa.berlaku_dari': 'tanggal mulai berlakunya tarif; tarif lama tidak dihapus',
  'komponen_biaya.nama': 'mis. Listrik, Air, Kebersihan',
  'komponen_biaya.nilai': 'rupiah bulat yang ditambahkan ke tagihan setiap bulan',
  'komponen_biaya.kamar_id': 'kosong berarti berlaku untuk seluruh kamar',
  'kebijakan_kos.tipe': 'satuan_sewa | jatuh_tempo | denda',
  'kebijakan_kos.nilai': 'nilai utama; artinya bergantung pada `tipe`',
  'kebijakan_kos.persen': 'dipakai bila tipe denda dan nilainya persen_harian atau persen_bulanan',
  'kebijakan_kos.plafon_persen': 'batas tertinggi denda, mis. 30 berarti 30% dari pokok',
  'kebijakan_kos.masa_tenggang': 'jumlah hari sebelum denda mulai dihitung',
  'kebijakan_kos.nominal': 'dipakai bila tipe denda dan nilainya nominal_tetap',
  'kebijakan_kos.tanggal': 'dipakai bila tipe jatuh_tempo dan nilainya tanggal_tetap',
  'kontrak.tgl_masuk': 'dasar perhitungan prorata bulan pertama',
  'kontrak.status': 'dipesan | aktif | selesai | dibatalkan',
  'tagihan.periode': 'bulan yang ditagih; satu periode hanya boleh punya satu tagihan hidup per kontrak',
  'tagihan.pokok': 'jumlah sebelum denda, sesudah prorata',
  'tagihan.tarif_denda_persen': 'salinan tarif denda saat terbit; denda dihitung dari sini, tidak disimpan sebagai kolom',
  'tagihan.plafon_persen': 'salinan batas denda saat terbit, mis. 30 berarti 30% dari pokok',
  'tagihan.masa_tenggang': 'salinan jumlah hari tenggang saat terbit',
  'tagihan.denda_dimaafkan': 'true bila pemilik menghapus denda pada tagihan ini; pokok tetap ditagih',
  'tagihan.total_final': 'pokok + denda berjalan - pembayaran yang sudah tercatat',
  'tagihan.status': 'belum_bayar | sebagian | lunas | dibatalkan',
  'tagihan.tarif_terpakai': 'salinan tarif saat tagihan diterbitkan, supaya riwayat tidak berubah bila tarif diperbarui',
  'tagihan.kebijakan_terpakai': 'salinan kebijakan saat terbit, agar tagihan lama tetap bisa dipertanggungjawabkan',
  'tagihan.terbit_pada': 'waktu tagihan diterbitkan; tidak berubah walau tagihan diperbarui',
  'tagihan.dibatalkan_oleh': 'hanya status yang berubah; baris tidak pernah dihapus',
  'pembayaran.jumlah': 'bernilai negatif bila baris ini adalah pembalik',
  'pembayaran.pembalik_id': 'menunjuk baris yang dibalik; unik, sehingga satu pembayaran hanya bisa dibalik sekali',
  'pembayaran.sumber': 'manual | tunai | gateway_webhook',
  'pembayaran.referensi': 'nomor referensi dari gateway, bila sumbernya gateway_webhook',
  'verifikasi_pembayaran.keputusan': 'disetujui | ditolak',
  'transaksi_gateway.order_id': 'unik; dipakai mencocokkan webhook Midtrans dengan transaksi',
  'transaksi_gateway.status': 'menunggu | berhasil | gagal | kedaluwarsa',
  'rekening.gambar': 'biner gambar QRIS; hanya boleh ditulis lewat decode(?, base64)',
  'usulan_perubahan.status': 'menunggu | disetujui | ditolak | kedaluwarsa',
  'usulan_perubahan.kedaluwarsa_pada': 'usulan yang tidak diputuskan dalam 7 hari menjadi kedaluwarsa',
  'log_audit.aktor_id': 'sengaja tanpa kunci asing agar jejak audit tetap utuh bila akun dihapus',
  'log_audit.aksi': 'mis. pemilik.tambah, tagihan.terbit, tarif.ubah',
  'pengelola_kewenangan.wewenang': 'satu baris per wewenang; 8 jenis, 2 di antaranya sensitif',
  'persetujuan_pdp.versi': 'versi pernyataan yang disetujui',
  'token_akun.token_hash': 'hash token; token asli hanya ada di tautan yang dikirim lewat email',
  'token_akun.jenis': 'aktivasi | reset',
  'notifications.notifiable_type': 'kelas pemilik notifikasi; relasi polimorfik, bukan kunci asing',
  'notifications.notifiable_id': 'id baris pemilik notifikasi',
};

/**
 * Urutan penyajian tabel di dokumen. Tabel yang tidak disebut akan muncul
 * di belakang, urut abjad — jadi menambah tabel baru tidak pernah membuat
 * build gagal hanya karena lupa mendaftarkannya di sini.
 */
const URUTAN_TABEL = [
  'users',
  'penghuni',
  'kamar',
  'fasilitas',
  'kamar_fasilitas',
  'tarif_sewa',
  'komponen_biaya',
  'kebijakan_kos',
  'kontrak',
  'tagihan',
  'tagihan_rincian',
  'pembayaran',
  'verifikasi_pembayaran',
  'transaksi_gateway',
  'rekening',
  'aturan_kos',
  'pengelola_kewenangan',
  'usulan_perubahan',
  'log_audit',
  'pengaturan_notifikasi',
  'persetujuan_pdp',
  'token_akun',
  'notifications',
];

module.exports = { KETERANGAN_TABEL, KETERANGAN_KOLOM, URUTAN_TABEL };
