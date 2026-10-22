<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\PenerbitTagihan;
use App\Domain\Billing\TagihanTidakDapatDiterbitkan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TerbitkanTagihanRequest;
use App\Http\Resources\TagihanResource;
use App\Models\Kontrak;
use App\Models\Tagihan;
use App\Support\WaktuSistem;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagihanController extends Controller
{
    public function __construct(
        private readonly PenerbitTagihan $penerbit,
        private readonly WaktuSistem $waktu,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Tagihan::query()->with(['rincian', 'kontrak.kamar', 'kontrak.penghuni']);

        if ($request->filled('kontrak_id')) {
            $query->where('kontrak_id', $request->integer('kontrak_id'));
        }

        if ($request->filled('periode')) {
            $request->validate(['periode' => ['date_format:Y-m-d']]);
            $query->whereDate('periode', Carbon::parse($request->string('periode')->value())->startOfMonth());
        }

        $daftar = $query->orderByDesc('periode')->orderByDesc('id')->get();

        return response()->json([
            'data' => TagihanResource::collection($daftar),
            // Diumumkan di badan respons, bukan hanya lewat header: header
            // lintas-origin tidak terbaca JavaScript tanpa exposed_headers,
            // dan bergantung pada itu membuat UI tidak bisa menjelaskan
            // apakah tanggal simulasi benar-benar berlaku.
            'meta' => [
                'tanggal_acuan' => $this->waktu->hariIni()->toDateString(),
                'simulasi' => $this->waktu->sedangSimulasi(),
                'demo_mode' => (bool) config('app.demo_mode'),
            ],
        ]);
    }

    public function store(TerbitkanTagihanRequest $request): JsonResponse
    {
        $kontrak = Kontrak::with('kamar')->findOrFail($request->integer('kontrak_id'));
        $periode = Carbon::parse($request->string('periode')->value());

        try {
            $tagihan = $this->penerbit->terbitkan($kontrak, $periode);
        } catch (TagihanTidakDapatDiterbitkan $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['periode' => [$e->getMessage()]],
            ], 422);
        } catch (QueryException $e) {
            // R-02: unique index (kontrak_id, periode). Pengecekan exists() di
            // aplikasi punya celah balapan — dua klik cepat bisa lolos keduanya.
            // Constraint yang jadi pengaman; di sini hanya diterjemahkan ke 409.
            if ($e->getCode() === '23505') {
                return response()->json([
                    'message' => 'Tagihan untuk kontrak ini pada periode tersebut sudah ada.',
                    'errors' => ['periode' => ['Tagihan periode ini sudah pernah diterbitkan.']],
                ], 409);
            }

            throw $e;
        }

        $tagihan->load(['rincian', 'kontrak.kamar', 'kontrak.penghuni']);

        return response()->json(['data' => new TagihanResource($tagihan)], 201);
    }

    public function show(Tagihan $tagihan): JsonResponse
    {
        $tagihan->load(['rincian', 'kontrak.kamar', 'kontrak.penghuni']);

        return response()->json(['data' => new TagihanResource($tagihan)]);
    }
}
