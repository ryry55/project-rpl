<?php

namespace Tests\Feature;

use App\Domain\Kebijakan\PenyelesaiKebijakan;
use App\Domain\Kebijakan\PilihanKebijakan;
use App\Models\Kamar;
use App\Models\KebijakanKos;
use App\Models\Kontrak;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\TarifSewa;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\MenyiapkanKebijakan;
use Tests\TestCase;

/**
 * T16 â€” kebijakan kos sebagai data, dan gerbang yang memaksanya diisi.
 *
 * Sebelum T16, cara menagih tertanam di kode: satuan selalu bulanan, jatuh
 * tempo selalu mengikuti tanggal masuk. Itu satu kos tertentu yang diangkat
 * jadi program, padahal tesis proyek ini justru sebaliknya â€” tiap kos berbeda.
 *
 * Yang dibuktikan di sini:
 *   1. Default kos berlaku untuk semua kamar; penimpa kamar menang atas default.
 *   2. Penimpa kamar menang tanpa memandang tanggal (bukan "yang terbaru menang").
 *   3. Gerbang menolak terbit dan menyebut tipe mana yang kurang.
 *   4. Satuan sewa benar-benar mengubah angka, bukan hanya label.
 *   5. Jatuh tempo benar-benar mengubah tanggal, termasuk penjepitan Februari.
 *   6. Tabelnya append-only, ditegakkan basis data.
 *   7. CHECK constraint menolak kombinasi kolom yang tidak masuk akal.
 *   8. Snapshot: mengubah kebijakan setelah terbit tidak mengubah tagihan lama.
 */
class KebijakanKosTest extends TestCase
{
    use MenyiapkanKebijakan;
    use RefreshDatabase;

    private User $pemilik;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pemilik = User::create([
            'name' => 'Pemilik Kos',
            'email' => 'pemilik@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PEMILIK,
        ]);

        // Denda sengaja TIDAK disiapkan di sini: berkas ini menguji gerbang dan
        // kelengkapan, jadi ia harus bisa menyatakan "denda belum diisi" tanpa
        // harus menghapus baris — dan tabelnya append-only, sehingga menghapus
        // memang tidak mungkin. Tes yang butuh lengkap memakai
        // kebijakanLengkap().
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    /**
     * @param  array<string, mixed>  $tambahan
     */
    private function kebijakan(string $tipe, string $nilai, array $tambahan = []): KebijakanKos
    {
        return KebijakanKos::create(array_merge([
            'tipe' => $tipe,
            'nilai' => $nilai,
            'berlaku_dari' => '2026-01-01',
        ], $tambahan));
    }

    private function kebijakanLengkap(string $berlakuDari = '2026-01-01', ?int $kamarId = null): void
    {
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN, [
            'berlaku_dari' => $berlakuDari,
            'kamar_id' => $kamarId,
        ]);

        $this->kebijakan(PilihanKebijakan::TIPE_JATUH_TEMPO, PilihanKebijakan::JATUH_TEMPO_TANGGAL_MASUK, [
            'berlaku_dari' => $berlakuDari,
            'kamar_id' => $kamarId,
        ]);

        $this->dendaBawaan($berlakuDari, $kamarId);
    }

    /**
     * Denda R-03 saja, tanpa satuan sewa dan jatuh tempo.
     *
     * Tes yang menguji satuan sewa atau tanggal jatuh tempo tidak sedang
     * menguji denda, tetapi sejak T17 tetap harus memenuhinya: `parameter_denda`
     * sudah dihapus, jadi ketiga tipe wajib terisi sebelum tagihan boleh
     * terbit. Memakai R-03 di sini menjaga angka denda yang dipakai tes-tes itu
     * tetap sama seperti sebelum tiket ini.
     */
    private function dendaBawaan(string $berlakuDari = '2026-01-01', ?int $kamarId = null): void
    {
        $this->kebijakan(PilihanKebijakan::TIPE_DENDA, PilihanKebijakan::DENDA_PERSEN_HARIAN, [
            'persen' => '1.00',
            'plafon_persen' => '30.00',
            'masa_tenggang' => 7,
            'berlaku_dari' => $berlakuDari,
            'kamar_id' => $kamarId,
        ]);
    }

    private function siapkanKontrak(string $tglMasuk = '2026-10-01', int $tarif = 1_500_000): Kontrak
    {
        $kamar = Kamar::create(['nomor' => 'A1', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);

        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => $tarif,
            'berlaku_dari' => '2026-01-01',
        ]);

        return Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => $tglMasuk,
            'tarif_awal' => $tarif,
        ]);
    }

    // --- Penyelesaian: default vs penimpa kamar ---------------------------

    public function test_default_kos_berlaku_untuk_semua_kamar(): void
    {
        $kamarA = Kamar::create(['nomor' => 'A1']);
        $kamarB = Kamar::create(['nomor' => 'B1']);
        $this->kebijakanLengkap();

        $penyelesai = app(PenyelesaiKebijakan::class);
        $periode = Carbon::parse('2026-10-15');

        foreach ([$kamarA, $kamarB] as $kamar) {
            $this->assertSame(
                PilihanKebijakan::SATUAN_BULANAN,
                $penyelesai->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, $periode, $kamar)?->nilai,
            );
        }
    }

    public function test_penimpa_kamar_menang_atas_default_kos(): void
    {
        $kamarA = Kamar::create(['nomor' => 'A1']);
        $kamarB = Kamar::create(['nomor' => 'B1']);

        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN);
        // Kamar A: harian. Berlaku dari tanggal yang sama, jadi yang membedakan
        // hanya kamar_id â€” bukan tanggal.
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_HARIAN, [
            'kamar_id' => $kamarA->id,
        ]);

        $penyelesai = app(PenyelesaiKebijakan::class);
        $periode = Carbon::parse('2026-10-15');

        $this->assertSame(
            PilihanKebijakan::SATUAN_HARIAN,
            $penyelesai->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, $periode, $kamarA)?->nilai,
        );
        $this->assertSame(
            PilihanKebijakan::SATUAN_BULANAN,
            $penyelesai->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, $periode, $kamarB)?->nilai,
        );
    }

    public function test_penimpa_kamar_menang_walau_default_kos_lebih_baru(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);

        // Penimpa kamar ditetapkan Januari, default kos diubah Desember.
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_HARIAN, [
            'kamar_id' => $kamar->id,
            'berlaku_dari' => '2026-01-01',
        ]);
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN, [
            'berlaku_dari' => '2026-12-01',
        ]);

        // Yang menentukan adalah tingkatnya (kamar > global), bukan tanggalnya.
        // Kalau urutannya terbalik, mengubah default kos akan diam-diam
        // menimpa penimpa per kamar yang lebih tua â€” kesalahan yang sulit
        // terlihat karena hasilnya tetap "salah satu dari dua nilai sah".
        $this->assertSame(
            PilihanKebijakan::SATUAN_HARIAN,
            app(PenyelesaiKebijakan::class)
                ->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, Carbon::parse('2027-01-15'), $kamar)
                ?->nilai,
        );
    }

    public function test_baris_paling_baru_menang_untuk_tingkat_yang_sama(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);

        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN, [
            'kamar_id' => $kamar->id,
            'berlaku_dari' => '2026-01-01',
        ]);
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_MINGGUAN, [
            'kamar_id' => $kamar->id,
            'berlaku_dari' => '2026-06-01',
        ]);

        $penyelesai = app(PenyelesaiKebijakan::class);

        // Sebelum perubahan: masih bulanan.
        $this->assertSame(
            PilihanKebijakan::SATUAN_BULANAN,
            $penyelesai->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, Carbon::parse('2026-05-10'), $kamar)?->nilai,
        );
        // Sesudah perubahan: mingguan. Periode lama tidak ikut berubah, itulah
        // gunanya `berlaku_dari` daripada sekadar "nilai terkini".
        $this->assertSame(
            PilihanKebijakan::SATUAN_MINGGUAN,
            $penyelesai->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, Carbon::parse('2026-07-10'), $kamar)?->nilai,
        );
    }

    public function test_kebijakan_yang_berlaku_nanti_tidak_dipakai_untuk_periode_lalu(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN, [
            'kamar_id' => $kamar->id,
            'berlaku_dari' => '2026-01-01',
        ]);
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_HARIAN, [
            'kamar_id' => $kamar->id,
            'berlaku_dari' => '2027-01-01',
        ]);

        $this->assertSame(
            PilihanKebijakan::SATUAN_BULANAN,
            app(PenyelesaiKebijakan::class)
                ->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, Carbon::parse('2026-11-01'), $kamar)
                ?->nilai,
        );
    }

    public function test_kebijakan_kamar_lain_tidak_bocor_ke_kamar_ini(): void
    {
        $kamarA = Kamar::create(['nomor' => 'A1']);
        $kamarB = Kamar::create(['nomor' => 'B1']);

        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_HARIAN, [
            'kamar_id' => $kamarA->id,
        ]);

        // Kamar B tidak punya default dan tidak punya penimpa: null, bukan
        // nilai milik kamar A.
        $this->assertNull(
            app(PenyelesaiKebijakan::class)
                ->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, Carbon::parse('2026-10-15'), $kamarB),
        );
    }

    /**
     * Regresi: kebijakan bertanggal hari ini tidak berlaku untuk periode bulan
     * ini, karena penyelesaian memakai AWAL periode.
     *
     * Ini bukan bug penyelesaian - memakai "hari ini" akan membuat tagihan
     * Agustus yang terbit September memakai kebijakan September, dan itu
     * melanggar R-06. Yang salah adalah tawaran tanggal di layar: formulir
     * dulu mengisikan tanggal hari ini, sehingga pemilik membaca "Kebijakan
     * tersimpan" sementara gerbangnya tetap menutup dan tagihan tetap tidak
     * bisa terbit - sampai bulan depan.
     *
     * Tes ini mengunci perilaku yang harus dijaga: baris bertanggal setelah
     * awal periode TIDAK memenuhi gerbang periode itu, dan baris bertanggal
     * awal periode MEMENUHINYA. Layar wajib menawarkan tanggal yang kedua.
     */
    public function test_kebijakan_bertanggal_tengah_bulan_belum_berlaku_untuk_bulan_itu(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);

        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN, [
            'kamar_id' => $kamar->id,
            'berlaku_dari' => '2026-10-23',
        ]);

        $penyelesai = app(PenyelesaiKebijakan::class);

        // Periode Oktober diselesaikan terhadap 2026-10-01, jadi baris
        // bertanggal 23 Oktober belum terpakai.
        $this->assertNull(
            $penyelesai->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, Carbon::parse('2026-10-01'), $kamar),
            'baris bertanggal 23 Okt tidak boleh berlaku untuk periode yang dimulai 1 Okt',
        );

        // Mulai November barulah terpakai.
        $this->assertSame(
            PilihanKebijakan::SATUAN_BULANAN,
            $penyelesai->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, Carbon::parse('2026-11-01'), $kamar)?->nilai,
        );

        // Baris bertanggal awal bulan langsung terpakai untuk bulan itu -
        // inilah tanggal yang ditawarkan formulir secara bawaan.
        $this->kebijakan(PilihanKebijakan::TIPE_JATUH_TEMPO, PilihanKebijakan::JATUH_TEMPO_TANGGAL_MASUK, [
            'kamar_id' => $kamar->id,
            'berlaku_dari' => '2026-10-01',
        ]);

        $this->assertSame(
            PilihanKebijakan::JATUH_TEMPO_TANGGAL_MASUK,
            $penyelesai->kebijakan(PilihanKebijakan::TIPE_JATUH_TEMPO, Carbon::parse('2026-10-01'), $kamar)?->nilai,
        );
    }

    // --- Gerbang ----------------------------------------------------------

    public function test_tanpa_kebijakan_tagihan_ditolak_dan_menyebut_tipe_yang_kurang(): void
    {
        $kontrak = $this->siapkanKontrak();

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertStatus(422);

        $pesan = $respons->json('message');
        $this->assertStringContainsString('satuan sewa', $pesan);
        $this->assertStringContainsString('jatuh tempo', $pesan);

        // Tidak ada tagihan yang terbit. Gerbang yang meloloskan satu baris pun
        // sudah cukup untuk membuat seluruh jaminan ini tidak berarti.
        $this->assertSame(0, Tagihan::count());
    }

    public function test_gerbang_menyebut_hanya_tipe_yang_masih_kurang(): void
    {
        $kontrak = $this->siapkanKontrak();
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN);

        $pesan = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString('jatuh tempo', $pesan);
        $this->assertStringNotContainsString('satuan sewa', $pesan);
    }

    public function test_tagihan_terbit_setelah_kebijakan_lengkap(): void
    {
        $kontrak = $this->siapkanKontrak();
        $this->kebijakanLengkap();

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_500_000);
    }

    public function test_gerbang_terbit_massal_menolak_422_bukan_200(): void
    {
        $this->siapkanKontrak();

        // 200 berarti "tidak ada yang perlu dikerjakan" menurut arti kode itu
        // sendiri. Batch yang seluruhnya gagal karena kebijakan kosong justru
        // kebalikannya, jadi 422 yang benar.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertStatus(422);

        $this->assertSame(0, Tagihan::count());
    }

    public function test_gerbang_terbit_massal_melaporkan_sebab_kebijakan_per_kamar(): void
    {
        $kontrak = $this->siapkanKontrak();
        $this->kebijakanLengkap();

        // Kamar punya penimpa yang belum lengkap: hanya satuan sewa ditimpa,
        // jatuh tempo tetap dari default. Jadi ini harus lolos.
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_HARIAN, [
            'kamar_id' => $kontrak->kamar_id,
            'berlaku_dari' => '2026-01-01',
        ]);

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.terbit', 1);
    }

    public function test_gerbang_terbit_massal_memberi_sebab_kebijakan_saat_default_kos_tidak_lengkap(): void
    {
        $this->siapkanKontrak();

        // Hanya satu tipe diisi untuk default kos.
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN);

        $pesan = $this->sebagaiPemilik()
            ->postJson('/api/tagihan/terbitkan', ['periode' => '2026-11-01'])
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString('jatuh tempo', $pesan);
    }

    // --- Satuan sewa mengubah angka ---------------------------------------

    public function test_satuan_harian_menagih_tarif_kali_jumlah_hari(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01', 50_000);
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_HARIAN);
        $this->kebijakan(PilihanKebijakan::TIPE_JATUH_TEMPO, PilihanKebijakan::JATUH_TEMPO_TANGGAL_MASUK);
        $this->dendaBawaan();

        // Oktober 31 hari, masuk tanggal 1: 50.000 Ã— 31 = 1.550.000.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_550_000);
    }

    public function test_satuan_mingguan_menagih_tarif_kali_hari_dibagi_tujuh(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01', 350_000);
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_MINGGUAN);
        $this->kebijakan(PilihanKebijakan::TIPE_JATUH_TEMPO, PilihanKebijakan::JATUH_TEMPO_TANGGAL_MASUK);
        $this->dendaBawaan();

        // 350.000 Ã— 31 / 7 = 1.550.000 tepat.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_550_000);
    }

    public function test_satuan_non_bulanan_tetap_diprorata_pada_bulan_masuk(): void
    {
        // Masuk 15 Oktober = 17 hari tinggal dari 31.
        $kontrak = $this->siapkanKontrak('2026-10-15', 50_000);
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_HARIAN);
        $this->kebijakan(PilihanKebijakan::TIPE_JATUH_TEMPO, PilihanKebijakan::JATUH_TEMPO_TANGGAL_MASUK);
        $this->dendaBawaan();

        // 50.000 Ã— 17 = 850.000, bukan 50.000 Ã— 31. Penghuni yang masuk tanggal
        // 15 tidak boleh ditagih sebulan penuh hanya karena kosnya harian.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 850_000);
    }

    public function test_bulan_berikutnya_pada_satuan_harian_menagih_sebulan_penuh(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15', 50_000);
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_HARIAN);
        $this->kebijakan(PilihanKebijakan::TIPE_JATUH_TEMPO, PilihanKebijakan::JATUH_TEMPO_TANGGAL_MASUK);
        $this->dendaBawaan();

        // November 30 hari: 50.000 Ã— 30 = 1.500.000.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_500_000);
    }

    public function test_label_rincian_menyebut_satuan_yang_dipakai(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01', 50_000);
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_HARIAN);
        $this->kebijakan(PilihanKebijakan::TIPE_JATUH_TEMPO, PilihanKebijakan::JATUH_TEMPO_TANGGAL_MASUK);
        $this->dendaBawaan();

        $nama = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->json('data.rincian.0.nama');

        // Tanpa keterangan satuan, pemilik sendiri tidak bisa menjelaskan asal
        // angka Rp 1.550.000 pada kamar yang tertulis Rp 50.000 di menu Kamar.
        $this->assertStringContainsString('harian', $nama);
        $this->assertStringContainsString('31 hari', $nama);
    }

    // --- Jatuh tempo mengubah tanggal -------------------------------------

    public function test_jatuh_tempo_tanggal_tetap(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN);
        $this->kebijakan(PilihanKebijakan::TIPE_JATUH_TEMPO, PilihanKebijakan::JATUH_TEMPO_TANGGAL_TETAP, [
            'tanggal' => 10,
        ]);
        $this->dendaBawaan();

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.jatuh_tempo', '2026-11-10');
    }

    public function test_jatuh_tempo_tanggal_tetap_31_dijepit_di_februari(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN);
        $this->kebijakan(PilihanKebijakan::TIPE_JATUH_TEMPO, PilihanKebijakan::JATUH_TEMPO_TANGGAL_TETAP, [
            'tanggal' => 31,
        ]);
        $this->dendaBawaan();

        // Tanpa penjepitan, tanggal 31 Februari akan menggelinding ke 3 Maret
        // dan denda dihitung dari tanggal yang salah.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2027-02-01'])
            ->assertCreated()
            ->assertJsonPath('data.jatuh_tempo', '2027-02-28');
    }

    public function test_jatuh_tempo_bulan_masuk_tidak_mendahului_tanggal_masuk(): void
    {
        // Cacat yang ditemukan saat memverifikasi angka panduan sidang:
        // kebijakan "tanggal tetap 15" membuat penghuni yang masuk tanggal 16
        // September menerima tagihan September yang jatuh temponya 15
        // September - sehari SEBELUM ia menempati kamar. Tagihan itu lahir
        // dalam keadaan sudah terlambat, dan dendanya berjalan dari tanggal ia
        // belum berutang apa pun.
        $kontrak = $this->siapkanKontrak('2026-09-16');
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN);
        $this->kebijakan(PilihanKebijakan::TIPE_JATUH_TEMPO, PilihanKebijakan::JATUH_TEMPO_TANGGAL_TETAP, [
            'tanggal' => 15,
        ]);
        $this->dendaBawaan();

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-09-01'])
            ->assertCreated()
            // Dinaikkan sampai tanggal masuk, bukan dibiarkan 2026-09-15.
            ->assertJsonPath('data.jatuh_tempo', '2026-09-16')
            // Pokoknya tetap prorata 15/30 - yang ditagih memang bukan
            // sebulan penuh, jadi menagihnya lebih cepat tidak punya dasar.
            ->assertJsonPath('data.pokok', 750_000);
    }

    public function test_jatuh_tempo_bulan_berikutnya_tetap_tanggal_tetap(): void
    {
        // Penjaga di atas hanya berlaku pada bulan masuk. Bulan berikutnya
        // penghuni sudah menempati kamar sejak lama, jadi tanggal 15 harus
        // dipakai apa adanya - kalau tidak, kebijakan "tanggal tetap" berhenti
        // berarti apa pun sesudah bulan pertama.
        $kontrak = $this->siapkanKontrak('2026-09-16');
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN);
        $this->kebijakan(PilihanKebijakan::TIPE_JATUH_TEMPO, PilihanKebijakan::JATUH_TEMPO_TANGGAL_TETAP, [
            'tanggal' => 15,
        ]);
        $this->dendaBawaan();

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.jatuh_tempo', '2026-10-15')
            ->assertJsonPath('data.pokok', 1_500_000);
    }

    public function test_jatuh_tempo_akhir_bulan(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN);
        $this->kebijakan(PilihanKebijakan::TIPE_JATUH_TEMPO, PilihanKebijakan::JATUH_TEMPO_AKHIR_BULAN);
        $this->dendaBawaan();

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2027-02-01'])
            ->assertCreated()
            ->assertJsonPath('data.jatuh_tempo', '2027-02-28');
    }

    // --- Snapshot ---------------------------------------------------------

    public function test_mengubah_kebijakan_setelah_terbit_tidak_mengubah_tagihan_lama(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01', 1_500_000);
        $this->kebijakanLengkap();

        $tagihan = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_500_000)
            ->json('data');

        // Kebijakan baru: harian. Berlaku mulai periode berikutnya, tetapi
        // ditulis dengan tanggal mundur supaya pembacaannya untuk Oktober pun
        // berubah bila snapshot tidak dipakai.
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_HARIAN, [
            'berlaku_dari' => '2026-09-01',
        ]);

        $this->sebagaiPemilik()
            ->getJson('/api/tagihan/'.$tagihan['id'])
            ->assertOk()
            ->assertJsonPath('data.pokok', 1_500_000)
            ->assertJsonPath('data.tarif_terpakai.kebijakan.satuan_sewa.nilai', 'bulanan');
    }

    public function test_snapshot_menyimpan_kebijakan_yang_dipakai(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01');
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN);
        $this->kebijakan(PilihanKebijakan::TIPE_JATUH_TEMPO, PilihanKebijakan::JATUH_TEMPO_TANGGAL_TETAP, [
            'tanggal' => 10,
        ]);
        $this->dendaBawaan();

        $snapshot = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->json('data.tarif_terpakai');

        $this->assertSame('bulanan', $snapshot['kebijakan']['satuan_sewa']['nilai']);
        $this->assertSame('tanggal_tetap', $snapshot['kebijakan']['jatuh_tempo']['nilai']);
        $this->assertSame(10, $snapshot['kebijakan']['jatuh_tempo']['tanggal']);
        $this->assertSame(31, $snapshot['kebijakan']['hari_dalam_bulan']);
    }

    // --- Append-only dan CHECK constraint ---------------------------------

    public function test_update_langsung_ke_kebijakan_ditolak_database(): void
    {
        $baris = $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::table('kebijakan_kos')->where('id', $baris->id)->update(['nilai' => 'harian']);
    }

    public function test_hapus_langsung_kebijakan_ditolak_database(): void
    {
        $baris = $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::table('kebijakan_kos')->where('id', $baris->id)->delete();
    }

    public function test_check_constraint_menolak_tipe_tidak_dikenal(): void
    {
        // Dua constraint sama-sama menolak baris ini (tipe dan nilai dua-duanya
        // tidak sah), dan PostgreSQL bebas melaporkan salah satunya lebih dulu.
        // Karena itu yang diperiksa adalah SQLSTATE pelanggaran CHECK dan nama
        // tabelnya, bukan nama constraint tertentu — mematok nama constraint
        // membuat tes ini gagal karena urutan pemeriksaan internal, bukan
        // karena ada yang rusak.
        try {
            // Dibungkus transaksinya sendiri (menjadi SAVEPOINT di dalam
            // transaksi RefreshDatabase): tanpa itu, penolakan membuat
            // transaksi PostgreSQL teracun dan pemeriksaan di bawah gagal
            // karena "current transaction is aborted".
            DB::transaction(fn () => DB::table('kebijakan_kos')->insert([
                'tipe' => 'bunga',
                'nilai' => 'flat',
                'berlaku_dari' => '2026-01-01',
            ]));
            $this->fail('tipe yang tidak dikenal seharusnya ditolak CHECK constraint');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode(), 'harus pelanggaran CHECK (23514)');
            $this->assertStringContainsString('kebijakan_kos', $e->getMessage());
        }

        $this->assertSame(0, KebijakanKos::count());
    }

    public function test_check_constraint_menolak_nilai_yang_tidak_cocok_dengan_tipe(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('kebijakan_kos_nilai_valid');

        DB::table('kebijakan_kos')->insert([
            'tipe' => PilihanKebijakan::TIPE_SATUAN_SEWA,
            'nilai' => 'tanggal_masuk',
            'berlaku_dari' => '2026-01-01',
        ]);
    }

    public function test_check_constraint_menolak_tanggal_tetap_tanpa_tanggal(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('kebijakan_kos_kolom_sesuai_tipe');

        DB::table('kebijakan_kos')->insert([
            'tipe' => PilihanKebijakan::TIPE_JATUH_TEMPO,
            'nilai' => PilihanKebijakan::JATUH_TEMPO_TANGGAL_TETAP,
            'tanggal' => null,
            'berlaku_dari' => '2026-01-01',
        ]);
    }

    public function test_check_constraint_menolak_kolom_angka_pada_tipe_yang_tidak_memakainya(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('kebijakan_kos_kolom_sesuai_tipe');

        // Satuan sewa tidak punya angka pendukung. Angka menganggur di sini
        // membuat baris terbaca seolah-olah angkanya dipakai.
        DB::table('kebijakan_kos')->insert([
            'tipe' => PilihanKebijakan::TIPE_SATUAN_SEWA,
            'nilai' => PilihanKebijakan::SATUAN_BULANAN,
            'nominal' => 100_000,
            'berlaku_dari' => '2026-01-01',
        ]);
    }

    public function test_unique_nulls_not_distinct_menolak_duplikat_default(): void
    {
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN);

        // Tanpa NULLS NOT DISTINCT, dua baris default (kamar_id NULL) dianggap
        // berbeda dan PenyelesaiKebijakan memilih salah satunya berdasarkan
        // urutan fisik â€” yang tidak dijamin apa pun.
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');

        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_HARIAN);
    }

    // --- HTTP -------------------------------------------------------------

    public function test_get_kebijakan_mengembalikan_riwayat_dan_kelengkapan(): void
    {
        $this->kebijakan(PilihanKebijakan::TIPE_SATUAN_SEWA, PilihanKebijakan::SATUAN_BULANAN);

        $this->sebagaiPemilik()
            ->getJson('/api/kebijakan?berlaku_pada=2026-10-15')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tipe', 'satuan_sewa')
            ->assertJsonPath('data.0.label_nilai', 'Bulanan')
            // Lengkap = false karena jatuh tempo belum diisi, dan daftar yang
            // kurang menyebut persis apa yang harus diisi.
            ->assertJsonPath('meta.lengkap', false)
            ->assertJsonPath('meta.kurang', ['jatuh_tempo', 'denda'])
            ->assertJsonPath('meta.berlaku.jatuh_tempo', null);
    }

    public function test_get_kebijakan_lengkap_setelah_dua_tipe_diisi(): void
    {
        $this->kebijakanLengkap();

        $this->sebagaiPemilik()
            ->getJson('/api/kebijakan')
            ->assertOk()
            ->assertJsonPath('meta.lengkap', true)
            ->assertJsonPath('meta.kurang', [])
            ->assertJsonPath('meta.berlaku.satuan_sewa.nilai', 'bulanan');
    }

    public function test_post_kebijakan_menyimpan_dan_mengembalikan_label(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => 'jatuh_tempo',
                'nilai' => 'tanggal_tetap',
                'tanggal' => 10,
                'berlaku_dari' => '2026-10-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tipe', 'jatuh_tempo')
            ->assertJsonPath('data.nilai', 'tanggal_tetap')
            ->assertJsonPath('data.tanggal', 10)
            ->assertJsonPath('data.label_nilai', 'Tanggal tetap')
            ->assertJsonPath('data.kamar_id', null);
    }

    public function test_post_kebijakan_menolak_nilai_yang_tidak_cocok_dengan_tipe(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => 'satuan_sewa',
                'nilai' => 'tanggal_masuk',
                'berlaku_dari' => '2026-10-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nilai');
    }

    public function test_post_kebijakan_tanggal_tetap_wajib_menyertakan_tanggal(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => 'jatuh_tempo',
                'nilai' => 'tanggal_tetap',
                'berlaku_dari' => '2026-10-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal');
    }

    public function test_post_kebijakan_menolak_tanggal_pada_tipe_yang_tidak_memakainya(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => 'jatuh_tempo',
                'nilai' => 'akhir_bulan',
                'tanggal' => 10,
                'berlaku_dari' => '2026-10-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tanggal');
    }

    public function test_post_kebijakan_duplikat_ditolak_422(): void
    {
        $isi = [
            'tipe' => 'satuan_sewa',
            'nilai' => 'bulanan',
            'berlaku_dari' => '2026-10-01',
        ];

        $this->sebagaiPemilik()->postJson('/api/kebijakan', $isi)->assertCreated();

        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', $isi)
            ->assertStatus(422)
            ->assertJsonValidationErrors('berlaku_dari');
    }

    public function test_penghuni_tidak_bisa_membaca_atau_menulis_kebijakan(): void
    {
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);
        $akun = User::create([
            'name' => 'Budi',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $penghuni->id,
        ]);

        $this->actingAs($akun, 'sanctum')->getJson('/api/kebijakan')->assertStatus(403);
        $this->actingAs($akun, 'sanctum')->postJson('/api/kebijakan', [
            'tipe' => 'satuan_sewa',
            'nilai' => 'bulanan',
            'berlaku_dari' => '2026-10-01',
        ])->assertStatus(403);
    }

    // --- Kolom angka per bentuk (T17) -------------------------------------

    /**
     * Denda persen butuh tiga angka sekaligus. Menyimpan hanya sebagian
     * menghasilkan kebijakan yang tampak lengkap tetapi tidak bisa dihitung:
     * tarif tanpa plafon berarti denda tumbuh tanpa batas, dan tanpa tenggang
     * "denda mulai kapan" hanya bisa ditebak.
     */
    public function test_post_denda_persen_wajib_menyertakan_tiga_angka(): void
    {
        foreach ([
            'tanpa persen' => ['plafon_persen' => 30, 'masa_tenggang' => 7],
            'tanpa plafon' => ['persen' => 1, 'masa_tenggang' => 7],
            'tanpa tenggang' => ['persen' => 1, 'plafon_persen' => 30],
        ] as $nama => $isi) {
            $this->sebagaiPemilik()
                ->postJson('/api/kebijakan', array_merge([
                    'tipe' => 'denda',
                    'nilai' => 'persen_harian',
                    'berlaku_dari' => '2026-10-01',
                ], $isi))
                ->assertStatus(422, "kasus: {$nama}");
        }

        // Tidak ada satu pun yang tersimpan: gerbang yang meloloskan satu baris
        // sudah cukup membuat jaminan ini tidak berarti.
        $this->assertSame(0, KebijakanKos::count());
    }

    public function test_post_denda_persen_menyimpan_tiga_angka(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => 'denda',
                'nilai' => 'persen_harian',
                'persen' => 1.5,
                'plafon_persen' => 40,
                'masa_tenggang' => 5,
                'berlaku_dari' => '2026-10-01',
            ])
            ->assertCreated()
            // Persentase disimpan sebagai decimal(5,2), jadi "1.5" menjadi
            // "1.50". Normalisasi ini disengaja supaya riwayat tidak memuat
            // dua bentuk berbeda untuk angka yang sama.
            ->assertJsonPath('data.persen', '1.50')
            ->assertJsonPath('data.plafon_persen', '40.00')
            ->assertJsonPath('data.masa_tenggang', 5)
            ->assertJsonPath('data.nominal', null)
            ->assertJsonPath('data.ringkasan', '1.5% per hari, plafon 40%, tenggang 5 hari');
    }

    public function test_post_denda_nominal_hanya_menyertakan_nominal(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => 'denda',
                'nilai' => 'nominal_tetap',
                'nominal' => 100_000,
                'berlaku_dari' => '2026-10-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.nominal', 100_000)
            ->assertJsonPath('data.persen', null)
            ->assertJsonPath('data.masa_tenggang', null)
            ->assertJsonPath('data.ringkasan', 'Rp 100.000 per bulan keterlambatan');
    }

    public function test_post_denda_nominal_menolak_persen_yang_menganggur(): void
    {
        // Angka menganggur di baris kebijakan membuat pembaca mengira angka itu
        // dipakai, padahal tidak. Karena itu kolom yang tidak dibaca rumus
        // ditolak, bukan diabaikan diam-diam.
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => 'denda',
                'nilai' => 'nominal_tetap',
                'nominal' => 100_000,
                'persen' => 1,
                'berlaku_dari' => '2026-10-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('persen');
    }

    public function test_post_denda_tanpa_denda_menolak_semua_angka(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => 'denda',
                'nilai' => 'tanpa_denda',
                'nominal' => 50_000,
                'berlaku_dari' => '2026-10-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nominal');
    }

    /**
     * Deposit sudah dikeluarkan dari sistem di fase terakhir.
     *
     * Tes ini menjaga agar ia tidak masuk kembali tanpa sengaja. Yang diuji
     * adalah dua lapis: validasi HTTP menolaknya lebih dulu dengan 422 yang
     * rapi, dan CHECK constraint basis data menolaknya juga bila lapis pertama
     * dilewati. Keduanya harus sepakat — kalau hanya HTTP yang menolak, baris
     * deposit masih bisa masuk lewat seeder atau SQL langsung dan menjadi baris
     * yang tidak bisa ditafsirkan kode mana pun.
     */
    public function test_tipe_deposit_ditolak_validasi_maupun_constraint(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => 'deposit',
                'nilai' => 'nominal',
                'nominal' => 1_500_000,
                'berlaku_dari' => '2026-10-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipe');

        $this->assertSame(0, KebijakanKos::count());

        // Lapis kedua: basis data tetap menolak walau validasi dilewati.
        try {
            DB::transaction(fn () => DB::table('kebijakan_kos')->insert([
                'tipe' => 'deposit',
                'nilai' => 'nominal',
                'nominal' => 1_500_000,
                'berlaku_dari' => '2026-10-01',
            ]));
            $this->fail('tipe deposit seharusnya ditolak CHECK constraint');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode(), 'harus pelanggaran CHECK (23514)');
            $this->assertStringContainsString('kebijakan_kos', $e->getMessage());
        }
    }

    public function test_post_denda_persen_menolak_persentase_di_atas_seratus(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => 'denda',
                'nilai' => 'persen_harian',
                'persen' => 150,
                'plafon_persen' => 30,
                'masa_tenggang' => 7,
                'berlaku_dari' => '2026-10-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('persen');
    }

    public function test_post_denda_persen_menolak_tenggang_negatif(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => 'denda',
                'nilai' => 'persen_harian',
                'persen' => 1,
                'plafon_persen' => 30,
                'masa_tenggang' => -1,
                'berlaku_dari' => '2026-10-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('masa_tenggang');
    }

    public function test_post_denda_persen_tenggang_nol_diterima(): void
    {
        // Nol adalah pernyataan yang sah: "denda mulai sejak hari pertama".
        // Yang ditolak adalah tenggang yang tidak disebut sama sekali.
        $this->sebagaiPemilik()
            ->postJson('/api/kebijakan', [
                'tipe' => 'denda',
                'nilai' => 'persen_harian',
                'persen' => 1,
                'plafon_persen' => 30,
                'masa_tenggang' => 0,
                'berlaku_dari' => '2026-10-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.masa_tenggang', 0);
    }

    public function test_get_kebijakan_mengirim_matriks_kolom_untuk_layar(): void
    {
        // Layar memakai matriks ini untuk tahu medan angka mana yang wajib
        // muncul. Menyalinnya ke Vue akan menjadi salinan ketiga dari aturan
        // yang sama dengan validasi dan CHECK constraint.
        $this->sebagaiPemilik()
            ->getJson('/api/kebijakan?berlaku_pada=2026-10-01')
            ->assertOk()
            ->assertJsonPath('meta.kolom.denda.persen_harian.wajib', ['persen', 'plafon_persen', 'masa_tenggang'])
            ->assertJsonPath('meta.kolom.denda.nominal_tetap.wajib', ['nominal'])
            ->assertJsonPath('meta.kolom.denda.tanpa_denda.wajib', [])
            ->assertJsonPath('meta.kolom.jatuh_tempo.tanggal_tetap.wajib', ['tanggal']);
    }
}
