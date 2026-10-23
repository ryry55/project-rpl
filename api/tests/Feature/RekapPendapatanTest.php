<?php

namespace Tests\Feature;

use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\Pembayaran;
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
 * T21 - rekap pendapatan per metode.
 *
 * Satu aturan menentukan seluruh berkas ini benar atau tidak:
 *
 *   Pendapatan = SUM(pembayaran.jumlah).
 *   Denda yang masih tertera di tagihan BUKAN pendapatan - ia piutang.
 *
 * Tes di bagian "denda bukan pendapatan" adalah inti tiket ini. Tampilan yang
 * wajar untuk pemilik kos adalah menjumlahkan kolom `total` tiap tagihan dan
 * menyebutnya pendapatan; angka itu terlalu besar, dan pemilik yang mengira
 * sudah menerima Rp 4 juta padahal rekeningnya bertambah Rp 3,2 juta akan
 * menyalahkan aplikasinya.
 */
class RekapPendapatanTest extends TestCase
{
    use MenyiapkanKebijakan;
    use RefreshDatabase;

    private User $pemilik;

    private User $pengelola;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pemilik = User::create([
            'name' => 'Pemilik Kos',
            'email' => 'pemilik@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PEMILIK,
        ]);

        $this->pengelola = User::create([
            'name' => 'Sari Pengelola',
            'email' => 'sari@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $this->pemilik->id,
        ]);

        $this->siapkanKebijakanDenda();
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    /**
     * Tagihan siap uji. Denda 1%/hari, tenggang 7 hari, plafon 30%.
     *
     * Dibuat langsung lewat model: tes ini soal rekap, dan membuat tagihan
     * lewat HTTP hanya menambah langkah yang bisa gagal karena alasan yang
     * tidak sedang diuji.
     */
    private function buatTagihan(
        string $nomor = 'A1',
        int $pokok = 1_500_000,
        string $status = Tagihan::STATUS_BELUM_BAYAR,
    ): Tagihan {
        $kamar = Kamar::create(['nomor' => $nomor, 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create([
            'nama' => "Penghuni {$nomor}",
            'email' => strtolower($nomor).'@kos.test',
        ]);

        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => $pokok,
            'berlaku_dari' => '2026-01-01',
        ]);

        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-01-15',
            'tarif_awal' => $pokok,
            'deposit' => 0,
        ]);

        return Tagihan::create([
            'kontrak_id' => $kontrak->id,
            'periode' => '2026-11-01',
            'pokok' => $pokok,
            'jatuh_tempo' => '2026-11-15',
            'status' => $status,
            'tarif_terpakai' => ['prorata' => false],
            'kebijakan_terpakai' => [
                'denda' => [
                    'tipe' => 'persen_harian',
                    'persen' => '1.00',
                    'plafon_persen' => '30.00',
                    'masa_tenggang' => 7,
                    'nominal' => 0,
                ],
            ],
            'terbit_pada' => now(),
        ]);
    }

    private function catat(
        Tagihan $tagihan,
        int $jumlah,
        string $metode,
        string $tanggal = '2026-11-10',
        ?int $penerima = null,
    ): Pembayaran {
        return Pembayaran::create([
            'tagihan_id' => $tagihan->id,
            'jumlah' => $jumlah,
            'metode' => $metode,
            'sumber' => 'dicatat_pemilik',
            'dicatat_oleh' => $this->pemilik->id,
            'diterima_oleh' => $penerima,
            'tanggal_pembayaran' => $tanggal,
        ]);
    }

    private function rekap(string $dari = '2026-11-01', string $sampai = '2026-11-30')
    {
        return $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-30')
            ->getJson("/api/rekap?dari={$dari}&sampai={$sampai}");
    }

    // --- Bentuk dasar ------------------------------------------------------

    public function test_rekap_mengelompokkan_per_metode(): void
    {
        $tagihan = $this->buatTagihan();

        $this->catat($tagihan, 1_000_000, 'tunai', '2026-11-05', $this->pemilik->id);
        $this->catat($tagihan, 500_000, 'transfer', '2026-11-06');
        $this->catat($tagihan, 250_000, 'qris', '2026-11-07');

        $data = $this->rekap()->assertOk()->json('data');

        $perMetode = collect($data['per_metode'])->keyBy('metode');

        $this->assertSame(1_000_000, $perMetode['tunai']['jumlah']);
        $this->assertSame(500_000, $perMetode['transfer']['jumlah']);
        $this->assertSame(250_000, $perMetode['qris']['jumlah']);
        $this->assertSame(1_750_000, $data['total']);
    }

    public function test_metode_tanpa_uang_tetap_muncul_dengan_nol(): void
    {
        $tagihan = $this->buatTagihan();
        $this->catat($tagihan, 1_000_000, 'tunai', '2026-11-05', $this->pemilik->id);

        $data = $this->rekap()->assertOk()->json('data');

        // Empat metode selalu ada, termasuk yang belum menerima uang. Kelompok
        // yang hilang membuat pemilik bertanya apakah QRIS memang tidak dipakai
        // atau layarnya rusak.
        $this->assertCount(4, $data['per_metode']);

        $perMetode = collect($data['per_metode'])->keyBy('metode');
        $this->assertSame(0, $perMetode['gateway']['jumlah']);
        $this->assertArrayHasKey('label', $perMetode['qris']);
    }

    public function test_rentang_tanggal_menentukan_yang_dihitung(): void
    {
        $tagihan = $this->buatTagihan();

        $this->catat($tagihan, 1_000_000, 'tunai', '2026-10-20', $this->pemilik->id);
        $this->catat($tagihan, 500_000, 'tunai', '2026-11-10', $this->pemilik->id);

        $november = $this->rekap('2026-11-01', '2026-11-30')->assertOk()->json('data');
        $oktober = $this->rekap('2026-10-01', '2026-10-31')->assertOk()->json('data');

        $this->assertSame(500_000, $november['total']);
        $this->assertSame(1_000_000, $oktober['total']);
    }

    public function test_periode_memakai_tanggal_bayar_bukan_periode_tagihan(): void
    {
        // Tagihan periode November, dibayar Oktober (membayar di muka).
        $tagihan = $this->buatTagihan();
        $this->catat($tagihan, 1_500_000, 'transfer', '2026-10-28');

        $oktober = $this->rekap('2026-10-01', '2026-10-31')->assertOk()->json('data');
        $november = $this->rekap('2026-11-01', '2026-11-30')->assertOk()->json('data');

        // Basis kas, bukan akrual: yang dihitung adalah uang yang MASUK bulan
        // itu. Itu yang terlihat di rekening, dan itulah yang harus cocok.
        $this->assertSame(1_500_000, $oktober['total']);
        $this->assertSame(0, $november['total']);
    }

    // --- Denda bukan pendapatan -------------------------------------------

    public function test_denda_belum_dibayar_tidak_masuk_pendapatan(): void
    {
        // Tagihan Rp 1.500.000, jatuh tempo 15 Nov, acuan 30 Nov.
        // Terlambat 15 hari, tenggang 7 -> 8 hari denda -> 8% x 1.500.000 =
        // Rp 120.000. Pokoknya belum dibayar sama sekali.
        $this->buatTagihan();

        $data = $this->rekap()->assertOk()->json('data');

        // Inti T21. Kalau angka ini bukan nol, rekapnya menghitung uang yang
        // belum diterima siapa pun - dan pemilik akan mengira rekeningnya
        // kurang Rp 120.000.
        $this->assertSame(0, $data['total']);
        $this->assertSame(120_000, $data['piutang']['denda_belum_dibayar']);
        $this->assertSame(1, $data['piutang']['tagihan_terbuka']);
    }

    public function test_denda_yang_sudah_dibayar_masuk_pendapatan(): void
    {
        // Tagihan LUNAS: `total_final` sudah membekukan pokok + denda, dan
        // baris pembayaran yang mencatatnya adalah pendapatan. Denda berhenti
        // berjalan dan tidak lagi dihitung sebagai piutang.
        $tagihan = $this->buatTagihan('A1', 1_500_000, Tagihan::STATUS_LUNAS);
        $tagihan->update(['total_final' => 1_620_000]);
        $this->catat($tagihan, 1_620_000, 'tunai', '2026-11-30', $this->pemilik->id);

        $data = $this->rekap()->assertOk()->json('data');

        $this->assertSame(1_620_000, $data['total']);
        // Sudah lunas, jadi tidak lagi dihitung sebagai piutang. Menghitungnya
        // lagi akan menghitung uang yang sama dua kali: sekali sebagai
        // pendapatan, sekali sebagai tagihan terbuka.
        $this->assertSame(0, $data['piutang']['denda_belum_dibayar']);
        $this->assertSame(0, $data['piutang']['tagihan_terbuka']);
    }

    public function test_total_rekap_sama_dengan_jumlah_baris_pembayaran(): void
    {
        $tagihan = $this->buatTagihan();
        $this->catat($tagihan, 400_000, 'tunai', '2026-11-05', $this->pemilik->id);
        $this->catat($tagihan, 600_000, 'transfer', '2026-11-08');
        $this->catat($tagihan, 200_000, 'qris', '2026-11-09');

        $data = $this->rekap()->assertOk()->json('data');

        // Sumber kebenarannya satu: SUM(pembayaran.jumlah). Kalau rekap dan
        // tabel pembayaran bisa berbeda, salah satunya berbohong dan tidak ada
        // cara tahu yang mana.
        $this->assertSame(1_200_000, $data['total']);
        $this->assertSame(3, $data['jumlah_transaksi']);
        $this->assertSame(
            1_200_000,
            (int) Pembayaran::query()->sum('jumlah'),
        );
    }

    // --- Baris tanpa metode ------------------------------------------------

    public function test_baris_tanpa_metode_dilaporkan_terpisah(): void
    {
        $tagihan = $this->buatTagihan();

        $this->catat($tagihan, 500_000, 'tunai', '2026-11-05', $this->pemilik->id);

        // Baris lama dari sebelum T20. Migrasinya sengaja tidak menebak
        // metodenya; menganggapnya tunai berarti mengarang fakta tentang uang.
        Pembayaran::create([
            'tagihan_id' => $tagihan->id,
            'jumlah' => 300_000,
            'metode' => null,
            'sumber' => 'dicatat_pemilik',
            'tanggal_pembayaran' => '2026-11-06',
        ]);

        $data = $this->rekap()->assertOk()->json('data');

        // Totalnya tetap menjumlahkan semuanya - uangnya nyata. Yang berbeda
        // adalah pengelompokannya: "tanpa metode" membuat lubangnya terlihat,
        // bukan tertutup rapi di dalam "tunai".
        $this->assertSame(800_000, $data['total']);
        $this->assertSame(300_000, $data['tanpa_metode']['jumlah']);
        $this->assertSame(1, $data['tanpa_metode']['transaksi']);

        $perMetode = collect($data['per_metode'])->keyBy('metode');
        $this->assertSame(500_000, $perMetode['tunai']['jumlah']);
    }

    // --- Tunai di tangan siapa --------------------------------------------

    public function test_tunai_dipecah_per_pemegang_uang(): void
    {
        $tagihan = $this->buatTagihan();

        $this->catat($tagihan, 700_000, 'tunai', '2026-11-05', $this->pemilik->id);
        $this->catat($tagihan, 300_000, 'tunai', '2026-11-06', $this->pengelola->id);
        $this->catat($tagihan, 200_000, 'transfer', '2026-11-07');

        $data = $this->rekap()->assertOk()->json('data');

        $perOrang = collect($data['tunai_di_tangan'])->keyBy('nama');

        $this->assertSame(700_000, $perOrang[$this->pemilik->name]['jumlah']);
        $this->assertSame(300_000, $perOrang[$this->pengelola->name]['jumlah']);

        // Transfer tidak ikut: uangnya sudah di rekening sejak awal, tidak ada
        // yang memegang. Kalau ikut, pertanyaan "setoran siapa yang belum
        // disetor" berubah menjadi daftar yang salah.
        $this->assertCount(2, $data['tunai_di_tangan']);
        $this->assertSame(
            1_000_000,
            collect($data['tunai_di_tangan'])->sum('jumlah'),
        );
    }

    public function test_tunai_tanpa_pemegang_dikelompokkan_tidak_tercatat(): void
    {
        $tagihan = $this->buatTagihan();

        // Baris lama: metode tunai tetapi pemegangnya tidak tercatat. Tidak
        // mungkin lewat jalur aplikasi sejak T20 - CHECK menolaknya - tetapi
        // bisa ada di data yang dibuat sebelum kolomnya ada. Constraint
        // dilepas sebentar untuk membuat keadaan itu, lalu dipasang kembali;
        // itulah satu-satunya cara menguji perilaku rekap atas data warisan
        // tanpa melemahkan constraint-nya di produksi.
        DB::statement('ALTER TABLE pembayaran DROP CONSTRAINT pembayaran_tunai_ada_pemegang');

        DB::table('pembayaran')->insert([
            'tagihan_id' => $tagihan->id,
            'jumlah' => 400_000,
            'metode' => 'tunai',
            'sumber' => 'dicatat_pemilik',
            'diterima_oleh' => null,
            'tanggal_pembayaran' => '2026-11-05',
            'created_at' => now(),
        ]);

        DB::statement(
            "ALTER TABLE pembayaran
             ADD CONSTRAINT pembayaran_tunai_ada_pemegang
             CHECK (metode IS DISTINCT FROM 'tunai' OR diterima_oleh IS NOT NULL)
             NOT VALID"
        );

        $data = $this->rekap()->assertOk()->json('data');

        // Dibuang akan membuat total per orang tidak lagi berjumlah sama dengan
        // total tunai, dan selisih yang tidak dijelaskan adalah hal pertama
        // yang ditanyakan saat uangnya tidak cocok. Karena itu ia tetap muncul,
        // dengan nama yang jujur.
        $this->assertSame(400_000, $data['total']);
        $this->assertSame(400_000, collect($data['tunai_di_tangan'])->sum('jumlah'));
        $this->assertSame('Tidak tercatat', $data['tunai_di_tangan'][0]['nama']);
        $this->assertNull($data['tunai_di_tangan'][0]['diterima_oleh']);
    }

    // --- Meta dan akses ----------------------------------------------------

    public function test_meta_mengumumkan_status_simulasi(): void
    {
        $data = $this->rekap()->assertOk()->json('data');

        $this->assertTrue($data['simulasi']);
        $this->assertArrayHasKey('demo_mode', $data);
    }

    public function test_bawaan_rentang_adalah_bulan_berjalan(): void
    {
        $data = $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-15')
            ->getJson('/api/rekap')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-11-01', $data['dari']);
        $this->assertSame('2026-11-15', $data['sampai']);
    }

    public function test_rentang_terbalik_ditolak(): void
    {
        $this->sebagaiPemilik()
            ->getJson('/api/rekap?dari=2026-11-30&sampai=2026-11-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sampai');
    }

    public function test_pengelola_boleh_membaca_rekap(): void
    {
        // Membaca uang yang sudah masuk bukan tindakan yang merugikan siapa
        // pun, dan pengelola yang mencatat pembayaran memang perlu memeriksa
        // catatannya sendiri. Yang dibatasi wewenang adalah MENULIS.
        $this->actingAs($this->pengelola, 'sanctum')
            ->getJson('/api/rekap?dari=2026-11-01&sampai=2026-11-30')
            ->assertOk();
    }

    public function test_penghuni_tidak_boleh_membaca_rekap(): void
    {
        $penghuni = User::create([
            'name' => 'Budi',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
        ]);

        $this->actingAs($penghuni, 'sanctum')
            ->getJson('/api/rekap')
            ->assertForbidden();
    }

    public function test_tanpa_login_ditolak(): void
    {
        $this->getJson('/api/rekap')->assertUnauthorized();
    }

    // --- Daftar pembayaran -------------------------------------------------

    public function test_daftar_pembayaran_membawa_metode_dan_pemegang(): void
    {
        $tagihan = $this->buatTagihan();
        $this->catat($tagihan, 500_000, 'tunai', '2026-11-05', $this->pengelola->id);

        $data = $this->sebagaiPemilik()
            ->getJson('/api/pembayaran?dari=2026-11-01&sampai=2026-11-30')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('tunai', $data[0]['metode']);
        $this->assertSame('Tunai', $data[0]['metode_label']);
        $this->assertSame($this->pengelola->name, $data[0]['diterima_oleh_nama']);
        // Belum diperiksa, jadi `null` - bukan 'terima'.
        $this->assertNull($data[0]['verifikasi_terakhir']);
    }

    public function test_daftar_pembayaran_rentang_wajib(): void
    {
        $this->sebagaiPemilik()
            ->getJson('/api/pembayaran')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dari', 'sampai']);
    }

    public function test_daftar_pembayaran_menyebut_penghuni_dan_kamarnya(): void
    {
        $tagihan = $this->buatTagihan('B7');
        $this->catat($tagihan, 500_000, 'transfer', '2026-11-05');

        $baris = $this->sebagaiPemilik()
            ->getJson('/api/pembayaran?dari=2026-11-01&sampai=2026-11-30')
            ->assertOk()
            ->json('data.0');

        // Daftar uang tanpa "dari siapa" hanya berisi tanggal dan angka. Saat
        // rekonsiliasi, pertanyaan pertama yang muncul justru "ini setoran
        // siapa" - dan menjawabnya menuntut membuka tagihan satu per satu.
        $this->assertSame('Penghuni B7', $baris['penghuni_nama']);
        $this->assertSame('B7', $baris['kamar_nomor']);
        $this->assertSame('2026-11-01', $baris['tagihan_periode']);
    }

    // --- Daftar pemegang uang ----------------------------------------------

    public function test_daftar_penerima_berisi_pemilik_dan_pengelola(): void
    {
        $data = $this->sebagaiPemilik()
            ->getJson('/api/pembayaran/penerima')
            ->assertOk()
            ->json('data');

        $nama = collect($data)->pluck('nama')->all();

        $this->assertContains('Pemilik Kos', $nama);
        $this->assertContains('Sari Pengelola', $nama);
    }

    public function test_daftar_penerima_tidak_memuat_penghuni(): void
    {
        User::create([
            'name' => 'Budi Penghuni',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
        ]);

        $nama = collect(
            $this->sebagaiPemilik()->getJson('/api/pembayaran/penerima')->json('data')
        )->pluck('nama')->all();

        // Penghuni adalah pihak yang membayar, bukan yang memegang setoran.
        // Memasukkannya ke pilihan akan menghasilkan baris "uang dipegang Budi"
        // yang tidak punya arti apa pun saat rekap tunai dijumlahkan.
        $this->assertNotContains('Budi Penghuni', $nama);
    }

    public function test_pengelola_boleh_membaca_daftar_penerima(): void
    {
        // Pengelola yang mencatat pembayaran justru yang paling butuh daftar
        // ini. Menutupnya dengan peran pemilik akan membuatnya mencatat semua
        // uang tunai atas namanya sendiri - fakta yang ingin dihindari.
        $this->actingAs($this->pengelola, 'sanctum')
            ->getJson('/api/pembayaran/penerima')
            ->assertOk();
    }

    public function test_penghuni_tidak_boleh_membaca_daftar_penerima(): void
    {
        $penghuni = User::create([
            'name' => 'Budi Penghuni',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
        ]);

        $this->actingAs($penghuni, 'sanctum')
            ->getJson('/api/pembayaran/penerima')
            ->assertForbidden();
    }
}
