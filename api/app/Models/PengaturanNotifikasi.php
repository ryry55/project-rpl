<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu keputusan kanal notifikasi milik satu pengguna (T25).
 *
 * Barisnya hanya ada bila pengguna menyimpang dari bawaan. Tidak adanya baris
 * berarti "pakai bawaan", bukan "tidak menerima apa pun" - dan itu bedanya
 * penting: akun baru yang belum pernah membuka layar Pengaturan tetap menerima
 * notifikasi, karena tidak ada yang perlu diisi lebih dulu.
 */
class PengaturanNotifikasi extends Model
{
    protected $table = 'pengaturan_notifikasi';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'peristiwa',
        'kanal',
        'aktif',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
