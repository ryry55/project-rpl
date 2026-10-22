<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Total yang dibekukan saat tagihan menjadi lunas.
 *
 * Tanpa kolom ini, angka di layar tidak bisa konsisten. Denda berhenti berjalan
 * begitu tagihan lunas (lihat `PenghitungDenda`), sehingga tagihan yang dibayar
 * Rp 1.950.000 akan menampilkan "total Rp 1.500.000" dan "dibayar Rp 1.950.000"
 * pada saat yang sama — dua angka yang saling bertentangan di satu layar.
 *
 * `total_final` diisi **sekali**, tepat ketika status berubah menjadi `lunas`,
 * dengan nilai `pokok + denda` pada hari pembayaran terakhir. Sesudah itu ia
 * tidak pernah dihitung ulang: angka yang ditagihkan sudah final, dan mengubahnya
 * berarti menagih ulang penghuni untuk bulan yang sudah selesai.
 *
 * Nullable karena tagihan yang belum lunas belum punya angka final — nilainya
 * masih bergerak mengikuti denda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tagihan', function (Blueprint $table) {
            $table->decimal('total_final', 14, 0)->nullable()->after('pokok');
        });
    }

    public function down(): void
    {
        Schema::table('tagihan', function (Blueprint $table) {
            $table->dropColumn('total_final');
        });
    }
};
