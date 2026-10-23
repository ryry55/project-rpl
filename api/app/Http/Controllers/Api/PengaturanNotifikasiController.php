<?php

namespace App\Http\Controllers\Api;

use App\Domain\Notifikasi\PengaturanKanal;
use App\Domain\Notifikasi\Peristiwa;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanPengaturanNotifikasiRequest;
use App\Models\PengaturanNotifikasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan kanal notifikasi milik akun yang sedang masuk (T25).
 *
 * **Per akun, bukan per kos.** Pemilik bisa mematikan email untuk dirinya
 * sendiri, dan pengelola mengatur miliknya sendiri. Kalau pengaturannya global,
 * satu pengelola yang tidak suka email akan mendiamkan peringatan tarif untuk
 * pemilik juga - dan pemilik yang merasa sudah mengatur notifikasi ternyata
 * tidak menerima apa pun karena keputusan orang lain.
 *
 * Endpoint ini tidak memeriksa wewenang apa pun selain "sudah masuk". Mengatur
 * cara diri sendiri diberi tahu bukan tindakan atas data kos.
 */
class PengaturanNotifikasiController extends Controller
{
    public function __construct(private readonly PengaturanKanal $pengaturan) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'matriks' => $this->pengaturan->matriks($user),
            ],
            'meta' => [
                'peristiwa' => Peristiwa::SEMUA,
                'label_peristiwa' => collect(Peristiwa::SEMUA)
                    ->mapWithKeys(fn (string $p) => [$p => Peristiwa::label($p)])
                    ->all(),
                'kanal' => [Peristiwa::KANAL_IN_APP, Peristiwa::KANAL_EMAIL],
                'label_kanal' => [
                    Peristiwa::KANAL_IN_APP => 'Dalam aplikasi',
                    Peristiwa::KANAL_EMAIL => 'Email',
                ],
                // Kanal yang tidak boleh dimatikan. In-app selalu menyala:
                // mematikannya berarti peristiwa yang mengubah uang tidak
                // memberi tahu siapa pun, dan satu-satunya cara mengetahui
                // tarif naik adalah membuka layarnya kebetulan.
                'kanal_wajib' => [Peristiwa::KANAL_IN_APP],
            ],
        ]);
    }

    /**
     * Ganti matriks notifikasi.
     *
     * Tiga keputusan yang menjaga tabel ini tetap berarti:
     *
     *   1. **Hanya peristiwa yang dikirim yang disentuh.** `delete` dibatasi
     *      pada `array_keys($matriks)`, bukan seluruh baris pengguna. Layar
     *      memang mengirim seluruh matriks, tetapi endpoint yang menghapus
     *      semuanya lalu menulis sebagian akan menghapus pengaturan yang tidak
     *      pernah disebut pemanggil - dan kegagalan itu tidak menimbulkan galat,
     *      hanya preferensi yang diam-diam kembali ke bawaan.
     *
     *   2. **Baris yang sama dengan bawaan tidak ditulis.** Tanpa ini, tabel
     *      yang seharusnya berisi penyimpangan berisi salinan bawaan untuk
     *      setiap akun yang pernah membuka layar ini, dan perubahan bawaan di
     *      kode berhenti berlaku bagi mereka.
     *
     *   3. **Kanal wajib tidak pernah disimpan sebagai `false`.** `in_app`
     *      dipaksa `true` sebelum dibandingkan dengan bawaan, sehingga
     *      permintaan "matikan in-app" berakhir sebagai "tidak ada baris" -
     *      bukan sebagai baris yang berisi keputusan yang tidak pernah
     *      dijalankan.
     */
    public function update(SimpanPengaturanNotifikasiRequest $request): JsonResponse
    {
        $user = $request->user();
        $matriks = $request->matriks();

        DB::transaction(function () use ($user, $matriks) {
            PengaturanNotifikasi::query()
                ->where('user_id', $user->getKey())
                ->whereIn('peristiwa', array_keys($matriks))
                ->delete();

            $baris = [];

            foreach ($matriks as $peristiwa => $kanal) {
                foreach ($kanal as $nama => $aktif) {
                    if ($nama === Peristiwa::KANAL_IN_APP) {
                        $aktif = true;
                    }

                    if ($aktif === in_array($nama, Peristiwa::kanalBawaan($peristiwa), true)) {
                        continue;
                    }

                    $baris[] = [
                        'user_id' => $user->getKey(),
                        'peristiwa' => $peristiwa,
                        'kanal' => $nama,
                        'aktif' => $aktif,
                    ];
                }
            }

            if ($baris !== []) {
                PengaturanNotifikasi::query()->insert($baris);
            }
        });

        $this->pengaturan->lupakan();

        return response()->json([
            'data' => ['matriks' => $this->pengaturan->matriks($user)],
            'meta' => ['pesan' => 'Pengaturan notifikasi disimpan.'],
        ]);
    }
}
