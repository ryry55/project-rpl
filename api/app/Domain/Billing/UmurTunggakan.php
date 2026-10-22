<?php

namespace App\Domain\Billing;

/**
 * Kelompok umur tunggakan (R-04).
 *
 * Kunci kelompok dipakai di tiga tempat: badan JSON, kelas CSS di layar, dan
 * kunci array di PHP. Karena itu bentuknya konstan dan stabil — bukan label
 * tampilan. Label boleh berubah kapan saja tanpa memecah apa pun.
 *
 * Kenapa kelompok "belum jatuh tempo" ada padahal namanya tunggakan: pemilik
 * perlu melihat tagihan yang **belum** waktunya bayar secara terpisah. Kalau
 * tagihan itu dimasukkan ke kelompok 0-30, daftar tunggakan akan berisi orang
 * yang tidak menunggak, dan angka "jumlah penunggak" tidak bisa dipercaya.
 */
final class UmurTunggakan
{
    /** Tagihan belum lewat jatuh tempo: `hari_terlambat < 0`. */
    public const BELUM_JATUH_TEMPO = 'belum_jatuh_tempo';

    /** Terlambat 0 sampai 30 hari. */
    public const HARI_0_30 = '0_30';

    /** Terlambat 31 sampai 60 hari. */
    public const HARI_31_60 = '31_60';

    /** Terlambat 61 hari atau lebih. */
    public const LEBIH_60 = 'lebih_60';

    /**
     * Urutan tampil: dari yang paling ringan ke yang paling parah.
     *
     * @return list<string>
     */
    public static function semua(): array
    {
        return [self::BELUM_JATUH_TEMPO, self::HARI_0_30, self::HARI_31_60, self::LEBIH_60];
    }

    public static function label(string $kunci): string
    {
        return match ($kunci) {
            self::BELUM_JATUH_TEMPO => 'Belum jatuh tempo',
            self::HARI_0_30 => '0-30 hari',
            self::HARI_31_60 => '31-60 hari',
            self::LEBIH_60 => 'Lebih dari 60 hari',
            default => $kunci,
        };
    }

    /**
     * Keterangan singkat: apa arti kelompok ini bagi pemilik.
     *
     * Angka tanpa tafsiran tidak menuntun tindakan — dan itulah keluhan yang
     * mendasari tiket ini.
     */
    public static function keterangan(string $kunci): string
    {
        return match ($kunci) {
            self::BELUM_JATUH_TEMPO => 'Belum waktunya bayar',
            self::HARI_0_30 => 'Baru terlambat, cukup diingatkan',
            self::HARI_31_60 => 'Perlu ditagih langsung',
            self::LEBIH_60 => 'Perlu keputusan: tegur atau akhiri kontrak',
            default => '',
        };
    }
}
