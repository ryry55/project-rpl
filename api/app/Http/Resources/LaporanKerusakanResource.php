<?php

namespace App\Http\Resources;

use App\Models\LampiranKerusakan;
use App\Models\LaporanKerusakan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LaporanKerusakan
 */
class LaporanKerusakanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kamar_id' => $this->kamar_id,
            'kamar' => $this->whenLoaded('kamar', fn () => [
                'id' => $this->kamar->id,
                'nomor' => $this->kamar->nomor,
            ]),
            'deskripsi' => $this->deskripsi,
            // Nama, bukan id: yang dibaca di tabel adalah "dilaporkan Sari",
            // dan layar tidak perlu memuat daftar akun hanya untuk itu.
            'pelapor' => $this->whenLoaded('pelapor', fn () => $this->pelapor?->name),
            'dilaporkan_pada' => $this->created_at?->toIso8601String(),
            'selesai_pada' => $this->selesai_pada?->toIso8601String(),
            'penutup' => $this->whenLoaded('penutup', fn () => $this->penutup?->name),
            // Foto lampiran (K-15). Tautannya bertanda tangan dan berbatas
            // waktu; `url` bisa dipakai langsung di `src` gambar.
            'foto' => $this->whenLoaded('lampiran', fn () => $this->lampiran
                ->map(fn (LampiranKerusakan $l) => $l->ringkas())
                ->values()),
        ];
    }
}
