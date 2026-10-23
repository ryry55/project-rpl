<?php

namespace Tests\Feature;

use App\Models\Fasilitas;
use App\Models\Kamar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * T18 - fasilitas kamar.
 *
 * Yang dijaga di sini bukan hanya CRUD-nya, melainkan **pemisahan** yang
 * menjadi alasan tiket ini ada: fasilitas menjawab "kamar ini punya apa",
 * komponen biaya menjawab "kamar ini ditagih apa". Mencentang AC tidak boleh
 * menagih apa pun. Kalau pemisahan itu bocor, biaya muncul tanpa ada yang
 * menyetujuinya, dan tidak ada layar yang menjelaskan asalnya.
 */
class FasilitasTest extends TestCase
{
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

    // --- CRUD fasilitas ----------------------------------------------------

    public function test_pemilik_bisa_mendaftarkan_fasilitas(): void
    {
        $this->sebagaiPemilik()
            ->postJson('/api/fasilitas', ['nama' => 'AC', 'ikon' => 'ac', 'urutan' => 10])
            ->assertCreated()
            ->assertJsonPath('data.nama', 'AC')
            ->assertJsonPath('data.urutan', 10);

        $this->assertDatabaseHas('fasilitas', ['nama' => 'AC']);
    }

    public function test_nama_fasilitas_duplikat_ditolak_422(): void
    {
        Fasilitas::create(['nama' => 'AC']);

        $this->sebagaiPemilik()
            ->postJson('/api/fasilitas', ['nama' => 'AC'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['nama']]);
    }

    public function test_nama_boleh_dipertahankan_saat_update(): void
    {
        $fasilitas = Fasilitas::create(['nama' => 'AC']);

        $this->sebagaiPemilik()
            ->putJson("/api/fasilitas/{$fasilitas->id}", ['nama' => 'AC', 'urutan' => 99])
            ->assertOk()
            ->assertJsonPath('data.urutan', 99);
    }

    public function test_daftar_fasilitas_urut_dan_menyebut_jumlah_kamar(): void
    {
        $wifi = Fasilitas::create(['nama' => 'Wi-Fi', 'urutan' => 20]);
        $ac = Fasilitas::create(['nama' => 'AC', 'urutan' => 10]);
        $kamar = Kamar::create(['nomor' => 'A1']);
        $kamar->fasilitas()->attach($ac->id);

        $respons = $this->sebagaiPemilik()->getJson('/api/fasilitas')->assertOk();

        $this->assertSame(['AC', 'Wi-Fi'], array_column($respons->json('data'), 'nama'));
        $this->assertSame(1, $respons->json('data.0.dipakai_kamar'));
        $this->assertSame(0, $respons->json('data.1.dipakai_kamar'));

        // Variabel ini hanya untuk menegaskan bahwa Wi-Fi memang tidak dipakai.
        $this->assertSame(0, $wifi->kamar()->count());
    }

    public function test_menghapus_fasilitas_melepasnya_dari_kamar(): void
    {
        $ac = Fasilitas::create(['nama' => 'AC']);
        $kamar = Kamar::create(['nomor' => 'A1']);
        $kamar->fasilitas()->attach($ac->id);

        $this->sebagaiPemilik()->deleteJson("/api/fasilitas/{$ac->id}")->assertOk();

        $this->assertDatabaseMissing('fasilitas', ['id' => $ac->id]);
        // Pivot ikut terhapus lewat `cascadeOnDelete`. Tanpa ini, kamar akan
        // menunjuk fasilitas yang tidak ada dan layar menampilkan label kosong.
        $this->assertDatabaseMissing('kamar_fasilitas', ['fasilitas_id' => $ac->id]);
    }

    // --- Pemasangan per kamar ---------------------------------------------

    public function test_memasang_fasilitas_untuk_kamar(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $ac = Fasilitas::create(['nama' => 'AC']);
        $wifi = Fasilitas::create(['nama' => 'Wi-Fi']);

        $this->sebagaiPemilik()
            ->putJson("/api/kamar/{$kamar->id}/fasilitas", ['fasilitas' => [$ac->id, $wifi->id]])
            ->assertOk()
            ->assertJsonCount(2, 'data.fasilitas');

        $this->assertSame(2, $kamar->fasilitas()->count());
    }

    public function test_mengirim_daftar_baru_melepas_yang_tidak_ada_di_daftar(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $ac = Fasilitas::create(['nama' => 'AC']);
        $wifi = Fasilitas::create(['nama' => 'Wi-Fi']);
        $kamar->fasilitas()->attach([$ac->id, $wifi->id]);

        // Hanya AC yang dikirim: Wi-Fi harus lepas.
        $this->sebagaiPemilik()
            ->putJson("/api/kamar/{$kamar->id}/fasilitas", ['fasilitas' => [$ac->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data.fasilitas')
            ->assertJsonPath('data.fasilitas.0.nama', 'AC');

        $this->assertSame(1, $kamar->fasilitas()->count());
    }

    public function test_daftar_kosong_melepas_semua_fasilitas(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $ac = Fasilitas::create(['nama' => 'AC']);
        $kamar->fasilitas()->attach($ac->id);

        $this->sebagaiPemilik()
            ->putJson("/api/kamar/{$kamar->id}/fasilitas", ['fasilitas' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.fasilitas');

        $this->assertSame(0, $kamar->fasilitas()->count());
    }

    public function test_fasilitas_tak_dikenal_ditolak_422(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);

        $this->sebagaiPemilik()
            ->putJson("/api/kamar/{$kamar->id}/fasilitas", ['fasilitas' => [9999]])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['fasilitas.0']]);
    }

    public function test_fasilitas_ganda_dalam_satu_permintaan_ditolak_422(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $ac = Fasilitas::create(['nama' => 'AC']);

        // Tanpa `distinct`, `sync` akan menerima daftar ini dan pivotnya
        // berakhir dengan satu baris - tetapi pengguna mengira ia memilih dua
        // hal, dan tidak ada yang memberi tahu bahwa yang kedua diabaikan.
        $this->sebagaiPemilik()
            ->putJson("/api/kamar/{$kamar->id}/fasilitas", ['fasilitas' => [$ac->id, $ac->id]])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['fasilitas.0']]);
    }

    public function test_daftar_fasilitas_wajib_dikirim(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);

        // Tanpa `present`, permintaan kosong akan dianggap "tidak ada yang
        // berubah" alih-alih "lepas semuanya". Dua arti yang sangat berbeda,
        // dan yang salah satunya membuat pemilik mengira sudah menyimpan.
        $this->sebagaiPemilik()
            ->putJson("/api/kamar/{$kamar->id}/fasilitas", [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['fasilitas']]);
    }

    public function test_daftar_kamar_menyertakan_fasilitasnya(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $ac = Fasilitas::create(['nama' => 'AC', 'urutan' => 10]);
        $wifi = Fasilitas::create(['nama' => 'Wi-Fi', 'urutan' => 20]);
        $kamar->fasilitas()->attach([$wifi->id, $ac->id]);

        $respons = $this->sebagaiPemilik()->getJson('/api/kamar')->assertOk();

        // Urutan mengikuti `urutan`, bukan urutan pemasangan.
        $this->assertSame(
            ['AC', 'Wi-Fi'],
            array_column($respons->json('data.0.fasilitas'), 'nama'),
        );
    }

    // --- Pemisahan dari uang ----------------------------------------------

    public function test_fasilitas_tidak_membuat_baris_tagihan(): void
    {
        $kamar = Kamar::create(['nomor' => 'A1']);
        $ac = Fasilitas::create(['nama' => 'AC']);
        $kamar->fasilitas()->attach($ac->id);

        // Inti tiket T18: fasilitas dicatat, tidak ditagih. Yang menagih hanya
        // `komponen_biaya`, dan tabel itu tidak dibaca dari sini sama sekali.
        $this->assertDatabaseCount('komponen_biaya', 0);
    }

    public function test_penghuni_tidak_bisa_mengelola_fasilitas(): void
    {
        $penghuni = User::create([
            'name' => 'Budi',
            'email' => 'budi@kos.test',
            'password' => Hash::make('password'),
            'peran' => User::PERAN_PENGHUNI,
        ]);

        $this->actingAs($penghuni, 'sanctum')
            ->getJson('/api/fasilitas')
            ->assertForbidden();

        $this->actingAs($penghuni, 'sanctum')
            ->postJson('/api/fasilitas', ['nama' => 'Kolam renang'])
            ->assertForbidden();
    }
}
