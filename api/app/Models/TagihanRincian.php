<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris rincian tagihan.
 *
 * Rincian dipisah per baris supaya pemilik dan penghuni bisa memeriksa dari mana
 * angka `pokok` berasal: berapa sewa, berapa kebersihan, berapa parkir. Tagihan
 * yang hanya menampilkan satu angka total tidak bisa diperiksa, dan justru itu
 * yang paling sering dipersoalkan penghuni.
 */
class TagihanRincian extends Model
{
    use HasFactory;

    protected $table = 'tagihan_rincian';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tagihan_id',
        'nama',
        'nilai',
        'urutan',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nilai' => 'decimal:0',
            'urutan' => 'integer',
        ];
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(Tagihan::class);
    }
}
