// Data untuk Bab 4 — Implementasi dan Pengujian.
//
// SUSUNAN MENGIKUTI LAPORAN REFERENSI (mata kuliah Sistem Basis Data):
//   4.1.1 Basis Data
//         1. Unnormalized Form (NF0)
//         2. First Normal Form (1NF)
//         3. Second Normal Form (2NF)
//         4. Third Normal Form (3NF) — hasil akhir
//         Entity Relationship Diagram (ERD)
//         Logical Record Structure (LRS)
//   4.1.2 Spesifikasi Data  (tabel contoh per entitas)
//   4.2   Pendukung
//
// Angka dan nama tabel di sini TIDAK dikarang: seluruhnya diambil dari
// docs/erd/skema.json (24 tabel, 209 kolom) dan dari seeder aplikasi.
// Yang berupa contoh data diberi keterangan "contoh" supaya tidak dikira
// potret basis data sungguhan.

/* ---------------------------------------------------------------- 4.1.1 */

// Empat bentuk normalisasi. Setiap langkah menjawab satu masalah yang
// disebutkan di kolom `masalah`, bukan sekadar "lalu dipecah".
const NORMALISASI = [
  {
    nomor: 1,
    judul: 'Unnormalized Form (NF0)',
    paragraf:
      'Data transaksi harian dikumpulkan apa adanya, mengikuti bentuk pembukuan ' +
      'yang dipakai pemilik kos sebelum sistem ini ada: satu buku, satu baris per ' +
      'pembayaran, tanpa aturan. Terlihat data penghuni, kamar, dan fasilitas terus ' +
      'berulang di setiap baris, dan satu sel memuat lebih dari satu nilai.',
    kolom: [
      'No',
      'Nama Penghuni',
      'No. HP',
      'Kamar',
      'Tarif',
      'Fasilitas Kamar',
      'Bulan',
      'Status',
      'Dibayar',
    ],
    baris: [
      ['1', 'Budi Santoso', '081200000001', 'A-01', '1.500.000', 'AC, Kamar mandi dalam, Wi-Fi', '2026-01', 'Lunas', '1.500.000'],
      ['2', 'Budi Santoso', '081200000001', 'A-01', '1.500.000', 'AC, Kamar mandi dalam, Wi-Fi', '2026-02', 'Lunas', '1.500.000'],
      ['3', 'Sari Dewi', '081200000002', 'A-02', '1.500.000', 'AC, Lemari', '2026-01', 'Belum', '0'],
      ['4', 'Sari Dewi', '081200000002', 'A-02', '1.500.000', 'AC, Lemari', '2026-02', 'Lunas', '1.500.000'],
      ['5', 'Budi Santoso', '081200000001', 'A-01', '1.500.000', 'AC, Kamar mandi dalam, Wi-Fi', '2026-03', 'Lunas', '1.500.000'],
    ],
    masalah: [
      '**Kelompok data berulang.** Satu sel "Fasilitas Kamar" memuat tiga nilai ' +
        '(AC, Kamar mandi dalam, Wi-Fi). Sel seperti ini tidak bisa dicari, diurutkan, ' +
        'atau dihitung — "berapa kamar yang punya AC?" tidak bisa dijawab tanpa membaca ' +
        'seluruh isi sel.',
      '**Redundansi.** Nama, nomor HP, dan tarif Budi diketik ulang setiap bulan dan ' +
        'setiap kali ia membayar. Pada 30 kamar × 12 bulan, nama pemilik yang sama ' +
        'tersimpan 12 kali.',
      '**Anomali pembaruan.** Nomor HP Sari berubah. Kalau hanya satu baris yang ' +
        'diperbaiki, dua baris lain menyimpan nomor lama — dan tidak ada cara menentukan ' +
        'mana yang benar.',
    ],
    gambar: 'Gambar 4.1 Unnormalized Form',
  },
  {
    nomor: 2,
    judul: 'First Normal Form (1NF)',
    paragraf:
      '1NF mewajibkan setiap kolom hanya berisi satu nilai dan tidak ada kelompok ' +
      'data yang berulang dalam satu baris. Kolom "Fasilitas Kamar" dipecah sehingga ' +
      'setiap fasilitas menempati barisnya sendiri. Hasilnya memang lebih panjang — ' +
      'inilah harga kejujuran data: satu baris kini berarti satu fakta, bukan tiga.',
    kolom: [
      'No',
      'Nama Penghuni',
      'No. HP',
      'Kamar',
      'Tarif',
      'Fasilitas',
      'Bulan',
      'Status',
      'Dibayar',
    ],
    baris: [
      ['1', 'Budi Santoso', '081200000001', 'A-01', '1.500.000', 'AC', '2026-01', 'Lunas', '1.500.000'],
      ['2', 'Budi Santoso', '081200000001', 'A-01', '1.500.000', 'Kamar mandi dalam', '2026-01', 'Lunas', '1.500.000'],
      ['3', 'Budi Santoso', '081200000001', 'A-01', '1.500.000', 'Wi-Fi', '2026-01', 'Lunas', '1.500.000'],
      ['4', 'Sari Dewi', '081200000002', 'A-02', '1.500.000', 'AC', '2026-01', 'Belum', '0'],
      ['5', 'Sari Dewi', '081200000002', 'A-02', '1.500.000', 'Lemari', '2026-01', 'Belum', '0'],
      ['6', 'Sari Dewi', '081200000002', 'A-02', '1.500.000', 'AC', '2026-02', 'Lunas', '1.500.000'],
    ],
    masalah: [
      '**Masalah yang sudah hilang.** Setiap sel kini bernilai tunggal, sehingga ' +
        'pertanyaan "berapa kamar yang punya AC?" bisa dijawab dengan satu query.',
      '**Masalah yang masih tersisa.** Redundansi belum hilang — justru bertambah. ' +
        'Budi sekarang punya tiga baris untuk satu bulan yang sama, dan nama, HP, serta ' +
        'tarif tetap diketik ulang di ketiganya. Satu pembayaran senilai 1.500.000 ' +
        'terbaca tiga kali, jadi total pendapatan bisa dihitung tiga kali lipat kalau ' +
        'salah menjumlahkan kolom "Dibayar".',
    ],
    gambar: 'Gambar 4.2 Normal Form 1',
  },
  {
    nomor: 3,
    judul: 'Second Normal Form (2NF)',
    paragraf:
      '2NF menghilangkan ketergantungan sebagian: setiap atribut non-kunci harus ' +
      'bergantung pada SELURUH kunci primer, bukan hanya sebagian. Setelah 1NF, kunci ' +
      'baris ini sebenarnya gabungan (kamar, bulan) — dan nama penghuni hanya bergantung ' +
      'pada kamar, tidak pada bulan. Karena itu data penghuni dan kamar dipisahkan ' +
      'menjadi tabel tersendiri, dan yang tersisa hanyalah fakta yang benar-benar ' +
      'melekat pada kombinasi kamar–bulan, yaitu status dan jumlah pembayaran.',
    kolom: ['Relasi', 'Kunci Primer', 'Atribut Non-Kunci', 'Ketergantungan'],
    baris: [
      ['penghuni', 'penghuni_id', 'nama, no_hp, email', 'penuh'],
      ['kamar', 'kamar_id', 'nomor, tipe, status_huni', 'penuh'],
      ['tagihan', '(kamar_id, periode)', 'status, tgl_bayar, jumlah_bayar', 'penuh'],
    ],
    masalah: [
      '**Ketergantungan sebagian yang hilang.** `nama` dan `no_hp` sekarang hanya ' +
        'tersimpan sekali per penghuni, bukan sekali per pembayaran. Mengubah nomor HP ' +
        'cukup satu baris, dan tidak mungkin lagi ada dua versi nomor yang berbeda.',
      '**Yang tetap tinggal, dan disengaja.** `tarif` masih menempel pada kamar, padahal ' +
        'tarif bisa berubah tanpa kamarnya berubah. Selama ia disimpan di tabel kamar, ' +
        'kenaikan tarif akan menimpa sejarah: tagihan lama ikut terbaca dengan tarif baru. ' +
        'Itu bukan pelanggaran 2NF, melainkan ketergantungan transitif — dan itulah yang ' +
        'dibereskan 3NF.',
    ],
    gambar: 'Gambar 4.3 Normal Form 2',
  },
  {
    nomor: 4,
    judul: 'Third Normal Form (3NF) — Hasil Akhir',
    paragraf:
      '3NF mewajibkan setiap kolom hanya bergantung pada kunci primer, dan tidak ' +
      'bergantung pada kolom lain yang bukan kunci. Dua ketergantungan transitif ' +
      'dipindahkan: (a) tarif bergantung pada kamar DAN tanggal berlaku, sehingga ia ' +
      'menjadi tabel riwayat `tarif_sewa`; (b) fasilitas adalah milik banyak kamar dan ' +
      'satu kamar punya banyak fasilitas, sehingga ia tidak boleh menempel di salah ' +
      'satunya. Hasil akhirnya adalah 24 tabel yang benar-benar dipakai aplikasi.',
    kolom: ['Relasi', 'Kunci Primer', 'Kunci Asing', 'Atribut Non-Kunci'],
    baris: [
      ['tarif_sewa', 'id', 'kamar_id', 'nilai, berlaku_dari'],
      ['fasilitas', 'id', '—', 'nama, ikon, urutan'],
      ['kamar_fasilitas', 'id', 'kamar_id, fasilitas_id', '— (pasangan kamar–fasilitas unik)'],
      ['kontrak', 'id', 'kamar_id, penghuni_id', 'tgl_masuk, tgl_keluar, tarif_awal, status'],
      ['pembayaran', 'id', 'tagihan_id, dicatat_oleh', 'jumlah, tanggal_pembayaran, metode, sumber'],
      ['users', 'id', 'penghuni_id, pemilik_id', 'name, email, password, peran'],
    ],
    masalah: [
      '**Tarif tidak lagi menimpa sejarah.** `tarif_sewa` menyimpan tarif beserta ' +
        'tanggal berlakunya. Tagihan yang terbit bulan lalu tetap menunjuk tarif yang ' +
        'berlaku saat itu — inilah sebabnya tabel `tagihan` menyimpan `tarif_terpakai` ' +
        'sebagai potret, bukan sekadar menunjuk kamar.',
      '**Fasilitas tidak lagi diulang.** Tabel `kamar_fasilitas` menyelesaikan hubungan ' +
        'banyak-ke-banyak tanpa menyalin nama fasilitas ke setiap kamar.',
      '**Identitas dipisah dari data penghuni.** Tabel `users` menyimpan kredensial, ' +
        '`penghuni` menyimpan identitas. Pemisahan ini yang memungkinkan pemilik tidak ' +
        'pernah mengetahui kata sandi penghuni: yang ia buat adalah tautan aktivasi, ' +
        'bukan kata sandi.',
      '**Pembayaran dipisah dari tagihan.** Satu tagihan bisa dibayar beberapa kali ' +
        'sebagian. Kalau jumlah bayar disimpan di tabel tagihan, cicilan tidak mungkin ' +
        'dicatat tanpa menghapus riwayat sebelumnya.',
    ],
    gambar: 'Gambar 4.4 Normal Form 3',
  },
]

// 24 tabel hasil normalisasi, dikelompokkan menurut perannya.
const TABEL_AKHIR = [
  { kelompok: 'Data master', tabel: ['users', 'penghuni', 'kamar', 'laporan_kerusakan', 'fasilitas', 'kamar_fasilitas', 'tarif_sewa', 'komponen_biaya', 'rekening'] },
  { kelompok: 'Kebijakan dan aturan', tabel: ['kebijakan_kos', 'aturan_kos'] },
  { kelompok: 'Transaksi', tabel: ['kontrak', 'tagihan', 'tagihan_rincian', 'pembayaran', 'verifikasi_pembayaran'] },
  { kelompok: 'Gerbang pembayaran', tabel: ['transaksi_gateway'] },
  { kelompok: 'Tata kelola dan jejak', tabel: ['pengelola_kewenangan', 'usulan_perubahan', 'log_audit', 'persetujuan_pdp'] },
  { kelompok: 'Penunjang sistem', tabel: ['token_akun', 'pengaturan_notifikasi', 'notifications'] },
]

/* ---------------------------------------------------------------- gambar */

// ERD memakai tingkat logikal: Chen, 12 entitas, dengan atribut kunci.
//
// LRS digambar dalam SATU bagan berisi SELURUH 209 kolom, dan diserahkan
// sebagai lampiran A3 terpisah - bukan ditempel di badan laporan A4.
//
// Ini hasil pengukuran seluruh kemungkinan (ambang baca 2,52 mm):
//
//   kolom | A4 tegak | A4 miring | A3 tegak | A3 miring
//     2   |  1,06 mm |  0,75 mm  |  1,50 mm |  1,06 mm
//     3   |  1,53 mm |  1,08 mm  |  2,16 mm |  1,53 mm
//     4   |  1,94 mm |  1,37 mm  | **2,74 mm** |  1,94 mm   <-- dipakai
//     5   |  1,72 mm |  1,69 mm  |  2,43 mm |  2,39 mm
//     6   |  1,41 mm |  1,87 mm  |  1,99 mm |  2,65 mm
//
// Seluruh varian A4 gagal; yang terbaik 1,94 mm. Pada A3 tegak dengan empat
// kolom hasilnya 2,74 mm. Karena itu lembar ini TIDAK ditempel di badan
// laporan: A3 yang diskalakan ke lebar A4 kembali menjadi 1,94 mm.
const GAMBAR = [
  {
    berkas: '../erd/01-L1-logikal.png',
    keterangan: 'Gambar 4.5 ERD Sistem Informasi Operasional Kos-Kosan',
    catatan:
      'Notasi Chen. Dua belas entitas inti beserta atribut kuncinya. Tabel teknis ' +
      '(token_akun, log_audit, notifications, dan sejenisnya) sengaja tidak digambar ' +
      'karena tidak menambah pemahaman konsep; daftarnya ada di dokumen ERD.',
  },
  {
    berkas: '../erd/03-L3-lrs.png',
    keterangan: 'Gambar 4.6 Logical Record Structure (LRS) — lampiran A3',
    catatan:
      'Dua puluh empat tabel, seluruh 209 kolom, dalam satu bagan. Kunci primer ' +
      'digarisbawahi; kunci asing bertanda bintang dan dihubungkan panah ke tabel ' +
      'yang dituju. Arah hubungan dibawa oleh panah, bukan oleh angka 1/N — angka ' +
      'itu milik notasi Chen dan tidak dipakai pada LRS.',
  },
]

/* ------------------------------------------------------------ 4.1.2 data */

// Tabel spesifikasi data. Contoh baris diberi tanda (contoh) pada keterangan
// supaya tidak dikira potret basis data yang sedang berjalan.
const SPESIFIKASI = [
  {
    judul: 'Entitas Akun Pengguna (t_users)',
    tabel: 't_users',
    paragraf:
      'Menyimpan kredensial dan peran. Dipisahkan dari tabel penghuni supaya ' +
      'kata sandi tidak pernah berada di tabel yang dibaca untuk menampilkan data ' +
      'penghuni. Peran menentukan apa yang boleh dilakukan: pemilik, pengelola, ' +
      'atau penghuni.',
    kolom: ['id', 'name', 'email', 'peran', 'wajib_ganti_sandi'],
    baris: [
      ['1', 'Pemilik Kos', 'pemilik@kos.test', 'pemilik', 'tidak'],
      ['2', 'Pengelola Kos', 'pengelola@kos.test', 'pengelola', 'tidak'],
      ['3', 'Budi Santoso', 'penghuni@kos.test', 'penghuni', 'tidak'],
    ],
  },
  {
    judul: 'Entitas Master Penghuni (t_penghuni)',
    tabel: 't_penghuni',
    paragraf:
      'Menyimpan identitas penghuni tanpa kredensial. Diperlukan agar nama dan ' +
      'nomor HP tidak diketik ulang di setiap tagihan dan setiap pembayaran.',
    kolom: ['id', 'nama', 'no_hp', 'email'],
    baris: [
      ['1', 'Budi Santoso', '081200000001', 'penghuni@kos.test'],
      ['2', 'Sari Dewi', '081200000002', 'sari@kos.test'],
      ['3', 'Rudi Hartono', '081200000003', 'rudi@kos.test'],
    ],
  },
  {
    judul: 'Entitas Master Kamar (t_kamar)',
    tabel: 't_kamar',
    paragraf:
      'Menyimpan kamar yang disewakan. Kolom status_huni menyatakan keadaan ' +
      'kamar saat ini, sedangkan riwayat siapa pernah menempatinya ada di tabel ' +
      'kontrak — dua hal berbeda yang tidak boleh dicampur.',
    kolom: ['id', 'nomor', 'tipe', 'status_huni'],
    baris: [
      ['1', 'A-01', 'Standar', 'terisi'],
      ['2', 'A-02', 'Standar', 'terisi'],
      ['3', 'A-03', 'Besar', 'kosong'],
    ],
  },
  {
    judul: 'Entitas Master Fasilitas (t_fasilitas)',
    tabel: 't_fasilitas',
    paragraf:
      'Daftar fasilitas yang bisa dimiliki kamar. Namanya disimpan sekali di ' +
      'sini, lalu dihubungkan ke kamar lewat tabel kamar_fasilitas.',
    kolom: ['id', 'nama', 'ikon', 'urutan'],
    baris: [
      ['1', 'AC', 'ac', '10'],
      ['2', 'Kamar mandi dalam', 'mandi', '20'],
      ['3', 'Lemari', 'lemari', '30'],
      ['4', 'Meja belajar', 'meja', '40'],
      ['5', 'Wi-Fi', 'wifi', '50'],
      ['6', 'Parkir motor', 'parkir', '60'],
    ],
  },
  {
    judul: 'Entitas Riwayat Tarif (t_tarif_sewa)',
    tabel: 't_tarif_sewa',
    paragraf:
      'Menyimpan tarif beserta tanggal berlakunya, bukan satu tarif terkini per ' +
      'kamar. Inilah yang membuat tagihan lama tetap benar setelah tarif dinaikkan: ' +
      'kenaikan tarif menambah baris baru, tidak menimpa baris lama.',
    kolom: ['id', 'kamar_id', 'nilai', 'berlaku_dari'],
    baris: [
      ['1', '1', '1.500.000', '2026-01-01'],
      ['2', '2', '1.500.000', '2026-01-01'],
      ['3', '3', '1.750.000', '2026-01-01'],
    ],
  },
  {
    judul: 'Entitas Kontrak Sewa (t_kontrak)',
    tabel: 't_kontrak',
    paragraf:
      'Mencatat "siapa menempati kamar mana, sejak kapan". Tagihan selalu ' +
      'mengacu ke kontrak, bukan langsung ke kamar, sehingga riwayat penghuni ' +
      'sebelumnya tidak hilang ketika kamar berganti penghuni.',
    kolom: ['id', 'kamar_id', 'penghuni_id', 'tgl_masuk', 'tarif_awal', 'status'],
    baris: [
      ['1', '1', '1', '2026-01-15', '1.500.000', 'aktif'],
      ['2', '2', '2', '2026-01-15', '1.500.000', 'aktif'],
    ],
  },
  {
    judul: 'Entitas Tagihan (t_tagihan)',
    tabel: 't_tagihan',
    paragraf:
      'Menyimpan tagihan per periode. Kolom tarif_terpakai adalah potret tarif ' +
      'dan kebijakan saat tagihan diterbitkan, sehingga mengubah tarif atau denda ' +
      'hari ini tidak mengubah tagihan bulan lalu.',
    kolom: ['id', 'kontrak_id', 'periode', 'pokok', 'jatuh_tempo', 'status'],
    baris: [
      ['1', '1', '2026-01-01', '1.500.000', '2026-01-15', 'lunas'],
      ['2', '1', '2026-02-01', '1.500.000', '2026-02-15', 'terbit'],
      ['3', '2', '2026-01-01', '1.500.000', '2026-01-15', 'terbit'],
    ],
  },
  {
    judul: 'Entitas Rincian Tagihan (t_tagihan_rincian)',
    tabel: 't_tagihan_rincian',
    paragraf:
      'Menguraikan tagihan menjadi baris-baris yang bisa dibaca penghuni: sewa, ' +
      'listrik, air, dan seterusnya. Tanpa tabel ini, penghuni hanya melihat satu ' +
      'angka besar tanpa tahu asalnya.',
    kolom: ['id', 'tagihan_id', 'nama', 'nilai', 'urutan'],
    baris: [
      ['1', '1', 'Sewa kamar A-01', '1.500.000', '10'],
      ['2', '2', 'Sewa kamar A-01', '1.500.000', '10'],
      ['3', '2', 'Kebersihan', '50.000', '20'],
    ],
  },
  {
    judul: 'Entitas Pembayaran (t_pembayaran)',
    tabel: 't_pembayaran',
    paragraf:
      'Mencatat setiap uang yang masuk, terpisah dari tagihan. Satu tagihan boleh ' +
      'dibayar beberapa kali. Pembatalan tidak menghapus baris, melainkan menambah ' +
      'baris bernilai negatif yang menunjuk baris aslinya lewat pembalik_id — supaya ' +
      'jejak uang tidak bisa hilang.',
    kolom: ['id', 'tagihan_id', 'jumlah', 'tanggal_pembayaran', 'metode', 'sumber'],
    baris: [
      ['1', '1', '1.500.000', '2026-01-10', 'transfer', 'manual'],
      ['2', '3', '1.000.000', '2026-01-14', 'qris', 'gateway'],
      ['3', '3', '500.000', '2026-01-16', 'tunai', 'manual'],
    ],
  },
]

/* ---------------------------------------------------------------- 4.2 */

const PERANGKAT = {
  keras: [
    'Processor: Intel Core i3 atau setara (aplikasi berjalan di atas Docker, bukan langsung di sistem operasi).',
    'RAM: minimal 4 GB untuk menjalankan aplikasi; 8 GB bila ingin menjalankan seluruh layanan sekaligus dengan nyaman.',
    'Penyimpanan: SSD 250 GB.',
    'Monitor, keyboard, dan mouse.',
    'Jaringan internet untuk verifikasi pembayaran melalui gerbang pembayaran.',
  ],
  lunak: [
    'Sistem operasi: Windows 10/11, macOS, atau Linux.',
    'Docker Desktop — menjalankan PostgreSQL 16, PHP 8.3, nginx, dan Mailpit sekaligus.',
    'PostgreSQL 16 sebagai sistem manajemen basis data.',
    'PHP 8.3 dan Laravel 12 sebagai kerangka kerja antarmuka pemrograman.',
    'Node.js 22 dan Vue 3 untuk antarmuka pengguna.',
    'Google Chrome atau Microsoft Edge untuk mengakses aplikasi dan menjalankan uji otomatis.',
  ],
}

module.exports = {
  NORMALISASI,
  TABEL_AKHIR,
  GAMBAR,
  SPESIFIKASI,
  PERANGKAT,
}
