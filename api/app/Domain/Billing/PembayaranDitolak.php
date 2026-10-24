<?php

namespace App\Domain\Billing;

/**
 * Alasan sebuah pembayaran tidak dapat dicatat.
 *
 * Berupa exception berpesan Indonesia, sama seperti
 * {@see TagihanTidakDapatDiterbitkan}: controller bisa meneruskan alasannya apa
 * adanya tanpa menebak, dan pesan yang menyebut angka konkret ("sisa Rp
 * 450.000") jauh lebih berguna daripada "pembayaran tidak valid".
 */
class PembayaranDitolak extends \RuntimeException
{
    public static function jumlahTidakPositif(int $jumlah): self
    {
        return new self(sprintf(
            'Jumlah pembayaran harus lebih dari nol, bukan %s.',
            number_format($jumlah, 0, ',', '.'),
        ));
    }

    public static function melebihiSisa(int $jumlah, int $sisa): self
    {
        return new self(sprintf(
            'Pembayaran Rp %s melebihi sisa tagihan Rp %s. Kelebihan bayar tidak disimpan sebagai saldo.',
            number_format($jumlah, 0, ',', '.'),
            number_format($sisa, 0, ',', '.'),
        ));
    }

    public static function sudahLunas(int $dibayar): self
    {
        return new self(sprintf(
            'Tagihan ini sudah lunas (dibayar Rp %s). Tidak ada sisa yang bisa dibayar.',
            number_format($dibayar, 0, ',', '.'),
        ));
    }

    public static function dibatalkan(): self
    {
        return new self('Tagihan ini dibatalkan. Pembayaran tidak dapat dicatat untuk tagihan yang dibatalkan.');
    }

    public static function tanggalMasaDepan(string $tanggal, string $hariIni): self
    {
        return new self(sprintf(
            'Tanggal pembayaran %s ada di masa depan. Hari ini %s.',
            $tanggal,
            $hariIni,
        ));
    }

    public static function metodeTidakDikenal(string $metode): self
    {
        return new self(sprintf(
            'Metode pembayaran "%s" tidak dikenal. Pilih salah satu: tunai, transfer, qris, gateway.',
            $metode,
        ));
    }

    /**
     * Tunai wajib menyebut pemegang uangnya.
     *
     * Pesan ini sengaja menyebut alasannya, bukan hanya aturannya. Pemilik yang
     * membacanya perlu tahu bahwa kolom itu ada untuk menjawab "setoran siapa
     * yang belum disetor" - kalau tidak, ia akan menganggapnya kolom
     * birokratis dan mengisinya asal-asalan.
     */
    public static function tunaiTanpaPemegang(): self
    {
        return new self(
            'Pembayaran tunai harus menyebut siapa yang menerima uangnya, '
            .'supaya setoran yang masih di tangan pengelola bisa dilacak.',
        );
    }

    // --- Pembalik pembayaran (REV-FIN-01) ---------------------------------

    /**
     * Pembalikan wajib beralasan.
     *
     * Sama alasannya dengan penolakan pemeriksaan: baris negatif di rekap
     * tanpa keterangan adalah pertanyaan baru, bukan jawaban. Yang membacanya
     * enam bulan lagi harus bisa tahu kenapa uang itu ditarik.
     */
    public static function pembalikTanpaAlasan(): self
    {
        return new self(
            'Pembalikan pembayaran harus menyebutkan alasannya, '
            .'supaya angka yang berkurang di rekap bisa dijelaskan.',
        );
    }

    public static function pembalikTidakBisaDibalik(): self
    {
        return new self(
            'Baris ini adalah pembalik, bukan pembayaran. Pembalik tidak bisa dibalik lagi; '
            .'catat pembayaran baru bila uangnya memang diterima.',
        );
    }

    public static function sudahDibalik(int $jumlah): self
    {
        return new self(sprintf(
            'Pembayaran Rp %s sudah dibalik sebelumnya. Satu pembayaran hanya bisa dibalik sekali.',
            number_format($jumlah, 0, ',', '.'),
        ));
    }

    // --- Pembatalan tagihan (REV-FIN-02) ----------------------------------

    /**
     * Pembatalan wajib beralasan.
     *
     * Sama alasannya dengan pembalikan dan penolakan pemeriksaan: baris batal
     * tanpa keterangan adalah pertanyaan baru bagi yang membacanya nanti.
     * Bedanya di sini, alasan juga disimpan sebagai kolom (`alasan_batal`),
     * bukan hanya di jejak audit — pembatalan mengubah angka yang dilihat
     * penghuni, jadi jawabannya harus ada di barisnya sendiri.
     */
    public static function batalTanpaAlasan(): self
    {
        return new self(
            'Pembatalan tagihan harus menyebutkan alasannya, '
            .'supaya periode yang dibebaskan bisa dijelaskan saat ditanya.',
        );
    }

    public static function sudahDibatalkan(): self
    {
        return new self(
            'Tagihan ini sudah dibatalkan. Periode itu sudah bebas dan bisa diterbitkan ulang.',
        );
    }

    /**
     * Tagihan yang sudah menerima uang tidak boleh dibatalkan.
     *
     * Membatalkannya akan meninggalkan uang yang tidak menempel pada apa pun:
     * `SUM(pembayaran.jumlah)` tetap menghitungnya sebagai pendapatan,
     * sementara tagihannya berstatus batal. Pesan ini menyebut jalan keluarnya,
     * karena penolakan yang tidak menyebut langkah berikutnya hanya membuat
     * pemilik menebak.
     */
    public static function batalSetelahDibayar(int $dibayar): self
    {
        return new self(sprintf(
            'Tagihan ini sudah menerima pembayaran Rp %s, jadi tidak bisa dibatalkan. '
            .'Tarik dulu pembayarannya lewat tombol pembalik, baru batalkan tagihannya.',
            number_format($dibayar, 0, ',', '.'),
        ));
    }

    // --- Pemaafan denda (REV-FIN-07) --------------------------------------

    public static function maafNegatif(int $jumlah): self
    {
        return new self(sprintf(
            'Jumlah denda yang dimaafkan tidak boleh negatif, diterima %s. '
            .'Pemaafan hanya mengurangi tagihan, tidak pernah menambahnya.',
            number_format($jumlah, 0, ',', '.'),
        ));
    }

    /**
     * Pemaafan yang mengurangi uang wajib beralasan.
     *
     * Bukan sekadar kelengkapan: denda yang tiba-tiba nol adalah hal pertama
     * yang ditanyakan penghuni, dan pemilik yang lupa kenapa ia memaafkan akan
     * mengira sistemnya salah hitung.
     */
    public static function maafTanpaAlasan(): self
    {
        return new self(
            'Pemaafan denda harus menyebutkan alasannya, '
            .'supaya "denda Rp 0" bisa dibedakan dari kebijakan tanpa denda.',
        );
    }

    /**
     * Tagihan batal tidak menagih denda, jadi tidak ada yang bisa dimaafkan.
     *
     * CHECK `tagihan_batal_tanpa_pemaafan` menahan keadaan ini di basis data.
     * Pemeriksaan di sini hanya supaya pesannya bisa dibaca manusia, bukan
     * "SQLSTATE 23514".
     */
    public static function maafTagihanDibatalkan(): self
    {
        return new self(
            'Tagihan ini dibatalkan dan tidak menagih denda apa pun, '
            .'jadi tidak ada denda yang bisa dimaafkan. Terbitkan tagihan baru bila memang perlu.',
        );
    }
}
