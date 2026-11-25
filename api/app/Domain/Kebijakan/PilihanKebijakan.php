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

    /**
     * Pola tagihan: kapan siklus sebuah kontrak dimulai.
     *
     * Berbeda dari tiga tipe lain, tipe ini **tidak wajib**: kosong berarti
     * `tanggal_1`, perilaku yang sudah ada sebelum fitur ini. Itu disengaja —
     * gerbang "kebijakan wajib diisi" di §12 tetap hanya untuk satuan sewa,
     * jatuh tempo, dan denda, sehingga kos yang tidak pernah menyentuh layar
     * Kebijakan tetap menagih seperti biasa.
     */
    public const TIPE_POLA_TAGIHAN = 'pola_tagihan';

    public const POLA_TANGGAL_1 = 'tanggal_1';

    public const POLA_ANNIVERSARY = 'anniversary';

    /**
     * Tanggal terbit otomatis (K-14 Level 1).
     *
     * Sama seperti pola, tipe ini **tidak wajib**: kosong berarti tagihan
     * terbit pada hari siklusnya mulai, yaitu perilaku sebelum fitur ini ada.
     *
     * Nilainya selalu `tanggal_tetap`; yang dipilih pemilik hanyalah angkanya,
     * dan angka itu disimpan di kolom `tanggal` yang sama dengan jatuh tempo.
     * Batasnya 1–28, bukan 1–31: tanggal 29–31 tidak ada di Februari, dan
     * tanggal terbit yang tidak ada berarti tidak pernah terbit.
     */
    public const TIPE_TANGGAL_TERBIT = 'tanggal_terbit';

    public const TERBIT_TANGGAL_TETAP = 'tanggal_tetap';

    /**
     * Nama kos (K-17).
     *
     * Satu-satunya tipe **teks bebas**: tidak ada daftar nama yang bisa disusun
     * di muka, jadi `menu()` sengaja tidak punya entri untuknya dan validasinya
     * memakai batas panjang, bukan `in`. Nilainya dipakai di judul bilah,
     * subjudul layar masuk, subjek email, dan kuitansi.
     *
     * Tidak wajib: kosong berarti "Huni", perilaku sebelum fitur ini.
     */
    public const TIPE_NAMA_KOS = 'nama_kos';

    /** Batas panjang nama kos, sama dengan lebar kolom `nilai`. */
    public const MAKS_NAMA_KOS = 60;

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

    /**
     * Seluruh tipe yang bisa dipilih pemilik.
     *
     * `denda` dibuka di LANGKAH 3, bersamaan dengan pembacanya, bukan
     * sebelumnya: kebijakan yang bisa diisi tetapi diabaikan lebih buruk
     * daripada kebijakan yang belum ada, karena layar menampilkan satu angka
     * sementara sistem memakai angka lain.
     *
     * `deposit` pernah ada di daftar ini dan sudah dikeluarkan di fase terakhir.
     * Alasannya bukan kerapian: deposit dikumpulkan saat kontrak dibuat tetapi
     * tidak pernah dicatat sebagai uang masuk, tidak pernah mengurangi tagihan,
     * dan tidak pernah dikembalikan. Kolom yang menyimpan angka tanpa akibat
     * membuat orang mengira uangnya terjaga. Riwayat lengkapnya di
     * `docs/utang-teknis.md`.
     *
     * @var list<string>
     */
    public const TIPE_TERSEDIA = [
        self::TIPE_SATUAN_SEWA,
        self::TIPE_JATUH_TEMPO,
        self::TIPE_DENDA,
        self::TIPE_POLA_TAGIHAN,
        self::TIPE_TANGGAL_TERBIT,
        self::TIPE_NAMA_KOS,
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
            self::TIPE_POLA_TAGIHAN => [
                self::POLA_TANGGAL_1 => 'Tanggal 1',
                self::POLA_ANNIVERSARY => 'Tanggal masuk (anniversary)',
            ],
            self::TIPE_TANGGAL_TERBIT => [
                self::TERBIT_TANGGAL_TETAP => 'Tanggal tetap',
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

    /**
     * Tipe yang hanya boleh satu baris untuk seluruh kos.
     *
     * Satu daftar, dipakai dua tempat: validasi (`kamar_id` ditolak) dan meta
     * layar (pemilih kamar tidak dikirim untuk tipe ini). Kalau daftarnya
     * disalin, tipe global baru akan lolos di layar lalu ditolak 422.
     *
     * @return list<string>
     */
    public static function tipeGlobal(): array
    {
        return [self::TIPE_POLA_TAGIHAN, self::TIPE_TANGGAL_TERBIT, self::TIPE_NAMA_KOS];
    }

    /**
     * Tipe yang nilainya teks bebas, bukan pilihan dari menu.
     *
     * Dipakai validasi (batas panjang, bukan `in`) dan layar (medan teks, bukan
     * dropdown). Satu daftar, karena salinan di Vue akan tertinggal saat tipe
     * teks berikutnya ditambahkan — dan gejalanya adalah formulir yang mengirim
     * nilai yang ditolak server.
     *
     * @return list<string>
     */
    public static function tipeTeks(): array
    {
        return [self::TIPE_NAMA_KOS];
    }

    /**
     * Batas panjang nilai untuk tipe teks bebas.
     */
    public static function maksTeks(string $tipe): int
    {
        return $tipe === self::TIPE_NAMA_KOS ? self::MAKS_NAMA_KOS : 0;
    }

    public static function labelTipe(string $tipe): string
    {
        return match ($tipe) {
            self::TIPE_SATUAN_SEWA => 'Satuan sewa',
            self::TIPE_JATUH_TEMPO => 'Jatuh tempo',
            self::TIPE_DENDA => 'Denda',
            self::TIPE_POLA_TAGIHAN => 'Pola tagihan',
            self::TIPE_TANGGAL_TERBIT => 'Tanggal terbit',
            self::TIPE_NAMA_KOS => 'Nama kos',
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
     *
     * Tanggal terbit memakainya juga, tetapi batas atasnya 28 — lihat
     * {@see maksTanggal}.
     */
    public static function butuhTanggal(string $tipe, string $nilai): bool
    {
        return ($tipe === self::TIPE_JATUH_TEMPO
                && $nilai === self::JATUH_TEMPO_TANGGAL_TETAP)
            || $tipe === self::TIPE_TANGGAL_TERBIT;
    }

    /**
     * Batas atas kolom `tanggal` untuk satu tipe.
     *
     * Tanggal terbit dibatasi 28 karena 29–31 tidak ada di setiap bulan:
     * tanggal terbit yang tidak ada berarti tidak pernah terbit.
     */
    public static function maksTanggal(string $tipe): int
    {
        return $tipe === self::TIPE_TANGGAL_TERBIT ? 28 : 31;
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

            // `tanpa_denda` tidak membawa angka sendiri.
            default => [],
        };

        return [
            'wajib' => $wajib,
            'terlarang' => array_values(array_diff($semua, $wajib)),
            // Batas dan teks kolom `tanggal` ikut dikirim: tanggal terbit
            // dibatasi 28 dan hanya bisa menunda, sedangkan jatuh tempo boleh
            // 31 dan dijepit ke akhir bulan. Layar tidak perlu menyalin aturan
            // itu — salinan di JavaScript adalah salinan yang paling sering
            // tertinggal saat batasnya berubah.
            'maks_tanggal' => self::maksTanggal($tipe),
            'label_tanggal' => $tipe === self::TIPE_TANGGAL_TERBIT
                ? 'Tanggal terbit'
                : 'Tanggal jatuh tempo',
            'bantuan_tanggal' => $tipe === self::TIPE_TANGGAL_TERBIT
                ? 'Tagihan tidak pernah terbit sebelum siklusnya mulai; tanggal ini hanya bisa menunda. Pilih tanggal yang tidak melewati jatuh tempo.'
                : 'Tanggal 29—31 dijepit ke akhir bulan yang lebih pendek.',
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
