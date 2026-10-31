<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /**
     * @return HasMany<LaporanKerusakan, $this>
     */
    public function laporanKerusakan(): HasMany
    {
        return $this->hasMany(LaporanKerusakan::class);
    }

    /**
     * Laporan yang belum ditutup - satu-satunya alasan kamar berstatus
     * `perbaikan` (T14).
     *
     * @return HasOne<LaporanKerusakan, $this>
     */
    public function laporanTerbuka(): HasOne
    {
        return $this->hasOne(LaporanKerusakan::class)->whereNull('selesai_pada');
    }

    /**
     * Status yang benar setelah sebuah laporan kerusakan ditutup (T14).
     *
     * Dihitung ulang dari kontrak, bukan dikembalikan ke nilai sebelum
     * perbaikan: selama kamar diperbaiki, kontrak bisa saja dimulai atau
     * diakhiri. Mengembalikan nilai lama berarti kamar yang penghuninya sudah
     * keluar tampil `terisi` selamanya - dan daftar kamar berbohong tentang
     * kamar yang bisa disewakan.
     */
    public function statusSetelahPerbaikan(): string
    {
        if ($this->kontrakAktif()->exists()) {
            return 'terisi';
        }

        if ($this->kontrak()->where('status', 'dipesan')->exists()) {
            return 'dipesan';
        }

        return 'kosong';
    }

    /**
     * Fasilitas yang dimiliki kamar ini (T18).
     *
     * Diurutkan di sini, bukan di view: urutan fasilitas adalah keputusan data
     * yang sama untuk semua layar, dan dua layar yang mengurutkan sendiri akan
     * menampilkan urutan berbeda untuk kamar yang sama.
     *
     * @return BelongsToMany<Fasilitas, $this>
     */
    public function fasilitas(): BelongsToMany
    {
        return $this->belongsToMany(Fasilitas::class, 'kamar_fasilitas')
            ->withTimestamps()
            ->orderBy('fasilitas.urutan')
            ->orderBy('fasilitas.nama');
    }
}
