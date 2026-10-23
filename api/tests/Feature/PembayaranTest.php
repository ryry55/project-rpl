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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\MenyiapkanKebijakan;
use Tests\TestCase;

/**
 * T5 — pencatatan pembayaran dan transisi status (proses 4.0).
 *
 * Yang diuji: status benar-benar diturunkan dari jumlah uang yang tercatat,
 * kelebihan bayar ditolak alih-alih disimpan sebagai saldo, dan angka sisa di
 * respons sama dengan yang dihitung dari basis data.
 *
 * Denda sengaja memakai tanggal acuan simulasi supaya hasilnya tidak bergantung
 * pada jam dinding saat tes dijalankan — tes yang berubah hasilnya besok bukan
 * bukti apa pun.
 */
class PembayaranTest extends TestCase
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

        // Denda sejak T17 berasal dari kebijakan, bukan tabel parameter.
        // Bawaannya R-03 supaya angka di tes ini tetap sama seperti sebelumnya.
        $this->siapkanKebijakanDenda();
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    /**
     * Tagihan siap uji. `jatuh_tempo` 15 Nov 2026, pokok Rp 1.500.000.
     *
     * Dibuat langsung lewat model, bukan lewat endpoint: tes ini soal
     * pembayaran, dan membuat tagihan lewat HTTP hanya menambah langkah yang
     * bisa gagal karena alasan yang tidak sedang diuji.
     */
    private function buatTagihan(string $status = Tagihan::STATUS_BELUM_BAYAR): Tagihan
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
            'periode' => '2026-11-01',
            'pokok' => 1_500_000,
            'jatuh_tempo' => '2026-11-15',
            'status' => $status,
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

    /**
     * Bayar pada tanggal acuan yang belum jatuh tempo, jadi denda nol.
     *
     * Metode bawaannya `tunai` karena itulah yang paling sering dipakai kos
     * kecil. `diterima_oleh` sengaja tidak ikut dikirim: sejak T20 domain
     * mengisinya dengan akun yang mencatat, dan tes ini menguji jalur bawaan
     * itu - bukan jalur yang selalu mengirim kolomnya sendiri.
     */
    private function bayar(
        Tagihan $tagihan,
        int $jumlah,
        string $tanggal = '2026-11-10',
        ?string $catatan = null,
        string $metode = 'tunai',
        ?string $referensi = null,
    ) {
        $isi = [
            'jumlah' => $jumlah,
            'tanggal_pembayaran' => $tanggal,
            'metode' => $metode,
        ];

        if ($catatan !== null) {
            $isi['catatan'] = $catatan;
        }

        if ($referensi !== null) {
            $isi['referensi'] = $referensi;
        }

        return $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", $isi);
    }

    // --- Transisi status ---------------------------------------------------

    public function test_pembayaran_sebagian_mengubah_status_menjadi_sebagian(): void
    {
        $tagihan = $this->buatTagihan();

        $respons = $this->bayar($tagihan, 500_000)
            ->assertCreated()
            ->assertJsonPath('data.status_sebelum', 'belum_bayar')
            ->assertJsonPath('data.status_sesudah', 'sebagian')
            ->assertJsonPath('data.dibayar', 500_000)
            ->assertJsonPath('data.sisa', 1_000_000)
            ->assertJsonPath('data.tagihan.status', 'sebagian');

        $this->assertSame(500_000, $respons->json('data.pembayaran.jumlah'));
        $this->assertSame('sebagian', $tagihan->fresh()->status);
    }

    public function test_pembayaran_pas_sisa_mengubah_status_menjadi_lunas(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, 500_000)->assertCreated();
        $this->bayar($tagihan, 1_000_000)
            ->assertCreated()
            ->assertJsonPath('data.status_sesudah', 'lunas')
            ->assertJsonPath('data.sisa', 0);

        $this->assertSame('lunas', $tagihan->fresh()->status);
    }

    public function test_dua_pembayaran_sebagian_berturut_turut_menjadi_lunas(): void
    {
        $tagihan = $this->buatTagihan();

        foreach ([400_000, 600_000, 500_000] as $jumlah) {
            $this->bayar($tagihan, $jumlah)->assertCreated();
        }

        $this->assertSame('lunas', $tagihan->fresh()->status);
        $this->assertSame(3, Pembayaran::count());
    }

    public function test_status_tidak_pernah_turun_walau_pembayaran_diulang_habis(): void
    {
        // Invarian: status adalah fungsi dari SUM(jumlah). Sekali lunas, tidak
        // ada pembayaran berikutnya yang bisa membuatnya kembali sebagian.
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, 1_500_000)->assertCreated();
        $this->assertSame('lunas', $tagihan->fresh()->status);

        $this->bayar($tagihan, 1)
            ->assertStatus(422);

        $this->assertSame('lunas', $tagihan->fresh()->status);
        $this->assertSame(1, Pembayaran::count());
    }

    // --- Penolakan ---------------------------------------------------------

    public function test_pembayaran_melebihi_sisa_ditolak_422(): void
    {
        $tagihan = $this->buatTagihan();

        $respons = $this->bayar($tagihan, 1_500_001)
            ->assertStatus(422)
            ->assertJsonValidationErrors('jumlah');

        // Pesannya menyebut angka konkret, bukan "tidak valid".
        $this->assertStringContainsString('1.500.000', $respons->json('errors.jumlah.0'));
        $this->assertSame(0, Pembayaran::count(), 'penolakan tidak boleh menyimpan apa pun');
        $this->assertSame('belum_bayar', $tagihan->fresh()->status);
    }

    public function test_pembayaran_melebihi_sisa_setelah_sebagian_ditolak(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, 500_000)->assertCreated();

        // Sisa 1.000.000; 1.000.001 harus ditolak.
        $this->bayar($tagihan, 1_000_001)->assertStatus(422);

        $this->assertSame(1, Pembayaran::count());
        $this->assertSame('sebagian', $tagihan->fresh()->status);
    }

    public function test_jumlah_nol_ditolak_422(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, 0)
            ->assertStatus(422)
            ->assertJsonValidationErrors('jumlah');

        $this->assertSame(0, Pembayaran::count());
    }

    public function test_jumlah_negatif_ditolak_422(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, -500_000)
            ->assertStatus(422)
            ->assertJsonValidationErrors('jumlah');

        $this->assertSame(0, Pembayaran::count());
    }

    public function test_jumlah_desimal_ditolak_bukan_dibulatkan_diam_diam(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 500_000.75,
                'tanggal_pembayaran' => '2026-11-10',
                'metode' => 'tunai',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('jumlah');

        $this->assertSame(0, Pembayaran::count());
    }

    public function test_pembayaran_pada_tagihan_dibatalkan_ditolak_422(): void
    {
        $tagihan = $this->buatTagihan(status: Tagihan::STATUS_DIBATALKAN);

        $respons = $this->bayar($tagihan, 100_000)->assertStatus(422);

        $this->assertStringContainsString('dibatalkan', $respons->json('message'));
        $this->assertSame(0, Pembayaran::count());
    }

    public function test_tanggal_pembayaran_masa_depan_ditolak_422(): void
    {
        $tagihan = $this->buatTagihan();

        $respons = $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 100_000,
                'tanggal_pembayaran' => '2026-11-11',
                'metode' => 'tunai',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal_pembayaran');

        // Pesannya menyebut hari ini menurut sistem, bukan menurut jam dinding.
        $this->assertStringContainsString('2026-11-10', $respons->json('errors.tanggal_pembayaran.0'));
        $this->assertSame(0, Pembayaran::count());
    }

    public function test_tanggal_pembayaran_hari_ini_diterima(): void
    {
        // Batas: hari ini bukan masa depan.
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, 100_000, '2026-11-10')->assertCreated();

        $this->assertSame(1, Pembayaran::count());
    }

    public function test_tanggal_pembayaran_mundur_diterima(): void
    {
        // Pemilik mencatat pembayaran kemarin hari ini. Boleh.
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, 100_000, '2026-11-01')->assertCreated();

        $this->assertSame('2026-11-01', Pembayaran::first()->tanggal_pembayaran->toDateString());
    }

    // --- Denda dan sisa ----------------------------------------------------

    public function test_sisa_tagihan_adalah_pokok_tambah_denda_kurang_pembayaran(): void
    {
        $tagihan = $this->buatTagihan();

        // 21 Nov: terlambat 6 hari, masih dalam masa tenggang 7 hari → denda 0.
        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-21')
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 500_000,
                'tanggal_pembayaran' => '2026-11-21',
                'metode' => 'tunai',
            ])
            ->assertCreated()
            ->assertJsonPath('data.total', 1_500_000)
            ->assertJsonPath('data.sisa', 1_000_000);
    }

    public function test_denda_masuk_ke_sisa_tagihan(): void
    {
        $tagihan = $this->buatTagihan();

        // 22 Nov: terlambat 7 hari, denda belum mulai (mulai hari ke-8).
        // 23 Nov: terlambat 8 hari → 1 hari denda = 1% × 1.500.000 = 15.000.
        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-23')
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 500_000,
                'tanggal_pembayaran' => '2026-11-23',
                'metode' => 'tunai',
            ])
            ->assertCreated()
            ->assertJsonPath('data.total', 1_515_000)
            ->assertJsonPath('data.sisa', 1_015_000);
    }

    public function test_pembayaran_sebagian_tidak_menurunkan_dasar_denda(): void
    {
        // Inti R-03: dasarnya pokok penuh, bukan sisa. Kalau dasarnya sisa,
        // penghuni bisa menurunkan denda dengan membayar Rp 1.
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-21')
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 1_000_000,
                'tanggal_pembayaran' => '2026-11-21',
                'metode' => 'tunai',
            ])
            ->assertCreated();

        // 21 Des: terlambat 36 hari → 29 hari denda → 29% × 1.500.000 = 435.000.
        // Dasarnya tetap 1.500.000 walau sudah dibayar 1.000.000.
        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-12-21')
            ->getJson("/api/tagihan/{$tagihan->id}/denda")
            ->assertOk()
            ->assertJsonPath('data.pokok', 1_500_000)
            ->assertJsonPath('data.denda', 435_000);
    }

    public function test_total_dibekukan_saat_lunas_sehingga_tidak_bertentangan(): void
    {
        $tagihan = $this->buatTagihan();

        // 23 Nov: total 1.515.000. Dibayar pas → lunas, total_final dibekukan.
        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-23')
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 1_515_000,
                'tanggal_pembayaran' => '2026-11-23',
                'metode' => 'tunai',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status_sesudah', 'lunas');

        // Sebulan kemudian: denda sudah berhenti, tetapi total dan dibayar
        // harus tetap sama. Tanpa `total_final`, total akan turun jadi
        // 1.500.000 sementara dibayar 1.515.000.
        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-12-31')
            ->getJson("/api/tagihan/{$tagihan->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 1_515_000)
            ->assertJsonPath('data.dibayar', 1_515_000)
            ->assertJsonPath('data.sisa', 0)
            ->assertJsonPath('data.denda', 15_000);
    }

    public function test_sisa_tidak_pernah_negatif(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, 1_500_000)->assertCreated();

        $sisa = $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2027-06-01')
            ->getJson("/api/tagihan/{$tagihan->id}")
            ->assertOk()
            ->json('data.sisa');

        $this->assertSame(0, $sisa);
    }

    // --- Riwayat -----------------------------------------------------------

    public function test_riwayat_pembayaran_ikut_di_detail_tagihan(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, 400_000, '2026-11-05', 'transfer BCA')->assertCreated();
        $this->bayar($tagihan, 600_000, '2026-11-08')->assertCreated();

        $riwayat = $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}")
            ->assertOk()
            ->json('data.pembayaran');

        $this->assertCount(2, $riwayat);
        // Urut tanggal, bukan urut id: riwayat uang dibaca menurut tanggal
        // penerimaan, bukan menurut kapan barisnya dicatat.
        $this->assertSame('2026-11-05', $riwayat[0]['tanggal_pembayaran']);
        $this->assertSame(400_000, $riwayat[0]['jumlah']);
        $this->assertSame('transfer BCA', $riwayat[0]['catatan']);
        $this->assertSame('2026-11-08', $riwayat[1]['tanggal_pembayaran']);
    }

    public function test_daftar_tagihan_membawa_dibayar_dan_sisa_tanpa_n_plus_satu(): void
    {
        $tagihan = $this->buatTagihan();
        $this->bayar($tagihan, 400_000)->assertCreated();

        $daftar = $this->sebagaiPemilik()
            ->getJson('/api/tagihan')
            ->assertOk()
            ->json('data');

        $this->assertSame(400_000, $daftar[0]['dibayar']);
        $this->assertSame(1_100_000, $daftar[0]['sisa']);
    }

    // --- Log audit ---------------------------------------------------------

    public function test_setiap_pembayaran_tercatat_di_log_audit(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, 500_000)->assertCreated();

        $log = LogAudit::where('aksi', PencatatAudit::AKSI_TAGIHAN_BAYAR)->firstOrFail();

        $this->assertSame('tagihan', $log->entitas);
        $this->assertSame($tagihan->id, $log->entitas_id);
        $this->assertSame($this->pemilik->id, $log->aktor_id);
        $this->assertSame('Pemilik Kos', $log->aktor_nama);

        // Nilai lama dan baru sama-sama tercatat: tanpa nilai lama, jejaknya
        // hanya mengatakan "sebagian" tanpa bisa menjelaskan dari mana.
        $this->assertSame('belum_bayar', $log->nilai_lama['status']);
        $this->assertSame(0, $log->nilai_lama['dibayar']);
        $this->assertSame(1_500_000, $log->nilai_lama['sisa']);

        $this->assertSame('sebagian', $log->nilai_baru['status']);
        $this->assertSame(500_000, $log->nilai_baru['jumlah']);
        $this->assertSame(1_000_000, $log->nilai_baru['sisa']);
        $this->assertSame('2026-11-10', $log->nilai_baru['tanggal_pembayaran']);
        $this->assertSame(Pembayaran::first()->id, $log->nilai_baru['pembayaran_id']);
    }

    public function test_pembayaran_ditolak_tidak_menulis_jejak_audit(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, 9_999_999)->assertStatus(422);

        // Log mencatat perubahan, bukan percobaan.
        $this->assertSame(0, LogAudit::where('aksi', PencatatAudit::AKSI_TAGIHAN_BAYAR)->count());
    }

    public function test_pembayaran_menolak_perubahan_dan_penghapusan(): void
    {
        $tagihan = $this->buatTagihan();
        $this->bayar($tagihan, 100_000)->assertCreated();

        $pembayaran = Pembayaran::firstOrFail();

        // Tiap percobaan di savepoint sendiri: penolakan pertama membuat
        // transaksi PostgreSQL teracun, dan percobaan kedua akan gagal karena
        // "current transaction is aborted" — pesan yang tidak ada hubungannya
        // dengan yang sedang diuji.
        $harusDitolak = function (callable $aksi, string $sebutan) {
            try {
                DB::transaction($aksi);
                $this->fail("{$sebutan} pada pembayaran seharusnya ditolak trigger");
            } catch (QueryException $e) {
                $this->assertStringContainsString(
                    'append-only',
                    $e->getMessage(),
                    "{$sebutan} ditolak, tetapi bukan oleh trigger append-only",
                );
            }
        };

        $harusDitolak(
            fn () => DB::table('pembayaran')->where('id', $pembayaran->id)->update(['jumlah' => 1]),
            'UPDATE',
        );

        $harusDitolak(
            fn () => DB::table('pembayaran')->where('id', $pembayaran->id)->delete(),
            'DELETE',
        );

        $this->assertSame(1, Pembayaran::count());
        $this->assertSame(100_000, (int) Pembayaran::firstOrFail()->jumlah);
    }

    // --- Akses -------------------------------------------------------------

    public function test_penghuni_tidak_boleh_mencatat_pembayaran(): void
    {
        $tagihan = $this->buatTagihan();

        $akun = User::create([
            'name' => 'Budi',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $tagihan->kontrak->penghuni_id,
        ]);

        $this->actingAs($akun, 'sanctum')
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 100_000,
                'tanggal_pembayaran' => '2026-11-10',
                'metode' => 'tunai',
            ])
            ->assertStatus(403);

        $this->assertSame(0, Pembayaran::count());
    }

    public function test_tanpa_login_ditolak(): void
    {
        $tagihan = $this->buatTagihan();

        $this->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
            'jumlah' => 100_000,
            'tanggal_pembayaran' => '2026-11-10',
            'metode' => 'tunai',
        ])->assertStatus(401);
    }

    public function test_tagihan_tidak_ada_menghasilkan_404(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/999999/pembayaran', [
                'jumlah' => 100_000,
                'tanggal_pembayaran' => '2026-11-10',
                'metode' => 'tunai',
            ])
            ->assertStatus(404);
    }
}
