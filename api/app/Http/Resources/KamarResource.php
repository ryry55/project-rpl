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
        ];
    }
}
