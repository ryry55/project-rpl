<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Kontrak extends Model
{
    use HasFactory;

    protected $table = 'kontrak';

    /**
     * Default di level model, bukan hanya di database — lihat catatan yang sama
     * di App\Models\Kamar.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'aktif',
        'deposit' => 0,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kamar_id',
        'penghuni_id',
        'tgl_masuk',
        'tgl_keluar',
        'tarif_awal',
        'deposit',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tgl_masuk' => 'date',
            'tgl_keluar' => 'date',
            'tarif_awal' => 'decimal:0',
            'deposit' => 'decimal:0',
        ];
    }

    public function kamar(): BelongsTo
    {
        return $this->belongsTo(Kamar::class);
    }

    public function penghuni(): BelongsTo
    {
        return $this->belongsTo(Penghuni::class);
    }
}
