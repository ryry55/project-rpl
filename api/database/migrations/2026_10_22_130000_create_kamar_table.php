<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1 pada DFD — Kamar.
 *
 * Tarif dasar tidak disimpan di sini. Tarif punya riwayat (R-06), dan riwayat
 * butuh tabel sendiri: `tarif_sewa`. Lihat catatan deviasi DFD di SPEC §5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kamar', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 20)->unique();
            $table->string('tipe', 40)->nullable();
            $table->enum('status_huni', ['kosong', 'terisi'])->default('kosong');
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index('status_huni');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kamar');
    }
};
