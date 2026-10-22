<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tagihan dan rinciannya (D4 pada DFD).
 *
 * Dua hal yang sengaja disimpan sebagai kolom, bukan dihitung ulang saat dibaca:
 *
 *   - `pokok` — dibekukan saat terbit. Kalau dihitung ulang dari `tarif_sewa`,
 *     menaikkan tarif akan mengubah tagihan lama, dan itu tepat yang dilarang R-06.
 *   - `tarif_terpakai` — salinan JSON tarif dan komponen yang dipakai. Inilah
 *     lapis kedua R-06: tanpa snapshot, tagihan lama tetap berubah lewat join
 *     walau `tarif_sewa` sudah append-only.
 *
 * `denda` dan `total` TIDAK ada sebagai kolom. Denda bergantung pada "hari ini",
 * jadi menyimpannya membuat nilainya basi dan butuh job penyegar. Total = pokok +
 * denda dihitung saat dibaca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tagihan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kontrak_id')->constrained('kontrak')->restrictOnDelete();

            // Selalu tanggal 1 bulan yang ditagihkan. Menyimpan tanggal lain
            // membuat dua tagihan untuk bulan yang sama bisa lolos unique index.
            $table->date('periode');

            $table->decimal('pokok', 14, 0);
            $table->date('jatuh_tempo');

            $table->enum('status', ['belum_bayar', 'sebagian', 'lunas', 'dibatalkan'])
                ->default('belum_bayar');

            // Salinan beku tarif, komponen biaya, dan parameter denda yang
            // dipakai saat tagihan ini terbit (R-06 lapis 2).
            $table->json('tarif_terpakai');

            // Parameter denda ikut dibekukan sebagai kolom, bukan hanya di dalam
            // JSON: T6 membacanya langsung untuk menghitung denda, dan kolom
            // bertipe membuat nilainya tidak bergantung pada bentuk JSON.
            $table->decimal('tarif_denda_persen', 5, 2);
            $table->decimal('plafon_persen', 5, 2);
            $table->unsignedSmallInteger('masa_tenggang');

            $table->timestamp('terbit_pada');
            $table->timestamps();

            // R-02 (T4): satu tagihan per kontrak per periode. Ditegakkan basis
            // data, bukan pengecekan di aplikasi yang punya celah balapan.
            $table->unique(['kontrak_id', 'periode']);
            $table->index(['status', 'jatuh_tempo']);
        });

        Schema::create('tagihan_rincian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tagihan_id')->constrained('tagihan')->cascadeOnDelete();
            $table->string('nama', 80);
            $table->decimal('nilai', 14, 0);
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();

            $table->index(['tagihan_id', 'urutan']);
        });

        // `pokok` harus sama dengan jumlah rinciannya. Kalau keduanya bisa
        // berbeda, layar bisa menampilkan rincian yang jumlahnya tidak cocok
        // dengan total yang ditagih, dan tidak ada cara tahu mana yang benar.
        DB::statement(<<<'SQL'
            ALTER TABLE tagihan
            ADD CONSTRAINT tagihan_pokok_positif CHECK (pokok >= 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE tagihan_rincian
            ADD CONSTRAINT tagihan_rincian_nilai_positif CHECK (nilai >= 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tagihan_rincian');
        Schema::dropIfExists('tagihan');
    }
};
