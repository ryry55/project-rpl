<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deposit dikeluarkan dari sistem — keputusan pemilik, fase terakhir.
 *
 * **Kenapa dihapus, bukan dilengkapi.** Deposit dikumpulkan saat kontrak
 * dibuat, tetapi tidak pernah dicatat sebagai uang masuk, tidak pernah
 * mengurangi tagihan, dan tidak pernah dikembalikan saat penghuni keluar.
 * Artinya: sistem menampung janji uang yang tidak pernah ia pertanggungjawabkan.
 * Dua jalan keluarnya merekam deposit sebagai pembayaran sah lalu mengembalikan
 * lewat baris negatif, atau berhenti berpura-pura menanganinya. Pemilik memilih
 * yang kedua. Kolom yang menyimpan angka tanpa akibat lebih buruk daripada
 * kolom yang tidak ada: ia membuat orang mengira uangnya terjaga.
 *
 * **Kenapa baris kebijakan `deposit` ikut dihapus, bukan hanya disembunyikan
 * dari menu.** Baris yang tidak bisa ditafsirkan kode mana pun adalah baris yang
 * menunggu untuk salah dibaca. `PenyelesaiKebijakan` membacanya lewat `tipe`;
 * membiarkan barisnya berarti meninggalkan jalur yang tidak punya pembaca sah.
 * CHECK constraint juga harus menyempit, kalau tidak basis data masih menerima
 * tipe yang tidak dikenal aplikasi.
 *
 * **Trigger append-only harus dilepas lebih dulu.** `kebijakan_kos` menolak
 * DELETE dengan sengaja (R-06). Ini satu-satunya tempat di mana kebijakan
 * benar-benar dihapus, dan itu memang tujuannya — tetapi ia harus melewati
 * penjaganya secara sadar, bukan karena penjaganya lupa dipasang.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. Buang baris kebijakan deposit -------------------------------
        DB::unprepared('DROP TRIGGER IF EXISTS kebijakan_kos_append_only ON kebijakan_kos;');

        DB::statement("DELETE FROM kebijakan_kos WHERE tipe = 'deposit'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER kebijakan_kos_append_only
            BEFORE UPDATE OR DELETE ON kebijakan_kos
            FOR EACH ROW EXECUTE FUNCTION tolak_ubah_kebijakan_kos();
        SQL);

        // --- 2. Sempitkan CHECK ke tiga tipe --------------------------------
        //
        // Constraint lama menyebut `deposit` di dua tempat: daftar tipe, dan
        // cabang `CASE` yang mengatur kolom angka. Keduanya diganti sekaligus;
        // menyisakan salah satunya berarti basis data masih menerima baris yang
        // tidak bisa dibaca aplikasi.
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_tipe_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_nilai_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_kolom_sesuai_tipe');

        DB::statement(<<<'SQL'
            ALTER TABLE kebijakan_kos
            ADD CONSTRAINT kebijakan_kos_tipe_valid
            CHECK (tipe IN ('satuan_sewa', 'jatuh_tempo', 'denda'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE kebijakan_kos
            ADD CONSTRAINT kebijakan_kos_nilai_valid
            CHECK (
                 (tipe = 'satuan_sewa'
                  AND nilai IN ('bulanan', 'mingguan', 'harian'))
              OR (tipe = 'jatuh_tempo'
                  AND nilai IN ('tanggal_masuk', 'tanggal_tetap', 'akhir_bulan'))
              OR (tipe = 'denda'
                  AND nilai IN ('persen_harian', 'persen_bulanan', 'nominal_tetap', 'tanpa_denda'))
            )
        SQL);

        // Bentuknya sama persis dengan migrasi T17, kecuali cabang `deposit`
        // yang hilang. `nominal` tetap ada karena denda nominal tetap memakainya.
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

                WHEN 'denda' THEN
                  tanggal IS NULL
                  AND CASE
                        WHEN nilai IN ('persen_harian', 'persen_bulanan') THEN
                          persen IS NOT NULL AND persen > 0
                          AND plafon_persen IS NOT NULL AND plafon_persen > 0
                          AND nominal IS NULL
                          AND masa_tenggang IS NOT NULL AND masa_tenggang >= 0
                        WHEN nilai = 'nominal_tetap' THEN
                          nominal IS NOT NULL AND nominal >= 0
                          AND persen IS NULL AND plafon_persen IS NULL
                          AND masa_tenggang IS NULL
                        ELSE
                          persen IS NULL AND plafon_persen IS NULL
                          AND masa_tenggang IS NULL AND nominal IS NULL
                      END
              END
            )
        SQL);

        // --- 3. Hapus kolom kontrak.deposit --------------------------------
        //
        // Isinya tidak bisa dipulihkan, dan itu konsekuensi yang diterima:
        // kolom ini tidak pernah dibaca siapa pun, jadi tidak ada laporan atau
        // tagihan yang angkanya bergantung padanya.
        Schema::table('kontrak', function (Blueprint $table) {
            $table->dropColumn('deposit');
        });
    }

    public function down(): void
    {
        // Kolom dikembalikan dengan bawaan 0, bukan NOT NULL tanpa bawaan:
        // baris yang sudah ada tidak punya angka untuk diisi, dan migrasi
        // mundur yang menuntut angka akan gagal di tengah jalan.
        Schema::table('kontrak', function (Blueprint $table) {
            $table->decimal('deposit', 14, 0)->default(0)->after('tarif_awal');
        });

        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_tipe_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_nilai_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_kolom_sesuai_tipe');

        DB::statement(<<<'SQL'
            ALTER TABLE kebijakan_kos
            ADD CONSTRAINT kebijakan_kos_tipe_valid
            CHECK (tipe IN ('satuan_sewa', 'jatuh_tempo', 'denda', 'deposit'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE kebijakan_kos
            ADD CONSTRAINT kebijakan_kos_nilai_valid
            CHECK (
                 (tipe = 'satuan_sewa'
                  AND nilai IN ('bulanan', 'mingguan', 'harian'))
              OR (tipe = 'jatuh_tempo'
                  AND nilai IN ('tanggal_masuk', 'tanggal_tetap', 'akhir_bulan'))
              OR (tipe = 'denda'
                  AND nilai IN ('persen_harian', 'persen_bulanan', 'nominal_tetap', 'tanpa_denda'))
              OR (tipe = 'deposit'
                  AND nilai IN ('tanpa_deposit', 'nominal', 'satu_bulan'))
            )
        SQL);

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

                WHEN 'denda' THEN
                  tanggal IS NULL
                  AND CASE
                        WHEN nilai IN ('persen_harian', 'persen_bulanan') THEN
                          persen IS NOT NULL AND persen > 0
                          AND plafon_persen IS NOT NULL AND plafon_persen > 0
                          AND nominal IS NULL
                          AND masa_tenggang IS NOT NULL AND masa_tenggang >= 0
                        WHEN nilai = 'nominal_tetap' THEN
                          nominal IS NOT NULL AND nominal >= 0
                          AND persen IS NULL AND plafon_persen IS NULL
                          AND masa_tenggang IS NULL
                        ELSE
                          persen IS NULL AND plafon_persen IS NULL
                          AND masa_tenggang IS NULL AND nominal IS NULL
                      END

                WHEN 'deposit' THEN
                  persen IS NULL AND plafon_persen IS NULL
                  AND masa_tenggang IS NULL AND tanggal IS NULL
                  AND CASE WHEN nilai = 'nominal'
                           THEN nominal IS NOT NULL AND nominal >= 0
                           ELSE nominal IS NULL END
              END
            )
        SQL);
    }
};
