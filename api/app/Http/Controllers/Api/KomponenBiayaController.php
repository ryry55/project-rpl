<?php

namespace App\Http\Controllers\Api;

use App\Domain\Tarif\PencariTarif;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanKomponenBiayaRequest;
use App\Http\Resources\KomponenBiayaResource;
use App\Models\KomponenBiaya;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Komponen biaya tetap — append-only seperti tarif sewa.
 *
 * Menghentikan komponen dilakukan dengan menambah baris baru bernilai 0, bukan
 * dengan menonaktifkan baris lama. Lihat catatan di migrasi 130300.
 */
class KomponenBiayaController extends Controller
{
    public function __construct(private readonly PencariTarif $pencari) {}

    public function index(Request $request): JsonResponse
    {
        $query = KomponenBiaya::query();

        if ($request->filled('berlaku_pada')) {
            $request->validate(['berlaku_pada' => ['date_format:Y-m-d']]);
            $periode = Carbon::parse($request->string('berlaku_pada')->value());

            return response()->json([
                'data' => KomponenBiayaResource::collection($this->pencari->komponenUntuk($periode)),
            ]);
        }

        $daftar = $query->orderBy('nama')->orderByDesc('berlaku_dari')->get();

        return response()->json(['data' => KomponenBiayaResource::collection($daftar)]);
    }

    public function store(SimpanKomponenBiayaRequest $request): JsonResponse
    {
        try {
            $komponen = KomponenBiaya::create($request->validated());
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return response()->json([
                    'message' => 'Komponen dengan nama dan tanggal berlaku itu sudah ada.',
                    'errors' => ['berlaku_dari' => ['Sudah ada baris dengan tanggal berlaku ini.']],
                ], 422);
            }

            throw $e;
        }

        return response()->json(['data' => new KomponenBiayaResource($komponen)], 201);
    }
}
