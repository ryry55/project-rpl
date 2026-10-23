<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Akun pengguna. Tiga peran: pemilik, pengelola, penghuni.
 *
 * "Petugas" tidak menjadi peran tersendiri. Petugas adalah pekerjaan, bukan
 * tingkat kewenangan: orang yang sama bisa mencatat pembayaran hari ini dan
 * mengurus kamar besok. Yang membedakan hanya wewenang yang diberikan, jadi
 * satu peran `pengelola` dengan daftar wewenang lebih jujur daripada tiga peran
 * yang tumpang tindih.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const PERAN_PEMILIK = 'pemilik';

    public const PERAN_PENGELOLA = 'pengelola';

    public const PERAN_PENGHUNI = 'penghuni';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'peran',
        'penghuni_id',
        'pemilik_id',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isPemilik(): bool
    {
        return $this->peran === self::PERAN_PEMILIK;
    }

    public function isPengelola(): bool
    {
        return $this->peran === self::PERAN_PENGELOLA;
    }

    public function isPenghuni(): bool
    {
        return $this->peran === self::PERAN_PENGHUNI;
    }

    /**
     * Pemilik atau pengelola - yaitu siapa pun yang mengurus kos.
     *
     * Dipakai scope isolasi. Namanya sengaja bukan "isAdmin": yang dimaksud
     * bukan tingkat keistimewaan, melainkan pihak yang memang berhak melihat
     * seluruh data kos ini (R-07).
     */
    public function mengurusKos(): bool
    {
        return $this->isPemilik() || $this->isPengelola();
    }

    /**
     * Apakah pengguna ini boleh melakukan sesuatu.
     *
     * Pemilik selalu boleh. Pengelola hanya bila wewenangnya tercatat.
     * Penghuni tidak pernah boleh - termasuk bila suatu saat ada baris
     * `pengelola_kewenangan` yang keliru menunjuk akun penghuni, karena
     * pemeriksaan peran dilakukan lebih dulu.
     *
     * Dibaca dari relasi yang sudah dimuat bila ada. Tanpa itu, setiap
     * pemeriksaan di dalam satu permintaan menjalankan query yang sama; pada
     * endpoint yang memeriksa tiga wewenang, itu tiga query untuk data yang
     * tidak berubah.
     */
    public function boleh(string $wewenang): bool
    {
        if ($this->isPemilik()) {
            return true;
        }

        if (! $this->isPengelola()) {
            return false;
        }

        return $this->kewenangan->contains('wewenang', $wewenang);
    }

    public function penghuni(): BelongsTo
    {
        return $this->belongsTo(Penghuni::class, 'penghuni_id');
    }

    /**
     * Pemilik yang dibantu akun pengelola ini.
     *
     * Null untuk pemilik dan penghuni. Diisi hanya untuk pengelola, dan basis
     * data menolak pengelola tanpa pemilik lewat CHECK constraint.
     */
    public function pemilik(): BelongsTo
    {
        return $this->belongsTo(self::class, 'pemilik_id');
    }

    /**
     * Baris wewenang milik pengguna ini.
     *
     * `hasMany`, bukan `belongsToMany`: tabelnya punya id sendiri dan tidak
     * perlu tabel pivot kedua. Satu baris = satu wewenang, sehingga
     * `$user->kewenangan->contains('wewenang', ...)` bisa dibaca langsung.
     */
    public function kewenangan(): HasMany
    {
        return $this->hasMany(PengelolaKewenangan::class, 'user_id');
    }
}
