<?php

namespace Tests\Feature;

use App\Models\Kamar;
use App\Models\KebijakanKos;
use App\Models\KomponenBiaya;
use App\Models\TarifSewa;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REV-DAT-01 dan REV-DAT-04 - kebenaran yang hanya terlihat di basis data.
 *
 * Dua temuan ini tidak bisa dibuktikan lewat endpoint: yang satu adalah
 * perilaku `ON DELETE` pada foreign key, yang lain adalah keberadaan sebuah
 * index. Keduanya hanya bisa diperiksa dengan bertanya langsung ke katalog
 * PostgreSQL, dan itulah yang dilakukan berkas ini.
 */
class SkemaRiwayatKamarTest extends TestCase
{
    use RefreshDatabase;

    // --- REV-DAT-01: hapus kamar ditolak foreign key ----------------------

    /**
     * Menghapus kamar yang punya riwayat tarif ditolak `RESTRICT`, bukan
     * diledakkan trigger append-only.
     *
     * Perbedaan ini bukan soal selera pesan galat. Sebelum REV-DAT-01, `DELETE
     * FROM kamar` mencoba cascade ke `tarif_sewa`, trigger `BEFORE DELETE` di
     * sana menolak, dan pemanggil menerima "Tabel tarif_sewa bersifat
     * append-only" - pesan yang menyebut tabel yang tidak ia sentuh. Sesudahnya
     * basis data menolak lebih awal lewat foreign key, dan pesannya menyebut
     * `tarif_sewa_kamar_id_foreign`.
     *
     * Kode SQLSTATE-nya yang membedakan keduanya: `23503`
     * (foreign_key_violation) untuk foreign key, `P0001` (raise_exception)
     * untuk trigger.
     */
    public function test_hapus_kamar_beriwayat_tarif_ditolak_foreign_key_bukan_trigger(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);

        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => 1_500_000,
            'berlaku_dari' => '2026-01-01',
        ]);

        try {
            // Dibungkus transaksi: satu pernyataan yang gagal membatalkan
            // seluruh transaksi PostgreSQL, dan tanpa pembungkus ini
            // `RefreshDatabase` tidak bisa membersihkan basis data sesudahnya.
            DB::transaction(fn () => DB::table('kamar')->where('id', $kamar->id)->delete());

            $this->fail('Menghapus kamar yang punya riwayat tarif seharusnya ditolak.');
        } catch (QueryException $e) {
            $this->assertSame(
                '23503',
                $e->getCode(),
                'penolakan harus datang dari foreign key, bukan dari trigger append-only',
            );
            $this->assertStringContainsString('tarif_sewa', $e->getMessage());
            $this->assertStringNotContainsString('append-only', $e->getMessage());
        }

        // Kamarnya masih ada, dan riwayatnya utuh.
        $this->assertSame(1, Kamar::count());
        $this->assertSame(1, TarifSewa::count());
    }

    public function test_hapus_kamar_beriwayat_komponen_ditolak_foreign_key(): void
    {
        $kamar = Kamar::create(['nomor' => 'B1']);

        KomponenBiaya::create([
            'kamar_id' => $kamar->id,
            'nama' => 'Kebersihan',
            'nilai' => 50_000,
            'berlaku_dari' => '2026-01-01',
        ]);

        try {
            DB::transaction(fn () => DB::table('kamar')->where('id', $kamar->id)->delete());

            $this->fail('Menghapus kamar yang punya komponen biaya seharusnya ditolak.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode());
            $this->assertStringContainsString('komponen_biaya', $e->getMessage());
        }
    }

    public function test_hapus_kamar_beriwayat_kebijakan_ditolak_foreign_key(): void
    {
        $kamar = Kamar::create(['nomor' => 'C1']);

        KebijakanKos::create([
            'kamar_id' => $kamar->id,
            'tipe' => KebijakanKos::TIPE_SATUAN_SEWA,
            'nilai' => 'bulanan',
            'berlaku_dari' => '2026-01-01',
        ]);

        try {
            DB::transaction(fn () => DB::table('kamar')->where('id', $kamar->id)->delete());

            $this->fail('Menghapus kamar yang punya penimpa kebijakan seharusnya ditolak.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode());
            $this->assertStringContainsString('kebijakan_kos', $e->getMessage());
        }
    }

    /**
     * Kamar tanpa riwayat tetap boleh dihapus.
     *
     * `RESTRICT` hanya menahan baris yang benar-benar dirujuk. Kalau ia menahan
     * semua kamar, kamar yang salah ketik nomornya akan menjadi baris abadi
     * yang tidak bisa dibersihkan siapa pun - dan pemilik akan menumpuk kamar
     * palsu di daftar.
     */
    public function test_hapus_kamar_tanpa_riwayat_tetap_boleh(): void
    {
        $kamar = Kamar::create(['nomor' => 'D1']);

        DB::table('kamar')->where('id', $kamar->id)->delete();

        $this->assertSame(0, Kamar::count());
    }

    /**
     * Pemasangan fasilitas tetap ikut terhapus bersama kamarnya.
     *
     * `kamar_fasilitas` sengaja TIDAK diubah menjadi `RESTRICT` (lihat
     * komentar migrasi): ia bukan tabel riwayat, tidak punya trigger, dan
     * pemasangan fasilitas memang wajar hilang bersama kamarnya. Kalau
     * `RESTRICT` dipasang di sini juga, kamar yang baru dipasangi fasilitas
     * menjadi tidak bisa dihapus - penjagaan yang tidak diminta siapa pun.
     */
    public function test_hapus_kamar_tetap_melepas_pemasangan_fasilitas(): void
    {
        $kamar = Kamar::create(['nomor' => 'E1']);
        $fasilitas = DB::table('fasilitas')->insertGetId([
            'nama' => 'AC',
            'urutan' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('kamar_fasilitas')->insert([
            'kamar_id' => $kamar->id,
            'fasilitas_id' => $fasilitas,
        ]);

        DB::table('kamar')->where('id', $kamar->id)->delete();

        $this->assertSame(0, DB::table('kamar_fasilitas')->count());
        // Katalognya sendiri tidak ikut terhapus: yang lepas hanya pemasangan.
        $this->assertSame(1, DB::table('fasilitas')->count());
    }

    // --- REV-DAT-04: index komposit untuk daftar tagihan per kontrak ------

    /**
     * `tagihan` punya index `(kontrak_id, status)`.
     *
     * Yang dilayani index ini adalah dua pertanyaan yang paling sering
     * diajukan: "tagihan mana milik kontrak ini" dan "mana yang masih belum
     * lunas untuk kontrak ini". Yang kedua tidak bisa memakai index
     * `(kontrak_id, periode)` karena `status` bukan kolom terdepan di sana.
     */
    public function test_index_kontrak_status_ada_di_tabel_tagihan(): void
    {
        $this->assertTrue(
            $this->indexAda('tagihan', 'tagihan_kontrak_status_index'),
            'index tagihan_kontrak_status_index harus ada',
        );
    }

    /**
     * Index lama `(status, jatuh_tempo)` tetap dipertahankan.
     *
     * Ia melayani pertanyaan yang berbeda: "tagihan mana yang jatuh tempo
     * dalam rentang ini" untuk laporan tunggakan. Menghapusnya sebagai
     * "duplikat" akan memaksa laporan itu memindai seluruh tabel.
     */
    public function test_index_status_jatuh_tempo_lama_tetap_ada(): void
    {
        $this->assertTrue($this->indexAda('tagihan', 'tagihan_status_jatuh_tempo_index'));
    }

    /**
     * Foreign key `kamar_id` di tiga tabel riwayat memakai `RESTRICT`.
     *
     * Diperiksa lewat katalog, bukan lewat percobaan hapus: percobaan hapus
     * membuktikan perilakunya hari ini, sedangkan katalog membuktikan
     * definisinya - dan definisi itulah yang dibaca orang berikutnya saat
     * bertanya "boleh tidak kamar dihapus".
     */
    public function test_tiga_foreign_key_riwayat_memakai_restrict(): void
    {
        foreach (['tarif_sewa', 'komponen_biaya', 'kebijakan_kos'] as $tabel) {
            $this->assertSame(
                'r',
                $this->aturanHapusForeignKey($tabel, "{$tabel}_kamar_id_foreign"),
                "foreign key {$tabel}.kamar_id harus RESTRICT (r)",
            );
        }
    }

    private function indexAda(string $tabel, string $nama): bool
    {
        return DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('tablename', $tabel)
            ->where('indexname', $nama)
            ->exists();
    }

    /**
     * Kode `confdeltype` di `pg_constraint`: `r` = RESTRICT, `c` = CASCADE,
     * `a` = NO ACTION, `n` = SET NULL, `d` = SET DEFAULT.
     */
    private function aturanHapusForeignKey(string $tabel, string $constraint): string
    {
        $baris = DB::selectOne(
            'SELECT confdeltype FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass',
            [$constraint, $tabel],
        );

        $this->assertNotNull($baris, "constraint {$constraint} tidak ditemukan");

        return $baris->confdeltype;
    }
}
