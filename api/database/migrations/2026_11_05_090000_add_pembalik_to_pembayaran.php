<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Baris pembalik pembayaran (REV-FIN-01).
 *
 * Masalah yang ditutup: `pembayaran` append-only, dan sampai sekarang tidak ada
 * cara sah untuk menarik kembali uang yang ternyata salah catat. Akibatnya
 * pemeriksaan yang menolak sebuah pembayaran hanya menempelkan label - uangnya
 * tetap tinggal di `SUM(pembayaran.jumlah)`, tagihannya tetap `lunas`, dan
 * rekap tetap melaporkan pendapatan yang tidak pernah diterima siapa pun.
 *
 * Tiga keputusan bentuk, dan yang ketiga paling menentukan:
 *
 *   1. **Pembalik adalah baris baru, bukan UPDATE atau DELETE.** Trigger
 *      append-only tidak dilonggarkan sedikit pun. Yang salah tetap terbaca
 *      sebagai salah; yang ditambahkan adalah pernyataan "uang ini ditarik
 *      kembali", lengkap dengan siapa dan kapan.
 *
 *   2. **`pembalik_id` menunjuk baris yang dibalik, dan unik.** Satu pembayaran
 *      hanya boleh dibalik sekali. Tanpa unique, dua penekanan tombol akan
 *      menarik uang yang sama dua kali - dan kelebihan tarik tidak punya
 *      pembalik, karena pembalik tidak bisa dibalik.
 *
 *   3. **`jumlah` boleh NEGATIF, tetapi hanya untuk baris pembalik.** Ini
 *      pilihan yang sengaja diambil meski bertentangan dengan catatan lama
 *      "jumlah selalu positif", karena ia menjaga satu hal yang jauh lebih
 *      penting: `SUM(pembayaran.jumlah)` tetap berarti **uang bersih yang
 *      diterima**. Kalau pembalik disimpan sebagai baris positif di tabel
 *      lain, setiap penjumlahan di sistem - rekap, sisa tagihan, tunai di
 *      tangan, piutang - harus diubah menjadi pengurangan dua sumber, dan
 *      satu tempat yang lupa akan menghasilkan angka yang berbeda dari
 *      rekening. Satu tanda minus di satu kolom jauh lebih murah daripada
 *      enam penjumlahan yang harus ingat.
 *
 *      Yang menjaga agar tanda minus tidak disalahgunakan adalah CHECK di
 *      bawah: baris tanpa `pembalik_id` wajib positif, baris dengan
 *      `pembalik_id` wajib negatif. Tidak ada keadaan ketiga.
 *
 * Catatan untuk pembaca laporan: "jumlah selalu positif" pada dokumen lama
 * karena itu tidak lagi berlaku, dan SPEC diperbarui bersama migrasi ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            // `restrictOnDelete`: baris pembalik menyebut baris yang dibalik,
            // dan baris pembayaran tidak pernah dihapus. Tanpa restrict,
            // penghapusan massal akan menolak lewat trigger append-only dengan
            // pesan yang menyesatkan - sama alasannya dengan `dicatat_oleh`.
            $table->foreignId('pembalik_id')->nullable()->after('tagihan_id')
                ->constrained('pembayaran')
                ->restrictOnDelete();

            // Satu pembayaran hanya boleh dibalik sekali. PostgreSQL menganggap
            // NULL berbeda satu sama lain, jadi baris biasa (NULL) tidak
            // terpengaruh.
            $table->unique('pembalik_id');
        });

        // Baris biasa wajib positif; baris pembalik wajib negatif. Ditegakkan
        // basis data, bukan hanya domain: satu jalur tulis yang lupa
        // memeriksanya sudah cukup untuk membuat rekap punya angka yang tidak
        // bisa dijelaskan.
        DB::statement(
            'ALTER TABLE pembayaran
             ADD CONSTRAINT pembayaran_tanda_jumlah
             CHECK (
                 (pembalik_id IS NULL AND jumlah > 0)
                 OR (pembalik_id IS NOT NULL AND jumlah < 0)
             )'
        );

        // Alasan pembalikan wajib ada, karena inilah satu-satunya keterangan
        // yang menjelaskan kenapa uang yang sudah tercatat ditarik kembali.
        // Tanpa itu, baris negatif di rekap hanya menimbulkan pertanyaan baru.
        DB::statement(
            'ALTER TABLE pembayaran
             ADD CONSTRAINT pembalik_ada_alasan
             CHECK (pembalik_id IS NULL OR (catatan IS NOT NULL AND length(btrim(catatan)) > 0))'
        );

        // Pembalik tidak bisa dibalik. Kalau diizinkan, dua baris negatif bisa
        // saling menunjuk dan uang yang sama bisa dibuat muncul-hilang tanpa
        // batas. Koreksi atas koreksi dilakukan dengan mencatat pembayaran baru,
        // bukan dengan membalik pembalik.
        //
        // Ditegakkan trigger, bukan CHECK: CHECK constraint PostgreSQL tidak
        // boleh berisi subquery, dan aturan ini butuh membaca baris lain.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tolak_pembalik_bertingkat() RETURNS trigger AS $$
            BEGIN
                IF NEW.pembalik_id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM pembayaran
                    WHERE id = NEW.pembalik_id AND pembalik_id IS NOT NULL
                ) THEN
                    RAISE EXCEPTION
                        'Baris pembalik tidak boleh dibalik lagi: koreksi dilakukan dengan mencatat pembayaran baru.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pembayaran_tolak_pembalik_bertingkat
            BEFORE INSERT ON pembayaran
            FOR EACH ROW EXECUTE FUNCTION tolak_pembalik_bertingkat();
        SQL);

        // Pola baca: "pembayaran ini sudah dibalik?" ditanyakan untuk setiap
        // baris di layar riwayat. Indeks parsial menjaga biayanya nol untuk
        // baris biasa, yang jumlahnya hampir seluruh tabel.
        DB::statement(
            'CREATE INDEX pembayaran_pembalik_id_idx ON pembayaran (pembalik_id)
             WHERE pembalik_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS pembayaran_tolak_pembalik_bertingkat ON pembayaran;');
        DB::unprepared('DROP FUNCTION IF EXISTS tolak_pembalik_bertingkat();');
        DB::statement('DROP INDEX IF EXISTS pembayaran_pembalik_id_idx');
        DB::statement('ALTER TABLE pembayaran DROP CONSTRAINT IF EXISTS pembalik_ada_alasan');
        DB::statement('ALTER TABLE pembayaran DROP CONSTRAINT IF EXISTS pembayaran_tanda_jumlah');

        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropUnique(['pembalik_id']);
            $table->dropConstrainedForeignId('pembalik_id');
        });
    }
};
