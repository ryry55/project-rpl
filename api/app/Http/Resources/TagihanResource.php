<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Tagihan
 */
class TagihanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kontrak_id' => $this->kontrak_id,
            'periode' => $this->periode?->toDateString(),
            'pokok' => (int) $this->pokok,
            'jatuh_tempo' => $this->jatuh_tempo?->toDateString(),
            'status' => $this->status,
            'terbit_pada' => $this->terbit_pada?->toIso8601String(),

            // Denda belum dihitung di T3 — endpoint /denda baru ada di T6.
            // Dikirim sebagai 0 sekarang supaya bentuk respons tidak berubah
            // bentuk antara T3 dan T6; yang berubah hanya nilainya.
            'denda' => 0,
            'total' => (int) $this->pokok,

            'rincian' => $this->whenLoaded('rincian', fn () => $this->rincian->map(fn ($baris) => [
                'id' => $baris->id,
                'nama' => $baris->nama,
                'nilai' => (int) $baris->nilai,
                'urutan' => $baris->urutan,
            ])->values()),

            'kontrak' => $this->whenLoaded('kontrak', fn () => $this->kontrak
                ? [
                    'id' => $this->kontrak->id,
                    'kamar' => $this->kontrak->kamar
                        ? ['id' => $this->kontrak->kamar->id, 'nomor' => $this->kontrak->kamar->nomor]
                        : null,
                    'penghuni' => $this->kontrak->penghuni
                        ? ['id' => $this->kontrak->penghuni->id, 'nama' => $this->kontrak->penghuni->nama]
                        : null,
                ]
                : null),

            // Snapshot beku (R-06 lapis 2). Dikirim apa adanya supaya bisa
            // diperiksa: tarif mana yang dipakai saat tagihan ini terbit.
            'tarif_terpakai' => $this->tarif_terpakai,
        ];
    }
}
