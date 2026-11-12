<?php

namespace Tests\Feature;

use App\Domain\Kebijakan\PilihanKebijakan;
use App\Models\Kamar;
use App\Models\KebijakanKos;
use App\Models\Kontrak;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\TarifSewa;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MenyiapkanKebijakan;
use Tests\TestCase;

/**
 * K-14 — pola tagihan anniversary.
 *
 * Yang diuji di sini hanya hal yang tidak bisa diuji tanpa basis data: periode
 * yang benar-benar tersimpan, penjagaan "belum waktunya" pada jalur terjadwal,
 * dan perpindahan pola yang tidak boleh menagih mundur.
 */
class PolaTagihanTest extends TestCase
{
    use MenyiapkanKebijakan;
    use RefreshDatabase;

    private User $pemilik;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pemilik = User::create([
            'name' => 'Pemilik Kos',
            'email' => 'pemilik@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PEMILIK,
        ]);

        $this->siapkanKebijakanDefault();
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    private function buatKontrak(
        string $nomor,
        string $tglMasuk = '2026-01-15',
        ?int $tarif = 1_500_000,
    ): Kontrak {
        $kamar = Kamar::create(['nomor' => $nomor, 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create([
            'nama' => 'Penghuni '.$nomor,
            'email' => strtolower($nomor).'@kos.test',
        ]);

        if ($tarif !== null) {
            TarifSewa::create([
                'kamar_id' => $kamar->id,
                'nilai' => $tarif,
                'berlaku_dari' => '2026-01-01',
            ]);
        }

        return Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => $tglMasuk,
            'tarif_awal' => $tarif ?? 0,
            'status' => 'aktif',
        ]);
    }

    private function terbitkanMassal(string $periode): TestResponse
    {
        return $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => $periode]);
    }

    // --- Pola anniversary -------------------------------------------------

    public function test_tanpa_kebijakan_pola_berarti_tanggal_1(): void
    {
        $kontrak = $this->buatKontrak('A1');

        $this->terbitkanMassal('2026-01-01')->assertCreated();

        $this->assertDatabaseHas('tagihan', [
            'kontrak_id' => $kontrak->id,
            'periode' => '2026-01-01',
        ]);
    }

    public function test_anniversary_memakai_tanggal_masuk_sebagai_awal_siklus(): void
    {
        $this->siapkanPolaTagihan();
        $kontrak = $this->buatKontrak('A1', '2026-01-15');

        $this->terbitkanMassal('2026-01-01')->assertCreated();

        $tagihan = Tagihan::where('kontrak_id', $kontrak->id)->firstOrFail();
        $this->assertSame('2026-01-15', $tagihan->periode->toDateString());
        $this->assertSame('2026-01-15', $tagihan->jatuh_tempo->toDateString());
        // Bulan masuk tetap diprorata: 17/31 dari Rp 1.500.000.
        $this->assertSame(822_581, (int) $tagihan->pokok);
    }

    public function test_anniversary_bulan_berikutnya_tarif_penuh(): void
    {
        $this->siapkanPolaTagihan();
        $kontrak = $this->buatKontrak('A1', '2026-01-15');

        $this->terbitkanMassal('2026-01-01')->assertCreated();
        $this->terbitkanMassal('2026-02-01')->assertCreated();

        $februari = Tagihan::where('kontrak_id', $kontrak->id)
            ->whereDate('periode', '2026-02-15')
            ->firstOrFail();

        $this->assertSame(1_500_000, (int) $februari->pokok);
    }

    public function test_anniversary_tanggal_31_dijepit_ke_akhir_bulan(): void
    {
        $this->siapkanPolaTagihan();
        $kontrak = $this->buatKontrak('A1', '2026-01-31');

        $this->terbitkanMassal('2026-02-01')->assertCreated();

        $tagihan = Tagihan::where('kontrak_id', $kontrak->id)->firstOrFail();
        $this->assertSame('2026-02-28', $tagihan->periode->toDateString());
        $this->assertSame('2026-02-28', $tagihan->jatuh_tempo->toDateString());
        $this->assertSame(1_500_000, (int) $tagihan->pokok);
    }

    public function test_anniversary_tidak_menerbitkan_sebelum_hari_siklus(): void
    {
        // Kontrol negatif: tanpa penjagaan "belum waktunya", penerbitan harian
        // tanggal 10 akan menagih siklus tanggal 15.
        $this->siapkanPolaTagihan();
        $this->buatKontrak('A1', '2026-01-15');

        $this->artisan('tagihan:terbitkan', [
            'periode' => '2026-01-10',
            '--hari-ini' => '2026-01-10',
        ])->assertExitCode(0);

        $this->assertSame(0, Tagihan::count());

        $this->artisan('tagihan:terbitkan', [
            'periode' => '2026-01-15',
            '--hari-ini' => '2026-01-15',
        ])->assertExitCode(0);

        $this->assertSame(1, Tagihan::count());
    }

    public function test_anniversary_menyusul_setelah_hari_siklus_terlewat(): void
    {
        $this->siapkanPolaTagihan();
        $kontrak = $this->buatKontrak('A1', '2026-01-15');

        $this->artisan('tagihan:terbitkan', [
            'periode' => '2026-01-20',
            '--hari-ini' => '2026-01-20',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('tagihan', [
            'kontrak_id' => $kontrak->id,
            'periode' => '2026-01-15',
        ]);
    }

    public function test_anniversary_dua_kontrak_hanya_yang_siklusnya_tiba(): void
    {
        $this->siapkanPolaTagihan();
        $awal = $this->buatKontrak('A1', '2026-01-10');
        $akhir = $this->buatKontrak('A2', '2026-01-20');

        $this->artisan('tagihan:terbitkan', [
            'periode' => '2026-01-15',
            '--hari-ini' => '2026-01-15',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('tagihan', ['kontrak_id' => $awal->id, 'periode' => '2026-01-10']);
        $this->assertDatabaseMissing('tagihan', ['kontrak_id' => $akhir->id]);
    }

    public function test_anniversary_bulan_sebelum_masuk_dilewati(): void
    {
        $this->siapkanPolaTagihan();
        $this->buatKontrak('A1', '2026-02-15');

        $this->terbitkanMassal('2026-01-01')
            ->assertOk()
            ->assertJsonPath('data.terbit', 0)
            ->assertJsonPath('data.dilewati', 1);

        $this->assertSame(0, Tagihan::count());
    }

    public function test_anniversary_jatuh_tempo_tidak_mendahului_awal_siklus(): void
    {
        $this->siapkanPolaTagihan();
        // Tanggal tetap 5 lebih awal daripada siklus tanggal 15.
        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_JATUH_TEMPO,
            'nilai' => PilihanKebijakan::JATUH_TEMPO_TANGGAL_TETAP,
            'tanggal' => 5,
            'berlaku_dari' => '2026-01-02',
        ]);
        $kontrak = $this->buatKontrak('A1', '2026-01-15');

        $this->terbitkanMassal('2026-02-01')->assertCreated();

        $tagihan = Tagihan::where('kontrak_id', $kontrak->id)->firstOrFail();
        $this->assertSame('2026-02-15', $tagihan->jatuh_tempo->toDateString());
    }

    // --- Perpindahan pola -------------------------------------------------

    public function test_perpindahan_ke_anniversary_tidak_menagih_bulan_yang_sama(): void
    {
        $kontrak = $this->buatKontrak('A1', '2026-01-15');

        // Januari masih tanggal 1.
        $this->terbitkanMassal('2026-01-01')->assertCreated();
        $this->assertDatabaseHas('tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-01-01']);

        // Pemilik berpindah pola mulai Februari: baris bertanggal 10 Januari
        // tidak berlaku untuk periode Januari, sesuai semantik awal periode.
        $this->siapkanPolaTagihan(berlakuDari: '2026-01-10');

        // Januari tidak boleh menerbitkan tagihan kedua.
        $this->artisan('tagihan:terbitkan', [
            'periode' => '2026-01-20',
            '--hari-ini' => '2026-01-20',
        ])->assertExitCode(0);

        $this->assertSame(1, Tagihan::where('kontrak_id', $kontrak->id)->count());

        // Februari memakai anniversary.
        $this->terbitkanMassal('2026-02-01')->assertCreated();
        $this->assertDatabaseHas('tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-02-15']);
    }

    public function test_perpindahan_ke_tanggal_1_tidak_menagih_bulan_yang_sama(): void
    {
        $this->siapkanPolaTagihan();
        $kontrak = $this->buatKontrak('A1', '2026-01-15');

        $this->terbitkanMassal('2026-01-01')->assertCreated();
        $this->assertDatabaseHas('tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-01-15']);

        $this->siapkanPolaTagihan(PilihanKebijakan::POLA_TANGGAL_1, berlakuDari: '2026-01-20');

        $this->artisan('tagihan:terbitkan', [
            'periode' => '2026-01-25',
            '--hari-ini' => '2026-01-25',
        ])->assertExitCode(0);

        $this->assertSame(1, Tagihan::where('kontrak_id', $kontrak->id)->count());

        $this->terbitkanMassal('2026-02-01')->assertCreated();
        $this->assertDatabaseHas('tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-02-01']);
    }

    // --- Validasi kebijakan ----------------------------------------------

    public function test_pola_tagihan_tersimpan_sebagai_kebijakan_global(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => PilihanKebijakan::TIPE_POLA_TAGIHAN,
                'nilai' => PilihanKebijakan::POLA_ANNIVERSARY,
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('kebijakan_kos', [
            'tipe' => 'pola_tagihan',
            'nilai' => 'anniversary',
            'kamar_id' => null,
        ]);
    }

    public function test_pola_tagihan_tidak_boleh_per_kamar(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);

        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => PilihanKebijakan::TIPE_POLA_TAGIHAN,
                'nilai' => PilihanKebijakan::POLA_ANNIVERSARY,
                'kamar_id' => $kamar->id,
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('kamar_id');
    }

    public function test_pola_tagihan_menolak_nilai_tidak_dikenal(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => PilihanKebijakan::TIPE_POLA_TAGIHAN,
                'nilai' => 'mingguan',
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nilai');
    }

    public function test_check_constraint_menolak_nilai_pola_yang_salah(): void
    {
        // Lapis kedua: validasi HTTP bisa dilewati seeder atau SQL langsung.
        $this->expectException(QueryException::class);

        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_POLA_TAGIHAN,
            'nilai' => 'mingguan',
            'berlaku_dari' => '2026-01-01',
        ]);
    }
}
