<?php

namespace App\Http\Resources;

use App\Domain\Kebijakan\PilihanKebijakan;
use App\Models\KebijakanKos;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris kebijakan.
 *
 * `label_tipe` dan `label_nilai` dikirim dari server, bukan disusun di layar.
 * Alasannya sama dengan `dihentikan` di KomponenBiayaResource: teks yang
 * menjelaskan arti sebuah nilai adalah bagian dari arti nilai itu, dan
 * menyalinnya ke JavaScript membuat dua tempat harus setuju soal istilah.
 *
 * @mixin KebijakanKos
 */
class KebijakanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // null berarti berlaku untuk seluruh kos.
            'kamar_id' => $this->kamar_id,
            'tipe' => $this->tipe,
            'nilai' => $this->nilai,
            'label_tipe' => PilihanKebijakan::labelTipe($this->tipe),
            'label_nilai' => PilihanKebijakan::labelNilai($this->tipe, $this->nilai),
            // Hanya terisi untuk jatuh tempo "tanggal tetap".
            'tanggal' => $this->tanggal,
            'berlaku_dari' => $this->berlaku_dari?->toDateString(),
        ];
    }
}
