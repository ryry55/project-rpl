<?php

namespace Tests\Feature;

use App\Domain\Audit\PencatatAudit;
use App\Models\LogAudit;
use App\Models\Penghuni;
use App\Models\Rekening;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\MenyiapkanKebijakan;
use Tests\TestCase;

/**
 * REV-UI-05 — rekening dan QRIS tujuan pembayaran.
 *
 * Sebelum ini portal penghuni menyebut angka yang harus dibayar tanpa pernah
 * menyebut ke mana uangnya dikirim. Penghuni lalu bertanya lewat WhatsApp, dan
 * pemilik mengetik ulang nomor rekeningnya untuk tiap orang.
 *
 * Yang diuji di sini bukan "formulirnya bisa menyimpan", melainkan tiga
 * keputusan yang membuat fitur ini aman:
 *
 *   1. **Menulis rekening hanya milik pemilik.** Nomor rekening adalah alamat
 *      uang masuk; pengelola yang boleh mengubahnya bisa mengarahkan setoran
 *      penghuni ke rekeningnya sendiri, lalu menandai tagihannya lunas sendiri.
 *   2. **Penghuni hanya melihat yang aktif.** Penghuni yang mentransfer ke
 *      rekening lama kehilangan uangnya, dan tidak ada kode yang bisa
 *      mengembalikannya.
 *   3. **Perubahan nomor tercatat di log audit.** Pertanyaan "dulu saya
 *      transfer ke mana" harus bisa dijawab setelah barisnya dihapus.
 */
class RekeningTest extends TestCase
{
    use MenyiapkanKebijakan;
    use RefreshDatabase;

    private User $pemilik;

    private User $pengelola;

    private User $penghuni;

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
        $this->pengelola->kewenangan()->create(['wewenang' => 'kelola_kamar']);
        $this->pengelola->kewenangan()->create(['wewenang' => 'catat_pembayaran']);
        $this->pengelola->load('kewenangan');

        $this->penghuni = User::create([
            'name' => 'Budi',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
        ]);

        $this->siapkanKebijakanDefault();
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    private function rekeningContoh(array $ubah = []): Rekening
    {
        return Rekening::create(array_merge([
            'jenis' => Rekening::JENIS_TRANSFER,
            'nama' => 'BCA',
            'nomor' => '1234567890',
            'atas_nama' => 'Yukie Wirapratama',
            'aktif' => true,
        ], $ubah));
    }

    // --- Menulis hanya milik pemilik ---------------------------------------

    public function test_pemilik_bisa_menambah_rekening(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/rekening', [
                'jenis' => 'transfer',
                'nama' => 'BCA',
                'nomor' => '1234567890',
                'atas_nama' => 'Yukie Wirapratama',
            ])
            ->assertCreated()
            ->assertJsonPath('data.jenis', 'transfer')
            ->assertJsonPath('data.jenis_label', 'Transfer bank')
            ->assertJsonPath('data.nomor', '1234567890')
            ->assertJsonPath('data.aktif', true);

        $this->assertDatabaseHas('rekening', ['nama' => 'BCA', 'nomor' => '1234567890']);
    }

    public function test_qris_tanpa_nomor_diterima(): void
    {
        // QRIS statis bisa hanya berupa gambar; memaksa nomor terisi membuat
        // pemilik mengarang angka supaya formulirnya mau tersimpan.
        $this->sebagaiPemilik()
            ->postJson('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS BCA',
            ])
            ->assertCreated()
            ->assertJsonPath('data.jenis', 'qris')
            ->assertJsonPath('data.nomor', null);
    }

    public function test_jenis_tidak_dikenal_ditolak(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/rekening', [
                'jenis' => 'paypal',
                'nama' => 'PayPal',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('jenis');
    }

    public function test_nama_wajib_diisi(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/rekening', ['jenis' => 'transfer'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nama');
    }

    public function test_pengelola_tidak_boleh_menambah_rekening(): void
    {
        // Ini penjagaan yang paling penting di berkas ini. Pengelola yang boleh
        // mengubah nomor rekening bisa mengarahkan setoran penghuni ke
        // rekeningnya sendiri, lalu menandai tagihannya lunas - dua wewenang
        // yang ia memang punya, dan tidak ada yang perlu disetujui siapa pun.
        $this->actingAs($this->pengelola, 'sanctum')
            ->postJson('/api/rekening', [
                'jenis' => 'transfer',
                'nama' => 'Rekening Sari',
                'nomor' => '9999999999',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('rekening', ['nomor' => '9999999999']);
    }

    public function test_pengelola_tidak_boleh_mengubah_rekening(): void
    {
        $rekening = $this->rekeningContoh();

        $this->actingAs($this->pengelola, 'sanctum')
            ->putJson("/api/rekening/{$rekening->id}", [
                'nama' => 'BCA',
                'nomor' => '9999999999',
            ])
            ->assertForbidden();

        $this->assertSame('1234567890', $rekening->fresh()->nomor);
    }

    public function test_penghuni_tidak_boleh_membaca_daftar_rekening_pemilik(): void
    {
        // `/rekening` mengirim juga yang tidak aktif dan `meta` untuk layar
        // pengelolaan. Penghuni memakai `/penghuni/rekening` yang lebih sempit.
        $this->actingAs($this->penghuni, 'sanctum')
            ->getJson('/api/rekening')
            ->assertForbidden();
    }

    // --- Membaca: pengelola boleh, penghuni hanya yang aktif ---------------

    public function test_pengelola_boleh_membaca_daftar_rekening(): void
    {
        $this->rekeningContoh();

        $this->actingAs($this->pengelola, 'sanctum')
            ->getJson('/api/rekening')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.label_jenis.transfer', 'Transfer bank');
    }

    public function test_pemilik_melihat_rekening_tidak_aktif_juga(): void
    {
        $this->rekeningContoh(['nama' => 'BCA', 'nomor' => '111']);
        $this->rekeningContoh(['nama' => 'Mandiri', 'nomor' => '222', 'aktif' => false]);

        // Layar pengelolaan harus bisa menampilkan rekening lama supaya bisa
        // diaktifkan lagi tanpa dibuat ulang.
        $this->sebagaiPemilik()
            ->getJson('/api/rekening')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_penghuni_hanya_melihat_rekening_aktif(): void
    {
        $this->rekeningContoh(['nama' => 'BCA', 'nomor' => '111']);
        $this->rekeningContoh(['nama' => 'Mandiri', 'nomor' => '222', 'aktif' => false]);

        $this->actingAs($this->penghuni, 'sanctum')
            ->getJson('/api/penghuni/rekening')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nama', 'BCA');
    }

    public function test_portal_penghuni_tidak_mengirim_meta_pengelolaan(): void
    {
        $this->actingAs($this->penghuni, 'sanctum')
            ->getJson('/api/penghuni/rekening')
            ->assertOk()
            ->assertJsonMissingPath('meta');
    }

    // --- Jejak audit -------------------------------------------------------

    public function test_menambah_rekening_tercatat_di_log_audit(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/rekening', [
                'jenis' => 'transfer',
                'nama' => 'BCA',
                'nomor' => '1234567890',
            ])
            ->assertCreated();

        $log = LogAudit::query()
            ->where('aksi', PencatatAudit::AKSI_REKENING_TAMBAH)
            ->first();

        $this->assertNotNull($log, 'penambahan rekening tidak tercatat di log audit');
        $this->assertSame('1234567890', $log->nilai_baru['nomor']);
        $this->assertSame($this->pemilik->id, (int) $log->aktor_id);
    }

    public function test_mengubah_nomor_menyimpan_nomor_lama_di_log(): void
    {
        $rekening = $this->rekeningContoh();

        $this->sebagaiPemilik()
            ->putJson("/api/rekening/{$rekening->id}", [
                'nama' => 'BCA',
                'nomor' => '9876543210',
            ])
            ->assertOk()
            ->assertJsonPath('data.nomor', '9876543210');

        $log = LogAudit::query()
            ->where('aksi', PencatatAudit::AKSI_REKENING_UBAH)
            ->first();

        // Nomor lama dikirim lengkap, bukan hanya id: barisnya bisa sudah
        // dihapus saat pertanyaan "dulu nomornya berapa" datang.
        $this->assertSame('1234567890', $log->nilai_lama['nomor']);
        $this->assertSame('9876543210', $log->nilai_baru['nomor']);
    }

    public function test_menghapus_rekening_menyimpan_salinan_lengkap(): void
    {
        $rekening = $this->rekeningContoh();

        $this->sebagaiPemilik()
            ->deleteJson("/api/rekening/{$rekening->id}")
            ->assertOk();

        $this->assertDatabaseMissing('rekening', ['id' => $rekening->id]);

        $log = LogAudit::query()
            ->where('aksi', PencatatAudit::AKSI_REKENING_HAPUS)
            ->first();

        $this->assertSame('BCA', $log->nilai_lama['nama']);
        $this->assertSame('1234567890', $log->nilai_lama['nomor']);
    }

    // --- Penghuni melihatnya di portal ------------------------------------

    public function test_penghuni_melihat_rekening_tanpa_harus_punya_tagihan(): void
    {
        // Rekening bukan bagian dari tagihan: kos yang baru mulai dan belum
        // menerbitkan apa pun tetap harus bisa memberi tahu ke mana uangnya
        // dikirim.
        $this->rekeningContoh(['nama' => 'BCA', 'nomor' => '111']);

        $this->actingAs($this->penghuni, 'sanctum')
            ->getJson('/api/penghuni/tagihan')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->penghuni, 'sanctum')
            ->getJson('/api/penghuni/rekening')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_tanpa_login_ditolak(): void
    {
        $this->getJson('/api/penghuni/rekening')->assertUnauthorized();
    }

    public function test_rekening_tidak_ada_menghasilkan_404(): void
    {
        $this->sebagaiPemilik()
            ->putJson('/api/rekening/999999', ['nama' => 'BCA'])
            ->assertNotFound();
    }
}
