<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tanggal terbit otomatis sebagai kebijakan (K-14 Level 1).
 *
 * Satu tipe baru, `tanggal_terbit`, dengan satu nilai (`tanggal_tetap`) dan
 * angkanya di kolom `tanggal` — kolom yang sama dengan jatuh tempo, bukan kolom
 * baru. Tanggalnya dibatasi 1–28: 29–31 tidak ada di setiap bulan, dan tanggal
 * terbit yang tidak ada berarti tagihan yang tidak pernah terbit.
 *
 * **Arti yang dipilih pemilik adalah A, bukan B** (lihat
 * `docs/keputusan-terbuka.md` K-14): tanggal ini hanya boleh **menunda**
 * penerbitan, tidak pernah memajukannya. Siklus yang mulai tanggal 15 tetap
 * terbit tanggal 15 walau tanggal terbit disetel 5. Karena itu tipe ini tidak
 * butuh kolom `berlaku` tambahan dan tidak butuh penjagaan baru — penjagaan
 * "belum waktunya" yang sudah ada cukup, hanya tanggal pembandingnya berubah.
 *
 * Migrasi ini bertanggal setelah `pola_tagihan` karena keduanya memasang ulang
 * CHECK yang sama; yang lebih awal akan ditimpa yang lebih akhir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_tipe_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_nilai_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_kolom_sesuai_tipe');

        DB::statement(<<<'SQL'
            ALTER TABLE kebijakan_kos
            ADD CONSTRAINT kebijakan_kos_tipe_valid
            CHECK (tipe IN ('satuan_sewa', 'jatuh_tempo', 'denda', 'pola_tagihan', 'tanggal_terbit'))
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
              OR (tipe = 'pola_tagihan'
                  AND nilai IN ('tanggal_1', 'anniversary'))
              OR (tipe = 'tanggal_terbit'
                  AND nilai IN ('tanggal_tetap'))
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

                WHEN 'pola_tagihan' THEN
                  persen IS NULL AND plafon_persen IS NULL
                  AND masa_tenggang IS NULL AND nominal IS NULL
                  AND tanggal IS NULL

                WHEN 'tanggal_terbit' THEN
                  persen IS NULL AND plafon_persen IS NULL
                  AND masa_tenggang IS NULL AND nominal IS NULL
                  AND tanggal IS NOT NULL AND tanggal BETWEEN 1 AND 28
              END
            )
        SQL);
    }

    public function down(): void
    {
        // Baris tanggal terbit dibuang lebih dulu: CHECK sebelumnya tidak
        // mengenal tipe ini. Trigger append-only dilepas lalu dipasang lagi.
        DB::unprepared('DROP TRIGGER IF EXISTS kebijakan_kos_append_only ON kebijakan_kos;');

        DB::statement("DELETE FROM kebijakan_kos WHERE tipe = 'tanggal_terbit'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER kebijakan_kos_append_only
            BEFORE UPDATE OR DELETE ON kebijakan_kos
            FOR EACH ROW EXECUTE FUNCTION tolak_ubah_kebijakan_kos();
        SQL);

        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_tipe_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_nilai_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_kolom_sesuai_tipe');

        // Bentuk tepat sebelum migrasi ini: empat tipe, termasuk pola_tagihan.
        DB::statement(<<<'SQL'
            ALTER TABLE kebijakan_kos
            ADD CONSTRAINT kebijakan_kos_tipe_valid
            CHECK (tipe IN ('satuan_sewa', 'jatuh_tempo', 'denda', 'pola_tagihan'))
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
              OR (tipe = 'pola_tagihan'
                  AND nilai IN ('tanggal_1', 'anniversary'))
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

                WHEN 'pola_tagihan' THEN
                  persen IS NULL AND plafon_persen IS NULL
                  AND masa_tenggang IS NULL AND nominal IS NULL
                  AND tanggal IS NULL
              END
            )
        SQL);
    }
};
