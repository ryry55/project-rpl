<?php

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
            'peran' => \App\Http\Middleware\PastikanPeran::class,
        ]);

        // Semua /api/* selalu dijawab JSON: 401, 403, 404, 422 — tidak pernah
        // redirect dan tidak pernah HTML. Lihat App\Http\Middleware\PaksaJson.
        $middleware->prependToGroup('api', \App\Http\Middleware\PaksaJson::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Semua permintaan ke /api/* dijawab JSON, termasuk 401 dan 404,
        // supaya SPA tidak pernah menerima halaman HTML.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
