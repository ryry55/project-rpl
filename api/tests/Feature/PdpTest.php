<?php

namespace Tests\Feature;

use App\Domain\Pdp\LayananPdp;
use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\LogAudit;
use App\Models\Penghuni;
use App\Models\PersetujuanPdp;
use App\Models\Tagihan;
use App\Models\TarifSewa;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * T28 - UU PDP: persetujuan, hak akses, dan hak penghapusan.
 *
 * Inti tiket ini adalah **tabrakan**, bukan penambahan fitur. UU 27/2022
 * memberi subjek data hak menghapus data pribadinya; R-06 dan R-07 menuntut
 * `log_audit`, `tagihan`, dan `pembayaran` tidak bisa dihapus. Yang diuji di
 * sini adalah bahwa keduanya tetap benar bersamaan:
 *
 *   1. **Persetujuan tercatat sebagai bukti, bukan sebagai kolom tanggal.**
 *      Satu baris per versi teks, append-only, dan versi lama tidak ditimpa.
 *
 *   2. **Penghapusan berarti anonimisasi.** Nama hilang dari akun, dari data
 *      induk penghuni, DAN dari setiap baris log audit yang menyebutnya.
 *      Angka, tanggal, dan tindakan tetap ada - itu bukan lagi data pribadi
 *      setelah identitasnya dilepas, dan justru itu bukti keuangannya.
 *
 *   3. **Trigger append-only tetap menolak UPDATE biasa.** Celah anonimisasi
 *      hanya terbuka di dalam transaksi yang secara eksplisit menyatakan diri
 *      sebagai anonimisasi, dan hanya untuk dua kolom. Kalau celah itu lebih
 *      lebar sedikit saja, R-07 berhenti berlaku.
 *
 *   4. **Yang tidak boleh dihapus ditolak dengan alasan, bukan dengan galat.**
 *      Penghuni dengan kontrak aktif masih menempati kamar dan masih punya
 *      kewajiban bayar; menghapus identitasnya membuat tagihan berikutnya
 *      menunjuk orang yang tidak ada.
 */
class PdpTest extends TestCase
{
    use RefreshDatabase;

    private User $pemilik;

    private Penghuni $penghuni;

    private User $akunPenghuni;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pemilik = User::create([
            'name' => 'Pemilik Kos',
            'email' => 'pemilik@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PEMILIK,
            'email_verified_at' => now(),
        ]);

        $this->penghuni = Penghuni::create([
            'nama' => 'Budi Santoso',
            'email' => 'budi@kos.test',
            'no_hp' => '081200000001',
        ]);

        $this->akunPenghuni = User::create([
            'name' => 'Budi Santoso',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $this->penghuni->id,
            'email_verified_at' => now(),
        ]);
    }

    private function sebagaiPenghuni()
    {
        return $this->actingAs($this->akunPenghuni, 'sanctum');
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    // --- Persetujuan -------------------------------------------------------

    public function test_me_melaporkan_bahwa_persetujuan_pdp_belum_diberikan(): void
    {
        $this->sebagaiPenghuni()
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('meta.perlu_persetujuan_pdp', true)
            ->assertJsonPath('meta.versi_pdp', PersetujuanPdp::VERSI_BERLAKU);
    }

    public function test_persetujuan_tercatat_dan_me_berhenti_meminta(): void
    {
        $this->sebagaiPenghuni()
            ->postJson('/api/persetujuan-pdp')
            ->assertOk()
            ->assertJsonPath('data.versi', PersetujuanPdp::VERSI_BERLAKU);

        $this->assertDatabaseHas('persetujuan_pdp', [
            'user_id' => $this->akunPenghuni->id,
            'versi' => PersetujuanPdp::VERSI_BERLAKU,
        ]);

        $this->sebagaiPenghuni()
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('meta.perlu_persetujuan_pdp', false);
    }

    public function test_menyetujui_dua_kali_tidak_menambah_baris_kedua(): void
    {
        $this->sebagaiPenghuni()->postJson('/api/persetujuan-pdp')->assertOk();
        $this->sebagaiPenghuni()->postJson('/api/persetujuan-pdp')->assertOk();

        // Layar boleh memanggil tanpa memeriksa lebih dulu - dan memang
        // seharusnya begitu. Pemeriksaan ganda di klien dan server hanya
        // menambah satu jalan yang bisa berbeda hasilnya.
        $this->assertSame(
            1,
            PersetujuanPdp::where('user_id', $this->akunPenghuni->id)->count()
        );
    }

    public function test_versi_baru_menuntut_persetujuan_baru(): void
    {
        $this->sebagaiPenghuni()->postJson('/api/persetujuan-pdp')->assertOk();

        PersetujuanPdp::create([
            'user_id' => $this->akunPenghuni->id,
            'versi' => '0.9',
            'disetujui_pada' => now()->subYear(),
        ]);

        // Menyetujui teks lama bukan menyetujui teks yang menggantikannya.
        // Baris lama tetap ada sebagai riwayat - karena itu `hasMany`, bukan
        // `hasOne`.
        $this->assertSame(
            2,
            PersetujuanPdp::where('user_id', $this->akunPenghuni->id)->count()
        );
        $this->assertTrue($this->akunPenghuni->fresh()->sudahMenyetujuiPdp());
    }

    public function test_persetujuan_tidak_bisa_diubah_maupun_dihapus(): void
    {
        $this->sebagaiPenghuni()->postJson('/api/persetujuan-pdp')->assertOk();

        // Bukti persetujuan yang bisa disunting tidak membuktikan apa pun.
        foreach ([
            fn () => DB::table('persetujuan_pdp')->update(['versi' => 'palsu']),
            fn () => DB::table('persetujuan_pdp')->delete(),
        ] as $percobaan) {
            try {
                DB::transaction($percobaan);
                $this->fail('Trigger append-only seharusnya menolak.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->assertSame(1, PersetujuanPdp::count());
    }

    // --- Hak akses ---------------------------------------------------------

    public function test_data_saya_memuat_profil_kontak_dan_kontrak(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => 1500000,
            'berlaku_dari' => '2026-01-01',
        ]);

        Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $this->penghuni->id,
            'tgl_masuk' => '2026-10-01',
            'tarif_awal' => 1500000,
            'deposit' => 0,
            'status' => 'aktif',
        ]);

        $respons = $this->sebagaiPenghuni()
            ->getJson('/api/pdp/data-saya')
            ->assertOk()
            ->assertJsonPath('data.akun.email', 'budi@kos.test')
            ->assertJsonPath('data.penghuni.no_hp', '081200000001')
            ->assertJsonPath('data.kontrak.0.kamar', 'A1')
            ->assertJsonPath('meta.sudah_menyetujui', false);

        // Kontrak yang tercatat memang kontraknya sendiri - bukan kontrak
        // penghuni lain, dan bukan tarif kos.
        $this->assertCount(1, $respons->json('data.kontrak'));
    }

    public function test_data_saya_tidak_memuat_data_penghuni_lain(): void
    {
        $lain = Penghuni::create(['nama' => 'Siti Aminah', 'email' => 'siti@kos.test', 'no_hp' => '081299999999']);
        User::create([
            'name' => 'Siti Aminah',
            'email' => 'siti@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $lain->id,
            'email_verified_at' => now(),
        ]);

        $isi = $this->sebagaiPenghuni()->getJson('/api/pdp/data-saya')->assertOk()->getContent();

        // Endpoint ini bekerja pada akun pemanggil sendiri dan tidak menerima
        // parameter id. Kalau suatu saat menerimanya, inilah tes yang menangkap
        // kebocoran seluruh data pribadi penghuni.
        $this->assertStringNotContainsString('Siti Aminah', $isi);
        $this->assertStringNotContainsString('081299999999', $isi);
    }

    public function test_data_saya_tidak_memuat_data_kos(): void
    {
        Kamar::create(['nomor' => 'A1']);
        TarifSewa::create([
            'kamar_id' => Kamar::firstOrFail()->id,
            'nilai' => 1500000,
            'berlaku_dari' => '2026-01-01',
        ]);

        $isi = $this->sebagaiPenghuni()->getJson('/api/pdp/data-saya')->assertOk()->getContent();

        // Tarif dan kamar adalah data kos, bukan data pribadi orang. Kalau ikut
        // terkirim, hak akses berubah menjadi jalan membocorkan tarif ke
        // penghuni - yang di model all-in justru tidak perlu melihatnya.
        $this->assertStringNotContainsString('1500000', $isi);
    }

    public function test_data_saya_hanya_memuat_jejak_milik_sendiri(): void
    {
        LogAudit::create([
            'aktor_id' => $this->akunPenghuni->id,
            'aktor_nama' => 'Budi Santoso',
            'aksi' => 'uji.sendiri',
            'entitas' => 'uji',
        ]);

        LogAudit::create([
            'aktor_id' => $this->pemilik->id,
            'aktor_nama' => 'Pemilik Kos',
            'aksi' => 'uji.oranglain',
            'entitas' => 'uji',
        ]);

        $jejak = $this->sebagaiPenghuni()->getJson('/api/pdp/data-saya')->json('data.jejak_terkait');

        $this->assertCount(1, $jejak);
        $this->assertSame('uji.sendiri', $jejak[0]['aksi']);
    }

    // --- Penolakan penghapusan --------------------------------------------

    public function test_pemilik_tidak_bisa_menghapus_akunnya(): void
    {
        $this->sebagaiPemilik()
            ->deleteJson('/api/pdp/data-saya', ['konfirmasi' => 'HAPUS'])
            ->assertStatus(409)
            ->assertJsonPath('kode', 'penghapusan_belum_dapat_dilakukan');
    }

    public function test_pengelola_tidak_bisa_menghapus_akunnya_sendiri(): void
    {
        $pengelola = User::create([
            'name' => 'Sari Pengelola',
            'email' => 'pengelola@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $this->pemilik->id,
            'email_verified_at' => now(),
        ]);

        // Pencabutan pengelola adalah tindakan pemilik, bukan tindakan
        // pengelolanya sendiri: akun yang mengurus kos tidak boleh menghapus
        // dirinya lalu meninggalkan kos tanpa pengurus.
        $this->actingAs($pengelola, 'sanctum')
            ->deleteJson('/api/pdp/data-saya', ['konfirmasi' => 'HAPUS'])
            ->assertStatus(409);
    }

    public function test_penghuni_dengan_kontrak_aktif_tidak_bisa_menghapus(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $this->penghuni->id,
            'tgl_masuk' => '2026-10-01',
            'tarif_awal' => 1500000,
            'deposit' => 0,
            'status' => 'aktif',
        ]);

        // 409, bukan 403: pengguna berhak meminta, hanya waktunya belum tepat.
        // 403 terbaca seperti "hak Anda ditolak" padahal yang benar "tunggu
        // kontraknya selesai".
        $this->sebagaiPenghuni()
            ->deleteJson('/api/pdp/data-saya', ['konfirmasi' => 'HAPUS'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'kontrak aktif'));
    }

    public function test_penghapusan_menuntut_konfirmasi_tertulis(): void
    {
        $this->sebagaiPenghuni()
            ->deleteJson('/api/pdp/data-saya', ['konfirmasi' => 'hapus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('konfirmasi');

        $this->sebagaiPenghuni()
            ->deleteJson('/api/pdp/data-saya')
            ->assertStatus(422)
            ->assertJsonValidationErrors('konfirmasi');
    }

    /**
     * Konfirmasi yang salah dijawab 422 WALAU kontraknya masih aktif.
     *
     * Urutan pemeriksaan ini pernah terbalik, dan akibatnya bukan sekadar kode
     * status yang berbeda: penghuni yang salah mengetik konfirmasi diberi tahu
     * bahwa masalahnya adalah kontraknya, lalu memperbaiki hal yang tidak salah
     * sambil tidak pernah tahu bahwa pagar konfirmasinya tidak pernah diperiksa.
     *
     * Bentuk permintaan yang salah harus dijawab sebagai bentuk yang salah,
     * sebelum keadaan datanya diperiksa.
     */
    public function test_konfirmasi_salah_dijawab_422_bukan_409_walau_kontrak_aktif(): void
    {
        $kamar = Kamar::create(['nomor' => 'A2']);
        Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $this->penghuni->id,
            'tgl_masuk' => '2026-10-01',
            'tarif_awal' => 1500000,
            'deposit' => 0,
            'status' => 'aktif',
        ]);

        $this->sebagaiPenghuni()
            ->deleteJson('/api/pdp/data-saya', ['konfirmasi' => 'hapus'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('konfirmasi');

        // Dan konfirmasi yang BENAR tetap dijawab 409 - pemeriksaan keadaan
        // tidak hilang, hanya urutannya yang berpindah.
        $this->sebagaiPenghuni()
            ->deleteJson('/api/pdp/data-saya', ['konfirmasi' => 'HAPUS'])
            ->assertStatus(409);
    }

    // --- Anonimisasi -------------------------------------------------------

    public function test_penghapusan_melepas_identitas_dan_mempertahankan_catatan(): void
    {
        LogAudit::create([
            'aktor_id' => $this->akunPenghuni->id,
            'aktor_nama' => 'Budi Santoso',
            'aksi' => 'pembayaran.catat',
            'entitas' => 'pembayaran',
            'entitas_id' => 7,
            'nilai_baru' => ['jumlah' => 1500000],
        ]);

        $this->sebagaiPenghuni()
            ->deleteJson('/api/pdp/data-saya', ['konfirmasi' => 'HAPUS'])
            ->assertOk();

        $user = $this->akunPenghuni->fresh();
        $penghuni = $this->penghuni->fresh();

        // Identitas hilang.
        $this->assertSame(LayananPdp::NAMA_ANONIM, $user->name);
        $this->assertSame(LayananPdp::NAMA_ANONIM, $penghuni->nama);
        $this->assertNull($penghuni->email);
        $this->assertNull($penghuni->no_hp);
        $this->assertStringNotContainsString('budi@kos.test', $user->email);
        $this->assertNull($user->penghuni_id);
        $this->assertSame(User::PERAN_PENGHUNI, $user->peran);

        // Catatan tetap - dan masih menyebut angkanya.
        $jejak = LogAudit::where('aksi', 'pembayaran.catat')->firstOrFail();
        $this->assertNull($jejak->aktor_id);
        $this->assertSame(LayananPdp::NAMA_ANONIM, $jejak->aktor_nama);
        $this->assertSame(7, $jejak->entitas_id);
        $this->assertSame(['jumlah' => 1500000], $jejak->nilai_baru);
    }

    public function test_penghapusan_memutus_sesi_dan_tautan_yang_beredar(): void
    {
        $this->akunPenghuni->createToken('spa');

        $this->sebagaiPenghuni()
            ->deleteJson('/api/pdp/data-saya', ['konfirmasi' => 'HAPUS'])
            ->assertOk();

        $this->assertSame(0, $this->akunPenghuni->tokens()->count());
    }

    public function test_akun_yang_dihapus_tidak_bisa_dipakai_masuk_lagi(): void
    {
        $this->sebagaiPenghuni()
            ->deleteJson('/api/pdp/data-saya', ['konfirmasi' => 'HAPUS'])
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/login', [
            'email' => 'budi@kos.test',
            'password' => 'password',
        ])->assertStatus(401);
    }

    public function test_penghapusan_mempertahankan_baris_users_karena_riwayat_uang_menunjuk_ke_sana(): void
    {
        $this->sebagaiPenghuni()
            ->deleteJson('/api/pdp/data-saya', ['konfirmasi' => 'HAPUS'])
            ->assertOk();

        // `pembayaran.dicatat_oleh` dan `usulan_perubahan.diajukan_oleh` memakai
        // RESTRICT. Menghapus barisnya akan menghapus riwayat uang - jadi
        // barisnya tetap ada, hanya identitasnya yang dilepas.
        $this->assertDatabaseHas('users', ['id' => $this->akunPenghuni->id]);
        $this->assertDatabaseHas('penghuni', ['id' => $this->penghuni->id]);
    }

    // --- Celah anonimisasi pada trigger -----------------------------------

    public function test_update_log_audit_biasa_tetap_ditolak_setelah_celah_dibuka(): void
    {
        LogAudit::create([
            'aktor_id' => $this->akunPenghuni->id,
            'aktor_nama' => 'Budi Santoso',
            'aksi' => 'uji.biasa',
            'entitas' => 'uji',
        ]);

        // Celah T28 hanya terbuka BILA transaksi menyatakan dirinya sebagai
        // anonimisasi. Tanpa penanda itu, seluruh UPDATE tetap ditolak seperti
        // sebelum T28 - kalau tidak, R-07 berhenti berlaku hari ini.
        $this->expectException(QueryException::class);

        DB::transaction(function () {
            DB::table('log_audit')->update(['aktor_nama' => 'siapa saja']);
        });
    }

    public function test_penanda_anonimisasi_tidak_membuka_perubahan_kolom_lain(): void
    {
        LogAudit::create([
            'aktor_id' => $this->akunPenghuni->id,
            'aktor_nama' => 'Budi Santoso',
            'aksi' => 'uji.kolom',
            'entitas' => 'uji',
        ]);

        // Yang boleh berubah hanya dua kolom identitas. Percobaan mengubah
        // `aksi` di dalam transaksi berpenanda tetap ditolak - dan itu yang
        // membuat celah ini bisa disebut sempit.
        try {
            DB::transaction(function () {
                DB::statement("SET LOCAL kos.anonimisasi = 'on'");
                DB::table('log_audit')->update(['aksi' => 'aksi.palsu']);
            });
            $this->fail('Trigger seharusnya menolak perubahan kolom aksi.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame('uji.kolom', LogAudit::firstOrFail()->aksi);
    }

    public function test_penanda_anonimisasi_tidak_membuka_nama_pengganti_sewenang_wenang(): void
    {
        LogAudit::create([
            'aktor_id' => $this->akunPenghuni->id,
            'aktor_nama' => 'Budi Santoso',
            'aksi' => 'uji.nama',
            'entitas' => 'uji',
        ]);

        // `aktor_nama` hanya boleh menjadi "pengguna dihapus" - bukan teks lain
        // yang kebetulan dikirim bersama penanda. Kalau bebas, penanda ini
        // menjadi alat untuk menulis ulang sejarah dengan nama siapa pun.
        try {
            DB::transaction(function () {
                DB::statement("SET LOCAL kos.anonimisasi = 'on'");
                DB::table('log_audit')->update([
                    'aktor_id' => null,
                    'aktor_nama' => 'Nama Karangan',
                ]);
            });
            $this->fail('Trigger seharusnya menolak nama pengganti selain "pengguna dihapus".');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame('Budi Santoso', LogAudit::firstOrFail()->aktor_nama);
    }

    public function test_anonimisasi_tidak_menyentuh_jejak_orang_lain(): void
    {
        LogAudit::create([
            'aktor_id' => $this->akunPenghuni->id,
            'aktor_nama' => 'Budi Santoso',
            'aksi' => 'uji.budi',
            'entitas' => 'uji',
        ]);

        LogAudit::create([
            'aktor_id' => $this->pemilik->id,
            'aktor_nama' => 'Pemilik Kos',
            'aksi' => 'uji.pemilik',
            'entitas' => 'uji',
        ]);

        $this->sebagaiPenghuni()
            ->deleteJson('/api/pdp/data-saya', ['konfirmasi' => 'HAPUS'])
            ->assertOk();

        // Hak penghapusan satu orang bukan hak menghapus jejak orang lain.
        $this->assertSame(
            'Pemilik Kos',
            LogAudit::where('aksi', 'uji.pemilik')->firstOrFail()->aktor_nama
        );
    }

    /**
     * Jejak tentang AKUN tidak boleh menyalin nama dan email subjeknya.
     *
     * Ini kegagalan yang tidak terlihat sampai seseorang meminta hak
     * penghapusannya. `log_audit` hanya mengizinkan perubahan `aktor_*` - jadi
     * nama yang tersalin ke `nilai_baru` di sana akan tinggal SELAMANYA, tidak
     * peduli berapa kali penghapusan dijalankan. Yang tersisa sesudah penghuni
     * menghapus akunnya adalah justru hal yang ia minta dihapus.
     *
     * `entitas_id` sudah menunjuk akunnya, dan namanya bisa dibaca dari sana
     * selama akunnya ada. Menyalinnya ke sini tidak menambah keterangan apa pun
     * yang belum ada, hanya menambah satu salinan yang tidak bisa ditarik
     * kembali.
     */
    public function test_jejak_akun_tidak_menyalin_nama_dan_email_subjeknya(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/pengelola', [
                'nama' => 'Pengelola Satu',
                'email' => 'pengelola-satu@kos.test',
                'wewenang' => ['catat_pembayaran'],
            ])
            ->assertCreated();

        $jejak = LogAudit::where('aksi', 'pengelola.tambah')->firstOrFail();

        $this->assertArrayNotHasKey('nama', $jejak->nilai_baru);
        $this->assertArrayNotHasKey('email', $jejak->nilai_baru);

        // Yang harus tetap ada: wewenangnya, dan penunjuk ke akunnya.
        $this->assertSame(['catat_pembayaran'], $jejak->nilai_baru['wewenang']);
        $this->assertNotNull($jejak->entitas_id);
        $this->assertSame('users', $jejak->entitas);
    }

    // --- Persetujuan sebagai gerbang --------------------------------------

    public function test_persetujuan_bukan_gerbang_yang_menahan_pekerjaan(): void
    {
        // Berbeda dari `wajib_ganti_sandi` yang menahan seluruh endpoint,
        // persetujuan PDP hanya dilaporkan lewat `meta` dan diputuskan layar.
        // Menahannya di server akan mengunci penghuni dari tagihannya sendiri
        // karena kotak centang - dan itu bukan perlindungan, itu pemerasan.
        $this->sebagaiPenghuni()
            ->getJson('/api/pdp/data-saya')
            ->assertOk();
    }
}
