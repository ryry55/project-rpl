<?php

namespace App\Http\Resources;

use App\Models\ParameterDenda;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ParameterDenda
 */
class ParameterDendaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Persentase dikirim sebagai angka agar UI mudah memakainya, tetapi
            // perhitungan di server selalu memakai nilai decimal dari database,
            // tidak pernah dari representasi JSON ini.
            'tarif_persen' => (float) $this->tarif_persen,
            'plafon_persen' => (float) $this->plafon_persen,
            'masa_tenggang' => $this->masa_tenggang,
            'berlaku_dari' => $this->berlaku_dari?->toDateString(),
            // Bentuk teks dipakai untuk ditampilkan tanpa risiko pembulatan
            // biner, misalnya "1.00" dan "30.00".
            'tarif_persen_teks' => (string) $this->tarif_persen,
            'plafon_persen_teks' => (string) $this->plafon_persen,
        ];
    }
}
