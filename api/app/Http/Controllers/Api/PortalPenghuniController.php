<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagihanResource;
use App\Models\Tagihan;
use App\Support\WaktuSistem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal penghuni (T9).
 *
 * Endpoint terpisah dari `/tagihan`, bukan sekadar `/tagihan` yang difilter.
 * Alasannya bukan teknis melainkan kebiasaan: `/tagihan` adalah halaman kerja
 * pemilik — di sana ada penyaringan per kontrak, penerbitan, dan pencatatan
 * pembayaran. Menumpuk dua peran pada satu endpoint berarti setiap perubahan
 * untuk pemilik harus dipikirkan ulang untuk penghuni, dan sebaliknya.
 *
 * Isolasi tetap dijalankan lewat scope yang sama dengan endpoint pemilik
 * (`Tagihan::terlihatOleh`), supaya tidak ada dua aturan yang bisa menyimpang.
 */
class PortalPenghuniController extends Controller
{
    public function __construct(private readonly WaktuSistem $waktu) {}

    /**
     * Tagihan milik penghuni yang sedang login.
     *
     * Kalau akun ini tidak terhubung ke data penghuni (akun yatim), hasilnya
     * daftar kosong — bukan 403 dan bukan 500. Penghuni yang sah selalu punya
     * `penghuni_id`; yang tidak punya berarti datanya belum lengkap, dan
     * menampilkan "belum ada tagihan" lebih jujur daripada galat.
     */
    public function tagihan(Request $request): JsonResponse
    {
        $daftar = Tagihan::query()
            ->terlihatOleh($request->user())
            ->with(['rincian', 'pembayaran', 'kontrak.kamar', 'kontrak.penghuni'])
            ->withSum('pembayaran as total_dibayar', 'jumlah')
            ->orderByDesc('periode')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => TagihanResource::collection($daftar),
            'meta' => [
                'tanggal_acuan' => $this->waktu->hariIni()->toDateString(),
                'simulasi' => $this->waktu->sedangSimulasi(),
                'demo_mode' => (bool) config('app.demo_mode'),
            ],
        ]);
    }
}
