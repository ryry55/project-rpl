<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jawaban JSON API tidak boleh disimpan di cache mana pun (C7).
 *
 * Bawaan Laravel sudah mengirim `Cache-Control: no-cache, private`, dan itu
 * menutup cache bersama (proxy, CDN). Yang belum tertutup adalah cache
 * peramban: `private` masih mengizinkan isinya tersimpan di disk mesin yang
 * dipakai bergantian di kantor kos, dan `no-cache` hanya mewajibkan validasi
 * ulang - validasi yang tidak akan terjadi, karena API ini tidak mengirim
 * ETag maupun Last-Modified.
 *
 * Yang TIDAK ditimpa: ekspor CSV (`no-store` sendiri) dan gambar QRIS
 * (`private, max-age=300`, supaya panel tidak mengunduh ulang setiap kali
 * dibuka). Karena itu penyaringnya jenis isi, bukan jalur URL.
 */
class TanpaCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (str_contains((string) $response->headers->get('Content-Type'), 'application/json')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
