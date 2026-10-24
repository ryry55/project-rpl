<?php

namespace Tests\Feature;

use App\Domain\Akses\Wewenang;
use App\Domain\Audit\PencatatAudit;
use App\Models\Fasilitas;
use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\LogAudit;
use App\Models\PengelolaKewenangan;
use App\Models\Penghuni;
use App\Models\TarifSewa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * REV-DAT-05 - data induk berjejak.
 *
 * Sebelum revisi ini, `kamar`, `penghuni`, `kontrak`, `fasilitas`, dan
 * `aturan_kos` bisa diubah tanpa meninggalkan satu pun catatan. Itu janggal
 * justru karena kelimanya adalah data yang paling menentukan: nomor kamar
 * muncul di tagihan, tarif menempel padanya, dan aturan dipakai menegur
 * penghuni. Pertanyaan "siapa yang mengganti ini, dan sebelumnya apa" tidak
 * punya jawaban sama sekali.
 *
 * **Yang diuji bukan "ada baris di log_audit"** - itu terlalu mudah dipenuhi
 * dengan mencatat semua hal setiap kali. Yang diuji:
 *
 *   1. Aksi yang tercatat memang aksi yang terjadi, dengan entitas dan id yang
 *      benar.
 *   2. `nilai_lama` dan `nilai_baru` hanya memuat kolom yang **benar-benar
 *      berubah**. Kalau seluruh baris disalin setiap kali, pembaca berhenti
 *      membedakan mana yang berubah dan lognya jadi tidak terbaca.
 *   3. Menyimpan tanpa mengubah apa pun **tidak** menghasilkan baris. Tombol
 *      simpan yang ditekan dua kali tidak boleh mengotori jejak.
 *   4. Pelakunya tercatat sebagai dirinya, bukan sebagai "sistem".
 */
class JejakDataIndukTest extends TestCase
{
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

        // Pengelola dipakai untuk membuktikan jejaknya mencatat ORANG YANG
        // BERTINDAK, bukan pemilik yang kebetulan punya kos ini. Jejak yang
        // selalu menyebut pemilik tidak menjawab "siapa" sama sekali.
        $this->pengelola = User::create([
            'name' => 'Sari Pengelola',
            'email' => 'pengelola@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $this->pemilik->id,
        ]);

        PengelolaKewenangan::firstOrCreate([
            'user_id' => $this->pengelola->id,
            'wewenang' => Wewenang::KELOLA_KAMAR,
        ]);

        PengelolaKewenangan::firstOrCreate([
            'user_id' => $this->pengelola->id,
            'wewenang' => Wewenang::KELOLA_PENGHUNI,
        ]);
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    private function sebagaiPengelola()
    {
        return $this->actingAs($this->pengelola, 'sanctum');
    }

    private function auditTerakhir(string $aksi): LogAudit
    {
        return LogAudit::query()->where('aksi', $aksi)->orderByDesc('id')->firstOrFail();
    }

    // --- Kamar -------------------------------------------------------------

    public function test_kamar_baru_mencatat_seluruh_keadaan_awalnya(): void
    {
        $respons = $this->sebagaiPemilik()
            ->postJson('/api/kamar', ['nomor' => 'A1', 'tipe' => 'AC'])
            ->assertCreated();

        $log = $this->auditTerakhir(PencatatAudit::AKSI_KAMAR_TAMBAH);

        $this->assertSame('kamar', $log->entitas);
        $this->assertSame($respons->json('data.id'), $log->entitas_id);
        $this->assertSame($this->pemilik->id, $log->aktor_id);
        $this->assertSame('Pemilik Kos', $log->aktor_nama);

        // Saat menambah, tidak ada nilai lama - dan `null` di sini berarti
        // "belum ada sebelumnya", bukan "tidak dicatat".
        $this->assertNull($log->nilai_lama);
        $this->assertSame('A1', $log->nilai_baru['nomor']);
        $this->assertSame('AC', $log->nilai_baru['tipe']);
        // Kolom yang tidak dikirim klien tetap tercatat: yang dibaca kemudian
        // adalah keadaan barisnya, bukan isi formulirnya.
        $this->assertSame('kosong', $log->nilai_baru['status_huni']);
    }

    public function test_kamar_yang_diubah_hanya_mencatat_kolom_yang_berubah(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1', 'tipe' => 'AC']);

        $this->sebagaiPemilik()
            ->putJson("/api/kamar/{$kamar->id}", ['nomor' => 'A1', 'tipe' => 'AC', 'catatan' => 'dekat tangga'])
            ->assertOk();

        $log = $this->auditTerakhir(PencatatAudit::AKSI_KAMAR_UBAH);

        // Hanya `catatan` yang berubah. `nomor` dan `tipe` dikirim ulang
        // dengan nilai yang sama, dan nilai yang sama bukan perubahan.
        $this->assertSame(['catatan'], array_keys($log->nilai_lama));
        $this->assertNull($log->nilai_lama['catatan']);
        $this->assertSame('dekat tangga', $log->nilai_baru['catatan']);
    }

    public function test_menyimpan_kamar_tanpa_perubahan_tidak_menghasilkan_jejak(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1', 'tipe' => 'AC']);

        $sebelum = LogAudit::count();

        $this->sebagaiPemilik()
            ->putJson("/api/kamar/{$kamar->id}", ['nomor' => 'A1', 'tipe' => 'AC'])
            ->assertOk();

        $this->assertSame($sebelum, LogAudit::count());
    }

    public function test_jejak_kamar_menyebut_pengelola_yang_bertindak(): void
    {
        $this->sebagaiPengelola()
            ->postJson('/api/kamar', ['nomor' => 'B1'])
            ->assertCreated();

        $log = $this->auditTerakhir(PencatatAudit::AKSI_KAMAR_TAMBAH);

        $this->assertSame($this->pengelola->id, $log->aktor_id);
        $this->assertSame('Sari Pengelola', $log->aktor_nama);
    }

    // --- Penghuni ----------------------------------------------------------

    public function test_penghuni_baru_mencatat_nama_dan_nomor_hp(): void
    {
        $respons = $this->sebagaiPemilik()
            ->postJson('/api/penghuni', [
                'nama' => 'Budi Santoso',
                'email' => 'budi@kos.test',
                'no_hp' => '081200000001',
            ])
            ->assertCreated();

        $log = $this->auditTerakhir(PencatatAudit::AKSI_PENGHUNI_TAMBAH);

        $this->assertSame('penghuni', $log->entitas);
        $this->assertSame($respons->json('data.id'), $log->entitas_id);
        $this->assertSame('Budi Santoso', $log->nilai_baru['nama']);
        $this->assertSame('081200000001', $log->nilai_baru['no_hp']);

        // Email sengaja tidak disalin ke sini: ia tinggal di `users`, dan
        // menyalinnya berarti dua tempat yang bisa berbeda. Jejak perubahan
        // email ada di alur aktivasi akun, bukan di data induk.
        $this->assertArrayNotHasKey('email', $log->nilai_baru);
    }

    public function test_penghuni_yang_diubah_hanya_mencatat_kolom_yang_berubah(): void
    {
        $penghuni = Penghuni::create([
            'nama' => 'Budi',
            'email' => 'budi@kos.test',
            'no_hp' => '081200000001',
        ]);

        $this->sebagaiPemilik()
            ->putJson("/api/penghuni/{$penghuni->id}", [
                'nama' => 'Budi Santoso',
                'email' => 'budi@kos.test',
                'no_hp' => '081200000001',
            ])
            ->assertOk();

        $log = $this->auditTerakhir(PencatatAudit::AKSI_PENGHUNI_UBAH);

        $this->assertSame(['nama'], array_keys($log->nilai_lama));
        $this->assertSame('Budi', $log->nilai_lama['nama']);
        $this->assertSame('Budi Santoso', $log->nilai_baru['nama']);
    }

    public function test_menyimpan_penghuni_tanpa_perubahan_tidak_menghasilkan_jejak(): void
    {
        $penghuni = Penghuni::create([
            'nama' => 'Budi',
            'email' => 'budi@kos.test',
        ]);

        $sebelum = LogAudit::count();

        $this->sebagaiPemilik()
            ->putJson("/api/penghuni/{$penghuni->id}", [
                'nama' => 'Budi',
                'email' => 'budi@kos.test',
            ])
            ->assertOk();

        $this->assertSame($sebelum, LogAudit::count());
    }

    // --- Kontrak -----------------------------------------------------------

    public function test_kontrak_baru_mencatat_kamar_penghuni_tarif_dan_deposit(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $penghuni = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);

        $respons = $this->sebagaiPemilik()
            ->postJson('/api/kontrak', [
                'kamar_id' => $kamar->id,
                'penghuni_id' => $penghuni->id,
                'tgl_masuk' => '2026-10-15',
                'tarif' => 1_500_000,
                'deposit' => 1_500_000,
            ])
            ->assertCreated();

        $log = $this->auditTerakhir(PencatatAudit::AKSI_KONTRAK_TAMBAH);

        $this->assertSame('kontrak', $log->entitas);
        $this->assertSame($respons->json('data.id'), $log->entitas_id);
        $this->assertSame($kamar->id, $log->nilai_baru['kamar_id']);
        $this->assertSame($penghuni->id, $log->nilai_baru['penghuni_id']);
        $this->assertSame(1_500_000, $log->nilai_baru['tarif_awal']);
        $this->assertSame(1_500_000, $log->nilai_baru['deposit']);
        $this->assertSame('aktif', $log->nilai_baru['status']);
    }

    /**
     * Mengakhiri kontrak mencatat tanggal keluarnya.
     *
     * Tanggal itu bukan pelengkap: ia yang menentukan bulan terakhir yang
     * masih harus dibayar. Tanpa tercatat, pertanyaan "ia keluar kapan" hanya
     * bisa dijawab dari tabel yang bisa berubah.
     */
    public function test_mengakhiri_kontrak_mencatat_status_dan_tanggal_keluar(): void
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
            'deposit' => 0,
        ]);

        $this->sebagaiPemilik()
            ->postJson("/api/kontrak/{$kontrak->id}/akhiri")
            ->assertOk();

        $log = $this->auditTerakhir(PencatatAudit::AKSI_KONTRAK_AKHIR);

        $this->assertSame('kontrak', $log->entitas);
        $this->assertSame($kontrak->id, $log->entitas_id);
        $this->assertSame('aktif', $log->nilai_lama['status']);
        $this->assertNull($log->nilai_lama['tgl_keluar']);
        $this->assertSame('selesai', $log->nilai_baru['status']);
        $this->assertNotNull($log->nilai_baru['tgl_keluar']);
    }

    // --- Fasilitas ---------------------------------------------------------

    public function test_fasilitas_baru_diubah_dan_dihapus_semuanya_berjejak(): void
    {
        $respons = $this->sebagaiPemilik()
            ->postJson('/api/fasilitas', ['nama' => 'AC', 'ikon' => 'ac', 'urutan' => 1])
            ->assertCreated();

        $id = $respons->json('data.id');

        $tambah = $this->auditTerakhir(PencatatAudit::AKSI_FASILITAS_TAMBAH);
        $this->assertSame($id, $tambah->entitas_id);
        $this->assertSame('AC', $tambah->nilai_baru['nama']);
        // `ikon` ikut: ia yang tampil di portal penghuni, jadi perubahan ikon
        // adalah perubahan yang dilihat orang.
        $this->assertSame('ac', $tambah->nilai_baru['ikon']);

        $this->sebagaiPemilik()
            ->putJson("/api/fasilitas/{$id}", ['nama' => 'AC', 'ikon' => 'snowflake', 'urutan' => 1])
            ->assertOk();

        $ubah = $this->auditTerakhir(PencatatAudit::AKSI_FASILITAS_UBAH);
        $this->assertSame(['ikon'], array_keys($ubah->nilai_lama));
        $this->assertSame('ac', $ubah->nilai_lama['ikon']);
        $this->assertSame('snowflake', $ubah->nilai_baru['ikon']);

        $this->sebagaiPemilik()
            ->deleteJson("/api/fasilitas/{$id}")
            ->assertOk();

        $hapus = $this->auditTerakhir(PencatatAudit::AKSI_FASILITAS_HAPUS);
        // Nama disimpan, bukan hanya id: id baris yang sudah dihapus tidak bisa
        // dicari lagi, dan "fasilitas 7 dihapus" tidak menjawab apa pun.
        $this->assertSame('AC', $hapus->nilai_lama['nama']);
    }

    public function test_mengganti_daftar_fasilitas_kamar_mencatat_yang_dipasang_dan_dilepas(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $ac = Fasilitas::create(['nama' => 'AC']);
        $kasur = Fasilitas::create(['nama' => 'Kasur']);

        // Pertama: pasang AC.
        $this->sebagaiPemilik()
            ->putJson("/api/kamar/{$kamar->id}/fasilitas", ['fasilitas' => [$ac->id]])
            ->assertOk();

        $log = $this->auditTerakhir(PencatatAudit::AKSI_FASILITAS_UBAH);
        $this->assertSame('kamar', $log->entitas);
        $this->assertSame([], $log->nilai_lama['fasilitas_dilepas']);
        $this->assertSame(['AC'], $log->nilai_baru['fasilitas_dipasang']);

        // Kedua: ganti AC dengan kasur. Yang dilepas HARUS tercatat: daftar
        // akhir saja tidak bisa menjawab "AC kamar ini dilepas kapan".
        $this->sebagaiPemilik()
            ->putJson("/api/kamar/{$kamar->id}/fasilitas", ['fasilitas' => [$kasur->id]])
            ->assertOk();

        $log = $this->auditTerakhir(PencatatAudit::AKSI_FASILITAS_UBAH);
        $this->assertSame(['AC'], $log->nilai_lama['fasilitas_dilepas']);
        $this->assertSame(['Kasur'], $log->nilai_baru['fasilitas_dipasang']);
    }

    public function test_mengirim_daftar_fasilitas_yang_sama_tidak_menghasilkan_jejak(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $ac = Fasilitas::create(['nama' => 'AC']);

        $kamar->fasilitas()->sync([$ac->id]);

        $sebelum = LogAudit::count();

        $this->sebagaiPemilik()
            ->putJson("/api/kamar/{$kamar->id}/fasilitas", ['fasilitas' => [$ac->id]])
            ->assertOk();

        $this->assertSame($sebelum, LogAudit::count());
    }

    // --- Aturan ------------------------------------------------------------

    public function test_aturan_baru_diubah_dan_dihapus_semuanya_berjejak(): void
    {
        $respons = $this->sebagaiPemilik()
            ->postJson('/api/aturan', [
                'judul' => 'Tamu tidak boleh menginap',
                'isi' => 'Tamu hanya boleh berkunjung sampai pukul 22.00.',
                'kategori' => 'tamu',
                'sanksi' => 'Teguran lisan',
            ])
            ->assertCreated();

        $id = $respons->json('data.id');

        $tambah = $this->auditTerakhir(PencatatAudit::AKSI_ATURAN_TAMBAH);
        $this->assertSame('aturan_kos', $tambah->entitas);
        $this->assertSame($id, $tambah->entitas_id);
        $this->assertSame('Tamu tidak boleh menginap', $tambah->nilai_baru['judul']);
        $this->assertSame('Teguran lisan', $tambah->nilai_baru['sanksi']);
        $this->assertTrue($tambah->nilai_baru['aktif']);

        $this->sebagaiPemilik()
            ->putJson("/api/aturan/{$id}", [
                'judul' => 'Tamu tidak boleh menginap',
                'isi' => 'Tamu hanya boleh berkunjung sampai pukul 22.00.',
                'kategori' => 'tamu',
                'sanksi' => 'Teguran lisan',
                'aktif' => false,
            ])
            ->assertOk();

        $ubah = $this->auditTerakhir(PencatatAudit::AKSI_ATURAN_UBAH);
        // Menyalakan/mematikan `aktif` ikut tercatat: itulah yang menentukan
        // apakah penghuni melihat aturan ini di portal.
        $this->assertSame(['aktif'], array_keys($ubah->nilai_lama));
        $this->assertTrue($ubah->nilai_lama['aktif']);
        $this->assertFalse($ubah->nilai_baru['aktif']);

        $this->sebagaiPemilik()
            ->deleteJson("/api/aturan/{$id}")
            ->assertOk();

        $hapus = $this->auditTerakhir(PencatatAudit::AKSI_ATURAN_HAPUS);
        $this->assertSame('Tamu tidak boleh menginap', $hapus->nilai_lama['judul']);
    }

    // --- Lintas entitas ----------------------------------------------------

    /**
     * Jejak bisa disaring per entitas, dan penyaringan itu yang dipakai
     * halaman riwayat.
     *
     * Tanpa ini, "apa yang terjadi pada kamar 3" hanya bisa dijawab dengan
     * membaca seluruh log dan menyaringnya di layar - yang berhenti bekerja
     * begitu lognya lebih dari beberapa ratus baris.
     */
    public function test_jejak_bisa_disaring_per_entitas_dan_id(): void
    {
        $respons = $this->sebagaiPemilik()
            ->postJson('/api/kamar', ['nomor' => 'A1'])
            ->assertCreated();

        $kamarId = $respons->json('data.id');

        $this->sebagaiPemilik()->postJson('/api/kamar', ['nomor' => 'B1'])->assertCreated();

        $this->sebagaiPemilik()
            ->getJson("/api/audit-log?entitas=kamar&entitas_id={$kamarId}")
            ->assertOk()
            ->assertJsonPath('meta.jumlah', 1)
            ->assertJsonPath('data.0.entitas_id', $kamarId)
            ->assertJsonPath('data.0.aksi', PencatatAudit::AKSI_KAMAR_TAMBAH);
    }
}
