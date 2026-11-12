<?php

namespace Tests\Unit;

use App\Domain\Billing\PolaTagihan;
use App\Domain\Kebijakan\PilihanKebijakan;
use App\Models\KebijakanKos;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Seam 1 — aturan tanggal pola tagihan, tanpa basis data.
 *
 * Mewarisi PHPUnit\Framework\TestCase, bukan Tests\TestCase: penjepitan akhir
 * bulan adalah aturan murni, dan tesnya harus tetap hijau walau PostgreSQL
 * mati.
 */
class PolaTagihanTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function kasusAwalSiklus(): array
    {
        return [
            'tanggal_1 selalu tanggal 1' => ['tanggal_1', '2026-01-15', '2026-02-10', '2026-02-01'],
            'anniversary mengikuti tanggal masuk' => ['anniversary', '2026-01-15', '2026-02-10', '2026-02-15'],
            'anniversary bulan masuk = tanggal masuk' => ['anniversary', '2026-01-15', '2026-01-01', '2026-01-15'],
            'tanggal 31 dijepit ke Februari' => ['anniversary', '2026-01-31', '2026-02-01', '2026-02-28'],
            'tanggal 31 dijepit ke April' => ['anniversary', '2026-01-31', '2026-04-01', '2026-04-30'],
            'tanggal 31 tetap 31 di Januari' => ['anniversary', '2026-01-31', '2026-01-01', '2026-01-31'],
            'tanggal 29 Februari kabisat' => ['anniversary', '2024-02-29', '2024-02-01', '2024-02-29'],
            'tanggal 29 Februari non-kabisat' => ['anniversary', '2024-02-29', '2025-02-01', '2025-02-28'],
        ];
    }

    #[DataProvider('kasusAwalSiklus')]
    public function test_awal_siklus(string $pola, string $masuk, string $acuan, string $harapan): void
    {
        $this->assertSame(
            $harapan,
            PolaTagihan::awalSiklus($pola, Carbon::parse($masuk), Carbon::parse($acuan))->toDateString(),
        );
    }

    public function test_siklus_pertama(): void
    {
        $masuk = Carbon::parse('2026-01-15');

        $this->assertSame(
            '2026-01-01',
            PolaTagihan::siklusPertama(PilihanKebijakan::POLA_TANGGAL_1, $masuk)->toDateString(),
        );
        $this->assertSame(
            '2026-01-15',
            PolaTagihan::siklusPertama(PilihanKebijakan::POLA_ANNIVERSARY, $masuk)->toDateString(),
        );
    }

    public function test_pola_dari_kebijakan(): void
    {
        $this->assertSame(PilihanKebijakan::POLA_TANGGAL_1, PolaTagihan::dari(null));
        $this->assertSame(
            PilihanKebijakan::POLA_TANGGAL_1,
            PolaTagihan::dari(new KebijakanKos(['nilai' => PilihanKebijakan::POLA_TANGGAL_1])),
        );
        $this->assertSame(
            PilihanKebijakan::POLA_ANNIVERSARY,
            PolaTagihan::dari(new KebijakanKos(['nilai' => PilihanKebijakan::POLA_ANNIVERSARY])),
        );
        // Nilai tak dikenal tidak boleh membuat penerbitan meledak; bawaan aman.
        $this->assertSame(
            PilihanKebijakan::POLA_TANGGAL_1,
            PolaTagihan::dari(new KebijakanKos(['nilai' => 'ngawur'])),
        );
    }
}
