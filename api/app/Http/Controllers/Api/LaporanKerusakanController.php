<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\PencatatAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanLaporanKerusakanRequest;
use App\Http\Resources\LaporanKerusakanResource;
use App\Models\Kamar;
use App\Models\LaporanKerusakan;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Laporan kerusakan kamar (T14).
 *
 * **Tiga keputusan pemilik 31 Oktober 2026 yang membentuk kelas ini:**
 *
 * 1. Kamar berstatus `perbaikan` **tetap ditagih penuh**. Karena itu tidak ada
 *    satu baris pun di sini yang menyentuh `tagihan`, `Kalkulator`, atau
 *    `PenerbitanMassal`. Perbaikan adalah keadaan fisik kamar, bukan alasan
 *    menghentikan sewa - kontraknya masih berjalan.
 * 2. Yang boleh menutup laporan adalah **pemilik dan pengelola**, mengikuti
 *    wewenang `kelola_kamar` yang sudah ada. Tidak ada wewenang baru: orang
 *    yang mengurus kamar memang yang tahu perbaikannya sudah selesai.
 * 3. Biaya perbaikan **tidak pernah** masuk tagihan penghuni. Tidak ada kolom
 *    nominal di tabelnya, jadi tidak ada angka yang bisa bocor ke tagihan
 *    tanpa disengaja.
 *
 * **Tidak ada `destroy`.** Laporan yang dihapus akan membuat kamar berstatus
 * `perbaikan` tanpa penjelasan apa pun - dan yang menemukannya enam bulan
 * kemudian hanya melihat kamar yang tidak bisa disewa tanpa tahu kenapa.
 */
class LaporanKerusakanController extends Controller
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = LaporanKerusakan::query()->with(['kamar', 'pelapor', 'penutup']);

        if ($request->filled('status')) {
            $request->string('status')->value() === 'terbuka'
                ? $query->whereNull('selesai_pada')
                : $query->whereNotNull('selesai_pada');
        }

        // Yang masih terbuka lebih dulu: daftar ini dibuka untuk mencari
        // pekerjaan yang belum selesai, bukan untuk membaca sejarah.
        $daftar = $query
            ->orderByRaw('selesai_pada IS NOT NULL')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => LaporanKerusakanResource::collection($daftar)]);
    }

    public function store(SimpanLaporanKerusakanRequest $request): JsonResponse
    {
        $kamar = Kamar::findOrFail($request->integer('kamar_id'));

        // Diperiksa lebih dulu supaya pesannya jelas. Yang benar-benar menjaga
        // adalah indeks unik parsial di basis data - dua permintaan bersamaan
        // bisa sama-sama lolos pemeriksaan ini, dan yang kedua ditangkap di
        // bawah sebagai 23505.
        if ($kamar->laporanTerbuka()->exists()) {
            return $this->tolakLaporanGanda($kamar);
        }

        try {
            $laporan = DB::transaction(function () use ($request, $kamar) {
                $laporan = LaporanKerusakan::create([
                    'kamar_id' => $kamar->id,
                    'deskripsi' => $request->string('deskripsi')->value(),
                    'dilaporkan_oleh' => $request->user()->getAuthIdentifier(),
                ]);

                $sebelum = $kamar->status_huni;
                $kamar->update(['status_huni' => 'perbaikan']);

                // Jejaknya menempel pada KAMAR, bukan pada laporan: yang
                // berubah dan yang dibaca orang lain adalah status kamarnya.
                // `laporan_id` disertakan supaya pembaca bisa menelusuri
                // alasannya tanpa menebak.
                $this->audit->catat(
                    aksi: PencatatAudit::AKSI_KERUSAKAN_LAPOR,
                    entitas: 'kamar',
                    entitasId: $kamar->id,
                    nilaiLama: ['status_huni' => $sebelum],
                    nilaiBaru: [
                        'status_huni' => 'perbaikan',
                        'laporan_id' => $laporan->id,
                        'deskripsi' => $laporan->deskripsi,
                    ],
                );

                return $laporan;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return $this->tolakLaporanGanda($kamar);
            }

            throw $e;
        }

        $laporan->load(['kamar', 'pelapor']);

        return response()->json(['data' => new LaporanKerusakanResource($laporan)], 201);
    }

    public function selesai(Request $request, LaporanKerusakan $laporan): JsonResponse
    {
        if (! $laporan->masihTerbuka()) {
            return response()->json([
                'message' => 'Laporan ini sudah ditutup.',
                'errors' => ['selesai_pada' => ['Laporan ini sudah ditutup.']],
            ], 422);
        }

        DB::transaction(function () use ($request, $laporan) {
            $laporan->update([
                'selesai_pada' => now(),
                'ditutup_oleh' => $request->user()->getAuthIdentifier(),
            ]);

            $kamar = $laporan->kamar;
            $statusBaru = $kamar->statusSetelahPerbaikan();
            $kamar->update(['status_huni' => $statusBaru]);

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_KERUSAKAN_SELESAI,
                entitas: 'kamar',
                entitasId: $kamar->id,
                nilaiLama: ['status_huni' => 'perbaikan'],
                nilaiBaru: ['status_huni' => $statusBaru, 'laporan_id' => $laporan->id],
            );
        });

        $laporan->load(['kamar', 'pelapor', 'penutup']);

        return response()->json(['data' => new LaporanKerusakanResource($laporan)]);
    }

    private function tolakLaporanGanda(Kamar $kamar): JsonResponse
    {
        return response()->json([
            'message' => sprintf(
                'Kamar %s sudah punya laporan kerusakan yang belum selesai. '
                .'Tandai laporan itu selesai lebih dulu.',
                $kamar->nomor,
            ),
            'errors' => ['kamar_id' => ['Kamar ini masih punya laporan kerusakan terbuka.']],
        ], 422);
    }
}
