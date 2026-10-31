<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan kerusakan kamar (T14).
 *
 * **Kenapa tabel sendiri, bukan sekadar kolom `catatan` di `kamar`.** Kolom
 * catatan hanya bisa menyimpan SATU kalimat terakhir. Pertanyaan yang muncul di
 * lapangan adalah "sejak kapan kamar ini rusak, siapa yang melaporkan, dan
 * sejak kapan selesai" - dan itu tiga hal yang tidak muat di satu kolom yang
 * ditimpa setiap kali ada yang mengetik.
 *
 * **Kenapa `selesai_pada` dan bukan kolom status.** Laporan hanya punya dua
 * keadaan, dan yang kedua adalah saat penutupannya. Dua kolom (`status` +
 * `selesai_pada`) selalu bisa bertentangan - status `selesai` tanpa tanggal,
 * atau tanggal tanpa status - dan tidak ada yang bisa memutuskan mana yang
 * benar. Satu kolom bernilai null tidak punya keadaan mustahil.
 *
 * **Biaya perbaikan tidak masuk tagihan penghuni (keputusan pemilik 31 Okt
 * 2026).** Tabel ini karena itu tidak punya kolom nominal, dan tidak ada satu
 * baris pun di sini yang dibaca `PenerbitanMassal`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laporan_kerusakan', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kamar_id')->constrained('kamar')->restrictOnDelete();

            // `text`, bukan `string`: kerusakan nyata ("plafon kamar mandi
            // bocor dan merembes ke lemari") tidak muat dalam 255 huruf, dan
            // memotongnya berarti kehilangan bagian yang menjelaskan.
            $table->text('deskripsi');

            // Dicatat, bukan diwajibkan: kolom ini bisa jadi kosong bila
            // akun pelapornya dihapus kelak. Riwayat lengkapnya tetap ada di
            // `log_audit`, yang menyimpan nama aktor sebagai salinan beku.
            $table->foreignId('dilaporkan_oleh')->nullable()
                ->constrained('users')->nullOnDelete();

            // Null berarti laporan masih terbuka.
            $table->timestampTz('selesai_pada')->nullable();
            $table->foreignId('ditutup_oleh')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('selesai_pada');
        });

        // Satu kamar hanya boleh punya SATU laporan terbuka. Tanpa ini,
        // menekan tombol "Laporkan kerusakan" dua kali membuat dua laporan
        // untuk satu kerusakan, dan menutup yang satu meninggalkan yang lain
        // terbuka selamanya - kamarnya tidak pernah kembali kosong.
        DB::statement(
            'CREATE UNIQUE INDEX laporan_kerusakan_satu_terbuka_per_kamar
             ON laporan_kerusakan (kamar_id)
             WHERE selesai_pada IS NULL'
        );

        // `perbaikan` bergabung dengan tiga status yang sudah ada. CHECK, bukan
        // enum: menambah nilai cukup dengan migrasi kecil, sama seperti
        // `transaksi_gateway.status`.
        DB::statement('ALTER TABLE kamar DROP CONSTRAINT IF EXISTS kamar_status_huni_check');
        DB::statement(
            "ALTER TABLE kamar ADD CONSTRAINT kamar_status_huni_check
             CHECK (status_huni IN ('kosong', 'terisi', 'dipesan', 'perbaikan'))"
        );
    }

    public function down(): void
    {
        // Laporan dihapus lebih dulu: `kamar` masih dirujuk olehnya lewat
        // RESTRICT, jadi membalik urutannya akan gagal.
        Schema::dropIfExists('laporan_kerusakan');

        // Kamar yang sedang diperbaiki tidak punya status yang sah setelah
        // `perbaikan` dicabut. Ia dikembalikan ke `kosong` - bukan ditebak
        // menjadi `terisi` - karena perbaikan yang dibatalkan berarti
        // kamarnya belum siap dihuni.
        DB::statement("UPDATE kamar SET status_huni = 'kosong' WHERE status_huni = 'perbaikan'");
        DB::statement('ALTER TABLE kamar DROP CONSTRAINT IF EXISTS kamar_status_huni_check');
        DB::statement(
            "ALTER TABLE kamar ADD CONSTRAINT kamar_status_huni_check
             CHECK (status_huni IN ('kosong', 'terisi', 'dipesan'))"
        );
    }
};
