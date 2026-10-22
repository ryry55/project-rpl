<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Log audit append-only (R-07).
 *
 * Tabel ini menjawab pertanyaan yang tidak bisa dijawab tagihan sendiri: siapa
 * mengubah apa, kapan. Tanpa itu, "tarif tidak berlaku surut" dan "satu tagihan
 * per periode" hanya bisa dipercaya, tidak bisa dibuktikan.
 *
 * Tiga keputusan bentuk yang perlu dijelaskan:
 *
 *   1. **Tidak ada foreign key ke `users`.** Bukan kelalaian. `ON DELETE SET
 *      NULL` melakukan UPDATE pada baris `log_audit`, dan trigger append-only
 *      akan menolaknya — artinya menghapus user menjadi mustahil. Sebaliknya,
 *      `RESTRICT` membuat user yang pernah tercatat tidak bisa dihapus selamanya.
 *      Log audit harus tetap terbaca justru ketika catatan asalnya sudah tidak
 *      ada, jadi `aktor_id` disimpan sebagai angka biasa tanpa constraint.
 *
 *   2. **`aktor_nama` disalin, bukan di-join.** Kalau nama diambil lewat join,
 *      mengganti nama user akan menulis ulang sejarah: log lama akan tampak
 *      dibuat oleh nama yang belum ada saat itu. Salinan beku membuat log
 *      jujur terhadap keadaan saat kejadian.
 *
 *   3. **`nilai_lama` dan `nilai_baru` bertipe jsonb.** Satu tabel untuk semua
 *      entitas menuntut bentuk nilai yang berbeda-beda; kolom per entitas akan
 *      menghasilkan tabel yang sebagian besar kosong.
 *
 * Penolakan UPDATE/DELETE ditegakkan trigger, sama seperti tabel riwayat lain
 * (R-06). Alasannya sudah dicatat di migrasi 130600: REVOKE tidak berlaku bagi
 * pemilik tabel, sehingga tesnya bisa lolos secara palsu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_audit', function (Blueprint $table) {
            $table->id();

            // Tanpa foreign key — lihat catatan 1 di atas.
            $table->unsignedBigInteger('aktor_id')->nullable();
            $table->string('aktor_nama')->nullable();

            // Contoh: 'tagihan.terbit', 'tagihan.bayar', 'tarif.tambah'.
            $table->string('aksi');
            // Nama tabel yang disentuh: 'tagihan', 'tarif_sewa', 'kontrak'.
            $table->string('entitas');
            $table->unsignedBigInteger('entitas_id')->nullable();

            $table->jsonb('nilai_lama')->nullable();
            $table->jsonb('nilai_baru')->nullable();

            $table->timestampTz('dibuat_pada')->useCurrent();

            // Dua pola baca yang akan datang: "riwayat satu tagihan" dan
            // "apa saja yang terjadi hari ini".
            $table->index(['entitas', 'entitas_id']);
            $table->index('dibuat_pada');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tolak_ubah_log_audit() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'Tabel log_audit bersifat append-only (R-07): % tidak diizinkan.',
                    TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER log_audit_append_only
            BEFORE UPDATE OR DELETE ON log_audit
            FOR EACH ROW EXECUTE FUNCTION tolak_ubah_log_audit();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS log_audit_append_only ON log_audit;');
        DB::unprepared('DROP FUNCTION IF EXISTS tolak_ubah_log_audit();');
        Schema::dropIfExists('log_audit');
    }
};
