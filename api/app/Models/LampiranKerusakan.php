<?php

namespace App\Models;

use App\Domain\Berkas\PenyimpanLampiran;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * Satu foto lampiran laporan kerusakan (K-15).
 *
 * Isinya tinggal di kolom `bytea` dan **tidak** ikut terbaca lewat model:
 * `$fillable` sengaja tidak memuat `isi`, dan pembacaannya lewat
 * {@see PenyimpanLampiran}. Alasannya sama dengan gambar
 * QRIS - daftar laporan yang menarik seluruh isi foto akan mengirim megabyte
 * yang tidak ditampilkan.
 *
 * Batas di bawah adalah batas yang **dipakai bersama** oleh validasi HTTP dan
 * layar. Menyalinnya ke JavaScript berarti dua angka yang harus setuju, dan
 * yang tertinggal adalah yang paling jarang dibaca.
 */
class LampiranKerusakan extends Model
{
    protected $table = 'lampiran_kerusakan';

    /** 5 MB, keputusan pemilik (K-15). */
    public const BATAS_UKURAN = 5 * 1024 * 1024;

    /** Tiga foto per laporan, keputusan pemilik (K-15). */
    public const MAKS_PER_LAPORAN = 3;

    /**
     * Jenis yang diterima, dipetakan ke akhiran berkas untuk nama unduhan.
     *
     * Daftar tertutup, dan yang menentukan adalah isi berkas - bukan nama atau
     * `Content-Type` kiriman. SVG sengaja tidak ada: ia dokumen yang bisa
     * memuat skrip, dan foto kerusakan tidak pernah butuh SVG.
     *
     * @var array<string, string>
     */
    public const MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'laporan_kerusakan_id',
        'nama_asli',
        'mime',
        'ukuran',
    ];

    public function laporan(): BelongsTo
    {
        return $this->belongsTo(LaporanKerusakan::class, 'laporan_kerusakan_id');
    }

    /**
     * Bentuk lampiran untuk API, dengan tautan bertanda tangan berbatas waktu.
     *
     * Di sini, bukan di resource, karena dua endpoint memakainya -
     * `/laporan-kerusakan` (pemilik dan pengelola) dan
     * `/penghuni/laporan-kerusakan` (pelapor). Kalau bentuknya ditulis dua
     * kali, cepat atau lambat salah satunya lupa memberi tanda tangan dan
     * layarnya menampilkan ikon rusak.
     *
     * 30 menit cukup untuk membuka satu laporan dan melihat fotonya, dan cukup
     * singkat sehingga tautan yang tidak sengaja tersalin tidak berlaku
     * selamanya.
     *
     * **Tautannya relatif, bukan absolut.** SPA dan API punya alamat berbeda di
     * laptop (port 3000 dan 8081), sedangkan CSP produksi hanya mengizinkan
     * `img-src 'self'`. Tautan absolut ke port API akan diblokir peramban tanpa
     * pesan apa pun - gambarnya hanya tidak muncul. Tautan relatif selalu
     * melewati origin yang sama dengan halaman, dan `signed:relative` di rute
     * memvalidasinya tanpa membandingkan host.
     *
     * @return array<string, mixed>
     */
    public function ringkas(): array
    {
        return [
            'id' => $this->id,
            'nama_asli' => $this->nama_asli,
            'mime' => $this->mime,
            'ukuran' => $this->ukuran,
            'url' => URL::temporarySignedRoute(
                'lampiran-kerusakan.tampil',
                now()->addMinutes(30),
                ['id' => $this->id],
                absolute: false,
            ),
        ];
    }
}
