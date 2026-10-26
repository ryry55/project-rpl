<?php

namespace App\Http\Controllers\Api;

use App\Domain\Penyiapan\StatusPenyiapan;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Status penyiapan kos.
 *
 * Diletakkan di luar grup `wewenang:` dan hanya dijaga `peran:pemilik,pengelola`.
 * Alasannya: ini endpoint BACA, dan isinya bukan data kos melainkan "apa yang
 * belum ada". Pengelola yang baru ditambahkan ke kos yang belum lengkap justru
 * orang yang paling perlu melihatnya - ia yang akan menerima keluhan pertama
 * saat tagihan tidak bisa terbit, dan ia tidak punya wewenang `ubah_kebijakan`
 * untuk memperbaikinya sendiri. Menutupnya dengan wewenang akan membuatnya
 * menemukan masalah itu lewat pesan galat, bukan lewat daftar.
 *
 * Yang tidak dibuka: tidak ada endpoint tulis. Status ini dihitung dari data,
 * jadi tidak ada yang bisa "ditandai selesai" tanpa benar-benar mengerjakannya.
 */
class PenyiapanController extends Controller
{
    public function __construct(private readonly StatusPenyiapan $status) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->status->susun()]);
    }
}
