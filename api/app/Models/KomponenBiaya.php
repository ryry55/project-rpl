<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Komponen biaya tetap. Append-only — tidak pernah di-UPDATE atau dihapus.
 */
class KomponenBiaya extends Model
{
    use HasFactory;

    protected $table = 'komponen_biaya';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nama',
        'nilai',
        'satuan',
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
}
