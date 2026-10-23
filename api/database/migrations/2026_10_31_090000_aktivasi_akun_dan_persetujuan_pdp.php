<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aktivasi akun, wajib ganti sandi, dan bukti persetujuan PDP (T27, T28).
 *
 * Kenapa tabel `token_akun` ada, padahal Laravel sudah menyediakan
 * `password_reset_tokens`:
 *
 *   Tabel bawaan itu berkunci **email** dan hanya menyimpan satu token per
 *   alamat. Dua alur di sini berbeda tujuan - "aktifkan akun baru" dan "saya
 *   lupa sandi" - dan keduanya boleh hidup bersamaan untuk satu orang. Dengan
 *   satu baris per email, tautan aktivasi yang belum dipakai akan tertimpa
 *   tautan reset, atau sebaliknya, dan yang kalah menjadi tautan mati tanpa
 *   penjelasan. Kolom `jenis` memisahkan keduanya, dan `pakai()` menolak token
 *   yang jenisnya tidak cocok.
 *
 *   Bentuknya juga menyimpan `kedaluwarsa_pada` per baris, bukan satu angka
 *   menit di config: tautan aktivasi pantas berlaku berhari-hari (pemilik
 *   membuat akun, penghuni baru membuka emailnya akhir pekan), sedangkan
 *   tautan reset sandi tidak. Satu angka untuk keduanya memaksa salah satunya
 *   menjadi tidak masuk akal.
 *
 * **Yang disimpan adalah hash, bukan tokennya.** Kalau tabel ini bocor, isinya
 * tidak bisa dipakai masuk. Ini sama seperti kolom `password`, dan alasannya
 * sama: rahasia yang bisa dipakai langsung tidak boleh tersimpan.
 *
 * `wajib_ganti_sandi` menutup lubang yang berbeda dari aktivasi. Akun yang
 * dibuat SEBELUM T27 sandinya diketik pemilik, jadi pemilik mengetahuinya.
 * Menghapus kolom sandi dari formulir tidak menghapus pengetahuan itu. Kolom
 * ini memaksa pemilik akun menggantinya pada login pertama, dan middleware
 * menutup seluruh endpoint lain sampai ia melakukannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Bawaan false: akun baru hasil aktivasi sudah menetapkan sandinya
            // sendiri, jadi tidak ada yang perlu diganti. Nilai true hanya
            // diberikan kepada akun lama lewat backfill di bawah.
            $table->boolean('wajib_ganti_sandi')->default(false)->after('password');
        });

        Schema::create('token_akun', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete: token tidak punya arti tanpa akunnya, dan
            // menghapus akun berarti tautan yang beredar harus mati.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // 'aktivasi' | 'reset' - lihat CHECK di bawah.
            $table->string('jenis', 10);

            // SHA-256 heksadesimal. Tetap 64 huruf, jadi char(64) lebih jujur
            // daripada varchar dan membuat index-nya lebih kecil.
            $table->char('token_hash', 64)->unique();

            $table->timestampTz('kedaluwarsa_pada');
            $table->timestampTz('dipakai_pada')->nullable();
            $table->timestampTz('dibuat_pada')->useCurrent();

            $table->index(['user_id', 'jenis']);
        });

        DB::statement(
            "ALTER TABLE token_akun
             ADD CONSTRAINT token_akun_jenis_dikenal
             CHECK (jenis IN ('aktivasi', 'reset'))"
        );

        /*
         * Bukti persetujuan pemrosesan data pribadi (T28).
         *
         * Satu baris per persetujuan, bukan satu kolom tanggal di `users`.
         * Alasannya versi: teks persetujuan akan berubah, dan persetujuan atas
         * teks lama bukan persetujuan atas teks baru. Dengan baris, riwayatnya
         * terbaca - "menyetujui versi 1 pada tanggal sekian" - dan kenaikan
         * versi cukup menambah baris, bukan menimpa bukti lama.
         *
         * Append-only lewat trigger, sama seperti log_audit: bukti persetujuan
         * yang bisa disunting bukan bukti.
         */
        Schema::create('persetujuan_pdp', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete, bukan cascade: menghapus akun tidak boleh
            // menghapus bukti bahwa pemrosesan pernah punya dasar. Akun yang
            // dihapus dianonimkan, bukan dihilangkan (T28), sehingga baris ini
            // tetap punya induk.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->string('versi', 20);
            $table->timestampTz('disetujui_pada')->useCurrent();

            // Alamat IP saat menyetujui. Bukan untuk melacak, melainkan untuk
            // menjawab "apakah persetujuan ini benar datang dari pemilik akun"
            // bila suatu hari dipertanyakan. Nullable: permintaan dari jaringan
            // internal tidak selalu punya alamat yang berarti.
            $table->string('ip', 45)->nullable();

            $table->unique(['user_id', 'versi']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tolak_ubah_persetujuan_pdp() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'Tabel persetujuan_pdp bersifat append-only (T28): % tidak diizinkan.',
                    TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER persetujuan_pdp_append_only
            BEFORE UPDATE OR DELETE ON persetujuan_pdp
            FOR EACH ROW EXECUTE FUNCTION tolak_ubah_persetujuan_pdp();
        SQL);

        /*
         * Backfill akun lama.
         *
         * Seluruh akun penghuni dan pengelola yang ada saat migrasi ini berjalan
         * dibuat lewat formulir yang meminta pemilik mengetik sandinya. Artinya
         * pemilik mengetahuinya, dan itu persis lubang yang T27 tutup. Sandi
         * yang sudah diketahui tidak bisa "ditarik kembali"; yang bisa dilakukan
         * adalah memaksa pemegangnya menggantinya.
         *
         * Dua akun demo dikecualikan dengan alasan yang praktis: kredensialnya
         * dicetak di layar masuk dan di README, dan seeder membuatnya ulang
         * setiap container start. Memaksanya ganti sandi berarti dosen yang
         * membuka aplikasi melihat layar "ganti sandi" sebelum melihat apa pun -
         * dan sandi barunya tidak akan tercetak di mana pun. Keduanya juga
         * akun contoh, bukan akun orang sungguhan.
         */
        DB::statement(
            "UPDATE users
             SET wajib_ganti_sandi = true
             WHERE peran IN ('penghuni', 'pengelola')
               AND email NOT IN ('penghuni@kos.test', 'pengelola@kos.test')"
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS persetujuan_pdp_append_only ON persetujuan_pdp;');
        DB::unprepared('DROP FUNCTION IF EXISTS tolak_ubah_persetujuan_pdp();');

        Schema::dropIfExists('persetujuan_pdp');
        Schema::dropIfExists('token_akun');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wajib_ganti_sandi');
        });
    }
};
