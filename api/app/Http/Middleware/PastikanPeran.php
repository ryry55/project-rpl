<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batasi endpoint ke peran tertentu.
 *
 * Dipakai sebagai `->middleware('peran:pemilik')`. Peran yang tidak cocok
 * dibalas 403 (bukan 404), karena pengguna memang berhak login — hanya tidak
 * berhak atas resource itu. Bandingkan dengan R-07, yang memakai 404 untuk
 * menyembunyikan keberadaan tagihan milik penghuni lain.
 */
class PastikanPeran
{
    public function handle(Request $request, Closure $next, string ...$peran): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Belum terautentikasi.');
        }

        if (! in_array($user->peran, $peran, true)) {
            abort(403, 'Peran Anda tidak berhak mengakses sumber daya ini.');
        }

        return $next($request);
    }
}
