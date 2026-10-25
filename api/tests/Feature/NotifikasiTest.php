<?php

namespace Tests\Feature;

use App\Domain\Akses\Wewenang;
use App\Domain\Kebijakan\PilihanKebijakan;
use App\Domain\Notifikasi\Peristiwa;
use App\Mail\PemberitahuanKos;
use App\Models\Kamar;
use App\Models\KebijakanKos;
use App\Models\Kontrak;
use App\Models\PengaturanNotifikasi;
use App\Models\PengelolaKewenangan;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\TarifSewa;
use App\Models\User;
use App\Models\UsulanPerubahan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * T25 - notifikasi dua kanal.
 *
 * Empat hal yang dibuktikan, dan yang pertama adalah yang paling penting:
 *
 *   1. **Email dikirim SETELAH transaksi, bukan di dalamnya.** Kalau email
 *      dikirim di dalam transaksi, SMTP yang menggantung menahan transaksi ubah
 *      tarif dan SMTP yang gagal membatalkan perubahan tarif itu sendiri. Yang
 *      diuji: perubahan tetap tersimpan saat email gagal.
 *
 *   2. **Notifikasi bukan kontrol.** Perubahan yang sah tetap sah walau tidak
 *      ada yang menerima pemberitahuannya.
 *
 *   3. **Pelaku tidak diberi tahu perbuatannya sendiri.** Notifikasi untuk diri
 *      sendiri adalah kebisingan yang membuat notifikasi orang lain diabaikan.
 *
 *   4. **Preferensi per akun, bukan per kos.** Satu pengelola yang mematikan
 *      emailnya tidak boleh mendiamkan peringatan pemilik.
 */
class NotifikasiTest extends TestCase
{
    use RefreshDatabase;

    private User $pemilik;

    private User $pengelola;

    private Kamar $kamar;

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

        $this->kamar = Kamar::create(['nomor' => 'A1']);
        TarifSewa::create(['kamar_id' => $this->kamar->id, 'nilai' => 1500000, 'berlaku_dari' => '2026-01-01']);
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    private function sebagaiPengelola(string ...$wewenang)
    {
        foreach ($wewenang as $w) {
            PengelolaKewenangan::firstOrCreate([
                'user_id' => $this->pengelola->id,
                'wewenang' => $w,
            ]);
        }

        $this->pengelola->unsetRelation('kewenangan');

        return $this->actingAs($this->pengelola, 'sanctum');
    }

    private function sebagaiPenghuni()
    {
        return $this->actingAs($this->akunPenghuni, 'sanctum');
    }

    private function lengkapiKebijakan(): void
    {
        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_SATUAN_SEWA,
            'nilai' => PilihanKebijakan::SATUAN_BULANAN,
            'berlaku_dari' => '2026-01-01',
        ]);
        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_JATUH_TEMPO,
            'nilai' => PilihanKebijakan::JATUH_TEMPO_TANGGAL_TETAP,
            'tanggal' => 10,
            'berlaku_dari' => '2026-01-01',
        ]);
        KebijakanKos::create([
            'tipe' => PilihanKebijakan::TIPE_DENDA,
            'nilai' => PilihanKebijakan::DENDA_TANPA_DENDA,
            'berlaku_dari' => '2026-01-01',
        ]);
    }

    private function kontrak(): Kontrak
    {
        return Kontrak::create([
            'kamar_id' => $this->kamar->id,
            'penghuni_id' => $this->penghuni->id,
            'tgl_masuk' => '2026-01-01',
            'tarif_awal' => 1500000,
            'status' => 'aktif',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function isiKebijakanDenda(): array
    {
        return [
            'tipe' => PilihanKebijakan::TIPE_DENDA,
            'nilai' => PilihanKebijakan::DENDA_PERSEN_HARIAN,
            'persen' => 1,
            'plafon_persen' => 30,
            'masa_tenggang' => 7,
            'berlaku_dari' => '2026-11-01',
        ];
    }

    // --- Penulisan in-app --------------------------------------------------

    public function test_perubahan_tarif_menulis_notifikasi_in_app_untuk_pengurus_lain(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/tarif', [
                'kamar_id' => $this->kamar->id,
                'nilai' => 2000000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertCreated();

        // Pelakunya pemilik, jadi yang menerima adalah pengelola - bukan
        // sebaliknya, dan bukan keduanya.
        $this->assertSame(0, $this->pemilik->notifications()->count());

        $notifikasi = $this->pengelola->notifications()->sole();

        $this->assertSame(Peristiwa::TARIF_DIUBAH, $notifikasi->data['peristiwa']);
        $this->assertStringContainsString('A1', $notifikasi->data['pesan']);
    }

    public function test_pengelola_yang_mengusulkan_tidak_memberi_tahu_dirinya_sendiri(): void
    {
        $this->sebagaiPengelola(Wewenang::UBAH_TARIF)
            ->postJson('/api/tarif', [
                'kamar_id' => $this->kamar->id,
                'nilai' => 2000000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertStatus(202);

        $this->assertSame(0, $this->pengelola->notifications()->count());

        // Yang menerima adalah pemilik: dialah yang harus memutuskan.
        $notifikasi = $this->pemilik->notifications()->sole();
        $this->assertSame(Peristiwa::USULAN_DIAJUKAN, $notifikasi->data['peristiwa']);
    }

    public function test_persetujuan_memberi_tahu_pengusul(): void
    {
        $this->sebagaiPengelola(Wewenang::UBAH_TARIF)
            ->postJson('/api/tarif', [
                'kamar_id' => $this->kamar->id,
                'nilai' => 2000000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertStatus(202);

        $usulan = UsulanPerubahan::sole();

        $this->sebagaiPemilik()->postJson("/api/usulan/{$usulan->id}/setujui")->assertOk();

        $peristiwa = $this->pengelola->notifications()
            ->get()
            ->map(fn ($n) => $n->data['peristiwa'])
            ->all();

        $this->assertContains(Peristiwa::USULAN_DIPUTUSKAN, $peristiwa);
        $this->assertContains(Peristiwa::TARIF_DIUBAH, $peristiwa);
    }

    // --- Email setelah transaksi ------------------------------------------

    public function test_email_dikirim_setelah_transaksi(): void
    {
        Mail::fake();

        $this->sebagaiPemilik()
            ->postJson('/api/tarif', [
                'kamar_id' => $this->kamar->id,
                'nilai' => 2000000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertCreated();

        Mail::assertSent(PemberitahuanKos::class, function (PemberitahuanKos $mail) {
            return $mail->hasTo('pengelola@kos.test')
                && $mail->peristiwa === Peristiwa::TARIF_DIUBAH;
        });
    }

    public function test_email_gagal_tidak_membatalkan_perubahan(): void
    {
        // Inti T25. Email adalah kesopanan, bukan syarat sahnya perubahan.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP mati'));

        $this->sebagaiPemilik()
            ->postJson('/api/tarif', [
                'kamar_id' => $this->kamar->id,
                'nilai' => 2000000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertCreated();

        // Perubahan tetap tersimpan, dan notifikasi in-app tetap ada.
        $this->assertSame(1, TarifSewa::where('nilai', 2000000)->count());
        $this->assertSame(1, $this->pengelola->notifications()->count());
    }

    // --- Preferensi kanal -------------------------------------------------

    public function test_email_bisa_dimatikan_per_akun(): void
    {
        Mail::fake();
        Notification::fake();

        PengaturanNotifikasi::create([
            'user_id' => $this->pengelola->id,
            'peristiwa' => Peristiwa::TARIF_DIUBAH,
            'kanal' => Peristiwa::KANAL_EMAIL,
            'aktif' => false,
        ]);

        $this->sebagaiPemilik()
            ->postJson('/api/tarif', [
                'kamar_id' => $this->kamar->id,
                'nilai' => 2000000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertCreated();

        Mail::assertNotSent(PemberitahuanKos::class);
    }

    public function test_preferensi_satu_akun_tidak_mendiamkan_akun_lain(): void
    {
        Mail::fake();

        // Pengelola mematikan emailnya sendiri.
        PengaturanNotifikasi::create([
            'user_id' => $this->pengelola->id,
            'peristiwa' => Peristiwa::USULAN_DIAJUKAN,
            'kanal' => Peristiwa::KANAL_EMAIL,
            'aktif' => false,
        ]);

        $this->sebagaiPengelola(Wewenang::UBAH_TARIF)
            ->postJson('/api/tarif', [
                'kamar_id' => $this->kamar->id,
                'nilai' => 2000000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertStatus(202);

        // Pemilik tetap menerima: preferensinya milik pengelola sendiri.
        Mail::assertSent(PemberitahuanKos::class, fn (PemberitahuanKos $m) => $m->hasTo('pemilik@kos.test'));
    }

    public function test_bawaan_tanpa_baris_pengaturan(): void
    {
        // Akun baru yang belum pernah membuka layar Pengaturan sudah punya
        // perilaku yang masuk akal: tarif diubah = in-app + email.
        //
        // Diperiksa lewat closure, bukan `assertJsonPath('data.matriks.tarif.diubah.in_app')`:
        // nama peristiwanya mengandung titik, dan titik adalah pemisah jalur
        // pada `data_get`. Menulis jalurnya akan selalu menghasilkan null.
        $this->sebagaiPemilik()
            ->getJson('/api/pengaturan-notifikasi')
            ->assertOk()
            ->assertJsonPath('data.matriks', function (array $matriks) {
                return $matriks[Peristiwa::TARIF_DIUBAH][Peristiwa::KANAL_IN_APP] === true
                    && $matriks[Peristiwa::TARIF_DIUBAH][Peristiwa::KANAL_EMAIL] === true
                    // Yang terjadi puluhan kali sehari: in-app saja.
                    && $matriks[Peristiwa::PEMBAYARAN_DICATAT][Peristiwa::KANAL_IN_APP] === true
                    && $matriks[Peristiwa::PEMBAYARAN_DICATAT][Peristiwa::KANAL_EMAIL] === false;
            });
    }

    public function test_kanal_in_app_tidak_bisa_dimatikan(): void
    {
        $this->sebagaiPemilik()
            ->putJson('/api/pengaturan-notifikasi', [
                'matriks' => [
                    Peristiwa::TARIF_DIUBAH => [
                        Peristiwa::KANAL_IN_APP => false,
                        Peristiwa::KANAL_EMAIL => true,
                    ],
                ],
            ])
            ->assertOk()
            // Server memaksa true, bukan menyimpan `false` yang tidak pernah
            // dijalankan. Baris yang berisi keputusan mati yang diabaikan
            // membuat pembaca berikutnya mengira keputusan itu berlaku.
            ->assertJsonPath(
                'data.matriks',
                fn (array $m) => $m[Peristiwa::TARIF_DIUBAH][Peristiwa::KANAL_IN_APP] === true,
            );

        // Tidak ada baris `false` sama sekali.
        $this->assertSame(0, PengaturanNotifikasi::where('aktif', false)->count());

        // Dan tidak ada baris `in_app` yang tersimpan: `true` adalah bawaan,
        // jadi menulisnya hanya menduplikasi konstanta ke dalam tabel.
        $this->assertSame(0, PengaturanNotifikasi::where('kanal', 'in_app')->count());
    }

    public function test_baris_yang_sama_dengan_bawaan_tidak_disimpan(): void
    {
        // Inti alasan tabel ini ada. Kalau setiap simpan menulis seluruh
        // matriks, akun yang pernah membuka layar ini sekali akan terkunci pada
        // bawaan hari itu - dan perubahan bawaan di kode berhenti berlaku
        // baginya tanpa ada yang menyadarinya.
        $this->sebagaiPemilik()
            ->putJson('/api/pengaturan-notifikasi', [
                'matriks' => [
                    Peristiwa::TARIF_DIUBAH => [
                        Peristiwa::KANAL_IN_APP => true,
                        Peristiwa::KANAL_EMAIL => true,
                    ],
                    Peristiwa::PEMBAYARAN_DICATAT => [
                        Peristiwa::KANAL_IN_APP => true,
                        Peristiwa::KANAL_EMAIL => false,
                    ],
                ],
            ])
            ->assertOk();

        // Keempat nilai di atas sama dengan bawaannya, jadi tidak satu pun
        // perlu disimpan.
        $this->assertSame(0, PengaturanNotifikasi::count());

        // Yang menyimpang tetap disimpan - satu baris, bukan dua.
        $this->sebagaiPemilik()
            ->putJson('/api/pengaturan-notifikasi', [
                'matriks' => [
                    Peristiwa::TARIF_DIUBAH => [
                        Peristiwa::KANAL_IN_APP => true,
                        Peristiwa::KANAL_EMAIL => false,
                    ],
                ],
            ])
            ->assertOk();

        $this->assertSame(1, PengaturanNotifikasi::count());

        $baris = PengaturanNotifikasi::sole();
        $this->assertSame(Peristiwa::TARIF_DIUBAH, $baris->peristiwa);
        $this->assertSame(Peristiwa::KANAL_EMAIL, $baris->kanal);
        $this->assertFalse((bool) $baris->aktif);
    }

    public function test_menyimpan_satu_peristiwa_tidak_menghapus_peristiwa_lain(): void
    {
        $this->sebagaiPemilik()
            ->putJson('/api/pengaturan-notifikasi', [
                'matriks' => [
                    Peristiwa::TARIF_DIUBAH => [
                        Peristiwa::KANAL_EMAIL => false,
                    ],
                ],
            ])
            ->assertOk();

        $this->sebagaiPemilik()
            ->putJson('/api/pengaturan-notifikasi', [
                'matriks' => [
                    Peristiwa::KEBIJAKAN_DIUBAH => [
                        Peristiwa::KANAL_EMAIL => false,
                    ],
                ],
            ])
            ->assertOk();

        // Dua baris, bukan satu: `delete` hanya menyentuh peristiwa yang
        // dikirim. Menghapus seluruh baris pengguna lalu menulis sebagian akan
        // mengembalikan tarif ke bawaan tanpa ada yang memintanya.
        $this->assertSame(2, PengaturanNotifikasi::count());
    }

    public function test_peristiwa_tidak_dikenal_ditolak(): void
    {
        $this->sebagaiPemilik()
            ->putJson('/api/pengaturan-notifikasi', [
                'matriks' => ['peristiwa.karangan' => [Peristiwa::KANAL_IN_APP => true]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('matriks');
    }

    public function test_kanal_tidak_dikenal_ditolak(): void
    {
        $this->sebagaiPemilik()
            ->putJson('/api/pengaturan-notifikasi', [
                'matriks' => [Peristiwa::TARIF_DIUBAH => ['whatsapp' => true]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('matriks');
    }

    public function test_pengaturan_berlaku_setelah_disimpan(): void
    {
        $this->sebagaiPemilik()
            ->putJson('/api/pengaturan-notifikasi', [
                'matriks' => [
                    Peristiwa::TARIF_DIUBAH => [
                        Peristiwa::KANAL_IN_APP => true,
                        Peristiwa::KANAL_EMAIL => false,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.matriks',
                fn (array $m) => $m[Peristiwa::TARIF_DIUBAH][Peristiwa::KANAL_EMAIL] === false,
            );

        // Dibaca ulang di permintaan baru: tembolok sudah dilupakan.
        $this->sebagaiPemilik()
            ->getJson('/api/pengaturan-notifikasi')
            ->assertOk()
            ->assertJsonPath(
                'data.matriks',
                fn (array $m) => $m[Peristiwa::TARIF_DIUBAH][Peristiwa::KANAL_EMAIL] === false,
            );
    }

    // --- Kotak masuk ------------------------------------------------------

    public function test_kotak_masuk_hanya_berisi_milik_sendiri(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/tarif', [
                'kamar_id' => $this->kamar->id,
                'nilai' => 2000000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertCreated();

        $this->sebagaiPengelola()
            ->getJson('/api/notifikasi')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.belum_dibaca', 1);

        // Pemilik adalah pelakunya, jadi kotak masuknya kosong.
        $this->sebagaiPemilik()
            ->getJson('/api/notifikasi')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_notifikasi_milik_orang_lain_menghasilkan_404(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/tarif', [
                'kamar_id' => $this->kamar->id,
                'nilai' => 2000000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertCreated();

        $milikPengelola = $this->pengelola->notifications()->sole();

        // 404, bukan 403: 403 membocorkan bahwa notifikasi itu ada.
        $this->sebagaiPemilik()
            ->postJson("/api/notifikasi/{$milikPengelola->id}/baca")
            ->assertNotFound();
    }

    public function test_menandai_terbaca(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/tarif', [
                'kamar_id' => $this->kamar->id,
                'nilai' => 2000000,
                'berlaku_dari' => '2026-11-01',
            ])
            ->assertCreated();

        $notifikasi = $this->pengelola->notifications()->sole();

        $this->sebagaiPengelola()
            ->postJson("/api/notifikasi/{$notifikasi->id}/baca")
            ->assertOk();

        $this->assertNotNull($notifikasi->fresh()->read_at);
        $this->assertSame(0, $this->pengelola->unreadNotifications()->count());
    }

    public function test_menandai_semua_terbaca(): void
    {
        foreach (['2026-11-01', '2026-12-01', '2027-01-01'] as $tanggal) {
            $this->sebagaiPemilik()
                ->postJson('/api/tarif', [
                    'kamar_id' => $this->kamar->id,
                    'nilai' => 2000000,
                    'berlaku_dari' => $tanggal,
                ])
                ->assertCreated();
        }

        $this->assertSame(3, $this->pengelola->unreadNotifications()->count());

        $this->sebagaiPengelola()
            ->postJson('/api/notifikasi/baca-semua')
            ->assertOk()
            ->assertJsonPath('data.ditandai', 3);

        $this->assertSame(0, $this->pengelola->unreadNotifications()->count());
    }

    public function test_kotak_masuk_terbuka_untuk_penghuni(): void
    {
        $this->lengkapiKebijakan();
        $kontrak = $this->kontrak();

        $this->sebagaiPemilik()
            ->postJson('/api/tagihan', [
                'kontrak_id' => $kontrak->id,
                'periode' => '2026-02-01',
            ])
            ->assertCreated();

        // Penghuni adalah satu-satunya pihak yang justru paling perlu tahu
        // tagihannya terbit. Menutup kotak masuknya dengan peran akan
        // membatalkan gunanya notifikasi tagihan.
        $this->sebagaiPenghuni()
            ->getJson('/api/notifikasi')
            ->assertOk()
            ->assertJsonPath('meta.belum_dibaca', 1)
            ->assertJsonPath('data.0.peristiwa', Peristiwa::TAGIHAN_TERBIT);
    }

    public function test_tanpa_login_ditolak(): void
    {
        $this->getJson('/api/notifikasi')->assertUnauthorized();
        $this->getJson('/api/pengaturan-notifikasi')->assertUnauthorized();
    }
}
