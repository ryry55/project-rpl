<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\DaftarTunggakan;
use App\Domain\Laporan\EksporCsv;
use App\Domain\Pembayaran\MetodePembayaran;
use App\Domain\Pembayaran\RekapPendapatan;
use App\Http\Controllers\Controller;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Support\WaktuSistem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor CSV untuk rekap, tunggakan, dan daftar pembayaran.
 *
 * **Kenapa ekspor ada sama sekali.** Pemilik kos kecil hampir selalu sudah
 * punya cara membukukan uangnya sendiri - buku tulis, atau satu berkas Excel
 * yang diwariskan dari kos sebelumnya. Aplikasi yang tidak bisa mengeluarkan
 * datanya memaksa dia mengetik ulang, dan orang yang mengetik ulang akan
 * berhenti memakai aplikasinya. Ekspor bukan fitur tambahan; ia syarat agar
 * aplikasi ini dipakai lebih dari satu bulan.
 *
 * **Kenapa ketiganya berbeda endpoint, bukan satu `/ekspor?jenis=`.** Yang
 * berbeda bukan formatnya, melainkan **siapa yang boleh membaca apa**:
 *
 *   - Rekap adalah gambaran utuh pendapatan kos, dan sejak REV-SEC-06 hanya
 *     pemilik. Pengelola yang bisa mengekspornya memegang penghasilan
 *     pemiliknya dalam satu berkas yang bisa dibawa pulang.
 *   - Tunggakan berisi nama penghuni dan utangnya. Pengelola yang menagih
 *     memang membutuhkannya.
 *   - Daftar pembayaran juga dibutuhkan pengelola - ia yang mencatatnya.
 *
 * Satu endpoint dengan satu pemeriksaan peran tidak bisa menyatakan tiga
 * aturan berbeda tanpa cabang `if` yang menyembunyikan salah satunya. Tiga
 * endpoint membuat aturannya terbaca dari daftar rute, tempat orang mencarinya.
 *
 * **Yang diekspor adalah keadaan saat tombol ditekan, bukan tautan abadi.**
 * Tidak ada token unduhan yang berlaku lama: setiap permintaan diperiksa
 * ulang. Tautan ekspor yang bisa dibuka tanpa sesi adalah berkas keuangan yang
 * bocor lewat riwayat peramban dan pesan WhatsApp.
 */
class EksporController extends Controller
{
    public function __construct(
        private readonly WaktuSistem $waktu,
        private readonly DaftarTunggakan $tunggakan,
        private readonly RekapPendapatan $rekap,
    ) {}

    /**
     * Rekap pendapatan per metode pada satu rentang, sebagai CSV.
     *
     * Isinya sengaja **dua tabel dalam satu berkas**: ringkasan per metode di
     * atas, lalu daftar transaksinya di bawah. Memisahkannya menjadi dua berkas
     * membuat pemilik harus mencocokkan dua unduhan, dan yang pertama
     * ditanyakan saat angkanya tidak cocok adalah "yang mana yang benar".
     */
    public function rekap(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);

        $hariIni = $this->waktu->hariIni();
        $dari = isset($data['dari'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $data['dari'])
            : $hariIni->startOfMonth();
        $sampai = isset($data['sampai'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $data['sampai'])
            : $hariIni;

        $ringkasan = $this->rekap->susun($dari, $sampai);

        $transaksi = Pembayaran::query()
            ->whereBetween('tanggal_pembayaran', [$dari->toDateString(), $sampai->toDateString()])
            ->with(['tagihan.kontrak.penghuni', 'tagihan.kontrak.kamar', 'penerima', 'pencatat'])
            ->orderBy('tanggal_pembayaran')
            ->orderBy('id')
            ->get();

        $labelMetode = MetodePembayaran::label();

        $baris = [];

        // --- Bagian 1: ringkasan -------------------------------------------
        $baris[] = ['Rekap pendapatan'];
        $baris[] = ['Dari', $ringkasan['dari']];
        $baris[] = ['Sampai', $ringkasan['sampai']];
        $baris[] = ['Total diterima (Rp)', $ringkasan['total']];
        $baris[] = ['Jumlah transaksi', $ringkasan['jumlah_transaksi']];
        $baris[] = ['Ditarik kembali (Rp)', $ringkasan['pembalik']['jumlah']];
        $baris[] = ['Denda belum dibayar - piutang, bukan pendapatan (Rp)', $ringkasan['piutang']['denda_belum_dibayar']];
        $baris[] = [];

        $baris[] = ['Per metode', 'Jumlah (Rp)', 'Transaksi'];
        foreach ($ringkasan['per_metode'] as $m) {
            $baris[] = [$m['label'], $m['jumlah'], $m['transaksi']];
        }

        // Kelompok "tanpa metode" hanya ditulis bila ada isinya. Baris nol
        // yang selalu muncul akan membuat pembaca mengira ada data rusak.
        if ($ringkasan['tanpa_metode']['transaksi'] > 0) {
            $baris[] = ['Tanpa metode', $ringkasan['tanpa_metode']['jumlah'], $ringkasan['tanpa_metode']['transaksi']];
        }
        $baris[] = [];

        if ($ringkasan['tunai_di_tangan'] !== []) {
            $baris[] = ['Uang tunai di tangan', 'Jumlah (Rp)', 'Transaksi'];
            foreach ($ringkasan['tunai_di_tangan'] as $t) {
                $baris[] = [$t['nama'], $t['jumlah'], $t['transaksi']];
            }
            $baris[] = [];
        }

        // --- Bagian 2: daftar transaksi ------------------------------------
        $baris[] = ['Daftar transaksi'];
        $baris[] = [
            'Tanggal', 'Penghuni', 'Kamar', 'Periode', 'Metode', 'Sumber',
            'Jumlah (Rp)', 'Diterima oleh', 'Dicatat oleh', 'Referensi',
            'Ditarik kembali', 'Catatan',
        ];

        foreach ($transaksi as $p) {
            $kontrak = $p->tagihan?->kontrak;

            $baris[] = [
                $p->tanggal_pembayaran?->toDateString(),
                $kontrak?->penghuni?->nama,
                $kontrak?->kamar?->nomor,
                $p->tagihan?->periode?->toDateString(),
                $p->metode !== null ? ($labelMetode[$p->metode] ?? $p->metode) : 'Tanpa metode',
                $p->sumber,
                // Angka mentah, bukan "Rp 1.500.000": lihat catatan 4 di
                // EksporCsv. Nilai negatif (baris pembalik) tetap negatif.
                (int) $p->jumlah,
                $p->penerima?->name,
                $p->pencatat?->name,
                $p->referensi,
                $p->adalahPembalik() ? 'ya' : 'tidak',
                $p->catatan,
            ];
        }

        $nama = sprintf('rekap-%s-sampai-%s.csv', $ringkasan['dari'], $ringkasan['sampai']);

        // Judul kolom kosong: seluruh berkas ini ditulis sendiri di atas, jadi
        // `EksporCsv` hanya dipakai sebagai penulis baris dan pengatur format.
        return (new EksporCsv([], $nama))->unduh($baris);
    }

    /**
     * Daftar tunggakan per kelompok umur, sebagai CSV.
     *
     * Memakai `DaftarTunggakan` yang sama dengan layar, bukan query salinan.
     * Kalau denda dihitung ulang di sini, berkas ekspor dan layar akan
     * menyebut angka berbeda untuk tagihan yang sama begitu salah satunya
     * diubah - dan yang dibawa ke pertemuan dengan penghuni adalah berkasnya.
     */
    public function tunggakan(): StreamedResponse
    {
        $data = $this->tunggakan->susun();

        // Tabel bersih: baris judul kolom lebih dulu, tanpa blok ringkasan.
        //
        // **Kenapa tidak ada blok "Tanggal acuan / Jumlah tagihan / Total".**
        // Berkas ini dibuat untuk DIGABUNG ke pembukuan yang sudah dipakai
        // pemilik, dan setiap alat yang membaca CSV (Excel, LibreOffice, skrip
        // milik pemilik sendiri) menganggap baris pertama sebagai judul kolom.
        // Blok ringkasan di atasnya membuat seluruh berkas terbaca sebagai satu
        // tabel yang kolomnya salah nama, dan pemilik harus membuang enam baris
        // dengan tangan setiap kali - pekerjaan yang akan ia lupakan, lalu
        // angkanya masuk ke sheet dengan kolom bergeser.
        //
        // Tanggal acuannya tidak hilang: ia ada di nama berkas
        // (`tunggakan-2026-10-26.csv`), yang tetap terbaca setelah berkasnya
        // disimpan. Angka totalnya juga tidak hilang: ia ada di layar, dan
        // berkas ini justru ada supaya pemilik bisa menjumlahkannya sendiri.
        $baris = [];

        $baris[] = [
            'Kelompok', 'Penghuni', 'Kamar', 'Periode', 'Jatuh tempo',
            'Pokok (Rp)', 'Denda (Rp)', 'Total (Rp)', 'Hari terlambat', 'Status',
        ];

        foreach ($data['kelompok'] as $kelompok) {
            foreach ($kelompok['tagihan'] as $t) {
                $baris[] = [
                    $kelompok['label'],
                    $t['penghuni'],
                    $t['kamar'],
                    $t['periode'],
                    $t['jatuh_tempo'],
                    $t['pokok'],
                    $t['denda'],
                    $t['total'],
                    $t['hari_terlambat'],
                    $t['status'],
                ];
            }
        }

        return (new EksporCsv([], 'tunggakan-'.$data['tanggal_acuan'].'.csv'))->unduh($baris);
    }

    /**
     * Daftar pembayaran pada satu rentang, sebagai CSV.
     *
     * Rentang wajib, sama seperti `GET /pembayaran`: ekspor yang diam-diam
     * memilih rentangnya sendiri adalah ekspor yang menipu saat
     * dipertanyakan - dan berbeda dari layar, berkasnya tidak menyebut
     * rentang yang dipakai di tempat yang mudah terlihat.
     */
    public function pembayaran(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'dari' => ['required', 'date_format:Y-m-d'],
            'sampai' => ['required', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);

        $daftar = Pembayaran::query()
            ->whereBetween('tanggal_pembayaran', [$data['dari'], $data['sampai']])
            ->with([
                'tagihan.kontrak.penghuni',
                'tagihan.kontrak.kamar',
                'penerima',
                'pencatat',
                'pembalik',
                'verifikasi',
            ])
            ->orderBy('tanggal_pembayaran')
            ->orderBy('id')
            ->get();

        $labelMetode = MetodePembayaran::label();

        // Tabel bersih, dengan alasan yang sama seperti `tunggakan()`: berkas
        // ini untuk digabung ke pembukuan yang sudah ada, dan blok ringkasan di
        // atas judul kolom membuat seluruh berkas terbaca sebagai satu tabel
        // yang kolomnya bergeser. Rentang tanggalnya ada di nama berkas.
        $baris = [];

        $baris[] = [
            'Tanggal', 'Penghuni', 'Kamar', 'Periode', 'Metode', 'Sumber',
            'Jumlah (Rp)', 'Diterima oleh', 'Dicatat oleh', 'Pemeriksaan',
            'Ditarik kembali', 'Referensi', 'Catatan',
        ];

        foreach ($daftar as $p) {
            $kontrak = $p->tagihan?->kontrak;

            $baris[] = [
                $p->tanggal_pembayaran?->toDateString(),
                $kontrak?->penghuni?->nama,
                $kontrak?->kamar?->nomor,
                $p->tagihan?->periode?->toDateString(),
                $p->metode !== null ? ($labelMetode[$p->metode] ?? $p->metode) : 'Tanpa metode',
                $p->sumber,
                (int) $p->jumlah,
                $p->penerima?->name,
                $p->pencatat?->name,
                // Status pemeriksaan terakhir. "belum diperiksa" dibedakan dari
                // "ditolak" - lihat catatan di `Pembayaran::statusVerifikasi`.
                $p->statusVerifikasi() ?? 'belum diperiksa',
                $p->adalahPembalik() ? 'ya' : 'tidak',
                $p->referensi,
                $p->catatan,
            ];
        }

        $nama = sprintf('pembayaran-%s-sampai-%s.csv', $data['dari'], $data['sampai']);

        return (new EksporCsv([], $nama))->unduh($baris);
    }
}
