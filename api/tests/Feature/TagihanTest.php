<?php

namespace Tests\Feature;

use App\Domain\Kebijakan\PilihanKebijakan;
use App\Models\Kamar;
use App\Models\KebijakanKos;
use App\Models\KomponenBiaya;
use App\Models\Kontrak;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\TarifSewa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\MenyiapkanKebijakan;
use Tests\TestCase;

/**
 * T3 — penerbitan tagihan, R-01 dan R-06 lapis 2.
 *
 * Kalkulator murni sudah diuji di tests/Unit/KalkulatorTest.php tanpa basis data.
 * Berkas ini menguji hal yang tidak bisa diuji di sana: apakah angka yang benar
 * benar-benar tersimpan, apakah snapshot membeku, dan apakah periode yang tidak
 * masuk akal ditolak.
 */
class TagihanTest extends TestCase
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

        // Sejak T16 tagihan tidak bisa terbit tanpa kebijakan. Tes di berkas ini
        // menguji prorata dan snapshot, bukan gerbangnya, jadi kebijakannya
        // dinyatakan di sini — sama seperti parameter denda.
        $this->siapkanKebijakanDefault();
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    /**
     * Menyiapkan kontrak siap tagih: kamar, penghuni, tarif, parameter denda.
     */
    private function siapkanKontrak(string $tglMasuk, int $tarif = 1_500_000, array $komponen = []): Kontrak
    {
        $kamar = Kamar::create(['nomor' => 'A1', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'email' => 'budi@kos.test']);

        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => $tarif,
            'berlaku_dari' => '2026-01-01',
        ]);

        foreach ($komponen as $nama => $nilai) {
            KomponenBiaya::create([
                'nama' => $nama,
                'nilai' => $nilai,
                'berlaku_dari' => '2026-01-01',
            ]);
        }

        return Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => $tglMasuk,
            'tarif_awal' => $tarif,
        ]);
    }

    // --- R-01 prorata ------------------------------------------------------

    public function test_masuk_tanggal_1_mendapat_tarif_penuh_bukan_kurang_serupiah(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01');

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', [
                'kontrak_id' => $kontrak->id,
                'periode' => '2026-10-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_500_000);

        // Asersi eksplisit: bukan 1.499.999. Ini titik yang paling sering salah
        // kalau pembulatan dilakukan sebelum pembagian.
        $this->assertSame(1_500_000, $respons->json('data.pokok'));
        $this->assertSame(1_500_000, (int) Tagihan::first()->pokok);
    }

    public function test_masuk_15_oktober_memakai_prorata_17_dari_31(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', [
                'kontrak_id' => $kontrak->id,
                'periode' => '2026-10-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 822_581)
            ->assertJsonPath('data.rincian.0.nama', 'Sewa Oktober 2026 (prorata 17/31 hari)')
            ->assertJsonPath('data.rincian.0.nilai', 822_581);
    }

    public function test_masuk_31_oktober_memakai_prorata_1_dari_31(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-31');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', [
                'kontrak_id' => $kontrak->id,
                'periode' => '2026-10-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 48_387);
    }

    public function test_masuk_28_februari_memakai_prorata_1_dari_28(): void
    {
        $kontrak = $this->siapkanKontrak('2026-02-28');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', [
                'kontrak_id' => $kontrak->id,
                'periode' => '2026-02-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 53_571);
    }

    public function test_bulan_berikutnya_selalu_tarif_penuh_tanpa_prorata(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');

        // Oktober: prorata.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 822_581);

        // November: penuh, walau penghuni masuk tanggal 15.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_500_000)
            ->assertJsonPath('data.rincian.0.nama', 'Sewa November 2026');
    }

    public function test_tidak_ada_prorata_saat_penghuni_keluar_di_tengah_bulan(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');

        // Keluar 10 November: November tetap ditagih penuh, karena sisa bulan
        // tetap disewa sampai penghuni benar-benar keluar.
        $kontrak->update(['tgl_keluar' => '2026-11-10']);

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_500_000);
    }

    public function test_masuk_tanggal_1_tidak_disebut_prorata(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.rincian.0.nama', 'Sewa Oktober 2026')
            ->assertJsonPath('data.pokok', 1_500_000);
    }

    // --- Rincian dan pokok -------------------------------------------------

    public function test_komponen_biaya_muncul_sebagai_baris_tersendiri(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01', 1_500_000, [
            'Kebersihan' => 50_000,
            'Parkir' => 30_000,
        ]);

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', [
                'kontrak_id' => $kontrak->id,
                'periode' => '2026-10-01',
            ])
            ->assertCreated();

        $rincian = collect($respons->json('data.rincian'));

        $this->assertCount(3, $rincian);
        // Masuk tanggal 1 tidak disebut "prorata 31/31" — hasilnya tarif penuh,
        // dan label prorata hanya membingungkan pembaca tagihan.
        $this->assertSame('Sewa Oktober 2026', $rincian[0]['nama']);
        $this->assertSame(1_500_000, $rincian[0]['nilai']);
        $this->assertSame('Kebersihan', $rincian[1]['nama']);
        $this->assertSame(50_000, $rincian[1]['nilai']);
        $this->assertSame('Parkir', $rincian[2]['nama']);
        $this->assertSame(30_000, $rincian[2]['nilai']);
    }

    public function test_pokok_sama_dengan_jumlah_seluruh_rincian(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15', 1_500_000, [
            'Kebersihan' => 50_000,
            'Parkir' => 30_000,
        ]);

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated();

        $jumlahRincian = array_sum(array_column($respons->json('data.rincian'), 'nilai'));

        $this->assertSame($respons->json('data.pokok'), $jumlahRincian);
        // 822.581 + 50.000 + 30.000
        $this->assertSame(902_581, $jumlahRincian);
    }

    public function test_komponen_bernilai_nol_tidak_muncul_sebagai_baris(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01', 1_500_000, [
            'Laundry' => 0,
        ]);

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated();

        $nama = array_column($respons->json('data.rincian'), 'nama');

        $this->assertNotContains('Laundry', $nama);
        $this->assertSame(1_500_000, $respons->json('data.pokok'));
    }

    public function test_total_selalu_sama_dengan_pokok_tambah_denda(): void
    {
        // Sejak T6 denda dihitung sungguhan, jadi nilainya bergantung pada
        // tanggal hari ini dan tidak bisa dipatok ke 0. Yang bisa dipatok
        // adalah invariannya: total selalu pokok + denda, apa pun dendanya.
        $kontrak = $this->siapkanKontrak('2026-10-01');

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated();

        $this->assertSame(
            $respons->json('data.pokok') + $respons->json('data.denda'),
            $respons->json('data.total'),
        );

        // Dan denda tidak pernah negatif, bahkan untuk tagihan yang baru terbit.
        $this->assertGreaterThanOrEqual(0, $respons->json('data.denda'));
    }

    // --- Jatuh tempo -------------------------------------------------------

    public function test_jatuh_tempo_mengikuti_tanggal_masuk(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.jatuh_tempo', '2026-11-15');
    }

    public function test_jatuh_tempo_dijepit_ke_akhir_bulan_pendek(): void
    {
        $kontrak = $this->siapkanKontrak('2026-01-31');

        // Januari: 31 ada, tidak dijepit.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-01-01'])
            ->assertCreated()
            ->assertJsonPath('data.jatuh_tempo', '2026-01-31');

        // Februari: 31 tidak ada, dijepit ke 28.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-02-01'])
            ->assertCreated()
            ->assertJsonPath('data.jatuh_tempo', '2026-02-28');
    }

    public function test_periode_disimpan_sebagai_tanggal_1(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');

        // Dikirim tanggal 15, disimpan tanggal 1.
        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-15'])
            ->assertCreated()
            ->assertJsonPath('data.periode', '2026-10-01');

        $this->assertDatabaseHas('tagihan', ['periode' => '2026-10-01']);
    }

    // --- R-06 lapis 2: snapshot -------------------------------------------

    public function test_tagihan_menyimpan_snapshot_tarif_dan_komponen(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01', 1_500_000, ['Kebersihan' => 50_000]);

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated();

        $snapshot = $respons->json('data.tarif_terpakai');

        $this->assertSame(1_500_000, $snapshot['tarif_sewa']['nilai']);
        $this->assertSame('2026-01-01', $snapshot['tarif_sewa']['berlaku_dari']);
        $this->assertSame('Kebersihan', $snapshot['komponen_biaya'][0]['nama']);
        $this->assertSame(50_000, $snapshot['komponen_biaya'][0]['nilai']);
        $this->assertSame('1.00', $respons->json('data.kebijakan_terpakai.denda.persen'));
        $this->assertSame(30.0, (float) $respons->json('data.kebijakan_terpakai.denda.plafon_persen'));
        $this->assertTrue($snapshot['prorata']);
    }

    public function test_menaikkan_tarif_tidak_mengubah_tagihan_yang_sudah_terbit(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01', 1_500_000);

        $tagihan = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_500_000)
            ->json('data.id');

        // Tarif naik untuk periode berikutnya. Ini penambahan baris, bukan UPDATE.
        TarifSewa::create([
            'kamar_id' => $kontrak->kamar_id,
            'nilai' => 2_000_000,
            'berlaku_dari' => '2026-11-01',
        ]);

        // Tagihan Oktober tidak berubah sedikit pun.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan}")
            ->assertOk()
            ->assertJsonPath('data.pokok', 1_500_000)
            ->assertJsonPath('data.tarif_terpakai.tarif_sewa.nilai', 1_500_000);
    }

    public function test_menaikkan_tarif_berlaku_untuk_tagihan_berikutnya(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01', 1_500_000);

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 1_500_000);

        TarifSewa::create([
            'kamar_id' => $kontrak->kamar_id,
            'nilai' => 2_000_000,
            'berlaku_dari' => '2026-11-01',
        ]);

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.pokok', 2_000_000)
            ->assertJsonPath('data.tarif_terpakai.tarif_sewa.berlaku_dari', '2026-11-01');
    }

    public function test_kebijakan_denda_dibekukan_di_tagihan(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01');

        $tagihan = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertCreated()
            ->assertJsonPath('data.kebijakan_terpakai.denda.tipe', PilihanKebijakan::DENDA_PERSEN_HARIAN)
            ->assertJsonPath('data.kebijakan_terpakai.denda.masa_tenggang', 7)
            ->json('data.id');

        // Kebijakan diubah untuk periode berikutnya.
        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_DENDA,
            'nilai' => PilihanKebijakan::DENDA_PERSEN_HARIAN,
            'persen' => '2.00',
            'plafon_persen' => '25.00',
            'masa_tenggang' => 5,
            'berlaku_dari' => '2026-11-01',
        ]);

        // Tagihan lama tetap memakai bentuk denda lama.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan}")
            ->assertOk()
            ->assertJsonPath('data.kebijakan_terpakai.denda.masa_tenggang', 7)
            ->assertJsonPath('data.kebijakan_terpakai.denda.persen', '1.00');
    }

    // --- Bentuk denda dan pengaruhnya pada tagihan -------------------------

    public function test_denda_nominal_tetap_dibekukan_dan_dipakai(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01');

        // Kebijakan denda default dari siapkanKontrak() harus diganti: satu
        // periode hanya boleh punya satu baris denda yang cocok. Karena itu
        // kebijakan baru berlaku mulai November, dan tagihan Oktober tetap
        // R-03 — yang diuji di sini adalah tagihan November.
        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_DENDA,
            'nilai' => PilihanKebijakan::DENDA_NOMINAL_TETAP,
            'nominal' => 100_000,
            'berlaku_dari' => '2026-11-01',
        ]);

        $tagihan = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.kebijakan_terpakai.denda.tipe', PilihanKebijakan::DENDA_NOMINAL_TETAP)
            ->assertJsonPath('data.kebijakan_terpakai.denda.nominal', 100_000)
            ->json('data.id');

        // Jatuh tempo 2026-11-01, simulasi 2026-11-20 -> 19 hari terlambat.
        // Denda nominal dihitung per bulan keterlambatan: 1 bulan -> 100.000.
        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan}", ['X-Simulasi-Tanggal' => '2026-11-20'])
            ->assertOk()
            ->assertJsonPath('data.denda', 100_000)
            ->assertJsonPath('data.denda_ringkas.tipe', PilihanKebijakan::DENDA_NOMINAL_TETAP)
            ->assertJsonPath('data.denda_ringkas.berjalan', false)
            ->assertJsonPath('data.denda_ringkas.plafon', null);
    }

    public function test_tanpa_denda_menghasilkan_nol_bukan_galat(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-01');

        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_DENDA,
            'nilai' => PilihanKebijakan::DENDA_TANPA_DENDA,
            'berlaku_dari' => '2026-11-01',
        ]);

        $tagihan = $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-11-01'])
            ->assertCreated()
            ->assertJsonPath('data.kebijakan_terpakai.denda.tipe', PilihanKebijakan::DENDA_TANPA_DENDA)
            ->json('data.id');

        $this->sebagaiPemilik()
            ->getJson("/api/tagihan/{$tagihan}", ['X-Simulasi-Tanggal' => '2027-06-01'])
            ->assertOk()
            ->assertJsonPath('data.denda', 0)
            ->assertJsonPath('data.status', Tagihan::STATUS_BELUM_BAYAR)
            ->assertJsonPath('data.denda_ringkas.berjalan', false);
    }

    // --- Penolakan ---------------------------------------------------------

    public function test_periode_sebelum_tanggal_masuk_ditolak(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-09-01'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['periode']]);

        $this->assertSame(0, Tagihan::count());
    }

    public function test_periode_setelah_kontrak_selesai_ditolak(): void
    {
        $kontrak = $this->siapkanKontrak('2026-10-15');
        $kontrak->update(['status' => 'selesai', 'tgl_keluar' => '2026-10-31']);

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-11-01'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['periode']]);
    }

    public function test_kontrak_tanpa_tarif_ditolak_dengan_pesan_jelas(): void
    {
        $kamar = Kamar::create(['nomor' => 'B9', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Ani', 'email' => 'ani@kos.test']);
        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id, 'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-10-01', 'tarif_awal' => 0,
        ]);

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => '2026-10-01'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kamar B9 belum punya tarif yang berlaku untuk periode 2026-10-01.');
    }

    // --- Akses -------------------------------------------------------------

    public function test_penghuni_tidak_boleh_menerbitkan_tagihan(): void
    {
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);
        $akun = User::create([
            'name' => 'Budi', 'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI, 'penghuni_id' => $penghuni->id,
        ]);

        $this->actingAs($akun, 'sanctum')
            ->postJson('/api/tagihan', ['kontrak_id' => 1, 'periode' => '2026-10-01'])
            ->assertStatus(403);
    }

    // --- Beban daftar tagihan ---------------------------------------------

    /**
     * Jumlah kueri `GET /api/tagihan` tidak boleh tumbuh bersama jumlah baris.
     *
     * Uji beban 29 Oktober 2026 mengukur 390 tagihan = 550 KB / 297 ms. Angka
     * itu masih wajar, tetapi ia hanya wajar **selama** relasi dimuat sekali di
     * muka: `TagihanController::index()` memakai `with(['rincian',
     * 'kontrak.kamar', 'kontrak.penghuni'])`. Satu `->load()` yang tertinggal di
     * dalam loop - atau `$tagihan->pembayaran` yang dibaca langsung di resource -
     * mengubah 390 baris menjadi 390 kueri tambahan, dan waktunya berlipat tanpa
     * satu asersi pun gagal: responsnya tetap benar, hanya lambat.
     *
     * Yang diuji karena itu bukan durasi (yang berubah-ubah menurut mesin),
     * melainkan **bentuk** akses datanya: dua baris dan enam baris harus
     * memakai jumlah kueri yang sama.
     */
    public function test_daftar_tagihan_tidak_menambah_kueri_per_baris(): void
    {
        $kontrak = $this->siapkanKontrak('2026-01-01');

        $terbitkan = function (string $periode) use ($kontrak): void {
            $this->sebagaiPemilik()
                ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => $periode])
                ->assertCreated();
        };

        $kueri = 0;
        DB::listen(function () use (&$kueri): void {
            $kueri++;
        });

        $ukur = function () use (&$kueri): int {
            $kueri = 0;
            $this->sebagaiPemilik()->getJson('/api/tagihan')->assertOk();

            return $kueri;
        };

        $terbitkan('2026-01-01');
        $terbitkan('2026-02-01');
        $dua = $ukur();

        foreach (['2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01'] as $periode) {
            $terbitkan($periode);
        }
        $enam = $ukur();

        $this->assertSame(
            $dua,
            $enam,
            "jumlah kueri daftar tagihan naik dari {$dua} ke {$enam} saat baris bertambah dari 2 ke 6 - ada relasi yang dimuat per baris (N+1)",
        );
    }

    /**
     * K-05: berhalaman hanya bila `per_page` diminta.
     *
     * Bentuk bawaan sengaja TIDAK berubah: layar Tagihan memakai daftar utuh,
     * dan ekspor membutuhkannya. Yang diuji karena itu dua arah - permintaan
     * tanpa `per_page` tidak boleh mendapat kunci halaman, dan permintaan
     * dengan `per_page` harus benar-benar terpotong.
     */
    public function test_daftar_tagihan_berhalaman_hanya_bila_per_page_diminta(): void
    {
        $kontrak = $this->siapkanKontrak('2026-01-01');

        foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $periode) {
            $this->sebagaiPemilik()
                ->postJson('/api/tagihan', ['kontrak_id' => $kontrak->id, 'periode' => $periode])
                ->assertCreated();
        }

        $this->sebagaiPemilik()->getJson('/api/tagihan')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta.total');

        $this->sebagaiPemilik()->getJson('/api/tagihan?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.halaman', 1)
            ->assertJsonPath('meta.halaman_terakhir', 2)
            ->assertJsonPath('meta.total', 3);

        $this->sebagaiPemilik()->getJson('/api/tagihan?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.halaman', 2);

        // Di luar batas ditolak, bukan dipotong diam-diam: ekspor yang meminta
        // 10.000 baris harus tahu bahwa ia tidak mendapatkannya.
        $this->sebagaiPemilik()->getJson('/api/tagihan?per_page=201')->assertStatus(422);
        $this->sebagaiPemilik()->getJson('/api/tagihan?per_page=0')->assertStatus(422);
    }
}
