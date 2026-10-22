<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Aplikasi ini API-only: SPA Vue dilayani terpisah oleh container `web`.
| Berkas ini sengaja kosong selain health-check sederhana.
|
*/

Route::get('/', fn () => response()->json([
    'data' => [
        'aplikasi' => 'Sistem Informasi Operasional Kos-Kosan',
        'api' => '/api',
    ],
]));
