<?php

use App\Http\Middleware\CatatPermintaanGagal;
use App\Http\Middleware\PaksaJson;
use App\Http\Middleware\PastikanPeran;
use App\Http\Middleware\PastikanWewenang;
use App\Http\Middleware\SimulasiTanggal;
use App\Http\Middleware\TanpaCache;
use App\Http\Middleware\WajibGantiSandi;
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
        // ------------------------------------------------------------------
        // A1 (FASE 0.1) - percayai header proxy, tetapi HANYA yang dibutuhkan.
        //
        // Sebelum ini middleware-nya tidak ada sama sekali, sehingga
        // `$request->ip()` mengembalikan IP proxy untuk SEMUA pengguna. Dua
        // akibat nyata, keduanya pernah diukur:
        //
        //   1. Pembatas laju `periksa-token` (20/menit per IP) menghitung
        //      seluruh pengguna sebagai satu IP, sehingga percobaan ke-21 dari
        //      orang yang BERBEDA ikut ditolak. Ini terbukti di demo lewat
        //      `http://localhost:3000`: 25 permintaan dengan X-Forwarded-For
        //      berbeda, 20 dijawab 200 lalu 5 dijawab 429.
        //   2. Log audit mencatat IP container `web` (172.20.0.4), bukan IP
        //      klien - sehingga catatan siapa melakukan apa tidak berarti.
        //
        // `at` SENGAJA berisi rentang privat, BUKAN `'*'`.
        //
        // Ini hasil pengukuran, bukan pilihan gaya. `'*'` di Laravel TIDAK
        // berarti "percayai semua proxy"; ia berarti "percayai HANYA alamat
        // yang sedang memanggil" (`setTrustedProxyIpAddressesToTheCallingIp`).
        // Untuk satu hop itu cukup. Demo ini punya DUA hop - klien -> `web`
        // -> `api-web` -> php-fpm - sehingga yang memanggil php-fpm adalah
        // `api-web`, sedangkan entri terakhir di X-Forwarded-For adalah
        // gateway `web` (172.20.0.1). Hasilnya IP gateway, bukan IP klien:
        //
        //   at: '*'                  -> ip() = 172.20.0.1  (salah)
        //   at: [rentang privat]     -> ip() = 203.0.113.1  (benar)
        //
        // Keduanya diukur langsung lewat tinker dan lewat HTTP.
        //
        // Rentang privat dipakai karena seluruh hop di sistem ini memang
        // berada di jaringan privat: di demo antarkontainer Docker
        // (172.16/12), di Render antarlayanan internal. Alamat publik TIDAK
        // dipercaya, sehingga X-Forwarded-For yang datang dari internet
        // tidak bisa dipakai memalsukan IP.
        //
        // Konsekuensi yang harus disadari: bila kelak aplikasi dipasang di
        // belakang proxy yang alamatnya publik (mis. Cloudflare tanpa
        // jaringan privat), daftar ini harus ditambah alamat proxy itu.
        // Menambahkannya sembarangan membuka pemalsuan IP.
        $middleware->trustProxies(
            at: [
                '127.0.0.1',        // loopback
                '172.16.0.0/12',    // Docker, Render internal
                '192.168.0.0/16',   // LAN
                '10.0.0.0/8',       // VPC
                '::1',              // loopback IPv6
            ],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'peran' => PastikanPeran::class,
            'wewenang' => PastikanWewenang::class,
            'wajib_ganti_sandi' => WajibGantiSandi::class,
        ]);

        // Semua /api/* selalu dijawab JSON: 401, 403, 404, 422 — tidak pernah
        // redirect dan tidak pernah HTML. Lihat App\Http\Middleware\PaksaJson.
        $middleware->prependToGroup('api', PaksaJson::class);

        // "Hari ini" harus sudah ditetapkan sebelum controller mana pun berjalan,
        // karena denda dan umur tunggakan membacanya. Ditempel ke seluruh grup
        // api, bukan hanya endpoint denda: dua layar yang memakai tanggal acuan
        // berbeda untuk tagihan yang sama akan tampak seperti bug.
        $middleware->prependToGroup('api', SimulasiTanggal::class);

        // Log terstruktur untuk setiap respons >= 400 (T29).
        //
        // `prependToGroup`, BUKAN `appendToGroup`. Middleware yang didaftarkan
        // lebih dulu berjalan lebih luar pada permintaan, dan itu yang
        // dibutuhkan di sini: 401 dari `auth:sanctum` dan 403 dari `wewenang:`
        // DILEMPAR sebelum handler rute berjalan, sehingga middleware yang
        // berada di dalam keduanya tidak pernah melihatnya.
        $middleware->prependToGroup('api', CatatPermintaanGagal::class);

        // C7 - jawaban JSON tidak boleh tersimpan di cache peramban.
        // Ditempel paling luar supaya ia melihat respons akhir, termasuk yang
        // dibuat pengendali galat (401 dari `auth:sanctum`, 422 dari validasi).
        $middleware->prependToGroup('api', TanpaCache::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Semua permintaan ke /api/* dijawab JSON, termasuk 401 dan 404,
        // supaya SPA tidak pernah menerima halaman HTML.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
