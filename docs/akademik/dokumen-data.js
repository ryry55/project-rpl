'use strict';

/**
 * Sumber tunggal bab-bab narasi laporan akademik
 * ----------------------------------------------
 * Memuat: entitas data utama (bab 6) dan aturan bisnis pokok (bab 7).
 * Bab 1–5 disusun dari `usecase-data.js` dan `docs/dfd/dfd-data.js`, bukan
 * dari sini, karena isinya memang milik berkas-berkas itu.
 *
 * Kenapa entitas data ikut di sini dan tidak dibaca langsung dari
 * `docs/produk/5_SCHEMAS.md`. Dua alasan:
 *
 *   1. `5_SCHEMAS.md` mendokumentasikan **22 tabel**, sedangkan bab 6 template
 *      dosen meminta **entitas**, bukan tabel. `kamar_fasilitas` adalah tabel
 *      penghubung, bukan entitas yang dikenali pembaca laporan; begitu juga
 *      `tagihan_rincian`, yang tidak punya makna di luar tagihannya.
 *   2. Atribut di sini dipilih untuk pembaca laporan — dosen dan penguji —
 *      bukan untuk orang yang menulis migrasi.
 *
 * PANJANGNYA DIBATASI DENGAN SENGAJA. Template dosen memakai satu baris per
 * entitas dan hanya memuat daftar atribut — 62 kata untuk lima entitas. Karena
 * itu `lingkup` dijaga maksimal sekitar 10 kata: cukup untuk menjelaskan arti
 * satu baris, tidak sampai menjadi paragraf. Alasan panjangnya tidak dibuang
 * melainkan dipindahkan ke `docs/produk/5_SCHEMAS.md`, tempat ia memang
 * dibutuhkan dan tidak ada batas halaman.
 *
 * Yang menjaga kejujuran berkas ini: setiap entitas wajib menyebut `tabel`
 * yang benar-benar ada, dan `build-dokumen.js` memeriksa daftarnya terhadap
 * `tabel-domain.json` (potret `pg_tables`). Entitas yang menunjuk tabel hantu
 * menggagalkan build.
 */

/* ------------------------------------------------------- entitas data */

/**
 * `tabel` boleh berisi lebih dari satu nama bila satu entitas logis tersebar
 * di beberapa tabel. `inti: true` menandai tujuh entitas transaksi; sisanya
 * pendukung yang tetap perlu dijelaskan karena muncul di alur.
 */
const ENTITAS_DATA = [
  {
    nama: 'Pengguna & Peran',
    tabel: ['users', 'pengelola_kewenangan', 'token_akun'],
    inti: true,
    atribut: 'id, name, email, password, peran, wajib_ganti_sandi',
    lingkup: '',
  },
  {
    nama: 'Penghuni',
    tabel: ['penghuni'],
    inti: true,
    atribut: 'id, nama, email, no_hp',
    lingkup: '',
  },
  {
    nama: 'Kamar',
    tabel: ['kamar', 'fasilitas', 'kamar_fasilitas'],
    inti: true,
    atribut: 'id, nomor, tipe, status_huni',
    lingkup: '',
  },
  {
    nama: 'Kontrak Sewa',
    tabel: ['kontrak'],
    inti: true,
    atribut: 'id, kamar_id, penghuni_id, tgl_masuk, tarif_awal, deposit, status',
    lingkup: '',
  },
  {
    nama: 'Tarif, Komponen Biaya & Kebijakan',
    tabel: ['tarif_sewa', 'komponen_biaya', 'kebijakan_kos'],
    inti: true,
    atribut: 'id, kamar_id, nilai, satuan, tipe, masa_tenggang, berlaku_dari',
    lingkup: '',
  },
  {
    nama: 'Tagihan & Rincian',
    tabel: ['tagihan', 'tagihan_rincian'],
    inti: true,
    atribut: 'id, kontrak_id, periode, pokok, jatuh_tempo, status, tarif_terpakai, total_final',
    lingkup: '',
  },
  {
    nama: 'Pembayaran & Verifikasi',
    tabel: ['pembayaran', 'verifikasi_pembayaran'],
    inti: true,
    atribut: 'id, tagihan_id, pembalik_id, jumlah, metode, dicatat_oleh',
    lingkup: '',
  },
  {
    nama: 'Usulan Perubahan',
    tabel: ['usulan_perubahan'],
    inti: false,
    atribut: 'id, tipe, payload, status, diajukan_oleh, diputuskan_oleh',
    lingkup: '',
  },
  {
    nama: 'Log Audit',
    tabel: ['log_audit'],
    inti: false,
    atribut: 'id, aktor_id, aktor_nama, aksi, entitas, nilai_lama, nilai_baru',
    lingkup: '',
  },
  {
    nama: 'Persetujuan PDP & Preferensi Notifikasi',
    tabel: ['persetujuan_pdp', 'pengaturan_notifikasi', 'notifications'],
    inti: false,
    atribut: 'user_id, versi, disetujui_pada, peristiwa, kanal, aktif',
    lingkup: '',  },
  {
    nama: 'Rekening Tujuan Pembayaran',
    tabel: ['rekening'],
    inti: false,
    atribut: 'id, jenis, nama, nomor, atas_nama, aktif, urutan',
    lingkup: '',
  },
  {
    nama: 'Aturan Kos',
    tabel: ['aturan_kos'],
    inti: false,
    atribut: 'id, judul, isi, kategori, sanksi, aktif',
    lingkup: '',
  },
];

/* ------------------------------------------------------ aturan bisnis */

/**
 * Tujuh aturan bisnis pokok. `kode` wajib cocok dengan `ATURAN` di
 * `docs/dfd/dfd-data.js`, karena DFD sudah memetakan tiap aturan ke proses
 * yang menjaganya — dan `build-dokumen.js` memeriksa kecocokan itu. Aturan
 * yang tidak ada di DFD berarti aturan yang tidak dijaga proses mana pun.
 *
 * `konsekuensi` menjelaskan apa yang gagal bila aturan dilepas. Aturan tanpa
 * konsekuensi biasanya bukan aturan, melainkan deskripsi fitur.
 */
const ATURAN_BISNIS = [
  {
    kode: 'R-01',
    judul: 'Sewa bulan pertama prorata',
    isi: 'Tagihan bulan pertama memakai `tarif × hari_ditagih ÷ hari_dalam_bulan`.',
    konsekuensi:
      'Tanpa ini, penghuni yang masuk di akhir bulan membayar penuh untuk beberapa ' +
      'hari huni.',
  },
  {
    kode: 'R-02',
    judul: 'Satu tagihan hidup per periode',
    isi:
      'Penerbitan ulang ditolak, kecuali periode yang tagihannya sudah dibatalkan ' +
      '(REV-FIN-02).',
    konsekuensi:
      'Tanpa batas ini, satu kontrak bisa tertagih dua kali dalam satu bulan.',
  },
  {
    kode: 'R-03',
    judul: 'Denda setelah tenggang, berplafon',
    isi:
      'Mulai hari ke-8 setelah jatuh tempo, 1% pokok per hari, berhenti pada 30% ' +
      'pokok. Bentuknya dapat diubah pemilik.',
    konsekuensi:
      'Tanpa plafon, tagihan bisa melebihi pokoknya dan tidak lagi terbaca sebagai ' +
      'sanksi keterlambatan.',
  },
  {
    kode: 'R-04',
    judul: 'Tunggakan menurut umur piutang',
    isi: 'Empat kelompok: `belum_jatuh_tempo`, `0_30`, `31_60`, dan `lebih_60` hari.',
    konsekuensi:
      'Tanpa pengelompokan, daftar tunggakan tidak membedakan yang baru menunggak ' +
      'dari yang sudah lama.',
  },
  {
    kode: 'R-05',
    judul: 'Kebijakan lengkap sebelum tagihan',
    isi: 'Tanpa `satuan_sewa`, `jatuh_tempo`, dan `denda` terisi, penerbitan ditolak.',
    konsekuensi:
      'Tanpa ini, tagihan terbit tanpa jatuh tempo dan tidak bisa dihitung ulang ' +
      'karena snapshotnya kosong.',
  },
  {
    kode: 'R-06',
    judul: 'Tarif tidak berlaku surut',
    isi:
      'Perubahan tarif hanya untuk periode berikutnya; tagihan terbit memakai ' +
      'salinan yang dibekukan.',
    konsekuensi:
      'Tanpa snapshot, menaikkan tarif hari ini mengubah tagihan bulan lalu.',
  },
  {
    kode: 'R-07',
    judul: 'Penghuni hanya lihat tagihannya',
    isi:
      'Pembacaan tagihan disaring scope `Tagihan::terlihatOleh`; tagihan orang lain ' +
      'dijawab **404**, bukan 403.',
    konsekuensi:
      'Tanpa scope ini, satu nomor tagihan yang salah ketik membuka data keuangan ' +
      'penghuni lain.',
  },
];

module.exports = { ENTITAS_DATA, ATURAN_BISNIS };
