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
use App\Models\VerifikasiPembayaran;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\MenyiapkanKebijakan;
use Tests\TestCase;

/**
 * T20 - verifikasi pembayaran.
 *
 * Yang menentukan kelas ini benar: **baris terakhir yang berlaku.** Membaca
 * "apakah pernah ada baris terima" akan membuat pembayaran yang sudah terbukti
 * salah tetap berstatus terverifikasi selamanya - dan uang yang diketahui salah
 * tidak bisa ditarik kembali statusnya.
 */
class VerifikasiPembayaranTest extends TestCase
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
        $this->pengelola->kewenangan()->create(['wewenang' => 'catat_pembayaran']);
        $this->pengelola->load('kewenangan');

        $this->siapkanKebijakanDenda();
    }

    private function buatPembayaran(): Pembayaran
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

        $tagihan = Tagihan::create([
            'kontrak_id' => $kontrak->id,
            'periode' => '2026-11-01',
            'pokok' => 1_500_000,
            'jatuh_tempo' => '2026-11-15',
            'status' => Tagihan::STATUS_SEBAGIAN,
            'tarif_terpakai' => ['prorata' => false],
            'kebijakan_terpakai' => ['denda' => ['tipe' => 'tanpa_denda']],
            'terbit_pada' => now(),
        ]);

        return Pembayaran::create([
            'tagihan_id' => $tagihan->id,
            'jumlah' => 500_000,
            'metode' => 'tunai',
            'sumber' => 'dicatat_pemilik',
            'dicatat_oleh' => $this->pengelola->id,
            'diterima_oleh' => $this->pengelola->id,
            'tanggal_pembayaran' => '2026-11-10',
        ]);
    }

    private function verifikasi(Pembayaran $pembayaran, array $isi)
    {
        return $this->actingAs($this->pemilik, 'sanctum')
            ->postJson("/api/pembayaran/{$pembayaran->id}/verifikasi", $isi);
    }

    // --- Keputusan ---------------------------------------------------------

    public function test_penerimaan_mencatat_keputusan_terima(): void
    {
        $pembayaran = $this->buatPembayaran();

        $this->verifikasi($pembayaran, ['keputusan' => 'terima'])
            ->assertCreated()
            ->assertJsonPath('data.verifikasi.keputusan', 'terima')
            ->assertJsonPath('data.pembayaran.verifikasi_terakhir.keputusan', 'terima');

        $this->assertSame(1, VerifikasiPembayaran::count());
    }

    public function test_penolakan_wajib_beralasan(): void
    {
        $pembayaran = $this->buatPembayaran();

        $this->verifikasi($pembayaran, ['keputusan' => 'tolak'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('catatan');

        // Tanpa alasan, pengelola yang mencatat pembayaran tidak tahu apa yang
        // harus diperbaiki, dan ia akan mencatat ulang baris yang sama -
        // sehingga uangnya tercatat dua kali.
        $this->assertSame(0, VerifikasiPembayaran::count());
    }

    public function test_penolakan_dengan_alasan_tersimpan(): void
    {
        $pembayaran = $this->buatPembayaran();

        $this->verifikasi($pembayaran, [
            'keputusan' => 'tolak',
            'catatan' => 'Bukti transfer buram, tidak terbaca.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.verifikasi.keputusan', 'tolak');
    }

    public function test_keputusan_selain_terima_dan_tolak_ditolak(): void
    {
        $pembayaran = $this->buatPembayaran();

        $this->verifikasi($pembayaran, ['keputusan' => 'mungkin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('keputusan');
    }

    // --- Baris terakhir yang berlaku ---------------------------------------

    public function test_pemeriksaan_ulang_menambah_baris_bukan_menimpa(): void
    {
        $pembayaran = $this->buatPembayaran();

        $this->verifikasi($pembayaran, ['keputusan' => 'terima'])->assertCreated();
        $this->verifikasi($pembayaran, [
            'keputusan' => 'tolak',
            'catatan' => 'Ternyata setorannya tidak masuk rekening.',
        ])->assertCreated();

        // Dua baris: pernah diterima, lalu ditolak. Menimpa baris pertama akan
        // menghapus fakta bahwa pemeriksaan pertama pernah menyatakan cocok -
        // dan itu justru yang ditanyakan saat uangnya tidak bisa ditemukan.
        $this->assertSame(2, VerifikasiPembayaran::count());
    }

    public function test_yang_berlaku_adalah_keputusan_terakhir(): void
    {
        $pembayaran = $this->buatPembayaran();

        $this->verifikasi($pembayaran, ['keputusan' => 'terima'])->assertCreated();
        $this->verifikasi($pembayaran, [
            'keputusan' => 'tolak',
            'catatan' => 'Salah orang.',
        ])->assertCreated();

        $pembayaran->refresh()->load('verifikasi');

        // Bukan "pernah ada baris terima", melainkan baris terakhir. Membaca
        // dengan cara pertama akan membuat penolakan tidak pernah berlaku.
        $this->assertSame(VerifikasiPembayaran::KEPUTUSAN_TOLAK, $pembayaran->statusVerifikasi());
    }

    public function test_tolak_lalu_terima_berakhir_terima(): void
    {
        $pembayaran = $this->buatPembayaran();

        $this->verifikasi($pembayaran, [
            'keputusan' => 'tolak',
            'catatan' => 'Bukti kurang jelas.',
        ])->assertCreated();
        $this->verifikasi($pembayaran, ['keputusan' => 'terima'])->assertCreated();

        $pembayaran->refresh()->load('verifikasi');

        $this->assertSame(VerifikasiPembayaran::KEPUTUSAN_TERIMA, $pembayaran->statusVerifikasi());
    }

    public function test_belum_diperiksa_berbeda_dari_ditolak(): void
    {
        $pembayaran = $this->buatPembayaran();
        $pembayaran->load('verifikasi');

        // `null`, bukan 'tolak' maupun 'terima'. Layar yang membedakan
        // "belum diperiksa" dari "diterima" hanya bisa melakukannya kalau
        // server membedakannya lebih dulu.
        $this->assertNull($pembayaran->statusVerifikasi());
    }

    // --- Audit -------------------------------------------------------------

    public function test_verifikasi_tercatat_di_log_audit(): void
    {
        $pembayaran = $this->buatPembayaran();

        $this->verifikasi($pembayaran, ['keputusan' => 'terima'])->assertCreated();

        $log = LogAudit::where('aksi', PencatatAudit::AKSI_PEMBAYARAN_VERIFIKASI)->firstOrFail();

        $this->assertSame('pembayaran', $log->entitas);
        $this->assertSame($pembayaran->id, $log->entitas_id);
        $this->assertSame($this->pemilik->id, $log->aktor_id);
        $this->assertNull($log->nilai_lama['verifikasi']);
        $this->assertSame('terima', $log->nilai_baru['verifikasi']);
    }

    public function test_pemeriksaan_kedua_mencatat_nilai_lama(): void
    {
        $pembayaran = $this->buatPembayaran();

        $this->verifikasi($pembayaran, ['keputusan' => 'terima'])->assertCreated();
        $this->verifikasi($pembayaran, [
            'keputusan' => 'tolak',
            'catatan' => 'Koreksi.',
        ])->assertCreated();

        $log = LogAudit::where('aksi', PencatatAudit::AKSI_PEMBAYARAN_VERIFIKASI)
            ->orderByDesc('id')
            ->firstOrFail();

        // "Dari terima menjadi tolak" hanya bisa dijawab kalau nilai lamanya
        // ikut tercatat. Tanpa itu jejaknya hanya mengatakan "tolak" tanpa
        // menjelaskan bahwa sebelumnya pernah dinyatakan cocok.
        $this->assertSame('terima', $log->nilai_lama['verifikasi']);
        $this->assertSame('tolak', $log->nilai_baru['verifikasi']);
    }

    // --- Penegakan di basis data -------------------------------------------

    public function test_verifikasi_menolak_perubahan(): void
    {
        $pembayaran = $this->buatPembayaran();
        $this->verifikasi($pembayaran, ['keputusan' => 'terima'])->assertCreated();

        $this->expectException(QueryException::class);

        DB::statement("UPDATE verifikasi_pembayaran SET keputusan = 'tolak'");
    }

    public function test_verifikasi_menolak_penghapusan(): void
    {
        $pembayaran = $this->buatPembayaran();
        $this->verifikasi($pembayaran, ['keputusan' => 'terima'])->assertCreated();

        $this->expectException(QueryException::class);

        DB::statement('DELETE FROM verifikasi_pembayaran');
    }

    public function test_penolakan_tanpa_alasan_ditolak_basis_data(): void
    {
        $pembayaran = $this->buatPembayaran();

        $this->expectException(QueryException::class);

        // CHECK constraint, bukan hanya validasi HTTP: jalur tulis lain
        // (seeder, artisan, SQL langsung) tidak melewati FormRequest.
        DB::table('verifikasi_pembayaran')->insert([
            'pembayaran_id' => $pembayaran->id,
            'keputusan' => 'tolak',
            'catatan' => null,
            'created_at' => now(),
        ]);
    }

    // --- Wewenang ----------------------------------------------------------

    public function test_pengelola_tanpa_verifikasi_ditolak(): void
    {
        $pembayaran = $this->buatPembayaran();

        $this->actingAs($this->pengelola, 'sanctum')
            ->postJson("/api/pembayaran/{$pembayaran->id}/verifikasi", ['keputusan' => 'terima'])
            ->assertForbidden();
    }

    public function test_pengelola_dengan_verifikasi_boleh(): void
    {
        $pembayaran = $this->buatPembayaran();

        $this->pengelola->kewenangan()->create(['wewenang' => 'verifikasi_pembayaran']);
        $this->pengelola->load('kewenangan');

        $this->actingAs($this->pengelola, 'sanctum')
            ->postJson("/api/pembayaran/{$pembayaran->id}/verifikasi", ['keputusan' => 'terima'])
            ->assertCreated();
    }

    public function test_penghuni_tidak_boleh_memverifikasi(): void
    {
        $pembayaran = $this->buatPembayaran();

        $penghuni = User::create([
            'name' => 'Budi',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
        ]);

        $this->actingAs($penghuni, 'sanctum')
            ->postJson("/api/pembayaran/{$pembayaran->id}/verifikasi", ['keputusan' => 'terima'])
            ->assertForbidden();
    }

    public function test_tanpa_login_ditolak(): void
    {
        $pembayaran = $this->buatPembayaran();

        $this->postJson("/api/pembayaran/{$pembayaran->id}/verifikasi", ['keputusan' => 'terima'])
            ->assertUnauthorized();
    }

    public function test_pembayaran_tidak_ada_menghasilkan_404(): void
    {
        $this->actingAs($this->pemilik, 'sanctum')
            ->postJson('/api/pembayaran/999999/verifikasi', ['keputusan' => 'terima'])
            ->assertNotFound();
    }
}
