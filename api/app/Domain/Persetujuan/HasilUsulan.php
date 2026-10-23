<?php

namespace App\Domain\Persetujuan;

use App\Domain\Notifikasi\Surat;
use App\Models\UsulanPerubahan;

/**
 * Usulan yang baru ditulis, beserta surat yang menunggu dikirim (T26).
 *
 * Kenapa dibungkus, bukan dikembalikan sebagai `UsulanPerubahan` saja. Email
 * harus dikirim **setelah** transaksi selesai, dan controller butuh kedua
 * objeknya untuk melakukan itu. Mengembalikan model saja akan membuat controller
 * harus mencari sendiri siapa penerimanya - dan itu berarti aturan "pemilik
 * saja, kecuali pengusul" hidup dua kali.
 */
final class HasilUsulan
{
    public function __construct(
        public readonly UsulanPerubahan $usulan,
        public readonly Surat $surat,
    ) {}
}
