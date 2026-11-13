<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Laporan kerusakan satu kamar (T14).
 *
 * Laporan yang masih terbuka adalah alasan `kamar.status_huni` bernilai
 * `perbaikan`. Keduanya tidak dijaga oleh satu trigger: yang menjaganya adalah
 * satu-satunya jalan menulisnya (`LaporanKerusakanController`), dan trigger
 * yang memeriksa dua tabel sekaligus akan menolak juga migrasi serta
 * penyemaian data.
 */
class LaporanKerusakan extends Model
{
    use HasFactory;

    protected $table = 'laporan_kerusakan';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kamar_id',
        'deskripsi',
        'dilaporkan_oleh',
        'selesai_pada',
        'ditutup_oleh',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selesai_pada' => 'datetime',
        ];
    }

    public function kamar(): BelongsTo
    {
        return $this->belongsTo(Kamar::class);
    }

    public function pelapor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dilaporkan_oleh');
    }

    public function penutup(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditutup_oleh');
    }

    /**
     * Foto lampiran (K-15).
     *
     * Diurutkan menurut id supaya urutan foto di layar sama dengan urutan
     * pengunggahannya - dan sama setiap kali halaman dibuka.
     */
    public function lampiran(): HasMany
    {
        return $this->hasMany(LampiranKerusakan::class)->orderBy('id');
    }

    public function masihTerbuka(): bool
    {
        return $this->selesai_pada === null;
    }
}
