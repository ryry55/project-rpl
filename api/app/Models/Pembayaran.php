<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pembayaran yang diterima (proses 4.0).
 *
 * Tabel ini **append-only**; trigger basis data menolak UPDATE dan DELETE.
 * Model ini karena itu tidak menyediakan jalur perubahan — memperbaiki salah
 * input dilakukan dengan baris pembalik, bukan dengan menyunting yang lama.
 *
 * `jumlah` selalu positif. Uang yang diterima tidak pernah negatif; kelebihan
 * bayar ditolak sebelum sempat tersimpan.
 *
 * @property int $id
 * @property int $tagihan_id
 * @property string $jumlah
 * @property \Illuminate\Support\Carbon $tanggal_pembayaran
 * @property string|null $catatan
 */
class Pembayaran extends Model
{
    use HasFactory;

    protected $table = 'pembayaran';

    /** Tidak ada `updated_at`: baris pembayaran tidak pernah berubah. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tagihan_id',
        'jumlah',
        'tanggal_pembayaran',
        'catatan',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jumlah' => 'decimal:0',
            'tanggal_pembayaran' => 'date',
        ];
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class);
    }
}
