<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rekening atau QRIS tujuan pembayaran (REV-UI-05).
 *
 * Hanya dibaca dan ditampilkan; tidak ada angka tagihan yang dihitung dari
 * baris ini. Karena itu ia boleh diubah dan dihapus, berbeda dari
 * `tarif_sewa`/`kebijakan_kos`/`komponen_biaya` yang append-only.
 */
class Rekening extends Model
{
    public const JENIS_TRANSFER = 'transfer';

    public const JENIS_QRIS = 'qris';

    /** Daftar tertutup, dipakai validasi dan pilihan formulir. */
    public const SEMUA_JENIS = [
        self::JENIS_TRANSFER,
        self::JENIS_QRIS,
    ];

    public const LABEL_JENIS = [
        self::JENIS_TRANSFER => 'Transfer bank',
        self::JENIS_QRIS => 'QRIS',
    ];

    protected $table = 'rekening';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'jenis',
        'nama',
        'nomor',
        'atas_nama',
        'catatan',
        'aktif',
        'urutan',
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
