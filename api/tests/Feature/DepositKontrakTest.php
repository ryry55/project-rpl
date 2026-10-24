<?php

namespace Tests\Feature;

use App\Domain\Kebijakan\PilihanKebijakan;
use App\Models\Kamar;
use App\Models\KebijakanKos;
use App\Models\Kontrak;
use App\Models\LogAudit;
use App\Models\Penghuni;
use App\Models\TarifSewa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MenyiapkanKebijakan;
use Tests\TestCase;

/**
 * T17 — deposit sebagai kebijakan kos.
 *
 * Sebelum tiket ini `kontrak.deposit` hanya kolom yang diisi tangan dan tidak
 * pernah dibaca siapa pun: nilai yang tersimpan tetapi tidak berakibat. Sekarang
 * kebijakan `deposit` menentukan bawaannya, dan isian pemilik menimpa bawaan itu.
 *
 * Yang dibuktikan di sini:
 *   1. Tiga bentuk deposit menghasilkan angka yang benar saat kontrak dibuat.
 *   2. `satu_bulan` membekukan tarif saat kontrak dibuat, bukan tarif terkini.
 *   3. Isian pemilik menang atas kebijakan — termasuk nol yang diketik sendiri.
 *   4. Ketiadaan kebijakan berarti nol, bukan galat.
 */
class DepositKontrakTest extends TestCase
{
    use MenyiapkanKebijakan;
    use RefreshDatabase;

    private User $pemilik;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pemilik = User::create([
            'name' => 'Pemilik Kos',
            'email' => 'pemilik@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PEMILIK,
        ]);
    }

    private function sebagaiPemilik()
    {
        return $this->actingAs($this->pemilik, 'sanctum');
    }

    /**
     * Kamar + penghuni + tarif, tanpa kontrak. Nomor kamar dan email wajib unik
     * per pemanggilan karena keduanya punya indeks unik.
     */
    private function siapkan(int $tarif = 1_500_000, string $nomor = 'A1'): array
    {
        $kamar = Kamar::create(['nomor' => $nomor, 'status_huni' => 'kosong']);
        $penghuni = Penghuni::create([
            'nama' => 'Budi Santoso',
            'email' => strtolower($nomor).'@kos.test',
        ]);

        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => $tarif,
            'berlaku_dari' => '2026-01-01',
        ]);

        return [$kamar, $penghuni];
    }

    private function buatKontrak(Kamar $kamar, Penghuni $penghuni, array $tambahan = []): TestResponse
    {
        return $this->sebagaiPemilik()->postJson('/api/kontrak', array_merge([
            'kamar_id' => $kamar->id,
            'penghuni_id' => $penghuni->id,
            'tgl_masuk' => '2026-10-01',
        ], $tambahan));
    }

    // --- Tiga bentuk deposit ----------------------------------------------

    public function test_tanpa_deposit_menghasilkan_nol(): void
    {
        [$kamar, $penghuni] = $this->siapkan();
        $this->siapkanKebijakanDeposit(PilihanKebijakan::DEPOSIT_TANPA_DEPOSIT);

        $this->buatKontrak($kamar, $penghuni)
            ->assertCreated()
            ->assertJsonPath('data.deposit', 0);
    }

    public function test_deposit_nominal_tetap_dipakai(): void
    {
        [$kamar, $penghuni] = $this->siapkan();
        $this->siapkanKebijakanDeposit(PilihanKebijakan::DEPOSIT_NOMINAL, 750_000);

        $this->buatKontrak($kamar, $penghuni)
            ->assertCreated()
            ->assertJsonPath('data.deposit', 750_000);
    }

    public function test_deposit_satu_bulan_memakai_tarif_saat_kontrak_dibuat(): void
    {
        [$kamar, $penghuni] = $this->siapkan(1_500_000);
        $this->siapkanKebijakanDeposit(PilihanKebijakan::DEPOSIT_SATU_BULAN);

        $this->buatKontrak($kamar, $penghuni)
            ->assertCreated()
            ->assertJsonPath('data.deposit', 1_500_000);
    }

    public function test_deposit_satu_bulan_tidak_ikut_naik_saat_tarif_dinaikkan(): void
    {
        // Deposit adalah uang yang diserahkan di muka. Menaikkan tarif setelah
        // kontrak berjalan tidak boleh mengubah deposit yang sudah dibayar —
        // karena itu angkanya dibekukan di `kontrak.deposit`, bukan dihitung
        // ulang dari tarif terkini.
        [$kamar, $penghuni] = $this->siapkan(1_500_000);
        $this->siapkanKebijakanDeposit(PilihanKebijakan::DEPOSIT_SATU_BULAN);

        $kontrakId = $this->buatKontrak($kamar, $penghuni)
            ->assertCreated()
            ->assertJsonPath('data.deposit', 1_500_000)
            ->json('data.id');

        // Tarif baru untuk periode berikutnya.
        TarifSewa::create([
            'kamar_id' => $kamar->id,
            'nilai' => 2_000_000,
            'berlaku_dari' => '2026-12-01',
        ]);

        $this->sebagaiPemilik()
            ->getJson("/api/kontrak/{$kontrakId}")
            ->assertOk()
            ->assertJsonPath('data.deposit', 1_500_000);
    }

    // --- Isian pemilik menang ---------------------------------------------

    public function test_isian_pemilik_menang_atas_kebijakan(): void
    {
        // Kesepakatan dengan penghuni bisa berbeda dari kebijakan umum kos
        // (mis. kamar tanpa deposit karena penghuni membawa kasur sendiri).
        [$kamar, $penghuni] = $this->siapkan();
        $this->siapkanKebijakanDeposit(PilihanKebijakan::DEPOSIT_NOMINAL, 750_000);

        $this->buatKontrak($kamar, $penghuni, ['deposit' => 200_000])
            ->assertCreated()
            ->assertJsonPath('data.deposit', 200_000);
    }

    public function test_nol_yang_diketik_pemilik_dihormati(): void
    {
        // Inilah alasan `KontrakController::depositUntuk()` memakai `filled()`
        // dan bukan `integer()`: `integer('deposit')` mengembalikan 0 baik
        // untuk "tidak diisi" maupun untuk "diisi nol", sehingga deposit
        // kebijakan tidak akan pernah terpakai. String "0" harus dibaca sebagai
        // pernyataan pemilik, bukan sebagai ketiadaan isian.
        [$kamar, $penghuni] = $this->siapkan();
        $this->siapkanKebijakanDeposit(PilihanKebijakan::DEPOSIT_NOMINAL, 750_000);

        $this->buatKontrak($kamar, $penghuni, ['deposit' => 0])
            ->assertCreated()
            ->assertJsonPath('data.deposit', 0);
    }

    public function test_tanpa_isian_deposit_memakai_kebijakan(): void
    {
        // Pasangan dari tes di atas: medan yang tidak dikirim sama sekali
        // berarti "ikuti kebijakan", dan hasilnya bukan nol.
        [$kamar, $penghuni] = $this->siapkan();
        $this->siapkanKebijakanDeposit(PilihanKebijakan::DEPOSIT_NOMINAL, 750_000);

        $kontrak = $this->buatKontrak($kamar, $penghuni)->assertCreated()->json('data');

        $this->assertSame(750_000, $kontrak['deposit']);
        $this->assertSame(
            750_000,
            (int) Kontrak::find($kontrak['id'])->deposit,
            'deposit harus tersimpan, bukan hanya dikembalikan di respons',
        );
    }

    // --- Kebijakan tidak ada berarti nol ----------------------------------

    public function test_tanpa_kebijakan_deposit_berarti_nol_bukan_galat(): void
    {
        // Berbeda dengan denda, deposit tidak wajib ditetapkan: kos yang tidak
        // memungut deposit adalah keadaan normal. Memaksanya menyatakan "tanpa
        // deposit" hanya menambah langkah tanpa menambah kejelasan.
        [$kamar, $penghuni] = $this->siapkan();

        $this->assertSame(
            0,
            KebijakanKos::where('tipe', PilihanKebijakan::TIPE_DEPOSIT)->count(),
        );

        $this->buatKontrak($kamar, $penghuni)
            ->assertCreated()
            ->assertJsonPath('data.deposit', 0);
    }

    // --- Penimpa per kamar ------------------------------------------------

    public function test_kebijakan_deposit_per_kamar_menimpa_default_kos(): void
    {
        [$kamar, $penghuni] = $this->siapkan();
        $this->siapkanKebijakanDeposit(PilihanKebijakan::DEPOSIT_NOMINAL, 500_000);
        $this->siapkanKebijakanDeposit(
            PilihanKebijakan::DEPOSIT_NOMINAL,
            900_000,
            $kamar->id,
        );

        $this->buatKontrak($kamar, $penghuni)
            ->assertCreated()
            ->assertJsonPath('data.deposit', 900_000);
    }

    public function test_kebijakan_deposit_kamar_lain_tidak_bocor(): void
    {
        // Penimpa milik kamar lain tidak boleh terpakai. Tanpa isolasi ini,
        // deposit kamar A akan menentukan deposit kamar B.
        [$kamar, $penghuni] = $this->siapkan();
        $lain = Kamar::create(['nomor' => 'B9', 'status_huni' => 'kosong']);

        $this->siapkanKebijakanDeposit(PilihanKebijakan::DEPOSIT_NOMINAL, 500_000);
        $this->siapkanKebijakanDeposit(PilihanKebijakan::DEPOSIT_NOMINAL, 900_000, $lain->id);

        $this->buatKontrak($kamar, $penghuni)
            ->assertCreated()
            ->assertJsonPath('data.deposit', 500_000);
    }

    // --- Validasi ---------------------------------------------------------

    public function test_deposit_negatif_ditolak(): void
    {
        [$kamar, $penghuni] = $this->siapkan();

        $this->buatKontrak($kamar, $penghuni, ['deposit' => -1])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['deposit']]);
    }

    // --- Penetapan tarif saat kontrak (REV-SEC-02) -------------------------

    public function test_pemilik_menetapkan_tarif_kamar_baru_lewat_kontrak(): void
    {
        // Kamar baru belum punya tarif sama sekali. Pemilik menetapkannya dalam
        // satu langkah bersama kontraknya - itu alur yang wajar dan tetap boleh.
        $kamar = Kamar::create(['nomor' => 'B1', 'status_huni' => 'kosong']);
        $penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'email' => 'b1@kos.test']);

        $this->buatKontrak($kamar, $penghuni, ['tarif' => 1_750_000])
            ->assertCreated()
            ->assertJsonPath('data.tarif_awal', 1_750_000);

        $this->assertDatabaseHas('tarif_sewa', [
            'kamar_id' => $kamar->id,
            'nilai' => 1_750_000,
        ]);
    }

    public function test_penetapan_tarif_lewat_kontrak_meninggalkan_jejak_audit(): void
    {
        // REV-SEC-02: baris tarif pertama dulu ditulis dengan `TarifSewa::create`
        // langsung, sehingga tarif termahal yang pernah ditetapkan - yang
        // menentukan tagihan setiap bulan - tidak meninggalkan jejak apa pun.
        $kamar = Kamar::create(['nomor' => 'B2', 'status_huni' => 'kosong']);
        $penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'email' => 'b2@kos.test']);

        $this->buatKontrak($kamar, $penghuni, ['tarif' => 1_750_000])
            ->assertCreated();

        $jejak = LogAudit::query()
            ->where('entitas', 'tarif_sewa')
            ->firstOrFail();

        $this->assertSame($this->pemilik->id, (int) $jejak->aktor_id);
        $this->assertSame(1_750_000, (int) $jejak->nilai_baru['nilai']);
    }

    public function test_pengelola_tidak_boleh_menetapkan_tarif_lewat_kontrak(): void
    {
        // Jalur yang tidak dijaga selalu menjadi jalur yang dipakai. Tanpa
        // pemeriksaan ini, pengelola yang hanya berhak MENGUSULKAN tarif bisa
        // menetapkannya sendiri cukup dengan membuat kontrak untuk kamar yang
        // belum bertarif.
        $pengelola = User::create([
            'name' => 'Sari Pengelola',
            'email' => 'sari@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $this->pemilik->id,
        ]);
        $pengelola->kewenangan()->create(['wewenang' => 'kelola_penghuni']);
        $pengelola->load('kewenangan');

        $kamar = Kamar::create(['nomor' => 'B3', 'status_huni' => 'kosong']);
        $penghuni = Penghuni::create(['nama' => 'Budi Santoso', 'email' => 'b3@kos.test']);

        $this->actingAs($pengelola, 'sanctum')
            ->postJson('/api/kontrak', [
                'kamar_id' => $kamar->id,
                'penghuni_id' => $penghuni->id,
                'tgl_masuk' => '2026-10-01',
                'tarif' => 1_750_000,
            ])
            ->assertStatus(422)
            ->assertJsonPath('kode', 'tarif_perlu_pemilik');

        // Tidak ada baris tarif dan tidak ada kontrak yang lahir.
        $this->assertSame(0, TarifSewa::query()->where('kamar_id', $kamar->id)->count());
        $this->assertSame(0, Kontrak::query()->where('kamar_id', $kamar->id)->count());
    }

    public function test_tarif_yang_berbeda_dari_yang_berlaku_ditolak_422(): void
    {
        // Tarif yang sudah ada tidak boleh "dikoreksi" lewat pembuatan kontrak:
        // itu jalan pintas mengubah tarif tanpa usulan, tanpa persetujuan, dan
        // tanpa jejak.
        [$kamar, $penghuni] = $this->siapkan(tarif: 1_500_000);

        $this->buatKontrak($kamar, $penghuni, ['tarif' => 1_900_000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tarif');
    }

    public function test_tarif_yang_sama_dengan_yang_berlaku_diterima(): void
    {
        // Mengirim tarif yang sama bukan percobaan mengubah apa pun - layar
        // boleh mengirim ulang angka yang ditampilkannya tanpa ditolak.
        [$kamar, $penghuni] = $this->siapkan(tarif: 1_500_000);

        $this->buatKontrak($kamar, $penghuni, ['tarif' => 1_500_000])
            ->assertCreated();
    }
}
