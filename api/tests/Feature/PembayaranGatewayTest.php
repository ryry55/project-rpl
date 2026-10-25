<?php

namespace Tests\Feature;

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
            return $permintaan['transaction_details']['gross_amount'] === 1500000
                && str_starts_with($permintaan['transaction_details']['order_id'], 'KOS-');
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

    // --- Kelas gateway secara langsung -----------------------------------

    public function test_tanda_tangan_memakai_perbandingan_waktu_tetap(): void
    {
        $gateway = new MidtransGateway(self::KUNCI, 'client', false);

        $benar = hash('sha512', 'ORDER-1'.'200'.'1500000.00'.self::KUNCI);

        $this->assertTrue($gateway->tandaTanganSah('ORDER-1', '200', '1500000.00', $benar));
        $this->assertFalse($gateway->tandaTanganSah('ORDER-1', '200', '1500000.00', ''));
        $this->assertFalse($gateway->tandaTanganSah('ORDER-2', '200', '1500000.00', $benar));
    }

    public function test_capture_hanya_lunas_bila_fraud_status_accept(): void
    {
        $gateway = new MidtransGateway(self::KUNCI, 'client', false);

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
            'sandbox', (new MidtransGateway('k', 'c', false))->alamatSnap(),
        );
        $this->assertStringNotContainsString(
            'sandbox', (new MidtransGateway('k', 'c', true))->alamatSnap(),
        );
    }
}
