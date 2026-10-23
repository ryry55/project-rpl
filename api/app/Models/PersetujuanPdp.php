<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bukti persetujuan pemrosesan data pribadi (T28).
 *
 * Satu baris = satu persetujuan atas satu versi teks. Versi disimpan karena
 * teksnya akan berubah, dan persetujuan atas teks lama bukan persetujuan atas
 * teks baru - menimpanya akan menghasilkan bukti yang menyatakan sesuatu yang
 * tidak pernah disetujui siapa pun.
 *
 * Tabelnya append-only lewat trigger, sama seperti `log_audit`. Bukti
 * persetujuan yang bisa disunting tidak membuktikan apa pun.
 */
class PersetujuanPdp extends Model
{
    /**
     * Versi teks persetujuan yang sedang berlaku.
     *
     * Naikkan angka ini bila isi `resources/views/legal/persetujuan-pdp.blade.php`
     * berubah secara material. Akibatnya: pengguna yang sudah menyetujui versi
     * lama akan diminta menyetujui versi baru pada login berikutnya, dan baris
     * lamanya tetap ada sebagai riwayat.
     */
    public const VERSI_BERLAKU = '1.0';

    protected $table = 'persetujuan_pdp';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'versi',
        'disetujui_pada',
        'ip',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'disetujui_pada' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
