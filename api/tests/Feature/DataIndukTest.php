<?php

namespace Tests\Feature;

use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\Penghuni;
use App\Models\TarifSewa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DataIndukTest extends TestCase
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
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    // --- Kamar -------------------------------------------------------------

    public function test_pemilik_bisa_mendaftarkan_kamar(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kamar', ['nomor' => 'A1', 'tipe' => 'Standar'])
            ->assertCreated()
            ->assertJsonPath('data.nomor', 'A1')
            ->assertJsonPath('data.status_huni', 'kosong');

        $this->assertDatabaseHas('kamar', ['nomor' => 'A1']);
    }

    public function test_nomor_kamar_duplikat_ditolak_422(): void
    {
        Kamar::create(['nomor' => 'A1']);

        $this->sebagaiPemilik()
            ->postJson('/api/kamar', ['nomor' => 'A1'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['nomor']]);
    }

    /**
     * C5: pesan validasi bawaan berbahasa Indonesia.
     *
     * `tipe` sengaja dipakai karena aturannya (`max:40`) tidak punya pesan
     * sendiri di `SimpanKamarRequest`. Yang diuji karena itu berkas
     * `lang/id/validation.php`, bukan pesan yang ditulis formulir - dan tanpa
     * berkas itu Laravel menjawab dengan nama kuncinya (`validation.max.string`)
     * atau kalimat Inggris, bukan kalimat di bawah.
     */
    public function test_pesan_validasi_bawaan_berbahasa_indonesia(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kamar', ['nomor' => 'A1', 'tipe' => str_repeat('x', 41)])
            ->assertStatus(422)
            ->assertJsonPath('errors.tipe.0', 'tipe tidak boleh lebih dari 40 karakter.');

        $this->assertSame('id', app()->getLocale());
    }

    public function test_nomor_kamar_boleh_dipertahankan_saat_update(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);

        $this->sebagaiPemilik()
            ->putJson("/api/kamar/{$kamar->id}", ['nomor' => 'A1', 'tipe' => 'VIP'])
            ->assertOk()
            ->assertJsonPath('data.tipe', 'VIP');
    }

    public function test_daftar_kamar_menampilkan_tarif_terakhir(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1500000, 'berlaku_dari' => '2026-10-01']);
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1750000, 'berlaku_dari' => '2026-11-01']);

        $this->sebagaiPemilik()
            ->getJson('/api/kamar')
            ->assertOk()
            ->assertJsonPath('data.0.tarif_terakhir.nilai', 1750000)
            ->assertJsonPath('data.0.tarif_terakhir.berlaku_dari', '2026-11-01');
    }

    public function test_kamar_tanpa_tarif_mengirim_null_bukan_galat(): void
    {
        Kamar::create(['nomor' => 'A1']);

        $this->sebagaiPemilik()
            ->getJson('/api/kamar')
            ->assertOk()
            ->assertJsonPath('data.0.tarif_terakhir', null);
    }

    // --- Penghuni ----------------------------------------------------------

    public function test_penghuni_baru_otomatis_mendapat_akun_login(): void
    {
        $respons = $this->sebagaiPemilik()
            ->postJson('/api/penghuni', [
                'nama' => 'Budi Santoso',
                'email' => 'budi@kos.test',
                'no_hp' => '081200000001',
            ])
            ->assertCreated()
            ->assertJsonPath('data.nama', 'Budi Santoso');

        $penghuniId = $respons->json('data.id');

        $this->assertDatabaseHas('users', [
            'email' => 'budi@kos.test',
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $penghuniId,
        ]);
    }

    /**
     * T27: akun baru dibuat dalam keadaan BELUM AKTIF, dan pemilik tidak
     * memegang sandinya.
     *
     * Inilah pengganti tes lama "penghuni bisa login dengan akun yang dibuat
     * pemilik". Tes itu sekarang justru menguji hal yang harus gagal: sandi
     * awal tidak diketahui siapa pun, jadi tidak ada kombinasi yang bisa
     * dipakai masuk sebelum penghuni menetapkannya sendiri.
     */
    public function test_akun_penghuni_baru_belum_aktif_dan_sandinya_tidak_diketahui_pemilik(): void
    {
        $this->sebagaiPemilik()->postJson('/api/penghuni', [
            'nama' => 'Budi Santoso',
            'email' => 'budi@kos.test',
        ])->assertCreated();

        $user = User::where('email', 'budi@kos.test')->firstOrFail();

        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->wajibGantiSandi());

        // Tidak ada sandi yang bisa ditebak: bukan nama, bukan "password",
        // bukan apa pun yang dikirim pemilik (tidak ada yang dikirim).
        foreach (['rahasia123', 'password', 'Budi Santoso', ''] as $tebakan) {
            $this->assertFalse(Hash::check($tebakan, $user->password));
        }
    }

    public function test_email_penghuni_duplikat_ditolak_422(): void
    {
        Penghuni::create(['nama' => 'A', 'email' => 'sama@kos.test']);

        $this->sebagaiPemilik()
            ->postJson('/api/penghuni', [
                'nama' => 'B',
                'email' => 'sama@kos.test',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    /**
     * T27: medan `password` yang masih dikirim klien lama tidak ditolak.
     *
     * Kalau ditolak `prohibited`, layar versi lama menampilkan galat tentang
     * medan yang sudah tidak ada di formulirnya - pesan yang tidak bisa
     * ditindaklanjuti siapa pun. Yang benar: diabaikan, dan sandi yang dikirim
     * TIDAK dipakai.
     */
    public function test_password_yang_masih_dikirim_klien_lama_diabaikan(): void
    {
        $this->sebagaiPemilik()->postJson('/api/penghuni', [
            'nama' => 'Budi Santoso',
            'email' => 'budi@kos.test',
            'password' => 'rahasia123',
        ])->assertCreated();

        $user = User::where('email', 'budi@kos.test')->firstOrFail();

        $this->assertFalse(Hash::check('rahasia123', $user->password));
        $this->assertNull($user->email_verified_at);
    }

    // --- Kontrak -----------------------------------------------------------

    public function test_kontrak_menyimpan_tgl_masuk_dan_tarif(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);

        $this->sebagaiPemilik()
            ->postJson('/api/kontrak', [
                'kamar_id' => $kamar->id,
                'penghuni_id' => $penghuni->id,
                'tgl_masuk' => '2026-10-15',
                'tarif' => 1500000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.tgl_masuk', '2026-10-15')
            ->assertJsonPath('data.tarif_awal', 1500000)
            ->assertJsonPath('data.status', 'aktif');

        // Kamar ikut menjadi terisi.
        $this->assertSame('terisi', $kamar->fresh()->status_huni);

        // Tarif kontrak pertama masuk ke riwayat tarif kamar, bukan hanya
        // tersimpan di kontrak — karena tagihan selalu membaca riwayat itu.
        $this->assertDatabaseHas('tarif_sewa', [
            'kamar_id' => $kamar->id,
            'nilai' => 1500000,
            'berlaku_dari' => '2026-10-15',
        ]);
    }

    public function test_kontrak_tanpa_tarif_ditolak_bila_kamar_belum_punya_tarif(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);

        $this->sebagaiPemilik()
            ->postJson('/api/kontrak', [
                'kamar_id' => $kamar->id,
                'penghuni_id' => $penghuni->id,
                'tgl_masuk' => '2026-10-15',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['tarif']]);

        $this->assertSame(0, Kontrak::count());
    }

    public function test_kontrak_memakai_tarif_yang_sudah_berlaku_tanpa_perlu_mengirimnya(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1750000, 'berlaku_dari' => '2026-10-01']);

        $this->sebagaiPemilik()
            ->postJson('/api/kontrak', [
                'kamar_id' => $kamar->id,
                'penghuni_id' => $penghuni->id,
                'tgl_masuk' => '2026-10-15',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tarif_awal', 1750000);

        // Tidak ada baris tarif baru yang dibuat: tarif yang sudah ada dipakai.
        $this->assertSame(1, TarifSewa::where('kamar_id', $kamar->id)->count());
    }

    public function test_kontrak_dengan_tarif_berbeda_dari_tarif_berlaku_ditolak_422(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1750000, 'berlaku_dari' => '2026-10-01']);

        $this->sebagaiPemilik()
            ->postJson('/api/kontrak', [
                'kamar_id' => $kamar->id,
                'penghuni_id' => $penghuni->id,
                'tgl_masuk' => '2026-10-15',
                'tarif' => 1500000,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['tarif']]);

        // Riwayat tarif tidak bertambah dan tidak berubah.
        $this->assertSame(1, TarifSewa::where('kamar_id', $kamar->id)->count());
    }

    public function test_dua_kontrak_aktif_pada_satu_kamar_ditolak_422(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $budi = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);
        $ani = Penghuni::create(['nama' => 'Ani', 'email' => 'ani@kos.test']);

        $this->sebagaiPemilik()->postJson('/api/kontrak', [
            'kamar_id' => $kamar->id,
            'penghuni_id' => $budi->id,
            'tgl_masuk' => '2026-10-01',
            'tarif' => 1500000,
        ])->assertCreated();

        $this->sebagaiPemilik()
            ->postJson('/api/kontrak', [
                'kamar_id' => $kamar->id,
                'penghuni_id' => $ani->id,
                'tgl_masuk' => '2026-10-20',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['kamar_id']]);

        $this->assertSame(1, Kontrak::where('kamar_id', $kamar->id)->count());
    }

    public function test_kontrak_baru_boleh_setelah_kontrak_lama_diakhiri(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $budi = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);
        $ani = Penghuni::create(['nama' => 'Ani', 'email' => 'ani@kos.test']);

        $lama = $this->sebagaiPemilik()->postJson('/api/kontrak', [
            'kamar_id' => $kamar->id,
            'penghuni_id' => $budi->id,
            'tgl_masuk' => '2026-10-01',
            'tarif' => 1500000,
        ])->json('data.id');

        $this->sebagaiPemilik()->postJson("/api/kontrak/{$lama}/akhiri")->assertOk();

        $this->sebagaiPemilik()->postJson('/api/kontrak', [
            'kamar_id' => $kamar->id,
            'penghuni_id' => $ani->id,
            'tgl_masuk' => '2026-10-20',
        ])->assertCreated();

        // Setelah kontrak baru aktif, kamar kembali terisi.
        $this->assertSame('terisi', Kamar::find($kamar->id)->status_huni);

        // Kamar baru menjadi kosong lagi setelah kontrak kedua juga diakhiri.
        $kontrakKedua = Kontrak::where('kamar_id', $kamar->id)->where('status', 'aktif')->first();
        $this->sebagaiPemilik()->postJson("/api/kontrak/{$kontrakKedua->id}/akhiri")->assertOk();
        $this->assertSame('kosong', Kamar::find($kamar->id)->status_huni);
    }

    public function test_tgl_masuk_di_masa_depan_diterima_sebagai_dipesan(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);

        $this->sebagaiPemilik()
            ->postJson('/api/kontrak', [
                'kamar_id' => $kamar->id,
                'penghuni_id' => $penghuni->id,
                'tgl_masuk' => now()->addDays(5)->toDateString(),
                'tarif' => 1500000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'dipesan');

        // Kamar TIDAK boleh tampak terisi: penghuninya belum masuk.
        $this->assertSame('dipesan', Kamar::find($kamar->id)->status_huni);
    }

    public function test_kamar_yang_sudah_dipesan_tidak_bisa_dipesan_lagi(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $pertama = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);
        $kedua = Penghuni::create(['nama' => 'Sari', 'email' => 'sari@kos.test']);

        $this->sebagaiPemilik()->postJson('/api/kontrak', [
            'kamar_id' => $kamar->id,
            'penghuni_id' => $pertama->id,
            'tgl_masuk' => now()->addDays(10)->toDateString(),
            'tarif' => 1500000,
        ])->assertStatus(201);

        // Pemesanan kedua pada kamar yang sama harus ditolak, dan yang menolak
        // adalah indeks unik di basis data - bukan pemeriksaan di aplikasi yang
        // punya celah balapan.
        $this->sebagaiPemilik()->postJson('/api/kontrak', [
            'kamar_id' => $kamar->id,
            'penghuni_id' => $kedua->id,
            'tgl_masuk' => now()->addDays(11)->toDateString(),
            'tarif' => 1500000,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['kamar_id']]);
    }

    public function test_tgl_masuk_lebih_dari_setahun_ditolak(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);

        // Batas atas dipertahankan supaya salah ketik tahun (2027 -> 2072)
        // tidak menghasilkan kontrak yang tidak akan pernah ditagih.
        $this->sebagaiPemilik()
            ->postJson('/api/kontrak', [
                'kamar_id' => $kamar->id,
                'penghuni_id' => $penghuni->id,
                'tgl_masuk' => now()->addYears(2)->toDateString(),
                'tarif' => 1500000,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['tgl_masuk']]);
    }

    // --- Peran -------------------------------------------------------------

    public function test_penghuni_tidak_boleh_menyentuh_data_induk(): void
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
            ->getJson('/api/kamar')
            ->assertStatus(403);

        $this->actingAs($akun, 'sanctum')
            ->postJson('/api/kamar', ['nomor' => 'X1'])
            ->assertStatus(403);
    }
}
