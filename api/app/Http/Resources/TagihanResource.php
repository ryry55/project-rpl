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
        // Denda dihitung saat dibaca, bukan disimpan sebagai kolom: nilainya
        // bergantung pada "hari ini", jadi kolom akan selalu basi dan butuh job
        // penyegar. Perhatikan bahwa ini hanya dihitung bila relasi/tagihan
        // memang diminta — daftar tagihan tetap ringan.
        $hasilDenda = app(\App\Domain\Billing\PenghitungDenda::class)->hitung($this->resource);

        return [
            'id' => $this->id,
            'kontrak_id' => $this->kontrak_id,
            'periode' => $this->periode?->toDateString(),
            'pokok' => (int) $this->pokok,
            'jatuh_tempo' => $this->jatuh_tempo?->toDateString(),
            'status' => $this->status,
            'terbit_pada' => $this->terbit_pada?->toIso8601String(),

            'denda' => $hasilDenda->denda,
            'total' => $hasilDenda->pokok + $hasilDenda->denda,

            // Ringkasan denda ikut dikirim supaya layar tidak perlu memanggil
            // endpoint /denda hanya untuk menampilkan "terlambat 12 hari".
            'denda_ringkas' => [
                'hari_terlambat' => $hasilDenda->hariTerlambat,
                'masa_tenggang' => $hasilDenda->masaTenggang,
                'hari_denda' => $hasilDenda->hariDenda,
                'plafon' => $hasilDenda->plafon,
                'sisa_hari_ke_plafon' => $hasilDenda->sisaHariKePlafon,
                'berhenti' => $hasilDenda->berhenti,
                'alasan_berhenti' => $hasilDenda->alasanBerhenti,
            ],

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
