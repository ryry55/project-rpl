<?php

namespace App\Jobs;

use App\Domain\Notifikasi\Pemberitahu;
use App\Domain\Notifikasi\Surat;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Satu email notifikasi, dikirim oleh pekerja antrean (Fase 5.5).
 *
 * Kenapa satu pekerjaan per penerima, bukan satu pekerjaan untuk semua.
 * Penerbitan massal menyurati 30 penghuni; sebagai satu pekerjaan, satu
 * alamat yang ditolak SMTP membatalkan 29 email lain yang sebenarnya baik.
 * Sebagai 30 pekerjaan, kegagalannya berhenti di penerimanya sendiri.
 *
 * Yang dibawa hanya nilai skalar dan id penerima. `Surat` memegang model
 * `User`, dan model yang ikut diserialisasi ke antrean adalah potret data
 * kemarin - alamat email yang sudah diganti tetap menerima surat lama.
 *
 * Kegagalan kirim ditangani di {@see Pemberitahu::kirimEmail}, tidak
 * dilempar ke antrean: jejak `log_audit` (B3) hanya terisi kalau kegagalan
 * melewati jalur itu, sedangkan pekerjaan yang gagal lalu diulang tidak
 * menulis apa pun tentang email yang hilang.
 */
final class KirimPemberitahuan implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $peristiwa,
        public readonly string $judul,
        public readonly string $pesan,
        public readonly ?string $tautan,
        public readonly int $penerimaId,
    ) {}

    public function handle(Pemberitahu $pemberitahu): void
    {
        $penerima = User::find($this->penerimaId);

        // Akun bisa dihapus antara penerbitan dan pengiriman. Tidak ada
        // penerima berarti tidak ada yang perlu dikirim - bukan kegagalan.
        if ($penerima === null) {
            return;
        }

        $pemberitahu->kirimEmail(
            new Surat($this->peristiwa, $this->judul, $this->pesan, $this->tautan),
            $penerima,
        );
    }
}
