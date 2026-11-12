<?php

namespace Tests\Feature;

use App\Domain\Billing\DendaTerpilih;
use App\Domain\Billing\Kalkulator;
use App\Domain\Kebijakan\IdentitasKos;
use App\Domain\Kebijakan\PilihanKebijakan;
use App\Mail\PemberitahuanKos;
use App\Mail\TautanAkunKos;
use App\Models\Kamar;
use App\Models\KebijakanKos;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\MenyiapkanKebijakan;
use Tests\TestCase;

/**
 * K-17 — nama kos dan pratinjau denda.
 *
 * Dua hal yang diuji, dan kenapa masing-masing perlu:
 *
 *   1. **Nama kos benar-benar menggantikan "Kos-Kosan" di tempat yang dibaca
 *      orang.** Menyimpan barisnya saja tidak membuktikan apa pun: nilai yang
 *      tersimpan tetapi tidak pernah dibaca adalah konfigurasi yang menipu.
 *      Karena itu yang diperiksa adalah endpoint publik, subjek email, dan
 *      nilai bawaan saat kebijakannya belum ada.
 *
 *   2. **Pratinjau denda memakai rumus yang sama dengan tagihan sungguhan.**
 *      Pratinjau yang menghitung sendiri di JavaScript akan berbeda satu rupiah
 *      pada pokok besar — tepat pada angka yang paling dipertanyakan penghuni.
 *      Tesnya membandingkan hasil endpoint dengan `Kalkulator` langsung, bukan
 *      dengan angka yang diketik di tes.
 */
class NamaKosTest extends TestCase
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
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    private function simpanNama(string $nama, string $berlakuDari = '2026-01-01'): void
    {
        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_NAMA_KOS,
            'nilai' => $nama,
            'berlaku_dari' => $berlakuDari,
        ]);
    }

    // --- Nama kos ----------------------------------------------------------

    public function test_tanpa_kebijakan_nama_kos_memakai_nilai_bawaan(): void
    {
        $this->getJson('/api/identitas-kos')
            ->assertOk()
            ->assertJsonPath('data.nama', IdentitasKos::BAWAAN);
    }

    public function test_nama_kos_tersimpan_dan_terbaca_di_endpoint_publik(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => PilihanKebijakan::TIPE_NAMA_KOS,
                'nilai' => 'Kos Putri Melati',
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertCreated();

        // Tanpa `actingAs`: layar masuk dibuka sebelum ada token.
        $this->getJson('/api/identitas-kos')
            ->assertOk()
            ->assertJsonPath('data.nama', 'Kos Putri Melati');
    }

    public function test_nama_kos_tidak_boleh_per_kamar(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);

        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => PilihanKebijakan::TIPE_NAMA_KOS,
                'nilai' => 'Kos Putri Melati',
                'kamar_id' => $kamar->id,
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('kamar_id');
    }

    public function test_nama_kos_menolak_teks_kosong(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => PilihanKebijakan::TIPE_NAMA_KOS,
                'nilai' => '   ',
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nilai');
    }

    public function test_nama_kos_menolak_lebih_dari_60_karakter(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => PilihanKebijakan::TIPE_NAMA_KOS,
                'nilai' => str_repeat('a', 61),
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nilai');
    }

    public function test_check_constraint_menolak_nama_kos_kosong(): void
    {
        // Lapis kedua: validasi HTTP bisa dilewati seeder atau SQL langsung.
        $this->expectException(QueryException::class);

        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_NAMA_KOS,
            'nilai' => '   ',
            'berlaku_dari' => '2026-01-01',
        ]);
    }

    public function test_subjek_email_memakai_nama_kos(): void
    {
        $this->simpanNama('Kos Putri Melati');

        $pemberitahuan = new PemberitahuanKos('tagihan_terbit', 'Tagihan baru', 'Isi');
        $this->assertSame('[Kos Putri Melati] Tagihan baru', $pemberitahuan->envelope()->subject);

        $tautan = new TautanAkunKos('aktivasi', 'Budi', 'https://contoh.test', Carbon::now()->addDay());
        $this->assertSame('[Kos Putri Melati] Aktifkan akun Anda', $tautan->envelope()->subject);
    }

    public function test_nama_kos_berlaku_mulai_tanggal_yang_ditetapkan(): void
    {
        $this->simpanNama('Kos Lama', '2026-01-01');
        $this->simpanNama('Kos Baru', '2026-06-01');

        $identitas = app(IdentitasKos::class);

        $this->assertSame('Kos Lama', $identitas->nama(Carbon::parse('2026-03-15')));
        $this->assertSame('Kos Baru', $identitas->nama(Carbon::parse('2026-07-15')));
    }

    // --- Pratinjau denda ---------------------------------------------------

    public function test_pratinjau_denda_sama_dengan_kalkulator(): void
    {
        $this->siapkanKebijakanDenda();

        $harapan = app(Kalkulator::class)->hitungDendaMenurut(
            DendaTerpilih::persenHarian('1.00', '30.00', 7),
            1_500_000,
            10,
        );

        $this->sebagaiPemilik()
            ->getJson('/api/kebijakan/pratinjau-denda?pokok=1500000&hari_terlambat=10')
            ->assertOk()
            ->assertJsonPath('data.denda', $harapan)
            ->assertJsonPath('data.bentuk', 'persen')
            // 30% dari 1.500.000.
            ->assertJsonPath('data.plafon', 450_000)
            // 10 hari terlambat, tenggang 7 → 3 hari kena denda.
            ->assertJsonPath('data.hari_denda', 3);
    }

    public function test_pratinjau_denda_nominal_tidak_berplafon(): void
    {
        $this->siapkanKebijakanDenda(denda: [
            'nilai' => PilihanKebijakan::DENDA_NOMINAL_TETAP,
            'nominal' => 50_000,
        ]);

        $this->sebagaiPemilik()
            ->getJson('/api/kebijakan/pratinjau-denda?pokok=1500000&hari_terlambat=10')
            ->assertOk()
            ->assertJsonPath('data.denda', 50_000)
            ->assertJsonPath('data.bentuk', 'nominal')
            // `null`, bukan 0: bentuk ini memang tidak punya batas atas.
            ->assertJsonPath('data.plafon', null);
    }

    public function test_pratinjau_denda_tanpa_denda_menghasilkan_nol(): void
    {
        $this->siapkanKebijakanDenda(denda: [
            'nilai' => PilihanKebijakan::DENDA_TANPA_DENDA,
        ]);

        $this->sebagaiPemilik()
            ->getJson('/api/kebijakan/pratinjau-denda?pokok=1500000&hari_terlambat=10')
            ->assertOk()
            ->assertJsonPath('data.denda', 0)
            ->assertJsonPath('data.bentuk', 'tanpa');
    }

    public function test_pratinjau_denda_tanpa_kebijakan_menjawab_null(): void
    {
        $this->sebagaiPemilik()
            ->getJson('/api/kebijakan/pratinjau-denda?pokok=1500000&hari_terlambat=10')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_pratinjau_denda_menolak_pokok_negatif(): void
    {
        $this->siapkanKebijakanDenda();

        $this->sebagaiPemilik()
            ->getJson('/api/kebijakan/pratinjau-denda?pokok=-1&hari_terlambat=10')
            ->assertStatus(422)
            ->assertJsonValidationErrors('pokok');
    }

    public function test_pratinjau_denda_tertutup_untuk_penghuni(): void
    {
        $this->siapkanKebijakanDenda();

        $penghuni = User::create([
            'name' => 'Penghuni',
            'email' => 'penghuni@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
        ]);

        $this->actingAs($penghuni, 'sanctum')
            ->getJson('/api/kebijakan/pratinjau-denda?pokok=1500000&hari_terlambat=10')
            ->assertForbidden();
    }
}
