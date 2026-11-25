'use strict';

/**
 * Sumber tunggal diagram aktivitas untuk proposal.
 *
 * Tiga alur, dipilih karena ketiganya memuat keputusan yang benar-benar ada di
 * kode - bukan alur hiasan:
 *
 *   1. Terbit tagihan: prorata + gerbang kebijakan wajib (R-01, R-02).
 *   2. Catat pembayaran: status diturunkan dari SUM, bukan ditambah (proses 4.0).
 *   3. Denda: dibekukan saat pokok lunas dan berhenti di plafon (R-03, REV-FIN-07).
 *
 * Tata letak ditulis sebagai daftar simpul dengan kolom dan baris, bukan
 * koordinat. Koordinat yang diketik tangan berubah satu per satu setiap kali
 * satu langkah disisipkan, dan hasilnya selalu ada satu kotak yang menimpa
 * panah.
 */

/** Tinggi dan lebar tetap supaya tiap gambar punya ritme yang sama. */
const UKURAN = {
  lebarKanvas: 1120,
  kolomUtama: 300, // x kotak aksi
  lebarKotak: 380,
  tinggiKotak: 52,
  jarakBaris: 34,
  atas: 120,
};

/**
 * @typedef {object} Simpul
 * @property {'mulai'|'aksi'|'keputusan'|'selesai'|'catatan'} jenis
 * @property {string} teks
 * @property {string} [ya]    cabang "ya" dari keputusan
 * @property {string} [tidak] cabang "tidak" dari keputusan
 */

const ALUR = [
  {
    berkas: 'aktivitas-terbit-tagihan',
    judul: 'Activity Diagram Terbit Tagihan',
    nomor: 'III.2',
    /** Satu simpul per baris; `keputusan` mengalihkan alur ke cabang. */
    simpul: [
      { jenis: 'mulai', teks: 'Pemilik membuka layar Tagihan' },
      { jenis: 'keputusan', teks: 'Kebijakan kos lengkap?', ya: 'Pilih kontrak dan periode', tidak: 'Sistem menolak 422 dan menyebut tipe yang kurang' },
      { jenis: 'catatan', teks: 'Kebijakan kos lengkap (R-02): satuan sewa, jatuh tempo, denda. Tanpa ketiganya tagihan tidak boleh terbit - sistem tidak menebak angka uang.' },
      { jenis: 'aksi', teks: 'Sistem menghitung pokok: prorata bila masuk di tengah bulan' },
      { jenis: 'catatan', teks: 'Prorata = tarif x hari tinggal / hari dalam bulan, hari masuk ikut dihitung (R-01).' },
      { jenis: 'aksi', teks: 'Sistem membekukan tarif dan kebijakan yang dipakai' },
      { jenis: 'aksi', teks: 'Sistem menyimpan tagihan, rincian, dan jejak audit dalam satu transaksi' },
      { jenis: 'keputusan', teks: 'Tagihan untuk periode itu sudah ada?', ya: 'Sistem menolak, tidak menerbitkan ganda', tidak: 'Tagihan terbit berstatus belum bayar' },
      { jenis: 'selesai', teks: 'Tagihan tampil di daftar dengan total dan jatuh tempo' },
    ],
  },
  {
    berkas: 'aktivitas-catat-pembayaran',
    judul: 'Activity Diagram Catat Pembayaran',
    nomor: 'III.3',
    simpul: [
      { jenis: 'mulai', teks: 'Pemilik atau pengelola membuka rincian tagihan' },
      { jenis: 'aksi', teks: 'Memilih metode bayar dan mengisi nominal, tanggal, penerima' },
      { jenis: 'keputusan', teks: 'Nominal melebihi sisa tagihan?', ya: 'Sistem menolak 422 dan menyebut sisa yang boleh dibayar', tidak: 'Sistem menyimpan baris pembayaran' },
      { jenis: 'catatan', teks: 'Baris pembayaran append-only: trigger basis data menolak UPDATE dan DELETE. Salah input ditarik dengan baris negatif, bukan diubah (REV-FIN-01).' },
      { jenis: 'aksi', teks: 'Sistem menjumlahkan ulang seluruh pembayaran tagihan itu' },
      { jenis: 'catatan', teks: 'Status diturunkan dari SUM(pembayaran.jumlah), tidak pernah ditambahkan sedikit-sedikit - sehingga pembalikan otomatis mengembalikan status.' },
      { jenis: 'keputusan', teks: 'Jumlah bayar >= pokok + denda?', ya: 'Status menjadi lunas', tidak: 'Status menjadi sebagian' },
      { jenis: 'selesai', teks: 'Saldo dan status tagihan diperbarui, audit tercatat' },
    ],
  },
  {
    berkas: 'aktivitas-hitung-denda',
    judul: 'Activity Diagram Perhitungan Denda',
    nomor: 'III.4',
    simpul: [
      { jenis: 'mulai', teks: 'Sistem membaca tagihan pada "hari ini"' },
      { jenis: 'keputusan', teks: 'Hari ini masih dalam masa tenggang?', ya: 'Denda Rp 0', tidak: 'Hitung hari terlambat' },
      { jenis: 'catatan', teks: 'Masa tenggang dihitung dari kebijakan yang dibekukan di tagihan, bukan dari kebijakan terkini (R-06).' },
      { jenis: 'keputusan', teks: 'Pokok sudah lunas?', ya: 'Denda dibekukan pada tanggal pokok lunas', tidak: 'Denda terus berjalan' },
      { jenis: 'aksi', teks: 'Denda = persen harian x pokok x hari terlambat' },
      { jenis: 'keputusan', teks: 'Melampaui plafon?', ya: 'Denda = plafon, berhenti bertambah', tidak: 'Denda = hasil hitung' },
      { jenis: 'selesai', teks: 'Angka denda yang sama dipakai daftar tagihan, tunggakan, dan kuitansi' },
    ],
  },
];

module.exports = { ALUR, UKURAN };
