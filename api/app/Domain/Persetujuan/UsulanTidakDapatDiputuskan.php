<?php

namespace App\Domain\Persetujuan;

/**
 * Usulan yang tidak bisa diputuskan, dengan alasan yang bisa dibaca pemilik.
 *
 * Pesannya sengaja menyebut keadaan yang sebenarnya - "sudah diputuskan",
 * "sudah kedaluwarsa" - bukan "tidak sah". Pemilik yang menekan Setujui pada
 * usulan yang baru saja ia setujui dari tab lain perlu tahu bahwa usulannya
 * sudah selesai, bukan bahwa tombolnya rusak.
 */
class UsulanTidakDapatDiputuskan extends \RuntimeException
{
    public static function sudahDiputuskan(string $status): self
    {
        $sebab = match ($status) {
            'disetujui' => 'sudah disetujui',
            'ditolak' => 'sudah ditolak',
            'kedaluwarsa' => 'sudah kedaluwarsa',
            default => "berstatus \"{$status}\"",
        };

        return new self("Usulan ini {$sebab} dan tidak bisa diputuskan lagi.");
    }

    public static function kedaluwarsa(): self
    {
        return new self(
            'Usulan ini sudah lewat 7 hari sejak diajukan, jadi ditutup otomatis. '
            .'Minta pengelola mengajukan ulang bila perubahannya masih diperlukan.'
        );
    }

    public static function bukanMilikPengusul(): self
    {
        return new self('Usulan ini bukan diajukan oleh akun Anda.');
    }
}
