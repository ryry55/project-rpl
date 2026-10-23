<?php

namespace App\Domain\Pembayaran;

use App\Domain\Billing\PembayaranDitolak;

/**
 * Alasan pemeriksaan pembayaran tidak dapat dicatat.
 *
 * Sama seperti {@see PembayaranDitolak}: exception
 * berpesan Indonesia yang diteruskan controller apa adanya.
 */
class VerifikasiDitolak extends \RuntimeException
{
    public static function tanpaAlasan(): self
    {
        return new self(
            'Penolakan pembayaran harus menyebutkan alasannya, supaya yang mencatatnya '
            .'tahu apa yang perlu diperbaiki dan tidak mencatat ulang baris yang sama.',
        );
    }
}
