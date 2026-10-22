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
}
