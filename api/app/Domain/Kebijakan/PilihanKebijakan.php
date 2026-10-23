<?php

namespace App\Domain\Kebijakan;

/**
 * Menu kebijakan kos: tipe apa saja yang ada, nilai apa saja yang boleh dipilih,
 * dan kolom angka apa yang menyertainya.
 *
 * Dikumpulkan di satu kelas tanpa Eloquent dan tanpa basis data karena tiga
 * pihak membutuhkannya dan ketiganya harus setuju:
 *
 *   1. validasi HTTP (`SimpanKebijakanRequest`) — nilai apa yang diterima;
 *   2. `Kalkulator` — cabang hitung mana yang dipakai untuk `satuan_sewa`;
 *   3. layar Pengaturan — pilihan apa yang ditampilkan ke pemilik.
 *
 * Kalau daftar ini disalin ke tiga tempat, cepat atau lambat satu salinan
 * tertinggal, dan gejalanya adalah pilihan yang tampil di layar tetapi ditolak
 * server — atau sebaliknya, nilai yang tersimpan tetapi tidak pernah dibaca.
 *
 * Yang **tidak** ada di sini: tipe mana yang wajib. Itu urusan
 * {@see PenyelesaiKebijakan::TIPE_WAJIB}, karena kewajiban bergantung pada
 * pembaca yang sudah ada, bukan pada bentuk data.
 */
final class PilihanKebijakan
{
    public const TIPE_SATUAN_SEWA = 'satuan_sewa';

    public const TIPE_JATUH_TEMPO = 'jatuh_tempo';

    public const SATUAN_BULANAN = 'bulanan';

    public const SATUAN_MINGGUAN = 'mingguan';

    public const SATUAN_HARIAN = 'harian';

    public const JATUH_TEMPO_TANGGAL_MASUK = 'tanggal_masuk';

    public const JATUH_TEMPO_TANGGAL_TETAP = 'tanggal_tetap';

    public const JATUH_TEMPO_AKHIR_BULAN = 'akhir_bulan';

    /**
     * Tipe yang dibuka pada LANGKAH 2. `denda` dan `deposit` menyusul bersama
     * pembacanya; CHECK constraint di basis data sudah menyediakan tempatnya.
     *
     * @var list<string>
     */
    public const TIPE_TERSEDIA = [
        self::TIPE_SATUAN_SEWA,
        self::TIPE_JATUH_TEMPO,
    ];

    /**
     * Pilihan sah per tipe, beserta label yang dibaca pemilik.
     *
     * @return array<string, array<string, string>>
     */
    public static function menu(): array
    {
        return [
            self::TIPE_SATUAN_SEWA => [
                self::SATUAN_BULANAN => 'Bulanan',
                self::SATUAN_MINGGUAN => 'Mingguan',
                self::SATUAN_HARIAN => 'Harian',
            ],
            self::TIPE_JATUH_TEMPO => [
                self::JATUH_TEMPO_TANGGAL_MASUK => 'Tanggal masuk',
                self::JATUH_TEMPO_TANGGAL_TETAP => 'Tanggal tetap',
                self::JATUH_TEMPO_AKHIR_BULAN => 'Akhir bulan',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function nilaiValid(string $tipe): array
    {
        return array_keys(self::menu()[$tipe] ?? []);
    }

    public static function labelTipe(string $tipe): string
    {
        return match ($tipe) {
            self::TIPE_SATUAN_SEWA => 'Satuan sewa',
            self::TIPE_JATUH_TEMPO => 'Jatuh tempo',
            default => $tipe,
        };
    }

    public static function labelNilai(string $tipe, ?string $nilai): string
    {
        if ($nilai === null) {
            return 'Belum ditetapkan';
        }

        return self::menu()[$tipe][$nilai] ?? $nilai;
    }

    /**
     * Apakah pilihan ini membutuhkan kolom `tanggal` (1–31).
     */
    public static function butuhTanggal(string $tipe, string $nilai): bool
    {
        return $tipe === self::TIPE_JATUH_TEMPO
            && $nilai === self::JATUH_TEMPO_TANGGAL_TETAP;
    }
}
