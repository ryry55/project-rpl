<?php

namespace Tests\Feature;

use App\Domain\Akses\Wewenang;
use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\LogAudit;
use App\Models\PengelolaKewenangan;
use App\Models\Penghuni;
use App\Models\Perangkat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * K-16 - kredensial perangkat kos.
 *
 * Empat hal yang diuji di sini, dan masing-masing bisa gagal tanpa satu pun
 * galat terlihat:
 *
 *   1. **Sandi benar-benar terenkripsi di basis data.** `encrypted` cast yang
 *      lupa dipasang tetap menyimpan teks biasa, dan semuanya tampak berjalan.
 *      Yang diperiksa karena itu isi kolomnya, bukan nilai dari model.
 *   2. **Sandi tidak ikut di daftar.** Daftar dibuka setiap kali halaman
 *      diragukan isinya; sandi CCTV yang melintas di sana terbaca siapa pun
 *      yang melihat layarnya.
 *   3. **Penghuni hanya melihat WiFi yang aktif.** CCTV dan RFID adalah
 *      kredensial pengurus kos: nomor kartu yang bocor bisa dipakai masuk.
 *   4. **Log audit tidak pernah memuat sandi.** Log dibaca manusia dan disimpan
 *      lama; sandi di dalamnya membatalkan seluruh gunanya enkripsi.
 */
class PerangkatTest extends TestCase
{
    use RefreshDatabase;

    private User $pemilik;

    private User $pengelola;

    private User $akunPenghuni;

    private Penghuni $penghuni;

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
            'name' => 'Pengelola Kos',
            'email' => 'pengelola@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $this->pemilik->id,
            'email_verified_at' => now(),
        ]);

        $this->penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'email' => 'budi@kos.test']);

        $this->akunPenghuni = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $this->penghuni->id,
        ]);

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

    private function sebagaiPenghuni()
    {
        return $this->actingAs($this->akunPenghuni, 'sanctum');
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

    private function wifi(array $ubah = []): Perangkat
    {
        return Perangkat::create(array_merge([
            'jenis' => Perangkat::JENIS_WIFI,
            'nama' => 'WiFi Lantai 1',
            'lokasi' => 'Dekat tangga',
            'nama_pengguna' => 'KOS-LANTAI-1',
            'sandi' => 'rahasia-wifi',
            'aktif' => true,
        ], $ubah));
    }

    // --- Mengelola ---------------------------------------------------------

    public function test_pemilik_menambah_perangkat_wifi(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/perangkat', [
                'jenis' => 'wifi',
                'nama' => 'WiFi Lantai 2',
                'lokasi' => 'Dekat jemuran',
                'nama_pengguna' => 'KOS-LANTAI-2',
                'sandi' => 'sandi-baru',
            ])
            ->assertCreated()
            ->assertJsonPath('data.nama', 'WiFi Lantai 2')
            ->assertJsonPath('data.ada_sandi', true)
            ->assertJsonMissingPath('data.sandi');
    }

    public function test_pengelola_dengan_kelola_kamar_boleh_menambah(): void
    {
        $this->sebagaiPengelola(Wewenang::KELOLA_KAMAR)
            ->postJson('/api/perangkat', [
                'jenis' => 'cctv',
                'nama' => 'CCTV Gerbang',
                'sandi' => 'cctv-gerbang',
            ])
            ->assertCreated()
            ->assertJsonPath('data.jenis', 'cctv');
    }

    public function test_pengelola_tanpa_kelola_kamar_ditolak(): void
    {
        $this->sebagaiPengelola(Wewenang::CATAT_PEMBAYARAN)
            ->postJson('/api/perangkat', ['nama' => 'WiFi Lantai 3'])
            ->assertForbidden();

        $this->assertSame(0, Perangkat::count());
    }

    public function test_penghuni_tidak_boleh_menambah_perangkat(): void
    {
        $this->sebagaiPenghuni()
            ->postJson('/api/perangkat', ['nama' => 'WiFi Palsu'])
            ->assertForbidden();

        $this->assertSame(0, Perangkat::count());
    }

    public function test_jenis_di_luar_daftar_ditolak(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/perangkat', ['jenis' => 'printer', 'nama' => 'Printer'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('jenis');

        $this->assertSame(0, Perangkat::count());
    }

    // --- Enkripsi ----------------------------------------------------------

    public function test_sandi_tersimpan_terenkripsi_di_basis_data(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/perangkat', ['nama' => 'WiFi Lantai 1', 'sandi' => 'rahasia-wifi'])
            ->assertCreated();

        $mentah = DB::table('perangkat')->value('sandi');

        // Yang diuji isi kolomnya, bukan nilai model: cast yang lupa dipasang
        // tetap mengembalikan nilai yang benar lewat model, dan hanya isi
        // tabelnya yang membuktikan enkripsinya bekerja.
        $this->assertNotSame('rahasia-wifi', $mentah);
        $this->assertStringNotContainsString('rahasia-wifi', (string) $mentah);

        // Dan tetap bisa dibaca kembali lewat model - enkripsi yang tidak bisa
        // didekripsi sama saja dengan sandi yang hilang.
        $this->assertSame('rahasia-wifi', Perangkat::firstOrFail()->sandi);
    }

    public function test_sandi_tidak_ikut_di_daftar_perangkat(): void
    {
        $this->wifi();

        $respons = $this->sebagaiPemilik()->getJson('/api/perangkat')->assertOk();

        $this->assertSame(true, $respons->json('data.0.ada_sandi'));
        $this->assertArrayNotHasKey('sandi', $respons->json('data.0'));
    }

    public function test_sandi_hanya_dikirim_saat_diminta(): void
    {
        $perangkat = $this->wifi();

        $this->sebagaiPemilik()
            ->getJson('/api/perangkat/'.$perangkat->id.'/sandi')
            ->assertOk()
            ->assertJsonPath('data.sandi', 'rahasia-wifi');
    }

    public function test_mengubah_tanpa_sandi_tidak_menghapus_sandi(): void
    {
        $perangkat = $this->wifi();

        $this->sebagaiPemilik()
            ->putJson('/api/perangkat/'.$perangkat->id, [
                'nama' => 'WiFi Lantai 1',
                'lokasi' => 'Dekat dapur',
            ])
            ->assertOk()
            ->assertJsonPath('data.ada_sandi', true);

        $this->assertSame('rahasia-wifi', $perangkat->fresh()->sandi);
    }

    public function test_sandi_tidak_masuk_log_audit(): void
    {
        $perangkat = $this->wifi();

        $this->sebagaiPemilik()
            ->putJson('/api/perangkat/'.$perangkat->id, [
                'nama' => 'WiFi Lantai 1',
                'sandi' => 'sandi-paling-baru',
            ])
            ->assertOk();

        $log = LogAudit::where('entitas', 'perangkat')->get();

        $this->assertGreaterThan(0, $log->count());
        foreach ($log as $baris) {
            $isi = json_encode([$baris->nilai_lama, $baris->nilai_baru]);
            $this->assertStringNotContainsString('sandi-paling-baru', $isi);
            $this->assertStringNotContainsString('rahasia-wifi', $isi);
        }

        // Yang tercatat hanya bahwa sandinya berganti - tanpa nilainya.
        $this->assertStringContainsString('sandi_diganti', json_encode($log->last()->nilai_baru));
    }

    public function test_menghapus_perangkat_mencatat_audit(): void
    {
        $perangkat = $this->wifi();

        $this->sebagaiPemilik()
            ->deleteJson('/api/perangkat/'.$perangkat->id)
            ->assertOk();

        $this->assertSame(0, Perangkat::count());
        $this->assertSame(
            'perangkat.hapus',
            LogAudit::where('entitas', 'perangkat')->latest('id')->firstOrFail()->aksi,
        );
    }

    // --- Portal penghuni ---------------------------------------------------

    public function test_penghuni_hanya_melihat_wifi_yang_aktif(): void
    {
        $this->kontrakAktif();

        $this->wifi();
        $this->wifi(['nama' => 'WiFi Lama', 'aktif' => false]);
        $this->wifi(['jenis' => Perangkat::JENIS_CCTV, 'nama' => 'CCTV Gerbang']);
        $this->wifi(['jenis' => Perangkat::JENIS_RFID, 'nama' => 'Kartu Pintu']);

        $respons = $this->sebagaiPenghuni()
            ->getJson('/api/penghuni/perangkat')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('WiFi Lantai 1', $respons->json('data.0.nama'));
        $this->assertSame('rahasia-wifi', $respons->json('data.0.sandi'));
        $this->assertSame('KOS-LANTAI-1', $respons->json('data.0.nama_pengguna'));
    }

    public function test_penghuni_tanpa_kontrak_aktif_tidak_melihat_perangkat(): void
    {
        $this->wifi();

        $this->sebagaiPenghuni()
            ->getJson('/api/penghuni/perangkat')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_penghuni_tidak_boleh_membaca_sandi_cctv(): void
    {
        $cctv = $this->wifi(['jenis' => Perangkat::JENIS_CCTV, 'nama' => 'CCTV Gerbang']);

        // Rute ini tidak didaftarkan untuk peran penghuni sama sekali, jadi
        // jawabannya 403 - bukan 404 yang menyembunyikan keberadaannya.
        $this->kontrakAktif();

        $this->sebagaiPenghuni()
            ->getJson('/api/perangkat/'.$cctv->id.'/sandi')
            ->assertForbidden();
    }
}
