<?php

namespace App\Http\Resources;

use App\Models\Kamar;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Kamar
 */
class KamarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nomor' => $this->nomor,
            'tipe' => $this->tipe,
            'status_huni' => $this->status_huni,
            'catatan' => $this->catatan,
            'penghuni' => $this->whenLoaded('kontrakAktif', fn () => $this->kontrakAktif?->penghuni
                ? [
                    'id' => $this->kontrakAktif->penghuni->id,
                    'nama' => $this->kontrakAktif->penghuni->nama,
                ]
                : null),
            'tarif_terakhir' => $this->whenLoaded('tarifTerakhir', fn () => $this->tarifTerakhir
                ? [
                    'nilai' => (int) $this->tarifTerakhir->nilai,
                    'berlaku_dari' => $this->tarifTerakhir->berlaku_dari?->toDateString(),
                ]
                : null),
            // Fasilitas yang dimiliki kamar (T18). Dikirim sebagai daftar utuh,
            // bukan jumlah, karena layar kamar menampilkannya sebagai label.
            // Fasilitas tidak pernah muncul di tagihan - lihat `KomponenBiaya`.
            'fasilitas' => $this->whenLoaded('fasilitas', fn () => $this->fasilitas
                ->map(fn ($f) => [
                    'id' => $f->id,
                    'nama' => $f->nama,
                    'ikon' => $f->ikon,
                ])->values()),
            // Laporan kerusakan yang belum selesai (T14). Inilah penjelasan
            // kenapa `status_huni` bernilai `perbaikan`; tanpa ini layar hanya
            // menampilkan lencana tanpa alasan, dan pemilik harus membuka
            // halaman lain untuk tahu apa yang rusak.
            'laporan_terbuka' => $this->whenLoaded('laporanTerbuka', fn () => $this->laporanTerbuka
                ? [
                    'id' => $this->laporanTerbuka->id,
                    'deskripsi' => $this->laporanTerbuka->deskripsi,
                    'dilaporkan_pada' => $this->laporanTerbuka->created_at?->toIso8601String(),
                    'pelapor' => $this->laporanTerbuka->pelapor?->name,
                ]
                : null),
        ];
    }
}
