<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tagihan extends Model
{
    use HasFactory;

    public const STATUS_BELUM_BAYAR = 'belum_bayar';

    public const STATUS_SEBAGIAN = 'sebagian';

    public const STATUS_LUNAS = 'lunas';

    public const STATUS_DIBATALKAN = 'dibatalkan';

    protected $table = 'tagihan';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_BELUM_BAYAR,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kontrak_id',
        'periode',
        'pokok',
        'jatuh_tempo',
        'status',
        'tarif_terpakai',
        'tarif_denda_persen',
        'plafon_persen',
        'masa_tenggang',
        'terbit_pada',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'periode' => 'date',
            'jatuh_tempo' => 'date',
            'pokok' => 'decimal:0',
            'tarif_terpakai' => 'array',
            'tarif_denda_persen' => 'decimal:2',
            'plafon_persen' => 'decimal:2',
            'masa_tenggang' => 'integer',
            'terbit_pada' => 'datetime',
        ];
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }

    public function rincian(): HasMany
    {
        return $this->hasMany(TagihanRincian::class)->orderBy('urutan');
    }
}
