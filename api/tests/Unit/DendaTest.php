<?php

namespace Tests\Unit;

use App\Domain\Billing\Kalkulator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Seam 1 — denda (R-03), diuji tanpa basis data.
 *
 * Inilah aturan yang paling mudah salah dan paling mungkin ditanya saat sidang,
 * karena angkanya berubah tiap hari. Semua titik batas diuji di sini dalam
 * hitungan milidetik; berkas Feature hanya memeriksa bahwa angka yang benar
 * benar tersimpan dan terbaca lewat HTTP.
 *
 * Parameter sengaja berbentuk array primitif — sama seperti yang dibaca dari
 * snapshot baris `tagihan` — supaya tidak ada model yang perlu dibuat.
 */
class DendaTest extends TestCase
{
    private Kalkulator $kalkulator;

    /**
     * Parameter dasar: 1%/hari, plafon 30%, tenggang 7 hari.
     *
     * @var array{tarif_persen: string, plafon_persen: string, masa_tenggang: int}
     */
    private array $parameter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kalkulator = new Kalkulator;
        $this->parameter = [
            'tarif_persen' => '1.00',
            'plafon_persen' => '30.00',
            'masa_tenggang' => 7,
        ];
    }

    // --- Titik batas yang disebut tiket ------------------------------------

    /**
     * Urutan lengkap dari tiket T6 untuk pokok Rp 1.500.000.
     *
     * @return array<string, array{int, int}>
     */
    public static function kasusBatas(): array
    {
        return [
            // hariTerlambat, harapan denda
            'hari 0 belum terlambat' => [0, 0],
            'hari 7 masih dalam tenggang' => [7, 0],
            'hari 8 hari denda pertama' => [8, 15_000],
            'hari 30 masih naik' => [30, 345_000],
            'hari 37 plafon tercapai tepat' => [37, 450_000],
            'hari 38 tetap plafon' => [38, 450_000],
            'hari 100 tetap plafon' => [100, 450_000],
            'hari 365 tetap plafon' => [365, 450_000],
        ];
    }

    #[DataProvider('kasusBatas')]
    public function test_denda_pada_titik_batas(int $hariTerlambat, int $harapan): void
    {
        $this->assertSame(
            $harapan,
            $this->kalkulator->hitungDenda(1_500_000, $hariTerlambat, $this->parameter),
            "pokok 1.500.000, terlambat {$hariTerlambat} hari",
        );
    }

    public function test_urutan_lengkap_dari_tiket(): void
    {
        // Tiket menyebut urutan: 0, 15.000, 345.000, 450.000, 450.000, 450.000
        // untuk hari 7, 8, 30, 37, 100, 365.
        $urutan = [];
        foreach ([7, 8, 30, 37, 100, 365] as $hari) {
            $urutan[] = $this->kalkulator->hitungDenda(1_500_000, $hari, $this->parameter);
        }

        $this->assertSame([0, 15_000, 345_000, 450_000, 450_000, 450_000], $urutan);
    }

    public function test_belum_jatuh_tempo_berarti_tanpa_denda(): void
    {
        // Hari terlambat negatif = tagihan belum jatuh tempo. Ini bukan kasus
        // teoretis: setiap tagihan baru melewati keadaan ini.
        $this->assertSame(0, $this->kalkulator->hitungDenda(1_500_000, -5, $this->parameter));
        $this->assertSame(0, $this->kalkulator->hitungDenda(1_500_000, -365, $this->parameter));
    }

    // --- Sifat: plafon tidak pernah terlampaui -----------------------------

    public function test_denda_tidak_pernah_melebihi_plafon_untuk_banyak_pokok(): void
    {
        // Sifat, bukan contoh. Kalau ada satu saja pokok yang melampaui plafon,
        // itu bug yang akan muncul di tagihan nyata dengan angka kebetulan.
        foreach ([1, 999, 50_000, 1_500_000, 7_777_777, 99_999_999] as $pokok) {
            $plafon = $this->kalkulator->plafonDenda($pokok, $this->parameter);

            foreach ([0, 1, 7, 8, 20, 30, 37, 38, 60, 200, 1000] as $hari) {
                $denda = $this->kalkulator->hitungDenda($pokok, $hari, $this->parameter);

                $this->assertLessThanOrEqual(
                    $plafon,
                    $denda,
                    "pokok {$pokok}, terlambat {$hari} hari melampaui plafon",
                );
                $this->assertGreaterThanOrEqual(0, $denda);
            }
        }
    }

    public function test_denda_tidak_pernah_menurun_walau_hari_bertambah(): void
    {
        // Monoton naik. Kalau pernah turun, penghuni yang terlambat lebih lama
        // justru membayar lebih sedikit — dan itu tidak bisa dijelaskan.
        $sebelumnya = -1;
        for ($hari = 0; $hari <= 60; $hari++) {
            $denda = $this->kalkulator->hitungDenda(1_500_000, $hari, $this->parameter);
            $this->assertGreaterThanOrEqual($sebelumnya, $denda, "denda turun di hari {$hari}");
            $sebelumnya = $denda;
        }
    }

    public function test_plafon_tercapai_tepat_di_hari_terlambat_37(): void
    {
        $plafon = $this->kalkulator->plafonDenda(1_500_000, $this->parameter);

        // Hari 36 belum plafon, hari 37 sudah.
        $this->assertLessThan($plafon, $this->kalkulator->hitungDenda(1_500_000, 36, $this->parameter));
        $this->assertSame($plafon, $this->kalkulator->hitungDenda(1_500_000, 37, $this->parameter));
    }

    // --- Sifat: dihitung sekali jalan --------------------------------------

    public function test_hasil_sama_berapa_kali_pun_dipanggil(): void
    {
        // Bukti bahwa denda tidak diakumulasi: memanggil 100 kali tidak
        // mengubah hasil. Kalau ada state atau akumulasi, tes ini gagal.
        $pertama = $this->kalkulator->hitungDenda(1_500_000, 20, $this->parameter);

        for ($i = 0; $i < 100; $i++) {
            $this->assertSame($pertama, $this->kalkulator->hitungDenda(1_500_000, 20, $this->parameter));
        }
    }

    public function test_hari_denda_nol_dalam_tenggang(): void
    {
        $this->assertSame(0, $this->kalkulator->hariDenda(0, 7));
        $this->assertSame(0, $this->kalkulator->hariDenda(7, 7));
        $this->assertSame(1, $this->kalkulator->hariDenda(8, 7));
        $this->assertSame(30, $this->kalkulator->hariDenda(37, 7));
    }

    public function test_hari_hingga_plafon_untuk_parameter_standar(): void
    {
        // 30% / 1% = 30 hari denda → hari terlambat ke-37.
        $this->assertSame(30, $this->kalkulator->hariHinggaPlafon($this->parameter));
    }

    public function test_hari_hingga_plafon_dengan_tarif_yang_tidak_membagi_habis(): void
    {
        // 25% / 2% = 12,5 → dibulatkan ke atas jadi 13. Denda baru menyentuh
        // plafon di hari ke-13, bukan ke-12.
        $parameter = [
            'tarif_persen' => '2.00',
            'plafon_persen' => '25.00',
            'masa_tenggang' => 7,
        ];

        $this->assertSame(13, $this->kalkulator->hariHinggaPlafon($parameter));

        $plafon = $this->kalkulator->plafonDenda(1_000_000, $parameter);
        $this->assertLessThan($plafon, $this->kalkulator->hitungDenda(1_000_000, 19, $parameter));
        $this->assertSame($plafon, $this->kalkulator->hitungDenda(1_000_000, 20, $parameter));
    }

    // --- Pembulatan --------------------------------------------------------

    public function test_pembulatan_denda_memakai_half_up(): void
    {
        // Pokok 1.234.567 × 1% = 12.345,67 → 12.346 (half-up).
        $this->assertSame(12_346, $this->kalkulator->hitungDenda(1_234_567, 8, $this->parameter));

        // Plafon 30% dari 1.234.567 = 370.370,1 → 370.370.
        $this->assertSame(370_370, $this->kalkulator->plafonDenda(1_234_567, $this->parameter));
    }

    public function test_persentase_dibaca_sebagai_teks_bukan_float(): void
    {
        // 1,15% tidak bisa direpresentasikan eksak sebagai float. Dibaca sebagai
        // teks, hasilnya tetap tepat.
        $parameter = [
            'tarif_persen' => '1.15',
            'plafon_persen' => '30.00',
            'masa_tenggang' => 0,
        ];

        // 1.000.000 × 1,15% = 11.500 tepat.
        $this->assertSame(11_500, $this->kalkulator->hitungDenda(1_000_000, 1, $parameter));
    }

    public function test_persentase_bulat_tanpa_desimal_juga_diterima(): void
    {
        $parameter = ['tarif_persen' => '1', 'plafon_persen' => '30', 'masa_tenggang' => 7];

        $this->assertSame(15_000, $this->kalkulator->hitungDenda(1_500_000, 8, $parameter));
    }

    // --- Parameter tidak wajar ---------------------------------------------

    public function test_pokok_negatif_ditolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->kalkulator->hitungDenda(-1, 10, $this->parameter);
    }

    public function test_persentase_tidak_sah_ditolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->kalkulator->hitungDenda(1_000_000, 10, [
            'tarif_persen' => 'satu persen',
            'plafon_persen' => '30.00',
            'masa_tenggang' => 7,
        ]);
    }

    public function test_tarif_nol_berarti_tanpa_denda(): void
    {
        // Tarif 0% bukan kesalahan: itu cara mematikan denda tanpa mengubah
        // skema. Hasilnya harus 0, bukan pembagian dengan nol.
        $parameter = ['tarif_persen' => '0.00', 'plafon_persen' => '30.00', 'masa_tenggang' => 7];

        $this->assertSame(0, $this->kalkulator->hitungDenda(1_500_000, 100, $parameter));
        $this->assertSame(0, $this->kalkulator->hariHinggaPlafon($parameter));
    }

    public function test_tenggang_nol_berarti_denda_mulai_hari_pertama(): void
    {
        $parameter = ['tarif_persen' => '1.00', 'plafon_persen' => '30.00', 'masa_tenggang' => 0];

        $this->assertSame(0, $this->kalkulator->hitungDenda(1_000_000, 0, $parameter));
        $this->assertSame(10_000, $this->kalkulator->hitungDenda(1_000_000, 1, $parameter));
    }
}
