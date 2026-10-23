<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Aturan kos, mis. "Tamu tidak boleh menginap", "Sampah dibuang malam hari".
 *
 * Tampil di portal penghuni sebagai bacaan, bukan sebagai tagihan. Karena itu
 * tabel ini tidak append-only: tidak ada angka uang yang bergantung padanya,
 * jadi memperbaiki salah ketik di judul aturan tidak merusak dokumen mana pun.
 *
 * `aktif` dipakai alih-alih menghapus baris. Aturan yang pernah berlaku tetap
 * perlu bisa dibaca ketika penghuni lama bertanya "memang ada aturan itu?" -
 * dan jawabannya tidak boleh "sudah saya hapus, jadi tidak tahu".
 */
class AturanKos extends Model
{
    use HasFactory;

    protected $table = 'aturan_kos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'judul',
        'isi',
        'kategori',
        'sanksi',
        'aktif',
        'urutan',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kategori' => 'umum',
        'aktif' => true,
        'urutan' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'urutan' => 'integer',
        ];
    }
}
