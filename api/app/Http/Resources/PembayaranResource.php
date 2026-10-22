<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu baris pembayaran.
 *
 * `jumlah` dikirim sebagai int rupiah, bukan string desimal. Cast
 * `decimal:0` mengembalikan string ("1500000"), dan mengirimkannya apa adanya
 * membuat frontend harus ingat mengubahnya sendiri — satu tempat yang lupa akan
 * menampilkan "1500000" tanpa pemisah ribuan.
 *
 * @mixin \App\Models\Pembayaran
 */
class PembayaranResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tagihan_id' => $this->tagihan_id,
            'jumlah' => (int) $this->jumlah,
            'tanggal_pembayaran' => $this->tanggal_pembayaran?->toDateString(),
            'catatan' => $this->catatan,
            'dicatat_pada' => $this->created_at?->toIso8601String(),
        ];
    }
}
