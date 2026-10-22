<?php

namespace App\Domain\Billing;

use App\Models\Pembayaran;
use App\Models\Tagihan;

/**
 * Hasil pencatatan satu pembayaran.
 *
 * Membawa status sebelum dan sesudah, bukan hanya status akhir: layar perlu
 * memberi tahu "status berubah dari belum bayar menjadi sebagian", dan tanpa
 * nilai lama itu ia hanya bisa menampilkan keadaan sekarang — yang sudah
 * terlihat di tabel.
 */
final class HasilPembayaran
{
    public function __construct(
        public readonly Pembayaran $pembayaran,
        public readonly string $statusSebelum,
        public readonly string $statusSesudah,
        /** Total yang dibebankan: `pokok + denda`, atau `total_final` bila sudah lunas. */
        public readonly int $total,
        public readonly int $dibayar,
        public readonly int $sisa,
    ) {}

    public function berubahMenjadiLunas(): bool
    {
        return $this->statusSesudah === Tagihan::STATUS_LUNAS;
    }
}
