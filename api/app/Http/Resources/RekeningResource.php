<?php

namespace App\Http\Resources;

use App\Models\Rekening;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rekening
 */
class RekeningResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'jenis' => $this->jenis,
            // Label dikirim server, bukan disusun layar: daftar kosakata jenis
            // rekening hanya boleh ada di satu tempat (`Rekening::LABEL_JENIS`),
            // sama seperti label metode pembayaran.
            'jenis_label' => Rekening::LABEL_JENIS[$this->jenis] ?? $this->jenis,
            'nama' => $this->nama,
            'nomor' => $this->nomor,
            'atas_nama' => $this->atas_nama,
            'catatan' => $this->catatan,
            'aktif' => (bool) $this->aktif,
            'urutan' => (int) $this->urutan,
        ];
    }
}
