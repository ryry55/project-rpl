<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Komponen biaya tetap — kebersihan, parkir, AC, laundry.
 *
 * Append-only seperti `tarif_sewa`. SPEC §6 menyebut kolom `aktif`, tetapi
 * kolom itu bertentangan dengan sifat append-only: menonaktifkan komponen
 * berarti meng-UPDATE baris lama, dan itu merusak riwayat R-06. Karena itu
 * `aktif` TIDAK dibuat. Komponen dihentikan dengan menambah baris baru bernilai
 * 0 dengan `berlaku_dari` baru, sehingga riwayatnya tetap utuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('komponen_biaya', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 80);
            $table->decimal('nilai', 14, 0);
            $table->string('satuan', 30)->default('rupiah/bulan');
            $table->date('berlaku_dari');
            $table->timestamps();

            $table->unique(['nama', 'berlaku_dari']);
            $table->index(['nama', 'berlaku_dari']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('komponen_biaya');
    }
};
