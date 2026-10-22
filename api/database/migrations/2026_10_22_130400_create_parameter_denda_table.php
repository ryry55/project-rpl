<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parameter denda — D3 pada DFD.
 *
 * Append-only: mengubah parameter = baris baru. Tagihan menyimpan salinan
 * parameter yang berlaku saat terbit (T6), jadi mengubah baris di sini tidak
 * pernah mengubah tagihan lama.
 *
 * Persentase disimpan sebagai numeric(5,2) agar 1% eksak — bukan 0.01 pada
 * float, yang tidak bisa direpresentasikan tepat dalam biner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parameter_denda', function (Blueprint $table) {
            $table->id();
            $table->decimal('tarif_persen', 5, 2)->default(1.00);
            $table->decimal('plafon_persen', 5, 2)->default(30.00);
            $table->unsignedSmallInteger('masa_tenggang')->default(7);
            $table->date('berlaku_dari');
            $table->timestamps();

            $table->unique('berlaku_dari');
            $table->index('berlaku_dari');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parameter_denda');
    }
};
