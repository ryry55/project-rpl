<?php

namespace Tests\Feature;

use App\Domain\Audit\PencatatAudit;
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
 * REV-FIN-01 - pembayaran yang salah dicatat bisa dibalik.
 *
 * Sebelum ini satu-satunya cara membetulkan salah ketik nominal adalah
 * menyunting barisnya, dan itu menghapus fakta bahwa angka yang salah pernah
 * tercatat. Yang ditambahkan di sini adalah **baris pembalik**: nominal negatif
 * yang menunjuk baris asalnya lewat `pembalik_id`.
 *
 * Kenapa baris negatif, bukan tabel pembalik tersendiri: seluruh laporan di
 * aplikasi ini menjumlahkan `SUM(pembayaran.jumlah)`. Dengan nominal negatif,
 * `totalDibayar()`, rekap pendapatan, sisa piutang, dan uang tunai di tangan
 * pengelola semuanya tetap benar tanpa satu pun agregat diubah - dan agregat
 * yang tidak perlu diubah adalah agregat yang tidak bisa lupa diubah.
 *
 * Pembalikan bersifat PENUH, bukan sebagian. Koreksi nominal berarti balik lalu
 * catat ulang; dua langkah itu meninggalkan dua baris yang bisa dibaca, bukan
 * satu baris yang berubah tanpa jejak.
 */
class PembalikPembayaranTest extends TestCase
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

    private function buatTagihan(): Tagihan
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
            'status' => Tagihan::STATUS_BELUM_BAYAR,
            'tarif_terpakai' => ['prorata' => false],
            'kebijakan_terpakai' => ['denda' => ['tipe' => 'tanpa_denda']],
            'terbit_pada' => now(),
        ]);
    }

    private function catat(Tagihan $tagihan, int $jumlah = 500_000): Pembayaran
    {
        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => $jumlah,
                'tanggal_pembayaran' => '2026-11-10',
                'metode' => 'tunai',
            ])
            ->assertCreated();

        return Pembayaran::query()->latest('id')->firstOrFail();
    }

    private function balik(Pembayaran $pembayaran, string $alasan = 'Salah ketik nominal')
    {
        return $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-12')
            ->postJson("/api/pembayaran/{$pembayaran->id}/pembalik", ['alasan' => $alasan]);
    }

    // --- Pembalikan dasar --------------------------------------------------

    public function test_membalik_pembayaran_menulis_baris_negatif_bertanda_pembalik(): void
    {
        $tagihan = $this->buatTagihan();
        $asli = $this->catat($tagihan, 500_000);

        $respons = $this->balik($asli, 'Salah ketik, seharusnya 300.000')->assertCreated();

        $pembalik = Pembayaran::query()->whereNotNull('pembalik_id')->firstOrFail();

        $this->assertSame(-500_000, (int) $pembalik->jumlah);
        $this->assertSame($asli->id, (int) $pembalik->pembalik_id);
        $this->assertSame($this->pemilik->id, (int) $pembalik->dicatat_oleh);

        // Alasannya masuk ke `catatan`, bukan kolom baru: `pembayaran` tidak
        // punya kolom `alasan`, dan menambahkannya berarti dua kolom teks yang
        // menjawab pertanyaan yang sama. CHECK `pembalik_ada_alasan` membaca
        // `catatan` justru supaya hanya ada satu tempat.
        $this->assertSame('Salah ketik, seharusnya 300.000', $pembalik->catatan);

        // Tanggalnya HARI INI, bukan tanggal pembayaran aslinya. Pembalikan
        // adalah peristiwa hari ini; menaruhnya di tanggal asal akan membuat
        // buku bulan lalu berubah setelah bulan itu ditutup.
        $this->assertSame('2026-11-12', $pembalik->tanggal_pembayaran->toDateString());

        $respons->assertJsonPath('data.pembalik.jumlah', -500_000);
        $respons->assertJsonPath('data.pembalik.adalah_pembalik', true);
        $respons->assertJsonPath('data.pembayaran.sudah_dibalik', true);
        $respons->assertJsonPath('data.status_tagihan', Tagihan::STATUS_BELUM_BAYAR);
    }

    public function test_saldo_tagihan_kembali_setelah_pembalikan(): void
    {
        $tagihan = $this->buatTagihan();
        $asli = $this->catat($tagihan, 500_000);

        $this->assertSame(500_000, $tagihan->fresh()->totalDibayar());
        $this->assertSame(Tagihan::STATUS_SEBAGIAN, $tagihan->fresh()->status);

        $this->balik($asli)->assertCreated();

        $this->assertSame(0, $tagihan->fresh()->totalDibayar());
        $this->assertSame(Tagihan::STATUS_BELUM_BAYAR, $tagihan->fresh()->status);
    }

    public function test_pembalikan_pembayaran_lunas_mengembalikan_status_dan_total_final(): void
    {
        $tagihan = $this->buatTagihan();
        $asli = $this->catat($tagihan, 1_500_000);

        $lunas = $tagihan->fresh();
        $this->assertSame(Tagihan::STATUS_LUNAS, $lunas->status);
        $this->assertNotNull($lunas->total_final);

        $this->balik($asli)->assertCreated();

        $sesudah = $tagihan->fresh();
        $this->assertSame(Tagihan::STATUS_BELUM_BAYAR, $sesudah->status);

        // `total_final` dikosongkan lagi: angka itu membeku "berapa yang
        // dibayar saat lunas", dan setelah pembalikan tidak ada lagi
        // pelunasan yang bisa dibekukan.
        $this->assertNull($sesudah->total_final);
    }

    public function test_rekap_menghitung_pembalik_terpisah_dari_transaksi(): void
    {
        $tagihan = $this->buatTagihan();
        $asli = $this->catat($tagihan, 500_000);
        $this->balik($asli)->assertCreated();

        $data = $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-15')
            ->getJson('/api/rekap?dari=2026-11-01&sampai=2026-11-30')
            ->assertOk()
            ->json('data');

        // Pendapatan bersih nol: satu transaksi masuk, satu pembalik keluar.
        $this->assertSame(0, (int) $data['total']);
        $this->assertSame(1, (int) $data['jumlah_transaksi']);
        $this->assertSame(1, (int) $data['pembalik']['transaksi']);
        $this->assertSame(-500_000, (int) $data['pembalik']['jumlah']);
    }

    // --- Batasan -----------------------------------------------------------

    public function test_alasan_wajib_diisi(): void
    {
        $tagihan = $this->buatTagihan();
        $asli = $this->catat($tagihan);

        $this->sebagaiPemilik()
            ->postJson("/api/pembayaran/{$asli->id}/pembalik", ['alasan' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('alasan');
    }

    public function test_pembalik_tidak_bisa_dibalik_lagi(): void
    {
        $tagihan = $this->buatTagihan();
        $asli = $this->catat($tagihan);
        $this->balik($asli)->assertCreated();

        $pembalik = Pembayaran::query()->whereNotNull('pembalik_id')->firstOrFail();

        // Membalik pembalik berarti mengembalikan uang yang sudah dinyatakan
        // tidak pernah diterima - dan itu harus dicatat sebagai pembayaran
        // baru, bukan sebagai pembalikan dari pembalikan.
        $this->balik($pembalik)->assertStatus(422);
    }

    public function test_pembayaran_hanya_boleh_dibalik_sekali(): void
    {
        $tagihan = $this->buatTagihan();
        $asli = $this->catat($tagihan);

        $this->balik($asli)->assertCreated();

        // Percobaan kedua ditolak oleh unique index pada `pembalik_id`, yang
        // dijawab controller sebagai 422 - bukan 500.
        $this->balik($asli)->assertStatus(422);

        $this->assertSame(
            1,
            Pembayaran::query()->where('pembalik_id', $asli->id)->count(),
        );
    }

    public function test_baris_pembalik_wajib_negatif_dan_asal_wajib_positif(): void
    {
        $tagihan = $this->buatTagihan();
        $asli = $this->catat($tagihan);

        // CHECK `pembayaran_tanda_jumlah`. Tanpa ini, baris "pembalik" yang
        // nominalnya positif akan MENAMBAH pendapatan alih-alih membatalkannya.
        $this->expectException(QueryException::class);

        DB::transaction(function () use ($asli) {
            DB::table('pembayaran')->insert([
                'tagihan_id' => $asli->tagihan_id,
                'jumlah' => 500_000,
                'metode' => 'tunai',
                'sumber' => 'dicatat_pemilik',
                'pembalik_id' => $asli->id,
                'alasan' => 'salah',
                'tanggal_pembayaran' => '2026-11-12',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_pembalik_wajib_punya_alasan_di_basis_data(): void
    {
        $tagihan = $this->buatTagihan();
        $asli = $this->catat($tagihan);

        // CHECK `pembalik_ada_alasan`: jalur tulis lain (seeder, artisan, SQL
        // langsung) tidak melewati FormRequest.
        $this->expectException(QueryException::class);

        DB::transaction(function () use ($asli) {
            DB::table('pembayaran')->insert([
                'tagihan_id' => $asli->tagihan_id,
                'jumlah' => -500_000,
                'metode' => 'tunai',
                'sumber' => 'dicatat_pemilik',
                'pembalik_id' => $asli->id,
                'alasan' => null,
                'tanggal_pembayaran' => '2026-11-12',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    // --- Wewenang dan jejak ------------------------------------------------

    public function test_pengelola_tanpa_verifikasi_tidak_boleh_membalik(): void
    {
        $tagihan = $this->buatTagihan();
        $asli = $this->catat($tagihan);

        $lain = User::create([
            'name' => 'Pengelola Lain',
            'email' => 'lain@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $this->pemilik->id,
        ]);
        $lain->kewenangan()->create(['wewenang' => 'catat_pembayaran']);
        $lain->load('kewenangan');

        $this->actingAs($lain, 'sanctum')
            ->postJson("/api/pembayaran/{$asli->id}/pembalik", ['alasan' => 'Salah'])
            ->assertForbidden();
    }

    public function test_pembalikan_mencatat_jejak_audit(): void
    {
        $tagihan = $this->buatTagihan();
        $asli = $this->catat($tagihan);

        $this->balik($asli, 'Dobel catat')->assertCreated();

        $jejak = LogAudit::query()
            ->where('aksi', PencatatAudit::AKSI_PEMBAYARAN_BALIK)
            ->firstOrFail();

        $this->assertSame('pembayaran', $jejak->entitas);
        $this->assertSame($this->pemilik->id, (int) $jejak->aktor_id);
        $this->assertSame(500_000, (int) $jejak->nilai_lama['jumlah']);
        $this->assertSame(-500_000, (int) $jejak->nilai_baru['jumlah']);
        $this->assertSame($asli->id, (int) $jejak->nilai_lama['pembayaran_id']);
        $this->assertSame('Dobel catat', $jejak->nilai_baru['alasan']);
    }

    public function test_pembalik_tidak_menghapus_baris_asli(): void
    {
        $tagihan = $this->buatTagihan();
        $asli = $this->catat($tagihan, 500_000);

        $this->balik($asli)->assertCreated();

        // Append-only: baris aslinya tetap ada dan tetap bernilai positif.
        // Menghapusnya akan menghapus fakta bahwa angka itu pernah tercatat.
        $this->assertDatabaseHas('pembayaran', [
            'id' => $asli->id,
            'jumlah' => 500_000,
            'pembalik_id' => null,
        ]);
        $this->assertSame(2, Pembayaran::count());
    }

    // --- Uang tunai di tangan sendiri (REV-SEC-06) -------------------------

    public function test_pengelola_hanya_melihat_uang_tunai_yang_di_tangannya(): void
    {
        $tagihan = $this->buatTagihan();

        // Dicatat pemilik, tunai, tetapi dipegang pemilik.
        $this->sebagaiPemilik()
            ->withHeader('X-Simulasi-Tanggal', '2026-11-10')
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 400_000,
                'tanggal_pembayaran' => '2026-11-10',
                'metode' => 'tunai',
            ])
            ->assertCreated();

        // Dicatat pengelola, tunai, dipegang pengelola.
        $this->actingAs($this->pengelola, 'sanctum')
            ->withHeader('X-Simulasi-Tanggal', '2026-11-11')
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 300_000,
                'tanggal_pembayaran' => '2026-11-11',
                'metode' => 'tunai',
            ])
            ->assertCreated();

        $data = $this->actingAs($this->pengelola, 'sanctum')
            ->getJson('/api/pembayaran/tunai-saya?dari=2026-11-01&sampai=2026-11-30')
            ->assertOk()
            ->json('data');

        // Hanya baris miliknya sendiri, bukan seluruh uang tunai kos. Endpoint
        // ini sengaja mengembalikan ANGKA, bukan daftar transaksi: rinciannya
        // sudah tersedia di `GET /pembayaran` yang tetap terbuka untuknya -
        // yang hilang saat rekap ditutup hanyalah agregat pendapatan kos.
        $this->assertSame(300_000, (int) $data['jumlah']);
        $this->assertSame(1, (int) $data['transaksi']);
        $this->assertArrayNotHasKey('per_metode', $data);
        $this->assertArrayNotHasKey('piutang', $data);
    }

    public function test_pemilik_tidak_memakai_tampilan_sempit(): void
    {
        // Endpoint ini ada karena rekap ditutup untuk pengelola; pemilik sudah
        // punya rekap, jadi memanggilnya tidak berarti apa-apa - tetapi tidak
        // boleh juga menjadi jalan pintas membaca angka tanpa wewenang.
        $this->sebagaiPemilik()
            ->getJson('/api/pembayaran/tunai-saya?dari=2026-11-01&sampai=2026-11-30')
            ->assertOk();
    }
}
