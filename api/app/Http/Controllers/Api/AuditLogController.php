<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LogAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Membaca jejak audit (R-07).
 *
 * Hanya ada satu aksi: `index`. Tidak ada `store`, `update`, maupun `destroy`,
 * dan itu bukan kelalaian — log audit ditulis oleh `PencatatAudit` di dalam
 * transaksi perubahan, bukan oleh permintaan HTTP. Endpoint tulis akan
 * memungkinkan seseorang menambahkan jejak palsu, dan itu menghancurkan seluruh
 * gunanya.
 *
 * Penyaringan disediakan karena log tumbuh terus dan yang paling sering dicari
 * adalah "apa yang terjadi pada tagihan ini" atau "apa yang dilakukan orang ini".
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'entitas' => ['nullable', 'string', 'max:40'],
            'entitas_id' => ['nullable', 'integer', 'min:1'],
            'aksi' => ['nullable', 'string', 'max:60'],
            'aktor_id' => ['nullable', 'integer', 'min:1'],
            'batas' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $query = LogAudit::query();

        if ($request->filled('entitas')) {
            $query->where('entitas', $request->string('entitas')->value());
        }

        if ($request->filled('entitas_id')) {
            $query->where('entitas_id', $request->integer('entitas_id'));
        }

        if ($request->filled('aksi')) {
            $query->where('aksi', $request->string('aksi')->value());
        }

        if ($request->filled('aktor_id')) {
            $query->where('aktor_id', $request->integer('aktor_id'));
        }

        // Urut waktu turun, dengan `id` sebagai pemutus: dua baris bisa lahir
        // pada detik yang sama, dan tanpa pemutus urutannya berubah-ubah antar
        // permintaan — membuat jejak yang seharusnya tetap terlihat bergerak.
        $batas = $request->integer('batas', 200);

        $daftar = $query->orderByDesc('dibuat_pada')->orderByDesc('id')->limit($batas)->get();

        return response()->json([
            'data' => $daftar->map(fn (LogAudit $log) => [
                'id' => $log->id,
                'aktor_id' => $log->aktor_id,
                'aktor_nama' => $log->aktor_nama,
                'aksi' => $log->aksi,
                'entitas' => $log->entitas,
                'entitas_id' => $log->entitas_id,
                'waktu' => $log->dibuat_pada?->toIso8601String(),
                'nilai_lama' => $log->nilai_lama,
                'nilai_baru' => $log->nilai_baru,
            ])->values(),
            'meta' => [
                'jumlah' => $daftar->count(),
                'batas' => $batas,
                // Diumumkan supaya UI bisa mengatakan "menampilkan 200 terakhir"
                // alih-alih berpura-pura itu seluruh isi log.
                'dipotong' => $daftar->count() >= $batas,
            ],
        ]);
    }
}
