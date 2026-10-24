<?php

namespace App\Models;

use App\Domain\Persetujuan\PenerapUsulan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu usulan perubahan yang menunggu keputusan pemilik (T26).
 *
 * `payload` sengaja tidak di-cast ke array. Isinya hanya dibaca oleh
 * {@see PenerapUsulan}, satu tempat, yang tahu bentuk
 * tiap tipe. Cast akan membuatnya tampak seperti data biasa yang boleh dibaca
 * kode mana pun, dan dari situ hanya soal waktu sebelum satu layar mulai
 * menafsirkan sendiri.
 */
class UsulanPerubahan extends Model
{
    public const TIPE_TARIF = 'tarif';

    public const TIPE_KEBIJAKAN = 'kebijakan';

    /**
     * Komponen biaya kamar (REV-SEC-07).
     *
     * Tipe ketiga ini ada karena komponen biaya adalah angka uang yang sama
     * sensitifnya dengan tarif - ia masuk ke setiap tagihan - tetapi punya
     * endpoint sendiri yang dulu menulis langsung tanpa alur usulan. Hasilnya
     * pengelola dengan `ubah_tarif` bisa menambah biaya bulanan ke semua kamar
     * tanpa persetujuan siapa pun.
     */
    public const TIPE_KOMPONEN = 'komponen';

    public const STATUS_MENUNGGU = 'menunggu';

    public const STATUS_DISETUJUI = 'disetujui';

    public const STATUS_DITOLAK = 'ditolak';

    /**
     * Bukan nilai kolom, melainkan status yang dilaporkan ke layar.
     *
     * Tidak pernah ditulis ke basis data: kedaluwarsa dihitung dari
     * `kedaluwarsa_pada`. Menyimpannya menuntut penjadwal yang harus berjalan,
     * dan penjadwal yang berhenti diam-diam menghasilkan usulan yang tampak
     * masih menunggu padahal sudah lewat sebulan.
     */
    public const STATUS_KEDALUWARSA = 'kedaluwarsa';

    /** Lama usulan menunggu sebelum ditutup sendiri. */
    public const HARI_KEDALUWARSA = 7;

    protected $table = 'usulan_perubahan';

    // Tidak ada `created_at`/`updated_at` bawaan: tabelnya memakai
    // `dibuat_pada` dan `diputuskan_pada`, dan nama itu yang dibaca laporan.
    // Membiarkan Laravel menulis kolom waktu kedua hanya menghasilkan dua
    // sumber waktu yang bisa berbeda.
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tipe',
        'payload',
        'ringkasan',
        'status',
        'diajukan_oleh',
        'diputuskan_oleh',
        'alasan',
        'dibuat_pada',
        'diputuskan_pada',
        'kedaluwarsa_pada',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'dibuat_pada' => 'datetime',
            'diputuskan_pada' => 'datetime',
            'kedaluwarsa_pada' => 'datetime',
        ];
    }

    public function pengusul(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }

    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputuskan_oleh');
    }

    /**
     * Apakah usulan ini sudah lewat batas waktu.
     *
     * Dihitung, bukan dibaca dari kolom `status`. Alasannya: penandaan status
     * butuh sesuatu yang berjalan menurut jadwal, dan penjadwal yang tidak
     * pernah berjalan menghasilkan usulan yang tampak masih menunggu padahal
     * sudah lewat sebulan. Yang dihitung di sini selalu benar, bahkan tanpa
     * cron.
     */
    public function sudahKedaluwarsa(): bool
    {
        if ($this->status !== self::STATUS_MENUNGGU) {
            return false;
        }

        return $this->kedaluwarsa_pada->isPast();
    }

    /**
     * Status yang benar-benar berlaku, termasuk kedaluwarsa yang belum ditandai.
     */
    public function statusEfektif(): string
    {
        return $this->sudahKedaluwarsa() ? self::STATUS_KEDALUWARSA : $this->status;
    }
}
