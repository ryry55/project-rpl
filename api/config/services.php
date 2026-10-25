<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Midtrans (T22)
    |--------------------------------------------------------------------------
    |
    | Nilai bawaannya kosong dengan sengaja. Aplikasi ini harus tetap bisa
    | dijalankan tanpa akun Midtrans - seluruh tes berjalan tanpa kredensial
    | sungguhan - dan satu-satunya hal yang hilang tanpa kunci adalah
    | kemampuan membuat transaksi baru. Tanpa `server_key`, endpoint pembuatan
    | transaksi menjawab 503 dengan alasan yang terbaca, bukan 500.
    |
    | `is_production` menentukan DUA hal sekaligus, dan keduanya harus ikut:
    | alamat API (sandbox vs produksi) dan cara memverifikasi tanda tangan.
    | Kunci sandbox tidak berlaku di produksi dan sebaliknya; menyetel salah
    | satunya saja menghasilkan tanda tangan yang selalu ditolak, atau lebih
    | buruk, diterima di lingkungan yang salah.
    |
    | `snap_url` sengaja tidak disimpan sebagai konfigurasi terpisah: ia
    | diturunkan dari `is_production` di dalam {@see \App\Domain\Pembayaran\MidtransGateway},
    | supaya tidak mungkin ada dua sumber kebenaran yang bisa bertentangan.
    |
    */
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        // Berapa lama tautan pembayaran berlaku. Midtrans sendiri punya
        // batasnya; nilai ini yang kita minta.
        'kedaluwarsa_menit' => env('MIDTRANS_KEDALUWARSA_MENIT', 60),
    ],

];
