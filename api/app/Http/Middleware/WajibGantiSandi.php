<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menahan akun yang wajib mengganti sandinya (T27).
 *
 * Akun yang dibuat sebelum T27 sandinya diketik oleh pemilik. Sandi itu masih
 * sah - karena itu akunnya tetap bisa masuk - tetapi pemilik mengetahuinya, dan
 * selama pemilik mengetahuinya, jejak audit yang menyebut nama akun itu tidak
 * membuktikan apa pun. Menghapus medan sandi dari formulir tidak menghapus
 * pengetahuan yang sudah ada.
 *
 * Middleware ini menutup SELURUH endpoint kecuali yang dibutuhkan untuk
 * mengganti sandi. Daftar putihnya sengaja sangat pendek dan diperiksa dengan
 * pencocokan nama rute, bukan prefiks jalur: prefiks `/api/auth` yang keliru
 * ditulis akan membuka seluruh `/api` tanpa ada yang menyadarinya.
 *
 * Jawabannya 403 dengan kode `wajib_ganti_sandi` di badan respons. Layar
 * memakai kode itu untuk mengalihkan ke form ganti sandi, bukan menampilkan
 * pesan galat - keadaan ini bukan kesalahan pengguna, melainkan langkah yang
 * memang harus dilalui.
 */
class WajibGantiSandi
{
    /**
     * Rute yang tetap boleh dipanggil saat sandi wajib diganti.
     *
     * Semuanya diperlukan agar penggantian sandi mungkin dilakukan: tahu siapa
     * dirinya (`me`), mengganti sandi (`ganti-sandi`), keluar, dan membaca
     * notifikasi - karena notifikasi bisa berisi tautan yang menjelaskan kenapa
     * akun ini ditahan.
     *
     * @var list<string>
     */
    private const RUTE_DIIZINKAN = [
        'api.me',
        'api.logout',
        'api.ganti-sandi',
        'notifikasi.index',
        'notifikasi.baca',
        'notifikasi.baca-semua',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'wajibGantiSandi') || ! $user->wajibGantiSandi()) {
            return $next($request);
        }

        $rute = $request->route()?->getName();

        if ($rute !== null && in_array($rute, self::RUTE_DIIZINKAN, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Kata sandi akun ini harus diganti sebelum aplikasi bisa dipakai.',
            'kode' => 'wajib_ganti_sandi',
        ], 403);
    }
}
