<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nama kos sebagai kebijakan (K-17).
 *
 * Sebelum ini nama kos tertulis di kode: judul bilah pemilik, subjudul layar
 * masuk, subjek email, dan kaki kuitansi semuanya berbunyi "Kos-Kosan". Itu
 * nama contoh, bukan nama kos siapa pun — dan pemilik yang kosnya bernama
 * "Kos Putri Melati" tidak punya jalan untuk menyatakannya.
 *
 * Bentuknya sengaja **teks bebas**, bukan menu pilihan seperti tipe lain:
 * tidak ada daftar nama yang bisa disusun di muka. Karena itu kolom `nilai`
 * dipakai apa adanya, dan pelebaran 30 → 60 karakter di sini adalah satu-satunya
 * perubahan bentuk tabel. Tipe lain tetap pendek; pelebaran tidak mengubah arti
 * apa pun bagi mereka.
 *
 * Tipe ini **tidak wajib**: kosong berarti "Kos-Kosan", perilaku sebelum fitur
 * ini. Gerbang "kebijakan wajib diisi" tetap tiga tipe, sehingga kos yang tidak
 * pernah menyentuh layar Kebijakan tetap menagih seperti biasa.
 *
 * Migrasi ini bertanggal setelah `tanggal_terbit` karena keduanya memasang ulang
 * CHECK yang sama; yang lebih awal akan ditimpa yang lebih akhir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE kebijakan_kos ALTER COLUMN nilai TYPE varchar(60)');

        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_tipe_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_nilai_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_kolom_sesuai_tipe');

        DB::statement(<<<'SQL'
            ALTER TABLE kebijakan_kos
            ADD CONSTRAINT kebijakan_kos_tipe_valid
            CHECK (tipe IN ('satuan_sewa', 'jatuh_tempo', 'denda', 'pola_tagihan', 'tanggal_terbit', 'nama_kos'))
        SQL);

        // `nama_kos` tidak punya daftar nilai: yang sah adalah teks yang tidak
        // kosong. `btrim` dipakai supaya nama berisi spasi saja ditolak di sini
        // juga, bukan hanya oleh validasi HTTP.
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
              OR (tipe = 'nama_kos'
                  AND length(btrim(nilai)) BETWEEN 1 AND 60)
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

                WHEN 'nama_kos' THEN
                  persen IS NULL AND plafon_persen IS NULL
                  AND masa_tenggang IS NULL AND nominal IS NULL
                  AND tanggal IS NULL
              END
            )
        SQL);
    }

    public function down(): void
    {
        // Baris nama kos dibuang lebih dulu: CHECK sebelumnya tidak mengenal
        // tipe ini. Trigger append-only dilepas lalu dipasang lagi.
        DB::unprepared('DROP TRIGGER IF EXISTS kebijakan_kos_append_only ON kebijakan_kos;');

        DB::statement("DELETE FROM kebijakan_kos WHERE tipe = 'nama_kos'");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER kebijakan_kos_append_only
            BEFORE UPDATE OR DELETE ON kebijakan_kos
            FOR EACH ROW EXECUTE FUNCTION tolak_ubah_kebijakan_kos();
        SQL);

        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_tipe_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_nilai_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_kolom_sesuai_tipe');

        // Bentuk tepat sebelum migrasi ini: lima tipe, termasuk tanggal_terbit.
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

        DB::statement('ALTER TABLE kebijakan_kos ALTER COLUMN nilai TYPE varchar(30)');
    }
};
