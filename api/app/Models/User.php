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
        // Penanda "akun sudah diaktifkan" (T27). Ikut `$fillable` karena
        // seeder dan tes perlu menetapkannya saat membuat akun contoh - dan
        // tanpa itu `User::create(['email_verified_at' => now()])` mengabaikan
        // kolomnya diam-diam, sehingga akun demo lahir dalam keadaan belum
        // aktif dan menolak login dengan pesan "belum diaktifkan".
        //
        // Aman dari masukan pengguna: tidak ada endpoint yang meneruskan
        // `$request->validated()` langsung ke `User::update`. Aktivasi hanya
        // ditulis `TautanAkun::setelSandi()` setelah tautan email terbukti
        // dibuka pemiliknya.
        'email_verified_at',
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
            'wajib_ganti_sandi' => 'boolean',
        ];
    }

    /**
     * Apakah akun ini belum pernah diaktifkan.
     *
     * Akun dibuat pemilik dengan sandi acak yang tidak diketahui siapa pun,
     * sehingga tidak ada yang bisa masuk sebelum pemiliknya sendiri menetapkan
     * sandi lewat tautan aktivasi. Dipakai layar masuk untuk menjawab "tautan
     * saya tidak berlaku" tanpa harus memberi tahu penebak apakah sebuah email
     * terdaftar.
     */
    public function belumAktif(): bool
    {
        return $this->email_verified_at === null;
    }

    /**
     * Apakah sandi akun ini wajib diganti sebelum boleh dipakai.
     *
     * Berlaku untuk akun lama yang sandinya pernah diketik pemilik. Sandi itu
     * masih sah - karena itu akunnya tetap bisa masuk - tetapi tidak boleh
     * dipakai untuk apa pun selain menggantinya.
     */
    public function wajibGantiSandi(): bool
    {
        return (bool) $this->wajib_ganti_sandi;
    }

    /**
     * Apakah akun ini sudah menyetujui versi teks PDP yang berlaku.
     *
     * Diperiksa per versi, bukan "pernah menyetujui": teks yang berubah
     * menuntut persetujuan baru, dan pengguna yang menyetujui teks lama tidak
     * otomatis menyetujui teks yang menggantikannya.
     */
    public function sudahMenyetujuiPdp(): bool
    {
        return $this->persetujuanPdp()
            ->where('versi', PersetujuanPdp::VERSI_BERLAKU)
            ->exists();
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

    /**
     * Riwayat persetujuan pemrosesan data pribadi (T28).
     *
     * `hasMany`, bukan `hasOne`: yang disimpan adalah riwayat per versi teks,
     * dan pertanyaan "apa yang pernah ia setujui" hanya bisa dijawab kalau
     * baris lama tidak ditimpa.
     */
    public function persetujuanPdp(): HasMany
    {
        return $this->hasMany(PersetujuanPdp::class);
    }

    public function tokenAkun(): HasMany
    {
        return $this->hasMany(TokenAkun::class);
    }
}
