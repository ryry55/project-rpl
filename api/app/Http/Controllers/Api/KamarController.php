<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanKamarRequest;
use App\Http\Resources\KamarResource;
use App\Models\Kamar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KamarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Kamar::query()->with(['kontrakAktif.penghuni', 'tarifTerakhir']);

        if ($request->filled('status_huni')) {
            $query->where('status_huni', $request->string('status_huni')->value());
        }

        $daftar = $query->orderBy('nomor')->get();

        return response()->json(['data' => KamarResource::collection($daftar)]);
    }

    public function store(SimpanKamarRequest $request): JsonResponse
    {
        $kamar = Kamar::create($request->validated());

        return response()->json(['data' => new KamarResource($kamar)], 201);
    }

    public function show(Kamar $kamar): JsonResponse
    {
        $kamar->load(['kontrakAktif.penghuni', 'tarif', 'tarifTerakhir']);

        return response()->json(['data' => new KamarResource($kamar)]);
    }

    public function update(SimpanKamarRequest $request, Kamar $kamar): JsonResponse
    {
        $kamar->update($request->validated());

        return response()->json(['data' => new KamarResource($kamar)]);
    }
}
