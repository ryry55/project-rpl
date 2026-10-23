<?php

use App\Http\Middleware\PaksaJson;
use App\Http\Middleware\PastikanPeran;
use App\Http\Middleware\PastikanWewenang;
use App\Http\Middleware\SimulasiTanggal;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'peran' => PastikanPeran::class,
            'wewenang' => PastikanWewenang::class,
        ]);

        // Semua /api/* selalu dijawab JSON: 401, 403, 404, 422 — tidak pernah
        // redirect dan tidak pernah HTML. Lihat App\Http\Middleware\PaksaJson.
        $middleware->prependToGroup('api', PaksaJson::class);

        // "Hari ini" harus sudah ditetapkan sebelum controller mana pun berjalan,
        // karena denda dan umur tunggakan membacanya. Ditempel ke seluruh grup
        // api, bukan hanya endpoint denda: dua layar yang memakai tanggal acuan
        // berbeda untuk tagihan yang sama akan tampak seperti bug.
        $middleware->prependToGroup('api', SimulasiTanggal::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Semua permintaan ke /api/* dijawab JSON, termasuk 401 dan 404,
        // supaya SPA tidak pernah menerima halaman HTML.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
