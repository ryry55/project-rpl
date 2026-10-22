<?php

namespace Tests\Feature;

use App\Models\Kamar;
use App\Models\KomponenBiaya;
use App\Models\Kontrak;
use App\Models\ParameterDenda;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\TarifSewa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * T3 — penerbitan tagihan, R-01 dan R-06 lapis 2.
 *
 * Kalkulator murni sudah diuji di tests/Unit/KalkulatorTest.php tanpa basis data.
 * Berkas ini menguji hal yang tidak bisa diuji di sana: apakah angka yang benar
 * benar-benar tersimpan, apakah snapshot membeku, dan apakah periode yang tidak
 * masuk akal ditolak.
 */
class TagihanTest extends TestCase
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

    /**
     * Menyiapkan kontrak siap tagih: kamar, penghuni, tarif, parameter denda.
     */
    private function siapkanKontrak(string $tglMasuk, int $tarif = 1_500_000, array $komponen = []): Kontrak
    {
        $kamar = Kamar::create(['nomor' => 'A1', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'email' => 'budi@kos.test']);

        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => $tarif,
            'berlaku_dari' => '2026-01-01',
        ]);

        if (ParameterDenda::count() === 0) {
            ParameterDenda::create([
                'tarif_persen' => 1.00,
                'plafon_persen' => 30.00,
                'masa_tenggang' => 7,
                'berlaku_dari' => '2026-01-01',
            ]);
        }

        foreach ($komponen as $nama => $nilai) {
            KomponenBiaya::create([
                'nama' => $nama,
                'nilai' => $nilai,
                'berlaku_dari' => '2026-01-01',
            ]);
        }

        return Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => $tglMasuk,
            'tarif_awal' => $tarif,
            'deposit' => 0,
        ]);
    }

    // --- R-01 prorata ------------------------------------------------------

    public function test_masuk_tanggal_1_mendapat_tarif_penuh_bukan_kurang_serupiah(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01');

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', [
                'kontrak_id' => $kontrak->id,
                'periode' => '2026-10-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_500_000);

        // Asersi eksplisit: bukan 1.499.999. Ini titik yang paling sering salah
        // kalau pembulatan dilakukan sebelum pembagian.
        $this->assertSame(1_500_000, $respons->json('data.pokok'));
        $this->assertSame(1_500_000, (int) Tagihan::first()->pokok);
    }

    public function test_masuk_15_oktober_memakai_prorata_17_dari_31(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', [
                'kontrak_id' => $kontrak->id,
                'periode' => '2026-10-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 822_581)
            ->assertJsonPath('data.rincian.0.nama', 'Sewa Oktober 2026 (prorata 17/31 hari)')
            ->assertJsonPath('data.rincian.0.nilai', 822_581);
    }

    public function test_masuk_31_oktober_memakai_prorata_1_dari_31(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-31');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', [
                'kontrak_id' => $kontrak->id,
                'periode' => '2026-10-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 48_387);
    }

    public function test_masuk_28_februari_memakai_prorata_1_dari_28(): void
    {
        $kontrak = $this->siapkanKontrak('2026-02-28');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', [
                'kontrak_id' => $kontrak->id,
                'periode' => '2026-02-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 53_571);
    }

    public function test_bulan_berikutnya_selalu_tarif_penuh_tanpa_prorata(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');

        // Oktober: prorata.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 822_581);

        // November: penuh, walau penghuni masuk tanggal 15.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_500_000)
            ->assertJsonPath('data.rincian.0.nama', 'Sewa November 2026');
    }

    public function test_tidak_ada_prorata_saat_penghuni_keluar_di_tengah_bulan(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');

        // Keluar 10 November: November tetap ditagih penuh, karena sisa bulan
        // tetap disewa sampai penghuni benar-benar keluar.
        $kontrak->update(['tgl_keluar' => '2026-11-10']);

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_500_000);
    }

    public function test_masuk_tanggal_1_tidak_disebut_prorata(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.rincian.0.nama', 'Sewa Oktober 2026')
            ->assertJsonPath('data.pokok', 1_500_000);
    }

    // --- Rincian dan pokok -------------------------------------------------

    public function test_komponen_biaya_muncul_sebagai_baris_tersendiri(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01', 1_500_000, [
            'Kebersihan' => 50_000,
            'Parkir' => 30_000,
        ]);

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', [
                'kontrak_id' => $kontrak->id,
                'periode' => '2026-10-01',
            ])
            ->assertCreated();

        $rincian = collect($respons->json('data.rincian'));

        $this->assertCount(3, $rincian);
        // Masuk tanggal 1 tidak disebut "prorata 31/31" — hasilnya tarif penuh,
        // dan label prorata hanya membingungkan pembaca tagihan.
        $this->assertSame('Sewa Oktober 2026', $rincian[0]['nama']);
        $this->assertSame(1_500_000, $rincian[0]['nilai']);
        $this->assertSame('Kebersihan', $rincian[1]['nama']);
        $this->assertSame(50_000, $rincian[1]['nilai']);
        $this->assertSame('Parkir', $rincian[2]['nama']);
        $this->assertSame(30_000, $rincian[2]['nilai']);
    }

    public function test_pokok_sama_dengan_jumlah_seluruh_rincian(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15', 1_500_000, [
            'Kebersihan' => 50_000,
            'Parkir' => 30_000,
        ]);

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated();

        $jumlahRincian = array_sum(array_column($respons->json('data.rincian'), 'nilai'));

        $this->assertSame($respons->json('data.pokok'), $jumlahRincian);
        // 822.581 + 50.000 + 30.000
        $this->assertSame(902_581, $jumlahRincian);
    }

    public function test_komponen_bernilai_nol_tidak_muncul_sebagai_baris(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01', 1_500_000, [
            'Laundry' => 0,
        ]);

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated();

        $nama = array_column($respons->json('data.rincian'), 'nama');

        $this->assertNotContains('Laundry', $nama);
        $this->assertSame(1_500_000, $respons->json('data.pokok'));
    }

    public function test_total_sama_dengan_pokok_karena_denda_belum_ada(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.denda', 0)
            ->assertJsonPath('data.total', 1_500_000);
    }

    // --- Jatuh tempo -------------------------------------------------------

    public function test_jatuh_tempo_mengikuti_tanggal_masuk(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.jatuh_tempo', '2026-11-15');
    }

    public function test_jatuh_tempo_dijepit_ke_akhir_bulan_pendek(): void
    {
        $kontrak = $this->siapkanKontrak('2026-01-31');

        // Januari: 31 ada, tidak dijepit.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-01-01'])
            ->assertCreated()
            ->assertJsonPath('data.jatuh_tempo', '2026-01-31');

        // Februari: 31 tidak ada, dijepit ke 28.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-02-01'])
            ->assertCreated()
            ->assertJsonPath('data.jatuh_tempo', '2026-02-28');
    }

    public function test_periode_disimpan_sebagai_tanggal_1(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');

        // Dikirim tanggal 15, disimpan tanggal 1.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-15'])
            ->assertCreated()
            ->assertJsonPath('data.periode', '2026-10-01');

        $this->assertDatabaseHas('tagihan', ['periode' => '2026-10-01']);
    }

    // --- R-06 lapis 2: snapshot -------------------------------------------

    public function test_tagihan_menyimpan_snapshot_tarif_dan_komponen(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01', 1_500_000, ['Kebersihan' => 50_000]);

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated();

        $snapshot = $respons->json('data.tarif_terpakai');

        $this->assertSame(1_500_000, $snapshot['tarif_sewa']['nilai']);
        $this->assertSame('2026-01-01', $snapshot['tarif_sewa']['berlaku_dari']);
        $this->assertSame('Kebersihan', $snapshot['komponen_biaya'][0]['nama']);
        $this->assertSame(50_000, $snapshot['komponen_biaya'][0]['nilai']);
        $this->assertSame('1.00', $snapshot['parameter_denda']['tarif_persen']);
        $this->assertSame(30.00, (float) $snapshot['parameter_denda']['plafon_persen']);
        $this->assertTrue($snapshot['prorata']);
    }

    public function test_menaikkan_tarif_tidak_mengubah_tagihan_yang_sudah_terbit(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01', 1_500_000);

        $tagihan = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_500_000)
            ->json('data.id');

        // Tarif naik untuk periode berikutnya. Ini penambahan baris, bukan UPDATE.
        TarifSewa::create([
            'kamar_id' => $kontrak->kamar_id,
            'nilai' => 2_000_000,
            'berlaku_dari' => '2026-11-01',
        ]);

        // Tagihan Oktober tidak berubah sedikit pun.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan}")
            ->assertOk()
            ->assertJsonPath('data.pokok', 1_500_000)
            ->assertJsonPath('data.tarif_terpakai.tarif_sewa.nilai', 1_500_000);
    }

    public function test_menaikkan_tarif_berlaku_untuk_tagihan_berikutnya(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01', 1_500_000);

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_500_000);

        TarifSewa::create([
            'kamar_id' => $kontrak->kamar_id,
            'nilai' => 2_000_000,
            'berlaku_dari' => '2026-11-01',
        ]);

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 2_000_000)
            ->assertJsonPath('data.tarif_terpakai.tarif_sewa.berlaku_dari', '2026-11-01');
    }

    public function test_parameter_denda_dibekukan_di_tagihan(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01');

        $tagihan = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.tarif_terpakai.parameter_denda.masa_tenggang', 7)
            ->json('data.id');

        // Parameter diubah untuk periode berikutnya.
        ParameterDenda::create([
            'tarif_persen' => 2.00,
            'plafon_persen' => 25.00,
            'masa_tenggang' => 5,
            'berlaku_dari' => '2026-11-01',
        ]);

        // Tagihan lama tetap memakai parameter lama.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan}")
            ->assertOk()
            ->assertJsonPath('data.tarif_terpakai.parameter_denda.masa_tenggang', 7);
    }

    // --- Penolakan ---------------------------------------------------------

    public function test_periode_sebelum_tanggal_masuk_ditolak(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-09-01'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['periode']]);

        $this->assertSame(0, Tagihan::count());
    }

    public function test_periode_setelah_kontrak_selesai_ditolak(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');
        $kontrak->update(['status' => 'selesai', 'tgl_keluar' => '2026-10-31']);

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-11-01'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['periode']]);
    }

    public function test_kontrak_tanpa_tarif_ditolak_dengan_pesan_jelas(): void
    {
        $kamar = Kamar::create(['nomor' => 'B9', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Ani', 'email' => 'ani@kos.test']);
        ParameterDenda::create([
            'tarif_persen' => 1.00, 'plafon_persen' => 30.00,
            'masa_tenggang' => 7, 'berlaku_dari' => '2026-01-01',
        ]);
        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id, 'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-10-01', 'tarif_awal' => 0, 'deposit' => 0,
        ]);

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kamar B9 belum punya tarif yang berlaku untuk periode 2026-10-01.');
    }

    public function test_tanpa_parameter_denda_ditolak_dengan_pesan_jelas(): void
    {
        $kamar = Kamar::create(['nomor' => 'C1', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Ani', 'email' => 'ani@kos.test']);
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1_500_000, 'berlaku_dari' => '2026-01-01']);
        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id, 'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-10-01', 'tarif_awal' => 1_500_000, 'deposit' => 0,
        ]);

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Belum ada parameter denda yang berlaku untuk periode 2026-10-01. Tetapkan dulu di menu parameter denda.');
    }

    // --- Akses -------------------------------------------------------------

    public function test_penghuni_tidak_boleh_menerbitkan_tagihan(): void
    {
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);
        $akun = User::create([
            'name' => 'Budi', 'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI, 'penghuni_id' => $penghuni->id,
        ]);

        $this->actingAs($akun, 'sanctum')
            ->postJson('/api/tagihan', ['kontrak_id' => 1, 'periode' => '2026-10-01'])
            ->assertStatus(403);
    }
}
