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
 * REV-FIN-02 — pembatalan tagihan yang salah terbit.
 *
 * Sebelum ini R-02 dijaga unique index **mutlak** `(kontrak_id, periode)`,
 * sementara DFD sudah menyebut "koreksi dilakukan lewat pembatalan lalu terbit
 * ulang". Jalur itu mustahil: baris yang dibatalkan tetap memegang kuncinya,
 * sehingga penerbitan ulang periode yang sama selalu ditolak 409. Aturannya
 * benar, jalannya tidak ada.
 *
 * Yang diuji di sini ada tiga lapis, dan ketiganya harus lulus bersama:
 *
 *   1. **Endpoint pembatalan** menolak tagihan yang sudah menerima uang, dan
 *      mencatat alasan, waktu, serta pelakunya.
 *   2. **Index parsial** benar-benar membebaskan periode, sehingga penerbitan
 *      ulang berhasil. Tanpa uji ini, seluruh fitur bisa tampak beres padahal
 *      gunanya nol.
 *   3. **Baris batal tidak hilang** — ia tetap terbaca lengkap, karena tagihan
 *      yang pernah disebut ke penghuni tidak boleh lenyap dari riwayat.
 */
class PembatalanTagihanTest extends TestCase
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

        $this->siapkanKebijakanDefault();
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    /**
     * Tagihan belum dibayar pada periode tertentu, siap dibatalkan.
     */
    private function buatTagihan(string $periode = '2026-11-01'): Tagihan
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
        ]);

        return Tagihan::create([
            'kontrak_id' => $kontrak->id,
            'periode' => $periode,
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
    }

    // --- Pembatalan dasar --------------------------------------------------

    public function test_pembatalan_mengubah_status_dan_menyimpan_alasan(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/batal", [
                'alasan' => 'Salah kamar, seharusnya A2',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'dibatalkan')
            ->assertJsonPath('data.alasan_batal', 'Salah kamar, seharusnya A2');

        $baris = $tagihan->fresh();

        $this->assertSame(Tagihan::STATUS_DIBATALKAN, $baris->status);
        $this->assertSame('Salah kamar, seharusnya A2', $baris->alasan_batal);
        $this->assertSame($this->pemilik->id, (int) $baris->dibatalkan_oleh);
        $this->assertNotNull($baris->dibatalkan_pada);
    }

    public function test_baris_batal_tidak_dihapus_dan_tetap_terbaca(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/batal", ['alasan' => 'Dobel terbit'])
            ->assertOk();

        // Barisnya masih ada, lengkap dengan pokok dan rinciannya.
        $this->assertDatabaseHas('tagihan', [
            'id' => $tagihan->id,
            'status' => 'dibatalkan',
            'pokok' => 1_500_000,
        ]);

        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'dibatalkan')
            ->assertJsonPath('data.alasan_batal', 'Dobel terbit');
    }

    public function test_pembatalan_tercatat_di_log_audit(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/batal", ['alasan' => 'Periode keliru'])
            ->assertOk();

        $log = LogAudit::query()
            ->where('aksi', PencatatAudit::AKSI_TAGIHAN_BATAL)
            ->where('entitas_id', $tagihan->id)
            ->first();

        $this->assertNotNull($log, 'pembatalan tidak tercatat di log audit');
        $this->assertSame($this->pemilik->id, (int) $log->aktor_id);
        $this->assertSame('Periode keliru', $log->nilai_baru['alasan']);
    }

    // --- Penjagaan ---------------------------------------------------------

    public function test_pembatalan_tanpa_alasan_ditolak(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/batal", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('alasan');

        $this->assertSame(Tagihan::STATUS_BELUM_BAYAR, $tagihan->fresh()->status);
    }

    public function test_pembatalan_tagihan_yang_sudah_dibayar_ditolak(): void
    {
        $tagihan = $this->buatTagihan();

        Pembayaran::create([
            'tagihan_id' => $tagihan->id,
            'jumlah' => 500_000,
            'metode' => 'tunai',
            'sumber' => 'dicatat_pemilik',
            'dicatat_oleh' => $this->pemilik->id,
            'diterima_oleh' => $this->pemilik->id,
            'tanggal_pembayaran' => '2026-11-10',
        ]);

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/batal", ['alasan' => 'Coba batalkan'])
            ->assertStatus(422)
            ->assertJsonPath('errors.alasan.0', fn (string $pesan) => str_contains($pesan, 'Rp 500.000'));

        $this->assertSame(Tagihan::STATUS_BELUM_BAYAR, $tagihan->fresh()->status);
    }

    public function test_pembatalan_dua_kali_ditolak(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/batal", ['alasan' => 'Salah terbit'])
            ->assertOk();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/batal", ['alasan' => 'Salah terbit lagi'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $pesan) => str_contains($pesan, 'sudah dibatalkan'));
    }

    public function test_pengelola_tanpa_webenang_terbitkan_tidak_boleh_membatalkan(): void
    {
        $tagihan = $this->buatTagihan();

        $this->actingAs($this->pengelola, 'sanctum')
            ->postJson("/api/tagihan/{$tagihan->id}/batal", ['alasan' => 'Coba batalkan'])
            ->assertForbidden();

        $this->assertSame(Tagihan::STATUS_BELUM_BAYAR, $tagihan->fresh()->status);
    }

    public function test_pengelola_dengan_webenang_terbitkan_boleh_membatalkan(): void
    {
        $this->pengelola->kewenangan()->create(['wewenang' => 'terbitkan_tagihan']);
        $this->pengelola->load('kewenangan');

        $tagihan = $this->buatTagihan();

        $this->actingAs($this->pengelola, 'sanctum')
            ->postJson("/api/tagihan/{$tagihan->id}/batal", ['alasan' => 'Salah kamar'])
            ->assertOk();

        // Pelakunya pengelola, bukan pemilik - jejak audit harus menyebut
        // orang yang benar-benar menekan tombol.
        $log = LogAudit::query()
            ->where('aksi', PencatatAudit::AKSI_TAGIHAN_BATAL)
            ->where('entitas_id', $tagihan->id)
            ->first();

        $this->assertSame($this->pengelola->id, (int) $log->aktor_id);
    }

    public function test_penghuni_tidak_boleh_membatalkan(): void
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
            ->postJson("/api/tagihan/{$tagihan->id}/batal", ['alasan' => 'Coba batalkan'])
            ->assertForbidden();
    }

    public function test_tanpa_login_ditolak(): void
    {
        $tagihan = $this->buatTagihan();

        $this->postJson("/api/tagihan/{$tagihan->id}/batal", ['alasan' => 'Coba batalkan'])
            ->assertUnauthorized();
    }

    // --- Inti REV-FIN-02: periode benar-benar dibebaskan -------------------

    public function test_periode_yang_sudah_dibatalkan_bisa_diterbitkan_ulang(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/batal", ['alasan' => 'Salah tarif'])
            ->assertOk();

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', [
                'kontrak_id' => $tagihan->kontrak_id,
                'periode' => '2026-11-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'belum_bayar');

        // Dua baris untuk periode yang sama, satu batal satu hidup. Itu memang
        // yang diinginkan: yang batal adalah jejak, yang hidup adalah tagihan.
        $this->assertSame(2, Tagihan::query()
            ->where('kontrak_id', $tagihan->kontrak_id)
            ->whereDate('periode', '2026-11-01')
            ->count());
    }

    public function test_terbit_massal_melihat_periode_yang_dibatalkan_sebagai_bebas(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson("/api/tagihan/{$tagihan->id}/batal", ['alasan' => 'Salah tarif'])
            ->assertOk();

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.terbit', 1)
            ->assertJsonPath('data.dilewati_duplikat', 0);
    }

    public function test_terbit_massal_tetap_melewati_periode_yang_punya_tagihan_hidup(): void
    {
        // Pembalikan uji sebelumnya: index parsial tidak boleh melonggarkan
        // R-02. Satu tagihan hidup sudah cukup untuk melewati periode itu.
        $this->buatTagihan();

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertStatus(409)
            ->assertJsonPath('data.dilewati_duplikat', 1);
    }

    public function test_index_parsial_tetap_menolak_dua_tagihan_hidup(): void
    {
        $tagihan = $this->buatTagihan();

        // Dua tagihan hidup pada periode yang sama harus tetap ditolak basis
        // data. Kalau uji ini lulus tapi yang di atas gagal, berarti index
        // parsialnya salah tulis dan R-02 sudah hilang.
        $this->expectException(QueryException::class);

        DB::transaction(function () use ($tagihan) {
            Tagihan::create([
                'kontrak_id' => $tagihan->kontrak_id,
                'periode' => '2026-11-01',
                'pokok' => 1_500_000,
                'jatuh_tempo' => '2026-11-15',
                'status' => Tagihan::STATUS_BELUM_BAYAR,
                'tarif_terpakai' => ['prorata' => false],
                'kebijakan_terpakai' => ['denda' => ['tipe' => 'tanpa_denda']],
                'terbit_pada' => now(),
            ]);
        });
    }

    public function test_baris_batal_tanpa_alasan_ditolak_basis_data(): void
    {
        $tagihan = $this->buatTagihan();

        // CHECK `tagihan_batal_ada_alasan` menjaga jalur tulis mana pun,
        // termasuk UPDATE langsung yang tidak lewat endpoint.
        $this->expectException(QueryException::class);

        DB::transaction(function () use ($tagihan) {
            DB::table('tagihan')
                ->where('id', $tagihan->id)
                ->update(['status' => 'dibatalkan']);
        });
    }

    public function test_tagihan_tidak_ada_menghasilkan_404_bukan_403(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/999999/batal', ['alasan' => 'Tidak ada'])
            ->assertNotFound();
    }
}
