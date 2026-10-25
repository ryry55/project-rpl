<?php

namespace Tests\Feature;

use App\Domain\Kebijakan\PilihanKebijakan;
use App\Models\KebijakanKos;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migrasi T17 bisa dibalik.
 *
 * Migrasi yang hanya bisa maju adalah jebakan: saat ada yang perlu kembali ke
 * versi sebelumnya — membandingkan perilaku, atau memulihkan server — perintah
 * `migrate:rollback` gagal di tengah jalan dan meninggalkan skema setengah
 * berubah, yang lebih buruk daripada tidak mundur sama sekali.
 *
 * Dua hal yang mudah terlewat dan pernah benar-benar gagal di sini:
 *
 *   1. **CHECK lama menolak baris baru.** Mengembalikan constraint dua tipe
 *      sementara baris `denda` masih ada membuat ALTER TABLE ditolak.
 *   2. **Trigger append-only menolak DELETE.** Membersihkan baris itu perlu
 *      melepas trigger lebih dulu, lalu memasangnya kembali — kalau tidak,
 *      tabelnya jadi bisa diubah diam-diam sesudah rollback.
 *
 * Sejak fase terakhir, `deposit` sudah keluar dari sistem (lihat
 * `MigrasiHapusDepositTest`). Tes ini tidak lagi membuat baris deposit karena
 * skema sekarang menolaknya; yang diuji tetap hal yang sama, yaitu T17 bisa
 * dibalik walau ada baris kebijakan yang harus dibuang.
 *
 * Tes ini menjalankan migrasi mundur dan maju sungguhan, jadi ia lebih lambat
 * daripada tes lain. Itu harga yang pantas: satu-satunya cara membuktikan
 * migrasi bisa dibalik adalah membaliknya.
 */
class MigrasiT17Test extends TestCase
{
    use RefreshDatabase;

    /**
     * Baris kebijakan tiga tipe, seperti keadaan sesudah pemilik mengisi
     * layar Kebijakan.
     */
    private function isiKebijakan(): void
    {
        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_SATUAN_SEWA,
            'nilai' => PilihanKebijakan::SATUAN_BULANAN,
            'berlaku_dari' => '2026-01-01',
        ]);
        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_JATUH_TEMPO,
            'nilai' => PilihanKebijakan::JATUH_TEMPO_TANGGAL_MASUK,
            'berlaku_dari' => '2026-01-01',
        ]);
        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_DENDA,
            'nilai' => PilihanKebijakan::DENDA_PERSEN_HARIAN,
            'persen' => '1.00',
            'plafon_persen' => '30.00',
            'masa_tenggang' => 7,
            'berlaku_dari' => '2026-01-01',
        ]);

        $this->assertSame(3, KebijakanKos::count());
    }

    private function jalankanMigrasiT17(): string
    {
        $berkas = collect(glob(database_path('migrations/*_denda_deposit_sebagai_kebijakan.php')))
            ->first();

        $this->assertNotNull($berkas, 'migrasi T17 tidak ditemukan');

        return $berkas;
    }

    public function test_rollback_berhasil_walau_ada_baris_denda(): void
    {
        $this->isiKebijakan();

        $migrasi = require $this->jalankanMigrasiT17();
        $migrasi->down();

        // Skema kembali ke bentuk sebelum T17.
        $this->assertTrue(Schema::hasTable('parameter_denda'), 'tabel parameter_denda harus kembali');
        $this->assertFalse(Schema::hasColumn('tagihan', 'kebijakan_terpakai'));

        foreach (['tarif_denda_persen', 'plafon_persen', 'masa_tenggang'] as $kolom) {
            $baris = DB::selectOne(
                'SELECT is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
                ['tagihan', $kolom],
            );
            $this->assertSame('NO', $baris->is_nullable, "{$kolom} harus kembali NOT NULL");
        }

        // Baris `denda` dibuang: kode lama tidak bisa membacanya, dan constraint
        // lama menolaknya.
        $this->assertSame(0, KebijakanKos::where('tipe', PilihanKebijakan::TIPE_DENDA)->count());
        $this->assertSame(2, KebijakanKos::count());
    }

    public function test_trigger_append_only_aktif_lagi_sesudah_rollback(): void
    {
        // Tanpa ini, rollback diam-diam membuka tabel yang seharusnya hanya
        // bisa ditambah. Gejalanya tidak terlihat sampai ada yang menghapus
        // riwayat kebijakan dan baru sadar belakangan.
        $this->isiKebijakan();

        $migrasi = require $this->jalankanMigrasiT17();
        $migrasi->down();

        $this->expectException(QueryException::class);

        DB::table('kebijakan_kos')->where('tipe', PilihanKebijakan::TIPE_SATUAN_SEWA)->delete();
    }

    public function test_maju_lagi_setelah_rollback_memulihkan_skema_t17(): void
    {
        // Siklus penuh: mundur lalu maju. Kalau `down()` meninggalkan sisa
        // (constraint lama, trigger ganda), `up()` akan gagal di sini.
        $this->isiKebijakan();

        $migrasi = require $this->jalankanMigrasiT17();
        $migrasi->down();
        $migrasi->up();

        $this->assertFalse(Schema::hasTable('parameter_denda'), 'tabel parameter_denda harus hilang lagi');
        $this->assertTrue(Schema::hasColumn('tagihan', 'kebijakan_terpakai'));

        // Tiga tipe diterima kembali.
        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_DENDA,
            'nilai' => PilihanKebijakan::DENDA_NOMINAL_TETAP,
            'nominal' => 100_000,
            'berlaku_dari' => '2026-02-01',
        ]);

        $this->assertSame(1, KebijakanKos::where('tipe', PilihanKebijakan::TIPE_DENDA)->count());
    }
}
