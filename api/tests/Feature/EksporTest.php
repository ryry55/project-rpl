<?php

namespace Tests\Feature;

use App\Domain\Laporan\EksporCsv;
use App\Domain\Pembayaran\MetodePembayaran;
use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\Pembayaran;
use App\Models\Penghuni;
use App\Models\Tagihan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ekspor CSV rekap, tunggakan, dan pembayaran (Fase 3.5).
 *
 * Dua kelas kegagalan diuji di sini, dan keduanya tidak akan tertangkap dengan
 * memeriksa "respons 200":
 *
 *   1. **Isi berkasnya salah bentuk.** Pemisah yang keliru, BOM yang hilang,
 *      atau angka yang diformat menjadi teks menghasilkan berkas yang terunduh
 *      dengan baik dan tidak bisa dipakai. Diuji dengan mengurai isi CSV-nya,
 *      bukan dengan mencocokkan potongan teks.
 *   2. **Yang boleh mengekspor keliru.** Berkas keuangan yang bisa diunduh
 *      pengelola adalah kebocoran yang tidak terlihat di layar mana pun.
 */
class EksporTest extends TestCase
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

    private function pengelola(User $pemilik): User
    {
        return User::create([
            'name' => 'Pengelola',
            'email' => 'pengelola@kos.test',
            'password' => 'sandi-yang-kuat',
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $pemilik->id,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Satu tagihan dengan satu pembayaran, cukup untuk mengisi ketiga ekspor.
     *
     * @return array{tagihan: Tagihan, pembayaran: Pembayaran}
     */
    private function tagihanDenganPembayaran(int $jumlah = 1500000, string $nama = 'Budi Santoso'): array
    {
        $kamar = Kamar::create(['nomor' => 'A1', 'status_huni' => 'terisi']);
        $penghuni = Penghuni::create(['nama' => $nama, 'email' => 'budi@kos.test']);

        $kontrak = Kontrak::create([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-01-01',
            'tarif_awal' => 1500000,
            'status' => 'aktif',
        ]);

        $tagihan = Tagihan::create([
            'kontrak_id' => $kontrak->id,
            'periode' => '2026-01-01',
            'pokok' => 1500000,
            'total_final' => 1500000,
            'jatuh_tempo' => '2026-01-10',
            'status' => Tagihan::STATUS_LUNAS,
            // `tarif_terpakai` dan `terbit_pada` NOT NULL tanpa nilai bawaan
            // (R-06): tagihan tanpa salinan tarif beku tidak bisa menjelaskan
            // angka yang tertulis di atasnya. Helper tes wajib mengisinya,
            // sama seperti `PenerbitTagihan` yang sesungguhnya.
            'tarif_terpakai' => ['prorata' => false],
            'kebijakan_terpakai' => ['denda' => ['tipe' => 'tanpa_denda']],
            'tarif_denda_persen' => 1,
            'plafon_persen' => 30,
            'masa_tenggang' => 7,
            'terbit_pada' => now(),
        ]);

        $pembayaran = Pembayaran::create([
            'tagihan_id' => $tagihan->id,
            'jumlah' => $jumlah,
            'metode' => MetodePembayaran::TRANSFER,
            'sumber' => MetodePembayaran::SUMBER_DICATAT_PEMILIK,
            'dicatat_oleh' => User::query()->value('id'),
            'tanggal_pembayaran' => '2026-01-05',
        ]);

        return ['tagihan' => $tagihan, 'pembayaran' => $pembayaran];
    }

    /**
     * Urai CSV berpemisah titik koma menjadi baris dan kolom.
     *
     * Memakai `str_getcsv` dengan pemisah yang sama seperti yang ditulis, bukan
     * `explode(';')`: nilai yang mengandung titik koma (mis. catatan) akan
     * dipecah salah oleh `explode`, dan tesnya akan gagal karena sebab yang
     * tidak ada hubungannya dengan aplikasi.
     *
     * @return list<list<string>>
     */
    private function urai(string $isi): array
    {
        // BOM dibuang lebih dulu. Kalau tidak, kolom pertama baris pertama
        // berisi "\xEF\xBB\xBFTanggal" dan setiap pencocokan judul kolom gagal.
        $isi = preg_replace('/^\xEF\xBB\xBF/', '', $isi);

        $hasil = [];
        foreach (explode("\n", $isi) as $baris) {
            if (trim($baris) === '') {
                continue;
            }
            $hasil[] = str_getcsv($baris, ';', '"', '\\');
        }

        return $hasil;
    }

    /**
     * Cari satu baris berdasarkan isi kolom pertamanya.
     *
     * @param  list<list<string>>  $baris
     * @return list<string>|null
     */
    private function cariBaris(array $baris, string $kolomPertama): ?array
    {
        foreach ($baris as $b) {
            if (($b[0] ?? '') === $kolomPertama) {
                return $b;
            }
        }

        return null;
    }

    public function test_ekspor_rekap_mengembalikan_csv_dengan_bom_dan_pemisah_titik_koma(): void
    {
        $this->pemilik();
        $this->tagihanDenganPembayaran();

        $respons = $this->get('/api/ekspor/rekap?dari=2026-01-01&sampai=2026-01-31');
        $respons->assertOk();

        $isi = $respons->streamedContent();

        // BOM wajib ada di awal berkas. Tanpa itu Excel di Windows membaca
        // berkasnya sebagai ANSI dan nama penghuni menjadi rusak.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $isi, 'CSV harus dimulai BOM UTF-8.');

        $this->assertStringContainsString(
            'text/csv',
            $respons->headers->get('Content-Type') ?? '',
            'Content-Type harus text/csv.',
        );

        // `attachment` memaksa unduhan. Tanpa itu berkasnya terbuka sebagai
        // teks di tab baru dan pemilik mengira tidak ada yang terunduh.
        $this->assertStringContainsString(
            'attachment',
            $respons->headers->get('Content-Disposition') ?? '',
        );

        $baris = $this->urai($isi);
        $this->assertNotEmpty($baris);

        // Yang diperiksa BUKAN baris pertama: baris pertama rekap adalah judul
        // satu kolom ("Rekap pendapatan"), jadi ia memang satu kolom. Yang
        // membuktikan pemisahnya benar adalah baris BERKOLOM BANYAK - baris
        // judul tabel per metode dan baris judul daftar transaksi.
        $perMetode = $this->cariBaris($baris, 'Per metode');
        $this->assertNotNull($perMetode, 'Judul tabel per metode harus ada.');
        $this->assertSame(
            3,
            count($perMetode),
            'Baris "Per metode" harus terpecah menjadi 3 kolom. Kalau pemisahnya salah, seluruh baris menjadi satu kolom.',
        );

        $daftar = $this->cariBaris($baris, 'Tanggal');
        $this->assertNotNull($daftar, 'Judul daftar transaksi harus ada.');
        $this->assertSame(12, count($daftar), 'Judul daftar transaksi harus punya 12 kolom.');
    }

    public function test_ekspor_rekap_memuat_total_dan_daftar_transaksi(): void
    {
        $this->pemilik();
        $this->tagihanDenganPembayaran(1500000, 'Budi Santoso');

        $isi = $this->get('/api/ekspor/rekap?dari=2026-01-01&sampai=2026-01-31')
            ->streamedContent();

        $this->assertStringContainsString('Rekap pendapatan', $isi);
        $this->assertStringContainsString('Daftar transaksi', $isi);

        // Nama penghuni harus muncul di daftar transaksi. Berkas rekap tanpa
        // "dari siapa" tidak bisa dipakai untuk rekonsiliasi.
        $this->assertStringContainsString('Budi Santoso', $isi);
    }

    public function test_angka_ditulis_mentah_bukan_teks_berformat(): void
    {
        $this->pemilik();
        $this->tagihanDenganPembayaran(1500000);

        $baris = $this->urai(
            $this->get('/api/ekspor/rekap?dari=2026-01-01&sampai=2026-01-31')->streamedContent(),
        );

        // Cari baris "Total diterima (Rp)" dan periksa nilainya.
        $total = $this->cariBaris($baris, 'Total diterima (Rp)');

        $this->assertNotNull($total, 'Baris total harus ada di berkas rekap.');
        $this->assertSame(
            '1500000',
            $total[1] ?? null,
            'Angka uang harus ditulis mentah. "Rp 1.500.000" tidak bisa di-SUM di Excel.',
        );
        $this->assertStringNotContainsString('Rp 1.500.000', $total[1] ?? '');
    }

    public function test_nilai_negatif_pembalik_tetap_negatif_dan_tidak_dikuttip(): void
    {
        $this->pemilik();
        ['tagihan' => $tagihan] = $this->tagihanDenganPembayaran(1500000);

        // Baris pembalik: nilai negatif yang menunjuk baris asalnya
        // (REV-FIN-01). Ini kasus yang paling mudah dirusak oleh sanitasi
        // formula: '-' adalah salah satu awalan berbahaya, dan memberi kutip
        // padanya akan mengubah angka menjadi teks - sehingga total di Excel
        // tidak lagi cocok dengan total di layar.
        Pembayaran::create([
            'tagihan_id' => $tagihan->id,
            'jumlah' => -500000,
            'metode' => MetodePembayaran::TRANSFER,
            'sumber' => MetodePembayaran::SUMBER_DICATAT_PEMILIK,
            'tanggal_pembayaran' => '2026-01-06',
            'pembalik_id' => Pembayaran::query()->value('id'),
            // Alasan wajib: CHECK `pembalik_ada_alasan` menolak baris negatif
            // tanpa catatan. Baris negatif tanpa keterangan hanya menimbulkan
            // pertanyaan baru di rekap.
            'catatan' => 'Salah ketik nominal',
        ]);

        $baris = $this->urai(
            $this->get('/api/ekspor/rekap?dari=2026-01-01&sampai=2026-01-31')->streamedContent(),
        );

        $negatif = null;
        foreach ($baris as $b) {
            if (in_array('-500000', $b, true)) {
                $negatif = '-500000';
                break;
            }
        }

        $this->assertSame(
            '-500000',
            $negatif,
            'Nilai negatif harus tetap berupa angka, bukan teks berkutip.',
        );

        // Dan tidak boleh ada bentuk berkutipnya di mana pun.
        $this->assertStringNotContainsString(
            "'-500000",
            $this->get('/api/ekspor/rekap?dari=2026-01-01&sampai=2026-01-31')->streamedContent(),
        );
    }

    public function test_teks_berawalan_sama_dengan_tanda_sama_dinetralkan(): void
    {
        // Nama yang dimulai '=' adalah rumus bagi Excel. Ini bentuk serangan
        // CSV injection yang paling dikenal, dan nama penghuni berasal dari
        // formulir yang bisa diisi orang lain.
        $this->pemilik();
        $this->tagihanDenganPembayaran(1500000, '=HYPERLINK("http://jahat.example","klik")');

        $isi = $this->get('/api/ekspor/rekap?dari=2026-01-01&sampai=2026-01-31')
            ->streamedContent();

        $baris = $this->urai($isi);

        $ditemukan = false;
        foreach ($baris as $b) {
            foreach ($b as $sel) {
                if (str_contains($sel, 'HYPERLINK')) {
                    $ditemukan = true;
                    // Sel harus diawali kutip tunggal, sehingga Excel
                    // memperlakukannya sebagai teks.
                    $this->assertStringStartsWith(
                        "'",
                        $sel,
                        'Sel yang dimulai "=" harus dinetralkan dengan kutip tunggal.',
                    );
                }
            }
        }

        $this->assertTrue($ditemukan, 'Nama uji harus muncul di berkas - kalau tidak, tesnya tidak menguji apa pun.');
    }

    public function test_ekspor_tunggakan_memuat_kelompok_dan_nama_penghuni(): void
    {
        $this->pemilik();
        $this->tagihanDenganPembayaran(1500000, 'Siti Aminah');

        // Tagihan belum lunas supaya masuk tunggakan.
        Tagihan::query()->update(['status' => Tagihan::STATUS_BELUM_BAYAR]);

        $isi = $this->get('/api/ekspor/tunggakan')->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $isi);
        $this->assertStringContainsString('Daftar tunggakan', $isi);
        $this->assertStringContainsString('Siti Aminah', $isi);
        $this->assertStringContainsString('Tanggal acuan', $isi);
    }

    public function test_ekspor_pembayaran_mewajibkan_rentang_tanggal(): void
    {
        $this->pemilik();

        // Tanpa rentang, ekspor akan memilih rentangnya sendiri - dan berbeda
        // dari layar, berkasnya tidak menyebut rentang yang dipakai di tempat
        // yang mudah terlihat.
        $this->getJson('/api/ekspor/pembayaran')->assertStatus(422);

        $this->getJson('/api/ekspor/pembayaran?dari=2026-01-01')
            ->assertStatus(422);
    }

    public function test_ekspor_pembayaran_memuat_baris_pembayaran(): void
    {
        $this->pemilik();
        $this->tagihanDenganPembayaran(1500000, 'Joko Widodo');

        $isi = $this->get('/api/ekspor/pembayaran?dari=2026-01-01&sampai=2026-01-31')
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $isi);
        $this->assertStringContainsString('Joko Widodo', $isi);
        $this->assertStringContainsString('Transfer bank', $isi);
    }

    public function test_pengelola_boleh_mengekspor_tunggakan_dan_pembayaran(): void
    {
        $pemilik = $this->pemilik();
        $this->tagihanDenganPembayaran();

        Sanctum::actingAs($this->pengelola($pemilik));

        // Pengelola yang menagih butuh daftar tunggakan di tangan, dan ia
        // sudah bisa membaca layarnya. Menutup ekspornya hanya memaksanya
        // menyalin dengan tangan.
        $this->get('/api/ekspor/tunggakan')->assertOk();
        $this->get('/api/ekspor/pembayaran?dari=2026-01-01&sampai=2026-01-31')->assertOk();
    }

    public function test_pengelola_tidak_boleh_mengekspor_rekap(): void
    {
        $pemilik = $this->pemilik();
        $this->tagihanDenganPembayaran();

        Sanctum::actingAs($this->pengelola($pemilik));

        // Rekap adalah gambaran utuh pendapatan kos. Berkas yang bisa dibawa
        // pulang justru lebih berbahaya daripada layar yang harus dibuka
        // dengan sesi - ia bisa dikirim ke siapa saja sesudah diunduh.
        $this->getJson('/api/ekspor/rekap?dari=2026-01-01&sampai=2026-01-31')
            ->assertForbidden();
    }

    public function test_penghuni_tidak_boleh_mengekspor_apa_pun(): void
    {
        $this->pemilik();
        $this->tagihanDenganPembayaran();

        $penghuni = User::create([
            'name' => 'Penghuni',
            'email' => 'penghuni@kos.test',
            'password' => 'sandi-yang-kuat',
            'peran' => User::PERAN_PENGHUNI,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($penghuni);

        $this->getJson('/api/ekspor/rekap')->assertForbidden();
        $this->getJson('/api/ekspor/tunggakan')->assertForbidden();
        $this->getJson('/api/ekspor/pembayaran')->assertForbidden();
    }

    public function test_tamu_tidak_boleh_mengekspor(): void
    {
        // Tanpa sesi sama sekali. Diuji terpisah dari penghuni karena
        // middleware yang salah urutan bisa meloloskan tamu sementara
        // penghuni tetap ditolak.
        $this->getJson('/api/ekspor/rekap')->assertUnauthorized();
        $this->getJson('/api/ekspor/tunggakan')->assertUnauthorized();
        $this->getJson('/api/ekspor/pembayaran')->assertUnauthorized();
    }

    public function test_nama_berkas_menyebut_rentang_tanggal(): void
    {
        $this->pemilik();
        $this->tagihanDenganPembayaran();

        $respons = $this->get('/api/ekspor/pembayaran?dari=2026-01-01&sampai=2026-01-31');

        // Nama berkas adalah satu-satunya penanda rentang yang ikut terbawa
        // saat berkasnya disimpan. "pembayaran.csv" akan menjadi tiga berkas
        // berbeda dengan nama sama di folder Unduhan.
        $this->assertStringContainsString(
            'pembayaran-2026-01-01-sampai-2026-01-31.csv',
            $respons->headers->get('Content-Disposition') ?? '',
        );
    }

    public function test_ekspor_dilarang_di_cache(): void
    {
        $this->pemilik();

        $respons = $this->get('/api/ekspor/tunggakan');

        // Berkas keuangan dan nama penghuni tidak boleh tertinggal di cache
        // komputer bersama yang dipakai bergantian.
        $this->assertStringContainsString(
            'no-store',
            $respons->headers->get('Cache-Control') ?? '',
        );
    }

    /**
     * Penulis CSV diuji langsung, tanpa lewat HTTP.
     *
     * **Kenapa tes ini harus ada, padahal sudah ada tes ekspor di atas.**
     * Percobaan pertama membuktikan bahwa tes HTTP TIDAK BISA menguji
     * netralisasi angka: controller mengirim `(int) $p->jumlah`, sehingga
     * nilainya sudah bertipe int saat mencapai `EksporCsv::sel()`, dan cabang
     * "string yang isinya angka" tidak pernah dilewati. Ketika cacatnya
     * disuntikkan - pemeriksaan angka dimatikan - seluruh tes HTTP tetap
     * lulus, dan itu terbaca sebagai "pagar terpasang" padahal pagarnya tidak
     * pernah diuji.
     *
     * Cabang itu tetap perlu ada: `EksporCsv` adalah kelas yang bisa dipakai
     * ekspor lain, dan Eloquent mengembalikan `decimal:0` sebagai string.
     * Karena itu ia diuji di sini, dengan masukan string yang memang
     * mencapainya.
     */
    public function test_penulis_csv_membedakan_angka_negatif_dari_teks_berbahaya(): void
    {
        $baris = [
            ['angka_int', 1500000],
            ['angka_string', '1500000'],
            ['negatif_string', '-500000'],
            ['rumus', '=1+1'],
            ['tanda_tambah', '+1+1'],
            ['tanda_minus_teks', '-teks'],
            ['teks_biasa', 'Budi Santoso'],
        ];

        // `getContent()` mengembalikan string kosong untuk respons STREAMING:
        // isinya baru ditulis saat callback dijalankan. Ditangkap dengan
        // output buffering, bukan dengan `getContent()` - kesalahan yang
        // membuat tes gagal pada hal yang tidak ada hubungannya dengan pagar.
        $respons = (new EksporCsv([], 'uji.csv'))->unduh($baris);

        ob_start();
        $respons->sendContent();
        $isi = (string) ob_get_clean();

        $this->assertStringContainsString('angka_int;1500000', $isi);
        $this->assertStringContainsString('angka_string;1500000', $isi);

        // Inti tes ini: negatif yang sah harus TETAP angka. Kalau ia dikutip,
        // SUM di Excel menghasilkan 0 dan total berkas tidak lagi cocok
        // dengan total di layar.
        $this->assertStringContainsString('negatif_string;-500000', $isi);
        $this->assertStringNotContainsString("negatif_string;'-500000", $isi);

        // Teks yang dimulai karakter berbahaya harus dinetralkan.
        $this->assertStringContainsString("rumus;'=1+1", $isi);
        $this->assertStringContainsString("tanda_tambah;'+1+1", $isi);

        // Teks yang dimulai '-' tetapi BUKAN angka juga harus dinetralkan:
        // Excel memperlakukannya sebagai rumus pula.
        $this->assertStringContainsString("tanda_minus_teks;'-teks", $isi);

        // Teks biasa tidak boleh disentuh. `fputcsv` mengutip bidang yang
        // berisi spasi, jadi bentuk yang benar adalah `"Budi Santoso"` -
        // kutip ganda itu bagian dari CSV yang sah, bukan netralisasi.
        $this->assertStringContainsString('teks_biasa;"Budi Santoso"', $isi);
    }
}
