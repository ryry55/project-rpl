<?php

namespace Tests\Feature;

use App\Models\Penghuni;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Seam 2 — konvensi HTTP untuk T1.
 *
 * Yang diuji di sini bukan logika bisnis, melainkan kontrak yang akan dipakai
 * seluruh tiket berikutnya: bentuk respons, kode status, dan pemisahan peran.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function buatPemilik(): User
    {
        return User::create([
            'name' => 'Pemilik Kos',
            'email' => 'pemilik@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PEMILIK,
        ]);
    }

    private function buatPenghuni(): User
    {
        $penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'no_hp' => '081200000001']);

        return User::create([
            'name' => 'Budi Santoso',
            'email' => 'penghuni@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $penghuni->id,
        ]);
    }

    public function test_login_dengan_kredensial_benar_mengembalikan_token(): void
    {
        $this->buatPemilik();

        $respons = $this->postJson('/api/login', [
            'email' => 'pemilik@kos.test',
            'password' => 'password',
        ]);

        $respons->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'nama', 'email', 'peran']]])
            ->assertJsonPath('data.user.peran', 'pemilik');

        $this->assertNotEmpty($respons->json('data.token'));
    }

    public function test_login_dengan_password_salah_ditolak_401(): void
    {
        $this->buatPemilik();

        $this->postJson('/api/login', [
            'email' => 'pemilik@kos.test',
            'password' => 'salah',
        ])->assertStatus(401)
            ->assertJsonStructure(['message']);
    }

    public function test_login_dengan_email_tidak_terdaftar_ditolak_401(): void
    {
        $this->postJson('/api/login', [
            'email' => 'tidak-ada@kos.test',
            'password' => 'password',
        ])->assertStatus(401);
    }

    public function test_login_tanpa_email_ditolak_422(): void
    {
        $this->postJson('/api/login', ['password' => 'password'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['email']]);
    }

    public function test_me_mengembalikan_peran_pengguna_yang_login(): void
    {
        $pemilik = $this->buatPemilik();

        $this->actingAs($pemilik, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.peran', 'pemilik')
            ->assertJsonPath('data.email', 'pemilik@kos.test');
    }

    public function test_me_tanpa_token_ditolak_401(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_me_dengan_token_tidak_valid_ditolak_401(): void
    {
        $this->withHeader('Authorization', 'Bearer token-palsu')
            ->getJson('/api/me')
            ->assertStatus(401);
    }

    public function test_penghuni_mengakses_endpoint_pemilik_ditolak_403(): void
    {
        $penghuni = $this->buatPenghuni();

        $this->actingAs($penghuni, 'sanctum')
            ->getJson('/api/pemilik/saja')
            ->assertStatus(403);
    }

    public function test_pemilik_mengakses_endpoint_pemilik_diizinkan(): void
    {
        $pemilik = $this->buatPemilik();

        $this->actingAs($pemilik, 'sanctum')
            ->getJson('/api/pemilik/saja')
            ->assertOk();
    }

    public function test_logout_mencabut_token(): void
    {
        $pemilik = $this->buatPemilik();
        $token = $pemilik->createToken('spa')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_respons_error_selalu_json_bukan_html(): void
    {
        $respons = $this->getJson('/api/me');

        $respons->assertStatus(401);
        $this->assertStringContainsString(
            'application/json',
            $respons->headers->get('Content-Type') ?? ''
        );
    }

    /**
     * Regresi: klien tanpa header Accept (curl, PowerShell, browser) sempat
     * mendapat 500 "Route [login] not defined" karena middleware auth mencoba
     * me-redirect ke route web yang tidak ada.
     */
    public function test_tanpa_header_accept_tetap_401_bukan_500(): void
    {
        $respons = $this->call('GET', '/api/me');

        $respons->assertStatus(401);
        $this->assertStringContainsString(
            'application/json',
            $respons->headers->get('Content-Type') ?? ''
        );
    }

    public function test_validasi_gagal_tanpa_header_accept_tetap_422_bukan_302(): void
    {
        $respons = $this->call('POST', '/api/login', ['password' => 'x']);

        $respons->assertStatus(422);
    }

    public function test_zona_waktu_aplikasi_asia_jakarta(): void
    {
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
        $this->assertSame('Asia/Jakarta', date_default_timezone_get());
    }
}
