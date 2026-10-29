<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Penerima laporan pelanggaran CSP (FASE 4.2).
 *
 * Endpoint ini tidak mengubah apa pun di basis data, jadi yang diuji bukan
 * "datanya tersimpan" - melainkan tiga sifat yang membuatnya aman dipasang di
 * internet tanpa autentikasi:
 *
 *   1. **Selalu 204**, termasuk untuk badan yang tidak berbentuk laporan.
 *      Peramban tidak membaca jawabannya; 4xx hanya menghasilkan pengiriman
 *      ulang yang sama, berulang.
 *   2. **Yang dicatat hanya medan yang dikenal, dan dipotong.** Tanpa itu, satu
 *      permintaan bisa menulis badan sebesar apa pun ke log.
 *   3. **Dibatasi laju.** Satu halaman yang salah bisa menghasilkan ratusan
 *      laporan; tanpa pembatas, satu tab memenuhi log.
 */
class CspLaporanTest extends TestCase
{
    use RefreshDatabase;

    private function kirim(array $badan, string $jenisIsi = 'application/csp-report'): TestResponse
    {
        return $this->call(
            'POST',
            '/csp-laporan',
            [],
            [],
            [],
            ['CONTENT_TYPE' => $jenisIsi, 'HTTP_ACCEPT' => 'application/json'],
            json_encode($badan),
        );
    }

    public function test_laporan_dikenal_dicatat_dengan_medan_yang_dikenal_saja(): void
    {
        Log::spy();

        $this->kirim([
            'csp-report' => [
                'document-uri' => 'https://kos.test/pemilik/tagihan',
                'blocked-uri' => 'inline',
                'violated-directive' => 'script-src',
                'effective-directive' => 'script-src-elem',
                'disposition' => 'report',
                // Medan yang tidak dikenal tidak boleh ikut tercatat: apa pun
                // yang dikirim ke sini datang dari luar.
                'documenturi' => 'https://kos.test/palsu',
                'sesuatu-yang-aneh' => str_repeat('x', 500),
            ],
        ])->assertNoContent();

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $pesan, array $konteks) {
            return $pesan === 'csp.lapor'
                && $konteks['blocked-uri'] === 'inline'
                && $konteks['violated-directive'] === 'script-src'
                && ! array_key_exists('documenturi', $konteks)
                && ! array_key_exists('sesuatu-yang-aneh', $konteks);
        });
    }

    public function test_medan_panjang_dipotong_sebelum_masuk_log(): void
    {
        Log::spy();

        $this->kirim([
            'csp-report' => [
                'document-uri' => 'https://kos.test/'.str_repeat('a', 2000),
                'script-sample' => str_repeat('b', 500),
            ],
        ])->assertNoContent();

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $pesan, array $konteks) {
            return $pesan === 'csp.lapor'
                && mb_strlen($konteks['document-uri']) === 200
                && mb_strlen($konteks['script-sample']) === 80;
        });
    }

    public function test_badan_bukan_laporan_tetap_dijawab_204_tanpa_menulis_log(): void
    {
        Log::spy();

        // Bukan JSON sama sekali.
        $this->call(
            'POST',
            '/csp-laporan',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            'bukan json',
        )->assertNoContent();

        // JSON, tetapi bentuknya salah.
        $this->kirim(['halo' => 'dunia'])->assertNoContent();
        $this->kirim(['csp-report' => 'bukan objek'])->assertNoContent();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_pengiriman_berulang_dibatasi_laju(): void
    {
        Log::spy();

        for ($i = 1; $i <= 30; $i++) {
            $this->kirim(['csp-report' => ['blocked-uri' => 'inline']])->assertNoContent();
        }

        $this->kirim(['csp-report' => ['blocked-uri' => 'inline']])->assertStatus(429);
    }
}
