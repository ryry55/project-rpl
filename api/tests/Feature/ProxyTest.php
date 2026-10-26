<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * A1 — aplikasi harus mempercayai header proxy, dan HANYA dari proxy.
 *
 * Tes ini adalah lapis cepat dari `docs/uji/uji-proxy.mjs`. Bedanya:
 *
 *   - Tes ini berjalan di dalam satu proses PHP, memakai request buatan.
 *     Ia membuktikan LOGIKA-nya benar dan cepat (bagian dari 748 tes).
 *   - `uji-proxy.mjs` menembak HTTP sungguhan lewat nginx. Ia membuktikan
 *     header benar-benar SAMPAI ke PHP - sesuatu yang tidak bisa dibuktikan
 *     dari dalam proses PHP, dan justru di situlah cacatnya bersembunyi
 *     (nginx membuang header sebelum Laravel melihatnya).
 *
 * Keduanya dibutuhkan. Tes ini sendiri akan lulus meski nginx membuang
 * headernya, jadi ia TIDAK cukup - tetapi ia menangkap regresi bila daftar
 * proxy atau daftar header diperkecil tanpa sengaja.
 */
class ProxyTest extends TestCase
{
    /**
     * Membuat request seolah datang dari proxy di jaringan privat.
     *
     * Middleware-nya dipanggil LANGSUNG, bukan lewat kernel. Memakai
     * `$kernel->handle()` akan menjalankan seluruh siklus permintaan -
     * menyentuh basis data, memicu middleware lain, dan menghasilkan respons
     * yang tidak ada hubungannya dengan yang diuji di sini.
     *
     * Tetap sah sebagai uji konfigurasi: `$middleware->trustProxies(...)` di
     * bootstrap/app.php menulis ke state statis kelas `TrustProxies`, dan
     * instans baru membaca state itu. Jadi tes ini gagal bila daftar proxy
     * atau daftar header di bootstrap/app.php berubah.
     */
    private function permintaanDariProxy(array $header = [], string $remoteAddr = '172.20.0.5'): Request
    {
        $request = Request::create('/api/tagihan', 'GET');
        $request->server->set('REMOTE_ADDR', $remoteAddr);

        foreach ($header as $nama => $nilai) {
            $request->headers->set($nama, $nilai);
        }

        (new TrustProxies)->handle($request, fn ($r) => $r);

        return $request;
    }

    public function test_ip_klien_dibaca_dari_x_forwarded_for(): void
    {
        $request = $this->permintaanDariProxy([
            'X-Forwarded-For' => '203.0.113.45',
        ]);

        $this->assertSame(
            '203.0.113.45',
            $request->ip(),
            'IP klien harus diambil dari X-Forwarded-For, bukan REMOTE_ADDR.'
        );
    }

    /**
     * Dua hop, seperti jalur demo: klien -> web -> api-web -> php-fpm.
     *
     * Inilah yang membedakan `at: '*'` dari `at: [rentang privat]`. Dengan
     * `'*'`, Laravel hanya mempercayai alamat pemanggil (api-web), sehingga
     * entri terakhir - gateway `web` - dikembalikan sebagai "IP klien".
     */
    public function test_dua_hop_proxy_menghasilkan_ip_klien_bukan_ip_gateway(): void
    {
        $request = $this->permintaanDariProxy([
            'X-Forwarded-For' => '203.0.113.45, 172.20.0.1',
        ]);

        $this->assertSame('203.0.113.45', $request->ip());
        $this->assertNotSame('172.20.0.1', $request->ip());
    }

    public function test_x_forwarded_proto_https_membuat_request_dianggap_aman(): void
    {
        $request = $this->permintaanDariProxy([
            'X-Forwarded-Proto' => 'https',
        ]);

        $this->assertTrue(
            $request->isSecure(),
            'Tanpa ini, tautan di email aktivasi dibangun sebagai http:// '
            .'padahal situsnya https://.'
        );
    }

    public function test_tanpa_header_proto_request_tetap_dianggap_tidak_aman(): void
    {
        $request = $this->permintaanDariProxy();

        $this->assertFalse(
            $request->isSecure(),
            'Tanpa header, permintaan memang http. Kalau ini true, berarti '
            .'skema dipaksa dan bukan dibaca - dan itu menyembunyikan salah '
            .'konfigurasi, bukan memperbaikinya.'
        );
    }

    /**
     * Alamat publik TIDAK boleh dipercaya.
     *
     * Kalau ini gagal, siapa pun di internet bisa mengirim X-Forwarded-For
     * berisi alamat apa pun dan lolos dari pembatas laju per IP - termasuk
     * pembatas 20/menit pada /periksa-token dan 5/menit pada /login.
     */
    public function test_proxy_beralamat_publik_tidak_dipercaya(): void
    {
        $request = $this->permintaanDariProxy(
            ['X-Forwarded-For' => '203.0.113.45'],
            '8.8.8.8'
        );

        $this->assertSame(
            '8.8.8.8',
            $request->ip(),
            'X-Forwarded-For dari alamat publik harus DIABAIKAN.'
        );
    }

    /**
     * X-Forwarded-Host tidak dipercaya.
     *
     * Tautan di aplikasi ini dibangun dari config('app.url_spa'), bukan dari
     * Host permintaan. Mempercayai header ini menambah permukaan serangan
     * tanpa menyelesaikan masalah apa pun.
     */
    public function test_x_forwarded_host_tidak_mengubah_host(): void
    {
        $request = $this->permintaanDariProxy([
            'Host' => 'kos.test',
            'X-Forwarded-Host' => 'penyerang.example',
        ]);

        $this->assertSame('kos.test', $request->getHost());
    }
}
