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

    public const AKSI_TAGIHAN_BAYAR = 'tagihan.bayar';

    public const AKSI_TARIF_TAMBAH = 'tarif.tambah';

    public const AKSI_KEBIJAKAN_TAMBAH = 'kebijakan.tambah';

    /**
     * Usulan perubahan oleh pengelola (T26).
     *
     * Entitasnya `usulan_perubahan`, bukan `tarif_sewa`: saat usulan diajukan,
     * belum ada baris tarif yang ditulis. Menyebutnya `tarif_sewa` dengan
     * `entitas_id` kosong akan menghasilkan jejak yang tampak menunjuk baris
     * tarif yang tidak pernah ada.
     */
    public const AKSI_TARIF_USUL = 'tarif.usul';

    public const AKSI_KEBIJAKAN_USUL = 'kebijakan.usul';

    /**
     * Persetujuan usulan (T26).
     *
     * Entitasnya `tarif_sewa`/`kebijakan_kos` dengan id baris yang baru
     * terbentuk: yang dicatat adalah penetapannya, dan pengusulnya sudah
     * tercatat di baris `*.usul`. Dua baris, dua aktor, dua kalimat yang
     * berbeda - kalau digabung menjadi satu, salah satu aktor hilang dari
     * riwayat.
     */
    public const AKSI_TARIF_SETUJUI = 'tarif.setujui';

    public const AKSI_KEBIJAKAN_SETUJUI = 'kebijakan.setujui';

    /**
     * Penolakan usulan (T26).
     *
     * Entitasnya `usulan_perubahan`, karena tidak ada baris tarif yang lahir.
     * Tanpa baris ini, pertanyaan "kenapa tarif ini tidak jadi naik" hanya bisa
     * dijawab dari kolom `alasan` pada tabel antrean - yang tidak dibaca siapa
     * pun yang memeriksa log audit.
     */
    public const AKSI_TARIF_TOLAK = 'tarif.tolak';

    public const AKSI_KEBIJAKAN_TOLAK = 'kebijakan.tolak';

    /**
     * Satu pemeriksaan atas satu pembayaran (T20).
     *
     * Entitasnya `pembayaran`, bukan `tagihan`: yang diperiksa adalah baris uang
     * tertentu, dan dua pembayaran pada tagihan yang sama bisa punya hasil
     * pemeriksaan berbeda. Menyebutnya `tagihan.verifikasi` akan membuat jejak
     * tidak bisa menunjuk baris mana yang ditolak.
     */
    public const AKSI_PEMBAYARAN_VERIFIKASI = 'pembayaran.verifikasi';

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
