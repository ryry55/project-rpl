<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pemeriksaan pembayaran sebagai tabel tersendiri (T20).
 *
 * Kenapa tabel baru, bukan kolom `diverifikasi_pada` yang di-UPDATE. Tabel
 * `pembayaran` append-only lewat trigger; begitu satu UPDATE diizinkan, janji
 * append-only kehilangan nilainya sebagai bukti ke penguji - dan janji itulah
 * satu-satunya alasan catatan uang ini bisa dipercaya. Precedent-nya `log_audit`,
 * yang juga tidak punya endpoint tulis sama sekali.
 *
 * Tiga keputusan bentuk:
 *
 *   1. **Baris = satu pemeriksaan, bukan satu status.** Karena tabelnya
 *      append-only, pemeriksaan ulang menambah baris kedua, dan yang berlaku
 *      adalah baris TERAKHIR. Ini bukan kerumitan tanpa guna: bukti transfer
 *      yang awalnya ditolak karena buram, lalu dikirim ulang dengan gambar yang
 *      jelas, harus menyisakan dua fakta - pernah ditolak, lalu diterima.
 *      Kolom status yang di-UPDATE hanya menyimpan fakta kedua.
 *
 *   2. **`keputusan` punya dua nilai, bukan hanya "terverifikasi".** Penolakan
 *      adalah hasil pemeriksaan yang sama pentingnya dengan penerimaan, dan
 *      penolakan yang tidak tercatat sama saja dengan tidak pernah diperiksa.
 *      Tanpa kolom ini, satu-satunya cara menyatakan "bukti ini tidak cocok"
 *      adalah diam - dan diam tidak bisa dipertanggungjawabkan.
 *
 *   3. **Tidak ada kolom `diubah_pada`.** Baris tidak pernah berubah, jadi
 *      `created_at` sudah menjadi satu-satunya waktu yang bermakna. Menambah
 *      kolom yang selalu NULL hanya mengundang orang menulis ke situ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verifikasi_pembayaran', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pembayaran_id')->constrained('pembayaran');

            // Siapa yang memeriksa. Nullable hanya untuk pemeriksaan otomatis
            // oleh sistem (webhook gateway di T22), yang tidak punya aktor
            // manusia - sama seperti `log_audit.aktor_id` yang null untuk
            // perintah artisan.
            $table->foreignId('diverifikasi_oleh')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('keputusan', 10);

            // Alasan penolakan, atau catatan pemeriksaan. Wajib untuk
            // penolakan - dijaga CHECK di bawah, bukan hanya validasi, karena
            // penolakan tanpa alasan tidak bisa ditindaklanjuti siapa pun.
            $table->string('catatan', 255)->nullable();

            $table->timestamp('created_at')->nullable();

            // Pola baca: seluruh pemeriksaan satu pembayaran, terbaru dulu.
            // Rekap juga menanyakan "apakah ada baris terima untuk pembayaran
            // ini", dan itu memakai indeks yang sama.
            $table->index(['pembayaran_id', 'id']);
        });

        DB::statement(
            "ALTER TABLE verifikasi_pembayaran
             ADD CONSTRAINT verifikasi_pembayaran_keputusan_dikenal
             CHECK (keputusan IN ('terima', 'tolak'))"
        );

        // Penolakan wajib beralasan. Ini bukan kerapian: penolakan tanpa alasan
        // membuat pengelola yang mencatat pembayaran tidak tahu apa yang harus
        // diperbaiki, dan ia akan mencatat ulang baris yang sama - sehingga
        // uangnya tercatat dua kali.
        DB::statement(
            "ALTER TABLE verifikasi_pembayaran
             ADD CONSTRAINT verifikasi_pembayaran_tolak_beralasan
             CHECK (keputusan <> 'tolak' OR catatan IS NOT NULL)"
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tolak_ubah_verifikasi_pembayaran() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'Tabel verifikasi_pembayaran bersifat append-only: % tidak diizinkan.',
                    TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER verifikasi_pembayaran_append_only
            BEFORE UPDATE OR DELETE ON verifikasi_pembayaran
            FOR EACH ROW EXECUTE FUNCTION tolak_ubah_verifikasi_pembayaran();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS verifikasi_pembayaran_append_only ON verifikasi_pembayaran;');
        DB::unprepared('DROP FUNCTION IF EXISTS tolak_ubah_verifikasi_pembayaran();');
        Schema::dropIfExists('verifikasi_pembayaran');
    }
};
