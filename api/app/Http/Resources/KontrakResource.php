<?php

namespace App\Http\Resources;

use App\Models\Kontrak;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Kontrak
 */
class KontrakResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kamar_id' => $this->kamar_id,
            'penghuni_id' => $this->penghuni_id,
            'tgl_masuk' => $this->tgl_masuk?->toDateString(),
            'tgl_keluar' => $this->tgl_keluar?->toDateString(),
            // Uang selalu dikirim sebagai integer rupiah, tidak pernah float.
            'tarif_awal' => (int) $this->tarif_awal,
            'status' => $this->status,
            'kamar' => $this->whenLoaded('kamar', fn () => $this->kamar
                ? ['id' => $this->kamar->id, 'nomor' => $this->kamar->nomor]
                : null),
            'penghuni' => $this->whenLoaded('penghuni', fn () => $this->penghuni
                ? ['id' => $this->penghuni->id, 'nama' => $this->penghuni->nama]
                : null),
        ];
    }
}
