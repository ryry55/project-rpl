<?php

namespace Tests\Feature;

use App\Models\LogAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Perintah `kos:buat-pemilik`.
 *
 * Perintah ini adalah satu-satunya jalan masuk ke instalasi produksi, jadi
 * setiap pagarnya diuji dengan dua arah: keadaan yang harus DITOLAK, dan
 * keadaan yang harus DILOLOSKAN. Pagar yang hanya diuji arah "menolak" akan
 * lulus walau ia menolak segalanya - dan perintah yang menolak segalanya
 * membuat instalasi produksi tidak bisa dipakai sama sekali.
 */
class BuatPemilikTest extends TestCase
{
    use RefreshDatabase;

    public function test_membuat_akun_pemilik_yang_langsung_aktif(): void
    {
        $this->artisan('kos:buat-pemilik', [
            '--nama' => 'Pemilik Kos',
            '--email' => 'pemilik@kos-sungguhan.id',
            '--sandi' => 'sandi-yang-kuat',
        ])->assertExitCode(0);

        $pemilik = User::where('email', 'pemilik@kos-sungguhan.id')->first();

        $this->assertNotNull($pemilik, 'Akun pemilik harus benar-benar dibuat.');
        $this->assertSame(User::PERAN_PEMILIK, $pemilik->peran);
        $this->assertSame('Pemilik Kos', $pemilik->name);
        $this->assertNull($pemilik->penghuni_id);
        $this->assertNull($pemilik->pemilik_id);

        // Langsung aktif: tidak ada tautan aktivasi untuk pemilik, dan akun
        // yang lahir belum aktif akan menolak login dengan pesan "belum
        // diaktifkan" - pada instalasi yang baru saja disiapkan, itu terbaca
        // sebagai deploy yang gagal.
        $this->assertNotNull($pemilik->email_verified_at);
        $this->assertFalse($pemilik->belumAktif());

        // Sandi benar-benar tersimpan sebagai hash, bukan teks biasa.
        $this->assertNotSame('sandi-yang-kuat', $pemilik->password);
        $this->assertTrue(Hash::check('sandi-yang-kuat', $pemilik->password));
    }

    public function test_akun_yang_dibuat_bisa_dipakai_login(): void
    {
        $this->artisan('kos:buat-pemilik', [
            '--nama' => 'Pemilik Kos',
            '--email' => 'pemilik@kos-sungguhan.id',
            '--sandi' => 'sandi-yang-kuat',
        ])->assertExitCode(0);

        // Dibuktikan lewat endpoint sungguhan, bukan lewat pemeriksaan kolom.
        // Perintah yang menulis baris yang benar tetapi akun yang tidak bisa
        // masuk tetap gagal pada tugasnya - dan kegagalan itu tidak terlihat
        // dari pemeriksaan basis data.
        $this->postJson('/api/login', [
            'email' => 'pemilik@kos-sungguhan.id',
            'password' => 'sandi-yang-kuat',
        ])->assertOk()->assertJsonPath('data.user.peran', User::PERAN_PEMILIK);
    }

    public function test_pemilik_kedua_ditolak_tanpa_paksa(): void
    {
        User::create([
            'name' => 'Pemilik Lama',
            'email' => 'lama@kos.id',
            'password' => 'sandi-lama-yang-kuat',
            'peran' => User::PERAN_PEMILIK,
            'email_verified_at' => now(),
        ]);

        $this->artisan('kos:buat-pemilik', [
            '--nama' => 'Pemilik Baru',
            '--email' => 'baru@kos.id',
            '--sandi' => 'sandi-yang-kuat',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'baru@kos.id']);
        $this->assertSame(1, User::where('peran', User::PERAN_PEMILIK)->count());
    }

    public function test_pemilik_kedua_dibuat_bila_diminta_dengan_paksa(): void
    {
        User::create([
            'name' => 'Pemilik Lama',
            'email' => 'lama@kos.id',
            'password' => 'sandi-lama-yang-kuat',
            'peran' => User::PERAN_PEMILIK,
            'email_verified_at' => now(),
        ]);

        $this->artisan('kos:buat-pemilik', [
            '--nama' => 'Pemilik Baru',
            '--email' => 'baru@kos.id',
            '--sandi' => 'sandi-yang-kuat',
            '--paksa' => true,
        ])->assertExitCode(0);

        // `--paksa` ada supaya pasangan pemilik bisa dibuat, bukan supaya
        // pagarnya bisa dilewati tanpa berpikir. Yang penting: tanpa paksa
        // pagarnya menahan, dengan paksa ia tidak menghalangi.
        $this->assertDatabaseHas('users', ['email' => 'baru@kos.id', 'peran' => User::PERAN_PEMILIK]);
    }

    public function test_email_yang_sudah_dipakai_ditolak(): void
    {
        User::create([
            'name' => 'Penghuni',
            'email' => 'dipakai@kos.id',
            'password' => 'sandi-yang-kuat',
            'peran' => User::PERAN_PENGHUNI,
            'email_verified_at' => now(),
        ]);

        $this->artisan('kos:buat-pemilik', [
            '--nama' => 'Pemilik Kos',
            '--email' => 'dipakai@kos.id',
            '--sandi' => 'sandi-yang-kuat',
        ])->assertExitCode(1);

        // Yang penting bukan sekadar "keluar dengan kode 1", melainkan bahwa
        // akun penghuni itu TIDAK berubah menjadi pemilik. Tanpa pemeriksaan
        // unique, jalur termudah adalah menimpa perannya - dan itu berarti
        // penghuni mana pun bisa menjadi pemilik dengan menebak perintahnya.
        $this->assertSame(
            User::PERAN_PENGHUNI,
            User::where('email', 'dipakai@kos.id')->value('peran'),
        );
    }

    public function test_sandi_kurang_dari_delapan_huruf_ditolak(): void
    {
        $this->artisan('kos:buat-pemilik', [
            '--nama' => 'Pemilik Kos',
            '--email' => 'pemilik@kos.id',
            '--sandi' => 'pendek',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'pemilik@kos.id']);
    }

    public function test_email_tidak_sah_ditolak(): void
    {
        $this->artisan('kos:buat-pemilik', [
            '--nama' => 'Pemilik Kos',
            '--email' => 'bukan-email',
            '--sandi' => 'sandi-yang-kuat',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'bukan-email']);
    }

    public function test_sandi_lewat_argumen_menandai_wajib_ganti(): void
    {
        $this->artisan('kos:buat-pemilik', [
            '--nama' => 'Pemilik Kos',
            '--email' => 'pemilik@kos.id',
            '--sandi' => 'sandi-yang-kuat',
        ])->assertExitCode(0);

        // Argumen perintah tercatat di `.bash_history` dan terlihat di `ps`.
        // Sandi yang sudah terlihat di tempat lain bukan rahasia lagi, jadi
        // akunnya diminta menggantinya saat pertama masuk.
        $this->assertTrue(User::where('email', 'pemilik@kos.id')->first()->wajibGantiSandi());
    }

    public function test_tanpa_tty_sandi_dibangkitkan_dan_wajib_diganti(): void
    {
        // `--no-interaction` adalah keadaan yang wajar di dalam skrip deploy dan
        // di Render Shell yang dijalankan otomatis. Tanpa cabang ini, perintah
        // yang menunggu isian akan menggantung sampai waktu habis - kegagalan
        // yang terbaca sebagai "deploy macet", bukan sebagai "perintah ini butuh
        // masukan".
        $this->artisan('kos:buat-pemilik', [
            '--nama' => 'Pemilik Kos',
            '--email' => 'pemilik@kos.id',
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $pemilik = User::where('email', 'pemilik@kos.id')->first();

        $this->assertNotNull($pemilik, 'Akun harus tetap dibuat tanpa TTY.');
        $this->assertTrue(
            $pemilik->wajibGantiSandi(),
            'Sandi yang dibangkitkan dan dicetak di layar harus diminta diganti.',
        );
    }

    public function test_pembuatan_pemilik_meninggalkan_jejak_audit(): void
    {
        $this->artisan('kos:buat-pemilik', [
            '--nama' => 'Pemilik Kos',
            '--email' => 'pemilik@kos.id',
            '--sandi' => 'sandi-yang-kuat',
        ])->assertExitCode(0);

        $jejak = LogAudit::where('aksi', 'pemilik.tambah')->first();

        $this->assertNotNull($jejak, 'Pembuatan akun pemilik harus tercatat di log audit.');
        $this->assertSame('users', $jejak->entitas);
        $this->assertSame(User::where('email', 'pemilik@kos.id')->value('id'), $jejak->entitas_id);

        // Data pribadi TIDAK boleh ikut tersalin: `log_audit` tidak bisa diubah,
        // sehingga salinan nama dan email di dalamnya tidak akan pernah
        // terjangkau permintaan penghapusan data (T28).
        $this->assertArrayNotHasKey('email', $jejak->nilai_baru ?? []);
        $this->assertArrayNotHasKey('nama', $jejak->nilai_baru ?? []);
    }
}
