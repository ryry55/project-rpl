<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Data induk penghuni (D2 pada DFD).
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
        'email',
        'no_hp',
    ];

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'penghuni_id');
    }

    public function kontrak(): HasMany
    {
        return $this->hasMany(Kontrak::class);
    }

    public function kontrakAktif(): HasOne
    {
        return $this->hasOne(Kontrak::class)->where('status', 'aktif');
    }
}
