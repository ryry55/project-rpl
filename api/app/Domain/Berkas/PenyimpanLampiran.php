<?php

namespace App\Domain\Berkas;

use App\Models\LampiranKerusakan;
use App\Models\LaporanKerusakan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menyimpan dan menyajikan foto lampiran kerusakan.
 *
 * **Kenapa kelas tersendiri, bukan beberapa baris di controller.** Sama persis
 * dengan `PenyimpanGambarQris`: menulis kolom `bytea` lewat parameter teks biasa
 * menghasilkan **0 byte** tanpa satu pun galat. Unggahan tampak berhasil,
 * `ukuran` terisi, fotografinya kosong - dan tidak ada pesan yang memberitahu.
 * Jalur yang benar (`decode(?, 'base64')`) hanya boleh ada di satu tempat.
 *
 * **Kenapa INSERT mentah, bukan `create()` lalu `update()`.** `LampiranKerusakan`
 * tidak punya `isi` di `$fillable`, dan `RETURNING id` membuat barisnya lahir
 * lengkap dalam satu pernyataan - tidak ada jendela saat baris ada tanpa isinya.
 */
class PenyimpanLampiran
{
    /**
     * Simpan satu berkas sebagai lampiran laporan.
     *
     * Jenis dan ukuran yang dicatat dihitung **dari isi berkas**, bukan dari
     * nama atau `Content-Type` kiriman: keduanya dikendalikan pengunggah, dan
     * berkas bernama `.png` yang berisi PDF tidak boleh tersimpan sebagai PNG.
     *
     * Ukuran dan jumlah sudah diperiksa validasi HTTP. Pemeriksaan jenis di
     * sini adalah lapis kedua yang murah, untuk jalur yang melewati HTTP
     * (perintah artisan, penyemaian data).
     *
     * @throws RuntimeException
     */
    public function simpan(LaporanKerusakan $laporan, UploadedFile $berkas): LampiranKerusakan
    {
        $jalur = $berkas->getRealPath();
        $info = $jalur ? @getimagesize($jalur) : false;

        if ($info === false) {
            throw new RuntimeException('Berkas ini bukan gambar yang bisa dibaca.');
        }

        $mime = $info['mime'] ?? '';

        if (! isset(LampiranKerusakan::MIME[$mime])) {
            throw new RuntimeException(sprintf(
                'Format gambar "%s" tidak didukung. Pakai JPG, PNG, atau WEBP.',
                $mime,
            ));
        }

        $isi = $jalur ? file_get_contents($jalur) : false;

        if ($isi === false || $isi === '') {
            throw new RuntimeException('Berkas kosong.');
        }

        $baris = DB::selectOne(
            'INSERT INTO lampiran_kerusakan
                (laporan_kerusakan_id, nama_asli, mime, ukuran, isi, created_at, updated_at)
             VALUES (?, ?, ?, ?, decode(?, \'base64\'), now(), now())
             RETURNING id',
            [
                $laporan->id,
                mb_substr((string) $berkas->getClientOriginalName(), 0, 160),
                $mime,
                strlen($isi),
                base64_encode($isi),
            ],
        );

        return LampiranKerusakan::findOrFail($baris->id);
    }

    /**
     * Isi satu lampiran sebagai byte mentah, atau `null`.
     *
     * Kueri tersendiri, bukan lewat model: kolom `bytea` yang ikut terbaca pada
     * setiap `SELECT *` akan menarik megabyte untuk setiap baris daftar
     * laporan, padahal daftar hanya butuh tahu lampirannya ada.
     */
    public function isi(LampiranKerusakan $lampiran): ?string
    {
        $baris = DB::selectOne('SELECT isi FROM lampiran_kerusakan WHERE id = ?', [$lampiran->id]);

        if ($baris === null || $baris->isi === null) {
            return null;
        }

        $isi = $baris->isi;

        if (is_resource($isi)) {
            $isi = stream_get_contents($isi);
        }

        return $isi === '' ? null : $isi;
    }
}
