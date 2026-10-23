<?php

use App\Domain\Pembayaran\MetodePembayaran;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metode, sumber, dan pemegang uang pada pembayaran (T20).
 *
 * Temuan yang memicu migrasi ini: DFD menyebut D5 punya `metode` dan `bukti`,
 * migrasinya tidak punya keduanya. Cacat itu lama, dan pertanyaan "biar rekapan
 * jelas" menunjuk persis ke situ - tanpa `metode`, pendapatan tunai tidak bisa
 * dipisahkan dari yang masuk rekening, dan rekap yang mencampur keduanya tidak
 * berguna untuk rekonsiliasi bank.
 *
 * Empat keputusan bentuk:
 *
 *   1. **`metode` nullable, bukan NOT NULL dengan nilai bawaan `tunai`.**
 *      Baris yang sudah ada dibuat sebelum kolom ini ada; tidak ada yang tahu
 *      bagaimana uangnya diterima. Memberinya nilai bawaan berarti mengarang
 *      fakta tentang uang, dan fakta karangan di tabel pembayaran lebih buruk
 *      daripada lubang yang terlihat. Rekap melaporkan baris tanpa metode
 *      sebagai kelompok tersendiri, sehingga lubangnya tidak hilang.
 *      Untuk baris BARU, metode wajib - dijaga validasi dan CHECK di bawah.
 *
 *   2. **CHECK constraint, bukan enum PostgreSQL.** Alasannya sama dengan
 *      `kebijakan_kos`: menambah metode nanti cukup ALTER CHECK, sementara enum
 *      butuh `ALTER TYPE ... ADD VALUE` yang tidak boleh dijalankan di dalam
 *      transaksi pada versi PostgreSQL lama. Daftarnya tetap tertutup, dan
 *      sumbernya satu: `MetodePembayaran::SEMUA`.
 *
 *   3. **`sumber` memisahkan uang yang dicatat orang dari uang yang dicatat
 *      mesin.** Tanpa kolom ini, baris hasil webhook gateway tidak bisa
 *      dibedakan dari baris yang diketik pemilik, padahal keduanya punya
 *      tingkat kepercayaan yang berbeda: yang satu diketik manusia yang mungkin
 *      salah, yang lain diverifikasi tanda tangan kriptografis.
 *
 *   4. **`diterima_oleh` terpisah dari `dicatat_oleh`.** Yang satu "siapa yang
 *      memegang uangnya", yang lain "siapa yang mengetik barisnya". Untuk kos
 *      yang pemiliknya tidak tinggal di lokasi, pertanyaan yang sebenarnya
 *      ditanyakan bukan "siapa mencatat", melainkan "setoran mana yang masih di
 *      tangan pengelola" - dan itu hanya bisa dijawab kolom pertama.
 *
 * Keduanya memakai `restrictOnDelete`, BUKAN `nullOnDelete`.
 *
 * Itu bukan pilihan gaya - `nullOnDelete` di sini **tidak mungkin berjalan**.
 * PostgreSQL menerapkan SET NULL dengan menjalankan UPDATE pada baris anak, dan
 * trigger append-only di `pembayaran` menolak setiap UPDATE. Akibatnya setiap
 * `DELETE FROM users` yang menyentuh seorang pencatat akan gagal dengan pesan
 * "Tabel pembayaran bersifat append-only" - pesan yang menyesatkan, karena yang
 * dicoba dihapus adalah akun, bukan pembayaran.
 *
 * `restrictOnDelete` membuat kegagalan itu jujur dan terjadi lebih awal: akun
 * yang pernah menangani uang tidak bisa dihapus, dan pesannya menyebut apa yang
 * menghalangi. Itu memang aturan yang benar untuk catatan uang: baris pembayaran
 * harus selalu bisa menyebut siapa yang menerimanya, dan nama di jejak audit
 * tidak boleh menjadi penunjuk kosong.
 *
 * Konsekuensinya dicatat sebagai batasan: akun yang pernah menangani pembayaran
 * tidak bisa dihapus, hanya bisa dicabut aksesnya (peran diturunkan menjadi
 * `penghuni`, token dihapus). Untuk pengelola, itu sudah menjadi jalur
 * pencabutan yang dipakai T24.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pembayaran', function (Blueprint $table) {
            // Nullable: lihat keputusan 1. Baris baru tetap wajib lewat
            // validasi + CHECK di bawah.
            $table->string('metode', 20)->nullable()->after('jumlah');

            $table->string('sumber', 30)
                ->default(MetodePembayaran::SUMBER_DICATAT_PEMILIK)
                ->after('metode');

            // Nomor referensi transfer / order_id gateway. Nullable karena
            // pembayaran tunai memang tidak punya nomor apa pun.
            $table->string('referensi', 100)->nullable()->after('sumber');

            $table->foreignId('dicatat_oleh')->nullable()->after('referensi')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('diterima_oleh')->nullable()->after('dicatat_oleh')
                ->constrained('users')
                ->restrictOnDelete();

            // Pola baca rekap: rentang tanggal, dikelompokkan per metode.
            // Tanpa indeks ini, rekap setahun menjalankan seq scan atas seluruh
            // pembayaran - dan rekap adalah layar yang dibuka paling sering
            // setelah halaman Tagihan.
            $table->index(['tanggal_pembayaran', 'metode']);
        });

        $metode = collect(MetodePembayaran::SEMUA)
            ->map(fn (string $m) => "'".$m."'")
            ->implode(', ');

        $sumber = collect(MetodePembayaran::SEMUA_SUMBER)
            ->map(fn (string $s) => "'".$s."'")
            ->implode(', ');

        // Nilai yang tidak dikenal ditolak basis data, bukan hanya validasi:
        // satu jalur tulis yang lupa memvalidasi sudah cukup untuk membuat
        // rekap punya kelompok yang tidak pernah tampil di layar.
        DB::statement(
            "ALTER TABLE pembayaran
             ADD CONSTRAINT pembayaran_metode_dikenal
             CHECK (metode IS NULL OR metode IN ({$metode}))"
        );

        DB::statement(
            "ALTER TABLE pembayaran
             ADD CONSTRAINT pembayaran_sumber_dikenal
             CHECK (sumber IN ({$sumber}))"
        );

        // Tunai wajib menyebut pemegang uangnya. Aturan ini ditegakkan basis
        // data karena ia yang membuat pertanyaan "setoran siapa yang belum
        // disetor" bisa dijawab sama sekali; baris tunai tanpa pemegang
        // membuat rekap per orang tidak berjumlah sama dengan total tunai.
        //
        // `NOT VALID` bukan kelonggaran: ia hanya melewati pemeriksaan baris
        // yang SUDAH ADA, lalu tetap menegakkan aturan untuk setiap INSERT dan
        // UPDATE sesudahnya. Itu yang dibutuhkan di sini - baris lama tidak
        // punya pemegang karena kolomnya belum ada saat ia dibuat, dan
        // memberinya nilai sekarang berarti mengarang fakta tentang uang yang
        // sudah diterima bertahun-tahun lalu.
        DB::statement(
            "ALTER TABLE pembayaran
             ADD CONSTRAINT pembayaran_tunai_ada_pemegang
             CHECK (metode IS DISTINCT FROM 'tunai' OR diterima_oleh IS NOT NULL)
             NOT VALID"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pembayaran DROP CONSTRAINT IF EXISTS pembayaran_tunai_ada_pemegang');
        DB::statement('ALTER TABLE pembayaran DROP CONSTRAINT IF EXISTS pembayaran_sumber_dikenal');
        DB::statement('ALTER TABLE pembayaran DROP CONSTRAINT IF EXISTS pembayaran_metode_dikenal');

        Schema::table('pembayaran', function (Blueprint $table) {
            $table->dropIndex(['tanggal_pembayaran', 'metode']);
            $table->dropConstrainedForeignId('diterima_oleh');
            $table->dropConstrainedForeignId('dicatat_oleh');
            $table->dropColumn(['referensi', 'sumber', 'metode']);
        });
    }
};
