<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\PenghitungDenda;
use App\Http\Controllers\Controller;
use App\Models\Tagihan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     *
     * Terbuka untuk pemilik dan penghuni pemilik tagihan. Penghuni yang membuka
     * tagihan orang lain mendapat 404 dari scope `terlihatOleh` — angka denda
     * sendiri tidak sensitif, tetapi keberadaan tagihan orang lain adalah
     * informasi yang tidak boleh bocor lewat pintu samping.
     */
    public function show(Request $request, Tagihan $tagihan): JsonResponse
    {
        $tagihan = Tagihan::query()
            ->terlihatOleh($request->user())
            ->findOrFail($tagihan->getKey());

        return response()->json(['data' => $this->penghitung->hitung($tagihan)->toArray()]);
    }
}
