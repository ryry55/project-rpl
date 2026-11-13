<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu perangkat kos beserta kredensialnya (K-16).
 *
 * **Sandi dienkripsi lewat cast, bukan lewat pemanggil.** `encrypted` membuat
 * setiap jalur baca dan tulis melewati kriptografi tanpa satu pun pemanggil
 * perlu ingat. Jalur yang menulis kolomnya lewat SQL mentah akan menyimpan teks
 * biasa tanpa galat - karena itu tidak ada kode yang menulis kolom ini di luar
 * model, dan tesnya memeriksa isi kolom di basis data, bukan hanya nilai yang
 * dikembalikan model.
 *
 * **`$hidden` memuat `sandi`.** Walaupun layar pemilik perlu melihatnya,
 * sandi tidak boleh ikut terkirim pada setiap `toArray()` - mis. saat model
 * diserialkan sebagai bagian relasi lain. Endpoint yang memang menampilkannya
 * mengambil nilainya secara eksplisit.
 */
class Perangkat extends Model
{
    protected $table = 'perangkat';

    public const JENIS_WIFI = 'wifi';

    public const JENIS_CCTV = 'cctv';

    public const JENIS_RFID = 'rfid';

    public const JENIS_LAINNYA = 'lainnya';

    /**
     * Daftar tertutup, sepadan dengan CHECK di basis data.
     *
     * @var list<string>
     */
    public const SEMUA_JENIS = [
        self::JENIS_WIFI,
        self::JENIS_CCTV,
        self::JENIS_RFID,
        self::JENIS_LAINNYA,
    ];

    /**
     * @var array<string, string>
     */
    public const LABEL_JENIS = [
        self::JENIS_WIFI => 'WiFi',
        self::JENIS_CCTV => 'CCTV',
        self::JENIS_RFID => 'Kartu RFID',
        self::JENIS_LAINNYA => 'Lainnya',
    ];

    /**
     * Jenis yang boleh dilihat penghuni (K-16).
     *
     * Hanya WiFi. CCTV dan RFID adalah kredensial pengurus kos: nomor kartu
     * yang bocor bisa dipakai orang lain untuk masuk, dan sandi CCTV membuka
     * rekaman yang justru bukan untuk penghuni.
     *
     * @var list<string>
     */
    public const JENIS_TERLIHAT_PENGHUNI = [self::JENIS_WIFI];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'jenis',
        'nama',
        'lokasi',
        'nama_pengguna',
        'sandi',
        'catatan',
        'aktif',
        'urutan',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'sandi',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'jenis' => 'wifi',
        'aktif' => true,
        'urutan' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sandi' => 'encrypted',
            'aktif' => 'boolean',
            'urutan' => 'integer',
        ];
    }
}
