<?php

namespace App\Domain\Audit;

use App\Models\LogAudit;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Pencatat jejak audit (R-07).
 *
 * Dipanggil **di dalam transaksi yang sama** dengan perubahan yang dicatat.
 * Kalau pencatatan dilakukan setelah commit, ada jendela waktu di mana data
 * sudah berubah tetapi jejaknya belum ada — dan kegagalan tepat di jendela itu
 * menghasilkan perubahan tanpa jejak, yaitu persis keadaan yang ingin dicegah
 * log audit.
 *
 * Aktor diambil dari sesi saat pemanggilan, bukan dioper sebagai argumen. Itu
 * disengaja: kalau tiap pemanggil harus mengoper aktor, satu pemanggil yang
 * lupa akan menghasilkan baris beraktor kosong, dan jejaknya menjadi tidak
 * berguna. Pemanggil yang tidak punya sesi (perintah artisan, seeder) tetap
 * menghasilkan baris dengan `aktor_id` null — jejak tanpa aktor masih lebih
 * baik daripada tidak ada jejak.
 */
class PencatatAudit
{
    public const AKSI_TAGIHAN_TERBIT = 'tagihan.terbit';
    public const AKSI_TARIF_TAMBAH = 'tarif.tambah';
    public const AKSI_PARAMETER_DENDA_TAMBAH = 'parameter_denda.tambah';

    public function catat(
        string $aksi,
        string $entitas,
        ?int $entitasId = null,
        ?array $nilaiLama = null,
        ?array $nilaiBaru = null,
    ): LogAudit {
        $aktor = $this->aktorSekarang();

        return LogAudit::create([
            'aktor_id' => $aktor?->getAuthIdentifier(),
            // Salinan beku: mengganti nama pengguna tidak boleh menulis ulang
            // sejarah log yang sudah ada.
            'aktor_nama' => $aktor?->getAttribute('name'),
            'aksi' => $aksi,
            'entitas' => $entitas,
            'entitas_id' => $entitasId,
            'nilai_lama' => $nilaiLama,
            'nilai_baru' => $nilaiBaru,
        ]);
    }

    private function aktorSekarang(): ?Authenticatable
    {
        // `auth()->user()` mengembalikan null di luar permintaan HTTP. Itu bukan
        // kesalahan: seeder dan perintah artisan tetap boleh menulis jejak.
        return auth()->user();
    }
}
