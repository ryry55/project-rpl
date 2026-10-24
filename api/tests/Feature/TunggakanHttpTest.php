<?php

namespace Tests\Feature;

use App\Domain\Kebijakan\PilihanKebijakan;
use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * T7 — tunggakan per kelompok umur lewat HTTP (R-04).
 *
 * Titik batas 30/31 dan 60/61 sudah diuji murni di tests/Unit/UmurTunggakanTest.php.
 * Berkas ini menguji hal yang hanya terlihat lewat HTTP: penyaringan status di
 * SQL, kelengkapan kelompok, dan — yang paling penting — bahwa angka di
 * dashboard **identik** dengan endpoint denda untuk tagihan yang sama.
 *
 * Semua tanggal memakai simulasi, jadi tes ini tidak bergantung pada tanggal
 * mesin yang menjalankannya. Tanpa itu, tes akan lulus hari ini dan gagal bulan
 * depan.
 */
class TunggakanHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $pemilik;

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
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    /**
     * Tagihan dengan jatuh tempo 2026-10-15, supaya umur tunggakan mudah
     * dihitung: hari terlambat = tanggal_acuan − 2026-10-15.
     */
    private function buatTagihan(
        string $nomorKamar,
        string $namaPenghuni,
        int $pokok = 1_500_000,
        string $jatuhTempo = '2026-10-15',
        string $status = Tagihan::STATUS_BELUM_BAYAR,
    ): Tagihan {
        $kamar = Kamar::create(['nomor' => $nomorKamar, 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => $namaPenghuni, 'email' => strtolower($nomorKamar).'@kos.test']);

        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-01-15',
            'tarif_awal' => $pokok,
            'deposit' => 0,
        ]);

        return Tagihan::create([
            'kontrak_id' => $kontrak->id,
            'periode' => '2026-10-01',
            'pokok' => $pokok,
            'jatuh_tempo' => $jatuhTempo,
            'status' => $status,
            'tarif_terpakai' => ['prorata' => false],
            // Bentuk denda R-03 dibekukan langsung, seperti yang ditulis
            // PenerbitTagihan saat terbit. Tunggakan hanya membaca arsip ini.
            'kebijakan_terpakai' => [
                'denda' => [
                    'tipe' => PilihanKebijakan::DENDA_PERSEN_HARIAN,
                    'persen' => '1.00',
                    'plafon_persen' => '30.00',
                    'masa_tenggang' => 7,
                    'nominal' => 0,
                ],
            ],
            'terbit_pada' => now(),
            // Sejak REV-FIN-02, baris berstatus `dibatalkan` wajib beralasan
            // (CHECK `tagihan_batal_ada_alasan`). Tes ini membuat tagihan batal
            // langsung lewat model, jadi alasannya harus ikut diisi.
            'alasan_batal' => $status === Tagihan::STATUS_DIBATALKAN
                ? 'Dibatalkan oleh tes: tagihan sengaja dibuat batal.'
                : null,
        ]);
    }

    /**
     * Ambil kelompok berdasarkan kunci, dari respons apa adanya.
     *
     * @param  array<string, mixed>  $isi
     * @return array<string, mixed>
     */
    private function kelompok(array $isi, string $kunci): array
    {
        foreach ($isi['data']['kelompok'] as $k) {
            if ($k['kunci'] === $kunci) {
                return $k;
            }
        }

        $this->fail("Kelompok {$kunci} tidak ada di respons.");
    }

    // --- Empat kelompok lengkap -------------------------------------------

    public function test_empat_kelompok_selalu_dikembalikan_walau_kosong(): void
    {
        // Tanpa tagihan sama sekali. Kelompok kosong tetap harus ada: pemilik
        // perlu melihat "0 di atas 60 hari" sebagai kabar baik, bukan sebagai
        // kelompok yang hilang dari layar.
        $respons = $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk();

        $isi = $respons->json();
        $this->assertCount(4, $isi['data']['kelompok']);

        $this->assertSame(
            ['belum_jatuh_tempo', '0_30', '31_60', 'lebih_60'],
            array_column($isi['data']['kelompok'], 'kunci'),
        );

        foreach ($isi['data']['kelompok'] as $k) {
            $this->assertSame(0, $k['jumlah'], "kelompok {$k['kunci']} tidak kosong");
            $this->assertSame([], $k['tagihan']);
        }

        $this->assertSame(0, $isi['data']['ringkasan']['jumlah']);
    }

    public function test_setiap_kelompok_berisi_tagihan_yang_tepat(): void
    {
        // Jatuh tempo 15 Okt. Tanggal acuan 21 Nov = hari terlambat 37.
        // Empat tagihan dengan jatuh tempo berbeda supaya masuk empat kelompok.
        $belum = $this->buatTagihan('A1', 'Belum Jatuh Tempo', jatuhTempo: '2026-12-15');
        $baru = $this->buatTagihan('A2', 'Baru Terlambat', jatuhTempo: '2026-11-01'); // 20 hari
        $perlu = $this->buatTagihan('A3', 'Perlu Ditagih', jatuhTempo: '2026-10-01'); // 51 hari
        $parah = $this->buatTagihan('A4', 'Sudah Lama', jatuhTempo: '2026-08-01'); // 112 hari

        $isi = $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->json();

        $this->assertSame($belum->id, $this->kelompok($isi, 'belum_jatuh_tempo')['tagihan'][0]['id']);
        $this->assertSame($baru->id, $this->kelompok($isi, '0_30')['tagihan'][0]['id']);
        $this->assertSame($perlu->id, $this->kelompok($isi, '31_60')['tagihan'][0]['id']);
        $this->assertSame($parah->id, $this->kelompok($isi, 'lebih_60')['tagihan'][0]['id']);

        $this->assertSame(1, $this->kelompok($isi, 'lebih_60')['jumlah']);
    }

    // --- Batas 30/31 dan 60/61 lewat HTTP ---------------------------------

    public function test_batas_hari_30_dan_31_lewat_http(): void
    {
        // Jatuh tempo 15 Okt. Hari terlambat 30 = 14 Nov, 31 = 15 Nov.
        $this->buatTagihan('B1', 'Tiga Puluh', jatuhTempo: '2026-10-15');

        $hari30 = $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => '2026-11-14'])
            ->assertOk()
            ->json();
        $this->assertSame(1, $this->kelompok($hari30, '0_30')['jumlah']);
        $this->assertSame(0, $this->kelompok($hari30, '31_60')['jumlah']);
        $this->assertSame(30, $this->kelompok($hari30, '0_30')['tagihan'][0]['hari_terlambat']);

        $hari31 = $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => '2026-11-15'])
            ->assertOk()
            ->json();
        $this->assertSame(0, $this->kelompok($hari31, '0_30')['jumlah']);
        $this->assertSame(1, $this->kelompok($hari31, '31_60')['jumlah']);
        $this->assertSame(31, $this->kelompok($hari31, '31_60')['tagihan'][0]['hari_terlambat']);
    }

    public function test_batas_hari_60_dan_61_lewat_http(): void
    {
        // Hari terlambat 60 = 14 Des, 61 = 15 Des.
        $this->buatTagihan('C1', 'Enam Puluh', jatuhTempo: '2026-10-15');

        $hari60 = $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => '2026-12-14'])
            ->assertOk()
            ->json();
        $this->assertSame(1, $this->kelompok($hari60, '31_60')['jumlah']);
        $this->assertSame(0, $this->kelompok($hari60, 'lebih_60')['jumlah']);

        $hari61 = $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => '2026-12-15'])
            ->assertOk()
            ->json();
        $this->assertSame(0, $this->kelompok($hari61, '31_60')['jumlah']);
        $this->assertSame(1, $this->kelompok($hari61, 'lebih_60')['jumlah']);
    }

    public function test_tagihan_belum_jatuh_tempo_masuk_kelompok_tersendiri(): void
    {
        $tagihan = $this->buatTagihan('D1', 'Belum Waktunya', jatuhTempo: '2026-12-15');

        $isi = $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->json();

        $belum = $this->kelompok($isi, 'belum_jatuh_tempo');
        $this->assertSame(1, $belum['jumlah']);
        $this->assertSame($tagihan->id, $belum['tagihan'][0]['id']);
        $this->assertSame(-24, $belum['tagihan'][0]['hari_terlambat']);

        // Dan tidak muncul di 0-30.
        $this->assertSame(0, $this->kelompok($isi, '0_30')['jumlah']);
    }

    // --- Penyaringan status -----------------------------------------------

    public function test_tagihan_lunas_dan_dibatalkan_tidak_muncul_di_kelompok_mana_pun(): void
    {
        $this->buatTagihan('E1', 'Sudah Lunas', status: Tagihan::STATUS_LUNAS);
        $this->buatTagihan('E2', 'Dibatalkan', status: Tagihan::STATUS_DIBATALKAN);
        $aktif = $this->buatTagihan('E3', 'Masih Menunggak');

        $isi = $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->json();

        // Hanya satu tagihan yang boleh muncul, dan ringkasannya harus 1.
        $this->assertSame(1, $isi['data']['ringkasan']['jumlah']);

        $semuaId = [];
        foreach ($isi['data']['kelompok'] as $k) {
            foreach ($k['tagihan'] as $baris) {
                $semuaId[] = $baris['id'];
            }
        }

        $this->assertSame([$aktif->id], $semuaId);
    }

    public function test_status_sebagian_tetap_muncul_sebagai_tunggakan(): void
    {
        // Dibayar sebagian belum berarti lunas. Kalau ikut tersaring, tunggakan
        // yang paling berbahaya — yang sudah bayar sedikit lalu berhenti —
        // justru hilang dari layar.
        $this->buatTagihan('F1', 'Bayar Sebagian', status: Tagihan::STATUS_SEBAGIAN);

        $isi = $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->json();

        $this->assertSame(1, $isi['data']['ringkasan']['jumlah']);
    }

    // --- Kelengkapan tiap baris -------------------------------------------

    public function test_tiap_baris_menampilkan_kolom_yang_dibutuhkan_pemilik(): void
    {
        $this->buatTagihan('G1', 'Budi Santoso', pokok: 1_500_000, jatuhTempo: '2026-10-15');

        $isi = $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->json();

        $baris = $this->kelompok($isi, '31_60')['tagihan'][0];

        $this->assertSame('Budi Santoso', $baris['penghuni']);
        $this->assertSame('G1', $baris['kamar']);
        $this->assertSame('2026-10-01', $baris['periode']);
        $this->assertSame('2026-10-15', $baris['jatuh_tempo']);
        $this->assertSame(1_500_000, $baris['pokok']);
        $this->assertSame(450_000, $baris['denda']);
        $this->assertSame(1_950_000, $baris['total']);
        $this->assertSame(37, $baris['hari_terlambat']);
        $this->assertSame('belum_bayar', $baris['status']);
    }

    // --- Konsistensi dengan endpoint denda --------------------------------

    public function test_angka_tunggakan_identik_dengan_endpoint_denda(): void
    {
        // Kriteria penerimaan tiket. Kalau dua jalur ini berbeda, berarti ada
        // yang menyalin rumus denda alih-alih memanggil kalkulator yang sama.
        $tagihan = $this->buatTagihan('H1', 'Budi Santoso', jatuhTempo: '2026-10-15');
        $tanggal = '2026-11-21';

        $dariTunggakan = $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => $tanggal])
            ->assertOk()
            ->json();

        $dariDenda = $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => $tanggal])
            ->assertOk()
            ->json();

        $baris = $this->kelompok($dariTunggakan, '31_60')['tagihan'][0];

        $this->assertSame($dariDenda['data']['denda'], $baris['denda'], 'denda berbeda');
        $this->assertSame($dariDenda['data']['pokok'], $baris['pokok'], 'pokok berbeda');
        $this->assertSame($dariDenda['data']['hari_terlambat'], $baris['hari_terlambat'], 'hari terlambat berbeda');
        $this->assertSame(
            $dariDenda['data']['pokok'] + $dariDenda['data']['denda'],
            $baris['total'],
            'total berbeda',
        );
        $this->assertSame($dariDenda['data']['tanggal_acuan'], $dariTunggakan['data']['tanggal_acuan']);
    }

    public function test_konsistensi_bertahan_di_beberapa_tanggal_acuan(): void
    {
        // Satu tanggal bisa kebetulan cocok. Diuji di beberapa titik, termasuk
        // yang belum jatuh tempo dan yang sudah kena plafon.
        $tagihan = $this->buatTagihan('I1', 'Budi Santoso', jatuhTempo: '2026-10-15');

        foreach (['2026-10-10', '2026-10-22', '2026-10-23', '2026-11-14', '2026-11-21', '2027-06-01'] as $tanggal) {
            $dariTunggakan = $this->sebagaiPemilik()
                ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => $tanggal])
                ->assertOk()
                ->json();

            $dariDenda = $this->sebagaiPemilik()
                ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => $tanggal])
                ->assertOk()
                ->json();

            $semuaBaris = [];
            foreach ($dariTunggakan['data']['kelompok'] as $k) {
                foreach ($k['tagihan'] as $b) {
                    $semuaBaris[] = $b;
                }
            }

            $this->assertCount(1, $semuaBaris, "tanggal {$tanggal}: baris tidak tepat satu");
            $this->assertSame($dariDenda['data']['denda'], $semuaBaris[0]['denda'], "tanggal {$tanggal}");
        }
    }

    // --- Agregat -----------------------------------------------------------

    public function test_total_rupiah_per_kelompok_dan_ringkasan_benar(): void
    {
        // Dua tagihan di 31-60, satu di lebih_60.
        $this->buatTagihan('J1', 'Satu', pokok: 1_500_000, jatuhTempo: '2026-10-15');
        $this->buatTagihan('J2', 'Dua', pokok: 1_000_000, jatuhTempo: '2026-10-15');
        $this->buatTagihan('J3', 'Tiga', pokok: 2_000_000, jatuhTempo: '2026-08-01');

        $isi = $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->json();

        $kelompok3150 = $this->kelompok($isi, '31_60');
        $this->assertSame(2, $kelompok3150['jumlah']);
        $this->assertSame(2_500_000, $kelompok3150['total_pokok']);
        // 1% dari 1.500.000 × 30 hari = 450.000; dari 1.000.000 = 300.000.
        $this->assertSame(750_000, $kelompok3150['total_denda']);
        $this->assertSame(3_250_000, $kelompok3150['total']);

        // Ringkasan menjumlahkan semua kelompok.
        $this->assertSame(3, $isi['data']['ringkasan']['jumlah']);
        $this->assertSame(
            $kelompok3150['total'] + $this->kelompok($isi, 'lebih_60')['total'],
            $isi['data']['ringkasan']['total'],
        );
    }

    public function test_total_kelompok_sama_dengan_jumlah_barisnya(): void
    {
        // Invarian: agregat tidak boleh menyimpang dari isi daftarnya. Kalau
        // menyimpang, ada baris yang dihitung dua kali atau tidak sama sekali.
        $this->buatTagihan('K1', 'Satu', jatuhTempo: '2026-10-15');
        $this->buatTagihan('K2', 'Dua', jatuhTempo: '2026-11-01');
        $this->buatTagihan('K3', 'Tiga', jatuhTempo: '2026-08-01');

        $isi = $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->json();

        foreach ($isi['data']['kelompok'] as $k) {
            $this->assertSame(count($k['tagihan']), $k['jumlah'], "jumlah {$k['kunci']}");
            $this->assertSame(
                array_sum(array_column($k['tagihan'], 'total')),
                $k['total'],
                "total {$k['kunci']}",
            );
            $this->assertSame(
                array_sum(array_column($k['tagihan'], 'denda')),
                $k['total_denda'],
                "total denda {$k['kunci']}",
            );
        }
    }

    public function test_meta_mengumumkan_status_simulasi(): void
    {
        $this->buatTagihan('L1', 'Budi', jatuhTempo: '2026-10-15');

        // Dengan header: meta harus jujur menyebut tanggal acuannya.
        $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->assertJsonPath('data.tanggal_acuan', '2026-11-21')
            ->assertJsonPath('data.simulasi', true)
            ->assertJsonPath('data.demo_mode', true);

        // Tanpa header: kembali ke tanggal sebenarnya.
        $this->sebagaiPemilik()
            ->getJson('/api/tunggakan')
            ->assertOk()
            ->assertJsonPath('data.simulasi', false)
            ->assertJsonPath('data.tanggal_acuan', now()->startOfDay()->toDateString());
    }

    public function test_meta_melaporkan_simulasi_tidak_aktif_saat_mode_demo_mati(): void
    {
        config(['app.demo_mode' => false]);

        $this->buatTagihan('M1', 'Budi', jatuhTempo: '2026-10-15');

        // Header dikirim tetapi mode demo mati. Kalau meta tetap melaporkan
        // simulasi aktif, layar akan mengklaim angka berasal dari tanggal
        // simulasi padahal berasal dari hari ini.
        $this->sebagaiPemilik()
            ->getJson('/api/tunggakan', ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->assertJsonPath('data.simulasi', false)
            ->assertJsonPath('data.demo_mode', false);
    }

    // --- Akses -------------------------------------------------------------

    public function test_penghuni_tidak_boleh_melihat_tunggakan(): void
    {
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);
        $akun = User::create([
            'name' => 'Budi',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $penghuni->id,
        ]);

        // Tunggakan adalah pandangan pemilik atas seluruh penghuni. Penghuni
        // tidak boleh melihat tunggakan orang lain.
        $this->actingAs($akun, 'sanctum')
            ->getJson('/api/tunggakan')
            ->assertStatus(403);
    }

    public function test_tanpa_login_ditolak(): void
    {
        $this->getJson('/api/tunggakan')->assertStatus(401);
    }
}
