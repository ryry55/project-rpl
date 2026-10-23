<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denda dan deposit sebagai kebijakan (T17) — LANGKAH 3.
 *
 * Sebelum ini, denda adalah satu rumus yang tertanam: 1% per hari, plafon 30%,
 * masa tenggang 7 hari, disimpan di tabel `parameter_denda` yang hanya boleh
 * berisi satu baris. Kos yang mendenda Rp 50.000 sekali saja, atau yang tidak
 * mendenda sama sekali, tidak bisa menyatakannya.
 *
 * Empat perubahan, semuanya saling bergantung:
 *
 *   1. CHECK constraint `kebijakan_kos` dibuka untuk tipe `denda` dan
 *      `deposit`. Di LANGKAH 2 keduanya sengaja ditutup karena belum ada
 *      pembacanya; sekarang pembacanya ada.
 *   2. `tagihan.kebijakan_terpakai` (jsonb) menyimpan dokumen kebijakan yang
 *      dipakai saat terbit. Ini R-06 lapis 2 untuk denda: tanpa snapshot,
 *      pemilik yang mengubah tarif denda bulan depan akan mengubah denda
 *      tagihan lama — denda dihitung ulang setiap kali dibaca, jadi ia satu-
 *      satunya angka yang benar-benar bergerak mengikuti waktu.
 *   3. Tiga kolom denda di `tagihan` menjadi nullable. Sebelumnya NOT NULL
 *      karena setiap tagihan pasti memakai parameter yang sama. Sekarang
 *      tagihan yang dendanya "nominal tetap" atau "tanpa denda" tidak punya
 *      tarif persen sama sekali — memaksakan angka di sana berarti menyimpan
 *      angka yang tidak dipakai rumus mana pun.
 *   4. `parameter_denda` dihapus. Dua sumber kebenaran denda adalah cacat yang
 *      sedang dibereskan: kalau tabelnya tetap ada, cepat atau lambat ada kode
 *      yang membacanya dan angkanya berbeda dari `kebijakan_kos`.
 *
 * Kenapa snapshot berupa jsonb, bukan kolom bertipe seperti `kebijakan_kos`:
 * keduanya punya peran berbeda. `kebijakan_kos` adalah sumber kebenaran yang
 * harus dijaga basis data, jadi kolom bertipe + CHECK. `kebijakan_terpakai`
 * adalah arsip beku: bentuknya harus boleh berbeda antar waktu tanpa migrasi,
 * karena mengubah bentuk arsip lama berarti mengubah masa lalu.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 1. Buka tipe denda dan deposit ---------------------------------
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

        // Kolom angka mengikuti PilihanKebijakan::kolomAngka(). Setiap cabang
        // dibuka dengan IS NULL/IS NOT NULL lebih dulu: tanpa itu, perbandingan
        // yang bernilai NULL membuat seluruh konjungsi NULL, dan CHECK
        // menganggap NULL sebagai lolos — baris yang seharusnya ditolak justru
        // tersimpan.
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

        // --- 2. Snapshot kebijakan pada tagihan -----------------------------
        Schema::table('tagihan', function (Blueprint $table) {
            // Dokumen beku: kebijakan yang dipakai saat tagihan ini terbit.
            // Nullable karena baris lama (dibuat sebelum T17) tidak punya
            // dokumen ini; pembacanya memperlakukan NULL sebagai "memakai
            // kolom denda lama" supaya tagihan lama tetap terbaca.
            $table->jsonb('kebijakan_terpakai')->nullable()->after('tarif_terpakai');
        });

        // --- 3. Tiga kolom denda menjadi nullable ---------------------------
        //
        // Nilai yang sudah ada dibiarkan apa adanya. Tagihan lama tetap
        // menghasilkan angka yang sama, karena PenghitungDenda membaca kolom
        // ini bila `kebijakan_terpakai` kosong.
        DB::statement('ALTER TABLE tagihan ALTER COLUMN tarif_denda_persen DROP NOT NULL');
        DB::statement('ALTER TABLE tagihan ALTER COLUMN plafon_persen DROP NOT NULL');
        DB::statement('ALTER TABLE tagihan ALTER COLUMN masa_tenggang DROP NOT NULL');

        // --- 4. Hapus parameter_denda ---------------------------------------
        //
        // Trigger append-only-nya ikut dihapus lebih dulu: DROP TABLE akan
        // menghapus trigger bersama tabelnya, tetapi fungsinya tertinggal dan
        // akan menabrak nama yang sama bila migrasi ini dijalankan ulang.
        DB::unprepared('DROP TRIGGER IF EXISTS parameter_denda_append_only ON parameter_denda;');
        DB::unprepared('DROP FUNCTION IF EXISTS tolak_ubah_parameter_denda();');

        Schema::dropIfExists('parameter_denda');
    }

    public function down(): void
    {
        // Tabel parameter_denda dikembalikan beserta bentuk aslinya. Isinya
        // tidak bisa dipulihkan — itu konsekuensi yang diterima: tabel ini
        // dihapus karena dianggap sumber kebenaran kedua, bukan karena isinya
        // tidak berharga.
        Schema::create('parameter_denda', function (Blueprint $table) {
            $table->id();
            $table->decimal('tarif_persen', 5, 2)->default(1.00);
            $table->decimal('plafon_persen', 5, 2)->default(30.00);
            $table->unsignedSmallInteger('masa_tenggang')->default(7);
            $table->date('berlaku_dari');
            $table->timestamps();

            $table->unique('berlaku_dari');
            $table->index('berlaku_dari');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tolak_ubah_parameter_denda() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'Tabel parameter_denda bersifat append-only (R-06): % tidak diizinkan. Tambahkan baris baru dengan berlaku_dari yang baru.',
                    TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER parameter_denda_append_only
            BEFORE UPDATE OR DELETE ON parameter_denda
            FOR EACH ROW EXECUTE FUNCTION tolak_ubah_parameter_denda();
        SQL);

        Schema::table('tagihan', function (Blueprint $table) {
            $table->dropColumn('kebijakan_terpakai');
        });

        // NOT NULL dikembalikan dengan default supaya baris yang terlanjur
        // NULL tidak menggagalkan migrasi mundur.
        DB::statement('UPDATE tagihan SET tarif_denda_persen = 1.00 WHERE tarif_denda_persen IS NULL');
        DB::statement('UPDATE tagihan SET plafon_persen = 30.00 WHERE plafon_persen IS NULL');
        DB::statement('UPDATE tagihan SET masa_tenggang = 7 WHERE masa_tenggang IS NULL');

        DB::statement('ALTER TABLE tagihan ALTER COLUMN tarif_denda_persen SET NOT NULL');
        DB::statement('ALTER TABLE tagihan ALTER COLUMN plafon_persen SET NOT NULL');
        DB::statement('ALTER TABLE tagihan ALTER COLUMN masa_tenggang SET NOT NULL');

        // Kembali ke dua tipe LANGKAH 2.
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_tipe_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_nilai_valid');
        DB::statement('ALTER TABLE kebijakan_kos DROP CONSTRAINT IF EXISTS kebijakan_kos_kolom_sesuai_tipe');

        // Baris `denda` dan `deposit` harus dibuang lebih dulu: constraint lama
        // hanya mengenal dua tipe, jadi menambahkannya kembali akan ditolak
        // CHECK bila baris T17 masih ada. Ini satu-satunya tempat di mana
        // kebijakan benar-benar dihapus, dan itu memang tujuannya — migrasi
        // mundur berarti kode lama berjalan, dan kode lama tidak bisa membaca
        // kedua tipe itu. Membiarkannya berarti meninggalkan baris yang tidak
        // bisa ditafsirkan siapa pun.
        //
        // Trigger append-only harus dilepas dulu; tanpa itu DELETE ditolak oleh
        // trigger, bukan oleh niat kita. Trigger dipasang kembali sesudahnya
        // dengan definisi yang sama seperti migrasi pembuatnya.
        DB::unprepared('DROP TRIGGER IF EXISTS kebijakan_kos_append_only ON kebijakan_kos;');

        DB::statement("DELETE FROM kebijakan_kos WHERE tipe IN ('denda', 'deposit')");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER kebijakan_kos_append_only
            BEFORE UPDATE OR DELETE ON kebijakan_kos
            FOR EACH ROW EXECUTE FUNCTION tolak_ubah_kebijakan_kos();
        SQL);

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
    }
};
