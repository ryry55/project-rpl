<?php

namespace App\Domain\Pembayaran;

use RuntimeException;

/**
 * Gateway menolak permintaan, atau jawabannya tidak bisa dipakai.
 *
 * Pesan yang disimpan di sini **untuk log dan operator**, bukan untuk penghuni:
 * jawaban Midtrans menyebut nama parameter internal dan kadang nilai kunci yang
 * salah. Controller mengembalikan pesan umum ke klien.
 */
class GatewayMenolak extends RuntimeException
{
    /**
     * @param  array<int, string>|string|null  $alasan
     */
    public static function dariJawaban(int $status, array|string|null $alasan): self
    {
        $teks = is_array($alasan) ? implode('; ', $alasan) : (string) $alasan;

        return new self(sprintf(
            'Midtrans menjawab HTTP %d: %s',
            $status,
            $teks !== '' ? $teks : '(tanpa pesan)',
        ));
    }

    public static function tanpaToken(): self
    {
        return new self('Midtrans menjawab sukses tetapi tidak mengirim token transaksi.');
    }
}
