<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel penghuni minimal untuk T1.
 *
 * Atribut lengkap (kontrak, kamar, tanggal masuk) ditambahkan di T2. Tabel dibuat
 * sekarang karena `users.penghuni_id` butuh tujuan foreign key agar isolasi data
 * per penghuni (R-07) bisa diuji sejak awal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penghuni', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 120);
            $table->string('no_hp', 30)->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('penghuni_id')
                ->references('id')
                ->on('penghuni')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['penghuni_id']);
        });

        Schema::dropIfExists('penghuni');
    }
};
