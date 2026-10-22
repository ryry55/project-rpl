<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Data induk penghuni. Pada T1 hanya dipakai untuk menautkan akun login
 * ke seorang penghuni; atribut lengkapnya baru diisi di T2.
 */
class Penghuni extends Model
{
    use HasFactory;

    protected $table = 'penghuni';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nama',
        'no_hp',
    ];

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'penghuni_id');
    }
}
