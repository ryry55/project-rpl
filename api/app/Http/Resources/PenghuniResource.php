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
            // Status aktivasi akun (T27). Layar memakainya untuk menandai
            // penghuni yang tautannya belum dibuka dan menawarkan kirim ulang.
            // Tanpa ini pemilik melihat baris yang tampak normal padahal
            // penghuninya belum bisa masuk sama sekali.
            'sudah_aktif' => $this->whenLoaded('user', fn () => $this->user !== null
                && $this->user->email_verified_at !== null),
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
