<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batasi endpoint ke pemilik dan pengelola yang punya wewenang tertentu (T24).
 *
 * Dipakai sebagai `->middleware('wewenang:ubah_tarif')`, dan beberapa wewenang
 * sekaligus bila pemanggilnya menerima salah satu: `wewenang:a,b` lolos bila
 * pengguna punya a **atau** b.
 *
 * Bedanya dengan `peran`:
 *
 *   - `peran:pemilik` memeriksa jenis akun. Dipakai untuk endpoint yang memang
 *     hanya milik pemilik dan tidak mungkin didelegasikan.
 *   - `wewenang:...` memeriksa izin. Pemilik selalu lolos; pengelola lolos bila
 *     wewenangnya tercatat; penghuni selalu 403.
 *
 * Kenapa 403 dan bukan 404: pengguna memang berhak login dan endpoint ini
 * memang ada. Yang kurang hanya izinnya, dan menyembunyikan itu tidak
 * melindungi apa pun - berbeda dari R-07, yang memakai 404 untuk menyembunyikan
 * keberadaan tagihan milik penghuni lain.
 *
 * Tanpa middleware ini, "pengelola" sama saja dengan "pemilik kedua", dan satu
 * akun yang bocor bisa mengubah tarif seluruh kamar.
 */
class PastikanWewenang
{
    public function handle(Request $request, Closure $next, string ...$wewenang): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Belum terautentikasi.');
        }

        // Pemilik selalu lolos, termasuk untuk wewenang yang belum dikenal -
        // daftar wewenang adalah daftar izin yang bisa didelegasikan, bukan
        // daftar hal yang boleh dilakukan pemilik.
        if ($user->isPemilik()) {
            return $next($request);
        }

        if (! $user->isPengelola()) {
            abort(403, 'Peran Anda tidak berhak mengakses sumber daya ini.');
        }

        foreach ($wewenang as $satu) {
            if ($user->boleh($satu)) {
                return $next($request);
            }
        }

        abort(403, 'Akun Anda tidak memiliki wewenang untuk tindakan ini.');
    }
}
