<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\PencatatAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanAturanRequest;
use App\Http\Resources\AturanResource;
use App\Models\AturanKos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Aturan kos (T19), berjejak sejak REV-DAT-05.
 *
 * Tampil di portal penghuni sebagai bacaan. Tidak menyentuh uang sama sekali,
 * sehingga tidak append-only dan boleh diubah maupun dihapus.
 *
 * `aktif` dipakai untuk menyembunyikan aturan dari portal tanpa menghapusnya.
 * Menghapus baris akan menghilangkan jawaban atas pertanyaan "memang ada aturan
 * itu?" yang sering ditanyakan penghuni lama.
 *
 * **Kenapa aturan tetap berjejak walau tidak menyentuh uang.** Aturan adalah
 * dasar menegur penghuni. Kalau pemilik menegur berdasarkan aturan yang
 * ternyata baru ditambahkan kemarin - atau sudah dihapus - penghuni tidak punya
 * cara membuktikan apa yang berlaku saat ia menandatangani. Jejak ini yang
 * membuat pertanyaan itu bisa dijawab.
 */
class AturanController extends Controller
{
    public function __construct(private readonly PencatatAudit $audit) {}

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
        $aturan = DB::transaction(function () use ($request) {
            $aturan = AturanKos::create($request->validated());

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_ATURAN_TAMBAH,
                entitas: 'aturan_kos',
                entitasId: $aturan->id,
                nilaiBaru: $aturan->only(['judul', 'kategori', 'isi', 'sanksi', 'urutan', 'aktif']),
            );

            return $aturan;
        });

        return response()->json(['data' => new AturanResource($aturan)], 201);
    }

    public function update(SimpanAturanRequest $request, AturanKos $aturan): JsonResponse
    {
        DB::transaction(function () use ($request, $aturan) {
            $sebelum = $aturan->only(['judul', 'kategori', 'isi', 'sanksi', 'urutan', 'aktif']);

            $aturan->update($request->validated());

            $sesudah = $aturan->only(['judul', 'kategori', 'isi', 'sanksi', 'urutan', 'aktif']);
            $berubah = array_keys(array_diff_assoc(
                array_map('strval', $sesudah),
                array_map('strval', $sebelum),
            ));

            if ($berubah === []) {
                return;
            }

            // Menyalakan atau mematikan `aktif` ikut tercatat: itulah yang
            // menentukan apakah penghuni melihat aturan ini di portal, dan
            // "aturan disembunyikan sejak kapan" adalah pertanyaan yang wajar.
            $this->audit->catat(
                aksi: PencatatAudit::AKSI_ATURAN_UBAH,
                entitas: 'aturan_kos',
                entitasId: $aturan->id,
                nilaiLama: array_intersect_key($sebelum, array_flip($berubah)),
                nilaiBaru: array_intersect_key($sesudah, array_flip($berubah)),
            );
        });

        return response()->json(['data' => new AturanResource($aturan)]);
    }

    public function destroy(AturanKos $aturan): JsonResponse
    {
        DB::transaction(function () use ($aturan) {
            // Disalin sebelum barisnya hilang, dan isinya ikut: "aturan 12
            // dihapus" tidak bisa dijawab sesudah baris 12 tidak ada.
            $nilai = $aturan->only(['judul', 'kategori', 'isi', 'sanksi', 'urutan', 'aktif']);
            $id = $aturan->id;

            $aturan->delete();

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_ATURAN_HAPUS,
                entitas: 'aturan_kos',
                entitasId: $id,
                nilaiLama: $nilai,
            );
        });

        return response()->json(['data' => ['id' => $aturan->id]]);
    }
}
