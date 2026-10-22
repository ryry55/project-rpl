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

    'allowed_origins' => ['*'],

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
