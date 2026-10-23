<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris wewenang milik satu pengelola.
 *
 * Dibuat sebagai model, bukan tabel pivot `belongsToMany` langsung, karena
 * baris ini punya id sendiri dan bisa diperiksa satu per satu di jejak audit.
 * Tabel pivot tanpa model menyulitkan pertanyaan "kapan wewenang ini diberikan".
 */
class PengelolaKewenangan extends Model
{
    protected $table = 'pengelola_kewenangan';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'wewenang',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
