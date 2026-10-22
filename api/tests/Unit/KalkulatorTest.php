<?php

namespace Tests\Unit;

use App\Domain\Billing\Kalkulator;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Seam 1 — kalkulator murni.
 *
 * Mewarisi PHPUnit\Framework\TestCase, **bukan** Tests\TestCase milik Laravel:
 * tidak perlu bootstrap aplikasi, tidak perlu basis data, tidak perlu HTTP.
 * Tes ini harus tetap hijau walau PostgreSQL mati. Kalau suatu saat tidak,
 * berarti ada dependensi tersembunyi yang bocor ke dalam kalkulator.
 */
class KalkulatorTest extends TestCase
{
    private Kalkulator $kalkulator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kalkulator = new Kalkulator;
    }

    /**
     * Kasus prorata yang disebut eksplisit di tiket T3.
     *
     * @return array<string, array{int, int, int, int}>
     */
    public static function kasusProrata(): array
    {
        return [
            // tarif, hariTinggal, hariDalamBulan, harapan
            'masuk tanggal 1 = tarif penuh, bukan tarif - 1' => [1_500_000, 31, 31, 1_500_000],
            'masuk 15 Oktober (17/31)' => [1_500_000, 17, 31, 822_581],
            'masuk 31 Oktober (1/31)' => [1_500_000, 1, 31, 48_387],
            'masuk 28 Februari (1/28)' => [1_500_000, 1, 28, 53_571],
            'sebulan penuh di bulan 28 hari' => [1_500_000, 28, 28, 1_500_000],
            'sebulan penuh di bulan 30 hari' => [1_000_000, 30, 30, 1_000_000],
            'setengah bulan Februari (14/28)' => [1_400_000, 14, 28, 700_000],
        ];
    }

    #[DataProvider('kasusProrata')]
    public function test_prorata(int $tarif, int $hariTinggal, int $hariDalamBulan, int $harapan): void
    {
        $this->assertSame(
            $harapan,
            $this->kalkulator->hitungSewaBulanPertama($tarif, $hariTinggal, $hariDalamBulan),
        );
    }

    public function test_sebulan_penuh_selalu_persis_tarif_untuk_banyak_nilai(): void
    {
        // Sifat yang harus berlaku untuk semua angka, bukan hanya contoh di tiket.
        // Kalau ada pembulatan yang salah tempat, cepat atau lambat satu nilai
        // akan meleset Rp 1.
        foreach ([1, 7, 999, 1_000_000, 1_500_000, 3_333_333, 99_999_999] as $tarif) {
            foreach ([28, 29, 30, 31] as $hari) {
                $this->assertSame(
                    $tarif,
                    $this->kalkulator->hitungSewaBulanPertama($tarif, $hari, $hari),
                    "tarif {$tarif} dengan {$hari} hari seharusnya persis sama",
                );
            }
        }
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function kasusPembulatanSetengah(): array
    {
        return [
            // Pembulatan half-up: tepat setengah dibulatkan ke atas.
            'tepat setengah dibulatkan ke atas' => [1_000_001, 2, 500_001],
            'sedikit di bawah setengah dibulatkan ke bawah' => [999_999, 2, 500_000],
            'sisa nol' => [1_000_000, 2, 500_000],
        ];
    }

    #[DataProvider('kasusPembulatanSetengah')]
    public function test_pembulatan_half_up(int $pembilang, int $penyebut, int $harapan): void
    {
        // Memakai refleksi supaya perilaku pembulatan diuji langsung, tanpa
        // harus mencari kombinasi tarif/hari yang kebetulan menghasilkan sisa itu.
        $metode = new \ReflectionMethod(Kalkulator::class, 'bagiBulatHalfUp');
        $metode->setAccessible(true);

        $this->assertSame($harapan, $metode->invoke($this->kalkulator, $pembilang, $penyebut));
    }

    public function test_hari_tinggal_menghitung_hari_masuk(): void
    {
        $this->assertSame(17, $this->kalkulator->hariTinggal(Carbon::parse('2026-10-15')));
        $this->assertSame(1, $this->kalkulator->hariTinggal(Carbon::parse('2026-10-31')));
        $this->assertSame(31, $this->kalkulator->hariTinggal(Carbon::parse('2026-10-01')));
        $this->assertSame(1, $this->kalkulator->hariTinggal(Carbon::parse('2026-02-28')));
        $this->assertSame(29, $this->kalkulator->hariTinggal(Carbon::parse('2028-02-01')));
    }

    public function test_hari_tinggal_di_luar_rentang_ditolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->kalkulator->hitungSewaBulanPertama(1_500_000, 0, 31);
    }

    public function test_hari_tinggal_melebihi_hari_dalam_bulan_ditolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->kalkulator->hitungSewaBulanPertama(1_500_000, 32, 31);
    }

    // --- Jatuh tempo -------------------------------------------------------

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function kasusJatuhTempo(): array
    {
        return [
            // tglMasuk, periode, harapan
            'tanggal 15 tetap 15' => ['2026-10-15', '2026-11-01', '2026-11-15'],
            '31 Januari di Februari dijepit ke 28' => ['2026-01-31', '2026-02-01', '2026-02-28'],
            '31 Januari di Februari kabisat dijepit ke 29' => ['2028-01-31', '2028-02-01', '2028-02-29'],
            '30 Januari di Februari dijepit ke 28' => ['2026-01-30', '2026-02-01', '2026-02-28'],
            '31 Maret di April dijepit ke 30' => ['2026-03-31', '2026-04-01', '2026-04-30'],
            '31 Januari di Januari tetap 31' => ['2026-01-31', '2026-01-01', '2026-01-31'],
            '1 tetap 1' => ['2026-10-01', '2026-11-01', '2026-11-01'],
        ];
    }

    #[DataProvider('kasusJatuhTempo')]
    public function test_jatuh_tempo_dijepit_ke_akhir_bulan(string $masuk, string $periode, string $harapan): void
    {
        $hasil = $this->kalkulator->tanggalJatuhTempo(
            Carbon::parse($masuk),
            Carbon::parse($periode),
        );

        $this->assertSame($harapan, $hasil->toDateString());
    }

    public function test_jatuh_tempo_tidak_pernah_menggelinding_ke_bulan_berikutnya(): void
    {
        // Sifat umum: untuk semua kombinasi tanggal 29-31 dan semua bulan,
        // hasilnya harus tetap di bulan periode.
        foreach ([29, 30, 31] as $hari) {
            for ($bulan = 1; $bulan <= 12; $bulan++) {
                $masuk = Carbon::create(2026, 1, $hari);
                $periode = Carbon::create(2026, $bulan, 1);

                $hasil = $this->kalkulator->tanggalJatuhTempo($masuk, $periode);

                $this->assertSame(
                    $bulan,
                    $hasil->month,
                    "masuk tanggal {$hari}, periode bulan {$bulan} tidak boleh bergeser bulan",
                );
                $this->assertLessThanOrEqual($hasil->daysInMonth, $hasil->day);
            }
        }
    }
}
