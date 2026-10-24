<?php

namespace Tests\Feature;

use App\Domain\Akses\Wewenang;
use App\Domain\Persetujuan\JenisUsulan;
use App\Models\KomponenBiaya;
use App\Models\LogAudit;
use App\Models\PengelolaKewenangan;
use App\Models\User;
use App\Models\UsulanPerubahan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * REV-SEC-07 - komponen biaya ikut jalur usulan.
 *
 * Sebelum perbaikan ini `POST /komponen-biaya` menulis baris untuk **kedua**
 * peran. Akibatnya `ubah_tarif` yang seharusnya berarti "boleh mengusulkan"
 * menjadi "boleh menambah biaya bulanan ke seluruh kamar tanpa persetujuan" -
 * dan karena menambah komponen berarti menaikkan tagihan setiap penghuni,
 * itulah jalur yang paling merugikan bila disalahgunakan.
 *
 * Bentuknya disamakan dengan tarif dan kebijakan, termasuk endpoint yang sama:
 * pemilik menulis sekarang (201), pengelola mengusulkan (202). Tidak ada
 * wewenang baru - `ubah_tarif` yang sudah ada dipakai kembali, karena menambah
 * wewenang baru akan diam-diam mencabut hak pengelola yang sudah memilikinya.
 */
class KomponenUsulanTest extends TestCase
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

        $this->pengelola = User::create([
            'name' => 'Sari Pengelola',
            'email' => 'pengelola@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $this->pemilik->id,
        ]);
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    private function sebagaiPengelola(bool $denganWewenang = true)
    {
        if ($denganWewenang) {
            PengelolaKewenangan::firstOrCreate([
                'user_id' => $this->pengelola->id,
                'wewenang' => Wewenang::UBAH_TARIF,
            ]);
            $this->pengelola->unsetRelation('kewenangan');
        }

        return $this->actingAs($this->pengelola, 'sanctum');
    }

    /**
     * @param  array<string, mixed>  $ganti
     */
    private function kirim(array $ganti = [])
    {
        return $ganti + [
            'nama' => 'Kebersihan',
            'nilai' => 50_000,
            'berlaku_dari' => '2026-11-01',
        ];
    }

    // --- Dua nasib, satu endpoint -----------------------------------------

    public function test_pemilik_menulis_komponen_langsung_201(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/komponen-biaya', $this->kirim())
            ->assertCreated()
            ->assertJsonPath('meta.diajukan', false);

        $this->assertSame(1, KomponenBiaya::count());
        $this->assertSame(0, UsulanPerubahan::count());
    }

    public function test_pengelola_mengusulkan_dan_komponen_belum_berubah(): void
    {
        $this->sebagaiPengelola()
            ->postJson('/api/komponen-biaya', $this->kirim())
            ->assertStatus(202)
            ->assertJsonPath('meta.diajukan', true);

        // Ini inti perbaikannya: tidak ada baris komponen yang lahir dari
        // usulan yang belum disetujui.
        $this->assertSame(0, KomponenBiaya::count());

        $usulan = UsulanPerubahan::latest('id')->firstOrFail();
        $this->assertSame(UsulanPerubahan::TIPE_KOMPONEN, $usulan->tipe);
        $this->assertSame(UsulanPerubahan::STATUS_MENUNGGU, $usulan->status);
        $this->assertSame($this->pengelola->id, $usulan->diajukan_oleh);
        $this->assertSame('Kebersihan', $usulan->payload['nama']);
    }

    public function test_pengelola_tanpa_ubah_tarif_ditolak(): void
    {
        $this->sebagaiPengelola(denganWewenang: false)
            ->postJson('/api/komponen-biaya', $this->kirim())
            ->assertForbidden();

        $this->assertSame(0, UsulanPerubahan::count());
        $this->assertSame(0, KomponenBiaya::count());
    }

    public function test_persetujuan_pemilik_menulis_komponen(): void
    {
        $this->sebagaiPengelola()
            ->postJson('/api/komponen-biaya', $this->kirim())
            ->assertStatus(202);

        $usulan = UsulanPerubahan::latest('id')->firstOrFail();

        $this->sebagaiPemilik()
            ->postJson("/api/usulan/{$usulan->id}/setujui")
            ->assertOk();

        $komponen = KomponenBiaya::sole();
        $this->assertSame('Kebersihan', $komponen->nama);
        $this->assertSame(50_000, (int) $komponen->nilai);
        $this->assertSame(UsulanPerubahan::STATUS_DISETUJUI, $usulan->fresh()->status);
    }

    public function test_penolakan_tidak_menulis_komponen(): void
    {
        $this->sebagaiPengelola()
            ->postJson('/api/komponen-biaya', $this->kirim())
            ->assertStatus(202);

        $usulan = UsulanPerubahan::latest('id')->firstOrFail();

        $this->sebagaiPemilik()
            ->postJson("/api/usulan/{$usulan->id}/tolak", ['alasan' => 'Belum perlu'])
            ->assertOk();

        $this->assertSame(0, KomponenBiaya::count());
        $this->assertSame(UsulanPerubahan::STATUS_DITOLAK, $usulan->fresh()->status);
    }

    // --- Jejak -------------------------------------------------------------

    public function test_pemilik_menulis_bukan_tanpa_jejak(): void
    {
        // Sebelum REV-SEC-07 jalur ini memanggil `KomponenBiaya::create()`
        // langsung, sehingga komponen termahal yang pernah ditambahkan tidak
        // meninggalkan jejak apa pun - berbeda dari tarif dan kebijakan yang
        // sudah punya.
        $this->sebagaiPemilik()
            ->postJson('/api/komponen-biaya', $this->kirim())
            ->assertCreated();

        $jejak = LogAudit::query()
            ->where('aksi', 'komponen.tambah')
            ->firstOrFail();

        $this->assertSame('komponen_biaya', $jejak->entitas);
        $this->assertSame($this->pemilik->id, (int) $jejak->aktor_id);
        $this->assertSame(50_000, (int) $jejak->nilai_baru['nilai']);
    }

    public function test_dua_baris_audit_untuk_jalur_usulan(): void
    {
        $this->sebagaiPengelola()
            ->postJson('/api/komponen-biaya', $this->kirim())
            ->assertStatus(202);

        $usulan = UsulanPerubahan::latest('id')->firstOrFail();

        $this->sebagaiPemilik()
            ->postJson("/api/usulan/{$usulan->id}/setujui")
            ->assertOk();

        // Dua aktor, dua baris: pengelola mengusulkan, pemilik menetapkan.
        // Menggabungkannya menjadi satu baris akan menghapus fakta bahwa ada
        // usulan sebelumnya.
        $this->assertDatabaseHas('log_audit', [
            'aksi' => 'komponen.usul',
            'aktor_id' => $this->pengelola->id,
        ]);
        $this->assertDatabaseHas('log_audit', [
            'aksi' => 'komponen.setujui',
            'aktor_id' => $this->pemilik->id,
        ]);
    }

    // --- Bentrok tanggal ---------------------------------------------------

    public function test_persetujuan_yang_bentrok_tanggal_menjawab_409(): void
    {
        // Pola yang sama dengan tarif: usulan yang disetujui tetapi kalah balapan
        // tanggal tidak boleh menghapus usulannya. 409 membuat pemilik bisa
        // memperbaiki tanggalnya lalu menyetujui ulang, bukan mengetik dari nol.
        //
        // Bentroknya harus LAHIR SESUDAH usulan dibuat - validasi FormRequest
        // memeriksa duplikat saat mengajukan, jadi bentrok yang bisa terjadi
        // hanyalah yang muncul di antara pengajuan dan persetujuan.
        $this->sebagaiPengelola()
            ->postJson('/api/komponen-biaya', $this->kirim())
            ->assertStatus(202);

        $usulan = UsulanPerubahan::latest('id')->firstOrFail();

        KomponenBiaya::create([
            'nama' => 'Kebersihan',
            'nilai' => 50_000,
            'berlaku_dari' => '2026-11-01',
        ]);

        $this->sebagaiPemilik()
            ->postJson("/api/usulan/{$usulan->id}/setujui")
            ->assertStatus(409);

        $this->assertSame(UsulanPerubahan::STATUS_MENUNGGU, $usulan->fresh()->status);
        $this->assertSame(1, KomponenBiaya::count());
    }

    // --- Pemberitahuan -----------------------------------------------------

    public function test_perubahan_komponen_memberitahu_pengurus_lain(): void
    {
        // Pelaku tidak diberi tahu tentang perbuatannya sendiri, jadi yang
        // menerima adalah pengelola - bukan pemilik yang menekan Simpan.
        $this->sebagaiPemilik()
            ->postJson('/api/komponen-biaya', $this->kirim())
            ->assertCreated();

        // Pemberitahuan in-app ditulis lewat kanal `database`, jadi barisnya ada
        // di tabel `notifications` Laravel: `notifiable_id` + JSON `data`.
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->pengelola->id,
            'notifiable_type' => User::class,
        ]);

        $baris = DB::table('notifications')
            ->where('notifiable_id', $this->pengelola->id)
            ->firstOrFail();

        $data = json_decode($baris->data, true);
        $this->assertSame('komponen.diubah', $data['peristiwa']);

        // Pemilik sebagai pelaku tidak menerima salinannya sendiri.
        $this->assertSame(
            0,
            DB::table('notifications')->where('notifiable_id', $this->pemilik->id)->count(),
        );
    }

    public function test_komponen_diubah_terdaftar_sebagai_jenis_usulan(): void
    {
        // Penjaga daftar tertutup: tipe yang dikenal harus punya wewenang dan
        // aksi audit, bukan hanya konstanta yang ditambahkan lalu lupa diurus.
        $this->assertContains(UsulanPerubahan::TIPE_KOMPONEN, JenisUsulan::SEMUA);
        $this->assertSame(Wewenang::UBAH_TARIF, JenisUsulan::wewenang(UsulanPerubahan::TIPE_KOMPONEN));
        $this->assertSame('komponen.usul', JenisUsulan::aksiUsul(UsulanPerubahan::TIPE_KOMPONEN));
        $this->assertSame('komponen.setujui', JenisUsulan::aksiSetujui(UsulanPerubahan::TIPE_KOMPONEN));
        $this->assertSame('komponen.tolak', JenisUsulan::aksiTolak(UsulanPerubahan::TIPE_KOMPONEN));
    }
}
