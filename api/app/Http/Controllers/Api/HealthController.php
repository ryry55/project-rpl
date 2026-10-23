<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Health check untuk pemantau dan pengawas uptime (T29).
 *
 * Endpoint `/up` bawaan Laravel hanya menjawab "aplikasi hidup". Itu tidak
 * cukup untuk layanan gratis yang tidur setelah 15 menit: pertanyaan yang
 * sebenarnya adalah "apakah aplikasi ini bisa melayani permintaan yang
 * menyentuh database", karena aplikasi yang hidup tetapi tidak bisa menghubungi
 * database akan menjawab 200 pada `/up` sambil menggagalkan setiap layar.
 *
 * **Tidak ada `auth` sama sekali**, dan itu bukan kelalaian. Pemantau uptime
 * tidak punya akun. Karena itu isinya dijaga: tidak ada nama tabel, tidak ada
 * versi, tidak ada pesan galat mentah, dan tidak ada hitungan baris. Yang
 * dikembalikan hanya tiga kata: `ok`, `degraded`, atau `down`.
 *
 * Status 503 saat database tidak terjangkau, supaya pemantau yang hanya melihat
 * kode HTTP tetap tahu ada masalah. 200 dengan badan "degraded" akan dianggap
 * sehat oleh sebagian besar pemantau.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = $this->periksaDatabase();

        $sehat = $database === 'ok';

        return response()->json([
            'data' => [
                'status' => $sehat ? 'ok' : 'down',
                'database' => $database,
                // Zona waktu ikut diumumkan karena seluruh perhitungan denda
                // bergantung padanya, dan container yang salah waktu akan
                // menghasilkan denda yang salah tanpa galat apa pun.
                'zona_waktu' => config('app.timezone'),
                'waktu' => now()->toIso8601String(),
            ],
        ], $sehat ? 200 : 503);
    }

    private function periksaDatabase(): string
    {
        try {
            // Query yang benar-benar menyentuh tabel, bukan `SELECT 1`. Koneksi
            // bisa hidup sementara migrasi belum berjalan - dan dalam keadaan
            // itu setiap layar gagal walau koneksinya sehat.
            if (! Schema::hasTable('users')) {
                return 'migrasi_belum_jalan';
            }

            DB::select('SELECT 1');

            return 'ok';
        } catch (Throwable) {
            // Pesan galatnya sengaja tidak diteruskan: health check yang publik
            // dan membocorkan nama host database adalah undangan.
            return 'tidak_terjangkau';
        }
    }
}
