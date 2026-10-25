<?php

namespace Tests\Feature;

use App\Domain\Audit\PencatatAudit;
use App\Domain\Pembayaran\MetodePembayaran;
use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\LogAudit;
use App\Models\Pembayaran;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\TarifSewa;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\MenyiapkanKebijakan;
use Tests\TestCase;

/**
 * T20 - fondasi pembayaran (metode, sumber, pemegang uang, verifikasi).
 *
 * Tiga hal yang menentukan tiket ini benar atau tidak:
 *
 *   1. Setiap baris uang bisa dikelompokkan. Baris tanpa metode adalah lubang
 *      yang harus terlihat, bukan lubang yang ditutup dengan nilai bawaan.
 *   2. Tunai selalu menyebut pemegang uangnya. Tanpa itu, "setoran siapa yang
 *      belum disetor" tidak bisa dijawab - dan itulah pertanyaan yang
 *      sebenarnya ditanyakan pemilik yang tidak tinggal di lokasi.
 *   3. Pemeriksaan pembayaran append-only dan yang berlaku adalah baris
 *      TERAKHIR. Membaca "apakah pernah ada baris terima" akan membuat
 *      pembayaran yang sudah terbukti salah tetap berstatus terverifikasi.
 */
class PembayaranMetodeTest extends TestCase
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
        $this->pengelola->kewenangan()->create(['wewenang' => 'catat_pembayaran']);
        $this->pengelola->kewenangan()->create(['wewenang' => 'verifikasi_pembayaran']);
        $this->pengelola->load('kewenangan');

        $this->siapkanKebijakanDenda();
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    private function buatTagihan(string $status = Tagihan::STATUS_BELUM_BAYAR): Tagihan
    {
        $kamar = Kamar::create(['nomor' => 'A1', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);

        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => 1_500_000,
            'berlaku_dari' => '2026-01-01',
        ]);

        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-01-15',
            'tarif_awal' => 1_500_000,
        ]);

        return Tagihan::create([
            'kontrak_id' => $kontrak->id,
            'periode' => '2026-11-01',
            'pokok' => 1_500_000,
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

    /**
     * @param  array<string, mixed>  $tambahan
     */
    private function bayar(Tagihan $tagihan, array $tambahan = [])
    {
        return $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", array_merge([
                'jumlah' => 500_000,
                'tanggal_pembayaran' => '2026-11-10',
                'metode' => 'tunai',
            ], $tambahan));
    }

    // --- Metode wajib dan tervalidasi --------------------------------------

    public function test_metode_wajib_diisi(): void
    {
        $tagihan = $this->buatTagihan();

        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 500_000,
                'tanggal_pembayaran' => '2026-11-10',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metode');

        // Ditolak sebelum tersimpan. Baris tanpa metode hanya boleh berasal dari
        // data lama, bukan dari jalur aplikasi.
        $this->assertSame(0, Pembayaran::count());
    }

    public function test_metode_tidak_dikenal_ditolak_422(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, ['metode' => 'transfer-bca'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metode');

        $this->assertSame(0, Pembayaran::count());
    }

    public function test_keempat_metode_diterima(): void
    {
        $tagihan = $this->buatTagihan();

        foreach (MetodePembayaran::SEMUA as $metode) {
            // Nominal kecil supaya empat pembayaran masih di bawah sisa.
            $this->bayar($tagihan, ['metode' => $metode, 'jumlah' => 100_000])
                ->assertCreated()
                ->assertJsonPath('data.pembayaran.metode', $metode);
        }

        $this->assertSame(4, Pembayaran::count());
    }

    // --- Pemegang uang -----------------------------------------------------

    public function test_tunai_tanpa_pemegang_diisi_otomatis_dengan_pencatat(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, ['metode' => 'tunai'])
            ->assertCreated()
            ->assertJsonPath('data.pembayaran.diterima_oleh', $this->pemilik->id);

        // Alasan kolomnya diisi otomatis, bukan dibiarkan kosong: orang yang
        // mencatat uang tunai yang ia terima sendiri adalah kasus paling umum,
        // dan memaksanya memilih namanya sendiri setiap kali hanya menambah
        // satu klik tanpa menambah fakta apa pun.
        $this->assertSame($this->pemilik->id, Pembayaran::firstOrFail()->diterima_oleh);
    }

    public function test_pemegang_tunai_bisa_disebut_eksplisit(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, [
            'metode' => 'tunai',
            'diterima_oleh' => $this->pengelola->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.pembayaran.diterima_oleh', $this->pengelola->id);
    }

    public function test_transfer_tidak_menyebut_pemegang(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, ['metode' => 'transfer'])
            ->assertCreated()
            ->assertJsonPath('data.pembayaran.diterima_oleh', null);

        // Uangnya sudah di rekening sejak awal - tidak ada yang "memegang".
        // Mengisi kolomnya dengan pencatat akan membuat rekap tunai per orang
        // menghitung uang yang tidak pernah lewat tangan siapa pun.
        $this->assertNull(Pembayaran::firstOrFail()->diterima_oleh);
    }

    public function test_penerima_uang_harus_akun_yang_ada(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, ['diterima_oleh' => 999_999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('diterima_oleh');
    }

    // --- Sumber, referensi, pencatat ---------------------------------------

    public function test_sumber_dan_pencatat_terisi_dari_sesi(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, ['metode' => 'transfer', 'referensi' => 'TRX-2026-001'])
            ->assertCreated();

        $pembayaran = Pembayaran::firstOrFail();

        $this->assertSame(MetodePembayaran::SUMBER_DICATAT_PEMILIK, $pembayaran->sumber);
        $this->assertSame($this->pemilik->id, $pembayaran->dicatat_oleh);
        $this->assertSame('TRX-2026-001', $pembayaran->referensi);
    }

    public function test_pengelola_dicatat_dengan_namanya_sendiri(): void
    {
        $tagihan = $this->buatTagihan();

        $this->actingAs($this->pengelola, 'sanctum')
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 500_000,
                'tanggal_pembayaran' => '2026-11-10',
                'metode' => 'tunai',
            ])->assertCreated();

        $pembayaran = Pembayaran::firstOrFail();

        // Bukan atas nama pemilik. Jejak yang menyebut pemilik padahal
        // pengelolanya yang menerima uang membuat pertanyaan "siapa memegang
        // uang ini" salah dijawab oleh sistemnya sendiri.
        $this->assertSame($this->pengelola->id, $pembayaran->dicatat_oleh);
        $this->assertSame($this->pengelola->id, $pembayaran->diterima_oleh);
    }

    public function test_metode_ikut_tercatat_di_log_audit(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, ['metode' => 'qris'])->assertCreated();

        $log = LogAudit::where('aksi', PencatatAudit::AKSI_TAGIHAN_BAYAR)->firstOrFail();

        $this->assertSame('qris', $log->nilai_baru['metode']);
        // QRIS masuk rekening, jadi tidak ada yang memegang uangnya. Jejaknya
        // harus menyebut `null` dengan jujur, bukan menyalin nama pencatat.
        $this->assertNull($log->nilai_baru['diterima_oleh']);
    }

    public function test_tunai_ikut_tercatat_di_log_audit_dengan_pemegang(): void
    {
        $tagihan = $this->buatTagihan();

        $this->bayar($tagihan, ['metode' => 'tunai'])->assertCreated();

        $log = LogAudit::where('aksi', PencatatAudit::AKSI_TAGIHAN_BAYAR)->firstOrFail();

        // Pertanyaan "uang tunai Rp 500.000 tanggal 10 itu masuk ke siapa"
        // harus bisa dijawab dari jejaknya sendiri. Tanpa ini, jawabannya
        // menuntut query langsung ke tabel pembayaran - dan jejak audit yang
        // butuh query lain untuk lengkap bukan jejak yang bisa ditunjukkan.
        $this->assertSame('tunai', $log->nilai_baru['metode']);
        $this->assertSame($this->pemilik->id, $log->nilai_baru['diterima_oleh']);
    }

    // --- Penegakan di basis data -------------------------------------------

    public function test_metode_tidak_dikenal_ditolak_basis_data(): void
    {
        $tagihan = $this->buatTagihan();

        $this->expectException(QueryException::class);

        // Query mentah: yang diuji adalah CHECK constraint, bukan validasi HTTP.
        // Tanpa ini, satu jalur tulis yang melewati FormRequest cukup untuk
        // membuat rekap punya kelompok yang tidak pernah tampil di layar.
        DB::table('pembayaran')->insert([
            'tagihan_id' => $tagihan->id,
            'jumlah' => 100_000,
            'tanggal_pembayaran' => '2026-11-10',
            'metode' => 'bitcoin',
            'created_at' => now(),
        ]);
    }

    public function test_tunai_tanpa_pemegang_ditolak_basis_data(): void
    {
        $tagihan = $this->buatTagihan();

        $this->expectException(QueryException::class);

        DB::table('pembayaran')->insert([
            'tagihan_id' => $tagihan->id,
            'jumlah' => 100_000,
            'tanggal_pembayaran' => '2026-11-10',
            'metode' => 'tunai',
            'diterima_oleh' => null,
            'created_at' => now(),
        ]);
    }

    public function test_akun_yang_pernah_menangani_uang_tidak_bisa_dihapus(): void
    {
        $tagihan = $this->buatTagihan();
        $this->bayar($tagihan, ['metode' => 'tunai'])->assertCreated();

        // `restrictOnDelete`, bukan `nullOnDelete`. PostgreSQL menerapkan SET
        // NULL dengan UPDATE pada baris anak, dan trigger append-only menolak
        // setiap UPDATE - sehingga `nullOnDelete` tidak mungkin berjalan dan
        // setiap penghapusan akun akan gagal dengan pesan yang menyesatkan
        // ("Tabel pembayaran bersifat append-only", padahal yang dihapus akun).
        // RESTRICT membuat kegagalan itu jujur dan terjadi lebih awal.
        $pembayaran = Pembayaran::firstOrFail();
        $this->assertSame($this->pemilik->id, $pembayaran->diterima_oleh);

        $this->expectException(QueryException::class);

        $this->pemilik->delete();
    }

    public function test_baris_pembayaran_tetap_utuh_setelah_penghapusan_ditolak(): void
    {
        $tagihan = $this->buatTagihan();
        $this->bayar($tagihan, ['metode' => 'tunai'])->assertCreated();

        try {
            // Dibungkus `DB::transaction` supaya kegagalannya hanya membatalkan
            // savepoint, bukan seluruh transaksi tes. Tanpa itu PostgreSQL
            // menandai transaksi sebagai aborted dan query berikutnya - yaitu
            // query yang justru ingin diperiksa hasilnya - ikut gagal.
            DB::transaction(fn () => $this->pemilik->delete());
        } catch (QueryException) {
            // Diharapkan. Yang diperiksa adalah akibatnya, bukan exceptionnya.
        }

        // Catatan uang harus tetap menyebut siapa yang menerimanya. Kalau
        // penghapusan berhasil dan kolomnya menjadi kosong, pertanyaan "setoran
        // siapa ini" tidak bisa dijawab lagi oleh sistem.
        $pembayaran = Pembayaran::firstOrFail();
        $this->assertSame($this->pemilik->id, $pembayaran->diterima_oleh);
        $this->assertSame($this->pemilik->id, $pembayaran->dicatat_oleh);
    }

    // --- Riwayat di panel tagihan ------------------------------------------

    public function test_riwayat_tagihan_membawa_metode_dan_pemegang(): void
    {
        $tagihan = $this->buatTagihan();
        $this->bayar($tagihan, [
            'metode' => 'tunai',
            'diterima_oleh' => $this->pengelola->id,
        ])->assertCreated();

        $riwayat = $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->getJson("/api/tagihan/{$tagihan->id}")
            ->assertOk()
            ->json('data.pembayaran.0');

        // Panel Tagihan menampilkan riwayat uang, jadi ia butuh hal yang sama
        // dengan daftar pembayaran: metode, pemegang, dan keadaan pemeriksaan.
        // Tanpa itu pemilik harus berpindah ke halaman Rekap hanya untuk tahu
        // uang ini tunai atau transfer.
        $this->assertSame('tunai', $riwayat['metode']);
        $this->assertSame('Tunai', $riwayat['metode_label']);
        $this->assertSame($this->pengelola->name, $riwayat['diterima_oleh_nama']);
        $this->assertNull($riwayat['verifikasi_terakhir']);
    }

    public function test_riwayat_tagihan_membawa_hasil_pemeriksaan_terakhir(): void
    {
        $tagihan = $this->buatTagihan();
        $this->bayar($tagihan, ['metode' => 'transfer'])->assertCreated();

        $pembayaran = Pembayaran::firstOrFail();

        $this->sebagaiPemilik()
            ->postJson("/api/pembayaran/{$pembayaran->id}/verifikasi", ['keputusan' => 'terima'])
            ->assertCreated();

        $riwayat = $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->getJson("/api/tagihan/{$tagihan->id}")
            ->assertOk()
            ->json('data.pembayaran.0');

        // `null` dan 'terima' harus bisa dibedakan di layar. Menyimpulkannya
        // dari "ada catatan" akan salah begitu pemeriksaan diterima tanpa
        // catatan - dan penerimaan tanpa catatan adalah kasus yang paling umum.
        $this->assertSame('terima', $riwayat['verifikasi_terakhir']['keputusan']);
    }

    public function test_baris_lama_tanpa_metode_tetap_boleh_ada(): void
    {
        $tagihan = $this->buatTagihan();

        // Ini keadaan data sebelum T20, dan migrasinya sengaja tidak menebak
        // metodenya. Menganggap baris lama sebagai tunai berarti mengarang
        // fakta tentang uang; membiarkannya NULL membuat lubangnya terlihat.
        DB::table('pembayaran')->insert([
            'tagihan_id' => $tagihan->id,
            'jumlah' => 100_000,
            'tanggal_pembayaran' => '2026-11-10',
            'metode' => null,
            'created_at' => now(),
        ]);

        $this->assertNull(Pembayaran::firstOrFail()->metode);
    }
}
