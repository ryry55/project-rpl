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
use Tests\Concerns\MenyiapkanKebijakan;
use Tests\TestCase;

/**
 * K-14 Level 1 — tanggal terbit otomatis.
 *
 * Arti yang dipilih pemilik adalah **A**: tanggal ini hanya boleh menunda
 * penerbitan, tidak pernah memajukannya. Yang diuji di sini karena itu tiga
 * hal: penundaan bekerja, siklus yang lebih lambat tidak ditarik mundur, dan
 * tanggal 29–31 ditolak (tidak ada di Februari).
 */
class TanggalTerbitTest extends TestCase
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

    private function buatKontrak(string $nomor, string $tglMasuk = '2026-01-01'): Kontrak
    {
        $kamar = Kamar::create(['nomor' => $nomor, 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create([
            'nama' => 'Penghuni '.$nomor,
            'email' => strtolower($nomor).'@kos.test',
        ]);

        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => 1_500_000,
            'berlaku_dari' => '2026-01-01',
        ]);

        return Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => $tglMasuk,
            'tarif_awal' => 1_500_000,
            'status' => 'aktif',
        ]);
    }

    private function terjadwal(string $tanggal): void
    {
        $this->artisan('tagihan:terbitkan', [
            'periode' => $tanggal,
            '--hari-ini' => $tanggal,
        ])->assertExitCode(0);
    }

    // --- Penundaan ---------------------------------------------------------

    public function test_tanpa_setelan_tagihan_terbit_pada_hari_siklus(): void
    {
        $this->buatKontrak('A1', '2026-01-01');

        $this->terjadwal('2026-01-01');

        $this->assertSame(1, Tagihan::count());
    }

    public function test_terjadwal_tidak_menerbitkan_sebelum_tanggal_terbit(): void
    {
        // Kontrol negatif: tanpa memakai tanggal terbit, penerbitan tanggal 3
        // langsung menagih siklus tanggal 1.
        $this->siapkanTanggalTerbit(5);
        $this->buatKontrak('A1', '2026-01-01');

        $this->terjadwal('2026-01-03');
        $this->assertSame(0, Tagihan::count());

        $this->terjadwal('2026-01-05');
        $this->assertSame(1, Tagihan::count());
    }

    public function test_terjadwal_menyusul_setelah_tanggal_terbit_terlewat(): void
    {
        $this->siapkanTanggalTerbit(5);
        $this->buatKontrak('A1', '2026-01-01');

        $this->terjadwal('2026-01-10');

        $this->assertSame(1, Tagihan::count());
    }

    public function test_tanggal_terbit_tidak_memajukan_siklus_anniversary(): void
    {
        $this->siapkanPolaTagihan();
        $this->siapkanTanggalTerbit(5);
        $this->buatKontrak('A1', '2026-01-15');

        // Tanggal terbit 5 lebih awal daripada siklus 15: tagihan tetap
        // menunggu siklusnya, bukan terbit tanggal 5.
        $this->terjadwal('2026-01-10');
        $this->assertSame(0, Tagihan::count());

        $this->terjadwal('2026-01-15');
        $this->assertSame(1, Tagihan::count());
    }

    public function test_tanggal_terbit_menunda_siklus_anniversary(): void
    {
        $this->siapkanPolaTagihan();
        $this->siapkanTanggalTerbit(20);
        $this->buatKontrak('A1', '2026-01-15');

        $this->terjadwal('2026-01-18');
        $this->assertSame(0, Tagihan::count());

        $this->terjadwal('2026-01-20');
        $this->assertSame(1, Tagihan::count());
    }

    // --- Penyimpanan kebijakan --------------------------------------------

    public function test_tanggal_terbit_tersimpan_sebagai_kebijakan_global(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => PilihanKebijakan::TIPE_TANGGAL_TERBIT,
                'nilai' => PilihanKebijakan::TERBIT_TANGGAL_TETAP,
                'tanggal' => 20,
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('kebijakan_kos', [
            'tipe' => 'tanggal_terbit',
            'nilai' => 'tanggal_tetap',
            'tanggal' => 20,
            'kamar_id' => null,
        ]);
    }

    public function test_tanggal_terbit_tidak_boleh_per_kamar(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);

        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => PilihanKebijakan::TIPE_TANGGAL_TERBIT,
                'nilai' => PilihanKebijakan::TERBIT_TANGGAL_TETAP,
                'tanggal' => 5,
                'kamar_id' => $kamar->id,
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('kamar_id');
    }

    public function test_tanggal_terbit_menolak_tanggal_29(): void
    {
        // 29 Februari tidak ada di tahun non-kabisat: tanggal terbit yang tidak
        // ada berarti tagihan yang tidak pernah terbit.
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => PilihanKebijakan::TIPE_TANGGAL_TERBIT,
                'nilai' => PilihanKebijakan::TERBIT_TANGGAL_TETAP,
                'tanggal' => 29,
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal');
    }

    public function test_tanggal_terbit_wajib_menyertakan_tanggal(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => PilihanKebijakan::TIPE_TANGGAL_TERBIT,
                'nilai' => PilihanKebijakan::TERBIT_TANGGAL_TETAP,
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal');
    }

    public function test_check_constraint_menolak_tanggal_terbit_29(): void
    {
        // Lapis kedua: validasi HTTP bisa dilewati seeder atau SQL langsung.
        $this->expectException(QueryException::class);

        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_TANGGAL_TERBIT,
            'nilai' => PilihanKebijakan::TERBIT_TANGGAL_TETAP,
            'tanggal' => 29,
            'berlaku_dari' => '2026-01-01',
        ]);
    }

    // --- Filter bulan ------------------------------------------------------

    public function test_filter_bulan_menyertakan_tagihan_anniversary(): void
    {
        $this->siapkanPolaTagihan();
        $this->buatKontrak('A1', '2026-01-15');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-01-01'])
            ->assertCreated();

        $this->assertDatabaseHas('tagihan', ['periode' => '2026-01-15']);

        // Filter satu bulan harus menemukan periode tanggal 15, bukan hanya
        // tanggal 1.
        $januari = $this->sebagaiPemilik()
            ->getJson('/api/tagihan?periode=2026-01-01')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $januari);
        $this->assertSame('2026-01-15', $januari[0]['periode']);

        $februari = $this->sebagaiPemilik()
            ->getJson('/api/tagihan?periode=2026-02-01')
            ->assertOk()
            ->json('data');

        $this->assertCount(0, $februari);
    }
}
