<?php

namespace App\Http\Resources;

use App\Models\AturanKos;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AturanKos
 */
class AturanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'judul' => $this->judul,
            'isi' => $this->isi,
            'kategori' => $this->kategori,
            'sanksi' => $this->sanksi,
            'aktif' => $this->aktif,
            'urutan' => $this->urutan,
        ];
    }
}
