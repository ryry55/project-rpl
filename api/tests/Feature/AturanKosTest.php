<?php

namespace Tests\Feature;

use App\Models\AturanKos;
use App\Models\Penghuni;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * T19 - aturan kos.
 *
 * Aturan adalah bacaan, bukan tagihan. Karena itu tabelnya boleh diubah dan
 * dihapus: tidak ada angka uang yang bergantung padanya, dan memperbaiki salah
 * ketik tidak merusak dokumen mana pun.
 *
 * Yang paling penting diuji di sini adalah **penyaringan di server**. Aturan
 * yang dinonaktifkan pemilik tidak boleh sampai ke peramban penghuni. Menyaring
 * di layar berarti datanya tetap terkirim, tetap bisa dibaca lewat jaringan,
 * dan hanya tidak digambar - yang bukan hal yang sama.
 */
class AturanKosTest extends TestCase
{
    use RefreshDatabase;

    private User $pemilik;

    private User $akunPenghuni;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pemilik = User::create([
            'name' => 'Pemilik Kos',
            'email' => 'pemilik@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PEMILIK,
        ]);

        $penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'email' => 'budi@kos.test']);

        $this->akunPenghuni = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $penghuni->id,
        ]);
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    private function sebagaiPenghuni()
    {
        return $this->actingAs($this->akunPenghuni, 'sanctum');
    }

    // --- CRUD --------------------------------------------------------------

    public function test_pemilik_bisa_menambah_aturan(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/aturan', [
                'judul' => 'Jam malam',
                'isi' => 'Gerbang dikunci pukul 23.00.',
                'kategori' => 'keamanan',
                'sanksi' => 'Teguran lisan',
            ])
            ->assertCreated()
            ->assertJsonPath('data.judul', 'Jam malam')
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('aturan_kos', ['judul' => 'Jam malam']);
    }

    public function test_judul_dan_isi_wajib_diisi(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/aturan', [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['judul', 'isi']]);
    }

    public function test_kategori_bawaan_umum_bila_tidak_diisi(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/aturan', ['judul' => 'Sampah', 'isi' => 'Buang tiap malam.'])
            ->assertCreated()
            ->assertJsonPath('data.kategori', 'umum');
    }

    public function test_pemilik_bisa_mengubah_aturan(): void
    {
        $aturan = AturanKos::create(['judul' => 'Jam malam', 'isi' => 'Pukul 22.00.']);

        $this->sebagaiPemilik()
            ->putJson("/api/aturan/{$aturan->id}", [
                'judul' => 'Jam malam',
                'isi' => 'Pukul 23.00.',
            ])
            ->assertOk()
            ->assertJsonPath('data.isi', 'Pukul 23.00.');

        // Boleh berubah: bukan append-only, karena tidak ada uang yang
        // bergantung padanya. Ini perbedaan sadar dari `kebijakan_kos`.
        $this->assertDatabaseHas('aturan_kos', ['id' => $aturan->id, 'isi' => 'Pukul 23.00.']);
    }

    public function test_pemilik_bisa_menghapus_aturan(): void
    {
        $aturan = AturanKos::create(['judul' => 'Aturan lama', 'isi' => 'Sudah tidak berlaku.']);

        $this->sebagaiPemilik()->deleteJson("/api/aturan/{$aturan->id}")->assertOk();

        $this->assertDatabaseMissing('aturan_kos', ['id' => $aturan->id]);
    }

    public function test_daftar_aturan_urut_dan_menyertakan_pilihan_kategori(): void
    {
        AturanKos::create(['judul' => 'Kedua', 'isi' => 'x', 'urutan' => 20]);
        AturanKos::create(['judul' => 'Pertama', 'isi' => 'x', 'urutan' => 10]);

        $respons = $this->sebagaiPemilik()->getJson('/api/aturan')->assertOk();

        $this->assertSame(['Pertama', 'Kedua'], array_column($respons->json('data'), 'judul'));
        $this->assertContains('keamanan', $respons->json('meta.kategori'));
    }

    // --- Penyaringan aktif ------------------------------------------------

    public function test_pemilik_dapat_menyaring_hanya_yang_aktif(): void
    {
        AturanKos::create(['judul' => 'Aktif', 'isi' => 'x', 'aktif' => true]);
        AturanKos::create(['judul' => 'Nonaktif', 'isi' => 'x', 'aktif' => false]);

        $respons = $this->sebagaiPemilik()->getJson('/api/aturan?aktif=1')->assertOk();

        $this->assertSame(['Aktif'], array_column($respons->json('data'), 'judul'));
    }

    public function test_portal_penghuni_hanya_menerima_aturan_aktif(): void
    {
        AturanKos::create(['judul' => 'Aktif', 'isi' => 'x', 'aktif' => true]);
        AturanKos::create(['judul' => 'Nonaktif', 'isi' => 'x', 'aktif' => false]);

        $respons = $this->sebagaiPenghuni()->getJson('/api/penghuni/aturan')->assertOk();

        $this->assertSame(['Aktif'], array_column($respons->json('data'), 'judul'));

        // Disaring di server: isi aturan nonaktif tidak boleh ada di muatan
        // sama sekali, bukan sekadar tidak digambar.
        $this->assertStringNotContainsString('Nonaktif', $respons->getContent());
    }

    public function test_portal_penghuni_kosong_bukan_galat(): void
    {
        // Kos yang belum mengisi aturan tetap harus bisa dibuka penghuninya.
        $this->sebagaiPenghuni()
            ->getJson('/api/penghuni/aturan')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_penghuni_tidak_bisa_mengubah_aturan(): void
    {
        $aturan = AturanKos::create(['judul' => 'Jam malam', 'isi' => 'Pukul 23.00.']);

        $this->sebagaiPenghuni()
            ->postJson('/api/aturan', ['judul' => 'Hapus semua aturan', 'isi' => 'x'])
            ->assertForbidden();

        $this->sebagaiPenghuni()
            ->putJson("/api/aturan/{$aturan->id}", ['judul' => 'Diubah', 'isi' => 'x'])
            ->assertForbidden();

        $this->sebagaiPenghuni()
            ->deleteJson("/api/aturan/{$aturan->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('aturan_kos', ['id' => $aturan->id, 'judul' => 'Jam malam']);
    }

    public function test_penghuni_tidak_bisa_membaca_daftar_aturan_pemilik(): void
    {
        // `/aturan` mengirim juga yang nonaktif. Penghuni tidak boleh memakainya
        // untuk melihat aturan yang sengaja disembunyikan pemilik.
        $this->sebagaiPenghuni()->getJson('/api/aturan')->assertForbidden();
    }
}
