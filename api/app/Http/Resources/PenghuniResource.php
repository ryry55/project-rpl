<?php

namespace App\Http\Resources;

use App\Models\Penghuni;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Penghuni
 */
class PenghuniResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->nama,
            'email' => $this->email,
            'no_hp' => $this->no_hp,
            'kontrak_aktif' => $this->whenLoaded('kontrakAktif', fn () => $this->kontrakAktif
                ? [
                    'id' => $this->kontrakAktif->id,
                    'tgl_masuk' => $this->kontrakAktif->tgl_masuk?->toDateString(),
                    'kamar' => $this->kontrakAktif->kamar
                        ? [
                            'id' => $this->kontrakAktif->kamar->id,
                            'nomor' => $this->kontrakAktif->kamar->nomor,
                        ]
                        : null,
                ]
                : null),
        ];
    }
}
