<?php

namespace App\Http\Resources;

use App\Models\KomponenBiaya;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin KomponenBiaya
 */
class KomponenBiayaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->nama,
            'nilai' => (int) $this->nilai,
            'satuan' => $this->satuan,
            'berlaku_dari' => $this->berlaku_dari?->toDateString(),
            // Baris bernilai 0 berarti komponen dihentikan pada tanggal itu.
            'dihentikan' => (int) $this->nilai === 0,
        ];
    }
}
