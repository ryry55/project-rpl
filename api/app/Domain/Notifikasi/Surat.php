<?php

namespace App\Domain\Notifikasi;

use App\Models\User;

/**
 * Notifikasi yang sudah ditulis in-app dan menunggu emailnya (T25).
 *
 * Kenapa objek, bukan array. Bentuk array akan membuat pemanggil menulis nama
 * kunci yang harus cocok dengan yang dibaca {@see Pemberitahu::setelahTransaksi},
 * dan kunci yang salah ketik menghasilkan email yang diam-diam tidak terkirim.
 */
final class Surat
{
    /** @var list<User> */
    public array $penerimaEmail = [];

    public function __construct(
        public readonly string $peristiwa,
        public readonly string $judul,
        public readonly string $pesan,
        public readonly ?string $tautan = null,
    ) {}

    public function tambahPenerimaEmail(User $user): void
    {
        $this->penerimaEmail[] = $user;
    }
}
