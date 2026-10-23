<?php

namespace App\Domain\Billing;

use App\Domain\Audit\PencatatAudit;
use App\Domain\Pembayaran\MetodePembayaran;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Support\WaktuSistem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat pembayaran dan menurunkan ulang status tagihan (proses 4.0).
 *
 * Empat keputusan yang menentukan kebenaran di sini:
 *
 * 1. **Status diturunkan dari `SUM(pembayaran.jumlah)`, tidak pernah
 *    ditambahkan sedikit-sedikit.** Kolom `tagihan.status` hanya simpanan untuk
 *    kemudahan query; sumber kebenarannya adalah jumlah uang yang benar-benar
 *    tercatat. Menambah status secara inkremental ("kalau sebagian lalu bayar
 *    lagi, tetap sebagian") akan salah begitu ada satu jalur yang lupa
 *    memperbaruinya, dan tidak ada cara mendeteksinya.
 *
 * 2. **Baris `tagihan` dikunci `FOR UPDATE` sebelum diperiksa.** Tanpa kunci,
 *    dua pembayaran yang tiba bersamaan sama-sama membaca sisa Rp 1.000.000,
 *    sama-sama menilai "tidak melebihi sisa", dan sama-sama menulis — total
 *    Rp 2.000.000 untuk tagihan Rp 1.500.000. Pemeriksaan sisa adalah
 *    baca-lalu-tulis, jadi ia wajib berada di dalam kunci.
 *
 * 3. **Denda dihitung pada tanggal acuan sistem, bukan pada tanggal
 *    pembayaran.** Denda adalah fungsi dari "sudah berapa hari terlambat
 *    sekarang". Kalau tanggal pembayaran dipakai sebagai acuan, pemilik bisa
 *    menghapus denda hanya dengan menuliskan tanggal mundur — dan itu bukan
 *    koreksi, itu penghindaran.
 *
 * 4. **Kelebihan bayar ditolak, bukan disimpan sebagai saldo.** Saldo negatif
 *    akan membuat `SUM(jumlah)` berhenti bermakna sebagai "uang yang diterima",
 *    dan status yang diturunkan dari situ bisa dibuat apa saja.
 *
 * Saat tagihan menjadi lunas, `total_final` dibekukan. Sesudah itu denda
 * berhenti berjalan, jadi tanpa pembekuan angka "total" akan turun menjadi
 * sebesar pokok saja sementara "dibayar" tetap sebesar pokok + denda — dua angka
 * yang bertentangan di satu layar.
 *
 * Sejak T20 setiap baris wajib menyebut **bagaimana** uangnya masuk dan **siapa
 * yang memegangnya**. Keduanya diisi di sini, di dalam transaksi yang sama,
 * bukan oleh controller: baris pembayaran tanpa metode akan lolos ke rekap
 * sebagai kelompok "tanpa metode", dan satu jalur tulis yang lupa mengisinya
 * sudah cukup untuk membuat rekap tidak bisa dipakai rekonsiliasi.
 */
class PencatatPembayaran
{
    public function __construct(
        private readonly PenghitungDenda $penghitung,
        private readonly WaktuSistem $waktu,
        private readonly PencatatAudit $audit,
    ) {}

    /**
     * @param  string  $metode  Salah satu dari {@see MetodePembayaran::SEMUA}.
     * @param  int|null  $diterimaOleh  Pemegang uang. Wajib untuk tunai, diisi
     *                                  otomatis dengan pencatat bila tidak
     *                                  disebut - orang yang mencatat uang tunai
     *                                  yang ia terima sendiri adalah kasus yang
     *                                  paling umum, dan memaksa pemilik memilih
     *                                  namanya sendiri setiap kali hanya
     *                                  menambah satu klik tanpa menambah fakta.
     */
    public function catat(
        Tagihan $tagihan,
        int $jumlah,
        CarbonInterface $tanggalPembayaran,
        string $metode,
        ?int $diterimaOleh = null,
        ?string $referensi = null,
        ?string $catatan = null,
    ): HasilPembayaran {
        if ($jumlah <= 0) {
            throw PembayaranDitolak::jumlahTidakPositif($jumlah);
        }

        if (! MetodePembayaran::dikenal($metode)) {
            throw PembayaranDitolak::metodeTidakDikenal($metode);
        }

        // `auth()->id()` di dalam transaksi, bukan dioper controller: aktor
        // sesi adalah satu-satunya sumber yang tidak bisa dipalsukan klien,
        // dan mengopernya dari luar membuka jalan untuk mencatat pembayaran
        // atas nama orang lain.
        $dicatatOleh = auth()->id();
        $penerima = $diterimaOleh ?? ($metode === MetodePembayaran::TUNAI ? $dicatatOleh : null);

        if ($metode === MetodePembayaran::TUNAI && $penerima === null) {
            throw PembayaranDitolak::tunaiTanpaPemegang();
        }

        return DB::transaction(function () use ($tagihan, $jumlah, $tanggalPembayaran, $metode, $penerima, $referensi, $catatan, $dicatatOleh) {
            // Baca ulang di dalam transaksi dengan kunci baris. Instance yang
            // dioper pemanggil bisa sudah basi, dan tanpa kunci dua permintaan
            // bersamaan akan saling menimpa.
            /** @var Tagihan $tagihan */
            $tagihan = Tagihan::query()->whereKey($tagihan->getKey())->lockForUpdate()->firstOrFail();

            if ($tagihan->status === Tagihan::STATUS_DIBATALKAN) {
                throw PembayaranDitolak::dibatalkan();
            }

            $sebelum = (string) $tagihan->status;
            $dibayar = $tagihan->totalDibayar();
            $total = $this->totalDibebankan($tagihan);
            $sisa = $total - $dibayar;

            if ($sisa <= 0) {
                throw PembayaranDitolak::sudahLunas($dibayar);
            }

            if ($jumlah > $sisa) {
                throw PembayaranDitolak::melebihiSisa($jumlah, $sisa);
            }

            $pembayaran = Pembayaran::create([
                'tagihan_id' => $tagihan->id,
                'jumlah' => $jumlah,
                'metode' => $metode,
                'sumber' => MetodePembayaran::SUMBER_DICATAT_PEMILIK,
                'referensi' => $referensi,
                'dicatat_oleh' => $dicatatOleh,
                'diterima_oleh' => $penerima,
                'tanggal_pembayaran' => $tanggalPembayaran->toDateString(),
                'catatan' => $catatan,
            ]);

            $dibayarBaru = $dibayar + $jumlah;
            $sisaBaru = $total - $dibayarBaru;

            // `>=` bukan `==`: kelebihan sudah ditolak di atas, jadi `>` tidak
            // mungkin terjadi — tetapi menulis `>=` membuat lunas tidak
            // bergantung pada asumsi itu tetap benar selamanya.
            $sesudah = $sisaBaru <= 0
                ? Tagihan::STATUS_LUNAS
                : Tagihan::STATUS_SEBAGIAN;

            $perubahan = ['status' => $sesudah];

            if ($sesudah === Tagihan::STATUS_LUNAS) {
                // Dibekukan tepat sekali. Tanpa ini, tagihan yang lunas akan
                // menampilkan total sebesar pokok saja sementara pembayarannya
                // berjumlah pokok + denda.
                $perubahan['total_final'] = $total;
            }

            $tagihan->update($perubahan);

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_TAGIHAN_BAYAR,
                entitas: 'tagihan',
                entitasId: $tagihan->id,
                nilaiLama: [
                    'status' => $sebelum,
                    'dibayar' => $dibayar,
                    'sisa' => $sisa,
                ],
                nilaiBaru: [
                    'status' => $sesudah,
                    'dibayar' => $dibayarBaru,
                    'sisa' => $sisaBaru,
                    'pembayaran_id' => $pembayaran->id,
                    'jumlah' => $jumlah,
                    // Metode ikut ke jejak audit. Tanpa ini, pertanyaan "uang
                    // tunai Rp 500.000 tanggal 10 itu masuk ke siapa" hanya
                    // bisa dijawab dengan membuka tabel pembayaran langsung -
                    // dan jejak audit yang menuntut query lain untuk lengkap
                    // bukan jejak yang bisa ditunjukkan ke penguji.
                    'metode' => $metode,
                    'diterima_oleh' => $penerima,
                    'tanggal_pembayaran' => $tanggalPembayaran->toDateString(),
                ],
            );

            return new HasilPembayaran(
                pembayaran: $pembayaran,
                statusSebelum: $sebelum,
                statusSesudah: $sesudah,
                total: $total,
                dibayar: $dibayarBaru,
                sisa: $sisaBaru,
            );
        });
    }

    /**
     * Total yang dibebankan kepada penghuni.
     *
     * Tagihan yang sudah lunas memakai `total_final` yang dibekukan; yang belum
     * memakai `pokok + denda hari ini`. Denda dibaca lewat {@see PenghitungDenda}
     * yang sama dengan endpoint `/denda` dan halaman Tunggakan — bukan rumus
     * salinan, karena tiga salinan rumus yang sama pasti akan menyimpang.
     */
    private function totalDibebankan(Tagihan $tagihan): int
    {
        if ($tagihan->total_final !== null) {
            return (int) $tagihan->total_final;
        }

        return $this->penghitung->hitung($tagihan)->total();
    }
}
