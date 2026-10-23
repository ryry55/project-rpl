<?php

namespace App\Domain\Pembayaran;

/**
 * Cara uang masuk ke kos ini (T20).
 *
 * Kenapa daftar tertutup, berbeda dari `aturan.kategori` yang sengaja teks
 * bebas. Kategori aturan adalah isi yang ditulis pemilik, jadi daftar tertutup
 * akan menghalangi. Metode pembayaran adalah **kategori akuntansi**: rekap
 * pendapatan mengelompokkan uang menurut kolom ini, dan kalau pemilik menulis
 * "tf", "transfer", "Transfer BCA" sebagai tiga nilai berbeda, rekapnya pecah
 * menjadi tiga baris yang masing-masing terlihat kecil. Rekonsiliasi bank
 * membutuhkan daftar yang jumlahnya pasti.
 *
 * Konsekuensi yang diterima: kos yang menerima cara bayar yang tidak ada di
 * daftar ini tidak bisa mencatatnya tanpa mengubah kode. Itu pertukaran yang
 * dipilih sadar - empat metode ini menutup hampir seluruh kos kecil di
 * Indonesia, dan `lainnya` yang menampung segalanya akan menghapus gunanya
 * rekap itu sendiri.
 *
 * Daftar ini dipakai tiga tempat sekaligus dan tidak boleh disalin: validasi
 * HTTP, CHECK constraint di basis data, dan pilihan di layar. Menyalinnya
 * berarti tiga daftar yang tumbuh sendiri, dan yang paling akhir diubah akan
 * menolak nilai yang diterima yang lain.
 */
final class MetodePembayaran
{
    /** Uang fisik diterima langsung, biasanya oleh pengelola di lokasi. */
    public const TUNAI = 'tunai';

    /** Transfer bank ke rekening pemilik. */
    public const TRANSFER = 'transfer';

    /** QRIS - discan di depan penghuni, masuk ke rekening merchant. */
    public const QRIS = 'qris';

    /** Payment gateway (Midtrans, T22). Uangnya belum tentu sudah settle. */
    public const GATEWAY = 'gateway';

    /**
     * Seluruh metode yang dikenal.
     *
     * Urutannya dipakai sebagai urutan tampil di layar dan di rekap, disusun
     * dari yang paling sering dipakai kos kecil ke yang paling jarang.
     *
     * @var list<string>
     */
    public const SEMUA = [
        self::TUNAI,
        self::TRANSFER,
        self::QRIS,
        self::GATEWAY,
    ];

    /** Baris dibuat oleh orang yang menekan tombol di aplikasi. */
    public const SUMBER_DICATAT_PEMILIK = 'dicatat_pemilik';

    /** Baris dibuat mesin, dari webhook gateway (T22). */
    public const SUMBER_GATEWAY_WEBHOOK = 'gateway_webhook';

    /**
     * @var list<string>
     */
    public const SEMUA_SUMBER = [
        self::SUMBER_DICATAT_PEMILIK,
        self::SUMBER_GATEWAY_WEBHOOK,
    ];

    /**
     * Label yang layak dibaca manusia.
     *
     * Dikirim server ke layar supaya tidak ada dua daftar kosakata yang tumbuh
     * sendiri. Sama alasannya dengan `Wewenang::label()`.
     *
     * @return array<string, string>
     */
    public static function label(): array
    {
        return [
            self::TUNAI => 'Tunai',
            self::TRANSFER => 'Transfer bank',
            self::QRIS => 'QRIS',
            self::GATEWAY => 'Payment gateway',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function labelSumber(): array
    {
        return [
            self::SUMBER_DICATAT_PEMILIK => 'Dicatat manual',
            self::SUMBER_GATEWAY_WEBHOOK => 'Webhook gateway',
        ];
    }

    public static function dikenal(string $metode): bool
    {
        return in_array($metode, self::SEMUA, true);
    }

    /**
     * Apakah metode ini menuntut diketahui siapa yang memegang uangnya.
     *
     * Hanya tunai. Uang tunai ada di tangan seseorang secara fisik, dan
     * pertanyaan "setoran mana yang masih di tangan pengelola" hanya bisa
     * dijawab kalau setiap baris tunai menyebut pemegangnya. Transfer, QRIS,
     * dan gateway masuk ke rekening, jadi tidak ada yang "memegang" - uangnya
     * sudah di bank sejak awal.
     */
    public static function butuhPemegang(string $metode): bool
    {
        return $metode === self::TUNAI;
    }
}
