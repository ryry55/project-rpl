<?php

namespace App\Domain\Rekening;

use App\Models\Rekening;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Menyimpan dan menyajikan gambar QRIS untuk satu rekening.
 *
 * **Kenapa kelas tersendiri, bukan beberapa baris di controller.** Menulis kolom
 * `bytea` punya satu jebakan yang tidak menimbulkan galat: parameter teks biasa
 * tersimpan sebagai **0 byte**. Unggahan tampak berhasil, `gambar_ukuran` terisi,
 * tetapi gambarnya kosong - dan tidak ada satu pun pesan yang memberitahunya.
 * Jalur yang benar (`decode(?, 'base64')`) hanya boleh ada di satu tempat, supaya
 * tidak ada kesempatan kedua untuk menulisnya dengan cara yang salah.
 *
 * **Kenapa base64, bukan `PARAM_LOB`.** Keduanya terbukti utuh, tetapi
 * `PARAM_LOB` memerlukan PDO langsung, artinya keluar dari query builder yang
 * dipakai seluruh aplikasi ini. `decode(?, 'base64')` tetap query biasa: ia ikut
 * transaksi, ikut rollback, dan ikut `DB::transaction` di controller tanpa
 * pengecualian.
 *
 * Pembengkakan 33% dari base64 hanya terjadi pada parameter yang dikirim, bukan
 * pada yang disimpan - kolomnya tetap `bytea` berisi byte asli.
 */
class PenyimpanGambarQris
{
    /**
     * Periksa berkas kiriman dan kembalikan isinya bila sah.
     *
     * Yang diperiksa, berurutan dari yang paling murah:
     *
     *   1. **Ukuran**, supaya berkas 50 MB tidak pernah masuk ke memori.
     *   2. **Isi sebenarnya**, lewat `getimagesize`. Ini yang menentukan; nama
     *      berkas dan `Content-Type` kiriman dikendalikan pengunggah, jadi
     *      keduanya tidak dipakai untuk memutuskan apa pun.
     *   3. **Daftar tipe tertutup**, supaya SVG - dokumen yang bisa memuat
     *      skrip - tidak lolos hanya karena ia "gambar".
     *
     * @return array{isi: string, mime: string, ukuran: int}
     *
     * @throws GambarQrisDitolak
     */
    public function periksa(UploadedFile $berkas): array
    {
        if (! $berkas->isValid()) {
            throw GambarQrisDitolak::gagalUnggah();
        }

        $ukuran = (int) $berkas->getSize();

        if ($ukuran <= 0) {
            throw GambarQrisDitolak::kosong();
        }

        if ($ukuran > Rekening::BATAS_GAMBAR) {
            throw GambarQrisDitolak::terlaluBesar($ukuran, Rekening::BATAS_GAMBAR);
        }

        $jalur = $berkas->getRealPath();
        $info = $jalur ? @getimagesize($jalur) : false;

        if ($info === false) {
            // Ini juga yang menangkap berkas yang dinamai ".png" tetapi isinya
            // PDF atau skrip: yang dibaca isinya, bukan namanya.
            throw GambarQrisDitolak::bukanGambar();
        }

        $mime = $info['mime'] ?? '';

        if (! isset(Rekening::MIME_GAMBAR[$mime])) {
            throw GambarQrisDitolak::formatTidakDidukung($mime);
        }

        $isi = file_get_contents($jalur);

        if ($isi === false || $isi === '') {
            throw GambarQrisDitolak::kosong();
        }

        return ['isi' => $isi, 'mime' => $mime, 'ukuran' => strlen($isi)];
    }

    /**
     * Tulis gambar ke satu rekening.
     *
     * `decode(?, 'base64')` adalah inti kelas ini. Lihat komentar kelas untuk
     * alasan mengapa bukan parameter biasa.
     */
    public function simpan(Rekening $rekening, string $isi, string $mime): void
    {
        DB::update(
            "UPDATE rekening SET gambar = decode(?, 'base64'), gambar_mime = ?, gambar_ukuran = ? WHERE id = ?",
            [base64_encode($isi), $mime, strlen($isi), $rekening->id]
        );

        // Model di memori disegarkan supaya pemanggil yang langsung mengirim
        // respons tidak mengirim `punya_gambar` yang sudah basi.
        $rekening->refresh();
    }

    /**
     * Isi gambar sebagai byte mentah, atau `null`.
     *
     * Query terpisah, bukan lewat model: kolom `bytea` yang ikut terbaca pada
     * setiap `SELECT *` akan menarik ratusan kilobyte untuk setiap baris daftar
     * rekening, padahal daftar hanya butuh tahu apakah gambarnya ada.
     */
    public function isi(Rekening $rekening): ?string
    {
        $baris = DB::selectOne('SELECT gambar FROM rekening WHERE id = ?', [$rekening->id]);

        if ($baris === null || $baris->gambar === null) {
            return null;
        }

        $isi = $baris->gambar;

        if (is_resource($isi)) {
            $isi = stream_get_contents($isi);
        }

        return $isi === '' ? null : $isi;
    }
}
