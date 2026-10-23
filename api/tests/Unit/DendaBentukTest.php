<?php

namespace Tests\Unit;

use App\Domain\Billing\DendaTerpilih;
use App\Domain\Billing\Kalkulator;
use App\Domain\Kebijakan\PilihanKebijakan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Empat bentuk denda (T17), diuji tanpa basis data.
 *
 * `DendaTest` menguji rumus R-03 lewat `hitungDenda()` — jalur lama yang tetap
 * dipakai untuk bentuk `persen_harian`. Berkas ini menguji lapisan di atasnya:
 * `hitungDendaMenurut()` yang memilih rumus berdasarkan bentuk yang dipilih
 * pemilik. Dua hal yang tidak bisa diuji di Feature dan karena itu ada di sini:
 *
 *   1. **Angka tepat di titik batas.** Bulan keterlambatan dibulatkan ke atas
 *      terhadap 30 hari, jadi hari ke-30 dan ke-31 menghasilkan angka berbeda
 *      untuk denda nominal. Batas itu hanya terlihat dengan memanggil fungsi
 *      murni.
 *   2. **Bentuk yang tidak berplafon mengembalikan `null`, bukan nol.**
 *      Perbedaan itu menentukan apakah layar menulis "dari maksimum Rp 0"
 *      atau tidak menulis apa pun.
 */
class DendaBentukTest extends TestCase
{
    private Kalkulator $kalkulator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kalkulator = new Kalkulator;
    }

    // --- persen_harian: harus identik dengan R-03 --------------------------

    /**
     * Bentuk `persen_harian` didelegasikan ke `hitungDenda()`, jadi angkanya
     * harus persis sama dengan jalur lama. Kalau keduanya menyimpang, pemilik
     * yang memilih "persen per hari" akan melihat angka berbeda dari yang
     * tertulis di dokumen R-03.
     *
     * @return array<string, array{int, int}>
     */
    public static function kasusHarian(): array
    {
        return [
            'hari 7 masih tenggang' => [7, 0],
            'hari 8 hari pertama' => [8, 15_000],
            'hari 30 masih naik' => [30, 345_000],
            'hari 37 plafon' => [37, 450_000],
            'hari 365 tetap plafon' => [365, 450_000],
        ];
    }

    #[DataProvider('kasusHarian')]
    public function test_persen_harian_sama_dengan_jalur_r03(int $hari, int $harapan): void
    {
        $denda = DendaTerpilih::persenHarian('1.00', '30.00', 7);

        $this->assertSame(
            $harapan,
            $this->kalkulator->hitungDendaMenurut($denda, 1_500_000, $hari),
            "persen_harian, terlambat {$hari} hari",
        );
    }

    // --- persen_bulanan ----------------------------------------------------

    /**
     * Persen per bulan: 2% dari pokok untuk tiap bulan keterlambatan, dibulatkan
     * ke atas. Pokok Rp 1.500.000 dengan 2% per bulan = Rp 30.000 per bulan.
     *
     * Titik batasnya bergeser karena tenggang 7 hari: `hariDenda = hari - 7`,
     * jadi satu bulan penuh tercapai di hari ke-37 (hariDenda 30) dan bulan
     * kedua mulai di hari ke-38 (hariDenda 31). Itulah sebabnya angka-angka di
     * sini tidak bulat per 30 hari kalender.
     *
     * @return array<string, array{int, int}>
     */
    public static function kasusBulanan(): array
    {
        return [
            'hari 7 masih tenggang' => [7, 0],
            // hariDenda 1 -> 1 bulan.
            'hari 8 satu bulan' => [8, 30_000],
            // hariDenda 23 -> 1 bulan.
            'hari 30 masih satu bulan' => [30, 30_000],
            // hariDenda 24 -> 1 bulan.
            'hari 31 masih satu bulan' => [31, 30_000],
            // hariDenda 30 -> tepat 1 bulan.
            'hari 37 tepat satu bulan' => [37, 30_000],
            // hariDenda 31 -> 2 bulan.
            'hari 38 dua bulan' => [38, 60_000],
            'hari 39 dua bulan' => [39, 60_000],
            // hariDenda 60 -> tepat 2 bulan.
            'hari 67 tepat dua bulan' => [67, 60_000],
            // hariDenda 61 -> 3 bulan.
            'hari 68 tiga bulan' => [68, 90_000],
            'hari 69 tiga bulan' => [69, 90_000],
        ];
    }

    #[DataProvider('kasusBulanan')]
    public function test_persen_bulanan_menghitung_bulan_penuh(int $hari, int $harapan): void
    {
        $denda = DendaTerpilih::persenBulanan('2.00', '30.00', 7);

        $this->assertSame(
            $harapan,
            $this->kalkulator->hitungDendaMenurut($denda, 1_500_000, $hari),
            "persen_bulanan 2%, terlambat {$hari} hari",
        );
    }

    public function test_persen_bulanan_berhenti_di_plafon(): void
    {
        // 30% dari 1.500.000 = 450.000. Dengan 2%/bulan, itu tercapai pada
        // bulan ke-15; setelahnya denda tidak bertambah lagi.
        $denda = DendaTerpilih::persenBulanan('2.00', '30.00', 7);

        $this->assertSame(450_000, $this->kalkulator->hitungDendaMenurut($denda, 1_500_000, 500));
        $this->assertSame(450_000, $this->kalkulator->hitungDendaMenurut($denda, 1_500_000, 5_000));
    }

    // --- nominal_tetap -----------------------------------------------------

    /**
     * Nominal tetap: Rp 100.000 untuk tiap bulan keterlambatan, tanpa tenggang
     * (tenggang selalu 0 untuk bentuk ini, karena tidak ada "hari ke berapa").
     *
     * @return array<string, array{int, int}>
     */
    public static function kasusNominal(): array
    {
        return [
            'hari 0 belum terlambat' => [0, 0],
            'hari 1 satu bulan' => [1, 100_000],
            'hari 30 satu bulan' => [30, 100_000],
            'hari 31 dua bulan' => [31, 200_000],
            'hari 60 dua bulan' => [60, 200_000],
            'hari 61 tiga bulan' => [61, 300_000],
        ];
    }

    #[DataProvider('kasusNominal')]
    public function test_nominal_tetap_per_bulan_keterlambatan(int $hari, int $harapan): void
    {
        $denda = DendaTerpilih::nominalTetap(100_000);

        $this->assertSame(
            $harapan,
            $this->kalkulator->hitungDendaMenurut($denda, 1_500_000, $hari),
            "nominal_tetap 100.000, terlambat {$hari} hari",
        );
    }

    public function test_nominal_tetap_tidak_terpengaruh_besar_pokok(): void
    {
        // Denda tetap berarti tetap: pokok 500.000 dan 5.000.000 menghasilkan
        // angka yang sama. Kalau tidak, namanya menyesatkan.
        $denda = DendaTerpilih::nominalTetap(100_000);

        $this->assertSame(100_000, $this->kalkulator->hitungDendaMenurut($denda, 500_000, 10));
        $this->assertSame(100_000, $this->kalkulator->hitungDendaMenurut($denda, 5_000_000, 10));
    }

    // --- tanpa_denda -------------------------------------------------------

    public function test_tanpa_denda_selalu_nol_bukan_galat(): void
    {
        $denda = DendaTerpilih::tanpaDenda();

        // Bahkan setelah bertahun-tahun: bentuk ini bukan "denda nol yang bisa
        // naik", melainkan "tidak ada denda sama sekali".
        $this->assertSame(0, $this->kalkulator->hitungDendaMenurut($denda, 1_500_000, 0));
        $this->assertSame(0, $this->kalkulator->hitungDendaMenurut($denda, 1_500_000, 100));
        $this->assertSame(0, $this->kalkulator->hitungDendaMenurut($denda, 1_500_000, 3_650));
    }

    // --- plafon: null vs nol -----------------------------------------------

    public function test_plafon_null_untuk_bentuk_yang_tidak_berplafon(): void
    {
        // Perbedaan ini yang menentukan apakah layar menulis "dari maksimum
        // Rp 0" — kalimat yang salah, karena denda nominal memang tidak
        // dibatasi plafon.
        $this->assertNull(
            $this->kalkulator->plafonDendaMenurut(DendaTerpilih::nominalTetap(100_000), 1_500_000),
        );
        $this->assertNull(
            $this->kalkulator->plafonDendaMenurut(DendaTerpilih::tanpaDenda(), 1_500_000),
        );
    }

    public function test_plafon_terisi_untuk_bentuk_persen(): void
    {
        // 30% dari 1.500.000.
        $this->assertSame(
            450_000,
            $this->kalkulator->plafonDendaMenurut(DendaTerpilih::persenHarian('1.00', '30.00', 7), 1_500_000),
        );
        $this->assertSame(
            450_000,
            $this->kalkulator->plafonDendaMenurut(DendaTerpilih::persenBulanan('2.00', '30.00', 7), 1_500_000),
        );
    }

    // --- bulanTerlambat: titik batas ---------------------------------------

    /**
     * @return array<string, array{int, int}>
     */
    public static function kasusBulan(): array
    {
        return [
            'nol hari' => [0, 0],
            'negatif' => [-5, 0],
            'satu hari' => [1, 1],
            'tiga puluh hari' => [30, 1],
            'tiga puluh satu hari' => [31, 2],
            'enam puluh hari' => [60, 2],
            'enam puluh satu hari' => [61, 3],
            'tiga ratus enam puluh lima hari' => [365, 13],
        ];
    }

    #[DataProvider('kasusBulan')]
    public function test_bulan_terlambat_dibulatkan_ke_atas(int $hari, int $harapan): void
    {
        $this->assertSame($harapan, $this->kalkulator->bulanTerlambat($hari), "{$hari} hari");
    }

    // --- kesepakatan antar bentuk ------------------------------------------

    public function test_bentuk_tak_dikenal_di_arsip_ditolak_bukan_dibaca_nol(): void
    {
        // `DendaTerpilih` sengaja readonly dan hanya bisa dibentuk lewat
        // factory, jadi bentuk tak dikenal tidak mungkin datang dari domain
        // ini. Yang bisa membawanya adalah **arsip**: `tagihan.kebijakan_terpakai`
        // adalah jsonb, dan bisa berisi bentuk yang sudah tidak ada di kode.
        // Membacanya sebagai nol akan menampilkan "tidak ada denda" untuk
        // tagihan yang jelas punya denda, jadi pembacanya harus menolak.
        //
        // Jaring pengaman itu ada di `PenghitungDenda::bentukDenda()`, yang
        // butuh model Tagihan; diuji lewat HTTP di
        // `tests/Feature/DendaHttpTest.php`. Yang dibuktikan di sini hanya
        // bahwa `DendaTerpilih` tidak bisa dibentuk dengan bentuk asing.
        $this->expectException(\Error::class);

        new DendaTerpilih(
            tipe: 'bunga_berbunga',
            persen: '1.00',
            plafonPersen: '30.00',
            masaTenggang: 0,
            nominal: 0,
        );
    }

    public function test_pokok_negatif_ditolak_untuk_semua_bentuk(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->kalkulator->hitungDendaMenurut(DendaTerpilih::nominalTetap(100_000), -1, 10);
    }

    // --- berjalan / berplafon ----------------------------------------------

    public function test_hanya_bentuk_persen_yang_berjalan(): void
    {
        $this->assertTrue(DendaTerpilih::persenHarian('1.00', '30.00', 7)->berjalan());
        $this->assertTrue(DendaTerpilih::persenBulanan('2.00', '30.00', 7)->berjalan());
        $this->assertFalse(DendaTerpilih::nominalTetap(100_000)->berjalan());
        $this->assertFalse(DendaTerpilih::tanpaDenda()->berjalan());
    }

    /**
     * `berjalan()` bernilai sama untuk `nominal_tetap` dan `tanpa_denda`, jadi
     * layar tidak boleh memakainya sebagai satu-satunya cabang.
     *
     * Inilah cacat yang pernah terjadi: panel rincian dan portal penghuni
     * memakai `berjalan` untuk memilih antara "persen" dan "nominal", sehingga
     * kos yang **tidak menerapkan denda** menampilkan "Nominal tetap per bulan,
     * dihitung per bulan keterlambatan" — kalimat yang menyatakan kebalikan
     * dari kebijakan pemiliknya. `bentuk()` yang membedakan keduanya.
     */
    public function test_bentuk_membedakan_nominal_dari_tanpa_denda(): void
    {
        $this->assertSame('persen', DendaTerpilih::persenHarian('1.00', '30.00', 7)->bentuk());
        $this->assertSame('persen', DendaTerpilih::persenBulanan('2.00', '30.00', 7)->bentuk());
        $this->assertSame('nominal', DendaTerpilih::nominalTetap(100_000)->bentuk());
        $this->assertSame('tanpa', DendaTerpilih::tanpaDenda()->bentuk());
    }

    public function test_bentuk_selalu_konsisten_dengan_berjalan(): void
    {
        // Kontrak antar-keduanya: hanya `persen` yang berjalan. Kalau suatu saat
        // sebuah bentuk baru ditambahkan, tes ini memaksa penulisnya memutuskan
        // keduanya sekaligus, bukan membiarkan layar menebak.
        foreach ([
            DendaTerpilih::persenHarian('1.00', '30.00', 7),
            DendaTerpilih::persenBulanan('2.00', '30.00', 7),
            DendaTerpilih::nominalTetap(100_000),
            DendaTerpilih::tanpaDenda(),
        ] as $denda) {
            $this->assertSame(
                $denda->bentuk() === 'persen',
                $denda->berjalan(),
                "bentuk {$denda->bentuk()} vs berjalan",
            );
        }
    }

    public function test_to_array_memuat_semua_kolom_untuk_arsip(): void
    {
        // Bentuk array ini yang masuk ke `tagihan.kebijakan_terpakai`, jadi
        // kuncinya harus sama dengan yang dibaca `PenghitungDenda`.
        $this->assertSame(
            [
                'tipe' => PilihanKebijakan::DENDA_PERSEN_HARIAN,
                'persen' => '1.00',
                'plafon_persen' => '30.00',
                'masa_tenggang' => 7,
                'nominal' => 0,
            ],
            DendaTerpilih::persenHarian('1.00', '30.00', 7)->toArray(),
        );
    }
}
