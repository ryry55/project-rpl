<?php

namespace Tests\Feature;

use App\Domain\Kebijakan\PilihanKebijakan;
use App\Domain\Pembayaran\MidtransGateway;
use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\Pembayaran;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\TransaksiGateway;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pembayaran daring lewat Midtrans (T22).
 *
 * Seluruh tes berjalan dengan kredensial **palsu**. Itu disengaja dan bukan
 * kekurangan: yang diuji di sini adalah perilaku aplikasi, bukan Midtrans.
 * `Http::fake()` menggantikan panggilan keluar, dan tanda tangan dihitung
 * dengan kunci yang sama seperti yang akan dipakai Midtrans sungguhan -
 * sehingga jalur verifikasinya benar-benar dieksekusi, bukan dilewati.
 *
 * Yang paling penting diuji di sini bukan "pembayaran berhasil", melainkan
 * tiga penolakan: tanda tangan palsu, nominal yang tidak cocok, dan notifikasi
 * ganda. Ketiganya adalah cara sebuah integrasi gateway kehilangan uang.
 */
class PembayaranGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const KUNCI = 'SB-Mid-server-KUNCI-UJI-PALSU';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.midtrans.server_key' => self::KUNCI,
            'services.midtrans.client_key' => 'SB-Mid-client-PALSU',
            'services.midtrans.is_production' => false,
            'services.midtrans.kedaluwarsa_menit' => 60,
        ]);
    }

    // --- Pembantu ---------------------------------------------------------

    private function tagihan(int $pokok = 1500000): Tagihan
    {
        $kamar = Kamar::create(['nomor' => 'A1', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'email' => 'budi@kos.test']);

        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => now()->subMonths(2)->toDateString(),
            'tarif_awal' => $pokok,
            'status' => 'aktif',
        ]);

        return Tagihan::create([
            'kontrak_id' => $kontrak->id,
            'periode' => now()->startOfMonth()->toDateString(),
            'pokok' => $pokok,
            'jatuh_tempo' => now()->addDays(5)->toDateString(),
            'status' => Tagihan::STATUS_BELUM_BAYAR,
            // Snapshot beku (R-06). Tanpa `tarif_terpakai`, CHECK di basis
            // data menolak barisnya - dan itu benar: tagihan tanpa snapshot
            // tidak bisa dipertanggungjawabkan saat tarif berubah.
            'tarif_terpakai' => ['prorata' => false, 'nilai' => $pokok],
            'terbit_pada' => now(),
        ]);
    }

    private function penghuni(): User
    {
        $penghuni = Penghuni::query()->first();

        // `firstOrCreate`, bukan `create`: tes yang memanggil ini dua kali
        // harus mendapat akun yang SAMA. Dengan `create`, panggilan kedua
        // membuat akun penghuni lain dan setiap permintaan menjadi 404 -
        // kegagalan yang terlihat seperti bug kepemilikan padahal tesnya yang
        // salah.
        return User::firstOrCreate(
            ['email' => $penghuni->email],
            [
                'name' => $penghuni->nama,
                'password' => Hash::make('password'),
                'peran' => User::PERAN_PENGHUNI,
                'penghuni_id' => $penghuni->id,
            ],
        );
    }

    private function tandaTangan(string $orderId, string $statusCode, string $gross): string
    {
        return hash('sha512', $orderId.$statusCode.$gross.self::KUNCI);
    }

    private function pemilik(): User
    {
        return User::firstOrCreate(
            ['email' => 'pemilik@kos.test'],
            ['name' => 'Pemilik', 'password' => Hash::make('password'), 'peran' => User::PERAN_PEMILIK],
        );
    }

    /**
     * Tagihan yang jatuh tempo 2026-10-15 dengan kebijakan denda 1%/hari,
     * plafon 30%, tenggang 7 hari - angka yang bisa dihitung tangan dan
     * cocok dengan `X-Simulasi-Tanggal`.
     */
    private function tagihanDenda(): Tagihan
    {
        $kamar = Kamar::create(['nomor' => 'A1', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'email' => 'budi@kos.test']);

        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-10-15',
            'tarif_awal' => 1500000,
        ]);

        return Tagihan::create([
            'kontrak_id' => $kontrak->id,
            'periode' => '2026-10-01',
            'pokok' => 1500000,
            'jatuh_tempo' => '2026-10-15',
            'status' => Tagihan::STATUS_BELUM_BAYAR,
            'tarif_terpakai' => ['prorata' => false, 'nilai' => 1500000],
            'kebijakan_terpakai' => [
                'denda' => [
                    'tipe' => PilihanKebijakan::DENDA_PERSEN_HARIAN,
                    'persen' => '1.00',
                    'plafon_persen' => '30.00',
                    'masa_tenggang' => 7,
                    'nominal' => 0,
                ],
            ],
            'terbit_pada' => now(),
        ]);
    }

    // --- Membuat transaksi ------------------------------------------------

    public function test_penghuni_mendapat_tautan_bayar(): void
    {
        Http::fake([
            '*/snap/v1/transactions' => Http::response([
                'token' => 'TOKEN-SNAP-123',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/TOKEN-SNAP-123',
            ], 201),
        ]);

        $tagihan = $this->tagihan();

        $jawaban = $this->actingAs($this->penghuni(), 'sanctum')
            ->postJson("/api/tagihan/{$tagihan->id}/bayar-daring")
            ->assertStatus(201)
            ->assertJsonPath('data.jumlah', 1500000)
            ->assertJsonPath('data.baru', true);

        $this->assertStringContainsString('midtrans.com', $jawaban->json('data.redirect_url'));
        $this->assertSame(1, TransaksiGateway::count());

        // Nominal yang dikirim ke gateway harus sama dengan sisa tagihan, dan
        // dikirim sebagai int - bukan float.
        Http::assertSent(function ($permintaan) {
            $item = $permintaan['item_details'][0] ?? null;

            return $permintaan['transaction_details']['gross_amount'] === 1500000
                && str_starts_with($permintaan['transaction_details']['order_id'], 'KOS-')
                // O-8: keterangan tagihan ikut terkirim, dan harga item kali
                // jumlahnya harus persis sama dengan gross_amount - Midtrans
                // menolak transaksi bila keduanya berbeda.
                && $item !== null
                && $permintaan['transaction_details']['gross_amount'] === $item['price'] * $item['quantity']
                && str_contains($item['name'], 'A1');
        });
    }

    public function test_membuka_dua_kali_tidak_membuat_dua_transaksi(): void
    {
        Http::fake([
            '*/snap/v1/transactions' => Http::response([
                'token' => 'TOKEN-1',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/TOKEN-1',
            ], 201),
        ]);

        $tagihan = $this->tagihan();
        $akun = $this->penghuni();

        $this->actingAs($akun, 'sanctum')
            ->postJson("/api/tagihan/{$tagihan->id}/bayar-daring")
            ->assertStatus(201);

        // Panggilan kedua memakai ulang tautan yang ada. Membuat order_id baru
        // hanya akan ditolak Midtrans, dan dua tautan untuk satu tagihan bisa
        // sama-sama dibayar.
        $this->actingAs($akun, 'sanctum')
            ->postJson("/api/tagihan/{$tagihan->id}/bayar-daring")
            ->assertStatus(200)
            ->assertJsonPath('data.baru', false);

        $this->assertSame(1, TransaksiGateway::count());
        Http::assertSentCount(1);
    }

    public function test_tanpa_kunci_gateway_menjawab_503_bukan_500(): void
    {
        config(['services.midtrans.server_key' => null]);

        $tagihan = $this->tagihan();

        $this->actingAs($this->penghuni(), 'sanctum')
            ->postJson("/api/tagihan/{$tagihan->id}/bayar-daring")
            ->assertStatus(503)
            ->assertJsonPath('kode', 'gateway_belum_siap');
    }

    public function test_penghuni_tidak_bisa_membayar_tagihan_orang_lain(): void
    {
        $tagihan = $this->tagihan();
        $lain = Penghuni::create(['nama' => 'Sari', 'email' => 'sari@kos.test']);

        $akun = User::create([
            'name' => 'Sari',
            'email' => 'sari@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $lain->id,
        ]);

        // 404, bukan 403: 403 akan memberi tahu bahwa tagihan itu ada.
        $this->actingAs($akun, 'sanctum')
            ->postJson("/api/tagihan/{$tagihan->id}/bayar-daring")
            ->assertStatus(404);
    }

    // --- Webhook ----------------------------------------------------------

    private function transaksiMenunggu(int $jumlah = 1500000): TransaksiGateway
    {
        $tagihan = $this->tagihan($jumlah);

        return TransaksiGateway::create([
            'tagihan_id' => $tagihan->id,
            'order_id' => 'KOS-'.$tagihan->id.'-20261120090000',
            'jumlah' => $jumlah,
            'status' => TransaksiGateway::STATUS_MENUNGGU,
        ]);
    }

    public function test_notifikasi_sah_mencatat_pembayaran(): void
    {
        $transaksi = $this->transaksiMenunggu();

        $this->postJson('/api/webhook/midtrans', [
            'order_id' => $transaksi->order_id,
            'status_code' => '200',
            'gross_amount' => '1500000.00',
            'transaction_status' => 'settlement',
            'transaction_id' => 'abc-123',
            'signature_key' => $this->tandaTangan($transaksi->order_id, '200', '1500000.00'),
        ])->assertStatus(200)->assertJsonPath('keadaan', 'dibayar');

        $this->assertSame(TransaksiGateway::STATUS_DIBAYAR, $transaksi->fresh()->status);
        $this->assertSame(Tagihan::STATUS_LUNAS, $transaksi->tagihan->fresh()->status);

        // Sumbernya harus `gateway_webhook`, bukan `dicatat_pemilik`: rekap
        // memisahkan uang yang diketik orang dari uang yang masuk sendiri.
        $pembayaran = $transaksi->tagihan->pembayaran()->first();
        $this->assertSame('gateway', $pembayaran->metode);
        $this->assertSame('gateway_webhook', $pembayaran->sumber);
        $this->assertNull($pembayaran->dicatat_oleh);
    }

    public function test_tanda_tangan_palsu_ditolak_401(): void
    {
        $transaksi = $this->transaksiMenunggu();

        $this->postJson('/api/webhook/midtrans', [
            'order_id' => $transaksi->order_id,
            'status_code' => '200',
            'gross_amount' => '1500000.00',
            'transaction_status' => 'settlement',
            'signature_key' => hash('sha512', 'kunci-yang-salah'),
        ])->assertStatus(401);

        // Tidak ada yang berubah. Ini inti dari seluruh pengamanan: tanpa
        // pemeriksaan tanda tangan, siapa pun yang tahu alamat endpoint bisa
        // menandai tagihan mana pun sebagai lunas.
        $this->assertSame(TransaksiGateway::STATUS_MENUNGGU, $transaksi->fresh()->status);
        $this->assertSame(Tagihan::STATUS_BELUM_BAYAR, $transaksi->tagihan->fresh()->status);
        $this->assertSame(0, $transaksi->tagihan->pembayaran()->count());
    }

    public function test_notifikasi_ganda_tidak_mencatat_dua_pembayaran(): void
    {
        $transaksi = $this->transaksiMenunggu();

        $badan = [
            'order_id' => $transaksi->order_id,
            'status_code' => '200',
            'gross_amount' => '1500000.00',
            'transaction_status' => 'settlement',
            'signature_key' => $this->tandaTangan($transaksi->order_id, '200', '1500000.00'),
        ];

        $this->postJson('/api/webhook/midtrans', $badan)->assertStatus(200);

        // Midtrans mengirim ulang sampai menerima 200, dan jaringan bisa
        // menggandakan. Notifikasi kedua harus diabaikan - bukan gagal, karena
        // jawaban selain 200 akan membuatnya diulang terus.
        $this->postJson('/api/webhook/midtrans', $badan)
            ->assertStatus(200)
            ->assertJsonPath('keadaan', 'diabaikan');

        $this->assertSame(1, $transaksi->tagihan->pembayaran()->count());
    }

    public function test_nominal_tidak_cocok_tidak_dicatat(): void
    {
        $transaksi = $this->transaksiMenunggu(1500000);

        // Notifikasi sah untuk transaksi Rp 1.000 pada tagihan Rp 1.500.000.
        // Mencatatnya akan membuat tagihan tampak "sebagian dibayar" padahal
        // uangnya bukan untuk tagihan ini.
        $this->postJson('/api/webhook/midtrans', [
            'order_id' => $transaksi->order_id,
            'status_code' => '200',
            'gross_amount' => '1000.00',
            'transaction_status' => 'settlement',
            'signature_key' => $this->tandaTangan($transaksi->order_id, '200', '1000.00'),
        ])->assertStatus(200)->assertJsonPath('keadaan', 'diabaikan');

        $this->assertSame(0, $transaksi->tagihan->pembayaran()->count());
        $this->assertSame(TransaksiGateway::STATUS_MENUNGGU, $transaksi->fresh()->status);
    }

    public function test_status_expire_menandai_transaksi_kedaluwarsa(): void
    {
        $transaksi = $this->transaksiMenunggu();

        $this->postJson('/api/webhook/midtrans', [
            'order_id' => $transaksi->order_id,
            'status_code' => '407',
            'gross_amount' => '1500000.00',
            'transaction_status' => 'expire',
            'signature_key' => $this->tandaTangan($transaksi->order_id, '407', '1500000.00'),
        ])->assertStatus(200);

        $this->assertSame(TransaksiGateway::STATUS_KEDALUWARSA, $transaksi->fresh()->status);
        $this->assertSame(0, $transaksi->tagihan->pembayaran()->count());
    }

    public function test_status_pending_tidak_mengubah_tagihan(): void
    {
        $transaksi = $this->transaksiMenunggu();

        $this->postJson('/api/webhook/midtrans', [
            'order_id' => $transaksi->order_id,
            'status_code' => '201',
            'gross_amount' => '1500000.00',
            'transaction_status' => 'pending',
            'signature_key' => $this->tandaTangan($transaksi->order_id, '201', '1500000.00'),
        ])->assertStatus(200)->assertJsonPath('keadaan', 'diabaikan');

        $this->assertSame(Tagihan::STATUS_BELUM_BAYAR, $transaksi->tagihan->fresh()->status);
    }

    public function test_settlement_setelah_expire_tetap_dicatat(): void
    {
        $transaksi = $this->transaksiMenunggu();

        // Penghuni membayar di detik terakhir; `expire` dan `settlement` bisa
        // tiba dalam urutan apa pun. Yang penting uangnya tetap tercatat.
        $this->postJson('/api/webhook/midtrans', [
            'order_id' => $transaksi->order_id,
            'status_code' => '407',
            'gross_amount' => '1500000.00',
            'transaction_status' => 'expire',
            'signature_key' => $this->tandaTangan($transaksi->order_id, '407', '1500000.00'),
        ])->assertStatus(200);

        $this->postJson('/api/webhook/midtrans', [
            'order_id' => $transaksi->order_id,
            'status_code' => '200',
            'gross_amount' => '1500000.00',
            'transaction_status' => 'settlement',
            'signature_key' => $this->tandaTangan($transaksi->order_id, '200', '1500000.00'),
        ])->assertStatus(200)->assertJsonPath('keadaan', 'dibayar');

        $this->assertSame(1, $transaksi->tagihan->pembayaran()->count());
        $this->assertSame(Tagihan::STATUS_LUNAS, $transaksi->tagihan->fresh()->status);
    }

    public function test_basis_data_menolak_dua_pembayaran_untuk_satu_order_id(): void
    {
        $transaksi = $this->transaksiMenunggu();

        $this->postJson('/api/webhook/midtrans', [
            'order_id' => $transaksi->order_id,
            'status_code' => '200',
            'gross_amount' => '1500000.00',
            'transaction_status' => 'settlement',
            'signature_key' => $this->tandaTangan($transaksi->order_id, '200', '1500000.00'),
        ])->assertStatus(200);

        // Pemeriksaan status di kode punya celah balapan: dua notifikasi yang
        // tiba bersamaan sama-sama membaca `menunggu`. Yang menutupnya adalah
        // indeks unik parsial pada `pembayaran.referensi`. Diuji langsung di
        // sini supaya kalau indeksnya hilang, tesnya gagal - bukan hanya
        // catatan di dokumen.
        $this->expectException(QueryException::class);

        Pembayaran::create([
            'tagihan_id' => $transaksi->tagihan_id,
            'jumlah' => 1500000,
            'tanggal_pembayaran' => now()->toDateString(),
            'metode' => 'gateway',
            'sumber' => 'gateway_webhook',
            'referensi' => $transaksi->order_id,
        ]);
    }

    public function test_order_id_tidak_dikenal_diabaikan_bukan_500(): void
    {
        // Tanda tangan sah, tetapi order_id tidak ada di database - bisa
        // terjadi setelah pemulihan backup. Menjawab 500 akan membuat gateway
        // mengulang selamanya.
        $this->postJson('/api/webhook/midtrans', [
            'order_id' => 'KOS-99999-20260101000000',
            'status_code' => '200',
            'gross_amount' => '1500000.00',
            'transaction_status' => 'settlement',
            'signature_key' => $this->tandaTangan('KOS-99999-20260101000000', '200', '1500000.00'),
        ])->assertStatus(200)->assertJsonPath('keadaan', 'diabaikan');
    }

    // --- Sisa kegagalan, pembatalan, dan denda beku (Fase 8) ---------------

    /**
     * Temuan #1: `mulai()` yang gagal setelah baris tersimpan (mis. timeout
     * gateway) meninggalkan baris `menunggu` tanpa tautan. Baris itu tidak
     * bisa dipakai siapa pun, tetapi indeks unik satu-menunggu-per-tagihan
     * menghalangi percobaan berikutnya - penghuni terkunci sampai barisnya
     * dibersihkan. Percobaan kedua harus mendapat tautan baru.
     */
    public function test_sisa_menunggu_tanpa_tautan_dibersihkan_bukan_mengunci(): void
    {
        Http::fake([
            '*/snap/v1/transactions' => Http::response([
                'token' => 'TOKEN-BARU',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/TOKEN-BARU',
            ], 201),
        ]);

        $tagihan = $this->tagihan();

        // Keadaan setelah kegagalan parsial: baris tersimpan, tautan tidak
        // pernah didapat. Tidak ada yang bisa membayar baris ini.
        $sisa = TransaksiGateway::create([
            'tagihan_id' => $tagihan->id,
            'order_id' => 'KOS-'.$tagihan->id.'-20260101000000',
            'jumlah' => 1500000,
            'status' => TransaksiGateway::STATUS_MENUNGGU,
        ]);

        $this->actingAs($this->penghuni(), 'sanctum')
            ->postJson("/api/tagihan/{$tagihan->id}/bayar-daring")
            ->assertStatus(201)
            ->assertJsonPath('data.baru', true)
            ->assertJsonPath('data.redirect_url', 'https://app.sandbox.midtrans.com/snap/v4/redirection/TOKEN-BARU');

        // Baris sisa hilang; yang tersisa hanya transaksi yang punya tautan.
        $this->assertNull($sisa->fresh());
        $this->assertSame(1, TransaksiGateway::count());
        $this->assertNotNull(TransaksiGateway::first()->redirect_url);
    }

    /**
     * K-19 (temuan #2a): membatalkan tagihan mematikan tautan bayar yang
     * masih terbuka. Tanpa ini, tagihan batal tetap bisa dibayar lewat
     * tautan yang iseng masih hidup.
     */
    public function test_membatalkan_tagihan_mematikan_tautan_gateway_yang_masih_terbuka(): void
    {
        $tagihan = $this->tagihan();

        $transaksi = TransaksiGateway::create([
            'tagihan_id' => $tagihan->id,
            'order_id' => 'KOS-'.$tagihan->id.'-20260101000000',
            'jumlah' => 1500000,
            'status' => TransaksiGateway::STATUS_MENUNGGU,
            'token' => 'TOKEN-LAMA',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/TOKEN-LAMA',
        ]);

        $this->actingAs($this->pemilik(), 'sanctum')
            ->postJson("/api/tagihan/{$tagihan->id}/batal", ['alasan' => 'salah terbit untuk periode ini'])
            ->assertOk();

        $this->assertSame(TransaksiGateway::STATUS_DIBATALKAN, $transaksi->fresh()->status);
    }

    /**
     * K-19 (temuan #2b): uang yang terlanjur masuk lewat tautan tagihan
     * yang sudah dibatalkan tidak boleh menguap. Ia dicatat sebagai
     * pembayaran berlabel perlu tindakan - rekap tidak boleh bohong
     * terhadap rekening Midtrans; refund diurus di luar aplikasi.
     */
    public function test_uang_yang_masuk_setelah_pembatalan_tercatat_berlabel_perlu_tindakan(): void
    {
        $tagihan = $this->tagihan();

        $transaksi = TransaksiGateway::create([
            'tagihan_id' => $tagihan->id,
            'order_id' => 'KOS-'.$tagihan->id.'-20260101000000',
            'jumlah' => 1500000,
            'status' => TransaksiGateway::STATUS_MENUNGGU,
            'token' => 'TOKEN-LAMA',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/TOKEN-LAMA',
        ]);

        $this->actingAs($this->pemilik(), 'sanctum')
            ->postJson("/api/tagihan/{$tagihan->id}/batal", ['alasan' => 'salah terbit untuk periode ini'])
            ->assertOk();

        // Pembayaran sah: tanda tangan benar, nominal sama persis.
        $this->postJson('/api/webhook/midtrans', [
            'order_id' => $transaksi->order_id,
            'status_code' => '200',
            'gross_amount' => '1500000.00',
            'transaction_status' => 'settlement',
            'transaction_id' => 'abc-123',
            'signature_key' => $this->tandaTangan($transaksi->order_id, '200', '1500000.00'),
        ])->assertStatus(200);

        $this->assertSame(1, Pembayaran::count());

        $pembayaran = Pembayaran::first();
        $this->assertSame(1500000, (int) $pembayaran->jumlah);
        $this->assertStringContainsStringIgnoringCase('perlu tindakan', (string) $pembayaran->catatan);

        // Tagihan tetap batal: uang yang terlanjur masuk tidak
        // membangkitkan tagihan yang sudah dibatalkan.
        $this->assertSame(Tagihan::STATUS_DIBATALKAN, $tagihan->fresh()->status);
        $this->assertSame(TransaksiGateway::STATUS_DIBAYAR, $transaksi->fresh()->status);
    }

    /**
     * K-20 (temuan #7): nominal tautan dibekukan saat dibuat. Penghuni
     * yang membayar sebesar nominal itu penuh dianggap LUNAS - denda yang
     * berjalan antara tautan dibuat dan dibayar dimaafkan otomatis,
     * `total_final` dibekukan pada pokok + denda saat tautan dibuat.
     */
    public function test_membayar_nominal_tautan_penuh_membuat_lunas_walau_denda_berjalan(): void
    {
        Http::fake([
            '*/snap/v1/transactions' => Http::response([
                'token' => 'TOKEN-BEKU',
                'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/TOKEN-BEKU',
            ], 201),
        ]);

        $tagihan = $this->tagihanDenda();

        // 2026-11-01: terlambat 17 hari, tenggang 7 -> denda 10 hari
        // x 1% x 1.500.000 = 150.000. Tautan dibekukan pada 1.650.000.
        $this->actingAs($this->penghuni(), 'sanctum')
            ->postJson("/api/tagihan/{$tagihan->id}/bayar-daring", [], ['X-Simulasi-Tanggal' => '2026-11-01'])
            ->assertStatus(201)
            ->assertJsonPath('data.jumlah', 1650000);

        $transaksi = TransaksiGateway::first();

        // Dibayar tiga hari kemudian: denda sudah tumbuh 45.000, tetapi
        // yang diminta ke penghuni adalah nominal beku - dan membayarnya
        // penuh berarti lunas.
        $this->postJson('/api/webhook/midtrans', [
            'order_id' => $transaksi->order_id,
            'status_code' => '200',
            'gross_amount' => '1650000.00',
            'transaction_status' => 'settlement',
            'transaction_id' => 'abc-123',
            'signature_key' => $this->tandaTangan($transaksi->order_id, '200', '1650000.00'),
        ], ['X-Simulasi-Tanggal' => '2026-11-04'])->assertStatus(200);

        $tagihan = $tagihan->fresh();
        $this->assertSame(Tagihan::STATUS_LUNAS, $tagihan->status);
        $this->assertSame(1650000, (int) $tagihan->total_final);
        $this->assertSame(TransaksiGateway::STATUS_DIBAYAR, $transaksi->fresh()->status);
    }

    // --- Kelas gateway secara langsung -----------------------------------

    public function test_tanda_tangan_memakai_perbandingan_waktu_tetap(): void
    {
        $gateway = new MidtransGateway(self::KUNCI, false);

        $benar = hash('sha512', 'ORDER-1'.'200'.'1500000.00'.self::KUNCI);

        $this->assertTrue($gateway->tandaTanganSah('ORDER-1', '200', '1500000.00', $benar));
        $this->assertFalse($gateway->tandaTanganSah('ORDER-1', '200', '1500000.00', ''));
        $this->assertFalse($gateway->tandaTanganSah('ORDER-2', '200', '1500000.00', $benar));
    }

    public function test_capture_hanya_lunas_bila_fraud_status_accept(): void
    {
        $gateway = new MidtransGateway(self::KUNCI, false);

        $this->assertTrue($gateway->statusBerartiDibayar('settlement', null));
        $this->assertTrue($gateway->statusBerartiDibayar('capture', 'accept'));
        // `challenge` berarti bank masih menahan. Memperlakukannya sebagai
        // lunas akan memberi penghuni status "sudah bayar" untuk uang yang
        // belum tentu masuk.
        $this->assertFalse($gateway->statusBerartiDibayar('capture', 'challenge'));
        $this->assertFalse($gateway->statusBerartiDibayar('pending', null));
    }

    public function test_alamat_snap_mengikuti_lingkungan(): void
    {
        $this->assertStringContainsString(
            'sandbox', (new MidtransGateway('k', false))->alamatSnap(),
        );
        $this->assertStringNotContainsString(
            'sandbox', (new MidtransGateway('k', true))->alamatSnap(),
        );
    }
}
