<?php

namespace Tests\Feature;

use App\Domain\Audit\PencatatAudit;
use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\LogAudit;
use App\Models\Pembayaran;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\TarifSewa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\MenyiapkanKebijakan;
use Tests\TestCase;

/**
 * REV-FIN-07 — pemaafan denda, dan denda yang berhenti saat pokoknya lunas.
 *
 * Dua cacat yang diperbaiki di sini berbeda sifatnya:
 *
 *   1. **Pemaafan tidak ada sama sekali.** Denda adalah satu-satunya angka uang
 *      di sistem ini yang tidak bisa diputuskan manusia. Pemilik yang ingin
 *      memaafkan penghuni lama hanya punya dua jalan: membiarkan tagihan
 *      menunggak selamanya, atau menyunting basis data langsung.
 *
 *   2. **Denda terus berjalan meski pokoknya sudah dibayar penuh.** Dasar
 *      denda memang `pokok` penuh — itu keputusan yang dipertahankan, karena
 *      penghuni yang membayar Rp 1 tidak boleh bisa menurunkan dendanya. Yang
 *      keliru adalah denda yang terus bertambah sampai plafon setelah pokoknya
 *      lunas: itu bukan lagi menagih keterlambatan, itu menagih sesuatu yang
 *      sudah dibayar.
 *
 * Perhatikan bahwa denda **dibekukan** pada hari pokoknya lunas, bukan
 * dinolkan. Menolkannya akan memberi hadiah kepada penghuni yang menunda pokok
 * 100 hari — ia membayar pokoknya, lalu seluruh dendanya lenyap.
 */
class PemaafanDendaTest extends TestCase
{
    use MenyiapkanKebijakan;
    use RefreshDatabase;

    private User $pemilik;

    private User $pengelola;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pemilik = User::create([
            'name' => 'Pemilik Kos',
            'email' => 'pemilik@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PEMILIK,
        ]);

        $this->pengelola = User::create([
            'name' => 'Sari Pengelola',
            'email' => 'sari@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $this->pemilik->id,
        ]);
        $this->pengelola->kewenangan()->create(['wewenang' => 'ubah_kebijakan']);
        $this->pengelola->kewenangan()->create(['wewenang' => 'terbitkan_tagihan']);
        $this->pengelola->load('kewenangan');

        $this->siapkanKebijakanDenda();
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    /**
     * Tagihan Rp 1.500.000, jatuh tempo 15 Okt 2026, denda 1%/hari dengan
     * plafon 30% dan tenggang 7 hari. Pada 21 Nov 2026 (37 hari terlambat)
     * dendanya sudah menyentuh plafon Rp 450.000.
     */
    private function buatTagihan(): Tagihan
    {
        $kamar = Kamar::create(['nomor' => 'A1', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);

        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => 1_500_000,
            'berlaku_dari' => '2026-01-01',
        ]);

        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-01-15',
            'tarif_awal' => 1_500_000,
            'deposit' => 0,
        ]);

        return Tagihan::create([
            'kontrak_id' => $kontrak->id,
            'periode' => '2026-10-01',
            'pokok' => 1_500_000,
            'jatuh_tempo' => '2026-10-15',
            'status' => Tagihan::STATUS_BELUM_BAYAR,
            'tarif_terpakai' => ['prorata' => false],
            'kebijakan_terpakai' => [
                'denda' => [
                    'tipe' => 'persen_harian',
                    'persen' => '1.00',
                    'plafon_persen' => '30.00',
                    'masa_tenggang' => 7,
                    'nominal' => 0,
                ],
            ],
            'terbit_pada' => now(),
        ]);
    }

    private function denda(Tagihan $tagihan, string $tanggal = '2026-11-21'): array
    {
        return $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}/denda", ['X-Simulasi-Tanggal' => $tanggal])
            ->assertOk()
            ->json('data');
    }

    // --- Pemaafan ---------------------------------------------------------

    public function test_pemaafan_penuh_menghapus_denda_tapi_mencatat_aslinya(): void
    {
        $tagihan = $this->buatTagihan();

        // Tanpa pemaafan: 37 hari terlambat, denda menyentuh plafon Rp 450.000.
        $sebelum = $this->denda($tagihan);
        $this->assertSame(450_000, $sebelum['denda']);

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", [
                'jumlah' => 450_000,
                'alasan' => 'Penghuni sakit, sudah melapor',
            ])
            ->assertOk()
            ->assertJsonPath('data.denda_dimaafkan', 450_000)
            ->assertJsonPath('data.alasan_maaf_denda', 'Penghuni sakit, sudah melapor');

        $sesudah = $this->denda($tagihan);

        $this->assertSame(0, $sesudah['denda']);
        // Dua fakta yang berbeda harus tetap terbaca: dendanya pernah ada, dan
        // pemilik memaafkannya. Menampilkan nol saja akan membuat penghuni
        // mengira dendanya tidak pernah muncul.
        $this->assertSame(450_000, $sesudah['denda_sebelum_maaf']);
        $this->assertSame(450_000, $sesudah['dimaafkan']);
        $this->assertTrue($sesudah['ada_pemaafan']);
        $this->assertSame(1_500_000, $sesudah['total']);
    }

    public function test_pemaafan_sebagian_mengurangi_denda(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", [
                'jumlah' => 200_000,
                'alasan' => 'Keringanan setengah',
            ])
            ->assertOk();

        $hasil = $this->denda($tagihan);

        $this->assertSame(250_000, $hasil['denda']);
        $this->assertSame(450_000, $hasil['denda_sebelum_maaf']);
        $this->assertSame(200_000, $hasil['dimaafkan']);
    }

    public function test_pemaafan_lebih_besar_dari_denda_dijepit_ke_nol(): void
    {
        $tagihan = $this->buatTagihan();

        // Pemilik memaafkan Rp 500.000 saat denda baru Rp 450.000. Itu sah: ia
        // sedang memaafkan denda yang akan datang juga. Hasilnya nol, bukan
        // angka negatif yang terbaca sebagai "kos berutang ke penghuni".
        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", [
                'jumlah' => 500_000,
                'alasan' => 'Maaf penuh, termasuk denda bulan depan',
            ])
            ->assertOk();

        $hasil = $this->denda($tagihan);

        $this->assertSame(0, $hasil['denda']);
        $this->assertSame(500_000, $hasil['dimaafkan']);
    }

    public function test_pemaafan_bisa_ditarik_kembali_dengan_jumlah_nol(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", [
                'jumlah' => 450_000,
                'alasan' => 'Salah orang',
            ])
            ->assertOk();

        $this->assertSame(0, $this->denda($tagihan)['denda']);

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", ['jumlah' => 0])
            ->assertOk()
            ->assertJsonPath('data.denda_dimaafkan', 0)
            ->assertJsonPath('data.alasan_maaf_denda', null);

        // Denda kembali seperti semula, karena memang tidak pernah benar-benar
        // dihapus - hanya dikurangkan.
        $this->assertSame(450_000, $this->denda($tagihan)['denda']);
    }

    public function test_pemaafan_mengurangi_sisa_yang_harus_dibayar(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", [
                'jumlah' => 450_000,
                'alasan' => 'Keringanan',
            ])
            ->assertOk();

        // Sisa = pokok + denda setelah pemaafan. Denda yang dimaafkan tidak
        // boleh muncul lagi di angka yang ditagihkan.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}", ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertOk()
            ->assertJsonPath('data.denda', 0)
            ->assertJsonPath('data.total', 1_500_000)
            ->assertJsonPath('data.sisa', 1_500_000);
    }

    public function test_pembayaran_setelah_pemaafan_hanya_menagih_pokok(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", [
                'jumlah' => 450_000,
                'alasan' => 'Keringanan',
            ])
            ->assertOk();

        // Bayar Rp 1.500.000 harus langsung lunas: dendanya sudah dimaafkan.
        // Kalau pemaafan tidak ikut terbaca di `totalDibebankan`, pembayaran
        // ini akan ditolak sebagai kurang bayar Rp 450.000.
        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 1_500_000,
                'tanggal_pembayaran' => '2026-11-21',
                'metode' => 'transfer',
            ], ['X-Simulasi-Tanggal' => '2026-11-21'])
            ->assertCreated()
            ->assertJsonPath('data.status_sesudah', 'lunas')
            ->assertJsonPath('data.sisa', 0);
    }

    public function test_pemaafan_wajib_beralasan(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", ['jumlah' => 450_000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('alasan');

        $this->assertSame(0, (int) $tagihan->fresh()->denda_dimaafkan);
    }

    public function test_pemaafan_negatif_ditolak(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", [
                'jumlah' => -100_000,
                'alasan' => 'Coba-coba',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('jumlah');
    }

    public function test_pemaafan_pada_tagihan_dibatalkan_ditolak(): void
    {
        $tagihan = $this->buatTagihan();

        DB::table('tagihan')->where('id', $tagihan->id)->update([
            'status' => 'dibatalkan',
            'alasan_batal' => 'Salah terbit',
        ]);

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", [
                'jumlah' => 100_000,
                'alasan' => 'Coba maafkan',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $pesan) => str_contains($pesan, 'dibatalkan'));
    }

    public function test_pemaafan_tercatat_di_log_audit(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", [
                'jumlah' => 450_000,
                'alasan' => 'Keringanan',
            ])
            ->assertOk();

        $log = LogAudit::query()
            ->where('aksi', PencatatAudit::AKSI_DENDA_MAAFKAN)
            ->where('entitas_id', $tagihan->id)
            ->first();

        $this->assertNotNull($log, 'pemaafan tidak tercatat di log audit');
        $this->assertSame(450_000, $log->nilai_baru['denda_dimaafkan']);
        $this->assertSame('Keringanan', $log->nilai_baru['alasan']);
    }

    public function test_menarik_pemaafan_tercatat_sebagai_aksi_tersendiri(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", [
                'jumlah' => 450_000,
                'alasan' => 'Keringanan',
            ])
            ->assertOk();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", ['jumlah' => 0])
            ->assertOk();

        // Dua aksi terpisah, bukan satu aksi dengan nilai nol: "denda dimaafkan
        // Rp 0" tidak bisa dibedakan dari kesalahan tulis.
        $this->assertDatabaseHas('log_audit', [
            'aksi' => PencatatAudit::AKSI_DENDA_MAAF_DIBATALKAN,
            'entitas_id' => $tagihan->id,
        ]);
    }

    public function test_pengelola_tidak_boleh_memaafkan(): void
    {
        $tagihan = $this->buatTagihan();

        // Pengelola punya `ubah_kebijakan` dan `terbitkan_tagihan`, tetapi
        // pemaafan tetap milik pemilik: ia melepaskan uang pemilik pada satu
        // tagihan satu orang, dan tidak ada catatan operasional yang
        // membutuhkannya.
        $this->actingAs($this->pengelola, 'sanctum')
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", [
                'jumlah' => 450_000,
                'alasan' => 'Keringanan',
            ])
            ->assertForbidden();

        $this->assertSame(0, (int) $tagihan->fresh()->denda_dimaafkan);
    }

    public function test_penghuni_tidak_boleh_memaafkan(): void
    {
        $tagihan = $this->buatTagihan();

        $penghuni = User::create([
            'name' => 'Budi',
            'email' => 'budi.akun@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $tagihan->kontrak->penghuni_id,
        ]);

        $this->actingAs($penghuni, 'sanctum')
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", [
                'jumlah' => 450_000,
                'alasan' => 'Memaafkan denda sendiri',
            ])
            ->assertForbidden();
    }

    // --- Denda berhenti saat pokok lunas --------------------------------

    public function test_tagihan_lunas_tidak_lagi_menagih_denda(): void
    {
        $tagihan = $this->buatTagihan();

        // Bayar pokoknya pada 20 Okt 2026 (5 hari terlambat, masih di dalam
        // tenggang 7 hari, jadi denda hari itu nol) — tagihan langsung lunas.
        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 1_500_000,
                'tanggal_pembayaran' => '2026-10-20',
                'metode' => 'transfer',
            ], ['X-Simulasi-Tanggal' => '2026-10-20'])
            ->assertCreated()
            ->assertJsonPath('data.status_sesudah', 'lunas');

        // Sebulan kemudian, denda tidak boleh ikut membengkak. Sebabnya di sini
        // adalah status lunas — sebab yang lebih kuat daripada pokok lunas,
        // karena tidak ada lagi yang belum dibayar sama sekali.
        $hasil = $this->denda($tagihan, '2026-11-21');

        $this->assertSame(0, $hasil['denda']);
        $this->assertTrue($hasil['berhenti']);
        $this->assertSame('Tagihan sudah lunas.', $hasil['alasan_berhenti']);
    }

    public function test_denda_dibekukan_pada_hari_pokok_lunas_bukan_dinolkan(): void
    {
        $tagihan = $this->buatTagihan();

        // Bayar pokok pada 25 Okt 2026: 10 hari terlambat, lewat tenggang 3
        // hari, jadi denda hari itu = 3 x 1% x Rp 1.500.000 = Rp 45.000.
        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 1_500_000,
                'tanggal_pembayaran' => '2026-10-25',
                'metode' => 'transfer',
            ], ['X-Simulasi-Tanggal' => '2026-10-25'])
            ->assertCreated();

        $hasil = $this->denda($tagihan, '2026-11-21');

        // Dibekukan, bukan dinolkan: penghuni yang menunda pokok 10 hari tetap
        // membayar denda 3 hari. Menolkannya akan memberi hadiah kepada
        // penghuni yang paling lama menunda.
        $this->assertSame(45_000, $hasil['denda']);
        $this->assertTrue($hasil['berhenti']);
        $this->assertStringContainsString('2026-10-25', $hasil['alasan_berhenti']);
    }

    public function test_pembayaran_sebagian_tidak_menghentikan_denda(): void
    {
        $tagihan = $this->buatTagihan();

        // Rp 1.000.000 dari Rp 1.500.000: pokok belum lunas, jadi denda tetap
        // berjalan dari pokok penuh. Inilah keputusan yang dipertahankan -
        // kalau dasarnya sisa, penghuni bisa menurunkan denda dengan membayar
        // Rp 1.
        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 1_000_000,
                'tanggal_pembayaran' => '2026-10-20',
                'metode' => 'transfer',
            ], ['X-Simulasi-Tanggal' => '2026-10-20'])
            ->assertCreated();

        $hasil = $this->denda($tagihan, '2026-11-21');

        $this->assertSame(450_000, $hasil['denda']);
        $this->assertFalse($hasil['berhenti']);
    }

    public function test_pembalik_pembayaran_membuat_denda_berjalan_lagi(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 1_500_000,
                'tanggal_pembayaran' => '2026-10-20',
                'metode' => 'transfer',
            ], ['X-Simulasi-Tanggal' => '2026-10-20'])
            ->assertCreated();

        $this->assertTrue($this->denda($tagihan, '2026-11-21')['berhenti']);

        $pembayaran = Pembayaran::query()->firstOrFail();

        $this->sebagaiPemilik()
            ->postJson("/api/pembayaran/{$pembayaran->id}/pembalik", [
                'alasan' => 'Uangnya tidak jadi masuk',
            ], ['X-Simulasi-Tanggal' => '2026-10-22'])
            ->assertCreated();

        // Tanggal pokok lunas ditelusuri dari baris pembayaran, bukan dari
        // kolom penanda. Karena barisnya ditarik, pokoknya kembali belum lunas
        // dan denda berjalan lagi - persis seperti keadaan sebenarnya.
        $hasil = $this->denda($tagihan, '2026-11-21');

        $this->assertFalse($hasil['berhenti']);
        $this->assertSame(450_000, $hasil['denda']);
    }

    public function test_pemaafan_dan_pembekuan_bisa_terjadi_bersamaan(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 1_500_000,
                'tanggal_pembayaran' => '2026-10-25',
                'metode' => 'transfer',
            ], ['X-Simulasi-Tanggal' => '2026-10-25'])
            ->assertCreated();

        // Denda beku Rp 45.000; pemilik memaafkan Rp 20.000 dari itu.
        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/maaf-denda", [
                'jumlah' => 20_000,
                'alasan' => 'Sebagian dimaafkan',
            ])
            ->assertOk();

        $hasil = $this->denda($tagihan, '2026-11-21');

        $this->assertSame(45_000, $hasil['denda_sebelum_maaf']);
        $this->assertSame(20_000, $hasil['dimaafkan']);
        $this->assertSame(25_000, $hasil['denda']);
    }
}
