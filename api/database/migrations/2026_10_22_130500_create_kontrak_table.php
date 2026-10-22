<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kontrak — ikatan penghuni dengan kamar (D2 pada DFD).
 *
 * Satu kamar hanya boleh punya satu kontrak aktif pada satu waktu. Ditegakkan
 * dengan partial unique index, bukan pengecekan di aplikasi: pengecekan
 * `exists()` punya celah balapan yang sama dengan R-02.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kontrak', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kamar_id')->constrained('kamar')->restrictOnDelete();
            $table->foreignId('penghuni_id')->constrained('penghuni')->restrictOnDelete();
            $table->date('tgl_masuk');
            $table->date('tgl_keluar')->nullable();

            // Salinan tarif saat kontrak ditandatangani. Sifatnya keterangan,
            // bukan sumber kebenaran: perhitungan tagihan selalu membaca
            // `tarif_sewa` (R-06), karena tarif bisa berubah di tengah kontrak.
            // Nilainya berguna untuk menunjukkan tarif apa yang disepakati pada
            // saat itu, walau tarifnya kemudian naik.
            $table->decimal('tarif_awal', 14, 0);

            $table->decimal('deposit', 14, 0)->default(0);
            $table->enum('status', ['aktif', 'selesai'])->default('aktif');
            $table->timestamps();

            $table->index(['penghuni_id', 'status']);
        });

        // Partial unique index: hanya satu kontrak aktif per kamar.
        DB::statement(
            'CREATE UNIQUE INDEX kontrak_satu_aktif_per_kamar
             ON kontrak (kamar_id)
             WHERE status = \'aktif\''
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS kontrak_satu_aktif_per_kamar');
        Schema::dropIfExists('kontrak');
    }
};
