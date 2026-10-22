<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran yang benar-benar diterima (proses 4.0).
 *
 * Tiga keputusan bentuk:
 *
 *   1. **Append-only, ditegakkan trigger.** Sama seperti `tarif_sewa` dan
 *      `log_audit`. Catatan uang yang bisa diedit diam-diam tidak berguna untuk
 *      mempertanggungjawabkan saldo: angka yang salah akan "diperbaiki" dengan
 *      menghapus jejaknya, dan tidak ada yang bisa membuktikan sebaliknya.
 *      Konsekuensinya jujur dicatat di SPEC: koreksi salah input butuh baris
 *      pembalik, dan fitur itu belum ada di T5.
 *
 *   2. **`jumlah` selalu positif.** Kelebihan bayar ditolak 422, bukan disimpan
 *      sebagai saldo negatif. Pembayaran negatif akan membuat `SUM(jumlah)`
 *      tidak lagi bermakna sebagai "uang yang diterima", dan status tagihan
 *      yang diturunkan dari situ jadi bisa dibuat apa saja.
 *
 *   3. **`tanggal_pembayaran` terpisah dari `created_at`.** Yang pertama adalah
 *      tanggal uang diterima menurut pemilik; yang kedua adalah kapan baris ini
 *      dicatat. Keduanya bisa berbeda ketika pemilik mencatat pembayaran
 *      kemarin hari ini, dan perbedaan itu sendiri informasi yang berguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pembayaran', function (Blueprint $table) {
            $table->id();

            // Tanpa cascade: tagihan tidak pernah dihapus, jadi tidak ada
            // gunanya menyiapkan jalur hapus yang tidak akan dipakai.
            $table->foreignId('tagihan_id')->constrained('tagihan');

            $table->decimal('jumlah', 14, 0);
            $table->date('tanggal_pembayaran');
            $table->string('catatan')->nullable();

            $table->timestamp('created_at')->nullable();

            // Pola baca: riwayat pembayaran satu tagihan, terbaru dulu.
            $table->index(['tagihan_id', 'tanggal_pembayaran']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tolak_ubah_pembayaran() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'Tabel pembayaran bersifat append-only: % tidak diizinkan.',
                    TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER pembayaran_append_only
            BEFORE UPDATE OR DELETE ON pembayaran
            FOR EACH ROW EXECUTE FUNCTION tolak_ubah_pembayaran();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS pembayaran_append_only ON pembayaran;');
        DB::unprepared('DROP FUNCTION IF EXISTS tolak_ubah_pembayaran();');
        Schema::dropIfExists('pembayaran');
    }
};
