<?php

namespace App\Domain\Laporan;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Penulis CSV untuk laporan yang dibuka di Excel.
 *
 * Terlihat sepele - "cetak beberapa kolom dengan koma" - tetapi ada empat
 * keputusan di sini yang masing-masing pernah menghasilkan laporan yang salah
 * dibaca orang, dan tidak satu pun terlihat dari layar aplikasi.
 *
 * **1. Pemisahnya titik koma, bukan koma.**
 * Excel dengan pengaturan wilayah Indonesia memakai koma sebagai pemisah
 * DESIMAL. Berkas berpemisah koma karena itu dibaca sebagai satu kolom raksasa
 * berisi "1.500.000,00" di mana-mana - dan pemilik yang membukanya menyimpulkan
 * ekspornya rusak. Titik koma adalah pemisah yang dipakai Excel untuk wilayah
 * itu. Berkasnya tetap CSV yang sah (RFC 4180 mengizinkan titik koma bila
 * dinyatakan), dan LibreOffice maupun Google Sheets membacanya benar.
 *
 * **2. Ada BOM UTF-8 di awal berkas.**
 * Tanpa BOM, Excel di Windows membaca CSV sebagai ANSI dan menampilkan nama
 * penghuni "Budi Santoso" dengan benar tetapi "Ayu Nurhaliza" bisa menjadi
 * "Ayu NurhalizÃ¢". Yang paling sering rusak justru nama orang - dan laporan
 * keuangan yang salah mengeja nama penghuninya akan dikembalikan untuk
 * diperbaiki.
 *
 * **3. Teks yang dimulai `=`, `+`, `-`, `@` diberi awalan kutip.**
 * Ini bukan kerapian: Excel memperlakukan sel yang dimulai salah satu karakter
 * itu sebagai RUMUS. Nama penghuni yang dimulai `=` akan dieksekusi, dan
 * bentuk paling berbahayanya (`=HYPERLINK(...)`, `=cmd|...`) adalah cara
 * penyerang memakai berkas ekspor untuk menjalankan perintah di komputer yang
 * membukanya. Aplikasi ini mengekspor nama yang diketik pemilik sendiri, jadi
 * risikonya kecil - tetapi nama itu bisa berasal dari formulir yang diisi
 * penghuni, dan "kecil" bukan "tidak ada".
 *
 * **4. Angka ditulis mentah, bukan "Rp 1.500.000".**
 * Tujuan ekspor adalah dijumlahkan dan diperiksa, bukan dipandang. Angka yang
 * sudah diformat menjadi teks tidak bisa di-SUM di Excel, dan pemilik yang
 * mencoba menjumlahkannya mendapat 0. Karena itu kolom uang berisi angka bulat
 * tanpa titik, dan satuannya dinyatakan di judul kolom.
 */
class EksporCsv
{
    /**
     * Karakter yang membuat Excel memperlakukan sel sebagai rumus.
     *
     * `\t` dan `\r` ikut disertakan karena keduanya juga bisa memulai sel yang
     * ditafsirkan sebagai rumus pada beberapa versi Excel.
     */
    private const AWALAN_BERBAHAYA = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  list<string>  $judul  Nama kolom, sudah dalam bahasa Indonesia.
     */
    public function __construct(
        private readonly array $judul,
        private readonly string $namaBerkas,
    ) {}

    /**
     * Bangun respons unduhan yang mengalir, bukan string raksasa di memori.
     *
     * Dipakai `streamDownload` karena ekspor pembayaran setahun pada kos 30
     * kamar berisi ratusan baris, dan menyusunnya sebagai satu string lalu
     * mengirimnya sekaligus menahan seluruh isinya di memori bersamaan dengan
     * sisa permintaan. Aliran per baris membuat biayanya tetap.
     *
     * @param  iterable<list<mixed>>  $baris
     */
    public function unduh(iterable $baris): StreamedResponse
    {
        return response()->streamDownload(function () use ($baris) {
            $keluaran = fopen('php://output', 'w');

            // BOM UTF-8. Lihat catatan 2 di atas.
            fwrite($keluaran, "\xEF\xBB\xBF");

            $this->tulisBaris($keluaran, $this->judul);

            foreach ($baris as $satu) {
                $this->tulisBaris($keluaran, $satu);
            }

            fclose($keluaran);
        }, $this->namaBerkas, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // `attachment` memaksa unduhan, bukan tampilan di peramban. Tanpa
            // ini, berkas CSV dibuka sebagai teks di tab baru dan pemilik
            // mengira tidak ada yang terunduh.
            'Content-Disposition' => 'attachment; filename="'.$this->namaBerkas.'"',
            // Ekspor berisi data keuangan dan nama penghuni. Melarang cache
            // membuatnya tidak tertinggal di komputer bersama yang dipakai
            // bergantian.
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    /**
     * @param  resource  $keluaran
     * @param  list<mixed>  $nilai
     */
    private function tulisBaris($keluaran, array $nilai): void
    {
        $sel = array_map(fn ($v) => $this->sel($v), $nilai);

        fputcsv($keluaran, $sel, ';', '"', '\\');
    }

    /**
     * Satu nilai menjadi isi sel yang aman.
     *
     * Angka TIDAK disanitasi: nilai negatif yang sah (baris pembalik) dimulai
     * `-`, dan memberi awalan kutip padanya akan mengubahnya menjadi teks -
     * sehingga total di Excel tidak lagi cocok dengan total di layar. Angka di
     * sini selalu berasal dari kolom basis data bertipe numerik, bukan dari
     * ketikan bebas, jadi tidak ada yang bisa menyelipkan rumus lewat jalur itu.
     */
    private function sel(mixed $nilai): string
    {
        if ($nilai === null) {
            return '';
        }

        if (is_int($nilai) || is_float($nilai)) {
            return (string) $nilai;
        }

        $teks = (string) $nilai;

        // Angka yang dikirim sebagai string (mis. `decimal:0` dari Eloquent)
        // tetap harus lolos sebagai angka, bukan teks berkutip. Diperiksa
        // dengan pola yang ketat supaya "1.500.000" - yang memang teks - tidak
        // ikut dianggap angka.
        if (preg_match('/^-?\d+(\.\d+)?$/', $teks) === 1) {
            return $teks;
        }

        foreach (self::AWALAN_BERBAHAYA as $awalan) {
            if (str_starts_with($teks, $awalan)) {
                // Awalan kutip tunggal: Excel membacanya sebagai "ini teks",
                // dan kutipnya tidak ikut tampil di sel.
                return "'".$teks;
            }
        }

        return $teks;
    }
}
