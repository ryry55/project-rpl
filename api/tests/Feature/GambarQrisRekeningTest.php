<?php

namespace Tests\Feature;

use App\Domain\Rekening\PenyimpanGambarQris;
use App\Models\LogAudit;
use App\Models\Penghuni;
use App\Models\Rekening;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\MenyiapkanKebijakan;
use Tests\TestCase;

/**
 * Gambar QRIS pada rekening tujuan pembayaran.
 *
 * Ini utang teknis terakhir yang ditutup: sampai fase terakhir, `jenis: qris`
 * hanya menyimpan teks. Padahal QRIS statis **adalah** gambarnya - nomor
 * merchant tidak bisa dipindai, dan mengetik ulang nomor tidak membayar apa pun.
 *
 * Yang diuji di sini bukan "berkasnya tersimpan", melainkan lima hal yang
 * membuat penyimpanan ini aman dan benar:
 *
 *   1. **Isinya utuh.** Kolomnya `bytea`, dan menulisnya dengan cara yang salah
 *      menyimpan **0 byte tanpa galat** - lihat
 *      `test_gambar_tersimpan_utuh_byte_demi_byte`.
 *   2. **Yang diperiksa isinya, bukan namanya.** Berkas bernama `.png` yang
 *      isinya PDF harus ditolak.
 *   3. **SVG ditolak.** Ia dokumen yang bisa memuat skrip, dan disajikan dari
 *      origin aplikasi.
 *   4. **Penghuni hanya melihat gambar rekening aktif.** QRIS lama yang sudah
 *      tidak dipakai tidak boleh bisa dipindai.
 *   5. **Isinya tidak pernah bocor ke JSON.** Resource hanya mengirim penanda
 *      dan URL; `bytea` tidak boleh ikut ter-encode.
 */
class GambarQrisRekeningTest extends TestCase
{
    use MenyiapkanKebijakan;
    use RefreshDatabase;

    private User $pemilik;

    private User $pengelola;

    private User $penghuni;

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
        $this->pengelola->load('kewenangan');

        $this->penghuni = User::create([
            'name' => 'Budi',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
        ]);

        $this->siapkanKebijakanDefault();
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    /**
     * PNG 1x1 sungguhan, bukan byte acak.
     *
     * Dipakai supaya `getimagesize` benar-benar mengenalinya sebagai gambar -
     * byte acak akan ditolak, dan tes yang memakai byte acak tidak membuktikan
     * apa pun tentang jalur unggah yang sebenarnya.
     */
    private function pngContoh(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    private function berkasPng(string $nama = 'qris.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nama, $this->pngContoh());
    }

    private function rekeningQris(array $ubah = []): Rekening
    {
        return Rekening::create(array_merge([
            'jenis' => Rekening::JENIS_QRIS,
            'nama' => 'QRIS BCA',
            'aktif' => true,
        ], $ubah));
    }

    // --- Isi tersimpan utuh ------------------------------------------------

    /**
     * Inti berkas ini: byte yang tersimpan harus identik dengan yang dikirim.
     *
     * Kolom `bytea` yang ditulis sebagai parameter teks biasa tersimpan sebagai
     * **0 byte tanpa galat apa pun**. Tes ini ada supaya kesalahan itu tidak
     * pernah bisa kembali tanpa ketahuan: `gambar_ukuran` bisa saja benar
     * sementara isinya kosong, jadi yang diperiksa adalah panjang isinya.
     */
    public function test_gambar_tersimpan_utuh_byte_demi_byte(): void
    {
        $png = $this->pngContoh();

        $respons = $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS BCA',
                'gambar' => $this->berkasPng(),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $id = $respons->json('data.id');

        // Dibaca lewat jalur yang sama dengan yang dipakai endpoint penyaji.
        $isi = app(PenyimpanGambarQris::class)->isi(Rekening::find($id));

        $this->assertNotNull($isi, 'gambar tidak tersimpan');
        $this->assertSame(strlen($png), strlen($isi), 'panjang gambar tidak sama');
        $this->assertSame($png, $isi, 'isi gambar tidak identik byte demi byte');
        $this->assertNotSame('', $isi, 'gambar tersimpan sebagai 0 byte');
    }

    public function test_ukuran_dan_mime_ikut_tersimpan(): void
    {
        $png = $this->pngContoh();

        $respons = $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS BCA',
                'gambar' => $this->berkasPng(),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.punya_gambar', true)
            ->assertJsonPath('data.gambar_mime', 'image/png');

        $this->assertSame(strlen($png), $respons->json('data.gambar_ukuran'));
    }

    // --- Yang diperiksa isinya, bukan namanya ------------------------------

    public function test_berkas_bernama_png_tetapi_bukan_gambar_ditolak(): void
    {
        // Nama berkas dikendalikan pengunggah; isinya tidak. Berkas PDF yang
        // dinamai ".png" harus ditolak, dan pesannya harus bisa dibaca.
        $bukanGambar = UploadedFile::fake()->createWithContent(
            'qris.png',
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n"
        );

        $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS Palsu',
                'gambar' => $bukanGambar,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gambar');

        // Transaksi digulung balik: rekening setengah jadi tidak tertinggal.
        $this->assertDatabaseMissing('rekening', ['nama' => 'QRIS Palsu']);
    }

    public function test_svg_ditolak(): void
    {
        // SVG adalah dokumen yang bisa memuat skrip. Menyajikannya kembali dari
        // origin aplikasi membuat satu unggahan menjadi jalan menjalankan kode
        // di peramban penghuni.
        $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">
          <script>alert('xss')</script>
        </svg>
        SVG;

        $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS SVG',
                'gambar' => UploadedFile::fake()->createWithContent('qris.svg', $svg),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gambar');
    }

    public function test_gambar_lebih_dari_dua_megabita_ditolak(): void
    {
        // Batas di lapisan validasi, supaya pesannya menyebut MB dan bukan
        // "SQLSTATE 22001".
        $besar = UploadedFile::fake()->create('qris.png', 3000, 'image/png');

        $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS Besar',
                'gambar' => $besar,
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gambar');
    }

    // --- Hanya untuk QRIS --------------------------------------------------

    public function test_gambar_pada_rekening_transfer_ditolak(): void
    {
        // Gambar pada rekening transfer akan disajikan portal sebagai "cara
        // membayar" yang tidak berlaku.
        $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'transfer',
                'nama' => 'BCA',
                'nomor' => '1234567890',
                'gambar' => $this->berkasPng(),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gambar');

        $this->assertDatabaseMissing('rekening', ['nama' => 'BCA']);
    }

    // --- Penyajian ---------------------------------------------------------

    public function test_gambar_disajikan_dengan_tipe_yang_benar(): void
    {
        $png = $this->pngContoh();

        $respons = $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS BCA',
                'gambar' => $this->berkasPng(),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $id = $respons->json('data.id');

        $saji = $this->sebagaiPemilik()->get("/api/rekening/{$id}/gambar");

        $saji->assertOk();
        $this->assertSame('image/png', $saji->headers->get('Content-Type'));
        $this->assertSame($png, $saji->getContent());

        // `nosniff` yang menutup jalan "peramban menebak sendiri lalu
        // memperlakukannya sebagai HTML".
        $this->assertSame('nosniff', $saji->headers->get('X-Content-Type-Options'));

        // Middleware `TanpaCache` hanya menyentuh jawaban JSON. Bila ia ikut
        // menimpa gambar, QRIS diunduh ulang setiap kali panel dibuka.
        $this->assertStringContainsString('max-age=300', (string) $saji->headers->get('Cache-Control'));
    }

    public function test_gambar_tanpa_isi_menghasilkan_404(): void
    {
        $rekening = $this->rekeningQris();

        $this->sebagaiPemilik()
            ->get("/api/rekening/{$rekening->id}/gambar")
            ->assertNotFound();
    }

    public function test_resource_tidak_pernah_mengirim_isi_gambar(): void
    {
        // `bytea` yang ikut ter-encode akan mengirim ratusan kilobyte ke setiap
        // layar daftar, dan JSON-nya bukan tempat untuk byte biner.
        $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS BCA',
                'gambar' => $this->berkasPng(),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $respons = $this->sebagaiPemilik()->getJson('/api/rekening')->assertOk();

        $baris = $respons->json('data.0');
        $this->assertArrayNotHasKey('gambar', $baris);
        $this->assertTrue($baris['punya_gambar']);
        $this->assertNotNull($baris['gambar_path']);
    }

    public function test_url_gambar_null_saat_tidak_ada_gambar(): void
    {
        // URL yang menunjuk 404 akan menampilkan ikon rusak di <img>, bukan
        // ruang kosong. `null` membuat layar bisa memutuskan tidak merender.
        $this->rekeningQris();

        $this->sebagaiPemilik()
            ->getJson('/api/rekening')
            ->assertOk()
            ->assertJsonPath('data.0.punya_gambar', false)
            ->assertJsonPath('data.0.gambar_path', null);
    }

    // --- Penghuni hanya melihat yang aktif ---------------------------------

    public function test_penghuni_tidak_bisa_membuka_gambar_rekening_nonaktif(): void
    {
        $respons = $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS Lama',
                'aktif' => false,
                'gambar' => $this->berkasPng(),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $id = $respons->json('data.id');

        // 404, bukan 403: penghuni tidak berhak tahu rekening lama itu ada.
        $this->actingAs($this->penghuni, 'sanctum')
            ->get("/api/rekening/{$id}/gambar")
            ->assertNotFound();
    }

    public function test_penghuni_bisa_membuka_gambar_rekening_aktif(): void
    {
        $respons = $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS Aktif',
                'gambar' => $this->berkasPng(),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $id = $respons->json('data.id');

        $this->actingAs($this->penghuni, 'sanctum')
            ->get("/api/rekening/{$id}/gambar")
            ->assertOk();
    }

    public function test_portal_penghuni_mengirim_url_gambar(): void
    {
        $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS Aktif',
                'gambar' => $this->berkasPng(),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->actingAs($this->penghuni, 'sanctum')
            ->getJson('/api/penghuni/rekening')
            ->assertOk()
            ->assertJsonPath('data.0.punya_gambar', true)
            ->assertJsonPath('data.0.jenis', 'qris');
    }

    public function test_tanpa_login_ditolak(): void
    {
        $rekening = $this->rekeningQris();

        $this->getJson("/api/rekening/{$rekening->id}/gambar")->assertUnauthorized();
    }

    // --- Mengganti dan membuang -------------------------------------------

    public function test_gambar_bisa_diganti_lewat_ubah(): void
    {
        $respons = $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS BCA',
                'gambar' => $this->berkasPng(),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $id = $respons->json('data.id');

        // PNG lain: 1x1 juga, tetapi isi byte-nya berbeda (warna lain).
        $pngBaru = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );

        $this->sebagaiPemilik()
            ->post("/api/rekening/{$id}", [
                '_method' => 'PUT',
                'nama' => 'QRIS BCA',
                'gambar' => UploadedFile::fake()->createWithContent('baru.png', $pngBaru),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $isi = app(PenyimpanGambarQris::class)->isi(Rekening::find($id));
        $this->assertSame($pngBaru, $isi);
    }

    public function test_hapus_gambar_mengosongkan_tanpa_menghapus_rekening(): void
    {
        $respons = $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS BCA',
                'gambar' => $this->berkasPng(),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $id = $respons->json('data.id');

        $this->sebagaiPemilik()
            ->post("/api/rekening/{$id}", [
                '_method' => 'PUT',
                'nama' => 'QRIS BCA',
                'hapus_gambar' => true,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.punya_gambar', false);

        // Rekeningnya tetap ada: yang dibuang gambarnya, bukan tujuannya.
        $this->assertDatabaseHas('rekening', ['id' => $id, 'nama' => 'QRIS BCA']);
        $this->assertNull(app(PenyimpanGambarQris::class)->isi(Rekening::find($id)));
    }

    // --- Jejak audit -------------------------------------------------------

    public function test_jejak_audit_menyebut_gambar_ada_tanpa_menyalin_isinya(): void
    {
        $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS BCA',
                'gambar' => $this->berkasPng(),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $log = LogAudit::query()
            ->where('aksi', 'rekening.tambah')
            ->first();

        $this->assertNotNull($log);
        $this->assertTrue($log->nilai_baru['punya_gambar']);
        $this->assertSame('image/png', $log->nilai_baru['gambar_mime']);

        // Isi gambarnya TIDAK boleh ikut: log dibaca manusia dan disimpan lama.
        $this->assertArrayNotHasKey('gambar', $log->nilai_baru);
    }

    // --- Pengelola ---------------------------------------------------------

    public function test_pengelola_tidak_boleh_mengunggah_gambar(): void
    {
        // Menulis rekening adalah `peran:pemilik`, bukan wewenang: pengelola
        // yang bisa mengganti tujuan pembayaran bisa mengarahkan setoran
        // penghuni ke rekeningnya sendiri. Gambar QRIS adalah tujuan pembayaran
        // itu sendiri, jadi aturannya sama.
        $this->actingAs($this->pengelola, 'sanctum')
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS Sari',
                'gambar' => $this->berkasPng(),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertDatabaseMissing('rekening', ['nama' => 'QRIS Sari']);
    }

    public function test_pengelola_boleh_melihat_gambar(): void
    {
        $respons = $this->sebagaiPemilik()
            ->post('/api/rekening', [
                'jenis' => 'qris',
                'nama' => 'QRIS BCA',
                'gambar' => $this->berkasPng(),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $id = $respons->json('data.id');

        $this->actingAs($this->pengelola, 'sanctum')
            ->get("/api/rekening/{$id}/gambar")
            ->assertOk();
    }
}
