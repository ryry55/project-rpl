<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya celah yang diizinkan pada trigger append-only log audit (T28).
 *
 * T28 menuntut hak penghapusan data pribadi. R-06 dan R-07 menuntut log audit
 * tidak bisa diubah. Keduanya bertemu tepat di kolom `aktor_nama`, yang
 * menyimpan salinan beku nama orang - dan salinan beku nama orang adalah data
 * pribadi yang harus bisa dihapus.
 *
 * Tiga jalan keluar yang ditolak sebelum yang ini dipilih:
 *
 *   1. **Hapus barisnya.** Menghapus jejak "siapa mengubah tarif" berarti
 *      menghapus bukti bahwa perubahan itu pernah terjadi dan disetujui siapa.
 *      Itu justru kehilangan yang paling mahal, dan pelakunya yang paling
 *      diuntungkan.
 *   2. **Biarkan namanya.** Menyatakan hak penghapusan sudah dipenuhi padahal
 *      nama orangnya masih terbaca di setiap baris. Itu bukan penyelesaian,
 *      itu pengabaian yang diberi nama.
 *   3. **Matikan trigger untuk siapa pun yang meminta.** Satu fungsi yang bisa
 *      mematikan penjaga append-only adalah penjaga yang tidak menjaga apa pun.
 *
 * Yang dipilih: trigger tetap menolak SETIAP UPDATE, kecuali satu bentuk yang
 * diperiksa kolom demi kolom - dan hanya bila permintaan itu secara eksplisit
 * menyatakan dirinya sebagai anonimisasi lewat `SET LOCAL kos.anonimisasi`.
 * Artinya:
 *
 *   - `aksi`, `entitas`, `entitas_id`, `nilai_lama`, `nilai_baru`, dan
 *     `dibuat_pada` **tidak boleh berubah sama sekali**. Percobaan mengubah
 *     satu saja tetap ditolak, dan ada tesnya.
 *   - `aktor_id` hanya boleh menjadi NULL, `aktor_nama` hanya boleh menjadi
 *     teks "pengguna dihapus". Tidak ada nilai lain yang lolos.
 *   - Tanpa `SET LOCAL`, seluruh UPDATE ditolak seperti sebelumnya.
 *
 * `SET LOCAL` berlaku sampai transaksi selesai dan tidak bocor ke koneksi lain
 * - penting karena PHP-FPM memakai ulang koneksi antar-permintaan, dan penanda
 * yang bocor akan membuka celah ini untuk permintaan berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tolak_ubah_log_audit() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE'
                   AND current_setting('kos.anonimisasi', true) = 'on'
                   AND OLD.aktor_id IS NOT NULL
                   AND NEW.aktor_id IS NULL
                   AND NEW.aktor_nama = 'pengguna dihapus'
                   AND NEW.aksi IS NOT DISTINCT FROM OLD.aksi
                   AND NEW.entitas IS NOT DISTINCT FROM OLD.entitas
                   AND NEW.entitas_id IS NOT DISTINCT FROM OLD.entitas_id
                   AND NEW.nilai_lama IS NOT DISTINCT FROM OLD.nilai_lama
                   AND NEW.nilai_baru IS NOT DISTINCT FROM OLD.nilai_baru
                   AND NEW.dibuat_pada IS NOT DISTINCT FROM OLD.dibuat_pada
                THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION
                    'Tabel log_audit bersifat append-only (R-07): % tidak diizinkan.',
                    TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        // Kembali ke bentuk asli: setiap UPDATE ditolak tanpa pengecualian.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tolak_ubah_log_audit() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'Tabel log_audit bersifat append-only (R-07): % tidak diizinkan.',
                    TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
