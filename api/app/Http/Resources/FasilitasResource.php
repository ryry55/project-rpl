<?php

namespace App\Http\Resources;

use App\Models\Fasilitas;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Fasilitas
 */
class FasilitasResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->nama,
            'ikon' => $this->ikon,
            'urutan' => $this->urutan,
            // Jumlah kamar yang memakai fasilitas ini, untuk layar pengelolaan:
            // pemilik perlu tahu fasilitas mana yang dipakai sebelum menghapus.
            'dipakai_kamar' => $this->whenCounted('kamar'),
        ];
    }
}
