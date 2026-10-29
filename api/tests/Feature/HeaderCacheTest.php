<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C7 - jawaban API tidak disimpan di cache peramban.
 *
 * Bawaan Laravel sudah mengirim `Cache-Control: no-cache, private`. Itu menutup
 * cache bersama, tetapi masih mengizinkan isinya tersimpan di disk peramban -
 * dan `no-cache` hanya mewajibkan validasi ulang, yang tidak akan terjadi karena
 * API ini tidak mengirim ETag. `TanpaCache` menaikkannya menjadi `no-store`.
 *
 * Dua sisi yang diuji, dan keduanya perlu: jawaban sukses **dan** jawaban galat.
 * Yang kedua mudah terlewat karena ia dibuat pengendali galat, bukan pengendali
 * rute - dan justru jawaban 401 yang membawa informasi "tagihan siapa".
 */
class HeaderCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_jawaban_json_sukses_tidak_boleh_disimpan(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_jawaban_json_galat_juga_tidak_boleh_disimpan(): void
    {
        $this->getJson('/api/tagihan')
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
