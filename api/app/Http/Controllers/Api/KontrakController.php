<?php

namespace App\Http\Controllers\Api;

use App\Domain\Tarif\PencariTarif;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanKontrakRequest;
use App\Http\Resources\KontrakResource;
use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\TarifSewa;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class KontrakController extends Controller
{
    public function __construct(private readonly PencariTarif $pencari) {}

    public function index(): JsonResponse
    {
        $daftar = Kontrak::with(['kamar', 'penghuni'])
            ->orderByDesc('tgl_masuk')
            ->get();

        return response()->json(['data' => KontrakResource::collection($daftar)]);
    }

    public function store(SimpanKontrakRequest $request): JsonResponse
    {
        $tglMasuk = Carbon::parse($request->string('tgl_masuk')->value());
        $kamar = Kamar::findOrFail($request->integer('kamar_id'));

        // Tarif kontrak harus berasal dari satu tempat: riwayat `tarif_sewa`.
        // Kalau kamar sudah punya tarif yang berlaku pada tanggal masuk, itulah
        // yang dipakai — angka yang diketik di formulir tidak boleh diam-diam
        // menimpanya, karena itu akan membuat dua sumber kebenaran untuk tarif
        // yang sama. Ketidakcocokan dilaporkan, bukan diabaikan.
        $tarifBerlaku = $this->pencari->sewaUntuk($kamar, $tglMasuk);
        $tarifDiminta = $request->has('tarif') ? $request->integer('tarif') : null;

        if ($tarifBerlaku !== null && $tarifDiminta !== null && (int) $tarifBerlaku->nilai !== $tarifDiminta) {
            return response()->json([
                'message' => sprintf(
                    'Kamar %s sudah punya tarif %s yang berlaku sejak %s. Untuk mengubah tarif, pakai menu Atur tarif pada kamar tersebut.',
                    $kamar->nomor,
                    'Rp '.number_format((int) $tarifBerlaku->nilai, 0, ',', '.'),
                    $tarifBerlaku->berlaku_dari->toDateString(),
                ),
                'errors' => ['tarif' => ['Tarif tidak sama dengan tarif yang sudah berlaku untuk kamar ini.']],
            ], 422);
        }

        if ($tarifBerlaku === null && $tarifDiminta === null) {
            return response()->json([
                'message' => 'Tarif kamar belum ditetapkan. Isi tarif pada formulir, atau atur tarif kamar lebih dulu.',
                'errors' => ['tarif' => ['Tarif wajib diisi karena kamar ini belum punya tarif.']],
            ], 422);
        }

        try {
            $kontrak = DB::transaction(function () use ($request, $tglMasuk, $tarifBerlaku, $tarifDiminta) {
                // Kamar belum punya tarif: baris pertama riwayat dibuat dari
                // angka yang diketik pemilik.
                if ($tarifBerlaku === null) {
                    $tarifBerlaku = TarifSewa::create([
                        'kamar_id' => $request->integer('kamar_id'),
                        'nilai' => $tarifDiminta,
                        'berlaku_dari' => $tglMasuk->toDateString(),
                    ]);
                }

                $kontrak = Kontrak::create([
                    'kamar_id' => $request->integer('kamar_id'),
                    'penghuni_id' => $request->integer('penghuni_id'),
                    'tgl_masuk' => $tglMasuk->toDateString(),
                    'tarif_awal' => (int) $tarifBerlaku->nilai,
                    'deposit' => $request->integer('deposit'),
                ]);

                // Status kamar mengikuti kontrak aktifnya, supaya daftar kamar
                // tidak pernah bertentangan dengan daftar kontrak.
                if ($kontrak->status === 'aktif') {
                    Kamar::whereKey($kontrak->kamar_id)->update(['status_huni' => 'terisi']);
                }

                return $kontrak;
            });
        } catch (QueryException $e) {
            // Partial unique index kontrak_satu_aktif_per_kamar. Pengecekan
            // exists() sebelumnya punya celah balapan, jadi constraint-lah yang
            // jadi pengaman; di sini hanya diterjemahkan ke pesan yang jelas.
            if ($e->getCode() === '23505') {
                return response()->json([
                    'message' => 'Kamar ini sudah punya kontrak aktif. Akhiri kontrak lama dulu.',
                    'errors' => ['kamar_id' => ['Kamar ini sudah punya kontrak aktif.']],
                ], 422);
            }

            throw $e;
        }

        $kontrak->load(['kamar', 'penghuni']);

        return response()->json(['data' => new KontrakResource($kontrak)], 201);
    }

    public function show(Kontrak $kontrak): JsonResponse
    {
        $kontrak->load(['kamar', 'penghuni']);

        return response()->json(['data' => new KontrakResource($kontrak)]);
    }

    /**
     * Akhiri kontrak. Tidak ada endpoint DELETE: kontrak yang dihapus akan
     * menghilangkan jejak tagihan yang pernah terbit untuknya.
     */
    public function akhiri(Kontrak $kontrak): JsonResponse
    {
        if ($kontrak->status === 'selesai') {
            return response()->json([
                'message' => 'Kontrak ini sudah berstatus selesai.',
                'errors' => ['status' => ['Kontrak ini sudah berstatus selesai.']],
            ], 422);
        }

        DB::transaction(function () use ($kontrak) {
            $kontrak->update([
                'status' => 'selesai',
                'tgl_keluar' => $kontrak->tgl_keluar ?? now()->toDateString(),
            ]);

            // Kamar kembali kosong hanya bila tidak ada kontrak aktif lain.
            $masihAktif = Kontrak::where('kamar_id', $kontrak->kamar_id)
                ->where('status', 'aktif')
                ->exists();

            if (! $masihAktif) {
                Kamar::whereKey($kontrak->kamar_id)->update(['status_huni' => 'kosong']);
            }
        });

        return response()->json(['data' => new KontrakResource($kontrak->fresh(['kamar', 'penghuni']))]);
    }
}
