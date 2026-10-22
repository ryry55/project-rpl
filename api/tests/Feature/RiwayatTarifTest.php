<?php

namespace Tests\Feature;

use App\Models\Kamar;
use App\Models\KomponenBiaya;
use App\Models\ParameterDenda;
use App\Models\TarifSewa;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * R-06 lapis pertama — riwayat tarif tidak boleh berubah.
 *
 * Dua hal yang dibuktikan di sini:
 *   1. Lewat API: mengubah tarif menambah baris, bukan menimpa.
 *   2. Lewat SQL langsung: UPDATE dan DELETE ditolak basis data, bukan hanya
 *      oleh ketiadaan endpoint.
 */
class RiwayatTarifTest extends TestCase
{
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
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    public function test_mengubah_tarif_menambah_baris_dan_baris_lama_tetap(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);

        $this->sebagaiPemilik()->postJson('/api/tarif', [
            'kamar_id' => $kamar->id,
            'nilai' => 1500000,
            'berlaku_dari' => '2026-10-01',
        ])->assertCreated();

        $this->sebagaiPemilik()->postJson('/api/tarif', [
            'kamar_id' => $kamar->id,
            'nilai' => 1750000,
            'berlaku_dari' => '2026-11-01',
        ])->assertCreated();

        // Dua baris, bukan satu baris yang ditimpa.
        $this->assertSame(2, TarifSewa::where('kamar_id', $kamar->id)->count());

        // Baris lama masih bernilai 1.500.000 — tidak tersentuh.
        $this->assertDatabaseHas('tarif_sewa', [
            'kamar_id' => $kamar->id,
            'berlaku_dari' => '2026-10-01',
            'nilai' => 1500000,
        ]);
        $this->assertDatabaseHas('tarif_sewa', [
            'kamar_id' => $kamar->id,
            'berlaku_dari' => '2026-11-01',
            'nilai' => 1750000,
        ]);
    }

    public function test_tarif_efektif_adalah_baris_terakhir_yang_berlaku_dari_kurang_dari_sama_periode(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1500000, 'berlaku_dari' => '2026-10-01']);
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1750000, 'berlaku_dari' => '2026-11-01']);

        // Oktober memakai tarif Oktober.
        $this->sebagaiPemilik()
            ->getJson("/api/kamar/{$kamar->id}/tarif-berlaku?periode=2026-10-15")
            ->assertOk()
            ->assertJsonPath('data.nilai', 1500000)
            ->assertJsonPath('data.berlaku_dari', '2026-10-01');

        // November memakai tarif baru.
        $this->sebagaiPemilik()
            ->getJson("/api/kamar/{$kamar->id}/tarif-berlaku?periode=2026-11-15")
            ->assertOk()
            ->assertJsonPath('data.nilai', 1750000);

        // Desember tetap memakai tarif November, karena tidak ada baris baru.
        $this->sebagaiPemilik()
            ->getJson("/api/kamar/{$kamar->id}/tarif-berlaku?periode=2026-12-01")
            ->assertOk()
            ->assertJsonPath('data.nilai', 1750000);
    }

    public function test_periode_sebelum_tarif_pertama_tidak_punya_tarif(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1500000, 'berlaku_dari' => '2026-10-01']);

        $this->sebagaiPemilik()
            ->getJson("/api/kamar/{$kamar->id}/tarif-berlaku?periode=2026-09-01")
            ->assertStatus(404)
            ->assertJsonStructure(['message', 'errors' => ['periode']]);
    }

    public function test_tarif_ganda_pada_tanggal_sama_ditolak_422(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1500000, 'berlaku_dari' => '2026-10-01']);

        $this->sebagaiPemilik()
            ->postJson('/api/tarif', [
                'kamar_id' => $kamar->id,
                'nilai' => 1600000,
                'berlaku_dari' => '2026-10-01',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['berlaku_dari']]);
    }

    public function test_tarif_harus_angka_bulat_bukan_desimal(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);

        $this->sebagaiPemilik()
            ->postJson('/api/tarif', [
                'kamar_id' => $kamar->id,
                'nilai' => 1500000.5,
                'berlaku_dari' => '2026-10-01',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['nilai']]);
    }

    public function test_tidak_ada_endpoint_untuk_mengubah_atau_menghapus_tarif(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $tarif = TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1500000, 'berlaku_dari' => '2026-10-01']);

        // 404: rute /api/tarif/{id} memang tidak pernah didaftarkan. Bukan 405,
        // karena Laravel hanya membalas 405 bila path-nya ada untuk method lain.
        $this->sebagaiPemilik()
            ->putJson("/api/tarif/{$tarif->id}", ['nilai' => 1])
            ->assertStatus(404);

        $this->sebagaiPemilik()
            ->deleteJson("/api/tarif/{$tarif->id}")
            ->assertStatus(404);

        // Dan yang lebih penting: barisnya tetap utuh.
        $this->assertDatabaseHas('tarif_sewa', [
            'id' => $tarif->id,
            'nilai' => 1500000,
        ]);
    }

    // --- Penegakan di basis data ------------------------------------------

    public function test_update_langsung_ke_tarif_sewa_ditolak_database(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1500000, 'berlaku_dari' => '2026-10-01']);

        $this->expectException(QueryException::class);

        DB::table('tarif_sewa')->update(['nilai' => 1]);
    }

    public function test_delete_langsung_ke_tarif_sewa_ditolak_database(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1500000, 'berlaku_dari' => '2026-10-01']);

        $this->expectException(QueryException::class);

        DB::table('tarif_sewa')->delete();
    }

    public function test_update_langsung_ke_parameter_denda_ditolak_database(): void
    {
        ParameterDenda::create([
            'tarif_persen' => 1,
            'plafon_persen' => 30,
            'masa_tenggang' => 7,
            'berlaku_dari' => '2026-10-01',
        ]);

        $this->expectException(QueryException::class);

        DB::table('parameter_denda')->update(['tarif_persen' => 5]);
    }

    public function test_update_langsung_ke_komponen_biaya_ditolak_database(): void
    {
        KomponenBiaya::create([
            'nama' => 'Kebersihan',
            'nilai' => 50000,
            'berlaku_dari' => '2026-10-01',
        ]);

        $this->expectException(QueryException::class);

        DB::table('komponen_biaya')->update(['nilai' => 1]);
    }

    // --- Komponen biaya ---------------------------------------------------

    public function test_komponen_biaya_append_only_lewat_api(): void
    {
        $this->sebagaiPemilik()->postJson('/api/komponen-biaya', [
            'nama' => 'Kebersihan',
            'nilai' => 50000,
            'berlaku_dari' => '2026-10-01',
        ])->assertCreated();

        $this->sebagaiPemilik()->postJson('/api/komponen-biaya', [
            'nama' => 'Kebersihan',
            'nilai' => 60000,
            'berlaku_dari' => '2026-11-01',
        ])->assertCreated();

        $this->assertSame(2, KomponenBiaya::where('nama', 'Kebersihan')->count());
    }

    public function test_komponen_yang_berlaku_pada_periode_memakai_baris_terakhir(): void
    {
        KomponenBiaya::create(['nama' => 'Kebersihan', 'nilai' => 50000, 'berlaku_dari' => '2026-10-01']);
        KomponenBiaya::create(['nama' => 'Kebersihan', 'nilai' => 60000, 'berlaku_dari' => '2026-11-01']);
        KomponenBiaya::create(['nama' => 'Parkir', 'nilai' => 30000, 'berlaku_dari' => '2026-10-01']);

        $respons = $this->sebagaiPemilik()
            ->getJson('/api/komponen-biaya?berlaku_pada=2026-11-15')
            ->assertOk();

        $data = collect($respons->json('data'))->keyBy('nama');

        $this->assertSame(60000, $data['Kebersihan']['nilai']);
        $this->assertSame(30000, $data['Parkir']['nilai']);
        $this->assertCount(2, $data);
    }

    public function test_komponen_dihentikan_dengan_baris_bernilai_nol(): void
    {
        KomponenBiaya::create(['nama' => 'Laundry', 'nilai' => 100000, 'berlaku_dari' => '2026-10-01']);
        KomponenBiaya::create(['nama' => 'Laundry', 'nilai' => 0, 'berlaku_dari' => '2026-12-01']);

        $respons = $this->sebagaiPemilik()
            ->getJson('/api/komponen-biaya?berlaku_pada=2026-12-15')
            ->assertOk();

        $data = collect($respons->json('data'))->firstWhere('nama', 'Laundry');

        $this->assertSame(0, $data['nilai']);
        $this->assertTrue($data['dihentikan']);
    }

    // --- Parameter denda --------------------------------------------------

    public function test_parameter_denda_default_menyimpan_tiga_nilai(): void
    {
        $this->sebagaiPemilik()->postJson('/api/parameter-denda', [
            'tarif_persen' => 1,
            'plafon_persen' => 30,
            'masa_tenggang' => 7,
            'berlaku_dari' => '2026-10-01',
        ])->assertCreated()
            ->assertJsonPath('data.tarif_persen_teks', '1.00')
            ->assertJsonPath('data.plafon_persen_teks', '30.00')
            ->assertJsonPath('data.masa_tenggang', 7);
    }

    public function test_parameter_denda_yang_berlaku_dipilih_periode_terakhir(): void
    {
        ParameterDenda::create(['tarif_persen' => 1, 'plafon_persen' => 30, 'masa_tenggang' => 7, 'berlaku_dari' => '2026-10-01']);
        ParameterDenda::create(['tarif_persen' => 2, 'plafon_persen' => 25, 'masa_tenggang' => 5, 'berlaku_dari' => '2027-01-01']);

        $this->sebagaiPemilik()
            ->getJson('/api/parameter-denda/berlaku?berlaku_pada=2026-11-01')
            ->assertOk()
            ->assertJsonPath('data.tarif_persen_teks', '1.00');

        $this->sebagaiPemilik()
            ->getJson('/api/parameter-denda/berlaku?berlaku_pada=2027-02-01')
            ->assertOk()
            ->assertJsonPath('data.tarif_persen_teks', '2.00')
            ->assertJsonPath('data.masa_tenggang', 5);
    }

    public function test_tarif_tersimpan_sebagai_numeric_bukan_float(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1500000, 'berlaku_dari' => '2026-10-01']);

        $tipe = DB::selectOne(
            "SELECT data_type, numeric_precision, numeric_scale
             FROM information_schema.columns
             WHERE table_name = 'tarif_sewa' AND column_name = 'nilai'"
        );

        $this->assertSame('numeric', $tipe->data_type);
        $this->assertSame(14, (int) $tipe->numeric_precision);
        $this->assertSame(0, (int) $tipe->numeric_scale);
    }
}
