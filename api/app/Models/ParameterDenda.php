<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Parameter denda. Append-only — mengubah parameter berarti baris baru.
 *
 * Nilai persentase disimpan sebagai string decimal agar tidak pernah menyentuh
 * float. Lihat SPEC §5.
 */
class ParameterDenda extends Model
{
    use HasFactory;

    protected $table = 'parameter_denda';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tarif_persen',
        'plafon_persen',
        'masa_tenggang',
        'berlaku_dari',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tarif_persen' => 'decimal:2',
            'plafon_persen' => 'decimal:2',
            'masa_tenggang' => 'integer',
            'berlaku_dari' => 'date',
        ];
    }
}
