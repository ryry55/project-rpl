<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satu baris jejak audit (R-07).
 *
 * Tabel ini **append-only**; trigger basis data menolak UPDATE dan DELETE. Model
 * ini karena itu tidak menyediakan cara mengubah apa pun — tidak ada `update()`
 * yang sah, dan `dibuat_pada` tidak pernah disentuh setelah baris ditulis.
 *
 * `aktor_nama` adalah salinan beku nama pengguna saat kejadian, bukan hasil
 * join. Mengganti nama pengguna tidak boleh menulis ulang sejarah.
 *
 * @property int $id
 * @property int|null $aktor_id
 * @property string|null $aktor_nama
 * @property string $aksi
 * @property string $entitas
 * @property int|null $entitas_id
 * @property array<string, mixed>|null $nilai_lama
 * @property array<string, mixed>|null $nilai_baru
 * @property Carbon $dibuat_pada
 */
class LogAudit extends Model
{
    protected $table = 'log_audit';

    /** Log audit tidak pernah diubah, jadi tidak ada kolom `updated_at`. */
    public const UPDATED_AT = null;

    public const CREATED_AT = 'dibuat_pada';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'aktor_id',
        'aktor_nama',
        'aksi',
        'entitas',
        'entitas_id',
        'nilai_lama',
        'nilai_baru',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nilai_lama' => 'array',
            'nilai_baru' => 'array',
            'dibuat_pada' => 'datetime',
        ];
    }
}
