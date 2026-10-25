<?php

namespace App\Domain\Rekening;

use App\Domain\Billing\PembayaranDitolak;

/**
 * Alasan sebuah gambar QRIS tidak dapat disimpan.
 *
 * Berupa exception berpesan Indonesia, sama seperti
 * {@see PembayaranDitolak}: controller meneruskan alasannya
 * apa adanya, dan pesan yang menyebut angka konkret ("berkas 3 MB, maksimal
 * 2 MB") jauh lebih berguna daripada "berkas tidak valid".
 *
 * Pesan-pesannya sengaja menyebut apa yang harus dilakukan, bukan hanya apa
 * yang salah. Pemilik kos yang mengunggah foto buram perlu tahu bahwa ia harus
 * memotret ulang, bukan sekadar bahwa berkasnya ditolak.
 */
class GambarQrisDitolak extends \RuntimeException
{
    public static function bukanGambar(): self
    {
        return new self(
            'Berkas ini bukan gambar yang bisa dibaca. Pilih berkas PNG, JPG, atau WEBP '
            .'- nama berkas yang berakhiran .png tidak menjamin isinya gambar.'
        );
    }

    public static function formatTidakDidukung(string $mime): self
    {
        return new self(sprintf(
            'Format gambar "%s" tidak didukung. Pakai PNG, JPG, atau WEBP. '
            .'SVG sengaja ditolak karena bisa memuat skrip.',
            $mime,
        ));
    }

    public static function terlaluBesar(int $ukuran, int $batas): self
    {
        return new self(sprintf(
            'Gambar terlalu besar (%s kB). Maksimal %s MB - cukup untuk foto QRIS dari ponsel, '
            .'dan lebih besar dari itu tidak akan terbaca lebih baik.',
            number_format((int) round($ukuran / 1024), 0, ',', '.'),
            number_format((int) round($batas / 1024 / 1024), 0, ',', '.'),
        ));
    }

    public static function kosong(): self
    {
        return new self('Berkas kosong. Pilih gambar QRIS yang benar.');
    }

    public static function gagalUnggah(): self
    {
        return new self(
            'Berkas gagal diunggah. Biasanya karena ukurannya melebihi batas server; '
            .'coba pilih ulang gambarnya.'
        );
    }

    public static function hanyaUntukQris(): self
    {
        return new self(
            'Gambar hanya berlaku untuk jenis QRIS. Rekening transfer ditampilkan '
            .'sebagai nomor rekening, bukan gambar.'
        );
    }
}
