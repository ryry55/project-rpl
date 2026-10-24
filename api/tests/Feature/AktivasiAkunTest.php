<?php

namespace Tests\Feature;

use App\Domain\Akses\Wewenang;
use App\Domain\Akun\PembatasMasuk;
use App\Mail\TautanAkunKos;
use App\Models\PengelolaKewenangan;
use App\Models\Penghuni;
use App\Models\TokenAkun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * T27 - aktivasi akun, pemulihan sandi, dan penahanan wajib-ganti-sandi.
 *
 * Yang dibuktikan di sini, berurut menurut akibatnya bila salah:
 *
 *   1. **Pemilik tidak pernah memegang sandi orang lain.** Akun baru lahir
 *      dengan sandi acak yang tidak dikembalikan siapa pun, dan satu-satunya
 *      jalan masuk adalah tautan yang dikirim ke email penghuni. Tanpa ini,
 *      klaim isolasi R-07 hanya berlaku terhadap orang yang kebetulan tidak
 *      tahu sandinya.
 *
 *   2. **Tautan sekali pakai dan berbatas waktu.** Dipakai dua kali, ditolak.
 *      Lewat tenggat, ditolak. Tautan aktivasi tidak bisa dipakai sebagai
 *      tautan reset, dan sebaliknya - kalau bisa, tautan lama yang bocor
 *      menjadi kunci untuk mengambil alih akun yang sudah aktif.
 *
 *   3. **Pembatas percobaan bekerja dan hanya menghitung kegagalan.** Lima
 *      kegagalan berturut-turut menghasilkan 429, dan pengguna yang berhasil
 *      masuk tidak pernah menghabiskan jatahnya.
 *
 *   4. **Lupa sandi tidak membocorkan siapa yang punya akun.** Jawaban untuk
 *      email terdaftar dan tidak terdaftar identik, termasuk kode statusnya.
 */
class AktivasiAkunTest extends TestCase
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
            'email_verified_at' => now(),
        ]);
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    /**
     * Ambil tautan terakhir yang dikirim ke satu alamat.
     *
     * Tautan diperlakukan sebagai data yang hanya bisa dibaca dari email -
     * persis seperti di produksi. Tes yang mengambil token langsung dari tabel
     * akan lulus walau emailnya tidak pernah berisi tautan yang benar.
     */
    private function tautanTerakhir(string $email): string
    {
        $tautan = null;

        Mail::assertSent(TautanAkunKos::class, function (TautanAkunKos $mail) use ($email, &$tautan) {
            if (! $mail->hasTo($email)) {
                return false;
            }

            $tautan = $mail->tautan;

            return true;
        });

        $this->assertNotNull($tautan, "Tidak ada email tautan untuk {$email}.");

        return $tautan;
    }

    /**
     * @return array{token: string, jenis: string}
     */
    private function tokenDari(string $tautan): array
    {
        parse_str((string) parse_url($tautan, PHP_URL_QUERY), $q);

        return [
            'token' => (string) ($q['token'] ?? ''),
            'jenis' => (string) ($q['jenis'] ?? ''),
        ];
    }

    private function buatPenghuni(string $email = 'budi@kos.test'): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/penghuni', ['nama' => 'Budi Santoso', 'email' => $email])
            ->assertCreated();
    }

    // --- Pembuatan akun ----------------------------------------------------

    public function test_membuat_penghuni_mengirim_tautan_aktivasi(): void
    {
        Mail::fake();

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/penghuni', ['nama' => 'Budi Santoso', 'email' => 'budi@kos.test'])
            ->assertCreated()
            ->assertJsonPath('meta.tautan_aktivasi_terkirim', true)
            ->assertJsonPath('meta.email', 'budi@kos.test');

        // Respons tidak boleh membocorkan sandi - bahkan tidak sebagai hash.
        $this->assertArrayNotHasKey('password', $respons->json('data'));

        $tautan = $this->tautanTerakhir('budi@kos.test');
        $token = $this->tokenDari($tautan);

        $this->assertSame('aktivasi', $token['jenis']);
        $this->assertNotSame('', $token['token']);
        $this->assertDatabaseHas('token_akun', ['jenis' => 'aktivasi']);
    }

    public function test_tautan_yang_beredar_tidak_memuat_token_mentah_di_basis_data(): void
    {
        Mail::fake();

        $this->buatPenghuni();

        $mentah = $this->tokenDari($this->tautanTerakhir('budi@kos.test'))['token'];
        $tersimpan = TokenAkun::firstOrFail()->token_hash;

        $this->assertSame(hash('sha256', $mentah), $tersimpan);
        $this->assertNotSame($mentah, $tersimpan);
    }

    /**
     * Badan email harus memuat tautan yang UTUH, bukan versi ter-escape.
     *
     * Ini pernah salah dan tidak tertangkap tes mana pun: templat email teks
     * polos memakai `{{ }}`, yang meng-escape `&` menjadi `&amp;`. Tautannya
     * sampai ke penghuni sebagai `?token=...&amp;jenis=aktivasi`, server
     * membacanya sebagai parameter bernama `amp;jenis`, `jenis` jatuh ke nilai
     * bawaan `reset`, dan SETIAP tautan aktivasi dijawab 410.
     *
     * Gejalanya menyesatkan ke arah yang mahal: yang terlihat adalah "tautan
     * sudah tidak berlaku", bukan "tautannya rusak saat ditulis". Karena itu
     * yang diperiksa di sini adalah teks email yang benar-benar dirender, bukan
     * nilai `$tautan` di objek Mailable - nilai itu selalu benar.
     */
    public function test_badan_email_memuat_tautan_utuh_tanpa_escape_html(): void
    {
        Mail::fake();

        $this->buatPenghuni();

        $mail = null;
        Mail::assertSent(TautanAkunKos::class, function (TautanAkunKos $satu) use (&$mail) {
            $mail = $satu;

            return true;
        });

        $this->assertNotNull($mail);

        $badan = $mail->render();

        $this->assertStringContainsString('&jenis=aktivasi', $badan);
        $this->assertStringNotContainsString('&amp;', $badan);

        // Tautannya harus benar-benar bisa dipakai: token dari badan email
        // ditukar lewat endpoint publik, dan jawabannya 200 - bukan 410.
        preg_match('#/atur-sandi\?[^\s]+#', $badan, $cocok);
        $this->assertNotEmpty($cocok, 'Badan email tidak memuat tautan /atur-sandi.');

        parse_str((string) parse_url($cocok[0], PHP_URL_QUERY), $kueri);

        $this->postJson('/api/atur-sandi', [
            'token' => $kueri['token'],
            'jenis' => $kueri['jenis'],
            'password' => 'sandi-dari-email',
            'password_confirmation' => 'sandi-dari-email',
        ])->assertOk();
    }

    // --- Gerbang aktivasi --------------------------------------------------

    /**
     * Gerbang aktivasi hanya bisa dicapai SETELAH sandi benar.
     *
     * Itu bukan kelemahan, melainkan urutan yang disengaja: sandi diperiksa
     * lebih dulu supaya siapa pun tidak bisa memakai endpoint ini untuk
     * memeriksa alamat mana yang punya akun. Akibatnya keadaan ini hanya bisa
     * dihasilkan lewat basis data - akun dengan sandi yang diketahui tetapi
     * belum diaktifkan, yaitu persis keadaan akun lama sebelum T27.
     */
    public function test_login_akun_belum_aktif_ditolak_403_bukan_401(): void
    {
        $this->buatPenghuniAktifLewatBasisData();
        User::where('email', 'budi@kos.test')->firstOrFail()
            ->forceFill(['email_verified_at' => null])->save();

        // Sandi yang benar pun tidak bisa dipakai masuk. Jawabannya 403 dengan
        // pesan yang menyebut tautan aktivasi, bukan 401 "sandi salah" -
        // karena pengguna yang dijawab "sandi salah" akan mencoba terus dengan
        // sandi yang memang tidak pernah ia tetapkan.
        $this->postJson('/api/login', [
            'email' => 'budi@kos.test',
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'belum diaktifkan'));
    }

    public function test_akun_belum_aktif_tetap_dijawab_401_bila_sandinya_salah(): void
    {
        $this->buatPenghuniAktifLewatBasisData();
        User::where('email', 'budi@kos.test')->firstOrFail()
            ->forceFill(['email_verified_at' => null])->save();

        // Urutan pemeriksaan penting: sandi diperiksa lebih dulu, aktivasi
        // sesudahnya. Kalau dibalik, siapa pun bisa mengetahui alamat mana yang
        // punya akun hanya dengan menebak sandi sembarang.
        $this->postJson('/api/login', [
            'email' => 'budi@kos.test',
            'password' => 'salah',
        ])->assertStatus(401);

        $this->postJson('/api/login', [
            'email' => 'budi@kos.test',
            'password' => 'apa-saja',
        ])->assertStatus(401);
    }

    // --- Atur sandi --------------------------------------------------------

    public function test_atur_sandi_mengaktifkan_akun_dan_bisa_login(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        $token = $this->tokenDari($this->tautanTerakhir('budi@kos.test'));

        $this->postJson('/api/atur-sandi', [
            'token' => $token['token'],
            'jenis' => $token['jenis'],
            'password' => 'sandi-baru-saya',
            'password_confirmation' => 'sandi-baru-saya',
        ])->assertOk();

        $user = User::where('email', 'budi@kos.test')->firstOrFail();

        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->wajibGantiSandi());
        $this->assertTrue(Hash::check('sandi-baru-saya', $user->password));

        $this->postJson('/api/login', [
            'email' => 'budi@kos.test',
            'password' => 'sandi-baru-saya',
        ])->assertOk()->assertJsonPath('data.user.peran', 'penghuni');
    }

    public function test_atur_sandi_memutus_seluruh_sesi_lama(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        $user = User::where('email', 'budi@kos.test')->firstOrFail();
        $user->createToken('spa');
        $user->createToken('laptop-warnet');

        $token = $this->tokenDari($this->tautanTerakhir('budi@kos.test'));

        $this->postJson('/api/atur-sandi', [
            'token' => $token['token'],
            'jenis' => $token['jenis'],
            'password' => 'sandi-baru-saya',
            'password_confirmation' => 'sandi-baru-saya',
        ])->assertOk();

        // Tanpa ini, mengganti sandi hanya mengganti pintu depan sementara
        // token lama tetap membuka pintu belakang.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_token_tidak_bisa_dipakai_dua_kali(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        $token = $this->tokenDari($this->tautanTerakhir('budi@kos.test'));

        $this->postJson('/api/atur-sandi', [
            'token' => $token['token'],
            'jenis' => $token['jenis'],
            'password' => 'sandi-pertama',
            'password_confirmation' => 'sandi-pertama',
        ])->assertOk();

        $this->postJson('/api/atur-sandi', [
            'token' => $token['token'],
            'jenis' => $token['jenis'],
            'password' => 'sandi-kedua',
            'password_confirmation' => 'sandi-kedua',
        ])
            ->assertStatus(410)
            ->assertJsonStructure(['errors' => ['token']]);

        // Sandi pertama tetap berlaku: percobaan kedua tidak boleh menyentuh
        // apa pun, termasuk tidak boleh mengubah sandi.
        $this->assertTrue(Hash::check('sandi-pertama', User::where('email', 'budi@kos.test')->firstOrFail()->password));
    }

    public function test_token_kedaluwarsa_ditolak_410(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        $token = $this->tokenDari($this->tautanTerakhir('budi@kos.test'));

        TokenAkun::query()->update(['kedaluwarsa_pada' => now()->subMinute()]);

        $this->postJson('/api/atur-sandi', [
            'token' => $token['token'],
            'jenis' => $token['jenis'],
            'password' => 'sandi-baru-saya',
            'password_confirmation' => 'sandi-baru-saya',
        ])->assertStatus(410);
    }

    public function test_tautan_aktivasi_tidak_bisa_dipakai_sebagai_tautan_reset(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        $token = $this->tokenDari($this->tautanTerakhir('budi@kos.test'));

        // Jenis yang diminta berbeda dari jenis token. Tanpa pemeriksaan ini,
        // tautan aktivasi yang bocor menjadi kunci untuk mengambil alih akun
        // yang sudah aktif - dan tautan aktivasi berlaku tujuh hari.
        $this->postJson('/api/atur-sandi', [
            'token' => $token['token'],
            'jenis' => 'reset',
            'password' => 'sandi-baru-saya',
            'password_confirmation' => 'sandi-baru-saya',
        ])->assertStatus(410);
    }

    public function test_sandi_baru_wajib_diulang_sama_dan_minimal_delapan_karakter(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        $token = $this->tokenDari($this->tautanTerakhir('budi@kos.test'));

        $this->postJson('/api/atur-sandi', [
            'token' => $token['token'],
            'jenis' => $token['jenis'],
            'password' => 'pendek',
            'password_confirmation' => 'pendek',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/atur-sandi', [
            'token' => $token['token'],
            'jenis' => $token['jenis'],
            'password' => 'sandi-baru-saya',
            'password_confirmation' => 'beda-sendiri',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_periksa_token_membedakan_berlaku_dan_tidak_tanpa_membedakan_sebabnya(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        $token = $this->tokenDari($this->tautanTerakhir('budi@kos.test'));

        $this->postJson('/api/periksa-token', [
            'token' => $token['token'],
            'jenis' => $token['jenis'],
        ])
            ->assertOk()
            ->assertJsonPath('data.berlaku', true)
            // REV-SEC-05: email disamarkan, namanya tidak.
            ->assertJsonPath('data.email', 'bu***@kos.test')
            ->assertJsonPath('data.nama', 'Budi Santoso');

        // Token asal-asalan dijawab sama seperti token kedaluwarsa: tidak ada
        // cara membedakan "pernah ada" dari "tidak pernah ada".
        $this->postJson('/api/periksa-token', [
            'token' => str_repeat('a', 64),
            'jenis' => 'aktivasi',
        ])->assertOk()->assertJsonPath('data.berlaku', false);
    }

    public function test_periksa_token_dibatasi_per_ip(): void
    {
        // REV-SEC-05: endpoint ini publik dan menjawab "token ini sah" untuk
        // token acak 64 karakter. Tanpa batas, ia adalah mesin penebak.
        Mail::fake();
        $this->buatPenghuni();

        $token = $this->tokenDari($this->tautanTerakhir('budi@kos.test'));

        // Batasnya 20 per menit; 20 percobaan pertama lolos.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/periksa-token', ['token' => $token['token']])
                ->assertOk();
        }

        $this->postJson('/api/periksa-token', ['token' => $token['token']])
            ->assertStatus(429)
            ->assertJsonValidationErrors('token');
    }

    public function test_periksa_token_tetap_membedakan_berlaku_tanpa_membocorkan_email_lengkap(): void
    {
        // Email lengkap tidak pernah muncul di respons; yang tersisa hanya dua
        // huruf pertama dan domainnya.
        Mail::fake();
        $this->buatPenghuni('siti.aminah@kos.test');

        $token = $this->tokenDari($this->tautanTerakhir('siti.aminah@kos.test'));

        $email = $this->postJson('/api/periksa-token', [
            'token' => $token['token'],
            'jenis' => $token['jenis'],
        ])
            ->assertOk()
            ->json('data.email');

        $this->assertNotSame('siti.aminah@kos.test', $email);
        $this->assertStringStartsWith('si', $email);
        $this->assertStringEndsWith('@kos.test', $email);
        $this->assertStringContainsString('*', $email);
    }

    // --- Lupa sandi --------------------------------------------------------

    public function test_lupa_sandi_selalu_menjawab_sama_untuk_email_terdaftar_maupun_tidak(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        $terdaftar = $this->postJson('/api/lupa-sandi', ['email' => 'budi@kos.test']);
        $tidakAda = $this->postJson('/api/lupa-sandi', ['email' => 'tidak-ada@kos.test']);

        $terdaftar->assertOk();
        $tidakAda->assertOk();

        // Badan respons identik. Jawaban yang berbeda menjadikan endpoint ini
        // alat untuk memeriksa alamat mana yang punya akun di kos ini - dan
        // daftar penghuni adalah data pribadi.
        $this->assertSame($terdaftar->json(), $tidakAda->json());
    }

    public function test_lupa_sandi_mengirim_tautan_reset_untuk_akun_aktif(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        $user = User::where('email', 'budi@kos.test')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->postJson('/api/lupa-sandi', ['email' => 'budi@kos.test'])->assertOk();

        $token = $this->tokenDari($this->tautanTerakhir('budi@kos.test'));

        $this->assertSame('reset', $token['jenis']);
    }

    public function test_lupa_sandi_untuk_akun_belum_aktif_mengirim_tautan_aktivasi(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        $this->postJson('/api/lupa-sandi', ['email' => 'budi@kos.test'])->assertOk();

        // Tautan reset untuk akun yang belum pernah dipakai masuk akan menjadi
        // jalan pintas aktivasi - dan pemilik yang salah mengetik email bisa
        // mengirim tautan reset ke orang yang tidak seharusnya.
        $this->assertSame('aktivasi', $this->tokenDari($this->tautanTerakhir('budi@kos.test'))['jenis']);
    }

    public function test_tautan_reset_berlaku_enam_puluh_menit_sedangkan_aktivasi_tujuh_hari(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        $user = User::where('email', 'budi@kos.test')->firstOrFail();

        $aktivasi = TokenAkun::where('jenis', 'aktivasi')->firstOrFail();
        $this->assertTrue($aktivasi->kedaluwarsa_pada->between(now()->addDays(6), now()->addDays(8)));

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->postJson('/api/lupa-sandi', ['email' => 'budi@kos.test'])->assertOk();

        $reset = TokenAkun::where('jenis', 'reset')->firstOrFail();
        $this->assertTrue($reset->kedaluwarsa_pada->between(now()->addMinutes(59), now()->addMinutes(61)));
    }

    // --- Pembatas percobaan ------------------------------------------------

    public function test_lima_kegagalan_berturut_turut_dijawab_429(): void
    {
        $this->buatPenghuniAktifLewatBasisData();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'budi@kos.test',
                'password' => 'salah',
            ])->assertStatus(401);
        }

        // 429, bukan 401. Bedanya penting bagi layar: 401 berarti "kredensial
        // salah, periksa lagi", 429 berarti "berhenti mencoba, tunggu".
        $this->postJson('/api/login', [
            'email' => 'budi@kos.test',
            'password' => 'salah',
        ])
            ->assertStatus(429)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Terlalu banyak'));
    }

    public function test_kegagalan_tidak_menghukum_pengguna_yang_berhasil_masuk(): void
    {
        $this->buatPenghuniAktifLewatBasisData();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/login', [
                'email' => 'budi@kos.test',
                'password' => 'salah',
            ])->assertStatus(401);
        }

        $this->postJson('/api/login', [
            'email' => 'budi@kos.test',
            'password' => 'password',
        ])->assertOk();

        // Jatah pasangan email+IP dibersihkan oleh keberhasilan. Tanpa ini,
        // pemilik yang salah ketik tiga kali lalu berhasil akan tetap terkunci
        // beberapa menit kemudian - dan tidak ada layar yang menjelaskan kenapa.
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', [
                'email' => 'budi@kos.test',
                'password' => 'salah',
            ])->assertStatus(401);
        }
    }

    public function test_hitungan_lapis_per_menit_kedaluwarsa_dalam_satu_menit(): void
    {
        $this->buatPenghuniAktifLewatBasisData();

        // Tiga kegagalan: cukup untuk menaikkan kedua lapis, belum cukup
        // untuk memicu 429 di lapis mana pun.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/login', [
                'email' => 'budi@kos.test',
                'password' => 'salah',
            ])->assertStatus(401);
        }

        $pasangan = new PembatasMasuk;
        $ipPair = 'masuk:budi@kos.test|127.0.0.1';
        $emailKey = 'masuk-email:budi@kos.test';

        // Lapis pasangan memakai umur SATU MENIT. Kalau ia memakai 3600 detik
        // seperti lapis per-jam, pengguna yang salah ketik lima kali terkunci
        // satu jam - dan pesannya menjanjikan "tunggu sebentar".
        $this->assertLessThanOrEqual(
            PembatasMasuk::DETIK_PER_MENIT,
            RateLimiter::availableIn($ipPair),
            'Hitungan lapis per-menit tidak boleh hidup lebih lama dari satu menit.',
        );

        // Lapis per-jam memang berumur satu jam: ia yang menghentikan tebakan
        // lambat lintas IP, dan memperpendeknya membuatnya tidak berguna.
        $this->assertGreaterThan(
            PembatasMasuk::DETIK_PER_MENIT,
            RateLimiter::availableIn($emailKey),
            'Hitungan lapis per-jam seharusnya bertahan lebih dari satu menit.',
        );
    }

    public function test_permintaan_tautan_dibatasi_tiga_per_jam(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/lupa-sandi', ['email' => 'budi@kos.test'])->assertOk();
        }

        // Lebih ketat daripada masuk, karena setiap permintaan mengirim email
        // ke alamat orang lain - dan biayanya ditanggung pemilik kos.
        $this->postJson('/api/lupa-sandi', ['email' => 'budi@kos.test'])
            ->assertStatus(429);
    }

    /**
     * Email yang tidak terdaftar tidak boleh lebih cepat dijawab.
     *
     * Bukan uji waktu yang presisi - uji ini membandingkan dua jalur yang
     * seharusnya memakan kerja bcrypt yang sama, dan hanya gagal bila salah
     * satunya melewati bcrypt sama sekali. Itu memang yang terjadi sebelum
     * `userTidakDikenal()` dipanggil di jalur "pengguna tidak ada": selisihnya
     * bukan milidetik, melainkan dua orde besaran.
     */
    public function test_lupa_sandi_untuk_email_tidak_terdaftar_tetap_menjalankan_bcrypt(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        $user = User::where('email', 'budi@kos.test')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        // Dua pengukuran pada proses yang sama, supaya biaya bcrypt yang
        // sedang dikonfigurasi ikut terhitung di keduanya.
        $t = microtime(true);
        $this->postJson('/api/lupa-sandi', ['email' => 'budi@kos.test'])->assertOk();
        $terdaftar = microtime(true) - $t;

        $t = microtime(true);
        $this->postJson('/api/lupa-sandi', ['email' => 'hantu@kos.test'])->assertOk();
        $hantu = microtime(true) - $t;

        // Batasnya longgar dengan sengaja: yang diuji "keduanya melewati
        // bcrypt", bukan "waktunya sama sampai mikrodetik". Mesin yang sibuk
        // bisa membuat keduanya berbeda dua kali lipat tanpa ada kebocoran.
        $this->assertGreaterThan(
            $terdaftar / 5,
            $hantu,
            'Jalur email tidak terdaftar jauh lebih cepat - pemeriksaan sandi tiruan tidak berjalan.'
        );
    }

    // --- Penahanan wajib ganti sandi ---------------------------------------

    public function test_akun_wajib_ganti_sandi_ditahan_di_endpoint_lain(): void
    {
        $user = $this->pengelolaWajibGantiSandi();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/kamar')
            ->assertStatus(403)
            ->assertJsonPath('kode', 'wajib_ganti_sandi');
    }

    public function test_akun_wajib_ganti_sandi_tetap_boleh_membaca_dirinya_dan_mengganti_sandi(): void
    {
        $user = $this->pengelolaWajibGantiSandi();
        $token = $user->createToken('spa')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('meta.wajib_ganti_sandi', true);

        // Tanpa jalan keluar ini, penahanan menjadi kurungan: pengguna tidak
        // bisa memanggil endpoint yang justru diminta oleh pesan galatnya.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/ganti-sandi', [
                'password' => 'sandi-baru-saya',
                'password_confirmation' => 'sandi-baru-saya',
            ])
            ->assertOk();

        $this->assertFalse($user->fresh()->wajibGantiSandi());
        $this->assertTrue(Hash::check('sandi-baru-saya', $user->fresh()->password));
    }

    public function test_ganti_sandi_wajib_menyebut_sandi_lama_bila_tidak_sedang_ditahan(): void
    {
        $user = $this->pengelolaWajibGantiSandi();
        $user->forceFill(['wajib_ganti_sandi' => false])->save();

        // Akun yang tidak ditahan harus membuktikan dirinya masih pemilik sandi
        // lama. Tanpa itu, token yang dicuri cukup untuk mengganti sandi dan
        // mengunci pemiliknya keluar.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ganti-sandi', [
                'password' => 'sandi-baru-saya',
                'password_confirmation' => 'sandi-baru-saya',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password_lama');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/ganti-sandi', [
                'password_lama' => 'salah',
                'password' => 'sandi-baru-saya',
                'password_confirmation' => 'sandi-baru-saya',
            ])
            ->assertStatus(401);
    }

    public function test_ganti_sandi_mempertahankan_sesi_sekarang_dan_memutus_yang_lain(): void
    {
        $user = $this->pengelolaWajibGantiSandi();
        $user->createToken('laptop-lain');

        $sekarang = $user->createToken('spa');
        $idSekarang = $sekarang->accessToken->id;

        $this->withHeader('Authorization', 'Bearer '.$sekarang->plainTextToken)
            ->postJson('/api/ganti-sandi', [
                'password' => 'sandi-baru-saya',
                'password_confirmation' => 'sandi-baru-saya',
            ])
            ->assertOk();

        // Memutus semuanya berarti pengguna yang baru saja berhasil mengganti
        // sandi langsung terlempar ke halaman masuk - dan itu terbaca sebagai
        // gagal.
        $this->assertSame([$idSekarang], $user->tokens()->pluck('id')->all());
    }

    public function test_sesi_dibatasi_tiga_dan_yang_terlama_diputus(): void
    {
        $this->buatPenghuniAktifLewatBasisData();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login', [
                'email' => 'budi@kos.test',
                'password' => 'password',
            ])->assertOk();
        }

        $user = User::where('email', 'budi@kos.test')->firstOrFail();

        // Tiga sudah cukup untuk ponsel, laptop, dan komputer kos. Tanpa batas,
        // akun yang tokennya pernah bocor terus menumpuk sesi dan tidak ada satu
        // pun layar yang menunjukkan berapa banyak yang masih hidup.
        $this->assertSame(3, $user->tokens()->count());
    }

    // --- Kirim ulang aktivasi ----------------------------------------------

    public function test_kirim_ulang_aktivasi_untuk_akun_belum_aktif(): void
    {
        Mail::fake();

        $this->sebagaiPemilik()
            ->postJson('/api/pengelola', [
                'nama' => 'Joko',
                'email' => 'joko@kos.test',
                'wewenang' => [Wewenang::KELOLA_KAMAR],
            ])
            ->assertCreated();

        $pengelola = User::where('email', 'joko@kos.test')->firstOrFail();

        $this->sebagaiPemilik()
            ->postJson("/api/pengelola/{$pengelola->id}/kirim-ulang-aktivasi")
            ->assertOk()
            ->assertJsonPath('meta.tautan_aktivasi_terkirim', true);

        // Tautan lama dihapus: dua tautan aktif berarti dua kesempatan masuk,
        // dan yang lebih tua tidak pernah dipakai siapa pun.
        $this->assertSame(1, TokenAkun::where('user_id', $pengelola->id)->count());
    }

    public function test_kirim_ulang_aktivasi_ditolak_untuk_akun_yang_sudah_aktif(): void
    {
        Mail::fake();

        $this->sebagaiPemilik()
            ->postJson('/api/pengelola', [
                'nama' => 'Joko',
                'email' => 'joko@kos.test',
                'wewenang' => [],
            ])
            ->assertCreated();

        $pengelola = User::where('email', 'joko@kos.test')->firstOrFail();
        $pengelola->forceFill(['email_verified_at' => now()])->save();

        // Pemilik yang bisa mengirim ulang tautan aktivasi untuk akun aktif
        // sama saja dengan pemilik yang bisa mengambil alih akun itu.
        $this->sebagaiPemilik()
            ->postJson("/api/pengelola/{$pengelola->id}/kirim-ulang-aktivasi")
            ->assertStatus(409);
    }

    /**
     * Kirim ulang tautan aktivasi untuk penghuni.
     *
     * Endpoint ini ada karena email bisa gagal terkirim tanpa jejak yang
     * terlihat pemilik: `kirimAman()` menelan kegagalan SMTP dan hanya menulis
     * peringatan ke log. Tanpa tombol kirim ulang, satu email yang tersangkut
     * berarti penghuni tidak akan pernah bisa masuk, dan pemilik tidak punya
     * cara lain memperbaikinya - ia memang tidak tahu sandi siapa pun.
     */
    public function test_kirim_ulang_aktivasi_penghuni_untuk_akun_belum_aktif(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        $penghuni = Penghuni::where('email', 'budi@kos.test')->firstOrFail();
        $user = $penghuni->user;

        $tautanPertama = $this->tokenDari($this->tautanTerakhir('budi@kos.test'))['token'];

        $this->sebagaiPemilik()
            ->postJson("/api/penghuni/{$penghuni->id}/kirim-ulang-aktivasi")
            ->assertOk()
            ->assertJsonPath('meta.tautan_aktivasi_terkirim', true)
            ->assertJsonPath('meta.email', 'budi@kos.test');

        $tautanKedua = $this->tokenDari($this->tautanTerakhir('budi@kos.test'))['token'];

        // Tautan lama harus mati. Kalau tidak, kotak masuk penghuni menyimpan
        // dua tautan yang sama-sama sah dan yang mana pun bisa dipakai orang
        // yang menemukan ponselnya.
        $this->assertNotSame($tautanPertama, $tautanKedua);
        $this->assertSame(1, TokenAkun::where('user_id', $user->id)->count());

        $this->postJson('/api/atur-sandi', [
            'token' => $tautanPertama,
            'jenis' => 'aktivasi',
            'password' => 'sandi-baru-sekali',
            'password_confirmation' => 'sandi-baru-sekali',
        ])->assertStatus(410);

        $this->postJson('/api/atur-sandi', [
            'token' => $tautanKedua,
            'jenis' => 'aktivasi',
            'password' => 'sandi-baru-sekali',
            'password_confirmation' => 'sandi-baru-sekali',
        ])->assertOk();
    }

    public function test_kirim_ulang_aktivasi_penghuni_ditolak_bila_akun_sudah_aktif(): void
    {
        Mail::fake();
        $this->buatPenghuni();

        $penghuni = Penghuni::where('email', 'budi@kos.test')->firstOrFail();
        $penghuni->user->forceFill(['email_verified_at' => now()])->save();

        // Sama alasannya dengan pengelola: pemilik yang bisa menerbitkan tautan
        // aktivasi untuk akun aktif bisa mengambil alih akun itu kapan saja,
        // dan seluruh klaim bahwa pemilik tidak memegang sandi penghuni runtuh.
        $this->sebagaiPemilik()
            ->postJson("/api/penghuni/{$penghuni->id}/kirim-ulang-aktivasi")
            ->assertStatus(409);

        $this->assertSame(0, TokenAkun::where('user_id', $penghuni->user_id)->count());
    }

    public function test_kirim_ulang_aktivasi_penghuni_ditolak_bila_tidak_punya_akun(): void
    {
        // Penghuni tanpa akun mungkin ada: data induk bisa dicatat lebih dulu
        // sebelum emailnya diketahui. Jawabannya tetap 409 dengan kalimat yang
        // menjelaskan, bukan 500 karena `$penghuni->user` bernilai null.
        $penghuni = Penghuni::create(['nama' => 'Tanpa Akun', 'email' => 'tanpa@kos.test']);

        $this->sebagaiPemilik()
            ->postJson("/api/penghuni/{$penghuni->id}/kirim-ulang-aktivasi")
            ->assertStatus(409)
            ->assertJsonValidationErrors('penghuni');
    }

    public function test_mengganti_email_penghuni_belum_aktif_mengirim_tautan_baru(): void
    {
        // REV-SEC-01 mempersempit jalur ini, tidak menutupnya: alamat yang
        // salah ketik memang hanya bisa ada pada akun yang belum aktif, dan
        // untuk akun seperti itu mengganti alamatnya justru satu-satunya jalan
        // keluar. Yang ditutup adalah penggantian alamat pada akun AKTIF.
        Mail::fake();
        $this->buatPenghuni();

        $user = User::where('email', 'budi@kos.test')->firstOrFail();
        $user->createToken('spa');

        $penghuni = Penghuni::where('email', 'budi@kos.test')->firstOrFail();

        $this->sebagaiPemilik()
            ->putJson("/api/penghuni/{$penghuni->id}", [
                'nama' => 'Budi Santoso',
                'email' => 'budi-baru@kos.test',
            ])
            ->assertOk()
            ->assertJsonPath('meta.tautan_aktivasi_terkirim', true);

        $baru = $user->fresh();

        // Tautan lama dikirim ke alamat yang mungkin bukan milik penghuni ini,
        // jadi ia tidak boleh lagi bisa dipakai - dan sesi lama ikut diputus.
        $this->assertNull($baru->email_verified_at);
        $this->assertSame(0, $baru->tokens()->count());
        $this->assertSame('aktivasi', $this->tokenDari($this->tautanTerakhir('budi-baru@kos.test'))['jenis']);
    }

    public function test_mengganti_email_penghuni_aktif_ditolak(): void
    {
        // REV-SEC-01 - jalan pengambilalihan yang ditutup.
        //
        // Sebelum perbaikan ini, pemilik cukup mengubah email penghuni menjadi
        // alamat yang ia kuasai; akun kembali "belum aktif", lalu "kirim ulang
        // aktivasi" mengirim tautan ke kotak surat pemilik sendiri. Pemilik
        // menetapkan sandi, masuk sebagai penghuni, dan R-07 runtuh.
        Mail::fake();
        $this->buatPenghuni();

        $user = User::where('email', 'budi@kos.test')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        $penghuni = Penghuni::where('email', 'budi@kos.test')->firstOrFail();

        $this->sebagaiPemilik()
            ->putJson("/api/penghuni/{$penghuni->id}", [
                'nama' => 'Budi Santoso',
                'email' => 'milik-pemilik@kos.test',
            ])
            ->assertStatus(409)
            ->assertJsonValidationErrors('email');

        // Tidak ada yang berubah: alamatnya tetap, aktivasi tetap, sesi tetap.
        $this->assertSame('budi@kos.test', $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame('budi@kos.test', $penghuni->fresh()->email);

        // Nama tetap boleh diubah - yang dikunci hanya alamat emailnya.
        $this->sebagaiPemilik()
            ->putJson("/api/penghuni/{$penghuni->id}", [
                'nama' => 'Budi Santoso Wibowo',
                'email' => 'budi@kos.test',
            ])
            ->assertOk();
    }

    public function test_mengganti_email_pengelola_membatalkan_aktivasi_sebelumnya(): void
    {
        Mail::fake();

        $this->sebagaiPemilik()
            ->postJson('/api/pengelola', [
                'nama' => 'Joko',
                'email' => 'joko@kos.test',
                'wewenang' => [],
            ])
            ->assertCreated();

        $pengelola = User::where('email', 'joko@kos.test')->firstOrFail();
        $pengelola->forceFill(['email_verified_at' => now()])->save();

        $this->sebagaiPemilik()
            ->putJson("/api/pengelola/{$pengelola->id}", [
                'nama' => 'Joko',
                'email' => 'joko-baru@kos.test',
                'wewenang' => [],
            ])
            ->assertOk();

        $this->assertNull($pengelola->fresh()->email_verified_at);
    }

    /**
     * Penghuni aktif yang dibuat langsung lewat basis data.
     *
     * Dipakai tes pembatas percobaan, yang butuh sandi yang DIKETAHUI untuk
     * membedakan "gagal karena sandi salah" dari "gagal karena belum aktif".
     * Jalur HTTP tidak bisa menghasilkan keadaan ini lagi sejak T27 - dan itu
     * memang yang diinginkan.
     */
    private function buatPenghuniAktifLewatBasisData(): User
    {
        $penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'email' => 'budi@kos.test']);

        return User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $penghuni->id,
            'email_verified_at' => now(),
        ]);
    }

    private function pengelolaWajibGantiSandi(): User
    {
        $user = User::create([
            'name' => 'Sari Pengelola',
            'email' => 'pengelola@kos.test',
            'password' => Hash::make('sandi-lama'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $this->pemilik->id,
            'email_verified_at' => now(),
        ]);

        PengelolaKewenangan::create([
            'user_id' => $user->id,
            'wewenang' => Wewenang::KELOLA_KAMAR,
        ]);

        $user->forceFill(['wajib_ganti_sandi' => true])->save();

        return $user->fresh();
    }
}
