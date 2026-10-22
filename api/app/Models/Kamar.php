<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Kamar extends Model
{
    use HasFactory;

    protected $table = 'kamar';

    /**
     * Default di level model, bukan hanya di database.
     *
     * Tanpa ini, `Kamar::create(['nomor' => 'A1'])` mengembalikan objek dengan
     * `status_huni` bernilai null — database sudah mengisi 'kosong', tetapi
     * objek di memori belum membacanya kembali. Respons API lalu mengirim null
     * padahal kolomnya tidak pernah null. Menaruh default di sini membuat model
     * dan database sepakat tanpa perlu `refresh()` di setiap pemanggil.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status_huni' => 'kosong',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'nomor',
        'tipe',
        'status_huni',
        'catatan',
    ];

    public function tarif(): HasMany
    {
        return $this->hasMany(TarifSewa::class)->orderByDesc('berlaku_dari');
    }

    /**
     * Baris tarif terbaru saja — untuk ditampilkan di daftar kamar.
     *
     * Memakai `latestOfMany` pada primary key, bukan pada `berlaku_dari`:
     * tabelnya append-only, jadi id terbesar selalu baris yang paling akhir
     * dicatat, termasuk bila pemilik memasukkan tanggal berlaku mundur.
     */
    public function tarifTerakhir(): HasOne
    {
        return $this->hasOne(TarifSewa::class)->latestOfMany();
    }

    public function kontrak(): HasMany
    {
        return $this->hasMany(Kontrak::class);
    }

    public function kontrakAktif(): HasOne
    {
        return $this->hasOne(Kontrak::class)->where('status', 'aktif');
    }
}
