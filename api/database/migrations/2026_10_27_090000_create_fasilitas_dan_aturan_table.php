<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T18 dan T19 - fasilitas kamar dan aturan kos.
 *
 * Dua tabel yang sengaja dipisahkan, karena keduanya menjawab pertanyaan yang
 * berbeda dan hanya satu yang menyentuh uang:
 *
 *   fasilitas       - kamar ini PUNYA apa. Mencatat. Tidak menagih apa pun.
 *   aturan_kos      - apa yang berlaku di kos ini. Membaca. Tidak menagih apa pun.
 *   komponen_biaya  - kamar ini DITAGIH apa. Sudah ada, append-only, dan tetap
 *                     menjadi satu-satunya sumber angka di tagihan.
 *
 * Fasilitas tidak pernah menjadi baris tagihan. Kalau pemilik mencentang "AC",
 * tagihan penghuni tidak berubah sedikit pun; kalau ia ingin menagih listrik
 * AC, ia menambah komponen biaya seperti biasa. Pemisahan ini disengaja: biaya
 * yang muncul hanya karena mencentang kotak adalah biaya yang tidak pernah
 * disetujui siapa pun.
 *
 * Tidak append-only, berbeda dari `tarif_sewa`/`komponen_biaya`/`kebijakan_kos`.
 * Alasannya bukan karena lebih longgar, tetapi karena tidak ada angka uang yang
 * bergantung pada barisnya: menghapus fasilitas hari ini tidak mengubah tagihan
 * bulan lalu, sebab tagihan tidak pernah membaca tabel ini. Menjadikannya
 * append-only akan menambah kerumitan tanpa menambah jaminan apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fasilitas', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 60)->unique();
            // Ikon dan urutan hanya memengaruhi tampilan; keduanya boleh kosong
            // supaya menambah fasilitas tidak pernah gagal karena hal sepele.
            $table->string('ikon', 40)->nullable();
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();

            $table->index('urutan');
        });

        Schema::create('kamar_fasilitas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kamar_id')->constrained('kamar')->cascadeOnDelete();
            $table->foreignId('fasilitas_id')->constrained('fasilitas')->cascadeOnDelete();
            $table->timestamps();

            // Satu fasilitas hanya boleh tercatat sekali per kamar. Tanpa ini,
            // mencentang dua kali menghasilkan dua baris dan layar menampilkan
            // fasilitas yang sama dua kali.
            $table->unique(['kamar_id', 'fasilitas_id']);
        });

        Schema::create('aturan_kos', function (Blueprint $table) {
            $table->id();
            $table->string('judul', 120);
            $table->text('isi');
            // Kategori dipakai untuk mengelompokkan di portal penghuni. Nilainya
            // bebas, bukan enum: pemilik kos punya kebiasaan berbeda dalam
            // mengelompokkan aturannya, dan enum akan memaksa satu daftar.
            $table->string('kategori', 40)->default('umum');
            $table->string('sanksi', 200)->nullable();
            // Aturan yang tidak aktif tetap tersimpan tetapi tidak tampil di
            // portal. Menghapusnya akan menghilangkan riwayat "aturan ini
            // pernah berlaku", yang justru sering ditanyakan penghuni lama.
            $table->boolean('aktif')->default(true);
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();

            $table->index(['aktif', 'urutan']);
        });
    }

    public function down(): void
    {
        // Urutannya dibalik dari pembuatan: pivot lebih dulu, karena ia yang
        // memegang foreign key ke `fasilitas`.
        Schema::dropIfExists('aturan_kos');
        Schema::dropIfExists('kamar_fasilitas');
        Schema::dropIfExists('fasilitas');
    }
};
