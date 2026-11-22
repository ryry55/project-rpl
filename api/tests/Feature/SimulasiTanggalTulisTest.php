<?php

namespace Tests\Feature;

use App\Domain\Akses\Wewenang;
use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\LaporanKerusakan;
use App\Models\PengelolaKewenangan;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\TarifSewa;
use App\Models\User;
use App\Models\UsulanPerubahan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\MenyiapkanKebijakan;
use Tests\TestCase;

/**
 * Temuan #8 (Fase 9) - kolom waktu yang ditulis dengan `now()` langsung.
 *
 * Konvensi repo ini satu: waktu sekarang dibaca dari `WaktuSistem`, bukan dari
 * `now()`. Alasannya bukan kerapian. Denda bergantung pada "hari ini", dan
 * satu-satunya cara mendemokan denda 37 hari dalam sidang 15 menit adalah
 * menggeser "hari ini" lewat header `X-Simulasi-Tanggal`. Setiap `now()` yang
 * lolos membuat satu kolom tercatat pada tanggal nyata sementara kolom di
 * sebelahnya memakai tanggal simulasi - dan yang membacanya menyimpulkan ada
 * dua peristiwa yang sebenarnya satu.
 *
 * Yang diuji karena itu bukan "nilainya terisi", melainkan **tanggal mana**
 * yang tersimpan: tanggal simulasi, bukan tanggal mesin. Tanggal mesin tidak
 * pernah dipakai sebagai pembanding, karena asersi seperti itu lulus hari ini
 * dan gagal bulan depan tanpa satu baris kode berubah.
 */
class SimulasiTanggalTulisTest extends TestCase
{
    use MenyiapkanKebijakan;
    use RefreshDatabase;

    private User $pemilik;

    private User $pengelola;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.demo_mode' => true]);

        $this->pemilik = User::create([
            'name' => 'Pemilik Kos',
            'email' => 'pemilik@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PEMILIK,
        ]);

        $this->pengelola = User::create([
            'name' => 'Sari Pengelola',
            'email' => 'pengelola@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $this->pemilik->id,
        ]);

        $this->siapkanKebijakanDefault();
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    private function kamar(string $nomor): Kamar
    {
        $kamar = Kamar::create(['nomor' => $nomor, 'status_huni' => 'kosong']);

        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => 1_500_000,
            'berlaku_dari' => '2026-01-01',
        ]);

        return $kamar;
    }

    private function kontrak(string $nomor, string $tglMasuk): Kontrak
    {
        $kamar = $this->kamar($nomor);
        $penghuni = Penghuni::create([
            'nama' => 'Penghuni '.$nomor,
            'email' => strtolower($nomor).'@kos.test',
        ]);

        return Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => $tglMasuk,
            'tarif_awal' => 1_500_000,
            'status' => 'aktif',
        ]);
    }

    // --- Tagihan terbit ----------------------------------------------------

    public function test_terbit_pada_memakai_tanggal_simulasi(): void
    {
        $kontrak = $this->kontrak('A1', '2026-01-01');

        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-03-10')
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-03-01'])
            ->assertCreated();

        $this->assertSame(
            '2026-03-10',
            Tagihan::latest('id')->firstOrFail()->terbit_pada->toDateString(),
        );
    }

    // --- Kontrak -----------------------------------------------------------

    /**
     * Kontrak yang mulai di masa depan lahir `dipesan`, bukan `aktif` (S1.9).
     *
     * "Masa depan" harus diukur dari tanggal simulasi. Kalau diukur dari jam
     * mesin, demo dengan tanggal mundur mencatat reservasi sebagai kontrak
     * berjalan - dan penghuninya langsung ditagih untuk bulan yang belum ia
     * tempati.
     */
    public function test_status_kontrak_baru_dinilai_dengan_tanggal_simulasi(): void
    {
        $kamar = $this->kamar('A2');
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);

        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-01')
            ->postJson('/api/kontrak', [
                'kamar_id' => $kamar->id,
                'penghuni_id' => $penghuni->id,
                'tgl_masuk' => '2026-11-20',
            ])
            ->assertCreated();

        $this->assertSame('dipesan', Kontrak::latest('id')->firstOrFail()->status);
    }

    public function test_tgl_keluar_memakai_tanggal_simulasi(): void
    {
        $kontrak = $this->kontrak('A3', '2026-01-01');

        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-06-15')
            ->postJson("/api/kontrak/{$kontrak->id}/akhiri")
            ->assertOk();

        $this->assertSame('2026-06-15', $kontrak->fresh()->tgl_keluar->toDateString());
    }

    // --- Kerusakan ---------------------------------------------------------

    public function test_selesai_pada_memakai_tanggal_simulasi(): void
    {
        $kamar = $this->kamar('A4');

        $this->sebagaiPemilik()
            ->postJson('/api/laporan-kerusakan', [
                'kamar_id' => $kamar->id,
                'deskripsi' => 'Plafon kamar mandi bocor',
            ])
            ->assertCreated();

        $laporan = LaporanKerusakan::latest('id')->firstOrFail();

        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-07-04')
            ->postJson("/api/laporan-kerusakan/{$laporan->id}/selesai")
            ->assertOk();

        $this->assertSame('2026-07-04', $laporan->fresh()->selesai_pada->toDateString());
    }

    // --- Usulan perubahan --------------------------------------------------

    private function usulanTarif(): UsulanPerubahan
    {
        PengelolaKewenangan::create([
            'user_id' => $this->pengelola->id,
            'wewenang' => Wewenang::UBAH_TARIF,
        ]);
        $this->pengelola->unsetRelation('kewenangan');

        $kamar = $this->kamar('A5');

        $this->actingAs($this->pengelola, 'sanctum')
            ->postJson('/api/tarif', [
                'kamar_id' => $kamar->id,
                'nilai' => 1_800_000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertStatus(202);

        return UsulanPerubahan::latest('id')->firstOrFail();
    }

    public function test_diputuskan_pada_memakai_tanggal_simulasi_saat_disetujui(): void
    {
        $usulan = $this->usulanTarif();

        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-05')
            ->postJson("/api/usulan/{$usulan->id}/setujui")
            ->assertOk();

        $this->assertSame('2026-11-05', $usulan->fresh()->diputuskan_pada->toDateString());
    }

    public function test_diputuskan_pada_memakai_tanggal_simulasi_saat_ditolak(): void
    {
        $usulan = $this->usulanTarif();

        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-06')
            ->postJson("/api/usulan/{$usulan->id}/tolak", ['alasan' => 'Tarif terlalu tinggi untuk kamar ini.'])
            ->assertOk();

        $this->assertSame('2026-11-06', $usulan->fresh()->diputuskan_pada->toDateString());
    }
}
