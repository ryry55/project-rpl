<?php

namespace Tests\Feature;

use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\ParameterDenda;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\TarifSewa;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sifat R-02 yang bisa dibuktikan tanpa konkurensi sungguhan.
 *
 * **Batas berkas ini perlu jujur.** Balapan sejati — dua permintaan HTTP tiba
 * pada saat yang sama — tidak bisa dibuat di dalam satu proses PHP: permintaan
 * kedua hanya bisa dijalankan setelah yang pertama selesai, sehingga yang diuji
 * bukan balapan melainkan urutan. Percobaan membuatnya dengan dua koneksi juga
 * gagal secara berarti: PostgreSQL memblokir penulis kedua (`55P03`, lock
 * timeout), bukan menolaknya dengan `23505` — dan kegagalan karena timeout
 * bukan bukti bahwa constraint bekerja.
 *
 * Karena itu pembagiannya:
 *
 *   - **di sini** — bahwa unique index menolak duplikat dan tidak terlalu
 *     ketat, berapa kali pun dicoba;
 *   - **docs/uji/uji-balapan-t4.mjs** — dua permintaan HTTP **benar-benar**
 *     bersamaan lewat dua koneksi jaringan, yang merupakan kriteria tiket.
 */
class PenerbitanBalapanTest extends TestCase
{
    use RefreshDatabase;

    private Kontrak $kontrak;

    protected function setUp(): void
    {
        parent::setUp();

        ParameterDenda::create([
            'tarif_persen' => 1.00,
            'plafon_persen' => 30.00,
            'masa_tenggang' => 7,
            'berlaku_dari' => '2026-01-01',
        ]);

        $kamar = Kamar::create(['nomor' => 'R1', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);

        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => 1_500_000,
            'berlaku_dari' => '2026-01-01',
        ]);

        $this->kontrak = Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-01-15',
            'tarif_awal' => 1_500_000,
            'deposit' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function barisTagihan(string $periode = '2026-11-01'): array
    {
        $bulan = substr($periode, 0, 7);

        return [
            'kontrak_id' => $this->kontrak->id,
            'periode' => $periode,
            'pokok' => 1_500_000,
            'jatuh_tempo' => $bulan.'-15',
            'status' => 'belum_bayar',
            'tarif_terpakai' => json_encode(['prorata' => false]),
            'tarif_denda_persen' => '1.00',
            'plafon_persen' => '30.00',
            'masa_tenggang' => 7,
            'terbit_pada' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Berapa kali pun dicoba, jumlah baris tidak pernah melebihi satu.
     *
     * Ini invarian yang sama yang dijaga balapan sungguhan: apa pun urutan dan
     * waktu kedatangannya, hasil akhirnya harus satu baris.
     */
    public function test_berapa_kali_pun_dicoba_jumlah_baris_tetap_satu(): void
    {
        $berhasil = 0;
        $ditolak = 0;

        for ($i = 0; $i < 5; $i++) {
            try {
                DB::transaction(fn () => DB::table('tagihan')->insert($this->barisTagihan()));
                $berhasil++;
            } catch (QueryException $e) {
                $this->assertSame('23505', $e->getCode(), 'penolakan harus dari unique index');
                $ditolak++;
            }
        }

        $this->assertSame(1, $berhasil, 'hanya satu percobaan boleh berhasil');
        $this->assertSame(4, $ditolak, 'sisanya harus ditolak');
        $this->assertSame(1, Tagihan::count(), 'R-02: satu tagihan per kontrak per periode');
    }

    /**
     * Periode berbeda adalah tagihan berbeda — unique index tidak boleh terlalu
     * ketat dan menolak bulan berikutnya.
     */
    public function test_periode_berbeda_tetap_boleh(): void
    {
        DB::table('tagihan')->insert($this->barisTagihan('2026-11-01'));
        DB::table('tagihan')->insert($this->barisTagihan('2026-12-01'));

        $this->assertSame(2, Tagihan::count());
    }

    /**
     * Kontrak berbeda pada periode yang sama juga sah: yang dibatasi adalah
     * satu tagihan per kontrak per periode, bukan satu tagihan per periode
     * untuk seluruh kos.
     */
    public function test_kontrak_berbeda_pada_periode_sama_tetap_boleh(): void
    {
        $kamar = Kamar::create(['nomor' => 'R2', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Citra', 'email' => 'citra@kos.test']);
        $lain = Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-01-15',
            'tarif_awal' => 1_500_000,
            'deposit' => 0,
        ]);

        DB::table('tagihan')->insert($this->barisTagihan());

        $kedua = $this->barisTagihan();
        $kedua['kontrak_id'] = $lain->id;
        DB::table('tagihan')->insert($kedua);

        $this->assertSame(2, Tagihan::count());
    }
}
