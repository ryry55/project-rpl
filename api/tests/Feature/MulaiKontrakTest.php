<?php

namespace Tests\Feature;

use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Perintah `kontrak:mulai` (S1.9).
 *
 * Yang diuji bukan sekadar "perintahnya berjalan", melainkan tiga akibat yang
 * hanya terlihat dari data: status kontrak berubah, status kamar ikut berubah,
 * dan kontrak yang belum jatuh tanggalnya TIDAK ikut berubah. Yang terakhir
 * sama pentingnya - perintah yang mengubah semuanya akan lulus dua pengujian
 * pertama dan merusak reservasi.
 */
class MulaiKontrakTest extends TestCase
{
    use RefreshDatabase;

    private function kontrak(string $nomor, string $tglMasuk, string $status = 'dipesan'): Kontrak
    {
        $kamar = Kamar::create(['nomor' => $nomor, 'status_huni' => $status === 'dipesan' ? 'dipesan' : 'kosong']);
        $penghuni = Penghuni::create([
            'nama' => 'Penghuni '.$nomor,
            'email' => strtolower($nomor).'@kos.test',
        ]);

        return Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => $tglMasuk,
            'tarif_awal' => 1500000,
            'status' => $status,
        ]);
    }

    public function test_kontrak_yang_tanggalnya_tiba_menjadi_aktif(): void
    {
        $kontrak = $this->kontrak('A1', '2026-11-01');

        $this->artisan('kontrak:mulai', ['--tanggal' => '2026-11-01'])
            ->assertExitCode(0);

        $this->assertSame('aktif', $kontrak->fresh()->status);
        $this->assertSame('terisi', Kamar::find($kontrak->kamar_id)->status_huni);
    }

    public function test_kontrak_yang_belum_tanggalnya_tidak_disentuh(): void
    {
        $kontrak = $this->kontrak('A2', '2026-12-01');

        $this->artisan('kontrak:mulai', ['--tanggal' => '2026-11-15'])
            ->assertExitCode(0);

        // Inilah yang membedakan perintah ini dari "ubah semua menjadi aktif".
        $this->assertSame('dipesan', $kontrak->fresh()->status);
        $this->assertSame('dipesan', Kamar::find($kontrak->kamar_id)->status_huni);
    }

    public function test_perintah_bisa_dijalankan_dua_kali_tanpa_efek_ganda(): void
    {
        $kontrak = $this->kontrak('A3', '2026-11-01');

        $this->artisan('kontrak:mulai', ['--tanggal' => '2026-11-05'])->assertExitCode(0);
        $this->artisan('kontrak:mulai', ['--tanggal' => '2026-11-06'])->assertExitCode(0);

        $this->assertSame('aktif', $kontrak->fresh()->status);
        $this->assertSame('terisi', Kamar::find($kontrak->kamar_id)->status_huni);
    }

    public function test_mode_uji_tidak_mengubah_apa_pun(): void
    {
        $kontrak = $this->kontrak('A4', '2026-11-01');

        $this->artisan('kontrak:mulai', ['--tanggal' => '2026-11-01', '--uji' => true])
            ->assertExitCode(0);

        // `--uji` ada supaya operator bisa melihat apa yang akan terjadi pada
        // data sungguhan sebelum menjalankannya. Kalau mode ini mengubah data,
        // ia tidak berguna untuk hal itu.
        $this->assertSame('dipesan', $kontrak->fresh()->status);
        $this->assertSame('dipesan', Kamar::find($kontrak->kamar_id)->status_huni);
    }

    public function test_kontrak_dipesan_tidak_ikut_ditagih_massal(): void
    {
        $kontrak = $this->kontrak('A5', '2026-12-01');

        // Penerbitan massal hanya menyaring kontrak `aktif`. Kontrak yang masih
        // dipesan karena itu tidak boleh menghasilkan tagihan - menagih bulan
        // yang belum ditempati adalah keluhan yang paling sulit dijelaskan.
        $this->artisan('tagihan:terbitkan', ['periode' => '2026-12-01'])
            ->assertExitCode(0);

        $this->assertSame(0, Tagihan::where('kontrak_id', $kontrak->id)->count());
    }

    /**
     * Temuan #4 (Fase 8): `akhiri()` memeriksa "masih ada kontrak aktif?"
     * lalu menulis status kamar. Tanpa kunci baris kamar, kontrak baru
     * yang tercatat di antara keduanya tidak terlihat - kamar tampil
     * kosong padahal berpenghuni. Dengan kunci, pemeriksaan terjadi
     * setelah kunci dipegang: kontrak yang tercatat sebelum pemeriksaan
     * harus terlihat.
     */
    public function test_akhiri_melihat_kontrak_yang_tercatat_sebelum_pemeriksaan(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1', 'status_huni' => 'terisi']);
        $budi = Penghuni::create(['nama' => 'Budi', 'email' => 'budi@kos.test']);

        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $budi->id,
            'tgl_masuk' => '2026-09-01',
            'tarif_awal' => 1000000,
            'status' => 'aktif',
        ]);

        // Simulasi pembalap: kontrak lain untuk kamar yang sama tercatat
        // tepat sebelum akhiri memeriksa. Dengan kunci baris kamar,
        // pemeriksaan berada di dalam wilayah yang terlindungi.
        $sudah = false;
        Kamar::retrieved(function () use (&$sudah, $kamar) {
            if ($sudah) {
                return;
            }
            $sudah = true;

            $sari = Penghuni::create(['nama' => 'Sari', 'email' => 'sari@kos.test']);
            Kontrak::create([
                'kamar_id' => $kamar->id,
                'penghuni_id' => $sari->id,
                'tgl_masuk' => '2026-11-17',
                'tarif_awal' => 1000000,
                'status' => 'aktif',
            ]);
        });

        $pemilik = User::firstOrCreate(
            ['email' => 'pemilik@kos.test'],
            ['name' => 'Pemilik', 'password' => Hash::make('password'), 'peran' => User::PERAN_PEMILIK],
        );

        $this->actingAs($pemilik, 'sanctum')
            ->postJson("/api/kontrak/{$kontrak->id}/akhiri")
            ->assertOk();

        Kamar::flushEventListeners();

        // Kamar tidak boleh tampil kosong padahal kontrak Sari aktif.
        $this->assertSame('terisi', $kamar->fresh()->status_huni);
        $this->assertSame('selesai', $kontrak->fresh()->status);
    }
}
