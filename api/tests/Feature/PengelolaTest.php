<?php

namespace Tests\Feature;

use App\Domain\Akses\Wewenang;
use App\Models\Kamar;
use App\Models\KebijakanKos;
use App\Models\LogAudit;
use App\Models\PengelolaKewenangan;
use App\Models\Penghuni;
use App\Models\TarifSewa;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * T24 - pengelola dan wewenang.
 *
 * Tiga hal yang dibuktikan di sini, berurutan dari yang paling penting:
 *
 *   1. **Pembatasan benar-benar membatasi.** Pengelola tanpa `ubah_tarif`
 *      ditolak 403 saat menetapkan tarif, dan penolakan itu terjadi di
 *      middleware - bukan di layar. Layar yang menyembunyikan tombol bukan
 *      pengamanan.
 *
 *   2. **Isolasi R-07 tidak rusak.** Pengelola melihat seluruh data kos
 *      (memang berhak), tetapi penghuni tetap 404 untuk tagihan orang lain.
 *      Yang mudah terjadi saat menambah peran baru adalah melebarkan scope
 *      sampai penghuni ikut lolos.
 *
 *   3. **Jejak menyebut nama pengelola.** Setiap aksi pengelola tercatat
 *      dengan namanya, dan `nilai_lama` terisi saat tarif atau kebijakan
 *      berubah - tanpa itu, laporan tidak bisa menyebut "dari berapa menjadi
 *      berapa".
 */
class PengelolaTest extends TestCase
{
    use RefreshDatabase;

    private User $pemilik;

    private User $pengelola;

    private User $akunPenghuni;

    private Penghuni $penghuni;

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
            'email' => 'pengelola@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $this->pemilik->id,
        ]);

        $this->penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'email' => 'budi@kos.test']);

        $this->akunPenghuni = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $this->penghuni->id,
        ]);
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    private function sebagaiPengelola(string ...$wewenang)
    {
        foreach ($wewenang as $w) {
            PengelolaKewenangan::create([
                'user_id' => $this->pengelola->id,
                'wewenang' => $w,
            ]);
        }

        // Relasi di memori dibuang supaya `boleh()` membaca baris yang baru
        // ditulis. Tanpa ini, tes akan menguji keadaan sebelum wewenang
        // diberikan dan lulus karena alasan yang salah.
        $this->pengelola->unsetRelation('kewenangan');

        return $this->actingAs($this->pengelola, 'sanctum');
    }

    private function sebagaiPenghuni()
    {
        return $this->actingAs($this->akunPenghuni, 'sanctum');
    }

    private function kamar(string $nomor = 'A1'): Kamar
    {
        return Kamar::create(['nomor' => $nomor]);
    }

    // --- Bentuk data -------------------------------------------------------

    public function test_pengelola_wajib_menunjuk_pemilik_ditegakkan_basis_data(): void
    {
        // Tanpa CHECK constraint, pengelola tanpa atasan adalah akun yang tidak
        // bisa dijelaskan siapa yang memberinya izin.
        $this->expectException(QueryException::class);

        User::create([
            'name' => 'Pengelola Liar',
            'email' => 'liar@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => null,
        ]);
    }

    public function test_wewenang_yang_tidak_dikenal_ditolak_basis_data(): void
    {
        $this->expectException(QueryException::class);

        PengelolaKewenangan::create([
            'user_id' => $this->pengelola->id,
            'wewenang' => 'ubah-tarif', // salah ketik: tanda hubung, bukan garis bawah
        ]);
    }

    public function test_satu_wewenang_hanya_boleh_tercatat_sekali(): void
    {
        PengelolaKewenangan::create([
            'user_id' => $this->pengelola->id,
            'wewenang' => Wewenang::CATAT_PEMBAYARAN,
        ]);

        $this->expectException(QueryException::class);

        PengelolaKewenangan::create([
            'user_id' => $this->pengelola->id,
            'wewenang' => Wewenang::CATAT_PEMBAYARAN,
        ]);
    }

    // --- CRUD pengelola ----------------------------------------------------

    public function test_pemilik_bisa_membuat_pengelola_dengan_wewenang(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/pengelola', [
                'nama' => 'Joko Pengelola',
                'email' => 'joko@kos.test',
                'password' => 'rahasia123',
                'wewenang' => [Wewenang::CATAT_PEMBAYARAN, Wewenang::KELOLA_KAMAR],
            ])
            ->assertCreated()
            ->assertJsonPath('data.nama', 'Joko Pengelola')
            ->assertJsonPath('data.peran', User::PERAN_PENGELOLA)
            ->assertJsonPath('data.pemilik_id', $this->pemilik->id);

        $baru = User::where('email', 'joko@kos.test')->firstOrFail();
        $this->assertSame(
            [Wewenang::CATAT_PEMBAYARAN, Wewenang::KELOLA_KAMAR],
            $baru->kewenangan()->pluck('wewenang')->sort()->values()->all()
        );
    }

    public function test_pemilik_id_diambil_dari_sesi_bukan_dari_isian(): void
    {
        // Klien yang mengirim pemilik_id sendiri tidak boleh menentukan atasan.
        // Kalau boleh, satu akun bisa menunjuk pemilik lain - dan dalam model
        // satu-kos itu tidak punya arti selain celah.
        $this->sebagaiPemilik()
            ->postJson('/api/pengelola', [
                'nama' => 'Joko',
                'email' => 'joko@kos.test',
                'password' => 'rahasia123',
                'wewenang' => [],
                'pemilik_id' => 999,
            ])
            ->assertCreated()
            ->assertJsonPath('data.pemilik_id', $this->pemilik->id);
    }

    public function test_pengelola_tidak_bisa_membuat_pengelola(): void
    {
        // Memberi wewenang adalah wewenang yang tidak didelegasikan: pemberinya
        // bisa memberi dirinya apa pun.
        $this->sebagaiPengelola(Wewenang::KELOLA_KAMAR)
            ->postJson('/api/pengelola', [
                'nama' => 'Joko',
                'email' => 'joko@kos.test',
                'password' => 'rahasia123',
                'wewenang' => [Wewenang::UBAH_TARIF],
            ])
            ->assertForbidden();
    }

    public function test_penghuni_tidak_bisa_membaca_daftar_pengelola(): void
    {
        $this->sebagaiPenghuni()->getJson('/api/pengelola')->assertForbidden();
    }

    public function test_daftar_pengelola_menyertakan_menu_wewenang_dari_server(): void
    {
        $this->sebagaiPemilik()
            ->getJson('/api/pengelola')
            ->assertOk()
            ->assertJsonPath('meta.semua_wewenang', Wewenang::SEMUA)
            ->assertJsonPath('meta.wewenang_sensitif', Wewenang::SENSITIF)
            ->assertJsonStructure(['meta' => ['label_wewenang' => [Wewenang::CATAT_PEMBAYARAN]]]);
    }

    public function test_wewenang_tidak_dikenal_ditolak_validasi(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/pengelola', [
                'nama' => 'Joko',
                'email' => 'joko@kos.test',
                'password' => 'rahasia123',
                'wewenang' => ['hapus_semua'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('wewenang.0');
    }

    public function test_mengubah_pengelola_mengganti_seluruh_daftar_wewenang(): void
    {
        $this->sebagaiPengelola(Wewenang::CATAT_PEMBAYARAN, Wewenang::KELOLA_KAMAR);

        $this->sebagaiPemilik()
            ->putJson("/api/pengelola/{$this->pengelola->id}", [
                'nama' => 'Sari Pengelola',
                'email' => 'pengelola@kos.test',
                'wewenang' => [Wewenang::BACA_AUDIT],
            ])
            ->assertOk()
            ->assertJsonPath('data.wewenang', [Wewenang::BACA_AUDIT]);

        $this->assertSame(
            [Wewenang::BACA_AUDIT],
            $this->pengelola->kewenangan()->pluck('wewenang')->all()
        );
    }

    public function test_kata_sandi_kosong_tidak_mengubah_kata_sandi(): void
    {
        $lama = $this->pengelola->password;

        $this->sebagaiPemilik()
            ->putJson("/api/pengelola/{$this->pengelola->id}", [
                'nama' => 'Sari Pengelola',
                'email' => 'pengelola@kos.test',
                'password' => '',
                'wewenang' => [],
            ])
            ->assertOk();

        $this->assertSame($lama, $this->pengelola->fresh()->password);
    }

    public function test_pencabutan_menurunkan_peran_dan_mencabut_token(): void
    {
        $this->sebagaiPengelola(Wewenang::KELOLA_KAMAR);
        $token = $this->pengelola->createToken('spa');

        $this->sebagaiPemilik()
            ->deleteJson("/api/pengelola/{$this->pengelola->id}")
            ->assertOk();

        $segar = $this->pengelola->fresh();
        $this->assertSame(User::PERAN_PENGHUNI, $segar->peran);
        $this->assertNull($segar->pemilik_id);
        $this->assertSame(0, $segar->kewenangan()->count());

        // Token yang dipegang orangnya ikut mati. Tanpa ini, "cabut akses"
        // hanya berlaku setelah token kedaluwarsa sendiri.
        $this->assertSame(0, $segar->tokens()->count());
        $this->assertNotNull($token);
    }

    public function test_endpoint_pengelola_menolak_akun_yang_bukan_pengelola(): void
    {
        // Tanpa pemeriksaan ini, PUT /pengelola/{id} dengan id pemilik akan
        // mengubah nama dan kata sandi akun pemilik.
        $this->sebagaiPemilik()
            ->putJson("/api/pengelola/{$this->pemilik->id}", [
                'nama' => 'Disusupi',
                'email' => 'pemilik@kos.test',
                'wewenang' => [],
            ])
            ->assertNotFound();
    }

    // --- Wewenang benar-benar membatasi -----------------------------------

    public function test_pengelola_tanpa_ubah_tarif_ditolak_menetapkan_tarif(): void
    {
        $kamar = $this->kamar();

        $this->sebagaiPengelola(Wewenang::KELOLA_KAMAR)
            ->postJson('/api/tarif', [
                'kamar_id' => $kamar->id,
                'nilai' => 1500000,
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertForbidden();

        $this->assertSame(0, TarifSewa::count());
    }

    public function test_pengelola_dengan_ubah_tarif_boleh_menetapkan_tarif(): void
    {
        $kamar = $this->kamar();

        $this->sebagaiPengelola(Wewenang::UBAH_TARIF)
            ->postJson('/api/tarif', [
                'kamar_id' => $kamar->id,
                'nilai' => 1500000,
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertCreated();

        $this->assertSame(1, TarifSewa::count());
    }

    public function test_pengelola_tanpa_ubah_kebijakan_ditolak_menetapkan_kebijakan(): void
    {
        $this->sebagaiPengelola(Wewenang::UBAH_TARIF)
            ->postJson('/api/kebijakan', [
                'tipe' => 'satuan_sewa',
                'nilai' => 'bulanan',
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertForbidden();

        $this->assertSame(0, KebijakanKos::count());
    }

    public function test_pengelola_tanpa_kelola_kamar_ditolak_menambah_kamar(): void
    {
        $this->sebagaiPengelola(Wewenang::CATAT_PEMBAYARAN)
            ->postJson('/api/kamar', ['nomor' => 'B1'])
            ->assertForbidden();

        $this->assertSame(0, Kamar::count());
    }

    public function test_pengelola_tanpa_catat_pembayaran_ditolak_mencatat_pembayaran(): void
    {
        // Tagihannya harus benar-benar ada: route model binding berjalan lebih
        // dulu daripada middleware wewenang, jadi id yang tidak ada dijawab 404
        // dan middleware tidak pernah dievaluasi. Memakai id palsu akan menguji
        // 404, bukan 403 - dan tesnya akan lulus karena alasan yang salah.
        $kamar = $this->kamar();
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1500000, 'berlaku_dari' => '2026-01-01']);
        $tagihan = $this->buatTagihan($kamar, $this->penghuni, '2026-02-01');

        $this->sebagaiPengelola(Wewenang::TERBITKAN_TAGIHAN)
            ->postJson("/api/tagihan/{$tagihan->id}/pembayaran", [
                'jumlah' => 100000,
                'tanggal_pembayaran' => '2026-01-05',
            ])
            ->assertForbidden();
    }

    public function test_pengelola_tanpa_baca_audit_ditolak_membaca_audit(): void
    {
        $this->sebagaiPengelola(Wewenang::KELOLA_KAMAR)
            ->getJson('/api/audit-log')
            ->assertForbidden();
    }

    public function test_pengelola_dengan_baca_audit_boleh_membaca_audit(): void
    {
        $this->sebagaiPengelola(Wewenang::BACA_AUDIT)
            ->getJson('/api/audit-log')
            ->assertOk();
    }

    public function test_penghuni_selalu_ditolak_untuk_endpoint_berwewenang(): void
    {
        $this->sebagaiPenghuni()->postJson('/api/kamar', ['nomor' => 'B1'])->assertForbidden();
        $this->sebagaiPenghuni()->getJson('/api/tarif')->assertForbidden();
        $this->sebagaiPenghuni()->getJson('/api/audit-log')->assertForbidden();
    }

    public function test_membaca_data_kos_tidak_menuntut_wewenang_mengubah(): void
    {
        // Pengelola yang tugasnya hanya mencatat pembayaran tetap harus bisa
        // mengisi pilihan kamar dan kontrak di layarnya. Wewenang membatasi
        // tindakan, bukan pengetahuan.
        $this->kamar('A1');

        $this->sebagaiPengelola(Wewenang::CATAT_PEMBAYARAN)
            ->getJson('/api/kamar')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->sebagaiPengelola()
            ->getJson('/api/penghuni')
            ->assertOk();
    }

    public function test_pengelola_tidak_bisa_menyentuh_data_lewat_jalur_baca(): void
    {
        // Rute baca memakai GET saja. Memastikan tidak ada jalur tulis yang
        // ikut terbuka karena rute baca didaftarkan lebih dulu.
        $this->sebagaiPengelola(Wewenang::CATAT_PEMBAYARAN)
            ->postJson('/api/kamar', ['nomor' => 'B1'])
            ->assertForbidden();
    }

    // --- Isolasi R-07 tetap utuh ------------------------------------------

    public function test_pengelola_melihat_seluruh_tagihan(): void
    {
        // Dua kamar, bukan satu: satu kamar hanya boleh punya satu kontrak
        // aktif, jadi menagih dua penghuni pada kamar yang sama akan ditolak
        // sebelum sampai ke bagian yang sedang diuji.
        $kamarA = $this->kamar('A1');
        $kamarB = $this->kamar('B1');
        TarifSewa::create(['kamar_id' => $kamarA->id, 'nilai' => 1500000, 'berlaku_dari' => '2026-01-01']);
        TarifSewa::create(['kamar_id' => $kamarB->id, 'nilai' => 1500000, 'berlaku_dari' => '2026-01-01']);

        $lain = Penghuni::create(['nama' => 'Ani', 'email' => 'ani@kos.test']);
        $this->buatTagihan($kamarA, $this->penghuni, '2026-02-01');
        $this->buatTagihan($kamarB, $lain, '2026-02-01');

        $this->sebagaiPengelola(Wewenang::TERBITKAN_TAGIHAN)
            ->getJson('/api/tagihan')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_penghuni_tetap_404_untuk_tagihan_orang_lain(): void
    {
        // Yang paling mudah rusak saat menambah peran: scope dilebarkan sampai
        // penghuni ikut lolos. Isolasi ini yang diuji ulang di sini.
        $kamar = $this->kamar();
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1500000, 'berlaku_dari' => '2026-01-01']);

        $lain = Penghuni::create(['nama' => 'Ani', 'email' => 'ani@kos.test']);
        $tagihanLain = $this->buatTagihan($kamar, $lain, '2026-02-01');

        $this->sebagaiPenghuni()
            ->getJson("/api/tagihan/{$tagihanLain->id}")
            ->assertNotFound();
    }

    public function test_pengelola_bisa_membuka_tagihan_penghuni(): void
    {
        $kamar = $this->kamar();
        TarifSewa::create(['kamar_id' => $kamar->id, 'nilai' => 1500000, 'berlaku_dari' => '2026-01-01']);

        $tagihan = $this->buatTagihan($kamar, $this->penghuni, '2026-02-01');

        $this->sebagaiPengelola(Wewenang::CATAT_PEMBAYARAN)
            ->getJson("/api/tagihan/{$tagihan->id}")
            ->assertOk();
    }

    // --- Jejak audit -------------------------------------------------------

    public function test_aksi_pengelola_tercatat_dengan_namanya(): void
    {
        $kamar = $this->kamar();

        $this->sebagaiPengelola(Wewenang::UBAH_TARIF)
            ->postJson('/api/tarif', [
                'kamar_id' => $kamar->id,
                'nilai' => 1500000,
                'berlaku_dari' => '2026-01-01',
            ])
            ->assertCreated();

        $jejak = LogAudit::where('aksi', 'tarif.tambah')->latest('id')->firstOrFail();

        $this->assertSame('Sari Pengelola', $jejak->aktor_nama);
        $this->assertSame($this->pengelola->id, $jejak->aktor_id);
    }

    public function test_nilai_lama_terisi_saat_tarif_dinaikkan(): void
    {
        $kamar = $this->kamar();

        $this->sebagaiPemilik()->postJson('/api/tarif', [
            'kamar_id' => $kamar->id,
            'nilai' => 1500000,
            'berlaku_dari' => '2026-01-01',
        ])->assertCreated();

        // Tarif pertama tidak menggantikan apa pun.
        $this->assertNull(LogAudit::where('aksi', 'tarif.tambah')->latest('id')->firstOrFail()->nilai_lama);

        $this->sebagaiPemilik()->postJson('/api/tarif', [
            'kamar_id' => $kamar->id,
            'nilai' => 1800000,
            'berlaku_dari' => '2026-06-01',
        ])->assertCreated();

        $jejak = LogAudit::where('aksi', 'tarif.tambah')->latest('id')->firstOrFail();

        $this->assertSame(1500000, $jejak->nilai_lama['nilai']);
        $this->assertSame(1800000, $jejak->nilai_baru['nilai']);
        $this->assertSame('2026-01-01', $jejak->nilai_lama['berlaku_dari']);
    }

    public function test_nilai_lama_tarif_mengabaikan_tarif_yang_berlaku_sesudahnya(): void
    {
        // Kalau pencariannya salah - mis. mengambil yang terakhir dibuat, bukan
        // yang terakhir berlaku sebelum tanggal baru - jejak akan menyebut
        // perubahan yang tidak pernah terjadi.
        $kamar = $this->kamar();

        $this->sebagaiPemilik()->postJson('/api/tarif', [
            'kamar_id' => $kamar->id, 'nilai' => 1000000, 'berlaku_dari' => '2026-01-01',
        ])->assertCreated();

        $this->sebagaiPemilik()->postJson('/api/tarif', [
            'kamar_id' => $kamar->id, 'nilai' => 3000000, 'berlaku_dari' => '2026-09-01',
        ])->assertCreated();

        // Tarif yang disisipkan di tengah harus membandingkan dengan Januari,
        // bukan dengan September.
        $this->sebagaiPemilik()->postJson('/api/tarif', [
            'kamar_id' => $kamar->id, 'nilai' => 2000000, 'berlaku_dari' => '2026-05-01',
        ])->assertCreated();

        $jejak = LogAudit::where('aksi', 'tarif.tambah')->latest('id')->firstOrFail();

        $this->assertSame(1000000, $jejak->nilai_lama['nilai']);
        $this->assertSame(2000000, $jejak->nilai_baru['nilai']);
    }

    public function test_nilai_lama_terisi_saat_kebijakan_diubah(): void
    {
        $this->sebagaiPemilik()->postJson('/api/kebijakan', [
            'tipe' => 'satuan_sewa',
            'nilai' => 'bulanan',
            'berlaku_dari' => '2026-01-01',
        ])->assertCreated();

        $this->sebagaiPemilik()->postJson('/api/kebijakan', [
            'tipe' => 'satuan_sewa',
            'nilai' => 'bulanan',
            'berlaku_dari' => '2026-06-01',
        ])->assertCreated();

        $jejak = LogAudit::where('aksi', 'kebijakan.tambah')->latest('id')->firstOrFail();

        $this->assertSame('bulanan', $jejak->nilai_lama['nilai']);
        $this->assertSame('2026-01-01', $jejak->nilai_lama['berlaku_dari']);
    }

    public function test_nilai_lama_kebijakan_khusus_kamar_tidak_tertukar_default(): void
    {
        // `kamar_id` null berarti default kos. Perbandingan `= null` di SQL
        // tidak pernah cocok, jadi tanpa penanganan khusus nilai lama untuk
        // default kos akan selalu kosong - dan jejaknya berbohong dengan cara
        // yang paling sulit dilihat.
        $kamar = $this->kamar();

        $this->sebagaiPemilik()->postJson('/api/kebijakan', [
            'tipe' => 'jatuh_tempo', 'nilai' => 'tanggal_tetap', 'tanggal' => 10,
            'berlaku_dari' => '2026-01-01',
        ])->assertCreated();

        // Kebijakan khusus kamar tidak boleh dianggap menggantikan default.
        $this->sebagaiPemilik()->postJson('/api/kebijakan', [
            'tipe' => 'jatuh_tempo', 'nilai' => 'tanggal_tetap', 'tanggal' => 5,
            'kamar_id' => $kamar->id, 'berlaku_dari' => '2026-01-01',
        ])->assertCreated();

        $jejak = LogAudit::where('aksi', 'kebijakan.tambah')->latest('id')->firstOrFail();
        $this->assertNull($jejak->nilai_lama);

        // Default kos yang diubah harus membandingkan dengan default sebelumnya.
        $this->sebagaiPemilik()->postJson('/api/kebijakan', [
            'tipe' => 'jatuh_tempo', 'nilai' => 'tanggal_tetap', 'tanggal' => 15,
            'berlaku_dari' => '2026-06-01',
        ])->assertCreated();

        $jejak = LogAudit::where('aksi', 'kebijakan.tambah')->latest('id')->firstOrFail();
        $this->assertSame(10, $jejak->nilai_lama['tanggal']);
        $this->assertSame(15, $jejak->nilai_baru['tanggal']);
    }

    // --- /me mengirim wewenang --------------------------------------------

    public function test_me_mengirim_seluruh_wewenang_untuk_pemilik(): void
    {
        $this->sebagaiPemilik()
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.wewenang', Wewenang::SEMUA);
    }

    public function test_me_mengirim_wewenang_pengelola_apa_adanya(): void
    {
        $this->sebagaiPengelola(Wewenang::CATAT_PEMBAYARAN);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.peran', User::PERAN_PENGELOLA)
            ->assertJsonPath('data.wewenang', [Wewenang::CATAT_PEMBAYARAN]);
    }

    public function test_me_tidak_mengirim_wewenang_untuk_penghuni(): void
    {
        $this->sebagaiPenghuni()
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.wewenang', []);
    }

    public function test_login_menyertakan_wewenang(): void
    {
        PengelolaKewenangan::create([
            'user_id' => $this->pengelola->id,
            'wewenang' => Wewenang::BACA_AUDIT,
        ]);

        $this->postJson('/api/login', [
            'email' => 'pengelola@kos.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.peran', User::PERAN_PENGELOLA)
            ->assertJsonPath('data.user.wewenang', [Wewenang::BACA_AUDIT]);
    }

    /**
     * Menetapkan tiga kebijakan wajib, sekali saja per tes.
     *
     * Penjaganya perlu karena `kebijakan_kos` append-only dengan kunci unik
     * (tipe, kamar_id, berlaku_dari): menetapkan tipe yang sama dua kali pada
     * tanggal yang sama dijawab 422 oleh basis data, bukan ditimpa. Tanpa
     * penjaga, tes yang menerbitkan dua tagihan akan gagal pada tagihan kedua
     * karena alasan yang tidak ada hubungannya dengan yang sedang diuji.
     */
    private function siapkanKebijakan(): void
    {
        if (KebijakanKos::count() > 0) {
            return;
        }

        $this->sebagaiPemilik()->postJson('/api/kebijakan', [
            'tipe' => 'satuan_sewa', 'nilai' => 'bulanan', 'berlaku_dari' => '2026-01-01',
        ])->assertCreated();

        $this->sebagaiPemilik()->postJson('/api/kebijakan', [
            'tipe' => 'jatuh_tempo', 'nilai' => 'tanggal_tetap', 'tanggal' => 10,
            'berlaku_dari' => '2026-01-01',
        ])->assertCreated();

        $this->sebagaiPemilik()->postJson('/api/kebijakan', [
            'tipe' => 'denda', 'nilai' => 'tanpa_denda', 'berlaku_dari' => '2026-01-01',
        ])->assertCreated();
    }

    /**
     * Menerbitkan satu tagihan lewat jalur yang sebenarnya, bukan dengan
     * menulis baris tagihan langsung - supaya tes isolasi memakai data yang
     * bentuknya sama dengan yang dihasilkan aplikasi.
     */
    private function buatTagihan(Kamar $kamar, Penghuni $penghuni, string $periode)
    {
        $this->siapkanKebijakan();

        $kontrak = $this->sebagaiPemilik()->postJson('/api/kontrak', [
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-01-01',
            'deposit' => 0,
        ])->assertCreated()->json('data');

        return (object) $this->sebagaiPemilik()->postJson('/api/tagihan', [
            'kontrak_id' => $kontrak['id'],
            'periode' => $periode,
        ])->assertCreated()->json('data');
    }
}
