<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanAturanRequest;
use App\Http\Resources\AturanResource;
use App\Models\AturanKos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aturan kos (T19).
 *
 * Tampil di portal penghuni sebagai bacaan. Tidak menyentuh uang sama sekali,
 * sehingga tidak append-only dan boleh diubah maupun dihapus.
 *
 * `aktif` dipakai untuk menyembunyikan aturan dari portal tanpa menghapusnya.
 * Menghapus baris akan menghilangkan jawaban atas pertanyaan "memang ada aturan
 * itu?" yang sering ditanyakan penghuni lama.
 */
class AturanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AturanKos::query();

        // Pemilik melihat semuanya secara default; `?aktif=1` menyaring ke yang
        // tampil di portal. Portal penghuni memakai penyaring itu, bukan
        // menyaring sendiri di layar: aturan yang tidak aktif tidak boleh
        // pernah terkirim ke peramban penghuni.
        if ($request->filled('aktif')) {
            $query->where('aktif', $request->boolean('aktif'));
        }

        $daftar = $query->orderBy('urutan')->orderBy('id')->get();

        return response()->json([
            'data' => AturanResource::collection($daftar),
            'meta' => ['kategori' => SimpanAturanRequest::KATEGORI_UMUM],
        ]);
    }

    public function store(SimpanAturanRequest $request): JsonResponse
    {
        $aturan = AturanKos::create($request->validated());

        return response()->json(['data' => new AturanResource($aturan)], 201);
    }

    public function update(SimpanAturanRequest $request, AturanKos $aturan): JsonResponse
    {
        $aturan->update($request->validated());

        return response()->json(['data' => new AturanResource($aturan)]);
    }

    public function destroy(AturanKos $aturan): JsonResponse
    {
        $aturan->delete();

        return response()->json(['data' => ['id' => $aturan->id]]);
    }
}
