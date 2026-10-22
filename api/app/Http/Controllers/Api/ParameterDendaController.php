<?php

namespace App\Http\Controllers\Api;

use App\Domain\Tarif\PencariTarif;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanParameterDendaRequest;
use App\Http\Resources\ParameterDendaResource;
use App\Models\ParameterDenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parameter denda — append-only.
 *
 * `PUT` di sini secara semantik adalah "berlakukan parameter baru mulai
 * tanggal tertentu": selalu membuat baris baru, tidak pernah mengubah baris
 * lama. Nama method `berlakukan` dipilih supaya tidak menyesatkan.
 */
class ParameterDendaController extends Controller
{
    public function __construct(private readonly PencariTarif $pencari) {}

    /**
     * Parameter yang berlaku sekarang, atau pada `berlaku_pada` bila diberikan.
     */
    public function show(Request $request): JsonResponse
    {
        $request->validate(['berlaku_pada' => ['nullable', 'date_format:Y-m-d']]);

        $periode = $request->filled('berlaku_pada')
            ? \Carbon\Carbon::parse($request->string('berlaku_pada')->value())
            : now();

        $parameter = $this->pencari->parameterDendaUntuk($periode);

        if (! $parameter) {
            return response()->json([
                'message' => 'Belum ada parameter denda. Pemilik harus menetapkannya lebih dulu.',
                'errors' => ['berlaku_pada' => ['Tidak ada parameter denda yang berlaku.']],
            ], 404);
        }

        return response()->json([
            'data' => new ParameterDendaResource($parameter),
        ] + ['meta' => ['berlaku_pada' => $periode->toDateString()]]);
    }

    /**
     * Riwayat lengkap parameter — untuk menunjukkan bahwa perubahan tidak
     * menghapus yang lama.
     */
    public function index(): JsonResponse
    {
        $daftar = ParameterDenda::orderByDesc('berlaku_dari')->get();

        return response()->json(['data' => ParameterDendaResource::collection($daftar)]);
    }

    public function store(SimpanParameterDendaRequest $request): JsonResponse
    {
        $parameter = ParameterDenda::create($request->validated());

        return response()->json(['data' => new ParameterDendaResource($parameter)], 201);
    }
}
