<?php

namespace App\Models;

use App\Domain\Rekening\PenyimpanGambarQris;
use Illuminate\Database\Eloquent\Model;

/**
 * Rekening atau QRIS tujuan pembayaran (REV-UI-05).
 *
 * Hanya dibaca dan ditampilkan; tidak ada angka tagihan yang dihitung dari
 * baris ini. Karena itu ia boleh diubah dan dihapus, berbeda dari
 * `tarif_sewa`/`kebijakan_kos`/`komponen_biaya` yang append-only.
 */
class Rekening extends Model
{
    public const JENIS_TRANSFER = 'transfer';

    public const JENIS_QRIS = 'qris';

    /** Daftar tertutup, dipakai validasi dan pilihan formulir. */
    public const SEMUA_JENIS = [
        self::JENIS_TRANSFER,
        self::JENIS_QRIS,
    ];

    public const LABEL_JENIS = [
        self::JENIS_TRANSFER => 'Transfer bank',
        self::JENIS_QRIS => 'QRIS',
    ];

    protected $table = 'rekening';

    /**
     * Batas ukuran gambar QRIS, dalam byte.
     *
     * 2 MB cukup untuk foto potret QRIS dari ponsel (biasanya 100-400 kB) dan
     * masih di bawah `upload_max_filesize` 8 MB di container. Batas yang lebih
     * longgar hanya akan menyimpan gambar yang tidak pernah terbaca sebagai
     * QRIS, karena yang dipindai penghuni adalah layar, bukan kertas.
     */
    public const BATAS_GAMBAR = 2 * 1024 * 1024;

    /**
     * Tipe gambar yang diterima.
     *
     * Hanya raster. SVG sengaja **ditolak**: ia dokumen yang bisa memuat
     * skrip, dan menyajikannya kembali dari origin aplikasi membuat satu
     * unggahan pemilik menjadi jalan menjalankan kode di peramban penghuni.
     * Untuk kode QR, PNG sudah cukup.
     */
    public const MIME_GAMBAR = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /**
     * `gambar` sengaja **tidak** ada di sini.
     *
     * Mass assignment akan menulis kolom `bytea` sebagai parameter teks biasa,
     * dan percobaan menunjukkan PostgreSQL menyimpannya sebagai **0 byte tanpa
     * galat apa pun**: unggahan tampak berhasil, `gambar_ukuran` terisi, tetapi
     * gambarnya kosong. Karena itu satu-satunya jalan menulis gambar adalah
     * {@see PenyimpanGambarQris}, yang memakai
     * `decode(?, 'base64')` - satu-satunya bentuk yang terbukti utuh.
     *
     * @var list<string>
     */
    protected $fillable = [
        'jenis',
        'nama',
        'nomor',
        'atas_nama',
        'catatan',
        'gambar_mime',
        'gambar_ukuran',
        'aktif',
        'urutan',
    ];

    /**
     * `gambar` sengaja **tidak** di-cast dan tidak masuk `$hidden`.
     *
     * Driver pgsql mengembalikan `bytea` sebagai resource stream, bukan string,
     * sehingga ia tidak bisa langsung dipakai `json_encode`. Karena itu resource
     * tidak pernah dibiarkan sampai ke `RekeningResource` - yang dikirim ke
     * layar hanya `punya_gambar`, dan isinya disajikan endpoint tersendiri.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'urutan' => 'integer',
            'gambar_ukuran' => 'integer',
        ];
    }

    /**
     * Isi gambar sebagai string biner, atau `null` bila tidak ada.
     *
     * Resource stream dari driver pgsql dibaca sampai habis di sini, sekali.
     * Tanpa ini, `file_get_contents` pada kolom akan mengembalikan string kosong
     * pada pembacaan kedua - dan gambar yang tadinya tampil tiba-tiba hilang.
     */
    public function isiGambar(): ?string
    {
        $isi = $this->getAttributeFromArray('gambar');

        if ($isi === null) {
            return null;
        }

        if (is_resource($isi)) {
            $isi = stream_get_contents($isi);
        }

        return $isi === '' ? null : $isi;
    }

    public function punyaGambar(): bool
    {
        return $this->gambar_mime !== null && $this->gambar_ukuran !== null;
    }

    /**
     * Buang gambar tanpa menyentuh kolom lain.
     *
     * Dipakai saat pemilik mengganti jenis rekening menjadi `transfer`: gambar
     * QRIS yang tertinggal di baris transfer akan disajikan portal sebagai
     * "cara membayar" yang tidak berlaku, dan penghuni bisa memindainya.
     */
    public function hapusGambar(): void
    {
        $this->forceFill([
            'gambar' => null,
            'gambar_mime' => null,
            'gambar_ukuran' => null,
        ])->save();
    }
}
