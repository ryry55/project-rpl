<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\PenerbitanHarian;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pemicu cron dari luar container (T31/penyebaran Vercel).
 *
 * Kenapa endpoint, bukan penjadwal di dalam container: Vercel menjalankan
 * image sebagai fungsi yang tidur setelah lima menit tanpa trafik. Proses
 * `schedule:work` ikut berhenti, sehingga tagihan tanggal 1 bisa terlewat
 * tanpa satu pun galat. Vercel Cron membangunkan layanan pada jam yang
 * ditentukan, dan endpoint inilah yang dihubunginya.
 *
 * Kenapa diam bila `CRON_SECRET` kosong: endpoint tanpa autentikasi yang
 * menerbitkan tagihan adalah jalan bagi siapa pun untuk menagih seluruh
 * penghuni. Bila rahasianya belum disetel, endpoint menjawab 503 - bukan
 * menjalankan pekerjaannya dengan anggapan "toh cuma satu kos".
 */
class CronController extends Controller
{
    public function tagihan(Request $request, PenerbitanHarian $penerbitan): JsonResponse
    {
        $rahasia = (string) config('app.cron_secret');

        if ($rahasia === '') {
            return response()->json([
                'message' => 'Endpoint cron belum dikonfigurasi. Setel CRON_SECRET.',
            ], 503);
        }

        $dikirim = (string) $request->bearerToken();

        // `hash_equals`, bukan `===`: perbandingan biasa berhenti pada huruf
        // pertama yang berbeda, dan selisih waktunya cukup untuk menebak
        // rahasia huruf demi huruf.
        if ($dikirim === '' || ! hash_equals($rahasia, $dikirim)) {
            return response()->json(['message' => 'Tidak berwenang.'], 401);
        }

        $hasil = $penerbitan->jalankan();

        return response()->json([
            'data' => [
                'dijalankan' => $hasil['dijalankan'],
                'keluaran' => $hasil['keluaran'],
            ],
        ], $hasil['dijalankan'] ? 200 : 409);
    }
}
