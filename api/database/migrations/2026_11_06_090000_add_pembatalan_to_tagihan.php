<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pembatalan tagihan (REV-FIN-02).
 *
 * Sebelum ini R-02 dijaga unique index **mutlak** `(kontrak_id, periode)`, dan
 * DFD sudah menyebut "koreksi dilakukan lewat pembatalan lalu terbit ulang"
 * padahal jalur itu mustahil: baris yang dibatalkan tetap memegang kuncinya,
 * sehingga penerbitan ulang periode yang sama selalu ditolak 409. Aturannya
 * benar, jalannya tidak ada.
 *
 * Dua perubahan, keduanya perlu:
 *
 *   1. **Index menjadi parsial** — `WHERE status <> 'dibatalkan'`. Tagihan yang
 *      dibatalkan keluar dari penjagaan, sehingga periode yang sama bisa
 *      diterbitkan ulang. Yang tetap dijaga: dua tagihan hidup untuk satu
 *      kontrak pada satu periode. Itu inti R-02, dan ia tidak dilonggarkan.
 *
 *   2. **Kolom pembatalan** — alasan, waktu, dan pelaku. Tanpa alasan, baris
 *      `dibatalkan` tidak bisa dibedakan dari baris yang salah status, dan
 *      pertanyaan "kenapa tagihan ini batal" hanya bisa dijawab dari ingatan.
 *
 * Pembatalan **tidak menghapus** baris. Tagihan yang batal tetap terbaca
 * lengkap dengan pokok dan rinciannya: yang berubah hanya statusnya. Menghapus
 * baris akan membuat nomor tagihan yang pernah disebut ke penghuni lenyap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tagihan', function (Blueprint $table) {
            // Alasan disimpan sebagai teks bebas, bukan enum: sebab pembatalan
            // di kos sungguhan beragam ("salah kamar", "penghuni batal masuk",
            // "dobel terbit"), dan enum akan menolak yang keempat.
            $table->text('alasan_batal')->nullable();

            // Pelaku ikut disimpan, bukan hanya di `log_audit`: pembatalan
            // mengubah angka yang dilihat penghuni, dan jawaban "siapa yang
            // membatalkan tagihan saya" harus ada di barisnya sendiri.
            // `restrictOnDelete` karena akun yang pernah membatalkan tagihan
            // tidak boleh dihapus — jejak uang tidak boleh yatim.
            $table->foreignId('dibatalkan_oleh')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampTz('dibatalkan_pada')->nullable();
        });

        // Index lama dijatuhkan lebih dulu: ia mutlak, jadi selama ia ada
        // penerbitan ulang periode yang sudah dibatalkan tetap ditolak.
        //
        // Yang dijatuhkan **constraint**-nya, bukan index-nya: `$table->unique()`
        // di PostgreSQL membuat UNIQUE CONSTRAINT, dan index yang menopangnya
        // tidak bisa dilepas selama constraint-nya masih ada.
        DB::statement('ALTER TABLE tagihan DROP CONSTRAINT IF EXISTS tagihan_kontrak_id_periode_unique');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tagihan_kontrak_periode_aktif_unique
            ON tagihan (kontrak_id, periode)
            WHERE status <> 'dibatalkan'
        SQL);

        // Baris yang berstatus dibatalkan wajib punya alasan. `NOT VALID`
        // karena baris lama (bila ada) tidak punya kolom ini sama sekali, dan
        // memeriksa ulang baris lama akan menggagalkan migrasi pada basis data
        // yang sudah berjalan. Constraint tetap berlaku untuk baris baru.
        DB::statement(<<<'SQL'
            ALTER TABLE tagihan
            ADD CONSTRAINT tagihan_batal_ada_alasan
            CHECK (status <> 'dibatalkan' OR alasan_batal IS NOT NULL)
            NOT VALID
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tagihan DROP CONSTRAINT IF EXISTS tagihan_batal_ada_alasan');
        DB::statement('DROP INDEX IF EXISTS tagihan_kontrak_periode_aktif_unique');

        // Index lama dipasang kembali sebagai constraint, sama seperti bentuk
        // asalnya. Bila sudah ada dua tagihan untuk periode yang sama (satu
        // batal, satu hidup), pemasangan ini akan gagal — dan itu benar:
        // keadaan itu memang tidak bisa diwakili bentuk lama.
        DB::statement(<<<'SQL'
            ALTER TABLE tagihan
            ADD CONSTRAINT tagihan_kontrak_id_periode_unique
            UNIQUE (kontrak_id, periode)
        SQL);

        Schema::table('tagihan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dibatalkan_oleh');
            $table->dropColumn(['alasan_batal', 'dibatalkan_pada']);
        });
    }
};
