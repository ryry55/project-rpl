<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gambar QRIS untuk rekening (utang teknis yang ditutup di fase terakhir).
 *
 * **Kenapa isinya di basis data, bukan berkas di disk.** Tiga hal menutup jalan
 * penyimpanan berkas, dan semuanya nyata di lingkungan yang dipakai:
 *
 *   1. `public/` dipasang read-only ke nginx (`docker-compose.yml`), jadi tidak
 *      ada tempat menulis yang bisa dilayani langsung.
 *   2. Render free **tidak punya disk persisten**. Berkas yang ditulis hari ini
 *      hilang saat service restart, dan gambar QRIS yang hilang lebih buruk
 *      daripada tidak ada gambar sama sekali: pemilik mengira sudah mengunggah,
 *      penghuni melihat kotak kosong, dan tidak ada yang tahu sejak kapan.
 *   3. `storage:link` membuat symlink di `public/`, yang justru read-only itu.
 *
 * Basis data sudah persisten (Neon), sudah dicadangkan, dan sudah ikut aturan
 * akses yang sama dengan baris lainnya. `bytea` menyimpannya apa adanya tanpa
 * perlu disk. Biayanya: baris lebih besar dan tidak bisa disajikan nginx
 * langsung - karena itu ada endpoint tersendiri yang mengirimkannya, bukan URL
 * statis.
 *
 * **Kenapa `bytea` dan bukan base64 di kolom teks.** Base64 membengkak 33% dan
 * membuat satu gambar 200 kB menjadi 270 kB teks yang harus di-encode ulang
 * setiap kali dibaca. `bytea` juga menutup satu jenis kesalahan: teks base64
 * yang rusak tetap berupa teks yang sah, jadi kerusakannya baru ketahuan saat
 * gambar gagal tampil. Sudah diperiksa bahwa driver pgsql di container ini
 * mengembalikan `bytea` identik byte demi byte.
 *
 * **Kenapa `mime` disimpan terpisah.** Saat menyajikan kembali, server harus
 * mengirim `Content-Type` yang benar. Menebaknya dari isi setiap kali berarti
 * memercayai tebakan itu; menyimpannya saat unggah berarti nilainya sudah
 * diperiksa sekali di tempat yang sama dengan pemeriksaan isinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rekening', function (Blueprint $table) {
            // Nullable: rekening transfer tidak punya gambar, dan QRIS boleh
            // hanya berupa teks (nama merchant + nomor). Memaksa kolom ini
            // terisi akan membuat pemilik mengunggah gambar apa saja supaya
            // formulirnya mau menyimpan.
            $table->binary('gambar')->nullable()->after('catatan');

            // Tipe MIME hasil pemeriksaan server, bukan dari nama berkas
            // kiriman: nama berkas dikendalikan pengunggah, isinya tidak.
            $table->string('gambar_mime', 60)->nullable()->after('gambar');

            // Ukuran dalam byte. Disimpan supaya daftar rekening bisa
            // menampilkan "berkas 214 kB" tanpa harus membaca seluruh isinya,
            // dan supaya batas ukuran bisa diperiksa ulang tanpa memuat bytea.
            $table->unsignedInteger('gambar_ukuran')->nullable()->after('gambar_mime');
        });

        // PostgreSQL memetakan `binary` Laravel ke `bytea`, yang panjangnya
        // tidak terbatas. Tidak ada ALTER lanjutan yang diperlukan; komentar
        // ini ada supaya pembaca berikutnya tidak mencari kolom `blob`.
        //
        // Batas 2 MB ditegakkan di lapisan validasi, bukan CHECK: pesan
        // penolakannya harus bisa dibaca pemilik ("maksimal 2 MB"), dan CHECK
        // hanya menghasilkan galat basis data yang tidak menjelaskan apa pun.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE rekening ALTER COLUMN gambar SET STORAGE EXTERNAL');
        }
    }

    public function down(): void
    {
        Schema::table('rekening', function (Blueprint $table) {
            $table->dropColumn(['gambar', 'gambar_mime', 'gambar_ukuran']);
        });
    }
};
