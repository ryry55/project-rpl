<?php

namespace Tests\Feature;

use App\Domain\Akses\Wewenang;
use App\Domain\Kebijakan\PilihanKebijakan;
use App\Models\Kamar;
use App\Models\KebijakanKos;
use App\Models\LogAudit;
use App\Models\PengelolaKewenangan;
use App\Models\TarifSewa;
use App\Models\User;
use App\Models\UsulanPerubahan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * T26 - persetujuan perubahan sensitif.
 *
 * Yang dibuktikan di sini bukan "usulan bisa disimpan". Yang dibuktikan adalah
 * pemisahan kewenangan itu **benar-benar memisahkan**:
 *
 *   1. Pengelola dengan `ubah_tarif` tidak bisa mengubah tarif. Titik. Bukan
 *      "bisa tapi tercatat" - tarifnya tetap angka lama sampai pemilik menekan
 *      Setujui, dan itu diuji dengan menghitung baris `tarif_sewa`.
 *
 *   2. Usulan tidak bisa diputuskan oleh orang yang mengusulkannya. Endpoint
 *      keputusan memakai `peran:pemilik`, bukan `wewenang:ubah_tarif`, karena
 *      memakai wewenang akan mengembalikan wewenang yang baru saja dipisahkan.
 *
 *   3. Persetujuan yang gagal tidak menutup usulannya. Kalau tanggal berlakunya
 *      sudah dipakai, usulan tetap `menunggu` - bukan ditutup sebagai "ditolak"
 *      dengan alasan yang tidak pernah dipilih siapa pun.
 *
 *   4. Kedaluwarsa dihitung, bukan dijadwalkan. Usulan yang lewat 7 hari
 *      ditolak saat diputuskan tanpa bergantung pada cron.
 *
 *   5. Dua baris audit, dua aktor. Satu baris akan berbohong ke arah mana pun:
 *      menyebut pemilik saja menghapus fakta ada usulan, menyebut pengelola
 *      saja menyatakan ia bertindak sendiri.
 */
class UsulanPersetujuanTest extends TestCase
{
    use RefreshDatabase;

    private User $pemilik;

    private User $pengelola;

    private Kamar $kamar;

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
            'email' => 'pengelola@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $this->pemilik->id,
        ]);

        $this->kamar = Kamar::create(['nomor' => 'A1']);
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    private function sebagaiPengelola(string ...$wewenang)
    {
        foreach ($wewenang as $w) {
            // firstOrCreate, bukan create: beberapa tes memberi wewenang dua
            // kali (satu di helper pengajuan, satu di tesnya sendiri), dan
            // unique (user_id, wewenang) akan menggagalkannya.
            PengelolaKewenangan::firstOrCreate([
                'user_id' => $this->pengelola->id,
                'wewenang' => $w,
            ]);
        }

        $this->pengelola->unsetRelation('kewenangan');

        return $this->actingAs($this->pengelola, 'sanctum');
    }

    /**
     * @param  array<string, mixed>  $ganti
     */
    private function ajukanTarif(array $ganti = []): UsulanPerubahan
    {
        $this->sebagaiPengelola(Wewenang::UBAH_TARIF)
            ->postJson('/api/tarif', $ganti + [
                'kamar_id' => $this->kamar->id,
                'nilai' => 1800000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertStatus(202);

        return UsulanPerubahan::latest('id')->firstOrFail();
    }

    // --- Pengajuan ---------------------------------------------------------

    public function test_pengelola_mengusulkan_tarif_tanpa_mengubah_tarif(): void
    {
        $usulan = $this->ajukanTarif();

        $this->assertSame(UsulanPerubahan::TIPE_TARIF, $usulan->tipe);
        $this->assertSame(UsulanPerubahan::STATUS_MENUNGGU, $usulan->status);
        $this->assertSame($this->pengelola->id, $usulan->diajukan_oleh);
        $this->assertNull($usulan->diputuskan_oleh);

        // Inti T26: nol baris tarif.
        $this->assertSame(0, TarifSewa::count());
    }

    public function test_ringkasan_dibekukan_saat_diajukan(): void
    {
        $usulan = $this->ajukanTarif();

        $this->assertStringContainsString('A1', $usulan->ringkasan);
        $this->assertStringContainsString('1.800.000', $usulan->ringkasan);
        $this->assertStringContainsString('1 November 2026', $usulan->ringkasan);

        // Kamar berganti nomor SETELAH usulan dibuat. Ringkasannya tidak boleh
        // ikut berubah: pemilik yang menyetujui "kamar A1" tidak boleh membaca
        // "kamar B3" di riwayat persetujuan.
        $this->kamar->update(['nomor' => 'B3']);

        $this->assertSame($usulan->ringkasan, $usulan->fresh()->ringkasan);
        $this->assertStringNotContainsString('B3', $usulan->fresh()->ringkasan);
    }

    public function test_pengelola_mengusulkan_kebijakan_tanpa_mengubah_kebijakan(): void
    {
        $this->sebagaiPengelola(Wewenang::UBAH_KEBIJAKAN)
            ->postJson('/api/kebijakan', [
                'tipe' => PilihanKebijakan::TIPE_DENDA,
                'nilai' => PilihanKebijakan::DENDA_PERSEN_HARIAN,
                'persen' => 1,
                'plafon_persen' => 30,
                'masa_tenggang' => 7,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertStatus(202)
            ->assertJsonPath('meta.diajukan', true);

        $this->assertSame(0, KebijakanKos::count());
        $this->assertSame(1, UsulanPerubahan::where('tipe', 'kebijakan')->count());
    }

    public function test_pemilik_tetap_menetapkan_langsung(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/tarif', [
                'kamar_id' => $this->kamar->id,
                'nilai' => 1800000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertCreated()
            ->assertJsonPath('meta.diajukan', false);

        $this->assertSame(1, TarifSewa::count());
        $this->assertSame(0, UsulanPerubahan::count());
    }

    public function test_pengelola_tanpa_wewenang_tetap_ditolak_saat_mengusulkan(): void
    {
        $this->sebagaiPengelola(Wewenang::KELOLA_KAMAR)
            ->postJson('/api/tarif', [
                'kamar_id' => $this->kamar->id,
                'nilai' => 1800000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertForbidden();

        $this->assertSame(0, UsulanPerubahan::count());
    }

    public function test_penghuni_tidak_bisa_mengusulkan(): void
    {
        $penghuni = User::create([
            'name' => 'Budi',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
        ]);

        $this->actingAs($penghuni, 'sanctum')
            ->postJson('/api/tarif', [
                'kamar_id' => $this->kamar->id,
                'nilai' => 1800000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertForbidden();
    }

    public function test_usulan_tidak_bisa_dipalsukan_lewat_payload_mentah(): void
    {
        // Kiriman dengan bentuk usulan tetapi tanpa `kamar_id` harus ditolak
        // validasi tarif - bukan diterima sebagai usulan bebas bentuk.
        $this->sebagaiPengelola(Wewenang::UBAH_TARIF)
            ->postJson('/api/tarif', [
                'nilai' => 1800000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertStatus(422);

        $this->assertSame(0, UsulanPerubahan::count());
    }

    // --- Persetujuan -------------------------------------------------------

    public function test_pemilik_menyetujui_lalu_tarif_baru_berlaku(): void
    {
        $usulan = $this->ajukanTarif();

        $this->sebagaiPemilik()
            ->postJson("/api/usulan/{$usulan->id}/setujui")
            ->assertOk()
            ->assertJsonPath('data.status', 'disetujui');

        $tarif = TarifSewa::sole();

        $this->assertSame(1800000, (int) $tarif->nilai);
        $this->assertSame('2026-11-01', $tarif->berlaku_dari->toDateString());

        $usulan->refresh();
        $this->assertSame(UsulanPerubahan::STATUS_DISETUJUI, $usulan->status);
        $this->assertSame($this->pemilik->id, $usulan->diputuskan_oleh);
        $this->assertNotNull($usulan->diputuskan_pada);
        // Persetujuan tidak boleh membawa alasan: kolom terisi akan terbaca
        // sebagai syarat oleh pembaca riwayat.
        $this->assertNull($usulan->alasan);
    }

    public function test_pemilik_menolak_wajib_beralasan(): void
    {
        $usulan = $this->ajukanTarif();

        $this->sebagaiPemilik()
            ->postJson("/api/usulan/{$usulan->id}/tolak", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('alasan');

        $this->assertSame(0, TarifSewa::count());
        $this->assertSame(UsulanPerubahan::STATUS_MENUNGGU, $usulan->fresh()->status);
    }

    public function test_pemilik_menolak_dengan_alasan(): void
    {
        $usulan = $this->ajukanTarif();

        $this->sebagaiPemilik()
            ->postJson("/api/usulan/{$usulan->id}/tolak", ['alasan' => 'Terlalu tinggi untuk kamar ini.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.alasan', 'Terlalu tinggi untuk kamar ini.');

        $this->assertSame(0, TarifSewa::count());
    }

    public function test_pengelola_tidak_bisa_menyetujui_usulannya_sendiri(): void
    {
        $usulan = $this->ajukanTarif();

        // Wewenang yang membuka pintu pengajuan tidak membuka pintu keputusan.
        // Kalau ini lolos, seluruh layar persetujuan menjadi formalitas.
        $this->sebagaiPengelola(Wewenang::UBAH_TARIF, Wewenang::UBAH_KEBIJAKAN)
            ->postJson("/api/usulan/{$usulan->id}/setujui")
            ->assertForbidden();

        $this->assertSame(0, TarifSewa::count());
        $this->assertSame(UsulanPerubahan::STATUS_MENUNGGU, $usulan->fresh()->status);
    }

    public function test_penghuni_tidak_bisa_menyetujui(): void
    {
        $usulan = $this->ajukanTarif();

        $penghuni = User::create([
            'name' => 'Budi',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
        ]);

        $this->actingAs($penghuni, 'sanctum')
            ->postJson("/api/usulan/{$usulan->id}/setujui")
            ->assertForbidden();
    }

    public function test_tanpa_login_ditolak(): void
    {
        $usulan = $this->ajukanTarif();

        // `actingAs` pada helper di atas menempel pada instance tes, jadi
        // sesinya harus dilepas dulu. Tanpa ini tes "tanpa login" sebenarnya
        // masih login dan lulus karena alasan yang salah.
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/usulan/{$usulan->id}/setujui")->assertUnauthorized();
    }

    public function test_usulan_yang_sudah_diputuskan_tidak_bisa_diputuskan_lagi(): void
    {
        $usulan = $this->ajukanTarif();

        $this->sebagaiPemilik()->postJson("/api/usulan/{$usulan->id}/setujui")->assertOk();

        // 409, bukan 422: kirimannya benar, keadaannya yang sudah berubah.
        $this->sebagaiPemilik()
            ->postJson("/api/usulan/{$usulan->id}/setujui")
            ->assertStatus(409);

        $this->sebagaiPemilik()
            ->postJson("/api/usulan/{$usulan->id}/tolak", ['alasan' => 'Berubah pikiran.'])
            ->assertStatus(409);

        $this->assertSame(1, TarifSewa::count());
    }

    // --- Kedaluwarsa -------------------------------------------------------

    public function test_usulan_kedaluwarsa_ditolak_saat_diputuskan(): void
    {
        $usulan = $this->ajukanTarif();

        // Dimundurkan tanpa menyentuh `status`. Kedaluwarsa dihitung dari
        // `kedaluwarsa_pada`, jadi tidak ada cron yang perlu dijalankan.
        $usulan->update(['kedaluwarsa_pada' => now()->subDay()]);

        $this->sebagaiPemilik()
            ->postJson("/api/usulan/{$usulan->id}/setujui")
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'lewat 7 hari'));

        $this->assertSame(0, TarifSewa::count());
        // Status kolomnya tetap `menunggu`: kedaluwarsa bukan nilai tersimpan.
        $this->assertSame(UsulanPerubahan::STATUS_MENUNGGU, $usulan->fresh()->status);
    }

    public function test_status_efektif_melaporkan_kedaluwarsa(): void
    {
        $usulan = $this->ajukanTarif();
        $usulan->update(['kedaluwarsa_pada' => now()->subDay()]);

        $this->sebagaiPemilik()
            ->getJson('/api/usulan')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'menunggu')
            ->assertJsonPath('data.0.status_efektif', 'kedaluwarsa')
            ->assertJsonPath('data.0.bisa_diputuskan', false);
    }

    public function test_penyaring_status_kedaluwarsa_tidak_mengembalikan_daftar_kosong(): void
    {
        $lewat = $this->ajukanTarif(['berlaku_dari' => '2026-11-01']);
        $lewat->update(['kedaluwarsa_pada' => now()->subDay()]);

        $masih = $this->ajukanTarif(['berlaku_dari' => '2026-12-01']);

        $this->sebagaiPemilik()
            ->getJson('/api/usulan?status=kedaluwarsa')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lewat->id);

        $this->sebagaiPemilik()
            ->getJson('/api/usulan?status=menunggu')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $masih->id);
    }

    // --- Persetujuan yang gagal -------------------------------------------

    public function test_persetujuan_yang_bentrok_tanggal_tetap_menunggu(): void
    {
        TarifSewa::create([
            'kamar_id' => $this->kamar->id,
            'nilai' => 1000000,
            'berlaku_dari' => '2026-11-01',
        ]);

        // Usulan dengan tanggal yang sudah dipakai. Unique index (kamar_id,
        // berlaku_dari) yang menang saat persetujuan diterapkan.
        $usulan = $this->ajukanTarif(['berlaku_dari' => '2026-11-02']);
        $usulan->update(['payload' => ['kamar_id' => $this->kamar->id, 'nilai' => 1800000, 'berlaku_dari' => '2026-11-01']]);

        $this->sebagaiPemilik()
            ->postJson("/api/usulan/{$usulan->id}/setujui")
            ->assertStatus(409);

        // Usulan TIDAK ditutup. Menutupnya sebagai "ditolak" akan menuliskan
        // alasan yang tidak pernah dipilih siapa pun, dan pengelola kehilangan
        // kesempatan mengajukan ulang dengan tanggal lain.
        $this->assertSame(UsulanPerubahan::STATUS_MENUNGGU, $usulan->fresh()->status);
        $this->assertNull($usulan->fresh()->alasan);
        $this->assertSame(1, TarifSewa::count());
    }

    // --- Audit -------------------------------------------------------------

    public function test_dua_baris_audit_dua_aktor(): void
    {
        $usulan = $this->ajukanTarif();

        $this->sebagaiPemilik()->postJson("/api/usulan/{$usulan->id}/setujui")->assertOk();

        $usul = LogAudit::where('aksi', 'tarif.usul')->sole();
        $this->assertSame($this->pengelola->id, $usul->aktor_id);
        $this->assertSame('usulan_perubahan', $usul->entitas);

        $setujui = LogAudit::where('aksi', 'tarif.setujui')->sole();
        $this->assertSame($this->pemilik->id, $setujui->aktor_id);
        $this->assertSame('tarif_sewa', $setujui->entitas);
        // Bukan `tarif.tambah`: pertanyaan yang dijawabnya berbeda, dan log
        // yang menyebutnya sama menghapus fakta ada usulan sebelumnya.
        $this->assertSame(0, LogAudit::where('aksi', 'tarif.tambah')->count());
    }

    public function test_penolakan_meninggalkan_jejak(): void
    {
        $usulan = $this->ajukanTarif();

        $this->sebagaiPemilik()
            ->postJson("/api/usulan/{$usulan->id}/tolak", ['alasan' => 'Terlalu tinggi.'])
            ->assertOk();

        $tolak = LogAudit::where('aksi', 'tarif.tolak')->sole();

        $this->assertSame($this->pemilik->id, $tolak->aktor_id);
        $this->assertSame('Terlalu tinggi.', $tolak->nilai_baru['alasan']);
    }

    // --- Daftar dan isolasi ------------------------------------------------

    public function test_pengelola_hanya_melihat_usulannya_sendiri(): void
    {
        $lain = User::create([
            'name' => 'Joko Pengelola',
            'email' => 'joko@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $this->pemilik->id,
        ]);

        $milikSendiri = $this->ajukanTarif();

        UsulanPerubahan::create([
            'tipe' => 'tarif',
            'payload' => ['kamar_id' => $this->kamar->id, 'nilai' => 1, 'berlaku_dari' => '2027-01-01'],
            'ringkasan' => 'Usulan orang lain.',
            'status' => 'menunggu',
            'diajukan_oleh' => $lain->id,
            'dibuat_pada' => now(),
            'kedaluwarsa_pada' => now()->addDays(7),
        ]);

        $this->sebagaiPengelola(Wewenang::UBAH_TARIF)
            ->getJson('/api/usulan')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $milikSendiri->id);

        // Pemilik melihat keduanya: dialah yang harus memutuskan.
        $this->sebagaiPemilik()
            ->getJson('/api/usulan')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_jumlah_menunggu_hanya_menghitung_yang_belum_kedaluwarsa(): void
    {
        $this->ajukanTarif(['berlaku_dari' => '2026-11-01']);

        $lewat = $this->ajukanTarif(['berlaku_dari' => '2026-12-01']);
        $lewat->update(['kedaluwarsa_pada' => now()->subDay()]);

        $this->sebagaiPemilik()
            ->getJson('/api/usulan')
            ->assertOk()
            ->assertJsonPath('meta.jumlah_menunggu', 1);
    }

    public function test_jumlah_menunggu_pengelola_hanya_menghitung_miliknya(): void
    {
        $lain = User::create([
            'name' => 'Joko Pengelola',
            'email' => 'joko@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $this->pemilik->id,
        ]);

        $this->ajukanTarif();

        UsulanPerubahan::create([
            'tipe' => 'tarif',
            'payload' => ['kamar_id' => $this->kamar->id, 'nilai' => 1, 'berlaku_dari' => '2027-01-01'],
            'ringkasan' => 'Usulan orang lain.',
            'status' => 'menunggu',
            'diajukan_oleh' => $lain->id,
            'dibuat_pada' => now(),
            'kedaluwarsa_pada' => now()->addDays(7),
        ]);

        // Angka seluruh tabel akan membocorkan berapa usulan rekan lain yang
        // sedang menunggu, dan layar pengelola akan menulis "menunggu keputusan
        // Anda" untuk sesuatu yang bukan miliknya.
        $this->sebagaiPengelola(Wewenang::UBAH_TARIF)
            ->getJson('/api/usulan')
            ->assertOk()
            ->assertJsonPath('meta.jumlah_menunggu', 1);

        $this->sebagaiPemilik()
            ->getJson('/api/usulan')
            ->assertOk()
            ->assertJsonPath('meta.jumlah_menunggu', 2);
    }

    // --- Bentuk basis data -------------------------------------------------

    public function test_status_kedaluwarsa_tidak_bisa_disimpan(): void
    {
        $this->expectException(QueryException::class);

        UsulanPerubahan::create([
            'tipe' => 'tarif',
            'payload' => [],
            'ringkasan' => 'Percobaan.',
            'status' => 'kedaluwarsa',
            'diajukan_oleh' => $this->pengelola->id,
            'dibuat_pada' => now(),
            'kedaluwarsa_pada' => now()->addDays(7),
        ]);
    }

    public function test_penolakan_tanpa_alasan_ditolak_basis_data(): void
    {
        $this->expectException(QueryException::class);

        UsulanPerubahan::create([
            'tipe' => 'tarif',
            'payload' => [],
            'ringkasan' => 'Percobaan.',
            'status' => 'ditolak',
            'diajukan_oleh' => $this->pengelola->id,
            'diputuskan_oleh' => $this->pemilik->id,
            'diputuskan_pada' => now(),
            'dibuat_pada' => now(),
            'kedaluwarsa_pada' => now()->addDays(7),
        ]);
    }

    public function test_diputuskan_tanpa_pemutus_ditolak_basis_data(): void
    {
        $this->expectException(QueryException::class);

        UsulanPerubahan::create([
            'tipe' => 'tarif',
            'payload' => [],
            'ringkasan' => 'Percobaan.',
            'status' => 'disetujui',
            'diajukan_oleh' => $this->pengelola->id,
            'dibuat_pada' => now(),
            'kedaluwarsa_pada' => now()->addDays(7),
        ]);
    }

    public function test_menunggu_tidak_boleh_menyebut_pemutus(): void
    {
        $this->expectException(QueryException::class);

        UsulanPerubahan::create([
            'tipe' => 'tarif',
            'payload' => [],
            'ringkasan' => 'Percobaan.',
            'status' => 'menunggu',
            'diajukan_oleh' => $this->pengelola->id,
            'diputuskan_oleh' => $this->pemilik->id,
            'dibuat_pada' => now(),
            'kedaluwarsa_pada' => now()->addDays(7),
        ]);
    }

    public function test_usulan_tidak_append_only(): void
    {
        // Berbeda dari `pembayaran`: usulan adalah barang antrean, dan seluruh
        // gunanya memang untuk diselesaikan. Bukti siapa memutuskan apa tetap
        // ada di `log_audit`.
        $usulan = $this->ajukanTarif();

        $usulan->update([
            'status' => UsulanPerubahan::STATUS_DITOLAK,
            'alasan' => 'Uji.',
            'diputuskan_oleh' => $this->pemilik->id,
            'diputuskan_pada' => now(),
        ]);

        $this->assertSame(UsulanPerubahan::STATUS_DITOLAK, $usulan->fresh()->status);
    }

    public function test_menghapus_akun_pengusul_ditolak_basis_data(): void
    {
        $this->ajukanTarif();

        // RESTRICT, bukan SET NULL: usulan adalah bukti siapa mengusulkan
        // perubahan angka uang. Mengosongkan kolomnya menghasilkan baris yang
        // tidak bisa dipertanggungjawabkan.
        $this->expectException(QueryException::class);

        DB::transaction(fn () => $this->pengelola->delete());
    }
}
