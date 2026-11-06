'use strict';

/**
 * Data diagram urutan — 14 use case, satu diagram per use case.
 * -------------------------------------------------------------
 * Setiap nama di sini harus bisa DIBUKTIKAN ada:
 *
 *   · `endpoint`     harus ada di `rute-api.json` dan harus milik use case itu
 *                    menurut `usecase-data.js`;
 *   · peserta `kendali`, `layanan`, dan `model` harus berupa kelas yang benar
 *                    -benar dideklarasikan di `api/app`;
 *   · peserta `batas` dan `data` adalah gambar (API, basis data), bukan kelas.
 *
 * `urutan-gambar.js` memeriksa ketiga hal itu dan MENOLAK menggambar bila ada
 * yang meleset. Diagram urutan yang menyebut `PenerbitTagihan::terbitkan()`
 * padahal metodenya tidak ada adalah karangan yang terlihat meyakinkan — persis
 * jenis kesalahan yang tidak akan ketahuan sampai ada yang membuka kodenya.
 *
 * Tingkat rinciannya sengaja satu lapis di bawah controller: cukup untuk
 * menunjukkan kelas mana yang mengerjakan apa, tanpa menyalin isi metode.
 *
 * `balik` berisi NOMOR LANGKAH (mulai 1) yang digambar sebagai panah balik
 * bergaris putus. Tidak diturunkan dari arah panahnya: `beri → db` pada UC-05
 * adalah panggilan INSERT yang memang mengarah ke kiri, jadi arah saja tidak
 * cukup untuk membedakan panggilan dari jawaban.
 */

/* eslint-disable max-len */

const URUTAN = [
  {
    kode: 'UC-01',
    judul: 'Masuk ke aplikasi',
    aktor: 'Pemilik / Pengelola / Penghuni',
    endpoint: 'api.login',
    balik: [5, 8, 11, 12],
    catatan:
      'Percobaan yang gagal dihitung di cache, bukan di basis data. Waktu jawab disamakan antara "email tidak terdaftar" dan "sandi salah" supaya alamat email tidak bisa diperiksa keberadaannya.',
    peserta: [
      ['aktor', 'Pengguna', 'aktor'],
      ['api', 'API  POST /login', 'batas'],
      ['ctrl', 'AuthController', 'kendali'],
      ['batas', 'PembatasMasuk', 'layanan'],
      ['user', 'User', 'model'],
      ['db', 'Basis Data', 'data'],
    ],
    langkah: [
      ['aktor', 'api', 'email, kata sandi'],
      ['api', 'ctrl', 'login(LoginRequest)'],
      ['ctrl', 'batas', 'tungguSebelum(email, ip)'],
      ['batas', 'db', 'baca penghitung percobaan'],
      ['db', 'batas', 'sisa waktu tunggu'],
      ['ctrl', 'user', 'where(email)->first()'],
      ['user', 'db', 'SELECT users'],
      ['db', 'user', 'baris pengguna'],
      ['ctrl', 'user', 'Hash::check(sandi)'],
      ['ctrl', 'db', 'INSERT personal_access_tokens'],
      ['ctrl', 'api', 'TokenResource + profil'],
      ['api', 'aktor', '200 + token'],
    ],
  },
  {
    kode: 'UC-02',
    judul: 'Tambah kamar',
    aktor: 'Pemilik',
    endpoint: 'kamar.store',
    balik: [5, 8, 9],
    catatan:
      'Laporan kerusakan memakai jalur yang sama ke `PencatatAudit`; yang berbeda hanya objek yang dicatat. Use case ini juga menaungi fasilitas, aturan kos, dan penutupan laporan.',
    peserta: [
      ['aktor', 'Pemilik', 'aktor'],
      ['api', 'API  POST /kamar', 'batas'],
      ['ctrl', 'KamarController', 'kendali'],
      ['kamar', 'Kamar', 'model'],
      ['audit', 'PencatatAudit', 'layanan'],
      ['db', 'Basis Data', 'data'],
    ],
    langkah: [
      ['aktor', 'api', 'nomor, tipe, tarif'],
      ['api', 'ctrl', 'store(SimpanKamarRequest)'],
      ['ctrl', 'kamar', 'create(validated)'],
      ['kamar', 'db', 'INSERT kamar'],
      ['db', 'kamar', 'baris kamar'],
      ['ctrl', 'audit', 'catat(aksi, objek)'],
      ['audit', 'db', 'INSERT log_audit'],
      ['ctrl', 'api', 'KamarResource'],
      ['api', 'aktor', '201 + kamar'],
    ],
  },
  {
    kode: 'UC-03',
    judul: 'Buat kontrak sewa',
    aktor: 'Pemilik',
    endpoint: 'kontrak.store',
    balik: [5, 10, 11],
    catatan:
      'Tarif tidak disalin dari formulir: ia diambil dari tarif yang berlaku pada kamar saat itu. Kalau pemilik mengubah tarif nanti, kontrak lama tetap memakai angka yang tersimpan.',
    peserta: [
      ['aktor', 'Pemilik', 'aktor'],
      ['api', 'API  POST /kontrak', 'batas'],
      ['ctrl', 'KontrakController', 'kendali'],
      ['tarif', 'PencariTarif', 'layanan'],
      ['kontrak', 'Kontrak', 'model'],
      ['audit', 'PencatatAudit', 'layanan'],
      ['db', 'Basis Data', 'data'],
    ],
    langkah: [
      ['aktor', 'api', 'penghuni, kamar, tanggal mulai'],
      ['api', 'ctrl', 'store(SimpanKontrakRequest)'],
      ['ctrl', 'tarif', 'sewaUntuk(kamar)'],
      ['tarif', 'db', 'SELECT tarif_sewa'],
      ['db', 'tarif', 'tarif yang berlaku'],
      ['ctrl', 'kontrak', 'create(..., tarif)'],
      ['kontrak', 'db', 'INSERT kontrak'],
      ['ctrl', 'audit', 'catat(aksi, objek)'],
      ['audit', 'db', 'INSERT log_audit'],
      ['ctrl', 'api', 'KontrakResource'],
      ['api', 'aktor', '201 + kontrak'],
    ],
  },
  {
    kode: 'UC-04',
    judul: 'Tetapkan tarif kamar',
    aktor: 'Pemilik',
    endpoint: 'tarif.store',
    balik: [5, 8, 9],
    catatan:
      'Pengelola yang punya wewenang tidak menetapkan langsung: `PengajuUsulan::ajukan()` mencatat usulan, dan angkanya baru berlaku setelah pemilik memutuskan (UC-06).',
    peserta: [
      ['aktor', 'Pemilik', 'aktor'],
      ['api', 'API  POST /tarif', 'batas'],
      ['ctrl', 'TarifController', 'kendali'],
      ['terap', 'PenerapPerubahan', 'layanan'],
      ['audit', 'PencatatAudit', 'layanan'],
      ['db', 'Basis Data', 'data'],
    ],
    langkah: [
      ['aktor', 'api', 'kamar, nilai, berlaku dari'],
      ['api', 'ctrl', 'store(SimpanTarifRequest)'],
      ['ctrl', 'terap', 'tarif(kamar, nilai, berlakuDari)'],
      ['terap', 'db', 'INSERT tarif_sewa'],
      ['db', 'terap', 'baris tarif'],
      ['ctrl', 'audit', 'catat(aksi, objek)'],
      ['audit', 'db', 'INSERT log_audit'],
      ['ctrl', 'api', 'TarifResource'],
      ['api', 'aktor', '201 + tarif'],
    ],
  },
  {
    kode: 'UC-05',
    judul: 'Tetapkan kebijakan kos',
    aktor: 'Pemilik',
    endpoint: 'kebijakan.store',
    balik: [5, 10, 11],
    catatan:
      'Kebijakan tidak pernah diubah di tempat: menetapkan nilai baru menambah baris dengan tanggal berlaku sendiri, sehingga tagihan lama tidak berubah artinya saat dibuka kembali.',
    peserta: [
      ['aktor', 'Pemilik', 'aktor'],
      ['api', 'API  POST /kebijakan', 'batas'],
      ['ctrl', 'KebijakanController', 'kendali'],
      ['terap', 'PenerapPerubahan', 'layanan'],
      ['audit', 'PencatatAudit', 'layanan'],
      ['beri', 'Pemberitahu', 'layanan'],
      ['db', 'Basis Data', 'data'],
    ],
    langkah: [
      ['aktor', 'api', 'tipe, nilai, berlaku dari'],
      ['api', 'ctrl', 'store(SimpanKebijakanRequest)'],
      ['ctrl', 'terap', 'kebijakan(tipe, nilai, berlakuDari)'],
      ['terap', 'db', 'INSERT kebijakan_kos'],
      ['db', 'terap', 'baris kebijakan'],
      ['ctrl', 'audit', 'catat(aksi, objek)'],
      ['audit', 'db', 'INSERT log_audit'],
      ['ctrl', 'beri', 'kebijakanDiubah(kebijakan)'],
      ['beri', 'db', 'INSERT notifikasi'],
      ['ctrl', 'api', 'KebijakanResource'],
      ['api', 'aktor', '201 + kebijakan'],
    ],
  },
  {
    kode: 'UC-06',
    judul: 'Setujui usulan perubahan',
    aktor: 'Pemilik',
    endpoint: 'usulan.setujui',
    balik: [7, 10, 11],
    catatan:
      'Usulan yang kedaluwarsa tidak dapat diputuskan; `UsulanTidakDapatDiputuskan` diterjemahkan menjadi 422, bukan 500. Penolakan tidak mengubah tarif maupun kebijakan apa pun.',
    peserta: [
      ['aktor', 'Pemilik', 'aktor'],
      ['api', 'API  POST /usulan/{id}/setujui', 'batas'],
      ['ctrl', 'UsulanController', 'kendali'],
      ['putus', 'PemutusUsulan', 'layanan'],
      ['terap', 'PenerapPerubahan', 'layanan'],
      ['beri', 'Pemberitahu', 'layanan'],
      ['db', 'Basis Data', 'data'],
    ],
    langkah: [
      ['aktor', 'api', 'usulan yang disetujui'],
      ['api', 'ctrl', 'setujui(usulan)'],
      ['ctrl', 'putus', 'setujui(usulan, pemutus)'],
      ['putus', 'db', 'UPDATE usulan_perubahan'],
      ['putus', 'terap', 'kebijakan(...) atau tarif(...)'],
      ['terap', 'db', 'INSERT baris kebijakan / tarif'],
      ['putus', 'ctrl', 'HasilUsulan'],
      ['ctrl', 'beri', 'setelahTransaksi(...)'],
      ['beri', 'db', 'INSERT notifikasi'],
      ['ctrl', 'api', 'UsulanResource'],
      ['api', 'aktor', '200 + usulan diputuskan'],
    ],
  },
  {
    kode: 'UC-07',
    judul: 'Terbitkan tagihan satu kontrak',
    aktor: 'Pemilik / Pengelola',
    endpoint: 'tagihan.store',
    balik: [6, 11, 12, 13],
    catatan:
      'Satu kontrak hanya punya satu tagihan per periode; penjaganya indeks unik di basis data, bukan pemeriksaan di aplikasi. Dua klik cepat tetap berakhir 409, bukan dua tagihan.',
    peserta: [
      ['aktor', 'Pemilik / Pengelola', 'aktor'],
      ['api', 'API  POST /tagihan', 'batas'],
      ['ctrl', 'TagihanController', 'kendali'],
      ['terbit', 'PenerbitTagihan', 'layanan'],
      ['kebij', 'PenyelesaiKebijakan', 'layanan'],
      ['hitung', 'Kalkulator', 'layanan'],
      ['tagihan', 'Tagihan', 'model'],
      ['db', 'Basis Data', 'data'],
    ],
    langkah: [
      ['aktor', 'api', 'kontrak, periode'],
      ['api', 'ctrl', 'store(TerbitkanTagihanRequest)'],
      ['ctrl', 'terbit', 'terbitkan(kontrak, periode)'],
      ['terbit', 'kebij', 'untukPenerbitan(kontrak)'],
      ['kebij', 'db', 'SELECT kebijakan_kos, komponen_biaya'],
      ['db', 'kebij', 'kebijakan yang berlaku'],
      ['terbit', 'hitung', 'hitungSewa(kontrak, periode)'],
      ['hitung', 'db', 'SELECT tarif_sewa'],
      ['terbit', 'tagihan', 'create(pokok, jatuh tempo)'],
      ['tagihan', 'db', 'INSERT tagihan (unik kontrak+periode)'],
      ['terbit', 'ctrl', 'Tagihan'],
      ['ctrl', 'api', 'TagihanResource'],
      ['api', 'aktor', '201 + tagihan'],
    ],
  },
  {
    kode: 'UC-08',
    judul: 'Catat pembayaran sewa',
    aktor: 'Pemilik / Pengelola',
    endpoint: 'tagihan.bayar',
    balik: [7, 10, 11],
    catatan:
      'Status tagihan tidak disimpan sebagai kolom terpisah: ia dihitung dari pokok, denda, dan jumlah yang sudah dibayar, sehingga tidak mungkin bertentangan dengan angka yang ditampilkan.',
    peserta: [
      ['aktor', 'Pengelola', 'aktor'],
      ['api', 'API  POST /tagihan/{id}/bayar', 'batas'],
      ['ctrl', 'TagihanController', 'kendali'],
      ['catat', 'PencatatPembayaran', 'layanan'],
      ['bayar', 'Pembayaran', 'model'],
      ['beri', 'Pemberitahu', 'layanan'],
      ['db', 'Basis Data', 'data'],
    ],
    langkah: [
      ['aktor', 'api', 'jumlah, tanggal, metode'],
      ['api', 'ctrl', 'bayar(CatatPembayaranRequest)'],
      ['ctrl', 'catat', 'catat(tagihan, jumlah, ...)'],
      ['catat', 'db', 'SELECT tagihan + pembayaran'],
      ['catat', 'bayar', 'create(...)'],
      ['bayar', 'db', 'INSERT pembayaran'],
      ['catat', 'ctrl', 'HasilPembayaran'],
      ['ctrl', 'beri', 'pembayaranDicatat(pembayaran)'],
      ['beri', 'db', 'INSERT notifikasi'],
      ['ctrl', 'api', 'PembayaranResource'],
      ['api', 'aktor', '201 + pembayaran'],
    ],
  },
  {
    kode: 'UC-09',
    judul: 'Verifikasi pembayaran daring',
    aktor: 'Pemilik',
    endpoint: 'pembayaran.verifikasi',
    balik: [6, 9, 10],
    catatan:
      'Pembayaran yang sudah diverifikasi tidak dihapus saat dikoreksi, melainkan dibalik dengan baris lawan (`PembalikPembayaran`), supaya jejaknya tetap terbaca di rekap.',
    peserta: [
      ['aktor', 'Pemilik', 'aktor'],
      ['api', 'API  POST /pembayaran/{id}/verifikasi', 'batas'],
      ['ctrl', 'PembayaranController', 'kendali'],
      ['verif', 'PencatatVerifikasi', 'layanan'],
      ['beri', 'Pemberitahu', 'layanan'],
      ['db', 'Basis Data', 'data'],
    ],
    langkah: [
      ['aktor', 'api', 'keputusan verifikasi'],
      ['api', 'ctrl', 'verifikasi(VerifikasiRequest)'],
      ['ctrl', 'verif', 'terima(pembayaran, pemeriksa)'],
      ['verif', 'db', 'INSERT verifikasi_pembayaran'],
      ['verif', 'db', 'UPDATE status pembayaran'],
      ['verif', 'ctrl', 'HasilVerifikasi'],
      ['ctrl', 'beri', 'pembayaranDiverifikasi(pembayaran)'],
      ['beri', 'db', 'INSERT notifikasi'],
      ['ctrl', 'api', 'PembayaranResource'],
      ['api', 'aktor', '200 + pembayaran'],
    ],
  },
  {
    kode: 'UC-10',
    judul: 'Lihat rekap pendapatan',
    aktor: 'Pemilik',
    endpoint: 'rekap.index',
    balik: [5, 6, 7, 8],
    catatan:
      'Pengelola hanya melihat uang tunai yang ia pegang sendiri; rekap seluruh kos adalah wewenang pemilik. Batas itu ditegakkan di kueri, bukan di tampilan.',
    peserta: [
      ['aktor', 'Pemilik', 'aktor'],
      ['api', 'API  GET /rekap', 'batas'],
      ['ctrl', 'PembayaranController', 'kendali'],
      ['rekap', 'RekapPendapatan', 'layanan'],
      ['db', 'Basis Data', 'data'],
    ],
    langkah: [
      ['aktor', 'api', 'rentang tanggal'],
      ['api', 'ctrl', 'rekap(RekapRequest)'],
      ['ctrl', 'rekap', 'susun(rentang, cakupan)'],
      ['rekap', 'db', 'SELECT pembayaran + tagihan'],
      ['db', 'rekap', 'baris teragregasi'],
      ['rekap', 'ctrl', 'RekapPendapatan'],
      ['ctrl', 'api', 'RekapResource'],
      ['api', 'aktor', '200 + rekap'],
    ],
  },
  {
    kode: 'UC-11',
    judul: 'Ubah pengaturan notifikasi',
    aktor: 'Pemilik / Pengelola / Penghuni',
    endpoint: 'pengaturan-notifikasi.update',
    balik: [5, 7, 8],
    catatan:
      'Kanal yang tidak tersedia di server tidak ditawarkan sebagai pilihan; `PengaturanKanal::aktif()` yang menentukan, supaya penghuni tidak menyalakan kanal yang tidak akan pernah mengirim apa pun.',
    peserta: [
      ['aktor', 'Pengguna', 'aktor'],
      ['api', 'API  PUT /pengaturan-notifikasi', 'batas'],
      ['ctrl', 'PengaturanNotifikasiController', 'kendali'],
      ['kanal', 'PengaturanKanal', 'layanan'],
      ['db', 'Basis Data', 'data'],
    ],
    langkah: [
      ['aktor', 'api', 'kanal dan jenis peristiwa'],
      ['api', 'ctrl', 'update(PengaturanRequest)'],
      ['ctrl', 'kanal', 'aktif()'],
      ['kanal', 'db', 'baca konfigurasi kanal'],
      ['db', 'kanal', 'kanal tersedia'],
      ['ctrl', 'db', 'UPDATE pengaturan_notifikasi'],
      ['ctrl', 'api', 'PengaturanResource'],
      ['api', 'aktor', '200 + pengaturan'],
    ],
  },
  {
    kode: 'UC-12',
    judul: 'Buat akun pengelola',
    aktor: 'Pemilik',
    endpoint: 'pengelola.store',
    balik: [7, 10, 11],
    catatan:
      'Kata sandi tidak dipilih pemilik dan tidak pernah dikirim lewat layar: akun dibuat dengan sandi acak, lalu tautan aktivasi berbatas waktu dikirim ke email pengelola.',
    peserta: [
      ['aktor', 'Pemilik', 'aktor'],
      ['api', 'API  POST /pengelola', 'batas'],
      ['ctrl', 'PengelolaController', 'kendali'],
      ['buat', 'PembuatAkun', 'layanan'],
      ['tautan', 'TautanAkun', 'layanan'],
      ['audit', 'PencatatAudit', 'layanan'],
      ['db', 'Basis Data', 'data'],
    ],
    langkah: [
      ['aktor', 'api', 'nama, email, wewenang'],
      ['api', 'ctrl', 'store(SimpanPengelolaRequest)'],
      ['ctrl', 'buat', 'untukPengelola(data)'],
      ['buat', 'db', 'INSERT users + pengelola_kewenangan'],
      ['ctrl', 'tautan', 'terbitkan(user)'],
      ['tautan', 'db', 'INSERT token_akun'],
      ['tautan', 'ctrl', 'kirim(email aktivasi)'],
      ['ctrl', 'audit', 'catat(aksi, objek)'],
      ['audit', 'db', 'INSERT log_audit'],
      ['ctrl', 'api', 'PengelolaResource'],
      ['api', 'aktor', '201 + pengelola'],
    ],
  },
  {
    kode: 'UC-13',
    judul: 'Penghuni melihat tagihannya',
    aktor: 'Penghuni',
    endpoint: 'penghuni.tagihan',
    balik: [5, 6, 7, 8],
    catatan:
      'Portal penghuni baca-saja. Yang membatasi bukan peran di peramban melainkan cakupan kueri: baris tagihan disaring menurut kontrak milik akun yang meminta.',
    peserta: [
      ['aktor', 'Penghuni', 'aktor'],
      ['api', 'API  GET /portal/tagihan', 'batas'],
      ['ctrl', 'PortalPenghuniController', 'kendali'],
      ['tagihan', 'Tagihan', 'model'],
      ['db', 'Basis Data', 'data'],
    ],
    langkah: [
      ['aktor', 'api', 'minta daftar tagihan'],
      ['api', 'ctrl', 'tagihan()'],
      ['ctrl', 'tagihan', 'scopeMilikPenghuni(user)'],
      ['tagihan', 'db', 'SELECT tagihan milik kontrak akun'],
      ['db', 'tagihan', 'baris tagihan + denda berjalan'],
      ['tagihan', 'ctrl', 'koleksi tagihan'],
      ['ctrl', 'api', 'TagihanResource[]'],
      ['api', 'aktor', '200 + tagihan'],
    ],
  },
  {
    kode: 'UC-14',
    judul: 'Tambah rekening tujuan',
    aktor: 'Pemilik',
    endpoint: 'rekening.store',
    balik: [4, 9, 10],
    catatan:
      'Gambar QRIS diperiksa sebelum disimpan: yang bukan gambar, atau lebih besar dari batas, ditolak `GambarQrisDitolak` dan tidak pernah masuk basis data.',
    peserta: [
      ['aktor', 'Pemilik', 'aktor'],
      ['api', 'API  POST /rekening', 'batas'],
      ['ctrl', 'RekeningController', 'kendali'],
      ['qris', 'PenyimpanGambarQris', 'layanan'],
      ['rekening', 'Rekening', 'model'],
      ['audit', 'PencatatAudit', 'layanan'],
      ['db', 'Basis Data', 'data'],
    ],
    langkah: [
      ['aktor', 'api', 'bank, nomor, atas nama, QRIS'],
      ['api', 'ctrl', 'store(SimpanRekeningRequest)'],
      ['ctrl', 'qris', 'periksa(berkas)'],
      ['qris', 'ctrl', 'gambar sah'],
      ['ctrl', 'rekening', 'create(..., gambar)'],
      ['rekening', 'db', 'INSERT rekening'],
      ['ctrl', 'audit', 'catat(aksi, objek)'],
      ['audit', 'db', 'INSERT log_audit'],
      ['ctrl', 'api', 'RekeningResource'],
      ['api', 'aktor', '201 + rekening'],
    ],
  },
];

module.exports = { URUTAN };

/* eslint-enable max-len */
