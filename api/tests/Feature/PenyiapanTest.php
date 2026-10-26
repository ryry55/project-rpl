<?php

namespace Tests\Feature;

use App\Models\Kamar;
use App\Models\KebijakanKos;
use App\Models\Rekening;
use App\Models\TarifSewa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Status penyiapan kos (`GET /penyiapan`).
 *
 * Satu aturan menentukan seluruh kelas ini benar atau tidak: **status harus
 * mengikuti DATA, bukan urutan tombol yang pernah ditekan.** Karena itu setiap
 * tes di sini menyiapkan data langsung, bukan dengan "menyelesaikan langkah
 * satu per satu" - dan dua tes terakhir justru menghapus data yang sudah ada
 * untuk membuktikan statusnya kembali menjadi belum selesai.
 */
class PenyiapanTest extends TestCase
{
    use RefreshDatabase;

    private function pemilik(): User
    {
        $user = User::create([
            'name' => 'Pemilik Kos',
            'email' => 'pemilik@kos.test',
            'password' => 'sandi-yang-kuat',
            'peran' => User::PERAN_PEMILIK,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    private function isiKebijakan(string $berlakuDari = '2026-01-01'): void
    {
        // Lewat model, bukan endpoint: yang diuji adalah status, bukan
        // kemampuan menyimpan kebijakan - dan endpoint itu sudah punya tesnya
        // sendiri di KebijakanKosTest.
        foreach ([
            ['tipe' => KebijakanKos::TIPE_SATUAN_SEWA, 'nilai' => 'bulanan'],
            ['tipe' => KebijakanKos::TIPE_JATUH_TEMPO, 'nilai' => 'tanggal_masuk'],
            ['tipe' => KebijakanKos::TIPE_DENDA, 'nilai' => 'persen_harian',
                'persen' => 1, 'plafon_persen' => 30, 'masa_tenggang' => 7],
        ] as $baris) {
            KebijakanKos::create($baris + ['berlaku_dari' => $berlakuDari]);
        }
    }

    private function kamarBertarif(string $nomor, int $nilai = 1500000): Kamar
    {
        $kamar = Kamar::create(['nomor' => $nomor]);

        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => $nilai,
            'berlaku_dari' => '2026-01-01',
        ]);

        return $kamar;
    }

    private function rekeningAktif(): Rekening
    {
        return Rekening::create([
            'jenis' => 'transfer',
            'nama' => 'BCA',
            'nomor' => '1234567890',
            'atas_nama' => 'Pemilik Kos',
            'aktif' => true,
        ]);
    }

    public function test_instalasi_kosong_belum_selesai_dan_menyebut_apa_yang_kurang(): void
    {
        $this->pemilik();

        $hasil = $this->getJson('/api/penyiapan')->assertOk();

        $hasil->assertJsonPath('data.selesai', false);
        $hasil->assertJsonPath('data.jumlah_selesai', 0);
        $hasil->assertJsonPath('data.jumlah_wajib', 4);

        $langkah = collect($hasil->json('data.langkah'))->keyBy('kunci');

        // Kebijakan yang kosong harus MENYEBUT tipe mana yang kurang, bukan
        // sekadar "belum lengkap". Pesan yang tidak menyebut apa yang kurang
        // memaksa pemilik membuka halaman lain untuk menebak.
        $this->assertFalse($langkah['kebijakan']['selesai']);
        $this->assertStringContainsString('satuan sewa', $langkah['kebijakan']['rincian']);
        $this->assertStringContainsString('aturan denda', $langkah['kebijakan']['rincian']);

        $this->assertFalse($langkah['kamar']['selesai']);
        $this->assertFalse($langkah['tarif']['selesai']);
        $this->assertFalse($langkah['rekening']['selesai']);
    }

    public function test_seluruh_langkah_wajib_terisi_menyatakan_selesai(): void
    {
        $this->pemilik();
        $this->isiKebijakan();
        $this->kamarBertarif('A1');
        $this->rekeningAktif();

        $hasil = $this->getJson('/api/penyiapan')->assertOk();

        $hasil->assertJsonPath('data.selesai', true);
        $hasil->assertJsonPath('data.jumlah_selesai', 4);
        $hasil->assertJsonPath('data.jumlah_wajib', 4);
    }

    public function test_kamar_tanpa_tarif_menahan_langkah_tarif(): void
    {
        $this->pemilik();
        $this->isiKebijakan();
        $this->kamarBertarif('A1');
        Kamar::create(['nomor' => 'A2']);
        $this->rekeningAktif();

        $hasil = $this->getJson('/api/penyiapan')->assertOk();
        $langkah = collect($hasil->json('data.langkah'))->keyBy('kunci');

        // Langkah "kamar" selesai (ada kamar), tetapi "tarif" belum - dan
        // inilah yang membedakan dua langkah itu. Menyatukannya menjadi satu
        // langkah akan menyembunyikan kamar yang lupa diberi tarif.
        $this->assertTrue($langkah['kamar']['selesai']);
        $this->assertFalse($langkah['tarif']['selesai']);
        $this->assertStringContainsString('A2', $langkah['tarif']['rincian']);
        $hasil->assertJsonPath('data.selesai', false);
    }

    public function test_kebijakan_bertanggal_awal_bulan_ini_dihitung_berlaku(): void
    {
        $this->pemilik();

        // Inilah jebakan yang paling mudah terlewat: kebijakan diselesaikan
        // terhadap AWAL BULAN, bukan terhadap hari ini. Kalau status memakai
        // "hari ini", kebijakan bertanggal 1 bulan ini akan dilaporkan belum
        // lengkap padahal tagihan bulan ini memang memakainya.
        $awalBulan = now('Asia/Jakarta')->startOfMonth()->toDateString();
        $this->isiKebijakan($awalBulan);

        $hasil = $this->getJson('/api/penyiapan')->assertOk();
        $langkah = collect($hasil->json('data.langkah'))->keyBy('kunci');

        $this->assertTrue(
            $langkah['kebijakan']['selesai'],
            'Kebijakan bertanggal awal bulan ini harus dihitung berlaku.',
        );
    }

    public function test_kebijakan_bertanggal_bulan_depan_belum_dihitung(): void
    {
        $this->pemilik();

        // Kebijakan yang baru berlaku bulan depan tidak menutup gerbang bulan
        // ini. Status yang menyatakannya selesai akan membuat pemilik menekan
        // "terbitkan" dan menerima penolakan - tepat keadaan yang ingin
        // dicegah kelas ini.
        $bulanDepan = now('Asia/Jakarta')->startOfMonth()->addMonth()->toDateString();
        $this->isiKebijakan($bulanDepan);

        $hasil = $this->getJson('/api/penyiapan')->assertOk();
        $langkah = collect($hasil->json('data.langkah'))->keyBy('kunci');

        $this->assertFalse($langkah['kebijakan']['selesai']);
    }

    public function test_rekening_nonaktif_tidak_dihitung(): void
    {
        $this->pemilik();
        $this->isiKebijakan();
        $this->kamarBertarif('A1');

        Rekening::create([
            'jenis' => 'transfer',
            'nama' => 'BCA lama',
            'nomor' => '999',
            'atas_nama' => 'Pemilik Kos',
            'aktif' => false,
        ]);

        $hasil = $this->getJson('/api/penyiapan')->assertOk();
        $langkah = collect($hasil->json('data.langkah'))->keyBy('kunci');

        // Rekening nonaktif tidak ditampilkan ke penghuni, jadi ia tidak
        // menutup langkah ini. Menghitungnya akan membuat portal penghuni
        // kosong padahal status menyatakan siap.
        $this->assertFalse($langkah['rekening']['selesai']);
    }

    public function test_penghuni_bukan_langkah_wajib(): void
    {
        $this->pemilik();
        $this->isiKebijakan();
        $this->kamarBertarif('A1');
        $this->rekeningAktif();

        $hasil = $this->getJson('/api/penyiapan')->assertOk();
        $langkah = collect($hasil->json('data.langkah'))->keyBy('kunci');

        // Kos yang baru dibuka boleh belum punya penghuni. Menjadikannya wajib
        // membuat penanda penyiapan menyala selamanya pada kos yang sebenarnya
        // sudah siap menerima penghuni pertama.
        $this->assertFalse($langkah['penghuni']['wajib']);
        $this->assertFalse($langkah['penghuni']['selesai']);
        $hasil->assertJsonPath('data.selesai', true);
    }

    public function test_menonaktifkan_rekening_membuat_status_kembali_belum_selesai(): void
    {
        $this->pemilik();
        $this->isiKebijakan();
        $this->kamarBertarif('A1');
        $rekening = $this->rekeningAktif();

        $this->getJson('/api/penyiapan')->assertJsonPath('data.selesai', true);

        // Inilah bukti bahwa statusnya dihitung dari data, bukan dari penanda
        // yang disetel sekali saat penyiapan selesai. Kalau ada kolom
        // `sudah_disiapkan`, tes ini akan gagal - dan itulah gunanya.
        //
        // Menonaktifkan rekening, bukan menghapus kamar: kamar yang punya
        // riwayat tarif memang tidak bisa dihapus (`RESTRICT`, REV-DAT-01),
        // sehingga memakai kamar sebagai contoh akan menguji constraint lain
        // dan bukan status penyiapan.
        $rekening->update(['aktif' => false]);

        $this->getJson('/api/penyiapan')->assertJsonPath('data.selesai', false);
    }

    public function test_mengakhiri_tarif_kamar_membuat_langkah_tarif_kembali_belum_selesai(): void
    {
        $this->pemilik();
        $this->isiKebijakan();
        $kamar = $this->kamarBertarif('A1');
        $this->rekeningAktif();

        $this->getJson('/api/penyiapan')->assertJsonPath('data.selesai', true);

        // Tarif append-only: menghapus barisnya tidak mungkin, dan itu benar -
        // riwayat tarif yang dihapus membuat tagihan lama tidak bisa
        // dijelaskan. Yang mungkin adalah menambah baris baru. Tes ini memakai
        // kamar baru tanpa tarif untuk membuktikan langkahnya menyala lagi,
        // bukan menghapus riwayat yang sudah ada.
        Kamar::create(['nomor' => 'A9']);

        $hasil = $this->getJson('/api/penyiapan')->assertJsonPath('data.selesai', false);
        $langkah = collect($hasil->json('data.langkah'))->keyBy('kunci');

        $this->assertFalse($langkah['tarif']['selesai']);
        $this->assertStringContainsString('A9', $langkah['tarif']['rincian']);
    }

    public function test_pengelola_boleh_membaca_status_penyiapan(): void
    {
        $pemilik = $this->pemilik();

        $pengelola = User::create([
            'name' => 'Pengelola',
            'email' => 'pengelola@kos.test',
            'password' => 'sandi-yang-kuat',
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $pemilik->id,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($pengelola);

        // Pengelola yang baru ditambahkan ke kos yang belum lengkap adalah
        // orang yang paling perlu melihat daftar ini - ia yang menerima
        // keluhan pertama saat tagihan tidak bisa terbit.
        $this->getJson('/api/penyiapan')->assertOk()->assertJsonPath('data.selesai', false);
    }

    public function test_penghuni_tidak_boleh_membaca_status_penyiapan(): void
    {
        $this->pemilik();

        $penghuni = User::create([
            'name' => 'Penghuni',
            'email' => 'penghuni@kos.test',
            'password' => 'sandi-yang-kuat',
            'peran' => User::PERAN_PENGHUNI,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($penghuni);

        // Isinya daftar kelemahan operasional kos. Penghuni tidak punya satu
        // pun pekerjaan yang membutuhkannya.
        $this->getJson('/api/penyiapan')->assertForbidden();
    }

    public function test_tidak_ada_endpoint_tulis_untuk_penyiapan(): void
    {
        $this->pemilik();

        // Status dihitung dari data. Kalau ada endpoint yang bisa menandai
        // selesai tanpa benar-benar mengerjakannya, seluruh gunanya hilang.
        $this->postJson('/api/penyiapan')->assertStatus(405);
        $this->putJson('/api/penyiapan')->assertStatus(405);
    }
}
