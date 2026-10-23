<?php

namespace Tests\Feature;

use App\Http\Middleware\CatatPermintaanGagal;
use App\Models\Penghuni;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * T29 - pengerasan operasional.
 *
 * Tiga hal yang diuji, dan ketiganya adalah hal yang baru berguna SETELAH
 * aplikasi ini berjalan di internet:
 *
 *   1. **Health check yang benar-benar memeriksa database.** `/up` bawaan
 *      Laravel menjawab 200 walau database tidak terjangkau - dan di layanan
 *      gratis yang tidur setelah 15 menit, "hidup" tanpa database berarti
 *      setiap layar gagal sementara pemantau melaporkan semuanya sehat.
 *
 *   2. **Log untuk penolakan, bukan hanya untuk exception.** 401, 403, 429,
 *      dan 409 bukan exception - dan justru keempatnya yang dicari saat ada
 *      masalah. Yang tidak boleh tercatat: kata sandi dan token.
 *
 *   3. **Seeder menolak berjalan di produksi.** Seeder menulis ulang sandi
 *      pemilik menjadi "password" setiap kali dijalankan, dan satu deploy
 *      dengan `RUN_SEEDER` yang lupa dimatikan sudah cukup untuk membuka
 *      seluruh data kos.
 */
class OperasionalTest extends TestCase
{
    use RefreshDatabase;

    // --- Health check ------------------------------------------------------

    public function test_health_menjawab_200_tanpa_login(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.database', 'ok')
            ->assertJsonPath('data.zona_waktu', 'Asia/Jakarta');
    }

    public function test_health_tidak_membocorkan_nama_tabel_versi_atau_galat(): void
    {
        $isi = $this->getJson('/api/health')->assertOk()->getContent();

        // Endpoint ini publik dan tidak punya akun. Health check yang
        // membocorkan nama host database adalah undangan.
        foreach (['users', 'pgsql', 'Laravel', 'PHP', 'kos_', 'postgres'] as $bocor) {
            $this->assertStringNotContainsString($bocor, $isi);
        }
    }

    public function test_health_menjawab_503_saat_database_tidak_terjangkau(): void
    {
        // Koneksi dipaksa gagal lewat Schema, bukan dengan memutus koneksi
        // sungguhan: memutusnya akan menggagalkan `RefreshDatabase` saat tes
        // berakhir, dan kegagalan itu muncul sebagai galat di tes berikutnya -
        // bukan di tes ini.
        Schema::shouldReceive('hasTable')->andThrow(new \PDOException('connection refused'));

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('data.status', 'down')
            ->assertJsonPath('data.database', 'tidak_terjangkau');
    }

    public function test_health_membedakan_database_hidup_tetapi_migrasi_belum_jalan(): void
    {
        // Koneksi bisa hidup sementara migrasi belum berjalan. Dalam keadaan
        // itu setiap layar gagal walau koneksinya sehat, jadi jawabannya
        // dibedakan - dan tetap 503, bukan 200.
        Schema::shouldReceive('hasTable')->andReturn(false);

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJsonPath('data.status', 'down')
            ->assertJsonPath('data.database', 'migrasi_belum_jalan');
    }

    // --- Log penolakan -----------------------------------------------------

    public function test_penolakan_401_tercatat_sebagai_satu_baris_json(): void
    {
        Log::spy();

        $this->getJson('/api/me')->assertStatus(401);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $pesan, array $konteks) {
                return $pesan === 'permintaan.gagal'
                    && $konteks['metode'] === 'GET'
                    && $konteks['jalur'] === '/api/me'
                    && $konteks['status'] === 401;
            })
            ->once();
    }

    public function test_penolakan_403_wajib_ganti_sandi_menyertakan_kodenya(): void
    {
        Log::spy();

        $user = User::create([
            'name' => 'Sari',
            'email' => 'sari@kos.test',
            'password' => bcrypt('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => User::create([
                'name' => 'Pemilik',
                'email' => 'pemilik@kos.test',
                'password' => bcrypt('password'),
                'peran' => User::PERAN_PEMILIK,
                'email_verified_at' => now(),
            ])->id,
            'email_verified_at' => now(),
        ]);

        $user->forceFill(['wajib_ganti_sandi' => true])->save();

        $this->actingAs($user->fresh(), 'sanctum')
            ->getJson('/api/kamar')
            ->assertStatus(403)
            ->assertJsonPath('kode', 'wajib_ganti_sandi');

        // `kode` inilah yang membedakan "ditolak karena wewenang" dari
        // "ditolak karena sandi harus diganti" saat membaca log - tanpa itu,
        // keduanya hanya deretan 403 yang sama.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $pesan, array $konteks) => $pesan === 'permintaan.gagal'
                && $konteks['status'] === 403
                && $konteks['kode'] === 'wajib_ganti_sandi')
            ->once();
    }

    public function test_respons_sukses_tidak_dicatat(): void
    {
        Log::spy();

        $this->getJson('/api/health')->assertOk();

        // Mencatat setiap 200 akan menenggelamkan yang penting - dan di Render
        // gratis kuota log-nya terbatas.
        Log::shouldNotHaveReceived('warning');
    }

    public function test_log_tidak_memuat_badan_permintaan_maupun_token(): void
    {
        Log::spy();

        $this->postJson('/api/login', [
            'email' => 'tidak-ada@kos.test',
            'password' => 'sandi-rahasia-sekali',
        ])->assertStatus(401);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $pesan, array $konteks) {
                $isi = json_encode($konteks);

                return $pesan === 'permintaan.gagal'
                    && ! str_contains($isi, 'sandi-rahasia-sekali')
                    && ! str_contains($isi, 'Authorization')
                    && ! array_key_exists('body', $konteks)
                    && ! array_key_exists('headers', $konteks);
            })
            ->once();
    }

    public function test_log_memakai_path_bukan_url_lengkap(): void
    {
        Log::spy();

        $this->getJson('/api/tagihan?cari=Budi+Santoso')->assertStatus(401);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $pesan, array $konteks) {
                // Query string pada endpoint pencarian berisi nama penghuni -
                // dan nama penghuni adalah data pribadi yang tidak boleh
                // mengalir ke berkas log yang disalin ke mana-mana.
                return $pesan === 'permintaan.gagal'
                    && $konteks['jalur'] === '/api/tagihan'
                    && ! str_contains(json_encode($konteks), 'Budi');
            })
            ->once();
    }

    public function test_middleware_menangani_respons_tanpa_badan_json(): void
    {
        // 204 dan 304 tidak punya badan. Middleware yang memanggil
        // `json_decode` tanpa memeriksa isinya akan melempar galat di jalur
        // yang justru tidak boleh gagal.
        Log::spy();

        $request = Request::create('/api/uji', 'DELETE');

        $respons = (new CatatPermintaanGagal)->handle(
            $request,
            fn () => new Response('', 404),
        );

        $this->assertSame(404, $respons->getStatusCode());
        Log::shouldHaveReceived('warning')->once();
    }

    // --- Pagar produksi ----------------------------------------------------

    public function test_seeder_menolak_berjalan_di_produksi(): void
    {
        $this->app['env'] = 'production';

        // `--force` karena `db:seed` di produksi meminta konfirmasi interaktif.
        // Yang dikonfirmasi di sini adalah "jalankan seeder", sedangkan yang
        // diuji adalah bahwa seeder-nya sendiri menolak - dua lapis yang
        // berbeda, dan hanya lapis kedua yang menjaga data.
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        // Akun demo TIDAK dibuat, dan tidak ada galat: seeder cukup
        // memperingatkan lalu berhenti. Galat keras akan menggagalkan deploy
        // yang sebenarnya benar, dan diam-diam berjalan akan membuka data kos.
        $this->assertDatabaseMissing('users', ['email' => 'pemilik@kos.test']);
    }

    public function test_seeder_berjalan_di_lingkungan_pengembangan(): void
    {
        $this->artisan('db:seed')->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'pemilik@kos.test']);
        $this->assertDatabaseHas('users', ['email' => 'pengelola@kos.test']);
        $this->assertDatabaseHas('users', ['email' => 'penghuni@kos.test']);
    }

    public function test_akun_demo_dikecualikan_dari_wajib_ganti_sandi_dan_sudah_aktif(): void
    {
        $this->artisan('db:seed')->assertSuccessful();

        foreach (['pemilik@kos.test', 'pengelola@kos.test', 'penghuni@kos.test'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            // Kredensialnya dicetak di layar masuk. Memaksa ganti sandi berarti
            // mencetak kredensial yang tidak berlaku.
            $this->assertFalse($user->wajibGantiSandi(), "{$email} tidak boleh ditahan.");
            $this->assertNotNull($user->email_verified_at, "{$email} harus sudah aktif.");
            $this->assertTrue(
                Hash::check('password', $user->password),
                "{$email} harus bisa masuk dengan sandi yang tercetak."
            );
        }
    }

    public function test_seeder_boleh_dijalankan_dua_kali(): void
    {
        $this->artisan('db:seed')->assertSuccessful();
        $this->artisan('db:seed')->assertSuccessful();

        // Seluruh penyemaian memakai `firstOrCreate`/`updateOrCreate` karena
        // tabel riwayat bersifat append-only - seeder yang menulis ulang
        // riwayat akan ditolak trigger, dan penolakan itu akan muncul sebagai
        // container yang gagal start setiap kali.
        $this->assertSame(1, User::where('email', 'pemilik@kos.test')->count());
        $this->assertSame(1, Penghuni::where('email', 'penghuni@kos.test')->count());
    }

    // --- Berkas operasional ------------------------------------------------

    public function test_skrip_backup_ada_dan_bisa_dijalankan_shell(): void
    {
        $jalur = '/var/www/html/docker/backup.sh';

        $this->assertFileExists($jalur);

        // Skrip yang tidak bisa dijalankan `sh` adalah skrip yang baru ketahuan
        // rusak saat dibutuhkan. Diperiksa dengan `sh -n` (parse saja), bukan
        // dijalankan - menjalankannya butuh `pg_dump` dan direktori tujuan.
        $keluaran = [];
        $kode = 0;
        exec('sh -n '.escapeshellarg($jalur).' 2>&1', $keluaran, $kode);

        $this->assertSame(0, $kode, 'backup.sh tidak lolos pemeriksaan sintaks: '.implode("\n", $keluaran));
    }

    public function test_entrypoint_tidak_pernah_menjalankan_migrate_fresh(): void
    {
        $isi = (string) file_get_contents('/var/www/html/docker/php/entrypoint.sh');

        // Komentar boleh menyebutnya - dan memang menyebutnya, untuk menjelaskan
        // kenapa perintah itu tidak ada. Yang diperiksa adalah baris perintah:
        // baris yang tidak dimulai `#`.
        $perintah = implode("\n", array_filter(
            explode("\n", $isi),
            fn (string $baris) => ! str_starts_with(ltrim($baris), '#'),
        ));

        // Satu perintah itu menghapus seluruh tagihan dan pembayaran, dan tidak
        // ada satu pun keadaan produksi yang membutuhkannya.
        $this->assertStringNotContainsString('migrate:fresh', $perintah);
        $this->assertStringContainsString('migrate --force', $perintah);
    }

    public function test_entrypoint_mematikan_seeder_saat_app_env_produksi(): void
    {
        $isi = (string) file_get_contents('/var/www/html/docker/php/entrypoint.sh');

        $this->assertStringContainsString('APP_ENV', $isi);
        $this->assertStringContainsString('RUN_SEEDER=false', $isi);
    }

    public function test_token_sanctum_punya_masa_berlaku(): void
    {
        // Sebelum T27 nilainya `null`, dan token dari laptop warnet berlaku
        // selamanya. Empat belas hari adalah kompromi: cukup panjang untuk
        // ponsel yang jarang dibuka, cukup pendek untuk membatasi token bocor.
        $this->assertSame(60 * 24 * 14, config('sanctum.expiration'));
    }
}
