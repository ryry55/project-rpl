<?php

namespace App\Domain\Billing;

/**
 * Alasan sebuah pembayaran tidak dapat dicatat.
 *
 * Berupa exception berpesan Indonesia, sama seperti
 * {@see TagihanTidakDapatDiterbitkan}: controller bisa meneruskan alasannya apa
 * adanya tanpa menebak, dan pesan yang menyebut angka konkret ("sisa Rp
 * 450.000") jauh lebih berguna daripada "pembayaran tidak valid".
 */
class PembayaranDitolak extends \RuntimeException
{
    public static function jumlahTidakPositif(int $jumlah): self
    {
        return new self(sprintf(
            'Jumlah pembayaran harus lebih dari nol, bukan %s.',
            number_format($jumlah, 0, ',', '.'),
        ));
    }

    public static function melebihiSisa(int $jumlah, int $sisa): self
    {
        return new self(sprintf(
            'Pembayaran Rp %s melebihi sisa tagihan Rp %s. Kelebihan bayar tidak disimpan sebagai saldo.',
            number_format($jumlah, 0, ',', '.'),
            number_format($sisa, 0, ',', '.'),
        ));
    }

    public static function sudahLunas(int $dibayar): self
    {
        return new self(sprintf(
            'Tagihan ini sudah lunas (dibayar Rp %s). Tidak ada sisa yang bisa dibayar.',
            number_format($dibayar, 0, ',', '.'),
        ));
    }

    public static function dibatalkan(): self
    {
        return new self('Tagihan ini dibatalkan. Pembayaran tidak dapat dicatat untuk tagihan yang dibatalkan.');
    }

    public static function tanggalMasaDepan(string $tanggal, string $hariIni): self
    {
        return new self(sprintf(
            'Tanggal pembayaran %s ada di masa depan. Hari ini %s.',
            $tanggal,
            $hariIni,
        ));
    }

    public static function metodeTidakDikenal(string $metode): self
    {
        return new self(sprintf(
            'Metode pembayaran "%s" tidak dikenal. Pilih salah satu: tunai, transfer, qris, gateway.',
            $metode,
        ));
    }

    /**
     * Tunai wajib menyebut pemegang uangnya.
     *
     * Pesan ini sengaja menyebut alasannya, bukan hanya aturannya. Pemilik yang
     * membacanya perlu tahu bahwa kolom itu ada untuk menjawab "setoran siapa
     * yang belum disetor" - kalau tidak, ia akan menganggapnya kolom
     * birokratis dan mengisinya asal-asalan.
     */
    public static function tunaiTanpaPemegang(): self
    {
        return new self(
            'Pembayaran tunai harus menyebut siapa yang menerima uangnya, '
            .'supaya setoran yang masih di tangan pengelola bisa dilacak.',
        );
    }

    // --- Pembalik pembayaran (REV-FIN-01) ---------------------------------

    /**
     * Pembalikan wajib beralasan.
     *
     * Sama alasannya dengan penolakan pemeriksaan: baris negatif di rekap
     * tanpa keterangan adalah pertanyaan baru, bukan jawaban. Yang membacanya
     * enam bulan lagi harus bisa tahu kenapa uang itu ditarik.
     */
    public static function pembalikTanpaAlasan(): self
    {
        return new self(
            'Pembalikan pembayaran harus menyebutkan alasannya, '
            .'supaya angka yang berkurang di rekap bisa dijelaskan.',
        );
    }

    public static function pembalikTidakBisaDibalik(): self
    {
        return new self(
            'Baris ini adalah pembalik, bukan pembayaran. Pembalik tidak bisa dibalik lagi; '
            .'catat pembayaran baru bila uangnya memang diterima.',
        );
    }

    public static function sudahDibalik(int $jumlah): self
    {
        return new self(sprintf(
            'Pembayaran Rp %s sudah dibalik sebelumnya. Satu pembayaran hanya bisa dibalik sekali.',
            number_format($jumlah, 0, ',', '.'),
        ));
    }
}
