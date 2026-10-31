<?php

namespace Tests\Feature;

use App\Domain\Akses\Wewenang;
use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\LaporanKerusakan;
use App\Models\LogAudit;
use App\Models\PengelolaKewenangan;
use App\Models\Penghuni;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * T14 - laporan kerusakan kamar dan status `perbaikan`.
 *
 * Yang dijaga bukan hanya CRUD-nya, melainkan **empat keputusan** yang
 * diambil pemilik 31 Oktober 2026:
 *
 * 1. kamar `perbaikan` tetap ditagih - jadi tidak ada satu pun tes di sini
 *    yang boleh melihat tagihan berubah karena laporan kerusakan;
 * 2. pemilik DAN pengelola boleh menutup laporan (wewenang `kelola_kamar`);
 * 3. biaya perbaikan tidak pernah masuk tagihan - sebab itu tabelnya tidak
 *    punya kolom nominal, dan tidak ada jalur uang yang dibuka di sini;
 * 4. status kamar setelah perbaikan dihitung dari kontraknya, bukan
 *    dikembalikan ke nilai sebelum perbaikan.
 */
class KerusakanTest extends TestCase
{
    use RefreshDatabase;

    private User $pemilik;

    private User $pengelola;

    private Kamar $kamar;

    private Penghuni $penghuni;

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
            'email_verified_at' => now(),
        ]);

        $this->penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'email' => 'budi@kos.test']);

        $this->kamar = Kamar::create(['nomor' => 'A1', 'tipe' => 'Standar']);
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    private function sebagaiPengelola(string ...$wewenang)
    {
        foreach ($wewenang as $w) {
            PengelolaKewenangan::create([
                'user_id' => $this->pengelola->id,
                'wewenang' => $w,
            ]);
        }

        $this->pengelola->unsetRelation('kewenangan');

        return $this->actingAs($this->pengelola, 'sanctum');
    }

    private function laporkan(string $deskripsi = 'Plafon kamar mandi bocor'): LaporanKerusakan
    {
        $this->sebagaiPemilik()
            ->postJson('/api/laporan-kerusakan', [
                'kamar_id' => $this->kamar->id,
                'deskripsi' => $deskripsi,
            ])
            ->assertCreated();

        return LaporanKerusakan::latest('id')->firstOrFail();
    }

    private function kontrakAktif(): Kontrak
    {
        $kontrak = Kontrak::create([
            'kamar_id' => $this->kamar->id,
            'penghuni_id' => $this->penghuni->id,
            'tgl_masuk' => '2026-10-01',
            'tarif_awal' => 1500000,
            'status' => 'aktif',
        ]);

        $this->kamar->update(['status_huni' => 'terisi']);

        return $kontrak;
    }

    // --- Melaporkan --------------------------------------------------------

    public function test_pemilik_melaporkan_kerusakan_dan_kamar_menjadi_perbaikan(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/laporan-kerusakan', [
                'kamar_id' => $this->kamar->id,
                'deskripsi' => 'Plafon kamar mandi bocor',
            ])
            ->assertCreated()
            ->assertJsonPath('data.kamar.nomor', 'A1')
            ->assertJsonPath('data.deskripsi', 'Plafon kamar mandi bocor')
            ->assertJsonPath('data.pelapor', 'Pemilik Kos')
            ->assertJsonPath('data.selesai_pada', null);

        $this->assertSame('perbaikan', $this->kamar->fresh()->status_huni);
    }

    public function test_laporan_meninggalkan_jejak_audit_pada_kamar(): void
    {
        $laporan = $this->laporkan();

        $jejak = LogAudit::where('aksi', 'kerusakan.lapor')->firstOrFail();

        $this->assertSame('kamar', $jejak->entitas);
        $this->assertSame($this->kamar->id, $jejak->entitas_id);
        $this->assertSame(['status_huni' => 'kosong'], $jejak->nilai_lama);
        $this->assertSame('perbaikan', $jejak->nilai_baru['status_huni']);
        $this->assertSame($laporan->id, $jejak->nilai_baru['laporan_id']);
        $this->assertSame('Pemilik Kos', $jejak->aktor_nama);
    }

    public function test_laporan_kedua_untuk_kamar_yang_sama_ditolak(): void
    {
        $this->laporkan();

        $this->sebagaiPemilik()
            ->postJson('/api/laporan-kerusakan', [
                'kamar_id' => $this->kamar->id,
                'deskripsi' => 'Kran wastafel menetes',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.kamar_id.0', 'Kamar ini masih punya laporan kerusakan terbuka.');

        $this->assertSame(1, LaporanKerusakan::count());
    }

    public function test_deskripsi_wajib_dan_tidak_boleh_terlalu_pendek(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/laporan-kerusakan', ['kamar_id' => $this->kamar->id])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['deskripsi']]);

        $this->sebagaiPemilik()
            ->postJson('/api/laporan-kerusakan', ['kamar_id' => $this->kamar->id, 'deskripsi' => 'x'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['deskripsi']]);
    }

    public function test_kamar_tidak_dikenal_ditolak_422(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/laporan-kerusakan', ['kamar_id' => 9999, 'deskripsi' => 'Bocor'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['kamar_id']]);
    }

    // --- Menutup -----------------------------------------------------------

    public function test_menutup_laporan_mengembalikan_kamar_ke_kosong(): void
    {
        $laporan = $this->laporkan();

        $this->sebagaiPemilik()
            ->postJson("/api/laporan-kerusakan/{$laporan->id}/selesai")
            ->assertOk()
            ->assertJsonPath('data.penutup', 'Pemilik Kos');

        $this->assertSame('kosong', $this->kamar->fresh()->status_huni);
        $this->assertNotNull($laporan->fresh()->selesai_pada);
        $this->assertSame($this->pemilik->id, $laporan->fresh()->ditutup_oleh);
    }

    /**
     * Status setelah perbaikan dihitung dari kontraknya. Kalau kamar masih
     * dihuni, mengembalikannya ke `kosong` akan membuatnya muncul di daftar
     * pilihan kontrak dan bisa disewa orang kedua.
     */
    public function test_menutup_laporan_pada_kamar_berpenghuni_mengembalikan_terisi(): void
    {
        $this->kontrakAktif();
        $laporan = $this->laporkan();

        $this->assertSame('perbaikan', $this->kamar->fresh()->status_huni);

        $this->sebagaiPemilik()
            ->postJson("/api/laporan-kerusakan/{$laporan->id}/selesai")
            ->assertOk();

        $this->assertSame('terisi', $this->kamar->fresh()->status_huni);
    }

    public function test_menutup_laporan_pada_kamar_yang_sudah_dipesan_mengembalikan_dipesan(): void
    {
        Kontrak::create([
            'kamar_id' => $this->kamar->id,
            'penghuni_id' => $this->penghuni->id,
            'tgl_masuk' => '2026-12-01',
            'tarif_awal' => 1500000,
            'status' => 'dipesan',
        ]);
        $this->kamar->update(['status_huni' => 'perbaikan']);
        $laporan = $this->laporkan();

        $this->sebagaiPemilik()
            ->postJson("/api/laporan-kerusakan/{$laporan->id}/selesai")
            ->assertOk();

        $this->assertSame('dipesan', $this->kamar->fresh()->status_huni);
    }

    public function test_menutup_laporan_dua_kali_ditolak_422(): void
    {
        $laporan = $this->laporkan();

        $this->sebagaiPemilik()
            ->postJson("/api/laporan-kerusakan/{$laporan->id}/selesai")
            ->assertOk();

        $this->sebagaiPemilik()
            ->postJson("/api/laporan-kerusakan/{$laporan->id}/selesai")
            ->assertStatus(422);
    }

    // --- Wewenang ----------------------------------------------------------

    public function test_pengelola_dengan_kelola_kamar_boleh_melaporkan_dan_menutup(): void
    {
        $this->sebagaiPengelola(Wewenang::KELOLA_KAMAR)
            ->postJson('/api/laporan-kerusakan', [
                'kamar_id' => $this->kamar->id,
                'deskripsi' => 'Jendela kamar pecah',
            ])
            ->assertCreated()
            ->assertJsonPath('data.pelapor', 'Sari Pengelola');

        $laporan = LaporanKerusakan::latest('id')->firstOrFail();

        // Wevenang sudah ditulis di atas; relasi di memori dibuang lagi supaya
        // middleware membaca baris yang baru.
        $this->pengelola->unsetRelation('kewenangan');

        $this->actingAs($this->pengelola, 'sanctum')
            ->postJson("/api/laporan-kerusakan/{$laporan->id}/selesai")
            ->assertOk()
            ->assertJsonPath('data.penutup', 'Sari Pengelola');

        $this->assertSame('kosong', $this->kamar->fresh()->status_huni);
    }

    public function test_pengelola_tanpa_wewenang_kelola_kamar_ditolak(): void
    {
        $this->sebagaiPengelola(Wewenang::CATAT_PEMBAYARAN)
            ->postJson('/api/laporan-kerusakan', [
                'kamar_id' => $this->kamar->id,
                'deskripsi' => 'Jendela pecah',
            ])
            ->assertForbidden();
    }

    public function test_penghuni_tidak_boleh_melaporkan_kerusakan(): void
    {
        $akun = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $this->penghuni->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($akun, 'sanctum')
            ->postJson('/api/laporan-kerusakan', [
                'kamar_id' => $this->kamar->id,
                'deskripsi' => 'Jendela pecah',
            ])
            ->assertForbidden();
    }

    // --- Pagar di jalur lain -----------------------------------------------

    /**
     * Pemeriksaan `exists()` di controller punya celah balapan: dua permintaan
     * bersamaan bisa sama-sama lolos. Yang benar-benar menjaga adalah indeks
     * unik parsial, dan tes ini menembaknya langsung - tanpa lewat HTTP - supaya
     * yang diuji memang basis datanya, bukan pemeriksaan di atasnya.
     */
    public function test_basis_data_menolak_dua_laporan_terbuka_untuk_satu_kamar(): void
    {
        $this->laporkan();

        $this->expectException(QueryException::class);

        LaporanKerusakan::create([
            'kamar_id' => $this->kamar->id,
            'deskripsi' => 'Laporan kedua tanpa lewat HTTP',
        ]);
    }

    public function test_kamar_yang_sedang_diperbaiki_tidak_bisa_disewakan(): void
    {
        $this->laporkan();

        $this->sebagaiPemilik()
            ->postJson('/api/kontrak', [
                'kamar_id' => $this->kamar->id,
                'penghuni_id' => $this->penghuni->id,
                'tgl_masuk' => '2026-11-01',
                'tarif' => 1500000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.kamar_id.0', 'Kamar sedang diperbaiki.');

        $this->assertSame(0, Kontrak::count());
    }

    public function test_mengakhiri_kontrak_tidak_menghapus_status_perbaikan(): void
    {
        $kontrak = $this->kontrakAktif();
        $this->laporkan();

        $this->assertSame('perbaikan', $this->kamar->fresh()->status_huni);

        $this->sebagaiPemilik()
            ->postJson("/api/kontrak/{$kontrak->id}/akhiri")
            ->assertOk();

        $this->assertSame('perbaikan', $this->kamar->fresh()->status_huni);
    }

    public function test_status_kamar_tidak_bisa_diubah_tangan_saat_laporan_terbuka(): void
    {
        $this->laporkan();

        $this->sebagaiPemilik()
            ->putJson("/api/kamar/{$this->kamar->id}", [
                'nomor' => 'A1',
                'status_huni' => 'terisi',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.status_huni.0', 'Kamar sedang diperbaiki.');

        $this->assertSame('perbaikan', $this->kamar->fresh()->status_huni);
    }

    // --- Pembacaan ---------------------------------------------------------

    public function test_daftar_kamar_menyertakan_alasan_perbaikan(): void
    {
        $this->laporkan('Keramik kamar mandi retak');

        $this->sebagaiPemilik()
            ->getJson('/api/kamar')
            ->assertOk()
            ->assertJsonPath('data.0.status_huni', 'perbaikan')
            ->assertJsonPath('data.0.laporan_terbuka.deskripsi', 'Keramik kamar mandi retak');
    }

    public function test_daftar_laporan_bisa_disaring_terbuka_dan_selesai(): void
    {
        $lama = $this->laporkan('Kran bocor');
        $this->sebagaiPemilik()->postJson("/api/laporan-kerusakan/{$lama->id}/selesai")->assertOk();

        $kamar2 = Kamar::create(['nomor' => 'A2', 'tipe' => 'Standar']);
        $this->sebagaiPemilik()
            ->postJson('/api/laporan-kerusakan', ['kamar_id' => $kamar2->id, 'deskripsi' => 'Lampu mati'])
            ->assertCreated();

        $this->sebagaiPemilik()
            ->getJson('/api/laporan-kerusakan?status=terbuka')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.deskripsi', 'Lampu mati');

        $this->sebagaiPemilik()
            ->getJson('/api/laporan-kerusakan?status=selesai')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.deskripsi', 'Kran bocor');
    }
}
