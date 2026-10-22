<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat tarif sewa per kamar — lapis pertama R-06.
 *
 * Append-only: baris lama tidak pernah di-UPDATE atau dihapus (ditegakkan oleh
 * trigger di migrasi 130600). Mengubah tarif = menambah baris baru dengan
 * `berlaku_dari` baru.
 *
 * Tarif efektif untuk periode P = baris dengan `berlaku_dari` terbesar yang
 * masih <= awal periode P.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarif_sewa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kamar_id')->constrained('kamar')->cascadeOnDelete();
            $table->decimal('nilai', 14, 0);
            $table->date('berlaku_dari');
            $table->timestamps();

            // Satu tarif per kamar per tanggal mulai berlaku. Tanpa ini, dua baris
            // dengan tanggal sama membuat "tarif efektif" tidak terdefinisi.
            $table->unique(['kamar_id', 'berlaku_dari']);
            $table->index(['kamar_id', 'berlaku_dari']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarif_sewa');
    }
};
