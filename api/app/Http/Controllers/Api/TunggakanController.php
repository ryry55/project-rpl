<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\DaftarTunggakan;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class TunggakanController extends Controller
{
    public function __construct(private readonly DaftarTunggakan $daftar) {}

    /**
     * Tunggakan per kelompok umur pada "hari ini".
     *
     * Tidak menerima parameter tanggal: tanggal acuan hanya bisa digeser lewat
     * `X-Simulasi-Tanggal` di mode demo, sama seperti endpoint denda. Dua jalur
     * penggeseran tanggal akan menghasilkan dua tanggal acuan berbeda untuk
     * tagihan yang sama.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->daftar->susun()]);
    }
}
