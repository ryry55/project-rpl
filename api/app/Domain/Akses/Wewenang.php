<?php

namespace App\Domain\Akses;

/**
 * Daftar wewenang yang bisa dimiliki pengelola (T24).
 *
 * Kenapa berupa kelas konstanta, bukan tabel di basis data. Wewenang diperiksa
 * **kode**, bukan dibaca pemilik. Kalau daftarnya hidup di tabel, menambah
 * wewenang berarti menambah baris tanpa ada kode yang memeriksanya - izin yang
 * terlihat ada di layar tetapi tidak melakukan apa pun. Daftar tertutup di sini
 * justru yang benar, dan itulah bedanya dengan `aturan.kategori` yang sengaja
 * teks bebas.
 *
 * Nilainya sengaja dibiarkan dalam bahasa Indonesia karena seluruh istilah
 * domain di aplikasi ini berbahasa Indonesia; mencampurnya dengan
 * `edit_tariff` membuat pembaca harus menerjemahkan di kepala.
 */
final class Wewenang
{
    /** Mencatat uang masuk dari penghuni. */
    public const CATAT_PEMBAYARAN = 'catat_pembayaran';

    /** Menambah dan mengubah data kamar. */
    public const KELOLA_KAMAR = 'kelola_kamar';

    /** Menambah dan mengubah data penghuni. */
    public const KELOLA_PENGHUNI = 'kelola_penghuni';

    /** Menerbitkan tagihan untuk satu kontrak. */
    public const TERBITKAN_TAGIHAN = 'terbitkan_tagihan';

    /** Menetapkan tarif sewa kamar. Sensitif: mengubah angka uang. */
    public const UBAH_TARIF = 'ubah_tarif';

    /** Menetapkan kebijakan kos. Sensitif: mengubah cara kos menagih. */
    public const UBAH_KEBIJAKAN = 'ubah_kebijakan';

    /** Memeriksa dan menyetujui pembayaran yang dicatat orang lain. */
    public const VERIFIKASI_PEMBAYARAN = 'verifikasi_pembayaran';

    /** Membaca jejak audit. */
    public const BACA_AUDIT = 'baca_audit';

    /**
     * Seluruh wewenang yang dikenal.
     *
     * Urutannya dipakai sebagai urutan tampil di layar, jadi disusun menurut
     * alur kerja harian (mencatat uang lebih dulu), bukan abjad.
     *
     * @var list<string>
     */
    public const SEMUA = [
        self::CATAT_PEMBAYARAN,
        self::KELOLA_KAMAR,
        self::KELOLA_PENGHUNI,
        self::TERBITKAN_TAGIHAN,
        self::UBAH_TARIF,
        self::UBAH_KEBIJAKAN,
        self::VERIFIKASI_PEMBAYARAN,
        self::BACA_AUDIT,
    ];

    /**
     * Wewenang yang menyentuh angka uang atau cara kos menagih.
     *
     * Dipisahkan karena dua hal. Pertama, layar pengaturan harus mengelompokkan
     * keduanya berbeda - mencentang "ubah tarif" bukan keputusan yang sama
     * dengan mencentang "kelola kamar". Kedua, kelompok inilah yang nanti
     * (T26) menuntut persetujuan pemilik sebelum berlaku, jadi daftarnya harus
     * bisa dibaca dari satu tempat, bukan ditulis ulang di validator.
     *
     * @var list<string>
     */
    public const SENSITIF = [
        self::UBAH_TARIF,
        self::UBAH_KEBIJAKAN,
    ];

    /**
     * Label yang layak dibaca manusia.
     *
     * Dikirim server ke layar supaya tidak ada dua daftar yang tumbuh sendiri.
     *
     * @return array<string, string>
     */
    public static function label(): array
    {
        return [
            self::CATAT_PEMBAYARAN => 'Mencatat pembayaran',
            self::KELOLA_KAMAR => 'Mengelola kamar',
            self::KELOLA_PENGHUNI => 'Mengelola penghuni',
            self::TERBITKAN_TAGIHAN => 'Menerbitkan tagihan',
            self::UBAH_TARIF => 'Mengubah tarif sewa',
            self::UBAH_KEBIJAKAN => 'Mengubah kebijakan kos',
            self::VERIFIKASI_PEMBAYARAN => 'Memverifikasi pembayaran',
            self::BACA_AUDIT => 'Membaca jejak audit',
        ];
    }

    public static function dikenal(string $wewenang): bool
    {
        return in_array($wewenang, self::SEMUA, true);
    }

    public static function sensitif(string $wewenang): bool
    {
        return in_array($wewenang, self::SENSITIF, true);
    }
}
