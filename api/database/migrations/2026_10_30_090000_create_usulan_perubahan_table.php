<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usulan perubahan tarif dan kebijakan (T26).
 *
 * Kenapa tabel ini ada padahal tarif dan kebijakan sudah append-only. Selama
 * ini wewenang `ubah_tarif` berarti "boleh langsung mengubah angka uang".
 * Artinya satu akun pengelola yang salah centang bisa menaikkan tarif seluruh
 * kos tanpa ada yang melihat, dan pemilik baru tahu setelah penghuni protes.
 * Append-only tidak menolong di sini: ia menjaga riwayat, bukan menjaga
 * kewenangan.
 *
 * Jadi wewenang sensitif dipecah menjadi dua perbuatan yang berbeda:
 *
 *   pengelola -> boleh MENGUSULKAN (baris di tabel ini)
 *   pemilik   -> yang MENETAPKAN (baris di tarif_sewa / kebijakan_kos)
 *
 * Tiga keputusan bentuk:
 *
 *   1. **`payload` jsonb, bukan kolom per tipe.** Usulan tarif membawa tiga
 *      nilai, usulan kebijakan membawa delapan. Tabel dengan kolom gabungan
 *      keduanya akan punya lima kolom yang selalu NULL untuk salah satu tipe,
 *      dan pembaca tidak bisa membedakan "tidak dipakai" dari "lupa diisi".
 *      Isinya adalah hasil `validated()` dari FormRequest yang sama dengan
 *      jalur langsung, jadi bentuknya tidak liar.
 *
 *   2. **`ringkasan` disimpan sebagai teks beku.** Kalimat "Tarif kamar A1
 *      menjadi Rp 1.800.000 mulai 1 Des 2026" disusun saat usulan dibuat, bukan
 *      saat dibaca. Kalau disusun saat dibaca, kamar yang berganti nomor akan
 *      mengubah bunyi usulan lama - dan pemilik yang menyetujui "kamar A1"
 *      tiba-tiba membaca "kamar B3" di riwayat persetujuan.
 *
 *   3. **Status boleh berubah, tabelnya TIDAK append-only.** Ini berbeda dari
 *      `pembayaran` dan `verifikasi_pembayaran`, dan bedanya disengaja: usulan
 *      bukan catatan uang, melainkan barang antrean yang seluruh gunanya
 *      memang untuk diselesaikan. Bukti siapa memutuskan apa tetap ada di
 *      `log_audit` yang append-only, jadi tidak ada fakta yang bisa hilang.
 *
 *   4. **`kedaluwarsa` BUKAN nilai status.** Kedaluwarsa dihitung dari
 *      `kedaluwarsa_pada`, tidak pernah disimpan. Alasannya: penandaan status
 *      butuh sesuatu yang berjalan menurut jadwal, dan penjadwal di hosting
 *      gratis adalah penjadwal yang berhenti tanpa pemberitahuan. Usulan yang
 *      tampak masih menunggu padahal sudah lewat sebulan lebih buruk daripada
 *      tidak ada batas waktu sama sekali, karena pengelola menunggu perubahan
 *      yang tidak akan datang. Nilai `kedaluwarsa` sengaja tidak masuk CHECK
 *      supaya tidak ada kolom yang berisi keadaan yang tidak pernah ditulis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usulan_perubahan', function (Blueprint $table) {
            $table->id();

            $table->string('tipe', 20);
            $table->jsonb('payload');

            // Ringkasan satu baris, dibekukan saat usulan dibuat.
            $table->string('ringkasan', 255);

            $table->string('status', 20)->default('menunggu');

            // RESTRICT, bukan SET NULL. Usulan adalah bukti siapa mengusulkan
            // perubahan angka uang; mengosongkan kolomnya saat akun dihapus
            // menghasilkan baris yang tidak bisa dipertanggungjawabkan. Aplikasi
            // tidak pernah menghapus akun - pencabutan akses menurunkan peran
            // (T24) - jadi larangan ini tidak menghalangi apa pun yang nyata.
            $table->foreignId('diajukan_oleh')->constrained('users')->restrictOnDelete();
            $table->foreignId('diputuskan_oleh')->nullable()->constrained('users')->restrictOnDelete();

            // Wajib untuk penolakan, kosong untuk persetujuan. Dijaga CHECK di
            // bawah: penolakan tanpa alasan tidak bisa ditindaklanjuti, dan
            // pengelola yang tidak tahu apa yang salah akan mengajukan ulang
            // usulan yang sama persis.
            $table->string('alasan', 255)->nullable();

            $table->timestamp('dibuat_pada');
            $table->timestamp('diputuskan_pada')->nullable();

            // 7 hari sejak diajukan. Tanpa batas, usulan yang tidak pernah
            // dibuka menggantung selamanya dan pengelola menunggu perubahan
            // yang tidak akan datang.
            $table->timestamp('kedaluwarsa_pada');

            // Pola baca: "apa yang menunggu keputusan saya", terbaru dulu.
            $table->index(['status', 'dibuat_pada']);
            $table->index(['diajukan_oleh', 'dibuat_pada']);
        });

        DB::statement(
            "ALTER TABLE usulan_perubahan
             ADD CONSTRAINT usulan_perubahan_tipe_dikenal
             CHECK (tipe IN ('tarif', 'kebijakan'))"
        );

        DB::statement(
            "ALTER TABLE usulan_perubahan
             ADD CONSTRAINT usulan_perubahan_status_dikenal
             CHECK (status IN ('menunggu', 'disetujui', 'ditolak'))"
        );

        // Penolakan wajib beralasan. Persetujuan tidak boleh membawa alasan:
        // kolom yang terisi pada persetujuan akan terbaca sebagai syarat, dan
        // pembaca riwayat akan mencari-cari apa yang salah padahal tidak ada.
        DB::statement(
            "ALTER TABLE usulan_perubahan
             ADD CONSTRAINT usulan_perubahan_tolak_beralasan
             CHECK (status <> 'ditolak' OR alasan IS NOT NULL)"
        );

        // Usulan yang sudah diputuskan wajib menyebut siapa yang memutuskan.
        // Tanpa ini, baris berstatus `disetujui` bisa ada tanpa satu pun nama
        // yang bisa ditanyai soal keputusan itu.
        DB::statement(
            "ALTER TABLE usulan_perubahan
             ADD CONSTRAINT usulan_perubahan_diputuskan_ada_pemutus
             CHECK (status = 'menunggu' OR diputuskan_oleh IS NOT NULL)"
        );

        DB::statement(
            "ALTER TABLE usulan_perubahan
             ADD CONSTRAINT usulan_perubahan_diputuskan_ada_waktu
             CHECK (status = 'menunggu' OR diputuskan_pada IS NOT NULL)"
        );

        // Usulan yang masih menunggu tidak boleh menyebut pemutus. Baris seperti
        // itu akan terbaca sebagai "sudah diputuskan oleh X" padahal statusnya
        // belum, dan dua kolom itu bercerita berbeda tentang hal yang sama.
        DB::statement(
            "ALTER TABLE usulan_perubahan
             ADD CONSTRAINT usulan_perubahan_menunggu_belum_diputuskan
             CHECK (status <> 'menunggu' OR (diputuskan_oleh IS NULL AND diputuskan_pada IS NULL))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('usulan_perubahan');
    }
};
