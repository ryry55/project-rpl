<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paksa seluruh permintaan /api/* dianggap mengharapkan JSON.
 *
 * Tanpa ini, Laravel memutuskan format respons dari header `Accept`. Klien yang
 * tidak mengirim `Accept: application/json` (curl, PowerShell, browser yang
 * membuka URL langsung) akan diperlakukan sebagai permintaan web: middleware
 * `auth` mencoba me-redirect ke route bernama `login` — yang pada aplikasi
 * API-only tidak ada — sehingga hasilnya 500, bukan 401. Validasi yang gagal
 * juga akan membalas 302, bukan 422.
 *
 * Menyetel header di sini membuat perilaku API konsisten untuk semua klien,
 * sesuai SPEC §7: semua error berbentuk JSON.
 */
class PaksaJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
