<?php

namespace Tests\Feature;

use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\LampiranKerusakan;
use App\Models\LaporanKerusakan;
use App\Models\Penghuni;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * K-15 - penghuni melaporkan kerusakan, dengan foto.
 *
 * Yang diuji bukan sekadar "unggahan berhasil". Empat hal yang bisa salah tanpa
 * satu pun galat terlihat:
 *
 *   1. **`kamar_id` diambil server dari kontrak aktif.** Kalau dibaca dari
 *      request, penghuni bisa menandai kamar orang lain sebagai `perbaikan` -
 *      mengubah data yang dilihat pemilik, bukan sekadar mengirim laporan.
 *   2. **Foto benar-benar tersimpan utuh.** Kolom `bytea` yang ditulis sebagai
 *      parameter teks biasa tersimpan sebagai 0 byte tanpa galat apa pun.
 *   3. **Foto hanya bisa dibuka lewat tautan bertanda tangan.** Rute itu di
 *      luar `auth:sanctum` karena `<img>` tidak bisa mengirim Bearer; yang
 *      menjaga harus tanda tangannya, bukan ketiadaan rute.
 *   4. **Penghuni hanya melihat laporannya sendiri.** Foto kamar adalah data
 *      pribadi orang yang memotretnya.
 */
class LampiranKerusakanTest extends TestCase
{
    use RefreshDatabase;

    private User $pemilik;

    private User $akunPenghuni;

    private Penghuni $penghuni;

    private Kamar $kamar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pemilik = User::create([
            'name' => 'Pemilik Kos',
            'email' => 'pemilik@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PEMILIK,
        ]);

        $this->penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'email' => 'budi@kos.test']);

        $this->akunPenghuni = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $this->penghuni->id,
        ]);

        $this->kamar = Kamar::create(['nomor' => 'A1', 'tipe' => 'Standar']);
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    private function sebagaiPenghuni()
    {
        return $this->actingAs($this->akunPenghuni, 'sanctum');
    }

    private function kontrakAktif(?Kamar $kamar = null): Kontrak
    {
        $kamar ??= $this->kamar;

        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $this->penghuni->id,
            'tgl_masuk' => '2026-10-01',
            'tarif_awal' => 1500000,
            'status' => 'aktif',
        ]);

        $kamar->update(['status_huni' => 'terisi']);

        return $kontrak;
    }

    /**
     * PNG 1x1 sungguhan, bukan byte acak.
     *
     * `getimagesize` harus benar-benar mengenalinya sebagai gambar; byte acak
     * akan ditolak dan tesnya tidak membuktikan apa pun tentang jalur unggah
     * yang sebenarnya.
     */
    private function pngContoh(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    private function berkasPng(string $nama = 'foto.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nama, $this->pngContoh());
    }

    private function lapor(array $tambahan = [])
    {
        return $this->sebagaiPenghuni()->post(
            '/api/penghuni/laporan-kerusakan',
            array_merge(['deskripsi' => 'Keran kamar mandi bocor'], $tambahan),
            ['Accept' => 'application/json'],
        );
    }

    // --- Melapor dari portal ----------------------------------------------

    public function test_penghuni_melaporkan_kerusakan_kamarnya_sendiri(): void
    {
        $this->kontrakAktif();

        $this->lapor()
            ->assertCreated()
            ->assertJsonPath('data.kamar.nomor', 'A1')
            ->assertJsonPath('data.deskripsi', 'Keran kamar mandi bocor')
            ->assertJsonPath('data.pelapor', 'Budi Santoso');

        $this->assertSame('perbaikan', $this->kamar->fresh()->status_huni);
    }

    public function test_kamar_diambil_dari_kontrak_bukan_dari_request(): void
    {
        $this->kontrakAktif();

        $kamarLain = Kamar::create(['nomor' => 'B2', 'tipe' => 'Standar']);

        $this->lapor(['kamar_id' => $kamarLain->id])
            ->assertCreated()
            ->assertJsonPath('data.kamar.nomor', 'A1');

        // Kamar yang dikirim di badan permintaan tidak tersentuh.
        $this->assertSame('kosong', $kamarLain->fresh()->status_huni);
    }

    public function test_penghuni_tanpa_kontrak_aktif_ditolak(): void
    {
        $this->lapor()
            ->assertStatus(422)
            ->assertJsonValidationErrors('kamar_id');

        $this->assertSame(0, LaporanKerusakan::count());
    }

    public function test_laporan_ganda_dari_penghuni_ditolak(): void
    {
        $this->kontrakAktif();

        $this->lapor()->assertCreated();
        $this->lapor()->assertStatus(422);

        $this->assertSame(1, LaporanKerusakan::count());
    }

    public function test_penghuni_hanya_melihat_laporannya_sendiri(): void
    {
        $this->kontrakAktif();
        $this->lapor()->assertCreated();

        // Penghuni kedua di kamar lain, dengan laporannya sendiri.
        $penghuniLain = Penghuni::create(['nama' => 'Sari', 'email' => 'sari@kos.test']);
        $akunLain = User::create([
            'name' => 'Sari',
            'email' => 'sari@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $penghuniLain->id,
        ]);

        $kamarLain = Kamar::create(['nomor' => 'B2', 'tipe' => 'Standar']);
        Kontrak::create([
            'kamar_id' => $kamarLain->id,
            'penghuni_id' => $penghuniLain->id,
            'tgl_masuk' => '2026-10-01',
            'tarif_awal' => 1500000,
            'status' => 'aktif',
        ]);

        $this->actingAs($akunLain, 'sanctum')
            ->post('/api/penghuni/laporan-kerusakan', ['deskripsi' => 'Lampu mati'], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->sebagaiPenghuni()
            ->getJson('/api/penghuni/laporan-kerusakan')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kamar.nomor', 'A1');
    }

    // --- Foto --------------------------------------------------------------

    public function test_foto_tersimpan_utuh_dan_bertanda_tangan(): void
    {
        $this->kontrakAktif();

        $this->lapor(['foto' => [$this->berkasPng('bocor.png')]])
            ->assertCreated()
            ->assertJsonCount(1, 'data.foto')
            ->assertJsonPath('data.foto.0.mime', 'image/png')
            ->assertJsonPath('data.foto.0.nama_asli', 'bocor.png');

        $lampiran = LampiranKerusakan::firstOrFail();

        // Ukuran yang dicatat adalah ukuran isi yang benar-benar tersimpan.
        $this->assertSame(strlen($this->pngContoh()), $lampiran->ukuran);

        $url = $lampiran->ringkas()['url'];
        $this->assertStringContainsString('signature=', $url);

        // Tanpa token: yang menjaga adalah tanda tangannya.
        $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_lebih_dari_tiga_foto_ditolak(): void
    {
        $this->kontrakAktif();

        $this->lapor([
            'foto' => [
                $this->berkasPng('1.png'),
                $this->berkasPng('2.png'),
                $this->berkasPng('3.png'),
                $this->berkasPng('4.png'),
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('foto');

        $this->assertSame(0, LaporanKerusakan::count());
    }

    public function test_berkas_bukan_gambar_ditolak(): void
    {
        $this->kontrakAktif();

        $this->lapor([
            'foto' => [UploadedFile::fake()->create('dokumen.pdf', 10, 'application/pdf')],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('foto.0');

        $this->assertSame(0, LaporanKerusakan::count());
    }

    public function test_foto_lebih_dari_lima_mb_ditolak(): void
    {
        $this->kontrakAktif();

        // PNG sungguhan yang dipanjangkan melewati batas: `getimagesize` tetap
        // mengenalinya sebagai gambar, jadi yang diuji benar-benar batas ukuran
        // - bukan aturan "harus gambar".
        $besar = UploadedFile::fake()->createWithContent(
            'besar.png',
            $this->pngContoh().str_repeat("\0", LampiranKerusakan::BATAS_UKURAN + 1024),
        );

        $this->lapor(['foto' => [$besar]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('foto.0');

        $this->assertSame(0, LaporanKerusakan::count());
    }

    public function test_tautan_tanpa_tanda_tangan_ditolak(): void
    {
        $this->kontrakAktif();
        $this->lapor(['foto' => [$this->berkasPng()]])->assertCreated();

        $lampiran = LampiranKerusakan::firstOrFail();

        $this->getJson('/api/lampiran-kerusakan/'.$lampiran->id)->assertForbidden();
    }

    public function test_tautan_kedaluwarsa_ditolak(): void
    {
        $this->kontrakAktif();
        $this->lapor(['foto' => [$this->berkasPng()]])->assertCreated();

        $url = LampiranKerusakan::firstOrFail()->ringkas()['url'];

        $this->travel(31)->minutes();

        $this->get($url)->assertForbidden();
    }

    public function test_pemilik_melihat_foto_pada_daftar_kamar(): void
    {
        $this->kontrakAktif();
        $this->lapor(['foto' => [$this->berkasPng()]])->assertCreated();

        $this->sebagaiPemilik()
            ->getJson('/api/kamar')
            ->assertOk()
            ->assertJsonPath('data.0.laporan_terbuka.foto.0.mime', 'image/png');
    }
}
