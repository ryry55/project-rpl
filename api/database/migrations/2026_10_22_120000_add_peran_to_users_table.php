<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peran pengguna.
 *
 * Hanya dua peran: pemilik dan penghuni. "Petugas" sudah dilebur ke pemilik
 * (lihat SPEC §2). Kolom `penghuni_id` ditambahkan sekarang tetapi foreign key-nya
 * baru dipasang di T2, karena tabel `penghuni` belum ada pada tiket ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('peran', 20)->default('penghuni')->after('email');
            $table->unsignedBigInteger('penghuni_id')->nullable()->after('peran');

            $table->index('peran');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['peran']);
            $table->dropColumn(['peran', 'penghuni_id']);
        });
    }
};
