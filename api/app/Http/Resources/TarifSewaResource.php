<?php

namespace App\Http\Resources;

use App\Models\TarifSewa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TarifSewa
 */
class TarifSewaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kamar_id' => $this->kamar_id,
            'kamar' => $this->whenLoaded('kamar', fn () => $this->kamar
                ? ['id' => $this->kamar->id, 'nomor' => $this->kamar->nomor]
                : null),
            'nilai' => (int) $this->nilai,
            'berlaku_dari' => $this->berlaku_dari?->toDateString(),
        ];
    }
}
