<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Origin yang boleh memanggil API
    |--------------------------------------------------------------------------
    |
    | Nilai bawaannya `*` di luar produksi, dan itu wajar: SPA lokal dilayani
    | dari port 3000 sementara API dari port 8081, dan pada demo keduanya
    | berjalan di satu laptop.
    |
    | Di produksi `*` adalah bahaya yang berbeda jenis. Aplikasi ini memakai
    | token Bearer, bukan cookie, sehingga situs lain tidak otomatis membawa
    | sesi pengguna - tetapi `*` tetap berarti situs mana pun boleh MEMBACA
    | jawaban API bila ia berhasil memperoleh token, dan itu menghapus satu
    | lapis pertahanan yang tidak ada alasan untuk dihapus.
    |
    | Karena itu di produksi daftarnya WAJIB eksplisit lewat
    | `CORS_ALLOWED_ORIGINS`. Bila variabel itu lupa diisi, bawaannya adalah
    | `APP_URL` - satu origin, yaitu aplikasi itu sendiri. Itu pilihan yang
    | dipilih dengan sengaja: nilai bawaan yang aman tidak bisa terlupa,
    | sedangkan nilai bawaan yang berbahaya akan terlupa tepat sekali.
    |
    */
    // Ditulis sebagai closure, bukan rangkaian array_map/array_filter bertumpuk:
    // rantai seperti itu mudah salah kurung, dan kesalahannya baru ketahuan saat
    // image dibangun - bukan saat berkas ini dibaca.
    'allowed_origins' => (static function (): array {
        $dariEnv = trim((string) env('CORS_ALLOWED_ORIGINS', ''));

        if ($dariEnv !== '') {
            $daftar = array_map('trim', explode(',', $dariEnv));

            return array_values(array_filter($daftar, fn ($o) => $o !== ''));
        }

        if (env('APP_ENV', 'production') === 'production') {
            $aplikasi = rtrim((string) env('APP_URL', ''), '/');

            // Tanpa APP_URL yang masuk akal, menebak berarti membuka. Lebih
            // baik menolak semua origin lintas-situs daripada menerima
            // semuanya - SPA tetap bekerja karena ia satu origin dengan API.
            return $aplikasi === '' ? [] : [$aplikasi];
        }

        return ['*'];
    })(),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    /*
     * Header yang boleh dibaca JavaScript. Default kosong, jadi `fetch()` di
     * browser tidak bisa membaca header respons apa pun selain yang
     * disederhanakan CORS — termasuk X-Simulasi-Dipakai, yang justru dipakai
     * layar untuk memberi tahu apakah tanggal simulasi berlaku. Tanpa ini,
     * demonya berjalan tetapi UI-nya tidak bisa menjelaskan apa yang terjadi.
     */
    'exposed_headers' => [
        'X-Simulasi-Dipakai',
        'X-Tanggal-Sistem',
    ],

    'max_age' => 0,

    'supports_credentials' => false,

];
