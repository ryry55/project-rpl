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

    public const TIPE_DENDA = 'denda';

    public const TIPE_DEPOSIT = 'deposit';

    public const SATUAN_BULANAN = 'bulanan';

    public const SATUAN_MINGGUAN = 'mingguan';

    public const SATUAN_HARIAN = 'harian';

    public const JATUH_TEMPO_TANGGAL_MASUK = 'tanggal_masuk';

    public const JATUH_TEMPO_TANGGAL_TETAP = 'tanggal_tetap';

    public const JATUH_TEMPO_AKHIR_BULAN = 'akhir_bulan';

    public const DENDA_PERSEN_HARIAN = 'persen_harian';

    public const DENDA_PERSEN_BULANAN = 'persen_bulanan';

    public const DENDA_NOMINAL_TETAP = 'nominal_tetap';

    public const DENDA_TANPA_DENDA = 'tanpa_denda';

    public const DEPOSIT_TANPA_DEPOSIT = 'tanpa_deposit';

    public const DEPOSIT_NOMINAL = 'nominal';

    public const DEPOSIT_SATU_BULAN = 'satu_bulan';

    /**
     * Seluruh tipe yang bisa dipilih pemilik.
     *
     * `denda` dan `deposit` dibuka di LANGKAH 3. Keduanya dibuka bersamaan
     * dengan pembacanya, bukan sebelumnya: kebijakan yang bisa diisi tetapi
     * diabaikan lebih buruk daripada kebijakan yang belum ada, karena layar
     * menampilkan satu angka sementara sistem memakai angka lain.
     *
     * @var list<string>
     */
    public const TIPE_TERSEDIA = [
        self::TIPE_SATUAN_SEWA,
        self::TIPE_JATUH_TEMPO,
        self::TIPE_DENDA,
        self::TIPE_DEPOSIT,
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
            self::TIPE_DENDA => [
                self::DENDA_PERSEN_HARIAN => 'Persen per hari',
                self::DENDA_PERSEN_BULANAN => 'Persen per bulan',
                self::DENDA_NOMINAL_TETAP => 'Nominal tetap',
                self::DENDA_TANPA_DENDA => 'Tanpa denda',
            ],
            self::TIPE_DEPOSIT => [
                self::DEPOSIT_TANPA_DEPOSIT => 'Tanpa deposit',
                self::DEPOSIT_NOMINAL => 'Nominal tetap',
                self::DEPOSIT_SATU_BULAN => 'Satu bulan sewa',
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
            self::TIPE_DENDA => 'Denda',
            self::TIPE_DEPOSIT => 'Deposit',
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

    /**
     * Kolom angka yang wajib terisi untuk satu pilihan, dan mana yang harus kosong.
     *
     * Dikembalikan sebagai satu daftar `wajib` agar pemanggil tidak perlu
     * menuliskan matriks yang sama tiga kali (validasi HTTP, penyimpanan, dan
     * penjelasan ke layar). Aturannya: **hanya** kolom yang benar-benar dibaca
     * rumus yang boleh terisi. Angka menganggur di baris kebijakan membuat
     * pembaca mengira angka itu dipakai, padahal tidak.
     *
     * Kolom yang tidak disebut di sini harus NULL.
     *
     * `masa_tenggang` sengaja **wajib** pada denda persen, bukan opsional
     * dengan bawaan nol. Tenggang yang tidak disebut akan terbaca sebagai
     * "denda mulai hari pertama", dan itu pilihan yang berbeda arti dengan
     * "tidak ada tenggang" hanya bagi orang yang tahu bawaannya. Meminta
     * pemilik menyebut angkanya membuat pilihan itu disengaja.
     *
     * @return array{wajib: list<string>, terlarang: list<string>}
     */
    public static function kolomAngka(string $tipe, string $nilai): array
    {
        $semua = ['persen', 'plafon_persen', 'masa_tenggang', 'nominal', 'tanggal'];

        $wajib = match (true) {
            self::butuhTanggal($tipe, $nilai) => ['tanggal'],

            // Denda persen butuh tarif, plafon, dan tenggang sekaligus: tarif
            // tanpa plafon berarti denda bisa tumbuh tanpa batas, plafon tanpa
            // tarif tidak menghasilkan angka apa pun, dan tanpa tenggang
            // "denda mulai kapan" hanya bisa ditebak.
            $tipe === self::TIPE_DENDA && $nilai === self::DENDA_PERSEN_HARIAN => ['persen', 'plafon_persen', 'masa_tenggang'],
            $tipe === self::TIPE_DENDA && $nilai === self::DENDA_PERSEN_BULANAN => ['persen', 'plafon_persen', 'masa_tenggang'],

            // Denda nominal tidak butuh plafon: angkanya tetap, jadi tidak ada
            // yang bisa tumbuh melewati batas. Tidak butuh tenggang juga:
            // dendanya tidak punya "hari ke berapa", jadi tenggang tidak
            // mengubah angka apa pun.
            $tipe === self::TIPE_DENDA && $nilai === self::DENDA_NOMINAL_TETAP => ['nominal'],

            $tipe === self::TIPE_DEPOSIT && $nilai === self::DEPOSIT_NOMINAL => ['nominal'],

            // `tanpa_denda`, `tanpa_deposit`, dan `satu_bulan` tidak membawa
            // angka sendiri: yang ketiga dihitung dari tarif sewa saat kontrak
            // dibuat, sehingga menyimpan nominal di sini akan membekukan angka
            // yang seharusnya mengikuti tarif.
            default => [],
        };

        return [
            'wajib' => $wajib,
            'terlarang' => array_values(array_diff($semua, $wajib)),
        ];
    }

    /**
     * Apakah tipe ini memakai `masa_tenggang`.
     *
     * Hanya denda persen: tenggang memperpanjang umur denda yang berjalan tiap
     * hari. Denda nominal tetap tidak punya "hari ke berapa", jadi tenggang
     * tidak berarti apa pun di sana.
     */
    public static function butuhTenggang(string $tipe, string $nilai): bool
    {
        return $tipe === self::TIPE_DENDA
            && in_array($nilai, [self::DENDA_PERSEN_HARIAN, self::DENDA_PERSEN_BULANAN], true);
    }
}
