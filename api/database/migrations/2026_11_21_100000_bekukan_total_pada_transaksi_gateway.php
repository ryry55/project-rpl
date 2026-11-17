<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * K-20: total tagihan yang dibekukan saat tautan gateway dibuat.
 *
 * `jumlah` sudah beku sejak awal, tetapi yang dibekukan adalah SISA saat
 * tautan dibuat - dan sisa tidak bisa menjawab "berapa total tagihannya"
 * bila ada pembayaran lain yang tercatat di antara tautan dibuat dan
 * dibayar. Kolom ini menyimpan total penuhnya (pokok + denda hari itu),
 * dipakai saat settlement: membayar nominal tautan penuh berarti lunas,
 * bukan menyisakan denda receh yang tumbuh setelah tautan dibuat.
 *
 * Nullable: baris yang lahir sebelum kolom ini tidak punya angka beku,
 * dan settlement-nya tetap memakai total hidup seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaksi_gateway', function (Blueprint $table) {
            $table->decimal('total_beku', 14, 0)->nullable()->after('jumlah');
        });
    }

    public function down(): void
    {
        Schema::table('transaksi_gateway', function (Blueprint $table) {
            $table->dropColumn('total_beku');
        });
    }
};
