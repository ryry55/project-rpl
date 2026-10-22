<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Komponen biaya tetap. Append-only — tidak pernah di-UPDATE atau dihapus.
 *
 * `kamar_id` NULL berarti komponen berlaku untuk semua kamar. Baris khusus
 * kamar menimpa baris global dengan nama yang sama pada periode yang sama.
 */
class KomponenBiaya extends Model
{
    use HasFactory;

    protected $table = 'komponen_biaya';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kamar_id',
        'nama',
        'nilai',
        'satuan',
        'berlaku_dari',
    ];

    /**
     * Kamar pemilik komponen, atau null bila komponen berlaku global.
     *
     * @return BelongsTo<Kamar, $this>
     */
    public function kamar(): BelongsTo
    {
        return $this->belongsTo(Kamar::class, 'kamar_id');
    }

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
}
