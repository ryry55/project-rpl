'use strict';

/**
 * Sumber tunggal data DFD — Sistem Informasi Operasional Kos-Kosan
 * -----------------------------------------------------------------
 * Berkas ini memuat SELURUH isi semantik diagram: entitas, proses,
 * penyimpanan data, aliran data, aturan bisnis, kamus data, dan tata letak.
 * Dua penyaji (build.js untuk Gane-Sarson, build-yourdon.js untuk
 * Yourdon/DeMarco) membaca berkas ini dan hanya berbeda pada cara menggambar.
 *
 * Kenapa dipisah: sebelumnya data dan koordinat tercampur di dua skrip, dan
 * keduanya bisa menyimpang tanpa ketahuan. README menyatakan kedua versi
 * "identik", tetapi tidak ada yang menjaminnya. Sekarang jaminan itu
 * struktural: tidak mungkin menggambar aliran yang tidak ada di sini.
 */

/* --------------------------------------------------------------- kanvas */

const KANVAS = {
  konteks: { w: 1900, h: 1000 },
  level1: { w: 2500, h: 1900 },
};

/* -------------------------------------------------------------- entitas */

/**
 * Tiga entitas eksternal. `peran` menjelaskan kedudukannya terhadap sistem
 * dan dipakai apa adanya di README — supaya tidak ada yang menulis ulang
 * alasannya secara berbeda di dua tempat.
 */
const ENTITAS = [
  {
    id: 'E1',
    nama: 'PEMILIK KOS',
    ringkas: 'Pemilik',
    peran:
      'Pemilik akun dan pemegang keputusan. Satu-satunya yang boleh menetapkan ' +
      'tarif, kebijakan, dan komponen biaya secara langsung, serta menyetujui ' +
      'usulan pengelola.',
  },
  {
    id: 'E2',
    nama: 'PENGELOLA',
    ringkas: 'Pengelola',
    peran:
      'Akun yang dibuat pemilik dan diberi wewenang terbatas. Mencatat ' +
      'pembayaran tunai yang ia terima dan mengusulkan perubahan tarif atau ' +
      'kebijakan — usulan itu baru berlaku setelah disetujui pemilik.',
  },
  {
    id: 'E3',
    nama: 'PENGHUNI',
    ringkas: 'Penghuni',
    peran:
      'Penerima informasi dan pembayar, bukan pemasok data. Portal penghuni ' +
      'hampir seluruhnya baca-saja: pendaftaran sewa dan pencatatan uang ' +
      'dilakukan pemilik atau pengelola. Satu-satunya tindakan yang ia mulai ' +
      'sendiri adalah membuka tautan bayar daring, dan itu tidak membawa data ' +
      'baru ke sistem - uangnya baru tercatat setelah gerbang pembayaran ' +
      'menyatakan settlement lewat webhook.',
  },
];

/* --------------------------------------------------------------- proses */

const PROSES = [
  {
    id: '1.0',
    kunci: 'P1',
    nama: ['Kelola Kamar,', 'Penghuni', '& Kontrak'],
    namaYourdon: ['Kelola', 'Kamar &', 'Kontrak'],
    ringkas: 'Kelola Kamar, Penghuni & Kontrak',
    aturan: [],
    keterangan:
      'Mendaftarkan kamar, menautkan penghuni, dan membuat kontrak sewa. ' +
      'Membaca kamar dan penghuni sebelum menulis kontrak — tanpa itu kontrak ' +
      'bisa menunjuk kamar yang sudah terisi.',
  },
  {
    id: '2.0',
    kunci: 'P2',
    nama: ['Kelola Tarif,', 'Kebijakan &', 'Komponen Biaya'],
    namaYourdon: ['Tarif &', 'Kebijakan'],
    ringkas: 'Kelola Tarif, Kebijakan & Komponen Biaya',
    aturan: ['R-06'],
    keterangan:
      'Menetapkan tarif, kebijakan per dimensi, dan komponen biaya. Pemilik ' +
      'berlaku langsung; pengelola hanya mengusulkan, dan usulan menunggu ' +
      'keputusan pemilik.',
  },
  {
    id: '3.0',
    kunci: 'P3',
    nama: ['Terbitkan', 'Tagihan Bulanan'],
    namaYourdon: ['Terbitkan', 'Tagihan'],
    ringkas: 'Terbitkan Tagihan Bulanan',
    // R-05 ikut di sini, bukan di 4.0: gerbang "kebijakan wajib lengkap"
    // diperiksa sebelum angka apa pun dihitung, dan yang diperiksa adalah
    // kebijakan yang dibaca proses ini.
    aturan: ['R-01', 'R-02', 'R-05'],
    keterangan:
      'Menghitung sewa prorata dan biaya tetap, lalu menerbitkan tagihan. ' +
      'Memeriksa tagihan yang sudah ada pada periode yang sama sebelum menulis.',
  },
  {
    id: '4.0',
    kunci: 'P4',
    nama: ['Catat Pembayaran,', 'Denda &', 'Tunggakan'],
    namaYourdon: ['Pembayaran', '& Denda'],
    ringkas: 'Catat Pembayaran, Denda & Tunggakan',
    aturan: ['R-03', 'R-04'],
    keterangan:
      'Mencatat uang masuk, menghitung denda dari kebijakan berlaku, dan ' +
      'memutakhirkan status tagihan. Setiap perubahan meninggalkan baris audit.',
  },
  {
    id: '5.0',
    kunci: 'P5',
    nama: ['Laporan & Rekap'],
    namaYourdon: ['Laporan', '& Rekap'],
    ringkas: 'Laporan & Rekap',
    aturan: ['R-07'],
    keterangan:
      'Menyusun rekap pendapatan, hunian, tunggakan, dan jejak audit. Hanya ' +
      'membaca — tidak pernah menulis ke penyimpanan data mana pun.',
  },
];

/* ----------------------------------------------------- penyimpanan data */

const STORE = [
  {
    id: 'D1',
    kunci: 'D1',
    nama: ['Kamar'],
    ringkas: 'Kamar',
    atribut: 'id, nomor, tipe, status_huni',
    isi:
      'Kamar beserta status huninya. Status hanya kosong atau terisi — tidak ' +
      'ada status perbaikan, karena perbaikan tidak pernah menghalangi penagihan.',
  },
  {
    id: 'D2',
    kunci: 'D2',
    nama: ['Penghuni', '& Kontrak'],
    ringkas: 'Penghuni & Kontrak',
    atribut:
      'penghuni: id, nama, no_hp, email · ' +
      'kontrak: id, kamar_id, penghuni_id, tgl_masuk, tgl_keluar, tarif_awal, status',
    isi:
      'Data penghuni dan kontrak sewanya. tarif_awal membekukan tarif yang ' +
      'berlaku ketika kontrak ditandatangani.',
  },
  {
    id: 'D3',
    kunci: 'D3',
    nama: ['Tarif, Kebijakan', '& Komponen'],
    ringkas: 'Tarif, Kebijakan & Komponen',
    atribut:
      'tarif_sewa: kamar_id, nilai, berlaku_dari · ' +
      'komponen_biaya: nama, nilai, satuan, kamar_id, berlaku_dari · ' +
      'kebijakan_kos: tipe, nilai, persen, plafon_persen, masa_tenggang, nominal, tanggal, kamar_id, berlaku_dari',
    isi:
      'Tarif sewa, kebijakan kos per dimensi, dan komponen biaya. Bersifat ' +
      'tambah-saja: perubahan membuat baris baru dengan berlaku_dari sendiri, ' +
      'baris lama tidak pernah ditimpa.',
  },
  {
    id: 'D4',
    kunci: 'D4',
    nama: ['Tagihan'],
    ringkas: 'Tagihan',
    atribut:
      'id, kontrak_id, periode, pokok, total_final, jatuh_tempo, status, ' +
      'tarif_terpakai, kebijakan_terpakai',
    isi:
      'Tagihan bulanan beserta salinan tarif dan kebijakan yang dipakai saat ' +
      'terbit. Denda tidak disimpan sebagai kolom: ia dihitung ulang setiap ' +
      'kali dibaca, dan hanya `total_final` yang dibekukan saat tagihan lunas.',
  },
  {
    id: 'D5',
    kunci: 'D5',
    nama: ['Pembayaran'],
    ringkas: 'Pembayaran',
    atribut:
      'id, tagihan_id, tanggal_pembayaran, jumlah, metode, sumber, ' +
      'diterima_oleh, dicatat_oleh',
    isi:
      'Uang yang benar-benar diterima, dicatat per tanggal pembayaran. Metode ' +
      'mencakup tunai, transfer, QRIS, dan gateway; sumber memisahkan yang ' +
      'dicatat orang dari yang datang lewat webhook.',
  },
  {
    id: 'D6',
    kunci: 'D6',
    nama: ['Log Audit'],
    ringkas: 'Log Audit',
    atribut: 'id, aktor_id, aktor_nama, aksi, entitas, entitas_id, nilai_lama, nilai_baru, dibuat_pada',
    isi:
      'Jejak setiap perubahan, ditulis tiga proses dan dibaca proses laporan. ' +
      'Bersifat tambah-saja dan dijaga trigger basis data, bukan hanya kode.',
  },
];

/* --------------------------------------------------------- aliran data */

/**
 * Seluruh aliran data. `jenis` menentukan letaknya di README:
 *   masuk    — melintasi batas sistem, dari entitas ke proses
 *   keluar   — melintasi batas sistem, dari proses ke entitas
 *   internal — antara proses dan penyimpanan data
 *
 * `kunci` dipakai sebagai penghubung ke koordinat rute.
 */
const ALIRAN = [
  /* ---- masuk ---- */
  {
    kunci: 'I-1', kode: 'I-1', jenis: 'masuk', dari: 'E1', ke: 'P1',
    label: ['data kamar, penghuni', '& kontrak'],
    struktur: 'nomor_kamar, tipe, nama, no_hp, email, tgl_masuk',
    catatan: 'Pemilik mendaftarkan kamar dan penghuni, lalu membuat kontrak.',
  },
  {
    kunci: 'I-2', kode: 'I-2', jenis: 'masuk', dari: 'E1', ke: 'P2',
    label: ['tarif, kebijakan', '& komponen biaya'],
    struktur: 'kamar_id, nilai, berlaku_dari (tarif) · tipe, nilai, angka_pendukung, kamar_id (kebijakan)',
    catatan: 'Pemilik menetapkan langsung; berlaku tanpa persetujuan siapa pun.',
  },
  {
    kunci: 'I-3', kode: 'I-3', jenis: 'masuk', dari: 'E1', ke: 'P2',
    label: ['keputusan persetujuan', 'usulan'],
    struktur: 'usulan_id, keputusan, alasan',
    catatan: 'Menutup alur maker-checker: pengelola mengusulkan, pemilik memutuskan.',
  },
  {
    kunci: 'I-4', kode: 'I-4', jenis: 'masuk', dari: 'E1', ke: 'P3',
    label: ['periode penagihan'],
    struktur: 'periode (bulan + tahun)',
    catatan:
      'Pemicu penerbitan. Tanpa aliran ini proses 3.0 tidak punya masukan ' +
      'selain data yang dibacanya sendiri.',
  },
  {
    kunci: 'I-5', kode: 'I-5', jenis: 'masuk', dari: 'E1', ke: 'P4',
    label: ['konfirmasi setoran uang'],
    struktur: 'tagihan_id, tanggal, jumlah, metode, diterima_oleh',
    catatan: 'Uang tunai yang diterima pemilik dan dicatat atas namanya.',
  },
  {
    kunci: 'I-6', kode: 'I-6', jenis: 'masuk', dari: 'E2', ke: 'P2',
    label: ['usulan perubahan', 'tarif & kebijakan'],
    struktur: 'jenis, tipe, nilai_baru, alasan, diajukan_oleh',
    catatan:
      'Pengelola tidak menetapkan, hanya mengusulkan. Usulan kedaluwarsa ' +
      'dalam 7 hari bila tidak diputuskan.',
  },
  {
    kunci: 'I-7', kode: 'I-7', jenis: 'masuk', dari: 'E2', ke: 'P4',
    label: ['pencatatan', 'pembayaran tunai'],
    struktur: 'tagihan_id, tanggal, jumlah, metode=tunai, diterima_oleh',
    catatan:
      'Pengelola mencatat uang yang ia pegang, sehingga tunai_di_tangan dapat ' +
      'dipertanggungjawabkan per akun.',
  },

  /* ---- keluar ---- */
  {
    kunci: 'O-1', kode: 'O-1', jenis: 'keluar', dari: 'P1', ke: 'E3',
    label: ['konfirmasi sewa', '& kontrak'],
    struktur: 'kontrak_id, kamar, tgl_mulai, tarif_awal',
    catatan: 'Penghuni menerima, tidak membalas - portalnya baca-saja.',
  },
  {
    kunci: 'O-2', kode: 'O-2', jenis: 'keluar', dari: 'P2', ke: 'E1',
    label: ['daftar usulan', 'menunggu persetujuan'],
    struktur: 'usulan_id, tipe, nilai_lama, nilai_baru, batas_waktu',
    catatan: 'Tanpa aliran ini usulan pengelola tidak pernah sampai ke pemilik.',
  },
  {
    kunci: 'O-3', kode: 'O-3', jenis: 'keluar', dari: 'P3', ke: 'E3',
    label: ['tagihan bulanan', '(pokok)'],
    struktur: 'periode, rincian[], pokok, total, jatuh_tempo',
    catatan:
      'Bernilai denda nol. Denda baru muncul di proses 4.0 setelah masa ' +
      'tenggang terlampaui, jadi menyebutnya di sini akan menyesatkan.',
  },
  {
    kunci: 'O-4', kode: 'O-4', jenis: 'keluar', dari: 'P3', ke: 'E2',
    label: ['daftar tagihan terbit'],
    struktur: 'periode, daftar_tagihan[], jumlah_terbit',
    catatan: 'Pengelola perlu tahu apa yang sudah ditagih agar tidak menagih ganda.',
  },
  {
    kunci: 'O-5', kode: 'O-5', jenis: 'keluar', dari: 'P4', ke: 'E3',
    label: ['kuitansi &', 'status pelunasan'],
    struktur: 'pembayaran_id, tanggal, jumlah, sisa_tunggakan',
    catatan: 'Penghuni melihat kuitansi dan sisa tagihannya di portal.',
  },
  {
    kunci: 'O-6', kode: 'O-6', jenis: 'keluar', dari: 'P5', ke: 'E1',
    label: ['rekap pendapatan', '& hunian'],
    struktur: 'periode, total_pendapatan, jumlah_transaksi, occupancy_rate',
    catatan: 'Terpisah dari O-7 supaya jumlah aliran level 0 dan level 1 seimbang.',
  },
  {
    kunci: 'O-7', kode: 'O-7', jenis: 'keluar', dari: 'P5', ke: 'E1',
    label: ['daftar tunggakan', '& log audit'],
    struktur: 'daftar_tunggakan[], kategori_umur, baris_audit[]',
    catatan: 'Membawa log audit keluar dari D6, sehingga D6 tidak jadi black hole.',
  },

  /* ---- proses ke penyimpanan ---- */
  {
    kunci: 'A1', kode: '10', jenis: 'internal', dari: 'P1', ke: 'D1',
    label: ['data kamar'],
    struktur: 'nomor, tipe, status_huni',
    catatan: '',
  },
  {
    kunci: 'A2', kode: '11', jenis: 'internal', dari: 'P1', ke: 'D2',
    label: ['kontrak sewa'],
    struktur: 'kontrak_id, penghuni, kamar, tgl_masuk, tarif_awal',
    catatan: '',
  },
  {
    kunci: 'A3', kode: '12', jenis: 'internal', dari: 'P2', ke: 'D3',
    label: ['tarif, kebijakan', '& komponen'],
    struktur: 'tarif: kamar_id, nilai, berlaku_dari · kebijakan: tipe, nilai, angka, kamar_id, berlaku_dari',
    catatan: '',
  },
  {
    kunci: 'A4', kode: '13', jenis: 'internal', dari: 'P2', ke: 'D6',
    label: ['log audit perubahan'],
    struktur: 'aktor, aksi, nilai_lama, nilai_baru, waktu',
    catatan: 'Perubahan tarif dan kebijakan ikut diaudit, bukan hanya transaksi uang.',
  },
  {
    kunci: 'A5', kode: '14', jenis: 'internal', dari: 'P3', ke: 'D4',
    label: ['tagihan'],
    struktur: 'periode, pokok, jatuh_tempo, status, tarif_terpakai, kebijakan_terpakai',
    catatan: '',
  },
  {
    kunci: 'A6', kode: '15', jenis: 'internal', dari: 'P3', ke: 'D6',
    label: ['log audit penerbitan'],
    struktur: 'aktor, aksi, tagihan_id, waktu',
    catatan: '',
  },
  {
    kunci: 'A7', kode: '16', jenis: 'internal', dari: 'P4', ke: 'D4',
    label: ['status tagihan', '& total final'],
    struktur: 'status, total_final, jatuh_tempo',
    catatan:
      'Tanpa panah ini tagihan yang sudah dibayar tetap tampak belum bayar di diagram.',
  },
  {
    kunci: 'A8', kode: '17', jenis: 'internal', dari: 'P4', ke: 'D5',
    label: ['pembayaran'],
    struktur: 'tagihan_id, tanggal_pembayaran, jumlah, metode, sumber',
    catatan: '',
  },
  {
    kunci: 'A9', kode: '18', jenis: 'internal', dari: 'P4', ke: 'D6',
    label: ['log audit pembayaran'],
    struktur: 'aktor, aksi, pembayaran_id, waktu',
    catatan: '',
  },

  /* ---- penyimpanan ke proses ---- */
  {
    kunci: 'B1', kode: '19', jenis: 'internal', dari: 'D1', ke: 'P1',
    label: ['ketersediaan kamar'],
    struktur: 'kamar_id, status_huni',
    catatan: 'Kontrak hanya boleh menunjuk kamar yang masih kosong.',
  },
  {
    kunci: 'B2', kode: '20', jenis: 'internal', dari: 'D2', ke: 'P1',
    label: ['identitas penghuni'],
    struktur: 'penghuni_id, nama, no_hp, email',
    catatan: '',
  },
  {
    kunci: 'B3', kode: '21', jenis: 'internal', dari: 'D2', ke: 'P3',
    label: ['kontrak aktif'],
    struktur: 'kontrak_id, kamar, tgl_masuk, tarif_awal',
    catatan: '',
  },
  {
    kunci: 'B4', kode: '22', jenis: 'internal', dari: 'D3', ke: 'P3',
    label: ['tarif & kebijakan', 'berlaku'],
    struktur: 'tipe, nilai, angka_pendukung, berlaku_dari, kamar_id',
    catatan: 'Hanya baris dengan berlaku_dari paling akhir yang dipakai.',
  },
  {
    kunci: 'B5', kode: '23', jenis: 'internal', dari: 'D4', ke: 'P3',
    label: ['validasi duplikasi', 'periode'],
    struktur: 'kontrak_id, periode',
    catatan:
      'R-02: satu kontrak hanya boleh punya satu tagihan per periode. Proses ' +
      '3.0 wajib membacanya, bukan mengandalkan indeks basis data saja.',
  },
  {
    kunci: 'B6', kode: '24', jenis: 'internal', dari: 'D3', ke: 'P4',
    label: ['kebijakan denda'],
    struktur: 'bentuk_denda, tarif_denda_persen, plafon_persen, masa_tenggang',
    catatan: 'Denda tidak punya tabel parameter sendiri sejak kebijakan_kos dipakai.',
  },
  {
    kunci: 'B7', kode: '25', jenis: 'internal', dari: 'D4', ke: 'P4',
    label: ['tagihan belum lunas'],
    struktur: 'tagihan_id, pokok, jatuh_tempo, status',
    catatan: '',
  },
  {
    kunci: 'B8', kode: '26', jenis: 'internal', dari: 'D5', ke: 'P4',
    label: ['riwayat pembayaran'],
    struktur: 'tagihan_id, jumlah, tanggal_pembayaran',
    catatan: 'Dibutuhkan untuk menghitung sisa tagihan pada pembayaran bertahap.',
  },

  /* ---- penyimpanan ke proses 5.0 ---- */
  {
    kunci: 'C1', kode: '27', jenis: 'internal', dari: 'D1', ke: 'P5',
    label: ['data kamar'],
    struktur: 'nomor, status_huni',
    catatan: '',
  },
  {
    kunci: 'C2', kode: '28', jenis: 'internal', dari: 'D2', ke: 'P5',
    label: ['kontrak'],
    struktur: 'kamar, penghuni, periode',
    catatan: '',
  },
  {
    kunci: 'C3', kode: '29', jenis: 'internal', dari: 'D4', ke: 'P5',
    label: ['tagihan'],
    struktur: 'periode, pokok, denda, status',
    catatan: '',
  },
  {
    kunci: 'C4', kode: '30', jenis: 'internal', dari: 'D5', ke: 'P5',
    label: ['pembayaran'],
    struktur: 'tanggal_pembayaran, jumlah, metode',
    catatan: '',
  },
  {
    kunci: 'C5', kode: '31', jenis: 'internal', dari: 'D6', ke: 'P5',
    label: ['log audit'],
    struktur: 'aktor, aksi, entitas, waktu',
    catatan: 'Pembacaan inilah yang membuat D6 berhenti menjadi black hole.',
  },
];

/* -------------------------------------------------------------- aturan */

const ATURAN = [
  {
    kode: 'R-01',
    aturan:
      'Sewa bulan pertama dihitung prorata: tarif x hari_ditagih / hari_dalam_bulan',
    proses: '3.0',
    uji: 'hari 1, 15, 28 (Feb), 29 (kabisat), 31',
  },
  {
    kode: 'R-02',
    aturan:
      'Satu kontrak hanya boleh punya satu tagihan per periode; terbit ulang ditolak',
    proses: '3.0',
    uji: 'periode sama / berbeda / setelah dibatalkan',
  },
  {
    kode: 'R-03',
    aturan:
      'Denda mulai hari ke-8 setelah jatuh tempo, 1% dari pokok per hari, plafon 30% dari pokok',
    proses: '4.0',
    uji: 'selisih hari 7 / 8, hari ke-37 (plafon tercapai), hari setelah plafon',
  },
  {
    kode: 'R-04',
    aturan:
      'Tunggakan dikelompokkan belum_jatuh_tempo / 0-30 / 31-60 / lebih dari 60 hari',
    proses: '4.0',
    uji: 'hari -1 (belum jatuh tempo), 0, 30 / 31, 60 / 61',
  },
  {
    kode: 'R-05',
    aturan:
      'Kebijakan wajib lengkap sebelum tagihan pertama: tanpa satuan sewa, jatuh tempo, dan denda, penerbitan ditolak',
    proses: '3.0',
    uji: 'terbit sebelum lengkap / sesudah lengkap / kamar berpenimpa',
  },
  {
    kode: 'R-06',
    aturan:
      'Tarif tidak berlaku surut - perubahan hanya untuk periode berikutnya, tagihan lama memakai salinan saat terbit',
    proses: '2.0',
    uji: 'transaksi sebelum / sesudah perubahan',
  },
  {
    kode: 'R-07',
    aturan:
      'Penghuni hanya melihat tagihannya sendiri; setiap perubahan status tercatat',
    proses: '4.0, 5.0',
    uji: 'lintas akun',
  },
];

/* --------------------------------------------------------- kamus data */

/**
 * Kamus data logis. Ditulis sebagai baris terpisah, bukan satu blok teks,
 * supaya README bisa menyusunnya menjadi blok kode tanpa salah indentasi.
 * Baris dengan nama kosong adalah lanjutan baris sebelumnya.
 */
const KAMUS = [
  ['kamar', 'nomor_kamar + tipe + status_huni'],
  ['status_huni', '"kosong" | "terisi"'],
  ['penghuni', 'nama + no_hp + email'],
  ['kontrak', 'kontrak_id + penghuni + kamar + tgl_masuk + tgl_keluar + tarif_awal + status_kontrak'],
  ['status_kontrak', '"aktif" | "selesai"'],
  ['tgl_masuk', 'tanggal'],
  ['tgl_keluar', 'tanggal | kosong (berarti masih aktif)'],
  ['tarif_sewa', 'kamar_id + nilai + berlaku_dari'],
  ['komponen_biaya', 'nama + nilai + satuan + kamar_id + berlaku_dari'],
  ['satuan_komponen', '"rupiah/bulan" | "rupiah/orang"'],
  ['kebijakan_kos', 'tipe + nilai + angka_pendukung + kamar_id + berlaku_dari'],
  ['angka_pendukung', 'persen + plafon_persen + masa_tenggang + nominal + tanggal'],
  ['tipe_kebijakan', '"satuan_sewa" | "jatuh_tempo" | "denda"'],
  ['bentuk_denda', '"persen_harian" | "persen_bulanan" | "nominal_tetap" | "tanpa_denda"'],
  ['persen_denda', 'persen per hari atau per bulan (0..100), dihitung dari pokok'],
  ['plafon_persen', '0..100'],
  ['masa_tenggang', 'jumlah_hari'],
  ['tagihan', 'tagihan_id + kontrak_id + periode + pokok + total_final + jatuh_tempo'],
  ['', '+ status_tagihan + tarif_terpakai + kebijakan_terpakai'],
  ['periode', 'bulan + tahun'],
  ['rincian', 'tagihan_rincian: { nama + nilai + urutan } +'],
  ['pokok', 'jumlah(rincian.nilai)'],
  ['sewa_prorata', 'bulat(tarif_dasar * hari_ditagih / hari_dalam_bulan)'],
  ['total_final', 'pokok + denda, dibekukan tepat saat tagihan menjadi lunas'],
  ['hari_denda', 'max(0, selisih - masa_tenggang)'],
  ['denda', '0  jika selisih <= masa_tenggang'],
  ['', '= min(bulat(pokok * hari_denda * persen_denda / 100),'],
  ['', '      bulat(pokok * plafon_persen / 100))  jika selisih > masa_tenggang'],
  ['selisih', 'hari_ini - jatuh_tempo (selisih tanggal kalender, zona Asia/Jakarta)'],
  ['status_tagihan', '"belum_bayar" | "sebagian" | "lunas" | "dibatalkan"'],
  ['pembayaran', 'pembayaran_id + tagihan_id + tanggal + jumlah + metode + sumber'],
  ['', '+ diterima_oleh + dicatat_oleh + referensi'],
  ['metode', '"tunai" | "transfer" | "qris" | "gateway"'],
  ['sumber', '"dicatat_pemilik" | "gateway_webhook"'],
  ['log_audit', 'aktor + aksi + entitas + entitas_id + waktu + nilai_lama + nilai_baru'],
  ['kategori_umur', '"belum_jatuh_tempo" | "0_30" | "31_60" | "lebih_60"'],
];

/* ------------------------------------------------------------ tata letak */

/**
 * Kotak pada DFD Level 1. Dipakai bersama oleh kedua penyaji: Gane-Sarson
 * menggambar persegi sudut tumpul di dalamnya, Yourdon menggambar elips yang
 * mengisi kotak itu.
 *
 * Tata letaknya berlapis menurut arah data, bukan sekadar berjajar:
 *
 *   y  70-258    lajur kabel di atas entitas masuk
 *   y  130-215   entitas masuk (Pemilik, Pengelola)
 *   y  650-810   proses 1.0-4.0
 *   y  950-1040  penyimpanan D1-D6
 *   y 1090-1290  lajur kabel menuju proses 5.0
 *   y 1420-1570  proses 5.0 (laporan)
 *   x 2080-2330  entitas keluar (Penghuni) dengan lajur kabel di kirinya
 *
 * Memisahkan entitas masuk (atas) dari entitas keluar (kanan) membuat aliran
 * tidak perlu saling silang. Sebelumnya Penghuni diletakkan di antara Pemilik
 * dan Pengelola, sehingga panah dari proses 1.0, 3.0, dan 4.0 harus melewati
 * badan entitas lain untuk mencapainya.
 */
const KOTAK_LEVEL1 = {
  E1: { x: 100, y: 130, w: 250, h: 85 },
  E2: { x: 1620, y: 130, w: 250, h: 85 },
  E3: { x: 2080, y: 480, w: 250, h: 300 },

  P1: { x: 100, y: 650, w: 250, h: 160 },
  P2: { x: 480, y: 650, w: 250, h: 160 },
  P3: { x: 860, y: 650, w: 250, h: 160 },
  P4: { x: 1240, y: 650, w: 250, h: 160 },
  P5: { x: 860, y: 1660, w: 620, h: 150 },

  D1: { x: 100, y: 1150, w: 250, h: 90 },
  D2: { x: 430, y: 1150, w: 250, h: 90 },
  D3: { x: 760, y: 1150, w: 250, h: 90 },
  D4: { x: 1090, y: 1150, w: 250, h: 90 },
  D5: { x: 1420, y: 1150, w: 250, h: 90 },
  D6: { x: 1750, y: 1150, w: 250, h: 90 },
};

/**
 * Rute tiap aliran pada DFD Level 1: rangkaian titik ortogonal. Titik
 * pertama menempel pada sisi kotak asal, titik terakhir pada sisi kotak
 * tujuan. `at` adalah pusat label.
 *
 * Kanal antara baris proses (bawah y=810) dan baris penyimpanan (atas
 * y=1150) sengaja diberi tinggi 340 px. Kanal sempit 140 px membuat 17 label
 * saling menimpa sampai tidak terbaca; label dua baris setinggi ~40 px, jadi
 * jarak antar-lajur di sini 25-50 px dan tiap label mendapat daerah sendiri.
 *
 * Lajur tegak memakai CELAH antar-penyimpanan (x 350-430, 680-760,
 * 1010-1090, 1340-1420, 1670-1750) supaya tidak memotong badan kotak.
 */
const RUTE_LEVEL1 = {
  /* ---- entitas masuk ke proses ---- */
  'I-1': { titik: [[225, 215], [225, 650]], at: [225, 430] },
  'I-2': { titik: [[350, 172], [400, 172], [400, 560], [605, 560], [605, 650]], at: [470, 560] },
  'I-3': { titik: [[300, 130], [300, 100], [570, 100], [570, 650]], at: [455, 100] },
  'I-4': { titik: [[250, 130], [250, 70], [985, 70], [985, 650]], at: [620, 70] },
  'I-5': { titik: [[350, 190], [460, 190], [460, 232], [1365, 232], [1365, 650]], at: [900, 232] },
  'I-6': { titik: [[1745, 215], [1745, 258], [700, 258], [700, 650]], at: [1200, 258] },
  'I-7': { titik: [[1620, 172], [1400, 172], [1400, 650]], at: [1510, 172] },

  /* ---- proses ke entitas keluar (Penghuni, sisi kanan) ---- */
  'O-1': { titik: [[350, 810], [350, 840], [2070, 840], [2070, 530], [2080, 530]], at: [1100, 840] },
  'O-3': { titik: [[1060, 810], [1060, 1265], [2030, 1265], [2030, 620], [2080, 620]], at: [1550, 1265] },
  'O-5': { titik: [[1400, 810], [1400, 1305], [2000, 1305], [2000, 710], [2080, 710]], at: [1750, 1305] },

  /* ---- proses ke entitas (Pemilik, Pengelola) ---- */
  'O-2': { titik: [[480, 700], [440, 700], [440, 232], [150, 232], [150, 215]], at: [300, 232] },
  'O-4': { titik: [[1110, 700], [1110, 190], [1620, 190]], at: [1360, 190] },

  /* ---- proses 5.0 ke Pemilik: DUA aliran terpisah.
     Sebelumnya keduanya digabung menjadi satu panah, sehingga kata
     "peringatan" pada level 0 hilang dan jumlah aliran batas sistem menjadi
     tidak seimbang (5 keluar di level 0 vs 4 di level 1). ---- */
  'O-6': { titik: [[860, 1690], [620, 1690], [620, 1560], [60, 1560], [60, 200], [100, 200]], at: [400, 1560] },
  'O-7': { titik: [[860, 1760], [580, 1760], [580, 1800], [40, 1800], [40, 170], [100, 170]], at: [400, 1800] },

  /* ---- proses ke penyimpanan ---- */
  A1: { titik: [[225, 810], [225, 1150]], at: [225, 880] },
  A2: { titik: [[300, 810], [300, 860], [555, 860], [555, 1150]], at: [430, 860] },
  A3: { titik: [[605, 810], [605, 885], [885, 885], [885, 1150]], at: [745, 885] },
  A4: { titik: [[700, 810], [700, 835], [1885, 835], [1885, 1150]], at: [1300, 835] },
  A5: { titik: [[960, 810], [960, 935], [1215, 935], [1215, 1150]], at: [1090, 935] },
  A6: { titik: [[1000, 810], [1000, 910], [1830, 910], [1830, 1150]], at: [1450, 910] },
  A7: { titik: [[1320, 810], [1320, 1150]], at: [1320, 960] },
  A8: { titik: [[1440, 810], [1440, 1150]], at: [1440, 1010] },
  A9: { titik: [[1490, 810], [1490, 1035], [1945, 1035], [1945, 1150]], at: [1720, 1035] },

  /* ---- penyimpanan ke proses ---- */
  B1: { titik: [[150, 1150], [150, 810]], at: [150, 930] },
  B2: { titik: [[470, 1150], [470, 1085], [330, 1085], [330, 810]], at: [400, 1085] },
  B3: { titik: [[505, 1150], [505, 1060], [940, 1060], [940, 810]], at: [720, 1060] },
  B4: { titik: [[900, 1150], [900, 810]], at: [900, 985] },
  B5: { titik: [[1110, 1150], [1110, 1010], [1080, 1010], [1080, 810]], at: [1120, 1010] },
  B6: { titik: [[830, 1150], [830, 1110], [1360, 1110], [1360, 810]], at: [1100, 1110] },
  B7: { titik: [[1190, 1150], [1190, 780], [1240, 780]], at: [1190, 1060] },
  B8: { titik: [[1535, 1150], [1535, 1135], [1440, 1135], [1440, 810]], at: [1500, 1135] },

  /* ---- penyimpanan ke proses 5.0 ---- */
  C1: { titik: [[225, 1240], [225, 1560], [900, 1560], [900, 1660]], at: [560, 1560] },
  C2: { titik: [[555, 1240], [555, 1610], [1000, 1610], [1000, 1660]], at: [780, 1610] },
  C3: { titik: [[1215, 1240], [1215, 1660]], at: [1215, 1500] },
  C4: { titik: [[1545, 1240], [1545, 1460], [1200, 1460], [1200, 1660]], at: [1380, 1460] },
  C5: { titik: [[1885, 1240], [1885, 1410], [1350, 1410], [1350, 1660]], at: [1620, 1410] },
};

/**
 * Kotak pada DFD Konteks.
 *
 * Sisi kiri memuat 8 aliran (5 masuk + 3 keluar) dan harus muat di dalam
 * tinggi proses 0.0 (y 190-670). Jarak antar-aliran 60 px dan seluruhnya
 * berada di dalam kotak — sebelumnya satu aliran diletakkan di y=695, di luar
 * kotak proses, sehingga panahnya menggantung di ruang kosong.
 *
 * Arah panah ditentukan urutan titik: titik pertama di sisi asal. Aliran
 * keluar (O-*) karena itu dimulai dari sisi kanan menuju kiri.
 */
const KOTAK_KONTEKS = {
  E1: { x: 80, y: 150, w: 240, h: 560 },
  P0: { x: 700, y: 190, w: 460, h: 480 },
  E2: { x: 1500, y: 150, w: 240, h: 240 },
  E3: { x: 1500, y: 470, w: 240, h: 240 },
};

const RUTE_KONTEKS = {
  /* ---- sisi kiri: Pemilik ---- */
  'I-1': { titik: [[320, 210], [700, 210]], at: [510, 210] },
  'I-2': { titik: [[320, 270], [700, 270]], at: [510, 270] },
  'I-3': { titik: [[320, 330], [700, 330]], at: [510, 330] },
  'I-4': { titik: [[320, 390], [700, 390]], at: [510, 390] },
  'I-5': { titik: [[320, 450], [700, 450]], at: [510, 450] },
  'O-2': { titik: [[700, 510], [320, 510]], at: [510, 510] },
  'O-6': { titik: [[700, 570], [320, 570]], at: [510, 570] },
  'O-7': { titik: [[700, 630], [320, 630]], at: [510, 630] },

  /* ---- sisi kanan: Pengelola (atas) ---- */
  'I-6': { titik: [[1500, 230], [1160, 230]], at: [1330, 230] },
  'I-7': { titik: [[1500, 310], [1160, 310]], at: [1330, 310] },
  'O-4': { titik: [[1160, 370], [1500, 370]], at: [1330, 370] },

  /* ---- sisi kanan: Penghuni (bawah) ---- */
  'O-1': { titik: [[1160, 510], [1500, 510]], at: [1330, 510] },
  'O-3': { titik: [[1160, 580], [1500, 580]], at: [1330, 580] },
  'O-5': { titik: [[1160, 650], [1500, 650]], at: [1330, 650] },
};

module.exports = {
  KANVAS,
  ENTITAS,
  PROSES,
  STORE,
  ALIRAN,
  ATURAN,
  KAMUS,
  KOTAK_LEVEL1,
  RUTE_LEVEL1,
  KOTAK_KONTEKS,
  RUTE_KONTEKS,
};
