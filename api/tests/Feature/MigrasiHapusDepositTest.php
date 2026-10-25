<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migrasi penghapusan deposit bisa dibalik.
 *
 * Deposit dikeluarkan dari sistem di fase terakhir, dan migrasi yang
 * mengeluarkannya menyentuh tiga hal sekaligus: satu kolom, tiga CHECK
 * constraint, dan sebuah trigger. Tiga-tiganya harus kembali persis seperti
 * semula saat `down()` dijalankan — bukan hanya kolomnya.
 *
 * Yang paling mudah terlewat: **trigger append-only**. Migrasi ini melepas
 * trigger untuk menghapus baris deposit, lalu memasangnya kembali. Kalau
 * pemasangan ulang itu lupa, tabel kebijakan diam-diam menjadi bisa diubah dan
 * dihapus, dan tidak ada tes lain yang akan menangkapnya karena semua tes lain
 * mengharapkan trigger itu memang menolak.
 */
class MigrasiHapusDepositTest extends TestCase
{
    use RefreshDatabase;

    private function migrasi(): object
    {
        $berkas = collect(glob(database_path('migrations/*_hapus_deposit.php')))->first();

        $this->assertNotNull($berkas, 'migrasi hapus deposit tidak ditemukan');

        return require $berkas;
    }

    public function test_kolom_deposit_sudah_tidak_ada(): void
    {
        $this->assertFalse(
            Schema::hasColumn('kontrak', 'deposit'),
            'kolom deposit seharusnya sudah dihapus dari tabel kontrak',
        );
    }

    public function test_tipe_deposit_ditolak_check_constraint(): void
    {
        try {
            DB::transaction(fn () => DB::table('kebijakan_kos')->insert([
                'tipe' => 'deposit',
                'nilai' => 'nominal',
                'nominal' => 750_000,
                'berlaku_dari' => '2026-01-01',
            ]));
            $this->fail('tipe deposit seharusnya ditolak CHECK constraint');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode(), 'harus pelanggaran CHECK (23514)');
            $this->assertStringContainsString('kebijakan_kos', $e->getMessage());
        }
    }

    public function test_nilai_deposit_ditolak_walau_tipenya_dipaksa(): void
    {
        // Lapis kedua dari constraint yang sama. Kalau hanya daftar tipe yang
        // disempitkan dan cabang `nilai` dibiarkan, baris yang lolos lewat jalur
        // lain masih bisa membawa nilai yang tidak dikenal aplikasi.
        try {
            DB::transaction(fn () => DB::table('kebijakan_kos')->insert([
                'tipe' => 'denda',
                'nilai' => 'satu_bulan',
                'berlaku_dari' => '2026-01-01',
            ]));
            $this->fail('nilai deposit pada tipe denda seharusnya ditolak');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
    }

    public function test_trigger_append_only_masih_aktif(): void
    {
        // Migrasi melepas trigger ini sebentar. Kalau ia lupa dipasang kembali,
        // riwayat kebijakan kehilangan penjaganya dan tidak ada tes lain yang
        // gagal — semua tes lain justru mengharapkan penolakan ini.
        DB::table('kebijakan_kos')->insert([
            'tipe' => 'satuan_sewa',
            'nilai' => 'bulanan',
            'berlaku_dari' => '2026-01-01',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::table('kebijakan_kos')->where('tipe', 'satuan_sewa')->delete();
    }

    public function test_mundur_lalu_maju_memulihkan_skema_yang_sama(): void
    {
        // Siklus penuh. Kalau `down()` meninggalkan sisa (constraint ganda,
        // trigger ganda), `up()` akan gagal di sini.
        $migrasi = $this->migrasi();

        $migrasi->down();

        $this->assertTrue(
            Schema::hasColumn('kontrak', 'deposit'),
            'down() harus mengembalikan kolom deposit',
        );

        // Constraint kembali menerima tipe deposit, dan trigger masih aktif.
        DB::table('kebijakan_kos')->insert([
            'tipe' => 'deposit',
            'nilai' => 'nominal',
            'nominal' => 750_000,
            'berlaku_dari' => '2026-01-01',
        ]);

        $migrasi->up();

        $this->assertFalse(
            Schema::hasColumn('kontrak', 'deposit'),
            'up() harus menghapus kolom deposit lagi',
        );
        $this->assertSame(
            0,
            DB::table('kebijakan_kos')->where('tipe', 'deposit')->count(),
            'up() harus membuang baris kebijakan deposit',
        );
    }
}
