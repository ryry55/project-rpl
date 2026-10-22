<?php

namespace Tests\Feature;

use App\Domain\Audit\PencatatAudit;
use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\LogAudit;
use App\Models\ParameterDenda;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\TarifSewa;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * T4 — penerbitan massal dan penolakan duplikat periode (R-02).
 *
 * Yang diuji di sini hanya hal yang tidak bisa diuji tanpa basis data: apakah
 * unique index benar-benar menahan balapan, apakah satu kontrak bermasalah
 * menggagalkan seluruh batch, dan apakah jejak audit benar-benar tertulis.
 *
 * Perilaku "dilewati karena belum mulai" dan "gagal karena tarif kosong" diuji
 * lewat HTTP karena keduanya hanya terlihat sebagai hasil batch.
 */
class PenerbitanMassalTest extends TestCase
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

        ParameterDenda::create([
            'tarif_persen' => 1.00,
            'plafon_persen' => 30.00,
            'masa_tenggang' => 7,
            'berlaku_dari' => '2026-01-01',
        ]);
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    /**
     * Kontrak siap tagih. Nomor kamar dan email wajib berbeda tiap kontrak
     * karena keduanya unik.
     */
    private function buatKontrak(
        string $nomor,
        string $tglMasuk = '2026-01-15',
        ?int $tarif = 1_500_000,
        string $status = 'aktif',
    ): Kontrak {
        $kamar = Kamar::create(['nomor' => $nomor, 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create([
            'nama' => 'Penghuni '.$nomor,
            'email' => strtolower($nomor).'@kos.test',
        ]);

        if ($tarif !== null) {
            TarifSewa::create([
                'kamar_id' => $kamar->id,
                'nilai' => $tarif,
                'berlaku_dari' => '2026-01-01',
            ]);
        }

        return Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => $tglMasuk,
            'tarif_awal' => $tarif ?? 0,
            'deposit' => 0,
            'status' => $status,
        ]);
    }

    // --- Terbit untuk semua kontrak aktif ---------------------------------

    public function test_terbit_untuk_semua_kontrak_aktif(): void
    {
        $a = $this->buatKontrak('A1');
        $b = $this->buatKontrak('A2');
        $c = $this->buatKontrak('A3');

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.terbit', 3)
            ->assertJsonPath('data.gagal', 0);

        $this->assertSame(3, Tagihan::count());

        foreach ([$a, $b, $c] as $kontrak) {
            $this->assertDatabaseHas('tagihan', [
                'kontrak_id' => $kontrak->id,
                'periode' => '2026-11-01',
            ]);
        }

        $this->assertCount(3, $respons->json('data.rincian'));
    }

    public function test_kontrak_tidak_aktif_tidak_diterbitkan(): void
    {
        $this->buatKontrak('B1');
        $this->buatKontrak('B2', status: 'selesai');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.terbit', 1);

        $this->assertSame(1, Tagihan::count());
    }

    // --- R-02 duplikat -----------------------------------------------------

    public function test_menekan_dua_kali_menghasilkan_409_bukan_tagihan_kedua(): void
    {
        $this->buatKontrak('C1');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertCreated();

        $kedua = $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertStatus(409)
            ->assertJsonPath('data.terbit', 0)
            ->assertJsonPath('data.dilewati', 1)
            ->assertJsonPath('data.dilewati_duplikat', 1);

        $this->assertSame(1, Tagihan::count(), 'duplikat tidak boleh membuat baris kedua');
        $this->assertSame(
            'Tagihan periode ini sudah pernah diterbitkan.',
            $kedua->json('data.rincian.0.alasan'),
        );
    }

    public function test_unique_index_menolak_duplikat_walau_aplikasi_dilewati(): void
    {
        // Menembak constraint langsung, tanpa lewat layanan. Kalau pengaman
        // hanya ada di kode aplikasi, baris ini akan lolos — dan R-02 hanya
        // jadi janji di dokumen.
        $kontrak = $this->buatKontrak('D1');

        $baris = fn () => DB::table('tagihan')->insert([
            'kontrak_id' => $kontrak->id,
            'periode' => '2026-11-01',
            'pokok' => 1_500_000,
            'jatuh_tempo' => '2026-11-15',
            'status' => 'belum_bayar',
            'tarif_terpakai' => json_encode(['prorata' => false]),
            'tarif_denda_persen' => '1.00',
            'plafon_persen' => '30.00',
            'masa_tenggang' => 7,
            'terbit_pada' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $baris();

        try {
            $baris();
            $this->fail('unique index (kontrak_id, periode) tidak menolak duplikat');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->getCode(), 'kode SQLSTATE harus 23505');
        }
    }

    public function test_duplikat_pada_satu_kontrak_tidak_menggagalkan_batch(): void
    {
        // Satu kontrak sudah punya tagihan periode ini; dua lainnya belum.
        $sudah = $this->buatKontrak('E1');
        $this->buatKontrak('E2');
        $this->buatKontrak('E3');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $sudah->id, 'periode' => '2026-11-01'])
            ->assertCreated();

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.terbit', 2)
            ->assertJsonPath('data.dilewati', 1)
            ->assertJsonPath('data.dilewati_duplikat', 1)
            ->assertJsonPath('data.gagal', 0);

        $this->assertSame(3, Tagihan::count());
        $this->assertCount(3, $respons->json('data.rincian'));
    }

    // --- Kontrak yang belum mulai -----------------------------------------

    public function test_kontrak_dengan_tgl_masuk_setelah_akhir_periode_dilewati(): void
    {
        $this->buatKontrak('F1', tglMasuk: '2026-01-15');
        $this->buatKontrak('F2', tglMasuk: '2026-12-05');

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            // F1 tertagih, jadi ada perubahan: 201, bukan 200.
            ->assertCreated()
            ->assertJsonPath('data.terbit', 1)
            ->assertJsonPath('data.dilewati', 1)
            // Bukan duplikat: tidak ada yang ditekan dua kali di sini.
            ->assertJsonPath('data.dilewati_duplikat', 0);

        $rincian = collect($respons->json('data.rincian'))->firstWhere('kamar', 'F2');
        $this->assertSame('belum_mulai', $rincian['sebab']);
        $this->assertStringContainsString('2026-12-05', $rincian['alasan']);
    }

    public function test_kontrak_yang_belum_mulai_tidak_menghasilkan_409(): void
    {
        // Tidak ada duplikat, jadi menekan tombol tidak boleh terlihat sebagai
        // penolakan. 200 dengan terbit 0 adalah jawaban yang jujur.
        $this->buatKontrak('G1', tglMasuk: '2026-12-05');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertOk()
            ->assertJsonPath('data.terbit', 0)
            ->assertJsonPath('data.dilewati', 1);
    }

    // --- Kegagalan per kontrak --------------------------------------------

    public function test_kamar_tanpa_tarif_gagal_tanpa_membatalkan_kontrak_lain(): void
    {
        $this->buatKontrak('H1');
        $tanpaTarif = $this->buatKontrak('H2', tarif: null);
        $this->buatKontrak('H3');

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.terbit', 2)
            ->assertJsonPath('data.gagal', 1);

        // Dua kontrak lain tetap tertagih. Inilah alasan tiap kontrak punya
        // transaksi sendiri.
        $this->assertSame(2, Tagihan::count());
        $this->assertDatabaseMissing('tagihan', ['kontrak_id' => $tanpaTarif->id]);

        $gagal = collect($respons->json('data.rincian'))
            ->firstWhere('kontrak_id', $tanpaTarif->id);

        $this->assertSame('gagal', $gagal['status']);
        $this->assertStringContainsString('tarif', $gagal['alasan']);
    }

    public function test_tanpa_kontrak_aktif_ditolak_422(): void
    {
        $this->buatKontrak('I1', status: 'selesai');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertStatus(422);
    }

    // --- Log audit ---------------------------------------------------------

    public function test_setiap_tagihan_yang_terbit_tercatat_di_log_audit(): void
    {
        $a = $this->buatKontrak('J1');
        $b = $this->buatKontrak('J2');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertCreated();

        $this->assertSame(2, LogAudit::count());

        foreach ([$a, $b] as $kontrak) {
            $tagihan = Tagihan::where('kontrak_id', $kontrak->id)->firstOrFail();

            $log = LogAudit::where('aksi', PencatatAudit::AKSI_TAGIHAN_TERBIT)
                ->where('entitas', 'tagihan')
                ->where('entitas_id', $tagihan->id)
                ->first();

            $this->assertNotNull($log, 'tagihan tanpa jejak audit');
            $this->assertSame($this->pemilik->id, $log->aktor_id);
            $this->assertSame('Pemilik Kos', $log->aktor_nama);
            $this->assertSame('2026-11-01', $log->nilai_baru['periode']);
            $this->assertSame((int) $tagihan->pokok, $log->nilai_baru['pokok']);
        }
    }

    public function test_tagihan_yang_dilewati_tidak_menghasilkan_jejak_audit(): void
    {
        $this->buatKontrak('K1');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertCreated();

        $sebelum = LogAudit::count();

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertStatus(409);

        // Jejak audit mencatat perubahan, bukan percobaan. Batch yang seluruhnya
        // dilewati tidak mengubah apa pun, jadi tidak boleh menambah baris.
        $this->assertSame($sebelum, LogAudit::count());
    }

    public function test_log_audit_menolak_perubahan_dan_penghapusan(): void
    {
        $this->buatKontrak('L1');
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertCreated();

        $log = LogAudit::firstOrFail();

        // Tiap percobaan dibungkus transaksinya sendiri (menjadi SAVEPOINT,
        // karena tes berjalan di dalam transaksi RefreshDatabase). Tanpa itu,
        // penolakan pertama membuat transaksi PostgreSQL teracun dan percobaan
        // kedua gagal karena "current transaction is aborted" — pesan yang
        // tidak ada hubungannya dengan yang sedang diuji.
        $harusDitolak = function (callable $aksi, string $sebutan) {
            try {
                DB::transaction($aksi);
                $this->fail("{$sebutan} pada log_audit seharusnya ditolak trigger");
            } catch (QueryException $e) {
                $this->assertStringContainsString(
                    'append-only',
                    $e->getMessage(),
                    "{$sebutan} ditolak, tetapi bukan oleh trigger append-only",
                );
            }
        };

        $harusDitolak(
            fn () => DB::table('log_audit')->where('id', $log->id)->update(['aksi' => 'diubah']),
            'UPDATE',
        );

        $harusDitolak(
            fn () => DB::table('log_audit')->where('id', $log->id)->delete(),
            'DELETE',
        );

        $this->assertSame(1, LogAudit::count());
        $this->assertSame(PencatatAudit::AKSI_TAGIHAN_TERBIT, LogAudit::firstOrFail()->aksi);
    }

    public function test_perubahan_tarif_tercatat_di_log_audit(): void
    {
        $kamar = Kamar::create(['nomor' => 'M1', 'status_huni' => 'kosong']);

        $this->sebagaiPemilik()
            ->postJson('/api/tarif', [
                'kamar_id' => $kamar->id,
                'nilai' => 1_750_000,
                'berlaku_dari' => '2026-12-01',
            ])
            ->assertCreated();

        $log = LogAudit::where('aksi', PencatatAudit::AKSI_TARIF_TAMBAH)->firstOrFail();

        $this->assertSame('tarif_sewa', $log->entitas);
        $this->assertSame(1_750_000, $log->nilai_baru['nilai']);
        $this->assertSame('2026-12-01', $log->nilai_baru['berlaku_dari']);
    }

    public function test_perubahan_parameter_denda_tercatat_di_log_audit(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/parameter-denda', [
                'tarif_persen' => 1.5,
                'plafon_persen' => 40,
                'masa_tenggang' => 5,
                'berlaku_dari' => '2026-12-01',
            ])
            ->assertCreated();

        $log = LogAudit::where('aksi', PencatatAudit::AKSI_PARAMETER_DENDA_TAMBAH)->firstOrFail();

        $this->assertSame('parameter_denda', $log->entitas);
        $this->assertSame('1.50', $log->nilai_baru['tarif_persen']);
        $this->assertSame(5, $log->nilai_baru['masa_tenggang']);
    }

    public function test_perintah_artisan_menulis_jejak_tanpa_aktor(): void
    {
        // Penerbitan bulanan adalah pekerjaan terjadwal: ia berjalan tanpa
        // sesi. Jejak tanpa aktor masih berguna — yang penting perubahan dan
        // waktunya tercatat — tetapi harus jelas bahwa tidak ada manusia yang
        // menekan tombol saat itu.
        $this->buatKontrak('P1');

        $this->artisan('tagihan:terbitkan', ['periode' => '2026-11-01'])
            ->assertExitCode(0);

        $this->assertSame(1, Tagihan::count());

        $log = LogAudit::where('aksi', PencatatAudit::AKSI_TAGIHAN_TERBIT)->firstOrFail();
        $this->assertNull($log->aktor_id);
        $this->assertNull($log->aktor_nama);
        $this->assertSame('2026-11-01', $log->nilai_baru['periode']);
    }

    public function test_perintah_artisan_menghormati_duplikat(): void
    {
        $this->buatKontrak('Q1');

        $this->artisan('tagihan:terbitkan', ['periode' => '2026-11-01'])->assertExitCode(0);
        $this->artisan('tagihan:terbitkan', ['periode' => '2026-11-01'])->assertExitCode(0);

        // Dijalankan dua kali, tetapi R-02 tetap utuh.
        $this->assertSame(1, Tagihan::count());
    }

    // --- Validasi dan akses -----------------------------------------------

    public function test_periode_wajib_format_tanggal(): void
    {
        $this->buatKontrak('N1');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => 'November 2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('periode');
    }

    public function test_penghuni_tidak_boleh_menerbitkan_massal(): void
    {
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);
        $akun = User::create([
            'name' => 'Budi',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $penghuni->id,
        ]);

        $this->actingAs($akun, 'sanctum')
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertStatus(403);
    }

    public function test_tanpa_login_ditolak(): void
    {
        $this->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertStatus(401);
    }
}
