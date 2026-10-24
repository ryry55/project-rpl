<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pemaafan denda (REV-FIN-07).
 *
 * Sebelum ini denda adalah satu-satunya angka uang yang tidak bisa dimaafkan:
 * begitu masa tenggang lewat, angkanya berjalan sendiri sampai plafon, dan
 * pemilik yang ingin memaafkan penghuni lama tidak punya jalan sama sekali.
 * Satu-satunya cara adalah membiarkan tagihan menunggak selamanya atau
 * menyunting barisnya langsung di basis data — keduanya buruk.
 *
 * Pemaafan disimpan sebagai **kolom di tagihan**, bukan tabel tersendiri. Yang
 * perlu dijawab hanya "berapa yang dimaafkan" dan "kenapa". Tabel tersendiri
 * akan memaksa setiap pembaca denda menjumlahkan seluruh baris pemaafan sebelum
 * bisa menyebut satu angka, dan pembaca yang lupa menjumlahkannya akan
 * menampilkan denda yang seharusnya sudah dimaafkan. Satu kolom tidak bisa
 * lupa dijumlahkan.
 *
 * Yang **tidak** disimpan: penanda "pokok sudah lunas". Itu diturunkan dari
 * `SUM(pembayaran.jumlah) >= pokok` saat denda dihitung. Menyimpannya sebagai
 * kolom berarti ia bisa basi — dan kolom yang basi tentang uang lebih buruk
 * daripada nol query tambahan. Biayanya juga kecil: daftar tagihan sudah
 * memuat `total_dibayar` lewat `withSum`, jadi pemeriksaannya tidak menambah
 * satu query pun di layar daftar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tagihan', function (Blueprint $table) {
            // Jumlah rupiah yang dimaafkan. Nol berarti tidak ada pemaafan, dan
            // itu keadaan bawaan: pemaafan adalah keputusan sadar, bukan
            // perilaku yang terjadi sendiri.
            $table->decimal('denda_dimaafkan', 14, 0)->default(0);

            // Alasan wajib bila jumlahnya bukan nol - dijaga CHECK di bawah.
            // Tanpa alasan, "denda Rp 0" tidak bisa dibedakan dari kebijakan
            // "tanpa denda", dan penghuni yang bertanya akan mendapat jawaban
            // berbeda dari tiap orang yang ditanya.
            $table->text('alasan_maaf_denda')->nullable();

            // Pelaku ikut disimpan, bukan hanya di `log_audit`: pemaafan
            // mengurangi uang yang seharusnya diterima pemilik, dan pertanyaan
            // "siapa yang memaafkan denda ini" harus bisa dijawab dari
            // barisnya. `restrictOnDelete` karena akun yang pernah memaafkan
            // denda tidak boleh dihapus.
            $table->foreignId('denda_dimaafkan_oleh')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampTz('denda_dimaafkan_pada')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tagihan
            ADD CONSTRAINT tagihan_denda_dimaafkan_tidak_negatif
            CHECK (denda_dimaafkan >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE tagihan
            ADD CONSTRAINT tagihan_maaf_denda_ada_alasan
            CHECK (denda_dimaafkan = 0 OR alasan_maaf_denda IS NOT NULL)
        SQL);

        // Pemaafan pada tagihan yang dibatalkan tidak punya arti: tagihan batal
        // tidak menagih denda sama sekali, jadi baris "denda dimaafkan" di sana
        // hanya menyesatkan pembacanya. `NOT VALID` karena baris lama dibuat
        // sebelum kolom ini ada.
        DB::statement(<<<'SQL'
            ALTER TABLE tagihan
            ADD CONSTRAINT tagihan_batal_tanpa_pemaafan
            CHECK (status <> 'dibatalkan' OR denda_dimaafkan = 0)
            NOT VALID
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tagihan DROP CONSTRAINT IF EXISTS tagihan_batal_tanpa_pemaafan');
        DB::statement('ALTER TABLE tagihan DROP CONSTRAINT IF EXISTS tagihan_maaf_denda_ada_alasan');
        DB::statement('ALTER TABLE tagihan DROP CONSTRAINT IF EXISTS tagihan_denda_dimaafkan_tidak_negatif');

        Schema::table('tagihan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('denda_dimaafkan_oleh');
            $table->dropColumn([
                'denda_dimaafkan',
                'alasan_maaf_denda',
                'denda_dimaafkan_pada',
            ]);
        });
    }
};
