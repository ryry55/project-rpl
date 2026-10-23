<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanFasilitasRequest;
use App\Http\Resources\FasilitasResource;
use App\Models\Fasilitas;
use App\Models\Kamar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fasilitas kos (T18).
 *
 * Fasilitas menjawab "kamar ini PUNYA apa". Tidak ada satu pun endpoint di sini
 * yang menyentuh tagihan: mencentang AC tidak menagih apa pun. Yang menagih
 * adalah `komponen_biaya`, dan itu tetap dikelola di tempatnya sendiri.
 *
 * Fasilitas boleh diubah dan dihapus, berbeda dari `tarif_sewa` dan
 * `komponen_biaya` yang append-only. Alasannya: tidak ada angka uang yang
 * bergantung pada baris fasilitas, jadi tidak ada riwayat yang perlu dilindungi.
 */
class FasilitasController extends Controller
{
    public function index(): JsonResponse
    {
        $daftar = Fasilitas::query()
            ->withCount('kamar')
            ->orderBy('urutan')
            ->orderBy('nama')
            ->get();

        return response()->json(['data' => FasilitasResource::collection($daftar)]);
    }

    public function store(SimpanFasilitasRequest $request): JsonResponse
    {
        $fasilitas = Fasilitas::create($request->validated());

        return response()->json(['data' => new FasilitasResource($fasilitas)], 201);
    }

    public function update(SimpanFasilitasRequest $request, Fasilitas $fasilitas): JsonResponse
    {
        $fasilitas->update($request->validated());

        return response()->json(['data' => new FasilitasResource($fasilitas)]);
    }

    public function destroy(Fasilitas $fasilitas): JsonResponse
    {
        // Pivot memakai `cascadeOnDelete`, jadi menghapus fasilitas otomatis
        // melepasnya dari semua kamar. Itu memang yang diinginkan: fasilitas
        // yang sudah tidak ada tidak boleh tetap tercatat di kamar mana pun.
        $fasilitas->delete();

        return response()->json(['data' => ['id' => $fasilitas->id]]);
    }

    /**
     * Mengganti seluruh fasilitas satu kamar.
     *
     * Memakai PUT dan bukan POST per fasilitas: layar menampilkan daftar
     * centang, dan yang dikirim adalah keadaan akhir daftar itu. Dengan POST
     * per item, menambah dan melepas menjadi dua operasi berbeda yang bisa
     * gagal separuh jalan, dan kamar berakhir dengan daftar yang tidak pernah
     * dipilih siapa pun.
     */
    public function pasangUntukKamar(Request $request, Kamar $kamar): JsonResponse
    {
        $data = $request->validate([
            'fasilitas' => ['present', 'array'],
            'fasilitas.*' => ['integer', 'distinct', 'exists:fasilitas,id'],
        ], [
            'fasilitas.present' => 'Daftar fasilitas wajib dikirim (boleh kosong).',
            'fasilitas.*.exists' => 'Ada fasilitas yang tidak dikenal.',
            'fasilitas.*.distinct' => 'Ada fasilitas yang dikirim dua kali.',
        ]);

        // `sync` menghitung sendiri selisihnya: yang sudah terpasang dibiarkan,
        // yang baru ditambahkan, yang tidak ada di daftar dilepas. Menulis
        // `detach` lalu `attach` akan menghapus dan membuat ulang baris yang
        // sama, sehingga `created_at` pivot berubah tanpa ada yang berubah.
        $kamar->fasilitas()->sync($data['fasilitas']);

        $kamar->load('fasilitas');

        return response()->json([
            'data' => [
                'kamar_id' => $kamar->id,
                'fasilitas' => FasilitasResource::collection($kamar->fasilitas),
            ],
        ]);
    }
}
