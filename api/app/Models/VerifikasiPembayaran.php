<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu pemeriksaan atas satu pembayaran (T20).
 *
 * Append-only; trigger basis data menolak UPDATE dan DELETE. Yang berlaku bagi
 * sebuah pembayaran adalah baris **terakhir** - pemeriksaan ulang menambah
 * baris, tidak menimpa yang lama.
 *
 * Tidak ada `updated_at`: baris yang tidak pernah berubah tidak punya waktu
 * "diubah".
 *
 * @property int $id
 * @property int $pembayaran_id
 * @property int|null $diverifikasi_oleh
 * @property string $keputusan
 * @property string|null $catatan
 * @property Carbon $created_at
 */
class VerifikasiPembayaran extends Model
{
    use HasFactory;

    public const KEPUTUSAN_TERIMA = 'terima';

    public const KEPUTUSAN_TOLAK = 'tolak';

    protected $table = 'verifikasi_pembayaran';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'pembayaran_id',
        'diverifikasi_oleh',
        'keputusan',
        'catatan',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function pembayaran(): BelongsTo
    {
        return $this->belongsTo(Pembayaran::class);
    }

    public function pemeriksa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diverifikasi_oleh');
    }
}
