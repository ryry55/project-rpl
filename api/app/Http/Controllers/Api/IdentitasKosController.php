<?php

namespace App\Http\Controllers\Api;

use App\Domain\Kebijakan\IdentitasKos;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Nama kos untuk layar yang dibuka sebelum masuk (K-17).
 *
 * Layar masuk adalah satu-satunya halaman yang dilihat orang sebelum punya
 * token, dan judulnya harus menyebut kos yang benar — "Huni" di layar
 * masuk kos orang lain terbaca seperti aplikasi yang belum disiapkan.
 *
 * **Tanpa `auth`**, dan isinya sengaja hanya nama. Nama kos tertulis di papan
 * depan kos; ia bukan rahasia. Yang tidak boleh bocor lewat endpoint publik
 * adalah data penghuni, tagihan, dan kredensial — dan tidak satu pun ada di
 * sini.
 */
class IdentitasKosController extends Controller
{
    public function __construct(private readonly IdentitasKos $identitas) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'nama' => $this->identitas->nama(),
            ],
        ]);
    }
}
