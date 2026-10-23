<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kotak masuk in-app (T25).
 *
 * Hanya membaca dan menandai terbaca. Tidak ada endpoint untuk **mengirim**
 * notifikasi: notifikasi dibuat oleh peristiwanya, bukan oleh permintaan.
 * Endpoint kirim akan menjadi jalan pintas untuk membuat pemberitahuan palsu
 * yang terlihat persis seperti pemberitahuan sistem.
 *
 * Penghuni, pengelola, dan pemilik semuanya memakai endpoint yang sama, dan
 * tidak ada pemeriksaan wewenang di sini. Isinya sudah terbatas pada
 * notifikasi milik akun pemanggil - `$request->user()->notifications` tidak
 * bisa menunjuk akun lain.
 */
class NotifikasiController extends Controller
{
    /**
     * Daftar notifikasi terbaru, plus jumlah yang belum dibaca.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'saja_belum_dibaca' => ['nullable', 'boolean'],
            'batas' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $batas = $request->integer('batas', 30);

        $query = $request->boolean('saja_belum_dibaca')
            ? $user->unreadNotifications()
            : $user->notifications();

        $daftar = $query->limit($batas)->get()->map(fn ($n) => [
            'id' => $n->id,
            // `data` adalah kolom JSON pada tabel standar Laravel; isinya yang
            // disusun `NotifikasiKos::toArray`.
            'peristiwa' => $n->data['peristiwa'] ?? null,
            'label_peristiwa' => $n->data['label_peristiwa'] ?? null,
            'judul' => $n->data['judul'] ?? null,
            'pesan' => $n->data['pesan'] ?? null,
            'tautan' => $n->data['tautan'] ?? null,
            'dibaca_pada' => $n->read_at?->toIso8601String(),
            'dibuat_pada' => $n->created_at?->toIso8601String(),
        ])->values();

        return response()->json([
            'data' => $daftar,
            'meta' => [
                // Dihitung dari seluruh notifikasi, bukan dari halaman yang
                // dikirim. Lencana menu yang menghitung dari 30 baris terakhir
                // akan berhenti bertambah setelah tiga puluh, dan pemilik
                // mengira tidak ada lagi yang baru.
                'belum_dibaca' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    /**
     * Tandai satu notifikasi terbaca.
     *
     * Notifikasi milik akun lain menghasilkan 404, bukan 403 - aturan yang
     * sama dengan tagihan (R-07): 403 membocorkan bahwa notifikasi itu ada.
     */
    public function tandaiDibaca(Request $request, string $notifikasi): JsonResponse
    {
        $baris = $request->user()->notifications()->whereKey($notifikasi)->firstOrFail();

        $baris->markAsRead();

        return response()->json(['data' => ['pesan' => 'Ditandai terbaca.']]);
    }

    /**
     * Tandai semuanya terbaca.
     *
     * Satu UPDATE, bukan satu per baris: kotak masuk dengan lima puluh
     * notifikasi akan menjalankan lima puluh UPDATE, dan setiap satunya bisa
     * gagal di tengah jalan sehingga sebagian tetap belum dibaca.
     */
    public function tandaiSemuaDibaca(Request $request): JsonResponse
    {
        $jumlah = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['ditandai' => $jumlah]]);
    }
}
