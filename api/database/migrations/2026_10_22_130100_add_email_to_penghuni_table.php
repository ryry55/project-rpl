<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email penghuni.
 *
 * SPEC §6 tidak mencantumkan email pada `penghuni`, tetapi setiap penghuni
 * harus bisa login (R-07: mereka melihat tagihannya sendiri), dan login butuh
 * email. Menaruhnya di sini lebih tepat daripada membuat email karangan dari
 * nama, yang tidak bisa dipakai pemilik untuk memberi tahu penghuni.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penghuni', function (Blueprint $table) {
            $table->string('email', 120)->nullable()->unique()->after('nama');
        });
    }

    public function down(): void
    {
        Schema::table('penghuni', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn('email');
        });
    }
};
