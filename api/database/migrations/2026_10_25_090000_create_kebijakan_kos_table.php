<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kebijakan kos (T16) — satu baris = satu dimensi kebijakan.
 *
 * Sebelum ini, cara menagih ditulis di dalam kode: satuan sewa selalu bulanan
 * (lihat Kalkulator::hitungSewaBulanPertama) dan jatuh tempo selalu mengikuti
 * tanggal masuk (lihat Kalkulator::tanggalJatuhTempo). Itu benar untuk satu kos
 * contoh, tetapi keliru sebagai produk: kos mingguan dan kos harian ada, dan
 * pemilik yang menagih "tanggal 10 tiap bulan" tidak bisa menyatakannya sama
 * sekali.
 *
 * Kenapa satu baris = satu dimensi, bukan satu baris berisi semua dimensi:
 * kalau pemilik hanya ingin mengubah jatuh tempo, satu-baris-semua-dimensi
 * memaksa menulis ulang satuan sewa juga, dan riwayatnya jadi tidak terbaca.
 * Dengan satu baris per dimensi, "kapan pemilik mengubah apa" bisa dibaca
 * langsung dari tabel.
 *
 * `kamar_id` NULL berarti default seluruh kos; terisi berarti penimpa untuk
 * kamar itu. Polanya sama dengan `komponen_biaya` (T11) supaya hanya ada satu
 * cara memahami "khusus kamar menimpa global" di seluruh sistem.
 *
 * Tabel ini **append-only**, ditegakkan trigger di bawah — sama seperti
 * `tarif_sewa` dan `komponen_biaya`. Mengubah kebijakan berarti menambah baris
 * baru dengan `berlaku_dari` baru, sehingga tagihan lama tetap bisa dijelaskan
 * dengan kebijakan yang berlaku saat ia terbit (R-06).
 *
 * **Hanya dua tipe yang dibuka di sini.** `denda` dan `deposit` menyusul di
 * LANGKAH 3 bersama pembacanya. Membuka keempat tipe sekarang berarti pemilik
 * bisa mengisi kebijakan denda yang belum dibaca siapa pun — dan angka denda
 * yang terlihat di layar akan tetap datang dari `parameter_denda`. Konfigurasi
 * yang bisa diisi tetapi diabaikan lebih buruk daripada konfigurasi yang belum
 * ada. Menambah tipe nanti butuh ALTER TABLE pada ketiga CHECK constraint;
 * itu harga yang sudah diterima sadar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kebijakan_kos', function (Blueprint $table) {
            $table->id();

            // NULL = default seluruh kos; terisi = penimpa untuk kamar itu.
            $table->foreignId('kamar_id')
                ->nullable()
                ->constrained('kamar')
                ->cascadeOnDelete();

            // `tipe` adalah dimensi kebijakan, `nilai` adalah pilihannya.
            // Keduanya string, bukan enum PostgreSQL: menambah pilihan baru
            // berarti ALTER TYPE yang tidak bisa dijalankan di dalam transaksi
            // pada versi PostgreSQL lama, sementara CHECK constraint cukup
            // dijatuhkan dan dipasang ulang.
            $table->string('tipe', 30);
            $table->string('nilai', 30);

            // Angka pendukung. Hanya kolom yang relevan dengan tipe yang boleh
            // terisi — ditegakkan CHECK constraint ketiga di bawah. Tanpa
            // penjagaan itu, lima kolom nullable bisa diisi kombinasi apa pun
            // tanpa protes, dan tabel bertipe jadi lebih buruk daripada jsonb.
            $table->decimal('persen', 5, 2)->nullable();
            $table->decimal('plafon_persen', 5, 2)->nullable();
            $table->smallInteger('masa_tenggang')->nullable();
            $table->decimal('nominal', 14, 0)->nullable();
            $table->smallInteger('tanggal')->nullable();

            $table->date('berlaku_dari');
            $table->timestampTz('dibuat_pada')->useCurrent();

            $table->index(['tipe', 'berlaku_dari']);
        });

        // NULLS NOT DISTINCT (PostgreSQL 15+, server ini 16). Tanpa itu, dua
        // baris default untuk tipe+tanggal yang sama dianggap berbeda karena
        // SQL memperlakukan NULL sebagai selalu unik — lalu PenyelesaiKebijakan
        // memilih salah satunya berdasarkan urutan fisik, yang tidak dijamin
        // apa pun.
        Schema::table('kebijakan_kos', function (Blueprint $table) {
            $table->unique(['tipe', 'kamar_id', 'berlaku_dari'], 'kebijakan_kos_tipe_kamar_tanggal_unique')
                ->nullsNotDistinct();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE kebijakan_kos
            ADD CONSTRAINT kebijakan_kos_tipe_valid
            CHECK (tipe IN ('satuan_sewa', 'jatuh_tempo'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE kebijakan_kos
            ADD CONSTRAINT kebijakan_kos_nilai_valid
            CHECK (
                 (tipe = 'satuan_sewa'
                  AND nilai IN ('bulanan', 'mingguan', 'harian'))
              OR (tipe = 'jatuh_tempo'
                  AND nilai IN ('tanggal_masuk', 'tanggal_tetap', 'akhir_bulan'))
            )
        SQL);

        // Setiap cabang sengaja dibuka dengan IS NOT NULL sebelum perbandingan.
        // Tanpa itu, `persen > 0` yang bernilai NULL membuat seluruh konjungsi
        // bernilai NULL, dan CHECK menganggap NULL sebagai lolos — jadi baris
        // yang seharusnya ditolak justru tersimpan.
        DB::statement(<<<'SQL'
            ALTER TABLE kebijakan_kos
            ADD CONSTRAINT kebijakan_kos_kolom_sesuai_tipe
            CHECK (
              CASE tipe
                WHEN 'satuan_sewa' THEN
                  persen IS NULL AND plafon_persen IS NULL
                  AND masa_tenggang IS NULL AND nominal IS NULL
                  AND tanggal IS NULL
                WHEN 'jatuh_tempo' THEN
                  persen IS NULL AND plafon_persen IS NULL
                  AND masa_tenggang IS NULL AND nominal IS NULL
                  AND CASE WHEN nilai = 'tanggal_tetap'
                           THEN tanggal IS NOT NULL AND tanggal BETWEEN 1 AND 31
                           ELSE tanggal IS NULL END
              END
            )
        SQL);

        // Append-only, dengan alasan yang sama seperti migrasi 130600: R-06
        // harus tetap benar walau seseorang menjalankan SQL langsung, dan
        // trigger dipilih daripada REVOKE karena REVOKE tidak berlaku bagi
        // pemilik tabel — termasuk user yang dipakai saat pengujian, sehingga
        // tesnya bisa lolos secara palsu.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tolak_ubah_kebijakan_kos() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'Tabel kebijakan_kos bersifat append-only (R-06): % tidak diizinkan. Tambahkan baris baru dengan berlaku_dari yang baru.',
                    TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER kebijakan_kos_append_only
            BEFORE UPDATE OR DELETE ON kebijakan_kos
            FOR EACH ROW EXECUTE FUNCTION tolak_ubah_kebijakan_kos();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS kebijakan_kos_append_only ON kebijakan_kos;');
        DB::unprepared('DROP FUNCTION IF EXISTS tolak_ubah_kebijakan_kos();');

        Schema::dropIfExists('kebijakan_kos');
    }
};
