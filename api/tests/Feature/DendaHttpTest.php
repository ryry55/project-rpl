<?php

namespace Tests\Feature;

use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\ParameterDenda;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * T6 — denda lewat HTTP, dan simulasi tanggal.
 *
 * Titik batas angkanya sudah diuji tanpa basis data di tests/Unit/DendaTest.php.
 * Berkas ini menguji hal yang hanya bisa dilihat lewat HTTP: apakah tanggal
 * simulasi benar-benar menggeser "hari ini", apakah header diabaikan saat mode
 * demo mati, dan apakah parameter yang dibekukan benar-benar dipakai alih-alih
 * parameter terkini.
 */
class DendaHttpTest extends TestCase
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
     * Tagihan siap uji dengan jatuh tempo tetap 2026-10-15.
     */
    private function buatTagihan(
        int $pokok = 1_500_000,
        string $jatuhTempo = '2026-10-15',
        string $status = Tagihan::STATUS_BELUM_BAYAR,
        array $parameter = [],
    ): Tagihan {
        $kamar = Kamar::create(['nomor' => 'A1', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'email' => 'budi@kos.test']);

        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-10-15',
            'tarif_awal' => $pokok,
            'deposit' => 0,
        ]);

        return Tagihan::create([
            'kontrak_id' => $kontrak->id,
            'periode' => '2026-10-01',
            'pokok' => $pokok,
            'jatuh_tempo' => $jatuhTempo,
            'status' => $status,
            'tarif_terpakai' => ['prorata' => true],
            'tarif_denda_persen' => $parameter['tarif_persen'] ?? '1.00',
            'plafon_persen' => $parameter['plafon_persen'] ?? '30.00',
            'masa_tenggang' => $parameter['masa_tenggang'] ?? 7,
            'terbit_pada' => now(),
        ]);
    }

    // --- Titik batas lewat HTTP --------------------------------------------

    public function test_denda_hari_ke_8_lewat_http(): void
    {
        $tagihan = $this->buatTagihan();

        // Jatuh tempo 15 Okt + 8 hari = 23 Okt.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2026-10-23'])
            ->assertOk()
            ->assertJsonPath('data.hari_terlambat', 8)
            ->assertJsonPath('data.hari_denda', 1)
            ->assertJsonPath('data.denda', 15_000)
            ->assertJsonPath('data.total', 1_515_000);
    }

    public function test_denda_masih_nol_dalam_masa_tenggang(): void
    {
        $tagihan = $this->buatTagihan();

        // 15 Okt + 7 hari = 22 Okt: hari terakhir tenggang.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2026-10-22'])
            ->assertOk()
            ->assertJsonPath('data.hari_terlambat', 7)
            ->assertJsonPath('data.denda', 0);
    }

    public function test_denda_plafon_tepat_di_hari_ke_37(): void
    {
        $tagihan = $this->buatTagihan();

        // 15 Okt + 37 hari = 21 Nov.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->assertJsonPath('data.hari_terlambat', 37)
            ->assertJsonPath('data.hari_denda', 30)
            ->assertJsonPath('data.denda', 450_000)
            ->assertJsonPath('data.plafon', 450_000)
            ->assertJsonPath('data.sisa_hari_ke_plafon', 0);
    }

    public function test_denda_tetap_plafon_walau_setahun_kemudian(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2027-10-15'])
            ->assertOk()
            ->assertJsonPath('data.denda', 450_000);
    }

    public function test_denda_sebelum_jatuh_tempo_nol_dan_hari_terlambat_negatif(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2026-10-10'])
            ->assertOk()
            ->assertJsonPath('data.hari_terlambat', -5)
            ->assertJsonPath('data.denda', 0);
    }

    public function test_sisa_hari_ke_plafon_menurun_seiring_waktu(): void
    {
        $tagihan = $this->buatTagihan();

        // Hari terlambat 8 → hari denda 1 → sisa 29 hari ke plafon.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2026-10-23'])
            ->assertOk()
            ->assertJsonPath('data.sisa_hari_ke_plafon', 29);

        // Hari terlambat 30 → hari denda 23 → sisa 7.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2026-11-14'])
            ->assertOk()
            ->assertJsonPath('data.sisa_hari_ke_plafon', 7);
    }

    // --- Dasar denda: pokok yang dibekukan --------------------------------

    public function test_pembayaran_sebagian_tidak_menurunkan_dasar_denda(): void
    {
        // Status sebagian = sudah dibayar sebagian. Dasarnya tetap pokok penuh.
        // Kalau dasarnya sisa tagihan, penghuni bisa menurunkan denda dengan
        // membayar Rp 1 — dan itu bukan perilaku yang diinginkan.
        $tagihan = $this->buatTagihan(status: Tagihan::STATUS_SEBAGIAN);

        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->assertJsonPath('data.pokok', 1_500_000)
            ->assertJsonPath('data.denda', 450_000)
            ->assertJsonPath('data.berhenti', false);
    }

    public function test_tagihan_lunas_tidak_lagi_berdenda(): void
    {
        $tagihan = $this->buatTagihan(status: Tagihan::STATUS_LUNAS);

        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2027-01-01'])
            ->assertOk()
            ->assertJsonPath('data.denda', 0)
            ->assertJsonPath('data.berhenti', true)
            ->assertJsonPath('data.alasan_berhenti', 'Tagihan sudah lunas.');
    }

    public function test_tagihan_dibatalkan_tidak_berdenda(): void
    {
        $tagihan = $this->buatTagihan(status: Tagihan::STATUS_DIBATALKAN);

        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2027-01-01'])
            ->assertOk()
            ->assertJsonPath('data.denda', 0)
            ->assertJsonPath('data.berhenti', true);
    }

    // --- R-06 lapis 2: parameter dibekukan --------------------------------

    public function test_mengubah_parameter_denda_tidak_mengubah_tagihan_lama(): void
    {
        $tagihan = $this->buatTagihan();

        // Parameter baru: 5%/hari, plafon 50%, tenggang 0.
        ParameterDenda::create([
            'tarif_persen' => 5.00,
            'plafon_persen' => 50.00,
            'masa_tenggang' => 0,
            'berlaku_dari' => '2026-11-01',
        ]);

        // Tagihan Oktober tetap memakai snapshot 1%/30%/7.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->assertJsonPath('data.masa_tenggang', 7)
            ->assertJsonPath('data.denda', 450_000);
    }

    public function test_parameter_berbeda_per_tagihan_dihormati(): void
    {
        // Dua tagihan dengan snapshot parameter berbeda harus menghitung
        // dengan parameternya masing-masing, bukan parameter global terkini.
        $tagihanLama = $this->buatTagihan(jatuhTempo: '2026-10-15');

        $kamar = Kamar::create(['nomor' => 'B2', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Ani', 'email' => 'ani@kos.test']);
        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-10-15',
            'tarif_awal' => 1_500_000,
            'deposit' => 0,
        ]);

        $tagihanBaru = Tagihan::create([
            'kontrak_id' => $kontrak->id,
            'periode' => '2026-11-01',
            'pokok' => 1_500_000,
            'jatuh_tempo' => '2026-10-15',
            'status' => Tagihan::STATUS_BELUM_BAYAR,
            'tarif_terpakai' => ['prorata' => false],
            'tarif_denda_persen' => '2.00',
            'plafon_persen' => '10.00',
            'masa_tenggang' => 0,
            'terbit_pada' => now(),
        ]);

        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihanLama->id}/denda", ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->assertJsonPath('data.denda', 450_000);

        // 2%/hari, plafon 10% → maksimum 150.000, tercapai di hari denda ke-5.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihanBaru->id}/denda", ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->assertJsonPath('data.plafon', 150_000)
            ->assertJsonPath('data.denda', 150_000);
    }

    // --- Simulasi tanggal --------------------------------------------------

    public function test_header_simulasi_diabaikan_saat_mode_demo_mati(): void
    {
        config(['app.demo_mode' => false]);

        $tagihan = $this->buatTagihan();

        // Diminta 2027, tapi mode demo mati: tanggal yang dipakai adalah hari
        // ini (2026 atau lebih baru, tergantung kapan tes jalan). Yang penting
        // bukan angka dendanya, melainkan bahwa tanggal simulasi tidak dipakai.
        $respons = $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2027-12-31'])
            ->assertOk()
            ->assertHeader('X-Simulasi-Dipakai', 'false');

        $this->assertNotSame('2027-12-31', $respons->json('data.tanggal_acuan'));
    }

    public function test_header_simulasi_dipakai_saat_mode_demo_aktif(): void
    {
        config(['app.demo_mode' => true]);

        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->assertHeader('X-Simulasi-Dipakai', 'true')
            ->assertHeader('X-Tanggal-Sistem', '2026-11-21')
            ->assertJsonPath('data.tanggal_acuan', '2026-11-21');
    }

    public function test_tanpa_header_simulasi_memakai_tanggal_sebenarnya(): void
    {
        config(['app.demo_mode' => true]);

        $tagihan = $this->buatTagihan();

        $respons = $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda")
            ->assertOk()
            ->assertHeader('X-Simulasi-Dipakai', 'false');

        // Tanggal acuan harus sama dengan hari ini di zona waktu aplikasi.
        $this->assertSame(
            now()->startOfDay()->toDateString(),
            $respons->json('data.tanggal_acuan'),
        );
    }

    public function test_format_tanggal_simulasi_tidak_sah_diabaikan_bukan_error(): void
    {
        config(['app.demo_mode' => true]);

        $tagihan = $this->buatTagihan();

        // Demo tidak boleh gagal karena salah ketik tanggal di tengah sidang.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => 'besok'])
            ->assertOk()
            ->assertHeader('X-Simulasi-Dipakai', 'false');

        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2026-13-45'])
            ->assertOk()
            ->assertHeader('X-Simulasi-Dipakai', 'false');
    }

    public function test_tanggal_simulasi_tidak_bocor_ke_permintaan_berikutnya(): void
    {
        config(['app.demo_mode' => true]);

        $tagihan = $this->buatTagihan();

        // Permintaan pertama memakai simulasi.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => '2027-12-31'])
            ->assertOk()
            ->assertJsonPath('data.tanggal_acuan', '2027-12-31');

        // Permintaan kedua tanpa header harus kembali ke tanggal sebenarnya.
        // Tanpa reset di middleware, tanggal 2027 akan terbawa dan denda tetap
        // 450.000 padahal seharusnya belum tentu.
        $kedua = $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda")
            ->assertOk()
            ->assertHeader('X-Simulasi-Dipakai', 'false');

        $this->assertNotSame('2027-12-31', $kedua->json('data.tanggal_acuan'));
    }

    // --- Integrasi dengan resource tagihan ---------------------------------

    public function test_daftar_tagihan_ikut_menampilkan_denda(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->getJson('/api/tagihan', ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->assertJsonPath('data.0.id', $tagihan->id)
            ->assertJsonPath('data.0.denda', 450_000)
            ->assertJsonPath('data.0.total', 1_950_000)
            ->assertJsonPath('data.0.denda_ringkas.hari_terlambat', 37);
    }

    public function test_meta_respons_mengumumkan_status_simulasi(): void
    {
        $this->buatTagihan();

        // Dengan simulasi: meta harus jujur menyebutkan tanggal acuannya.
        $this->sebagaiPemilik()
            ->getJson('/api/tagihan', ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->assertJsonPath('meta.tanggal_acuan', '2026-11-21')
            ->assertJsonPath('meta.simulasi', true);

        // Tanpa simulasi: tanggal acuan kembali ke hari ini.
        $this->sebagaiPemilik()
            ->getJson('/api/tagihan')
            ->assertOk()
            ->assertJsonPath('meta.simulasi', false)
            ->assertJsonPath('meta.tanggal_acuan', now()->startOfDay()->toDateString());
    }

    public function test_meta_melaporkan_simulasi_tidak_aktif_saat_mode_demo_mati(): void
    {
        config(['app.demo_mode' => false]);

        $this->buatTagihan();

        // Header dikirim, tapi mode demo mati: meta harus bilang tidak aktif.
        // Tanpa ini, layar akan mengira simulasi berjalan padahal tidak.
        $this->sebagaiPemilik()
            ->getJson('/api/tagihan', ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->assertJsonPath('meta.simulasi', false)
            ->assertJsonPath('meta.demo_mode', false);
    }

    public function test_detail_tagihan_menampilkan_ringkasan_denda(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}", ['X-Simulasi-Tanggal' => '2026-10-23'])
            ->assertOk()
            ->assertJsonPath('data.denda', 15_000)
            ->assertJsonPath('data.denda_ringkas.masa_tenggang', 7)
            ->assertJsonPath('data.denda_ringkas.sisa_hari_ke_plafon', 29);
    }

    // --- Akses -------------------------------------------------------------

    public function test_penghuni_tidak_boleh_melihat_denda_tagihan_orang_lain(): void
    {
        // T6 semula menutup endpoint ini rapat-rapat untuk peran penghuni.
        // T9 sengaja membukanya: penghuni berhak tahu dendanya sendiri, karena
        // itulah angka yang harus ia bayar. Yang tetap ditutup adalah tagihan
        // orang lain — dan di sini 404, bukan 403, karena 403 membocorkan bahwa
        // tagihan itu ada (R-07).
        $tagihan = $this->buatTagihan();

        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi2@kos.test']);
        $akun = User::create([
            'name' => 'Budi',
            'email' => 'budi2@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $penghuni->id,
        ]);

        $this->actingAs($akun, 'sanctum')
            ->getJson("/api/tagihan/{$tagihan->id}/denda")
            ->assertStatus(404);
    }
}
