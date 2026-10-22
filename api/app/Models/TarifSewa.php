<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat tarif sewa. Append-only — tidak pernah di-UPDATE atau dihapus.
 *
 * @see Kamar
 */
class TarifSewa extends Model
{
    use HasFactory;

    protected $table = 'tarif_sewa';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kamar_id',
        'nilai',
        'berlaku_dari',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nilai' => 'decimal:0',
            'berlaku_dari' => 'date',
        ];
    }

    public function kamar(): BelongsTo
    {
        return $this->belongsTo(Kamar::class);
    }
}
