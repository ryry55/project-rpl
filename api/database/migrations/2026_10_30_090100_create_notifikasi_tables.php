<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notifikasi in-app dan pengaturan kanalnya (T25).
 *
 * Tabel `notifications` memakai bentuk standar Laravel, bukan tabel karangan
 * sendiri. Alasannya bukan keseragaman gaya: `Notifiable` sudah tahu cara
 * menulis, membaca, dan menandai terbaca dari bentuk itu, dan menulis ulang
 * ketiganya dengan nama kolom sendiri berarti tiga kesempatan salah yang tidak
 * membeli apa pun.
 *
 * `pengaturan_notifikasi` menyimpan **hanya yang berbeda dari bawaan**. Baris
 * tidak dibuat untuk setiap pasangan pengguna x peristiwa x kanal. Kalau dibuat,
 * setiap akun baru melahirkan belasan baris yang isinya sama untuk semua orang,
 * dan pertanyaan "apakah pengguna ini menerima email untuk tarif" berubah dari
 * satu pembacaan konstanta menjadi query yang bisa gagal.
 *
 * Bentuknya `(user_id, peristiwa, kanal, aktif)`, bukan `(user_id, peristiwa,
 * in_app, email)`. Dua kolom boolean akan menuntut migrasi baru setiap kali
 * kanal ketiga muncul - dan kanal ketiga (WhatsApp) sudah ada di daftar
 * keinginan. Satu baris per kanal membuat penambahannya tidak menyentuh skema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            // UUID, bukan id berurut: bentuk standar Laravel, dan id berurut
            // pada tabel yang bisa dibaca pemilik sendiri akan membocorkan
            // berapa banyak notifikasi yang pernah dibuat sistem.
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pengaturan_notifikasi', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('peristiwa', 40);
            $table->string('kanal', 10);
            $table->boolean('aktif');

            // Satu baris = satu keputusan. Dua baris untuk pasangan yang sama
            // berarti "aktif dan tidak aktif" sekaligus, dan pembaca pertama
            // yang menemukannya yang menang - jawaban yang bergantung pada
            // urutan baris bukan jawaban.
            $table->unique(['user_id', 'peristiwa', 'kanal']);
        });

        // Nama peristiwa dan kanal divalidasi juga di PHP lewat
        // {@see \App\Domain\Notifikasi\Peristiwa}. CHECK di sini menjaga jalur
        // yang melewati PHP: seeder, perintah artisan, dan SQL langsung.
        DB::statement(
            "ALTER TABLE pengaturan_notifikasi
             ADD CONSTRAINT pengaturan_notifikasi_peristiwa_dikenal
             CHECK (peristiwa IN (
                 'usulan.diajukan', 'usulan.diputuskan',
                 'tarif.diubah', 'kebijakan.diubah',
                 'tagihan.massal', 'tagihan.terbit',
                 'pembayaran.dicatat', 'pembayaran.diverifikasi'
             ))"
        );

        DB::statement(
            "ALTER TABLE pengaturan_notifikasi
             ADD CONSTRAINT pengaturan_notifikasi_kanal_dikenal
             CHECK (kanal IN ('in_app', 'email'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan_notifikasi');
        Schema::dropIfExists('notifications');
    }
};
