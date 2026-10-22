<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\PenghitungDenda;
use App\Http\Controllers\Controller;
use App\Models\Tagihan;
use Illuminate\Http\JsonResponse;

class DendaController extends Controller
{
    public function __construct(private readonly PenghitungDenda $penghitung) {}

    /**
     * Denda sebuah tagihan pada "hari ini".
     *
     * Dipisah ke endpoint sendiri, bukan hanya diselipkan di resource tagihan,
     * karena inilah satu-satunya angka di sistem yang berubah sendiri seiring
     * waktu. Memisahkannya membuat perubahan itu terlihat dan bisa dipersoalkan
     * terpisah dari pokok tagihan yang tetap.
     */
    public function show(Tagihan $tagihan): JsonResponse
    {
        return response()->json(['data' => $this->penghitung->hitung($tagihan)->toArray()]);
    }
}
