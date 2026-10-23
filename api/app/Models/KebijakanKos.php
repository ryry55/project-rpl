<?php

namespace App\Models;

use App\Domain\Kebijakan\PilihanKebijakan;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kebijakan kos. Append-only — tidak pernah di-UPDATE atau dihapus.
 *
 * `kamar_id` NULL berarti kebijakan berlaku untuk seluruh kos; baris khusus
 * kamar menimpa baris global untuk tipe yang sama. Penyelesaiannya ada di
 * `App\Domain\Kebijakan\PenyelesaiKebijakan`, bukan di model ini: model hanya
 * menyimpan, sama seperti `KomponenBiaya`.
 *
 * Jangan menambahkan `update()` atau `delete()` di sini walau terasa praktis.
 * Trigger di basis data akan menolaknya, dan penolakan itu disengaja (R-06).
 */
class KebijakanKos extends Model
{
    use HasFactory;

    protected $table = 'kebijakan_kos';

    // Dialiaskan ke PilihanKebijakan, bukan ditulis ulang: satu sumber untuk
    // nilai yang sah, dipakai bersama oleh validasi, Kalkulator, dan layar.
    public const TIPE_SATUAN_SEWA = PilihanKebijakan::TIPE_SATUAN_SEWA;

    public const TIPE_JATUH_TEMPO = PilihanKebijakan::TIPE_JATUH_TEMPO;

    public const TIPE_DENDA = PilihanKebijakan::TIPE_DENDA;

    public const TIPE_DEPOSIT = PilihanKebijakan::TIPE_DEPOSIT;

    public const SATUAN_BULANAN = PilihanKebijakan::SATUAN_BULANAN;

    public const SATUAN_MINGGUAN = PilihanKebijakan::SATUAN_MINGGUAN;

    public const SATUAN_HARIAN = PilihanKebijakan::SATUAN_HARIAN;

    public const JATUH_TEMPO_TANGGAL_MASUK = PilihanKebijakan::JATUH_TEMPO_TANGGAL_MASUK;

    public const JATUH_TEMPO_TANGGAL_TETAP = PilihanKebijakan::JATUH_TEMPO_TANGGAL_TETAP;

    public const JATUH_TEMPO_AKHIR_BULAN = PilihanKebijakan::JATUH_TEMPO_AKHIR_BULAN;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kamar_id',
        'tipe',
        'nilai',
        'persen',
        'plafon_persen',
        'masa_tenggang',
        'nominal',
        'tanggal',
        'berlaku_dari',
    ];

    /**
     * Kamar pemilik kebijakan, atau null bila kebijakan berlaku global.
     *
     * @return BelongsTo<Kamar, $this>
     */
    public function kamar(): BelongsTo
    {
        return $this->belongsTo(Kamar::class, 'kamar_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'berlaku_dari' => 'date',
            'persen' => 'decimal:2',
            'plafon_persen' => 'decimal:2',
            'masa_tenggang' => 'integer',
            'nominal' => 'integer',
            'tanggal' => 'integer',
            'dibuat_pada' => 'datetime',
        ];
    }
}
