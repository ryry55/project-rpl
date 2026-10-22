<?php

namespace Tests\Unit;

use App\Domain\Billing\Kalkulator;
use App\Domain\Billing\UmurTunggakan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Seam 1 — kelompok umur tunggakan (R-04), diuji tanpa basis data.
 *
 * Enam nilai menutup semua batas: -1, 0, 30, 31, 60, 61. Tiap pasangan
 * (30, 31) dan (60, 61) adalah titik di mana satu hari salah hitung membuat
 * pemilik menagih orang di kelompok yang salah — dan kelompok yang salah
 * berarti tindakan yang salah.
 */
class UmurTunggakanTest extends TestCase
{
    private Kalkulator $kalkulator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kalkulator = new Kalkulator;
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function titikBatas(): array
    {
        return [
            // Nilai negatif: belum jatuh tempo.
            'hari -365' => [-365, UmurTunggakan::BELUM_JATUH_TEMPO],
            'hari -1 sehari sebelum jatuh tempo' => [-1, UmurTunggakan::BELUM_JATUH_TEMPO],

            // Batas bawah kelompok pertama.
            'hari 0 tepat jatuh tempo' => [0, UmurTunggakan::HARI_0_30],

            // Batas 30/31 — pasangan paling rawan salah.
            'hari 30 masih 0-30' => [30, UmurTunggakan::HARI_0_30],
            'hari 31 sudah 31-60' => [31, UmurTunggakan::HARI_31_60],

            // Batas 60/61.
            'hari 60 masih 31-60' => [60, UmurTunggakan::HARI_31_60],
            'hari 61 sudah lebih dari 60' => [61, UmurTunggakan::LEBIH_60],

            // Ujung jauh.
            'hari 365 tetap lebih dari 60' => [365, UmurTunggakan::LEBIH_60],
            'hari 10000 tetap lebih dari 60' => [10_000, UmurTunggakan::LEBIH_60],
        ];
    }

    #[DataProvider('titikBatas')]
    public function test_kelompok_pada_titik_batas(int $hariTerlambat, string $harapan): void
    {
        $this->assertSame(
            $harapan,
            $this->kalkulator->kelompokUmur($hariTerlambat),
            "hari terlambat {$hariTerlambat}",
        );
    }

    public function test_hari_negatif_bukan_kelompok_nol_sampai_tiga_puluh(): void
    {
        // Ini inti tiket: tagihan yang belum jatuh tempo tidak boleh dihitung
        // sebagai tunggakan. Kalau masuk 0-30, angka "jumlah penunggak" di
        // dashboard tidak bisa dipercaya.
        $this->assertNotSame(UmurTunggakan::HARI_0_30, $this->kalkulator->kelompokUmur(-1));
        $this->assertSame(UmurTunggakan::BELUM_JATUH_TEMPO, $this->kalkulator->kelompokUmur(-1));
    }

    public function test_setiap_hari_dari_negatif_sampai_seratus_masuk_tepat_satu_kelompok(): void
    {
        // Sifat: tidak ada nilai yang jatuh ke kelompok tak dikenal, dan tidak
        // ada celah antar-kelompok. Diuji menyapu seluruh rentang, bukan hanya
        // titik batas — kesalahan `<=` vs `<` hanya muncul di satu hari.
        $sah = UmurTunggakan::semua();

        for ($hari = -10; $hari <= 100; $hari++) {
            $kunci = $this->kalkulator->kelompokUmur($hari);

            $this->assertContains($kunci, $sah, "hari {$hari} menghasilkan kelompok tak dikenal");
        }
    }

    public function test_batas_kelompok_bersambung_tanpa_celah(): void
    {
        // Setiap hari harus lebih besar atau sama dengan kelompok hari
        // sebelumnya — tidak pernah mundur ke kelompok yang lebih ringan.
        $urutan = array_flip(UmurTunggakan::semua());
        $sebelumnya = -1;

        for ($hari = -5; $hari <= 90; $hari++) {
            $posisi = $urutan[$this->kalkulator->kelompokUmur($hari)];

            $this->assertGreaterThanOrEqual(
                $sebelumnya,
                $posisi,
                "kelompok mundur di hari {$hari}",
            );
            $sebelumnya = $posisi;
        }
    }

    public function test_perubahan_satu_hari_di_batas_memindahkan_kelompok(): void
    {
        // Pasangan 30/31 dan 60/61 harus benar-benar berbeda kelompok. Kalau
        // keduanya sama, berarti ada kelompok yang tidak pernah terpakai.
        $this->assertNotSame(
            $this->kalkulator->kelompokUmur(30),
            $this->kalkulator->kelompokUmur(31),
        );
        $this->assertNotSame(
            $this->kalkulator->kelompokUmur(60),
            $this->kalkulator->kelompokUmur(61),
        );
    }

    public function test_setiap_kunci_punya_label_dan_keterangan(): void
    {
        // Kelompok tanpa label akan tampil sebagai kunci mentah di layar.
        foreach (UmurTunggakan::semua() as $kunci) {
            $this->assertNotSame('', UmurTunggakan::label($kunci), "label {$kunci} kosong");
            $this->assertNotSame('', UmurTunggakan::keterangan($kunci), "keterangan {$kunci} kosong");
        }
    }
}
