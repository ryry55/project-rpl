<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pola tagihan sebagai kebijakan (K-14).
 *
 * Satu tipe baru, `pola_tagihan`, dengan dua nilai: `tanggal_1` (bawaan) dan
 * `anniversary`. Tidak ada kolom baru — tanggalnya diambil dari `kontrak.
 * tgl_masuk`, bukan disimpan di baris kebijakan. Yang berubah hanya daftar
 * tipe yang diterima CHECK constraint.
 *
 * Kenapa `pola_tagihan` tidak masuk `TIPE_WAJIB`: kos yang belum pernah
 * menyentuh layar Kebijakan harus tetap menagih seperti biasa. Kosong berarti
 * `tanggal_1`, dan itu bukan tebakan — itu perilaku yang sudah berjalan
 * sebelum migrasi ini.
 *
 * Migrasi ini sengaja bertanggal paling akhir: `hapus_deposit` (20 November)
 * memasang ulang ketiga CHECK dengan daftar tiga tipe, sehingga migrasi yang
 * lebih awal akan ditimpa dan tipe barunya ditolak basis data.
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

        // Pola tidak membawa angka apa pun: tanggalnya berasal dari kontrak.
        // Semua kolom angka wajib NULL, sama seperti `satuan_sewa`.
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

    public function down(): void
    {
        // Baris pola harus dibuang lebih dulu: constraint lama tidak mengenal
        // tipe ini, jadi memasangnya kembali akan ditolak CHECK. Trigger
        // append-only dilepas dulu, lalu dipasang lagi dengan definisi yang
        // sama seperti migrasi pembuatnya.
        DB::unprepared('DROP TRIGGER IF EXISTS kebijakan_kos_append_only ON kebijakan_kos;');

        DB::statement("DELETE FROM kebijakan_kos WHERE tipe = 'pola_tagihan'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER kebijakan_kos_append_only
            BEFORE UPDATE OR DELETE ON kebijakan_kos
            FOR EACH ROW EXECUTE FUNCTION tolak_ubah_kebijakan_kos();
        SQL);

        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_tipe_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_nilai_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_kolom_sesuai_tipe');

        // Bentuk yang berlaku tepat sebelum migrasi ini: tiga tipe, tanpa
        // deposit (lihat `hapus_deposit`).
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
    }
};
