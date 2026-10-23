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
 * T9 — isolasi penghuni dan log audit append-only (R-07).
 *
 * Dua hal yang saling menopang: isolasi tanpa audit tidak bisa dibuktikan, dan
 * audit tanpa isolasi tidak ada gunanya. Karena itu keduanya diuji di berkas
 * yang sama.
 *
 * Dua tes terakhir memakai `DB::statement` mentah untuk membuktikan penegakan
 * append-only ada di **basis data**, bukan hanya di kode aplikasi. Kalau
 * penegakannya hanya di aplikasi, `psql` tetap bisa mengubah riwayat — dan
 * riwayat yang bisa diubah dari `psql` bukan riwayat.
 */
class IsolasiDanAuditTest extends TestCase
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

    private function sebagai(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    /**
     * Satu penghuni lengkap dengan akun login dan satu tagihan.
     *
     * @return array{penghuni: Penghuni, user: User, tagihan: Tagihan, kamar: Kamar}
     */
    private function buatPenghuniDenganTagihan(string $nomorKamar, string $nama, string $email): array
    {
        $kamar = Kamar::create(['nomor' => $nomorKamar, 'status_huni' => 'terisi']);

        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => 1_500_000,
            'berlaku_dari' => '2026-01-01',
        ]);

        $penghuni = Penghuni::create(['nama' => $nama, 'email' => $email]);

        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-01-15',
            'tarif_awal' => 1_500_000,
            'deposit' => 0,
        ]);

        $tagihan = Tagihan::create([
            'kontrak_id' => $kontrak->id,
            'periode' => '2026-11-01',
            'pokok' => 1_500_000,
            'jatuh_tempo' => '2026-11-15',
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

        $akun = User::create([
            'name' => $nama,
            'email' => $email,
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $penghuni->id,
        ]);

        return ['penghuni' => $penghuni, 'user' => $akun, 'tagihan' => $tagihan, 'kamar' => $kamar];
    }

    // --- Isolasi penghuni --------------------------------------------------

    public function test_penghuni_hanya_melihat_tagihannya_sendiri(): void
    {
        $a = $this->buatPenghuniDenganTagihan('A1', 'Ani', 'ani@kos.test');
        $this->buatPenghuniDenganTagihan('B1', 'Budi', 'budi@kos.test');

        $respons = $this->sebagai($a['user'])
            ->getJson('/api/penghuni/tagihan')
            ->assertOk();

        $daftar = $respons->json('data');

        $this->assertCount(1, $daftar, 'penghuni hanya boleh melihat satu tagihannya sendiri');
        $this->assertSame($a['tagihan']->id, $daftar[0]['id']);
    }

    public function test_penghuni_mengakses_tagihan_orang_lain_mendapat_404_bukan_403(): void
    {
        $a = $this->buatPenghuniDenganTagihan('A1', 'Ani', 'ani@kos.test');
        $b = $this->buatPenghuniDenganTagihan('B1', 'Budi', 'budi@kos.test');

        // 404, bukan 403: 403 berarti "ada, tapi kamu tidak berhak" — itu
        // membocorkan keberadaan tagihan orang lain. 404 menyembunyikannya.
        $this->sebagai($a['user'])
            ->getJson("/api/tagihan/{$b['tagihan']->id}")
            ->assertStatus(404);
    }

    public function test_penghuni_mengakses_denda_tagihan_orang_lain_mendapat_404(): void
    {
        // Pintu samping harus dijaga sama ketatnya. Endpoint denda tidak
        // menampilkan data pribadi, tetapi ia tetap membocorkan bahwa tagihan
        // dengan id itu ada.
        $a = $this->buatPenghuniDenganTagihan('A1', 'Ani', 'ani@kos.test');
        $b = $this->buatPenghuniDenganTagihan('B1', 'Budi', 'budi@kos.test');

        $this->sebagai($a['user'])
            ->getJson("/api/tagihan/{$b['tagihan']->id}/denda")
            ->assertStatus(404);
    }

    public function test_penghuni_bisa_melihat_denda_tagihannya_sendiri(): void
    {
        $a = $this->buatPenghuniDenganTagihan('A1', 'Ani', 'ani@kos.test');

        $this->sebagai($a['user'])
            ->withHeader('X-Simulasi-Tanggal', '2026-11-23')
            ->getJson("/api/tagihan/{$a['tagihan']->id}/denda")
            ->assertOk()
            ->assertJsonPath('data.pokok', 1_500_000)
            ->assertJsonPath('data.denda', 15_000);
    }

    public function test_penghuni_memanggil_endpoint_pemilik_ditolak_403(): void
    {
        $a = $this->buatPenghuniDenganTagihan('A1', 'Ani', 'ani@kos.test');

        foreach ([
            '/api/tagihan',
            '/api/kamar',
            '/api/penghuni',
            '/api/kontrak',
            '/api/tarif',
            '/api/kebijakan',
            '/api/tunggakan',
            '/api/audit-log',
        ] as $jalur) {
            $this->sebagai($a['user'])
                ->getJson($jalur)
                ->assertStatus(403, "penghuni seharusnya ditolak di {$jalur}");
        }
    }

    public function test_penghuni_tidak_bisa_mengubah_apa_pun(): void
    {
        $a = $this->buatPenghuniDenganTagihan('A1', 'Ani', 'ani@kos.test');

        $this->sebagai($a['user'])
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->postJson("/api/tagihan/{$a['tagihan']->id}/pembayaran", [
                'jumlah' => 100_000,
                'tanggal_pembayaran' => '2026-11-10',
            ])
            ->assertStatus(403);

        $this->sebagai($a['user'])
            ->postJson('/api/tagihan', ['kontrak_id' => 1, 'periode' => '2026-12-01'])
            ->assertStatus(403);

        // Id kamar diambil dari data yang baru dibuat, bukan angka tetap.
        // `SubstituteBindings` berjalan **sebelum** `PastikanPeran`, jadi id
        // yang tidak ada menghasilkan 404 sebelum 403 sempat diperiksa — dan
        // dengan id tetap, hasilnya berubah tergantung tes lain yang berjalan
        // lebih dulu.
        $this->sebagai($a['user'])
            ->putJson("/api/kamar/{$a['kamar']->id}", ['nomor' => 'Z9'])
            ->assertStatus(403);
        $this->assertSame(0, Pembayaran::count());
    }

    public function test_id_tagihan_yang_tidak_ada_menghasilkan_404(): void
    {
        $a = $this->buatPenghuniDenganTagihan('A1', 'Ani', 'ani@kos.test');

        $this->sebagai($a['user'])
            ->getJson('/api/tagihan/999999')
            ->assertStatus(404);

        $this->sebagai($this->pemilik)
            ->getJson('/api/tagihan/999999')
            ->assertStatus(404);
    }

    public function test_penghuni_tanpa_data_penghuni_melihat_daftar_kosong(): void
    {
        // Akun yatim: peran penghuni tetapi tidak terhubung ke data penghuni.
        // Daftar kosong lebih jujur daripada 500, dan tidak membocorkan apa pun.
        $yatim = User::create([
            'name' => 'Tanpa Data',
            'email' => 'yatim@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
        ]);

        $this->sebagai($yatim)
            ->getJson('/api/penghuni/tagihan')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_pemilik_melihat_semua_tagihan(): void
    {
        $a = $this->buatPenghuniDenganTagihan('A1', 'Ani', 'ani@kos.test');
        $b = $this->buatPenghuniDenganTagihan('B1', 'Budi', 'budi@kos.test');

        $daftar = $this->sebagai($this->pemilik)
            ->getJson('/api/tagihan')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $daftar);
        $this->assertEqualsCanonicalizing(
            [$a['tagihan']->id, $b['tagihan']->id],
            array_column($daftar, 'id'),
        );
    }

    public function test_pemilik_melihat_tagihan_penghuni_mana_pun(): void
    {
        $a = $this->buatPenghuniDenganTagihan('A1', 'Ani', 'ani@kos.test');

        $this->sebagai($this->pemilik)
            ->getJson("/api/tagihan/{$a['tagihan']->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $a['tagihan']->id);
    }

    // --- Log audit: baca ---------------------------------------------------

    public function test_pemilik_membaca_log_audit(): void
    {
        $a = $this->buatPenghuniDenganTagihan('A1', 'Ani', 'ani@kos.test');

        $this->sebagai($this->pemilik)
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->postJson("/api/tagihan/{$a['tagihan']->id}/pembayaran", [
                'jumlah' => 500_000,
                'tanggal_pembayaran' => '2026-11-10',
            ])
            ->assertCreated();

        $log = $this->sebagai($this->pemilik)
            ->getJson('/api/audit-log')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $log);
        $this->assertSame(PencatatAudit::AKSI_TAGIHAN_BAYAR, $log[0]['aksi']);
        $this->assertSame('tagihan', $log[0]['entitas']);
        $this->assertSame($a['tagihan']->id, $log[0]['entitas_id']);
        $this->assertSame('Pemilik Kos', $log[0]['aktor_nama']);
        $this->assertNotNull($log[0]['waktu']);
        $this->assertSame('belum_bayar', $log[0]['nilai_lama']['status']);
        $this->assertSame('sebagian', $log[0]['nilai_baru']['status']);
    }

    public function test_log_audit_bisa_disaring_per_entitas(): void
    {
        $a = $this->buatPenghuniDenganTagihan('A1', 'Ani', 'ani@kos.test');

        $this->sebagai($this->pemilik)
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->postJson("/api/tagihan/{$a['tagihan']->id}/pembayaran", [
                'jumlah' => 500_000,
                'tanggal_pembayaran' => '2026-11-10',
            ])->assertCreated();

        $this->sebagai($this->pemilik)
            ->postJson('/api/tarif', [
                'kamar_id' => $a['tagihan']->kontrak->kamar_id,
                'nilai' => 1_600_000,
                'berlaku_dari' => '2026-12-01',
            ])->assertCreated();

        $semua = $this->sebagai($this->pemilik)->getJson('/api/audit-log')->json('data');
        $this->assertCount(2, $semua);

        $hanyaTagihan = $this->sebagai($this->pemilik)
            ->getJson('/api/audit-log?entitas=tagihan')
            ->json('data');

        $this->assertCount(1, $hanyaTagihan);
        $this->assertSame('tagihan', $hanyaTagihan[0]['entitas']);

        $satuTagihan = $this->sebagai($this->pemilik)
            ->getJson("/api/audit-log?entitas=tagihan&entitas_id={$a['tagihan']->id}")
            ->json('data');

        $this->assertCount(1, $satuTagihan);
    }

    public function test_log_audit_urut_terbaru_dulu(): void
    {
        $a = $this->buatPenghuniDenganTagihan('A1', 'Ani', 'ani@kos.test');

        $this->sebagai($this->pemilik)
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->postJson("/api/tagihan/{$a['tagihan']->id}/pembayaran", [
                'jumlah' => 400_000,
                'tanggal_pembayaran' => '2026-11-05',
            ])->assertCreated();

        $this->sebagai($this->pemilik)
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->postJson("/api/tagihan/{$a['tagihan']->id}/pembayaran", [
                'jumlah' => 600_000,
                'tanggal_pembayaran' => '2026-11-08',
            ])->assertCreated();

        $log = $this->sebagai($this->pemilik)->getJson('/api/audit-log')->json('data');

        $this->assertCount(2, $log);
        // Yang terakhir dicatat muncul lebih dulu.
        $this->assertSame(600_000, $log[0]['nilai_baru']['jumlah']);
        $this->assertSame(400_000, $log[1]['nilai_baru']['jumlah']);
    }

    public function test_batas_jumlah_baris_diumumkan(): void
    {
        $a = $this->buatPenghuniDenganTagihan('A1', 'Ani', 'ani@kos.test');

        foreach ([100_000, 100_000, 100_000] as $jumlah) {
            $this->sebagai($this->pemilik)
                ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
                ->postJson("/api/tagihan/{$a['tagihan']->id}/pembayaran", [
                    'jumlah' => $jumlah,
                    'tanggal_pembayaran' => '2026-11-10',
                ])->assertCreated();
        }

        $respons = $this->sebagai($this->pemilik)
            ->getJson('/api/audit-log?batas=2')
            ->assertOk();

        $this->assertCount(2, $respons->json('data'));
        // Tanpa pengumuman ini, layar akan menyiratkan bahwa dua baris adalah
        // seluruh isi log — padahal ada tiga.
        $this->assertTrue($respons->json('meta.dipotong'));
    }

    public function test_tidak_ada_endpoint_untuk_mengubah_atau_menghapus_log_audit(): void
    {
        // Path yang sama sekali tidak terdaftar → 404; path yang ada tetapi
        // metodenya tidak dilayani → 405. Keduanya berarti "tidak ada jalan",
        // dan yang penting adalah tidak ada satu pun yang berhasil.
        $this->sebagai($this->pemilik)->postJson('/api/audit-log', [])->assertStatus(405);
        $this->sebagai($this->pemilik)->putJson('/api/audit-log/1', [])->assertStatus(404);
        $this->sebagai($this->pemilik)->deleteJson('/api/audit-log/1')->assertStatus(404);
        $this->sebagai($this->pemilik)->patchJson('/api/audit-log/1', [])->assertStatus(404);

        $this->assertSame(0, LogAudit::count());
    }

    // --- Log audit: penegakan di basis data --------------------------------

    public function test_update_langsung_ke_log_audit_ditolak_basis_data(): void
    {
        $this->sebagai($this->pemilik)->postJson('/api/tagihan/1/pembayaran', []);

        LogAudit::create([
            'aktor_id' => $this->pemilik->id,
            'aktor_nama' => 'Pemilik Kos',
            'aksi' => 'uji.manual',
            'entitas' => 'uji',
            'entitas_id' => 1,
        ]);

        $this->expectException(QueryException::class);

        // Query mentah, bukan Eloquent: yang diuji adalah trigger basis data,
        // bukan perilaku model.
        DB::statement("UPDATE log_audit SET aksi = 'diubah' WHERE aksi = 'uji.manual'");
    }

    public function test_delete_langsung_ke_log_audit_ditolak_basis_data(): void
    {
        LogAudit::create([
            'aktor_id' => $this->pemilik->id,
            'aktor_nama' => 'Pemilik Kos',
            'aksi' => 'uji.manual',
            'entitas' => 'uji',
            'entitas_id' => 1,
        ]);

        $this->expectException(QueryException::class);

        DB::statement("DELETE FROM log_audit WHERE aksi = 'uji.manual'");
    }

    public function test_penolakan_menyebut_append_only_bukan_galat_acak(): void
    {
        LogAudit::create([
            'aktor_id' => null,
            'aktor_nama' => null,
            'aksi' => 'uji.pesan',
            'entitas' => 'uji',
        ]);

        try {
            DB::transaction(fn () => DB::table('log_audit')->where('aksi', 'uji.pesan')->delete());
            $this->fail('DELETE pada log_audit seharusnya ditolak trigger');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame(1, LogAudit::where('aksi', 'uji.pesan')->count());
    }
}
