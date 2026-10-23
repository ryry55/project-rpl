<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Fasilitas kos, mis. "AC", "Kamar mandi dalam", "Parkir".
 *
 * Fasilitas menjawab "kamar ini PUNYA apa", bukan "kamar ini DITAGIH apa".
 * Keduanya sengaja dipisah: mencentang AC tidak boleh memunculkan biaya di
 * tagihan. Kalau pemilik ingin menagih, ia menambah baris `komponen_biaya`
 * sendiri, dan barulah angkanya terlihat oleh penghuni.
 *
 * Tidak append-only: tidak ada angka uang yang bergantung pada baris ini.
 */
class Fasilitas extends Model
{
    use HasFactory;

    protected $table = 'fasilitas';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nama',
        'ikon',
        'urutan',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
        ];
    }

    /**
     * Kamar yang punya fasilitas ini.
     *
     * @return BelongsToMany<Kamar, $this>
     */
    public function kamar(): BelongsToMany
    {
        return $this->belongsToMany(Kamar::class, 'kamar_fasilitas')
            ->withTimestamps();
    }
}
