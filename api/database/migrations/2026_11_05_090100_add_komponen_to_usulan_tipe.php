<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tipe usulan ketiga: `komponen` (REV-SEC-07).
 *
 * CHECK `usulan_perubahan_tipe_dikenal` semula hanya mengenal `tarif` dan
 * `kebijakan`. Tanpa migrasi ini, usulan komponen biaya akan ditolak basis data
 * dengan `23514` - galat yang tidak bisa dijelaskan ke pemakai, karena
 * permintaannya sendiri sah menurut kode.
 *
 * Daftar nilainya ditulis ulang lengkap, bukan ditambah lewat `ALTER ... ADD`:
 * CHECK constraint tidak punya bentuk "tambah satu nilai", dan menuliskan
 * seluruh daftar di satu tempat membuat tipe yang dikenal terbaca sekaligus.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE usulan_perubahan DROP CONSTRAINT IF EXISTS usulan_perubahan_tipe_dikenal');

        DB::statement(
            "ALTER TABLE usulan_perubahan
             ADD CONSTRAINT usulan_perubahan_tipe_dikenal
             CHECK (tipe IN ('tarif', 'kebijakan', 'komponen'))"
        );
    }

    public function down(): void
    {
        // Baris `komponen` yang sudah ada harus hilang lebih dulu, kalau tidak
        // penambahan CHECK lama akan gagal dan rollback berhenti di tengah.
        DB::table('usulan_perubahan')->where('tipe', 'komponen')->delete();

        DB::statement('ALTER TABLE usulan_perubahan DROP CONSTRAINT IF EXISTS usulan_perubahan_tipe_dikenal');

        DB::statement(
            "ALTER TABLE usulan_perubahan
             ADD CONSTRAINT usulan_perubahan_tipe_dikenal
             CHECK (tipe IN ('tarif', 'kebijakan'))"
        );
    }
};
