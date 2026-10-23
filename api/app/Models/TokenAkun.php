<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token sekali pakai untuk aktivasi akun dan reset sandi (T27).
 *
 * Yang tersimpan adalah hash SHA-256 dari token, bukan tokennya. Tautan asli
 * hanya ada di email dan di memori selama satu permintaan; kalau tabel ini
 * bocor, isinya tidak bisa dipakai masuk. Alasannya sama dengan kolom
 * `password`, dan rahasia yang bisa dipakai langsung tidak boleh tersimpan.
 *
 * SHA-256, bukan bcrypt. Ini bukan pilihan yang lebih lemah: token dibuat
 * dengan 64 karakter acak dari generator kriptografis, bukan dari kata sandi
 * manusia, sehingga tidak ada ruang tebak yang perlu diperlambat. Bcrypt justru
 * merugikan di sini karena setiap verifikasi tautan menjadi mahal, dan token
 * dicari lewat index pada hash - pencarian yang tidak mungkin dilakukan bila
 * hash-nya bergaram.
 */
class TokenAkun extends Model
{
    public const JENIS_AKTIVASI = 'aktivasi';

    public const JENIS_RESET = 'reset';

    protected $table = 'token_akun';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'jenis',
        'token_hash',
        'kedaluwarsa_pada',
        'dipakai_pada',
        'dibuat_pada',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kedaluwarsa_pada' => 'datetime',
            'dipakai_pada' => 'datetime',
            'dibuat_pada' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Sudah lewat tenggat.
     *
     * Dibandingkan dengan `now()`, bukan `WaktuSistem::hariIni()`. Tenggat
     * token adalah tenggat jam, bukan tenggat hari: tautan reset yang berlaku
     * 60 menit tidak boleh tetap hidup sampai tengah malam hanya karena mode
     * demo sedang menggeser tanggal.
     */
    public function sudahKedaluwarsa(): bool
    {
        return $this->kedaluwarsa_pada->isPast();
    }

    public function sudahDipakai(): bool
    {
        return $this->dipakai_pada !== null;
    }

    /**
     * Apakah token ini masih boleh dipakai untuk keperluan tertentu.
     *
     * Jenisnya diperiksa di sini, bukan hanya saat penerbitan: tautan aktivasi
     * yang bocor tidak boleh dipakai untuk mengganti sandi akun yang sudah
     * aktif, dan sebaliknya.
     */
    public function masihBerlakuUntuk(string $jenis): bool
    {
        return $this->jenis === $jenis
            && ! $this->sudahDipakai()
            && ! $this->sudahKedaluwarsa();
    }
}
