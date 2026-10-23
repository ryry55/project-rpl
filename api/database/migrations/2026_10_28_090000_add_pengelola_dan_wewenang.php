<?php

use App\Domain\Akses\Wewenang;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Peran pengelola dan wewenangnya (T24).
 *
 * Kenapa peran ini ada: satu orang mengelola kos berisi 5-30 kamar, dan pemilik
 * sering tidak berada di lokasi. Yang dibutuhkan bukan "pemilik kedua" - itu
 * berbahaya, karena satu akun yang bocor berarti seluruh uang dan seluruh
 * kebijakan bisa diubah - melainkan akun yang boleh mencatat pembayaran dan
 * mengurus kamar, tetapi tidak boleh mengubah tarif atau kebijakan.
 *
 * Tiga keputusan bentuk:
 *
 *   1. **`pemilik_id` menunjuk ke `users`, bukan ke tabel `kos`.** Model
 *      kepemilikan yang dipilih adalah A: satu instalasi = satu kos. Karena itu
 *      tidak ada `kos_id` di delapan tabel lain, dan pengelola cukup menunjuk
 *      pemilik yang ia bantu. Model C (banyak kos dalam satu instalasi) ditolak
 *      dengan alasan tertulis di SPEC: ia butuh `kos_id` di 8+ tabel, filter di
 *      ~30 query, dan DFD digambar ulang. Itu proyek ketiga, bukan tambahan.
 *
 *   2. **Wewenang disimpan sebagai baris, bukan sebagai kolom boolean.** Delapan
 *      kolom boolean berarti menambah wewenang ke-9 adalah ALTER TABLE, dan
 *      setiap pengecekan menjadi `if ($user->boleh_ubah_tarif)` yang tersebar.
 *      Satu tabel pasangan (user, wewenang) membuat pengecekan seragam:
 *      `$user->boleh(Wewenang::UBAH_TARIF)`.
 *
 *   3. **`wewenang` diberi CHECK, berbeda dari `aturan.kategori` yang sengaja
 *      teks bebas.** Kategori aturan adalah isi yang ditulis pemilik, jadi
 *      daftar tertutup akan menghalangi; wewenang adalah izin yang diperiksa
 *      kode, jadi daftar tertutup justru yang benar. Tanpa CHECK, salah ketik
 *      `ubah_tarif` menjadi `ubah-tarif` tersimpan diam-diam dan pengelola
 *      kehilangan satu izin tanpa ada yang tahu sebabnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable: pemilik dan penghuni tidak menunjuk siapa pun.
            // `nullOnDelete` alih-alih cascade: kalau akun pemilik dihapus,
            // akun pengelolanya menjadi tidak jelas milik siapa. Menghapusnya
            // sekalian berarti menghapus akun orang lain tanpa jejak; menyisakan
            // dengan penunjuk kosong membuat keadaannya kelihatan dan bisa
            // diperbaiki pemilik berikutnya.
            $table->foreignId('pemilik_id')->nullable()->after('penghuni_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('pemilik_id');
        });

        // Pengelola wajib menunjuk pemilik; tanpa itu ia tidak bisa dibedakan
        // dari pemilik kedua yang liar. Ditegakkan basis data, bukan hanya
        // validasi, karena satu jalur tulis yang lupa memeriksanya sudah cukup
        // untuk membuat akun pengelola tanpa atasan.
        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_pengelola_wajib_pemilik
            CHECK (peran <> 'pengelola' OR pemilik_id IS NOT NULL)
        SQL);

        Schema::create('pengelola_kewenangan', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Salah satu dari Wewenang::SEMUA - lihat CHECK di bawah.
            $table->string('wewenang', 40);

            // Satu baris per pasangan. Tanpa ini, mencentang ulang wewenang yang
            // sama akan menambah baris kedua, dan pencabutan satu wewenang harus
            // menghapus banyak baris yang tidak diketahui jumlahnya.
            $table->unique(['user_id', 'wewenang']);
        });

        $daftar = collect(Wewenang::SEMUA)
            ->map(fn (string $w) => "'".$w."'")
            ->implode(', ');

        DB::statement(
            "ALTER TABLE pengelola_kewenangan
             ADD CONSTRAINT pengelola_kewenangan_dikenal
             CHECK (wewenang IN ({$daftar}))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pengelola_kewenangan');

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_pengelola_wajib_pemilik');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['pemilik_id']);
            $table->dropConstrainedForeignId('pemilik_id');
        });
    }
};
