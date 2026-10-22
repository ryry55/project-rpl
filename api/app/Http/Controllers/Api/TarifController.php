<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\PencatatAudit;
use App\Domain\Tarif\PencariTarif;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanTarifRequest;
use App\Http\Resources\TarifSewaResource;
use App\Models\Kamar;
use App\Models\TarifSewa;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Riwayat tarif sewa — lapis pertama R-06.
 *
 * Tidak ada endpoint PUT/PATCH/DELETE. Mengubah tarif berarti menambah baris
 * baru; baris lama dipertahankan, dan trigger basis data menolak perubahan
 * langsung. Tanpa ini, "tarif tidak berlaku surut" hanya janji di dokumen.
 */
class TarifController extends Controller
{
    public function __construct(
        private readonly PencariTarif $pencari,
        private readonly PencatatAudit $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = TarifSewa::query()->with('kamar');

        if ($request->filled('kamar_id')) {
            $query->where('kamar_id', $request->integer('kamar_id'));
        }

        $daftar = $query->orderBy('kamar_id')->orderByDesc('berlaku_dari')->get();

        return response()->json(['data' => TarifSewaResource::collection($daftar)]);
    }

    public function store(SimpanTarifRequest $request): JsonResponse
    {
        try {
            $tarif = DB::transaction(function () use ($request) {
                $tarif = TarifSewa::create($request->validated());

                // R-06 lapis 1: perubahan tarif adalah kejadian yang harus bisa
                // ditelusuri. Yang dicatat nilai barunya saja — baris lama tidak
                // diubah, jadi tidak ada nilai lama pada baris ini.
                $this->audit->catat(
                    aksi: PencatatAudit::AKSI_TARIF_TAMBAH,
                    entitas: 'tarif_sewa',
                    entitasId: $tarif->id,
                    nilaiBaru: [
                        'kamar_id' => $tarif->kamar_id,
                        'nilai' => (int) $tarif->nilai,
                        'berlaku_dari' => $tarif->berlaku_dari->toDateString(),
                    ],
                );

                return $tarif;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return response()->json([
                    'message' => 'Kamar ini sudah punya tarif yang berlaku dari tanggal tersebut.',
                    'errors' => ['berlaku_dari' => ['Sudah ada tarif dengan tanggal berlaku ini.']],
                ], 422);
            }

            throw $e;
        }

        $tarif->load('kamar');

        return response()->json(['data' => new TarifSewaResource($tarif)], 201);
    }

    /**
     * Tarif yang berlaku untuk sebuah periode.
     *
     * Dipakai UI dan, nanti di T3, oleh penerbitan tagihan. Endpoint terpisah
     * supaya "tarif mana yang dipakai" bisa diperiksa tanpa menerbitkan tagihan.
     */
    public function berlaku(Request $request, Kamar $kamar): JsonResponse
    {
        $request->validate([
            'periode' => ['required', 'date_format:Y-m-d'],
        ]);

        $periode = Carbon::parse($request->string('periode')->value());
        $tarif = $this->pencari->sewaUntuk($kamar, $periode);

        if (! $tarif) {
            return response()->json([
                'message' => 'Belum ada tarif untuk kamar ini pada periode tersebut.',
                'errors' => ['periode' => ['Belum ada tarif yang berlaku.']],
            ], 404);
        }

        return response()->json([
            'data' => [
                'kamar_id' => $kamar->id,
                'periode' => $periode->copy()->startOfMonth()->toDateString(),
                'tarif_id' => $tarif->id,
                'nilai' => (int) $tarif->nilai,
                'berlaku_dari' => $tarif->berlaku_dari->toDateString(),
            ],
        ]);
    }
}
