'use strict';

/**
 * Sumber tunggal 14 use case — Sistem Informasi Operasional Kos-Kosan
 * -------------------------------------------------------------------
 * Berkas ini memuat SELURUH isi semantik pemodelan use case: tiga aktor,
 * empat belas use case, dan endpoint nyata yang mendasari masing-masing.
 *
 * Kenapa dipisah dari dokumennya, sama seperti `docs/dfd/dfd-data.js`:
 * sebelumnya angka dan prosa bercampur di satu berkas, dan keduanya bisa
 * menyimpang tanpa ketahuan. Sekarang penyimpangan mustahil secara struktur —
 * `build.js` membaca berkas ini, memeriksa setiap nama endpoint terhadap
 * `rute-api.json` (potret `php artisan route:list`), lalu menulis ulang tabel
 * di `use-case-diagram.md` dan `Proses_Bisnis_Kos_Kosan.md`. Tabel yang tidak
 * cocok tidak mungkin tertinggal, karena tabelnya tidak punya sumber lain.
 *
 * ATURAN YANG DIJAGA BERKAS INI
 *
 *   1. Tepat 14 use case. Menambah atau mengurangi berarti mengubah cakupan
 *      produk, dan itu keputusan pemilik (lihat `docs/serah-terima.md` §3).
 *   2. Setiap use case menunjuk minimal satu endpoint yang benar-benar ada.
 *      Use case tanpa endpoint adalah karangan.
 *   3. Satu endpoint hanya boleh menjadi dasar satu use case, supaya
 *      penghitungan 86 endpoint bisa ditutup tanpa sisa dan tanpa dobel.
 *   4. Keanggotaan aktor diturunkan dari middleware, bukan diketik bebas:
 *      hanya endpoint bergerbang (`peran:` atau `wewenang:`) yang menentukan
 *      siapa pemilik use case. Endpoint yang hanya memakai `auth:sanctum`
 *      dapat dipanggil semua peran atas barisnya sendiri (scope R-07), jadi
 *      ia tidak membedakan aktor — kalau ia ikut dihitung, portal penghuni
 *      akan tampak dapat diakses pemilik hanya karena `/tagihan/{id}` dipakai
 *      bersama.
 */

/* ---------------------------------------------------------------- aktor */

/**
 * Tiga aktor, bukan lima. Template dosen memakai lima karena sistemnya
 * (reservasi sarana kampus) memang punya lima peran; sistem ini punya tiga.
 * Jumlah use case tetap 14 sesuai keputusan pemilik.
 *
 * `kunci` adalah nilai `users.peran` di basis data. `ringkas` dipakai sebagai
 * label di diagram, `peran` sebagai kalimat penjelas di dokumen narasi.
 *
 * `peranSingkat` ada karena dua dokumen punya anggaran halaman berbeda.
 * `use-case-diagram.md` tidak dibatasi halaman, jadi ia memakai `peran` utuh.
 * Laporan tiga halaman memakai `peranSingkat`, sebab tiga kalimat panjang di
 * satu bab akan mendorong bab lain keluar dari halaman yang tersedia. Isinya
 * bukan versi berbeda, hanya versi tanpa anak kalimat.
 */
const AKTOR = [
  {
    kunci: 'pemilik',
    nama: 'PEMILIK KOS',
    ringkas: ['Pemilik', 'Kos'],
    peran:
      'Pemilik akun dan pemegang keputusan. Satu-satunya yang boleh menetapkan ' +
      'tarif, kebijakan, dan komponen biaya secara langsung, menyetujui usulan ' +
      'pengelola, memaafkan denda, serta menetapkan rekening tujuan setoran.',
    peranSingkat: 'Pemilik akun dan pemegang keputusan: menetapkan tarif, kebijakan, dan rekening tujuan setoran, serta memaafkan denda.',
  },
  {
    kunci: 'pengelola',
    nama: 'PENGELOLA',
    ringkas: ['Pengelola'],
    peran:
      'Akun yang dibuat pemilik dan diberi wewenang terbatas dari delapan ' +
      'wewenang yang tersedia. Mencatat pembayaran tunai yang ia terima, ' +
      'menerbitkan tagihan, dan mengusulkan perubahan tarif atau kebijakan — ' +
      'usulan itu baru berlaku setelah pemilik memutuskan.',
    peranSingkat: 'Akun buatan pemilik dengan wewenang terbatas: mencatat pembayaran tunai, menerbitkan tagihan, mengusulkan perubahan tarif atau kebijakan.',
  },
  {
    kunci: 'penghuni',
    nama: 'PENGHUNI',
    ringkas: ['Penghuni'],
    peran:
      'Penerima informasi dan pembayar, bukan pemasok data. Portal penghuni ' +
      'hampir seluruhnya baca-saja: pendaftaran sewa dan pencatatan uang ' +
      'dilakukan pemilik atau pengelola. Satu-satunya aliran masuk darinya ' +
      'adalah membuka tautan bayar daring, dan uangnya baru tercatat setelah ' +
      'gerbang pembayaran menyatakan settlement.',
    peranSingkat: 'Penerima informasi dan pembayar, bukan pemasok data; portalnya baca-saja kecuali membuka tautan bayar daring.',  },
];

/* ------------------------------------------------------------ use case */

/**
 * Empat belas use case, urut menurut alur kerja bulanan: masuk → siapkan data
 * → tetapkan angka → terbitkan → tagih → laporkan → urus akun.
 *
 * `endpoint` memakai NAMA RUTE (`route:list` kolom `name`), bukan URI, karena
 * nama rute adalah kunci yang stabil: URI boleh berubah tanpa memutus
 * ketertelusuran, nama rute tidak.
 *
 * `gerbang` mencatat pembatasan yang lebih sempit daripada peran — dipakai
 * untuk catatan kaki matriks, bukan untuk menentukan keanggotaan aktor.
 *
 * `deskripsi` sengaja pendek (~15 kata): ia dicetak di laporan tiga halaman,
 * dan template dosen memakai kepadatan yang sama. Rincian yang dibuang di sini
 * tetap ada di bab 3 (alur transaksi) dan di `use-case-diagram.md`.
 */
const USE_CASE = [
  {
    kode: 'UC-01',
    nama: 'Registrasi & Login Akun',
    ringkas: 'Registrasi & Login Akun',
    deskripsi: 'Autentikasi ketiga peran: login, pemulihan sandi lewat surel, dan penggantian sandi wajib.',
    endpoint: [
      'api.login',
      'api.logout',
      'api.me',
      'api.lupa-sandi',
      'api.periksa-token',
      'api.atur-sandi',
      'api.ganti-sandi',
    ],
    gerbang: '',
    tanpaGerbang:
      'Seluruh endpoint autentikasi hanya memakai `auth:sanctum` — bahkan ' +
      '`/login` belum menuntut autentikasi sama sekali. Ketiga peran memakai ' +
      'pintu yang sama, jadi tidak ada aktor yang bisa dibedakan di sini.',
    gabungan:
      'Autentikasi, pemulihan sandi, dan penggantian sandi wajib adalah satu ' +
      'pintu masuk yang sama bagi ketiga peran. Memecahnya menjadi tiga use ' +
      'case akan menggambarkan tiga pekerjaan berbeda, padahal penggunanya ' +
      'mengalami satu urutan yang sama.',
  },
  {
    kode: 'UC-02',
    nama: 'Kelola Kamar, Fasilitas & Aturan Kos',
    ringkas: 'Kelola Kamar, Fasilitas & Aturan',
    deskripsi: 'Mendaftarkan kamar, fasilitas tiap kamar, aturan kos, dan laporan kerusakan yang membuat kamar berstatus diperbaiki.',
    endpoint: [
      'kamar.index',
      'kamar.store',
      'kamar.show',
      'kamar.update',
      'kamar.fasilitas',
      'kerusakan.index',
      'kerusakan.store',
      'kerusakan.selesai',
      'fasilitas.index',
      'fasilitas.store',
      'fasilitas.update',
      'fasilitas.destroy',
      'aturan.index',
      'aturan.store',
      'aturan.update',
      'aturan.destroy',
    ],
    gerbang: 'Menulis kamar, fasilitas, aturan, dan laporan kerusakan menuntut wewenang `kelola_kamar`.',
  },
  {
    kode: 'UC-03',
    nama: 'Kelola Data Penghuni & Kontrak Sewa',
    ringkas: 'Kelola Penghuni & Kontrak',
    deskripsi: 'Mendaftarkan penghuni, membuat kontrak atas satu kamar kosong, dan mengakhiri kontrak.',
    endpoint: [
      'penghuni.index',
      'penghuni.store',
      'penghuni.show',
      'penghuni.update',
      'penghuni.kirim-ulang-aktivasi',
      'kontrak.index',
      'kontrak.store',
      'kontrak.show',
      'kontrak.akhiri',
    ],
    gerbang: 'Menulis penghuni dan kontrak menuntut wewenang `kelola_penghuni`.',
  },
  {
    kode: 'UC-04',
    nama: 'Kelola Tarif Sewa & Komponen Biaya',
    ringkas: 'Kelola Tarif & Komponen Biaya',
    deskripsi: 'Menetapkan tarif per kamar dan komponen biaya; keduanya tambah-saja, riwayat tidak tertimpa.',
    endpoint: [
      'tarif.index',
      'tarif.store',
      'tarif.berlaku',
      'komponen.index',
      'komponen.store',
    ],
    gerbang:
      'Pemilik menetapkan langsung; pengelola dengan `ubah_tarif` hanya ' +
      'mengajukan usulan (HTTP 202) yang menunggu keputusan pemilik.',
  },
  {
    kode: 'UC-05',
    nama: 'Kelola Kebijakan Kos',
    ringkas: 'Kelola Kebijakan Kos',
    deskripsi: 'Menetapkan tiga dimensi kebijakan kos — satuan sewa, jatuh tempo, denda.',
    endpoint: ['kebijakan.index', 'kebijakan.store'],
    gerbang:
      'Pemilik menetapkan langsung; pengelola dengan `ubah_kebijakan` hanya ' +
      'mengajukan usulan (HTTP 202).',
  },
  {
    kode: 'UC-06',
    nama: 'Persetujuan Usulan Perubahan Tarif & Kebijakan',
    ringkas: 'Persetujuan Usulan Perubahan',
    deskripsi: 'Memproses antrean usulan pengelola dan keputusan pemilik atas perubahan tarif dan kebijakan.',
    endpoint: ['usulan.index', 'usulan.setujui', 'usulan.tolak'],
    gerbang:
      'Pengelola hanya membaca nasib usulannya sendiri; tombol keputusan ' +
      'ditegakkan `peran:pemilik`, bukan wewenang.',
  },
  {
    kode: 'UC-07',
    nama: 'Terbitkan & Kelola Tagihan Bulanan',
    ringkas: 'Terbitkan & Kelola Tagihan',
    deskripsi: 'Menghitung sewa prorata, menerbitkan tagihan satuan maupun massal, membatalkan yang salah, memaafkan denda.',
    endpoint: [
      'tagihan.index',
      'tagihan.store',
      'tagihan.terbitkan',
      'tagihan.batal',
      'tagihan.maaf-denda',
    ],
    gerbang:
      'Menerbitkan dan membatalkan menuntut `terbitkan_tagihan`; pemaafan ' +
      'denda khusus pemilik karena melepaskan uang yang seharusnya diterima.',
  },
  {
    kode: 'UC-08',
    nama: 'Catat Pembayaran Sewa',
    ringkas: 'Catat Pembayaran Sewa',
    deskripsi: 'Mencatat uang diterima beserta tanggal, metode, penerima; status tagihan jadi sebagian atau lunas.',
    endpoint: [
      'tagihan.bayar',
      'pembayaran.index',
      'pembayaran.penerima',
      'pembayaran.tunai-saya',
      'api.webhook.midtrans',
    ],
    gerbang:
      'Mencatat menuntut `catat_pembayaran`. Uang tunai di tangan ' +
      'dipertanggungjawabkan per akun lewat `/pembayaran/tunai-saya`.',
    gabungan:
      'Webhook gateway (`POST /api/webhook/midtrans`) ikut di sini karena ia ' +
      'peristiwa pencatatan uang yang sama, hanya pemicunya mesin bukan orang. ' +
      'Ia tidak bergerbang peran — pemanggilnya Midtrans, bukan pengguna — dan ' +
      'karena itu tidak menambah aktor mana pun; yang menjaganya tanda tangan ' +
      'SHA512 atas `order_id`, `status_code`, dan `gross_amount`.',
  },
  {
    kode: 'UC-09',
    nama: 'Verifikasi & Koreksi Pembayaran',
    ringkas: 'Verifikasi & Koreksi Pembayaran',
    deskripsi: 'Memeriksa catatan pembayaran akun lain dan menarik yang salah lewat baris pembalik.',
    endpoint: ['pembayaran.verifikasi', 'pembayaran.pembalik'],
    gerbang:
      'Menuntut `verifikasi_pembayaran`, dan akun yang mencatat sebuah ' +
      'pembayaran tidak boleh memverifikasinya sendiri (REV-FIN-03).',
  },
  {
    kode: 'UC-10',
    nama: 'Laporan Rekap Pendapatan, Tunggakan & Jejak Audit',
    ringkas: 'Laporan Rekap & Jejak Audit',
    deskripsi: 'Rekap pendapatan per metode, tunggakan menurut umur piutang, jejak audit, dan ekspor ketiganya ke berkas CSV.',
    // Tiga endpoint ekspor ikut di sini, bukan di use case tersendiri: ia
    // mengeluarkan DATA YANG SAMA dengan tiga layar di atas, hanya dalam bentuk
    // berkas yang bisa dibuka di Excel. Use case tersendiri akan berarti
    // "mengekspor" adalah pekerjaan yang berbeda dari "melihat", padahal
    // pekerjaannya satu: pemilik ingin tahu ke mana uangnya pergi.
    //
    // Gerbangnya berbeda dari layarnya, dan itu memang disengaja:
    // `/ekspor/rekap` bergerbang `peran:pemilik` (sama seperti `/rekap`),
    // sedangkan `/ekspor/tunggakan` dan `/ekspor/pembayaran` terbuka untuk
    // pengelola karena ia sudah bisa membaca layar yang sama. Karena UC-10
    // sudah punya pemilik DAN pengelola lewat `tunggakan.index`, penambahan
    // ini tidak mengubah keanggotaan aktornya.
    endpoint: [
      'rekap.index',
      'tunggakan.index',
      'audit-log.index',
      'ekspor.rekap',
      'ekspor.tunggakan',
      'ekspor.pembayaran',
    ],
    gerbang:
      'Rekap pendapatan khusus pemilik; pengelola membaca tunggakan dan, bila ' +
      'punya `baca_audit`, jejak audit.',
    gabungan:
      'Tiga ekspor CSV berada di sini, bukan di use case tersendiri, karena ' +
      'ketiganya mengeluarkan DATA YANG SAMA dengan tiga layar di atas - hanya ' +
      'dalam bentuk berkas yang bisa dibuka di Excel. Use case tersendiri akan ' +
      'berarti "mengekspor" adalah pekerjaan yang berbeda dari "melihat", ' +
      'padahal pekerjaan penggunanya satu: ia ingin tahu ke mana uangnya pergi. ' +
      'Gerbangnya tetap berbeda dan itu disengaja: `/ekspor/rekap` khusus ' +
      'pemilik seperti `/rekap`, sedangkan `/ekspor/tunggakan` dan ' +
      '`/ekspor/pembayaran` terbuka untuk pengelola karena ia sudah bisa ' +
      'membaca layar yang sama.',
  },
  {
    kode: 'UC-11',
    nama: 'Kelola Preferensi Akun, Notifikasi & Hak Data Pribadi',
    ringkas: 'Preferensi Akun, Notifikasi & Data Pribadi',
    deskripsi: 'Mengatur kanal notifikasi, membaca kotak masuk, persetujuan PDP, dan hak atas data pribadi sendiri.',
    endpoint: [
      'notifikasi.index',
      'notifikasi.baca-semua',
      'notifikasi.baca',
      'pengaturan-notifikasi.show',
      'pengaturan-notifikasi.update',
      'api.persetujuan-pdp',
      'pdp.data-saya',
      'pdp.hapus-saya',
    ],
    gerbang:
      'Tidak ada endpoint bergerbang: kedelapan endpoint hanya memakai ' +
      '`auth:sanctum`, sehingga ketiga peran sama-sama dapat memanggilnya atas ' +
      'akunnya sendiri.',
    tanpaGerbang:
      'Itu memang benar: preferensi notifikasi dan data pribadi adalah milik ' +
      'pemegang akun, bukan wewenang yang bisa didelegasikan. Membatasinya ' +
      'dengan `peran:` akan membuat pengelola tidak bisa mematikan surelnya ' +
      'sendiri.',
    gabungan:
      'Kesembilan peristiwa notifikasi dan tiga hak subjek data tidak ' +
      'membentuk alur tersendiri, tetapi ketiganya milik semua peran dan ' +
      'menyangkut akun sendiri, bukan data kos.',
  },
  {
    kode: 'UC-12',
    nama: 'Kelola Akun Pengelola & Wewenang',
    ringkas: 'Kelola Akun Pengelola & Wewenang',
    deskripsi: 'Membuat akun pengelola, menetapkan wewenangnya, mengirim ulang aktivasi, dan menonaktifkan akun.',
    endpoint: [
      'pengelola.index',
      'pengelola.store',
      'pengelola.show',
      'pengelola.update',
      'pengelola.destroy',
      'pengelola.kirim-ulang-aktivasi',
    ],
    gerbang:
      'Hanya pemilik, dan tidak bisa didelegasikan: siapa pun yang boleh ' +
      'memberi wewenang pada dasarnya boleh memberi dirinya wewenang apa pun.',
  },
  {
    kode: 'UC-13',
    nama: 'Portal Penghuni',
    ringkas: 'Portal Penghuni',
    deskripsi: 'Menyajikan kontrak sewa, tagihan, denda, aturan, dan rekening tujuan transfer milik penghuni sendiri, serta membuka tautan bayar daring.',
    endpoint: [
      'penghuni.tagihan',
      'penghuni.aturan',
      'penghuni.rekening',
      'penghuni.kontrak',
      'tagihan.show',
      'tagihan.denda',
      'tagihan.bayar-daring',
    ],
    gerbang:
      'Lima endpoint `/penghuni/*` dan `bayar-daring` bergerbang ' +
      '`peran:penghuni`, dan itulah yang menentukan keanggotaan aktor: hanya ' +
      'penghuni. Dua endpoint baca di dalamnya hanya memakai `auth:sanctum` ' +
      'dan dipakai bersama ketiga peran lewat scope `Tagihan::terlihatOleh` ' +
      '(R-07) — lihat catatan kaki (d).',
  },
  {
    kode: 'UC-14',
    nama: 'Kelola Rekening Tujuan Pembayaran, Status Penyiapan & Diagnostik Sistem',
    ringkas: 'Rekening, Penyiapan & Diagnostik',
    deskripsi: 'Mengelola rekening dan QRIS tujuan setoran, membaca status penyiapan kos, serta pemeriksaan kesehatan layanan dan peran.',
    endpoint: [
      'rekening.index',
      'rekening.store',
      'rekening.update',
      'rekening.destroy',
      // Gambar QRIS. Rutenya terpisah karena mengembalikan byte, bukan JSON,
      // tetapi ia bagian dari pengelolaan rekening: pemilik mengunggahnya di
      // layar yang sama dan penghuni melihatnya di portal.
      'rekening.gambar',
      // Status penyiapan kos (Fase 3.5): "apa yang belum ada dan mana yang
      // harus lebih dulu". Ia rumah yang tepat di sini karena isinya adalah
      // PEMERIKSAAN atas hal-hal yang dikelola use case lain (kebijakan,
      // kamar, tarif, rekening) - bukan alur kerja tersendiri. Kalau ia diberi
      // use case sendiri, diagram akan punya kotak yang pekerjaannya "melihat
      // daftar hal yang belum selesai", dan itu bukan pekerjaan pengguna.
      'penyiapan.index',
      'api.health',
      'api.pemilik-saja',
    ],
    gerbang:
      'Menulis rekening khusus pemilik karena nomor rekening adalah alamat ' +
      'uang masuk; `/health` adalah diagnostik publik tanpa aktor, dan ' +
      '`/pemilik/saja` adalah pemeriksa middleware peran.',
    gabungan:
      'Menulis rekening khusus pemilik karena nomor rekening adalah alamat ' +
      'uang masuk; `/health` adalah diagnostik publik tanpa aktor, dan ' +
      '`/pemilik/saja` adalah pemeriksa middleware peran. Ketiganya tidak ' +
      'membentuk alur tersendiri, tetapi tetap harus punya rumah di diagram. ' +
      '`/penyiapan` ikut di sini karena isinya PEMERIKSAAN atas hal-hal yang ' +
      'dikelola use case lain - kebijakan, kamar, tarif, rekening - bukan ' +
      'pekerjaan pengguna yang berdiri sendiri.',
  },
];

/**
 * Matriks hak akses aktor terhadap use case.
 *
 * Ditulis sebagai daftar kode, bukan sebagai centang per sel, karena bentuk
 * itulah yang diminta template (`Aktor: Mengakses UC-xx, ...`). `build.js`
 * memeriksa daftar ini terhadap middleware: aktor yang tercantum harus benar
 * dapat memanggil minimal satu endpoint bergerbang milik use case itu, dan
 * aktor yang tidak tercantum tidak boleh dapat memanggil satu pun.
 */
const MATRIKS = [
  {
    aktor: 'pemilik',
    uc: [
      'UC-01', 'UC-02', 'UC-03', 'UC-04', 'UC-05', 'UC-06', 'UC-07',
      'UC-08', 'UC-09', 'UC-10', 'UC-11', 'UC-12', 'UC-14',
    ],
  },
  {
    aktor: 'pengelola',
    uc: [
      'UC-01', 'UC-02', 'UC-03', 'UC-04', 'UC-05', 'UC-06', 'UC-07',
      'UC-08', 'UC-09', 'UC-10', 'UC-11', 'UC-14',
    ],
  },
  {
    aktor: 'penghuni',
    uc: ['UC-01', 'UC-11', 'UC-13'],
  },
];

/**
 * Catatan kaki matriks. Hanya tiga hal, dan ketiganya benar-benar lebih sempit
 * daripada peran: menyembunyikannya akan membuat matriks terbaca seolah
 * pengelola boleh memaafkan denda dan membaca rekap pendapatan.
 *
 * `teks` dipakai `use-case-diagram.md` (tanpa batas halaman). `teksSingkat`
 * dipakai laporan tiga halaman: catatan (d) yang utuh memakan 80 kata, dan
 * memuatnya dua kali berarti membayar dua kali untuk penjelasan yang sama.
 * Versi pendeknya tetap menyebut angka 404, sebab itulah yang membedakan
 * scope dari gerbang peran.
 */
const CATATAN_MATRIKS = [
  {
    kode: 'a',
    uc: 'UC-07',
    teks:
      'pemaafan denda (`POST /api/tagihan/{tagihan}/maaf-denda`) khusus pemilik; ' +
      'pengelola menerbitkan dan membatalkan tagihan, tidak memaafkan denda.',
  },
  {
    kode: 'b',
    uc: 'UC-10',
    teks:
      'rekap pendapatan (`GET /api/rekap`) khusus pemilik; pengelola membaca ' +
      'tunggakan dan — bila punya `baca_audit` — jejak audit.',
  },
  {
    kode: 'c',
    uc: 'UC-14',
    teks:
      'pengelola hanya membaca daftar rekening; menulis rekening khusus pemilik ' +
      'karena nomor rekening adalah alamat uang masuk.',
  },
  {
    kode: 'd',
    uc: 'UC-13',
    teks:
      'lima endpoint `/penghuni/*` dan `POST /api/tagihan/{tagihan}/bayar-daring` ' +
      'bergerbang `peran:penghuni`, sehingga UC-13 ' +
      'utuh hanya milik penghuni. Dua endpoint baca di dalamnya — `GET ' +
      '/api/tagihan/{tagihan}` dan `GET /api/tagihan/{tagihan}/denda` — hanya ' +
      'memakai `auth:sanctum` dan dipakai bersama: pemilik dan pengelola ' +
      'membukanya dari layar tagihan, penghuni dari portalnya. Yang membedakan ' +
      'bukan gerbang melainkan scope `Tagihan::terlihatOleh` (R-07): tagihan ' +
      'milik orang lain dijawab 404, bukan 403.',
  },
];

/* -------------------------------------------------------------- tata letak */

/**
 * Tata letak diagram use case.
 *
 * Aktor pemilik di kiri, pengelola dan penghuni di kanan. Alasannya bukan
 * keindahan: pemilik terhubung ke keempat belas use case, jadi empat belas
 * garis dari satu titik. Bila ia diletakkan di tengah, garisnya harus melewati
 * elips use case lain. Dengan pemilik di kiri dan dua aktor lain di kanan,
 * garis kiri dan garis kanan menempati dua lajur yang tidak pernah bertemu.
 *
 * Ukuran dipilih dari isi, bukan sebaliknya: 14 elips setinggi 64 px dengan
 * jarak 72 px menuntut kanvas setinggi 1180 px.
 */
const TATA_LETAK = {
  kanvas: { w: 1300, h: 1200 },
  batas: { x: 300, y: 84, w: 660, h: 1044 },
  elips: { cx: 630, rx: 212, ry: 32, mulai: 132, jarak: 72 },
  aktor: {
    pemilik: { x: 150, y: 600 },
    pengelola: { x: 1150, y: 372 },
    penghuni: { x: 1150, y: 900 },
  },
};

module.exports = {
  AKTOR,
  USE_CASE,
  MATRIKS,
  CATATAN_MATRIKS,
  TATA_LETAK,
};
