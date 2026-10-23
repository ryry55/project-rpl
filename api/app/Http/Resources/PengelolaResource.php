<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu akun pengelola beserta wewenangnya (T24).
 *
 * Kata sandi tidak pernah muncul di sini - bahkan hash-nya. Layar hanya perlu
 * tahu akun ini ada dan boleh apa.
 *
 * @mixin User
 */
class PengelolaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->name,
            'email' => $this->email,
            'peran' => $this->peran,
            'pemilik_id' => $this->pemilik_id,
            // `pluck` lalu `values`, bukan `->toArray()`: kolom `user_id` tidak
            // perlu ikut terkirim, dan daftar yang berisi kunci aneh membuat
            // layar harus menebak bentuknya.
            'wewenang' => $this->whenLoaded(
                'kewenangan',
                fn () => $this->kewenangan->pluck('wewenang')->values()->all(),
                [],
            ),
        ];
    }
}
