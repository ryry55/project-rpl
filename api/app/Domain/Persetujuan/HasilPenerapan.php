<?php

namespace App\Domain\Persetujuan;

use App\Domain\Notifikasi\Surat;
use App\Models\KebijakanKos;
use App\Models\KomponenBiaya;
use App\Models\TarifSewa;

/**
 * Baris yang baru ditulis, beserta surat yang menunggu dikirim (T26).
 *
 * Kenapa dibungkus. Notifikasi in-app harus ditulis **di dalam** transaksi
 * penulisan baris (supaya ikut batal bila transaksinya batal), sedangkan email
 * harus dikirim **setelah** transaksi selesai (supaya SMTP yang menggantung
 * tidak menahan transaksi, dan SMTP yang gagal tidak membatalkan perubahan).
 * Dua waktu yang berbeda itu menuntut hasil yang membawa keduanya.
 *
 * `surat` boleh null: jalur persetujuan tidak mengirim pemberitahuan "tarif
 * diubah" karena pemberitahuan "usulan diputuskan" sudah menjawab pertanyaan
 * yang sama kepada orang yang sama.
 */
final class HasilPenerapan
{
    public function __construct(
        public readonly TarifSewa|KebijakanKos|KomponenBiaya $baris,
        public readonly ?Surat $surat = null,
    ) {}
}
