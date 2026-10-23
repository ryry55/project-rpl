<?php

namespace App\Domain\Notifikasi;

use App\Domain\Akses\Wewenang;

/**
 * Daftar tertutup peristiwa yang boleh memicu notifikasi (T25).
 *
 * Kenapa tertutup, seperti {@see Wewenang}. Kalau peristiwa
 * berupa teks bebas, layar pengaturan akan menampilkan baris yang tidak pernah
 * dipicu kode mana pun - dan pemilik yang mematikan salah satunya tidak akan
 * melihat bedanya, karena memang tidak ada yang berubah.
 *
 * Setiap peristiwa membawa **kanal bawaannya**. Pembagiannya bukan selera:
 *
 *   - Yang mengubah angka uang atau cara kos menagih, dan yang terjadi beberapa
 *     kali sebulan saja, dikirim juga lewat email. Itu peristiwa yang perlu
 *     dibaca pemilik yang tidak tinggal di lokasi.
 *   - Yang terjadi puluhan kali sehari - mencatat pembayaran, memeriksa
 *     setoran, mengubah data kamar - hanya in-app. Pengelola yang mencatat 30
 *     pembayaran sehari akan membuat pemilik menerima 30 email sehari, dan
 *     dalam seminggu semuanya masuk folder spam. Notifikasi yang seharusnya
 *     melindungi justru berhenti dibaca.
 *
 * Bawaan ini bisa diubah pemilik di layar Pengaturan. Yang tidak bisa diubah:
 * notifikasi tidak pernah menjadi syarat sahnya sebuah perubahan. Itu dijaga
 * `log_audit`, bukan kotak masuk.
 */
final class Peristiwa
{
    /** Pengelola mengajukan perubahan tarif atau kebijakan. */
    public const USULAN_DIAJUKAN = 'usulan.diajukan';

    /** Pemilik menyetujui atau menolak usulan. */
    public const USULAN_DIPUTUSKAN = 'usulan.diputuskan';

    /** Tarif sewa berubah. */
    public const TARIF_DIUBAH = 'tarif.diubah';

    /** Kebijakan kos berubah. */
    public const KEBIJAKAN_DIUBAH = 'kebijakan.diubah';

    /** Tagihan sebulan diterbitkan sekaligus untuk semua kontrak aktif. */
    public const TAGIHAN_MASSAL = 'tagihan.massal';

    /** Satu tagihan terbit untuk satu penghuni. */
    public const TAGIHAN_TERBIT = 'tagihan.terbit';

    /** Uang masuk dicatat. */
    public const PEMBAYARAN_DICATAT = 'pembayaran.dicatat';

    /** Pemeriksaan atas pembayaran yang dicatat orang lain. */
    public const PEMBAYARAN_DIVERIFIKASI = 'pembayaran.diverifikasi';

    public const KANAL_IN_APP = 'in_app';

    public const KANAL_EMAIL = 'email';

    /**
     * @var list<string>
     */
    public const SEMUA = [
        self::USULAN_DIAJUKAN,
        self::USULAN_DIPUTUSKAN,
        self::TARIF_DIUBAH,
        self::KEBIJAKAN_DIUBAH,
        self::TAGIHAN_MASSAL,
        self::TAGIHAN_TERBIT,
        self::PEMBAYARAN_DICATAT,
        self::PEMBAYARAN_DIVERIFIKASI,
    ];

    /**
     * Kanal bawaan per peristiwa.
     *
     * @return list<string>
     */
    public static function kanalBawaan(string $peristiwa): array
    {
        return match ($peristiwa) {
            self::USULAN_DIAJUKAN,
            self::USULAN_DIPUTUSKAN,
            self::TARIF_DIUBAH,
            self::KEBIJAKAN_DIUBAH,
            self::TAGIHAN_MASSAL,
            self::TAGIHAN_TERBIT => [self::KANAL_IN_APP, self::KANAL_EMAIL],

            // Tiga yang tersisa terjadi berkali-kali sehari. In-app saja.
            default => [self::KANAL_IN_APP],
        };
    }

    public static function dikenal(string $peristiwa): bool
    {
        return in_array($peristiwa, self::SEMUA, true);
    }

    public static function dikenalKanal(string $kanal): bool
    {
        return in_array($kanal, [self::KANAL_IN_APP, self::KANAL_EMAIL], true);
    }

    /**
     * Judul pendek untuk daftar di layar.
     */
    public static function label(string $peristiwa): string
    {
        return match ($peristiwa) {
            self::USULAN_DIAJUKAN => 'Usulan perubahan diajukan',
            self::USULAN_DIPUTUSKAN => 'Usulan diputuskan',
            self::TARIF_DIUBAH => 'Tarif sewa diubah',
            self::KEBIJAKAN_DIUBAH => 'Kebijakan kos diubah',
            self::TAGIHAN_MASSAL => 'Tagihan sebulan diterbitkan',
            self::TAGIHAN_TERBIT => 'Tagihan baru terbit',
            self::PEMBAYARAN_DICATAT => 'Pembayaran dicatat',
            self::PEMBAYARAN_DIVERIFIKASI => 'Pembayaran diperiksa',
            default => $peristiwa,
        };
    }
}
