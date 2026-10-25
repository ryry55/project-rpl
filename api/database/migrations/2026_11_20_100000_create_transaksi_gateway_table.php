<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transaksi payment gateway (T22).
 *
 * **Kenapa tabel sendiri, bukan kolom di `tagihan`.** Satu tagihan bisa punya
 * lebih dari satu percobaan bayar: penghuni membuka tautan, tautannya
 * kedaluwarsa, ia membuka lagi. Mencatat percobaan itu di tagihan berarti
 * hanya percobaan terakhir yang tersimpan, dan pertanyaan "kenapa penghuni ini
 * bilang sudah bayar padahal tagihannya belum lunas" tidak bisa dijawab.
 *
 * **Kenapa `order_id` unik dan dibuat di sini, bukan diserahkan ke Midtrans.**
 * Midtrans menolak `order_id` yang sudah pernah dipakai, dan itu pengaman yang
 * diinginkan. Dengan menyimpannya di tabel ini, aplikasi bisa membuktikan
 * percobaan mana yang menghasilkan pembayaran mana - tanpa itu, jejaknya hanya
 * ada di dashboard Midtrans.
 *
 * **`midtrans_order_id` bukan primary key.** Primary key tetap `id` bigint
 * supaya foreign key dari tabel lain (bila nanti ada) tidak ikut memanjang
 * menjadi string. Yang unik adalah `order_id` karena itulah yang dikirim ke
 * gateway dan kembali lewat webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi_gateway', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tagihan_id')->constrained('tagihan')->restrictOnDelete();

            // `order_id` yang dikirim ke Midtrans. Bentuknya
            // `KOS-{tagihan_id}-{cap}`; unik karena gateway menolak yang sama
            // dua kali, dan kita ingin penolakan itu terjadi di sini - saat
            // percobaan dibuat - bukan saat penghuni menekan Bayar.
            $table->string('order_id', 64)->unique();

            // Nominal yang diminta ke gateway, dibekukan. Tagihan bisa berubah
            // (denda bertambah) setelah tautan dibuat, dan jumlah yang harus
            // dicocokkan saat webhook datang adalah jumlah yang DIMINTA, bukan
            // jumlah tagihan hari ini.
            $table->decimal('jumlah', 14, 0);

            $table->string('status', 20)->default('menunggu');

            // Tautan pembayaran untuk penghuni. Disimpan karena tautan yang
            // sama bisa dibuka ulang tanpa membuat transaksi baru - Midtrans
            // menolak order_id berulang, jadi membuat ulang bukan pilihan.
            $table->text('redirect_url')->nullable();
            $table->string('token', 128)->nullable();

            // Jejak jawaban gateway. Berguna saat penghuni mengeluh "sudah
            // bayar tapi belum lunas": yang diperiksa pertama adalah apa yang
            // sebenarnya dikatakan gateway.
            $table->string('transaction_id', 64)->nullable();
            $table->string('transaction_status', 32)->nullable();
            $table->string('fraud_status', 32)->nullable();

            $table->timestamp('kedaluwarsa_pada')->nullable();
            $table->timestamp('dibayar_pada')->nullable();

            $table->timestamps();

            $table->index(['tagihan_id', 'status']);
            $table->index('status');
        });

        // Status yang dikenal. CHECK, bukan enum: menambah status baru cukup
        // dengan migrasi kecil, dan PostgreSQL tidak perlu menulis ulang tabel.
        DB::statement(
            "ALTER TABLE transaksi_gateway ADD CONSTRAINT transaksi_gateway_status_dikenal
             CHECK (status IN ('menunggu', 'dibayar', 'gagal', 'kedaluwarsa', 'dibatalkan'))"
        );

        // Satu tagihan hanya boleh punya SATU transaksi yang masih menunggu.
        // Tanpa ini, penghuni yang menekan Bayar dua kali akan membuat dua
        // tautan untuk tagihan yang sama, dan keduanya bisa dibayar.
        DB::statement(
            "CREATE UNIQUE INDEX transaksi_gateway_satu_menunggu_per_tagihan
             ON transaksi_gateway (tagihan_id)
             WHERE status = 'menunggu'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_gateway');
    }
};
