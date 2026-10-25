<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Satu notifikasi gateway hanya boleh menghasilkan satu pembayaran (T22).
 *
 * **Kenapa migrasi ini ada padahal sudah ada pemeriksaan di kode.** Jalur
 * webhook memeriksa `transaksi_gateway.status` sebelum mencatat, dan
 * memeriksa ulang di dalam transaksi. Tetapi kedua notifikasi yang sama bisa
 * tiba benar-benar bersamaan: keduanya membaca status `menunggu`, keduanya
 * lolos, dan keduanya menulis. Pemeriksaan baca-lalu-tulis selalu punya celah
 * itu. Yang menutupnya hanya constraint di basis data.
 *
 * **Kenapa pada `referensi`, bukan pada `transaksi_gateway`.** Yang harus unik
 * adalah "satu order_id menghasilkan satu baris pembayaran". `referensi` di
 * tabel pembayaran berisi `order_id` untuk baris yang berasal dari gateway, dan
 * di situlah kebenarannya harus ditegakkan - bukan di tabel lain yang bisa
 * berbeda pendapat dengan tabel pembayaran.
 *
 * **Kenapa partial.** `referensi` juga dipakai pembayaran yang dicatat tangan
 * (mis. nomor referensi transfer bank), dan dua transfer bisa kebetulan
 * memakai nomor referensi yang sama dari bank yang berbeda. Yang dibatasi
 * hanya baris yang lahir dari webhook.
 *
 * **Kenapa `IS NOT NULL` wajib ditulis.** PostgreSQL menganggap NULL berbeda
 * satu sama lain, jadi tanpa itu baris tanpa referensi tidak akan pernah
 * bentrok - dan itu memang yang diinginkan, tetapi menuliskannya membuat
 * maksudnya terbaca, bukan kebetulan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX pembayaran_gateway_satu_per_order
             ON pembayaran (referensi)
             WHERE sumber = 'gateway_webhook' AND referensi IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pembayaran_gateway_satu_per_order');
    }
};
