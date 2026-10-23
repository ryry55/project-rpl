<?php

namespace Tests\Feature;

use App\Models\Kamar;
use App\Models\KomponenBiaya;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * T11 — komponen biaya per kamar.
 *
 * Sebelum T11 `komponen_biaya` tidak punya `kamar_id`, sehingga biaya tambahan
 * bersifat global: menagih "air kamar 3" akan ikut menagih semua kamar.
 *
 * Yang dibuktikan di sini:
 *   1. Komponen global berlaku untuk semua kamar.
 *   2. Komponen khusus kamar hanya muncul di kamar itu.
 *   3. Komponen khusus kamar MENIMPA komponen global dengan nama sama.
 *   4. Unique index `NULLS NOT DISTINCT` tetap menolak duplikat baris global.
 *   5. R-06 tetap utuh: tagihan yang sudah terbit tidak berubah.
 */
class KomponenPerKamarTest extends TestCase
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

    public function test_komponen_global_berlaku_untuk_semua_kamar(): void
    {
        $kamarA = Kamar::create(['nomor' => 'A1']);
        $kamarB = Kamar::create(['nomor' => 'B1']);

        $this->sebagaiPemilik()->postJson('/api/komponen-biaya', [
            'nama' => 'Kebersihan',
            'nilai' => 50000,
            'berlaku_dari' => '2026-10-01',
        ])->assertCreated()->assertJsonPath('data.kamar_id', null);

        foreach ([$kamarA, $kamarB] as $kamar) {
            $this->sebagaiPemilik()
                ->getJson('/api/komponen-biaya?berlaku_pada=2026-10-15&kamar_id='.$kamar->id)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.nama', 'Kebersihan')
                ->assertJsonPath('data.0.nilai', 50000);
        }
    }

    public function test_komponen_khusus_kamar_hanya_muncul_di_kamar_itu(): void
    {
        $kamarA = Kamar::create(['nomor' => 'A1']);
        $kamarB = Kamar::create(['nomor' => 'B1']);

        $this->sebagaiPemilik()->postJson('/api/komponen-biaya', [
            'kamar_id' => $kamarA->id,
            'nama' => 'Parkir Motor',
            'nilai' => 30000,
            'berlaku_dari' => '2026-10-01',
        ])->assertCreated();

        // Kamar A melihatnya.
        $this->sebagaiPemilik()
            ->getJson('/api/komponen-biaya?berlaku_pada=2026-10-15&kamar_id='.$kamarA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'Parkir Motor');

        // Kamar B tidak.
        $this->sebagaiPemilik()
            ->getJson('/api/komponen-biaya?berlaku_pada=2026-10-15&kamar_id='.$kamarB->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_komponen_khusus_kamar_menimpa_komponen_global_dengan_nama_sama(): void
    {
        $kamarA = Kamar::create(['nomor' => 'A1']);
        $kamarB = Kamar::create(['nomor' => 'B1']);

        // Global: air Rp 50.000 untuk semua kamar.
        $this->sebagaiPemilik()->postJson('/api/komponen-biaya', [
            'nama' => 'Air',
            'nilai' => 50000,
            'berlaku_dari' => '2026-10-01',
        ])->assertCreated();

        // Khusus A: air Rp 75.000, karena kamar mandi dalam.
        $this->sebagaiPemilik()->postJson('/api/komponen-biaya', [
            'kamar_id' => $kamarA->id,
            'nama' => 'Air',
            'nilai' => 75000,
            'berlaku_dari' => '2026-10-01',
        ])->assertCreated();

        // A kena 75.000, bukan dua baris.
        $this->sebagaiPemilik()
            ->getJson('/api/komponen-biaya?berlaku_pada=2026-10-15&kamar_id='.$kamarA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nilai', 75000);

        // B tetap 50.000.
        $this->sebagaiPemilik()
            ->getJson('/api/komponen-biaya?berlaku_pada=2026-10-15&kamar_id='.$kamarB->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nilai', 50000);
    }

    public function test_dua_baris_global_dengan_nama_dan_tanggal_sama_ditolak(): void
    {
        $this->sebagaiPemilik()->postJson('/api/komponen-biaya', [
            'nama' => 'Kebersihan',
            'nilai' => 50000,
            'berlaku_dari' => '2026-10-01',
        ])->assertCreated();

        // NULLS NOT DISTINCT yang membuat ini ditolak. Tanpa itu, PostgreSQL
        // menganggap dua NULL berbeda dan duplikat lolos.
        $this->sebagaiPemilik()->postJson('/api/komponen-biaya', [
            'nama' => 'Kebersihan',
            'nilai' => 60000,
            'berlaku_dari' => '2026-10-01',
        ])->assertStatus(422);
    }

    public function test_dua_kamar_berbeda_boleh_punya_komponen_nama_dan_tanggal_sama(): void
    {
        $kamarA = Kamar::create(['nomor' => 'A1']);
        $kamarB = Kamar::create(['nomor' => 'B1']);

        foreach ([$kamarA, $kamarB] as $kamar) {
            $this->sebagaiPemilik()->postJson('/api/komponen-biaya', [
                'kamar_id' => $kamar->id,
                'nama' => 'AC',
                'nilai' => 100000,
                'berlaku_dari' => '2026-10-01',
            ])->assertCreated();
        }

        $this->assertSame(2, KomponenBiaya::where('nama', 'AC')->count());
    }

    public function test_tagihan_sudah_terbit_tidak_berubah_saat_komponen_baru_ditambah(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);

        $this->sebagaiPemilik()->postJson('/api/tarif', [
            'kamar_id' => $kamar->id,
            'nilai' => 1500000,
            'berlaku_dari' => '2026-10-01',
        ])->assertCreated();

        $this->sebagaiPemilik()->postJson('/api/komponen-biaya', [
            'nama' => 'Kebersihan',
            'nilai' => 50000,
            'berlaku_dari' => '2026-10-01',
        ])->assertCreated();

        // Sejak T17 denda juga wajib: `parameter_denda` sudah dihapus, jadi
        // kebijakan inilah satu-satunya sumber angka denda. Dinyatakan lewat
        // HTTP supaya tes ini sekaligus membuktikan endpoint-nya bisa dipakai
        // sebagai jalur penyiapan yang sebenarnya.
        foreach ([
            ['satuan_sewa', 'bulanan'],
            ['jatuh_tempo', 'tanggal_masuk'],
            ['denda', 'persen_harian'],
        ] as [$tipe, $nilai]) {
            $this->sebagaiPemilik()->postJson('/api/kebijakan', [
                'tipe' => $tipe,
                'nilai' => $nilai,
                'berlaku_dari' => '2026-10-01',
                // Hanya denda persen yang membawa angka; kuncinya diabaikan
                // untuk tipe lain karena FormRequest melarangnya.
                ...($tipe === 'denda'
                    ? ['persen' => 1, 'plafon_persen' => 30, 'masa_tenggang' => 7]
                    : []),
            ])->assertCreated();
        }

        $penghuni = $this->sebagaiPemilik()->postJson('/api/penghuni', [
            'nama' => 'Budi',
            'email' => 'budi@kos.test',
        ])->assertCreated()->json('data');

        $kontrak = $this->sebagaiPemilik()->postJson('/api/kontrak', [
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni['id'],
            'tgl_masuk' => '2026-10-01',
        ])->assertCreated()->json('data');

        $tagihan = $this->sebagaiPemilik()->postJson('/api/tagihan', [
            'kontrak_id' => $kontrak['id'],
            'periode' => '2026-10-01',
        ])->assertCreated()->json('data');

        $pokokAwal = $tagihan['pokok'];
        $this->assertSame(1550000, $pokokAwal);

        // Komponen baru setelah tagihan terbit.
        $this->sebagaiPemilik()->postJson('/api/komponen-biaya', [
            'kamar_id' => $kamar->id,
            'nama' => 'AC',
            'nilai' => 100000,
            'berlaku_dari' => '2026-10-01',
        ])->assertCreated();

        // R-06: tagihan lama tidak boleh berubah.
        $this->sebagaiPemilik()
            ->getJson('/api/tagihan/'.$tagihan['id'])
            ->assertOk()
            ->assertJsonPath('data.pokok', $pokokAwal);
    }

    public function test_trigger_append_only_masih_menolak_perubahan_komponen(): void
    {
        $komponen = KomponenBiaya::create([
            'nama' => 'Kebersihan',
            'nilai' => 50000,
            'berlaku_dari' => '2026-10-01',
        ]);

        $this->expectException(QueryException::class);

        DB::table('komponen_biaya')
            ->where('id', $komponen->id)
            ->update(['nilai' => 99000]);
    }

    public function test_penghuni_tidak_boleh_membuat_komponen(): void
    {
        $penghuni = User::create([
            'name' => 'Penghuni',
            'email' => 'penghuni@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
        ]);

        $this->actingAs($penghuni, 'sanctum')->postJson('/api/komponen-biaya', [
            'nama' => 'Kebersihan',
            'nilai' => 50000,
            'berlaku_dari' => '2026-10-01',
        ])->assertForbidden();
    }
}
