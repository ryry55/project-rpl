<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Komponen biaya per kamar (T11).
 *
 * Sebelum ini `komponen_biaya` tidak punya `kamar_id`, sehingga biaya tambahan
 * bersifat global untuk semua kamar. Kos nyata sering punya biaya yang hanya
 * berlaku sebagian kamar (AC, parkir motor, kamar mandi dalam, surcharge lantai 2).
 *
 * `kamar_id` NULL berarti "berlaku untuk semua kamar". Baris khusus kamar
 * menimpa baris global dengan nama yang sama — lihat PencariTarif::komponenUntuk.
 *
 * Unique index memakai NULLS NOT DISTINCT (PostgreSQL 15+, server kita 16).
 * Tanpa itu, unique index `(nama, berlaku_dari, kamar_id)` akan mengizinkan
 * duplikat baris global, karena SQL memperlakukan NULL sebagai selalu berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('komponen_biaya', function (Blueprint $table) {
            $table->foreignId('kamar_id')
                ->nullable()
                ->after('id')
                ->constrained('kamar')
                ->cascadeOnDelete();
        });

        // Unique index lama tidak bisa dipertahankan: ia menganggap dua baris
        // global dengan nama+tanggal sama sebagai duplikat, tetapi tidak bisa
        // membedakan baris khusus kamar. Diganti versi tiga kolom.
        Schema::table('komponen_biaya', function (Blueprint $table) {
            $table->dropUnique(['nama', 'berlaku_dari']);
        });

        Schema::table('komponen_biaya', function (Blueprint $table) {
            $table->unique(['nama', 'berlaku_dari', 'kamar_id'], 'komponen_biaya_nama_tanggal_kamar_unique')
                ->nullsNotDistinct();
        });
    }

    public function down(): void
    {
        Schema::table('komponen_biaya', function (Blueprint $table) {
            $table->dropUnique('komponen_biaya_nama_tanggal_kamar_unique');
        });

        Schema::table('komponen_biaya', function (Blueprint $table) {
            $table->unique(['nama', 'berlaku_dari']);
        });

        Schema::table('komponen_biaya', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kamar_id');
        });
    }
};
