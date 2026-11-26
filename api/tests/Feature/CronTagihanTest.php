<?php

namespace Tests\Feature;

use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\TarifSewa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MenyiapkanKebijakan;
use Tests\TestCase;

/**
 * Endpoint cron tagihan (lingkungan tanpa penjadwal, mis. Vercel).
 *
 * Yang diuji bukan "endpoint menjawab 200", melainkan tiga hal yang membuatnya
 * layak dipasang di internet:
 *
 *   1. tanpa CRON_SECRET ia MATI (503), bukan berjalan tanpa penjaga;
 *   2. token yang salah ditolak tanpa membocorkan apakah rahasianya hampir
 *      benar;
 *   3. dengan token benar ia benar-benar menerbitkan tagihan, dan pemanggilan
 *      kedua tidak menggandakannya.
 *
 * Waktu dibekukan dengan `travelTo`: tanpa itu, tes yang menerbitkan tagihan
 * "bulan ini" akan berubah perilakunya saat bulan berganti.
 */
class CronTagihanTest extends TestCase
{
    use MenyiapkanKebijakan;
    use RefreshDatabase;

    private const RAHASIA = 'rahasia-uji-cron';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.cron_secret' => self::RAHASIA]);
        $this->siapkanKebijakanDefault();
    }

    private function kontrakAktif(): Kontrak
    {
        $kamar = Kamar::create(['nomor' => 'A1', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);

        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => 1_000_000,
            'berlaku_dari' => '2026-01-01',
        ]);

        return Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-01-01',
            'tarif_awal' => 1_000_000,
            'status' => 'aktif',
        ]);
    }

    public function test_tanpa_cron_secret_endpoint_mati(): void
    {
        config(['app.cron_secret' => null]);

        $this->getJson('/api/cron/tagihan', ['Authorization' => 'Bearer '.self::RAHASIA])
            ->assertStatus(503);

        $this->assertSame(0, Tagihan::count());
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->kontrakAktif();

        $this->getJson('/api/cron/tagihan')->assertStatus(401);
        $this->assertSame(0, Tagihan::count());
    }

    public function test_token_salah_ditolak(): void
    {
        $this->kontrakAktif();

        $this->getJson('/api/cron/tagihan', ['Authorization' => 'Bearer salah'])
            ->assertStatus(401);

        $this->assertSame(0, Tagihan::count());
    }

    public function test_token_benar_menerbitkan_tagihan_dan_idempoten(): void
    {
        $this->kontrakAktif();
        $this->travelTo(Carbon::parse('2026-03-10 00:10:00', 'Asia/Jakarta'));

        $this->getJson('/api/cron/tagihan', ['Authorization' => 'Bearer '.self::RAHASIA])
            ->assertOk()
            ->assertJsonPath('data.dijalankan', true);

        $this->assertSame(1, Tagihan::count());

        // Panggilan kedua pada hari yang sama: tagihan bulan itu sudah ada,
        // jadi tidak boleh bertambah.
        $this->getJson('/api/cron/tagihan', ['Authorization' => 'Bearer '.self::RAHASIA])
            ->assertOk();

        $this->assertSame(1, Tagihan::count());
    }
}
