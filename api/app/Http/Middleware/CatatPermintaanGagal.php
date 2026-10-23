<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Log terstruktur untuk setiap permintaan yang gagal (T29).
 *
 * Laravel sudah mencatat exception, tetapi yang tidak tercatat adalah penolakan
 * yang bukan exception: 401 karena token kedaluwarsa, 403 karena wewenang,
 * 429 karena pembatas percobaan, 409 karena bentrok. Justru keempatnya yang
 * ingin dilihat saat ada masalah - "kenapa pengelola ini tidak bisa mencatat
 * pembayaran" dijawab oleh deretan 403, bukan oleh tumpukan galat.
 *
 * Bentuknya JSON satu baris per permintaan, dengan kunci yang tetap. Log yang
 * bentuknya berubah-ubah tidak bisa dicari, dan log yang tidak bisa dicari
 * hanya berguna setelah seseorang membacanya satu per satu.
 *
 * **Yang tidak pernah dicatat: badan permintaan dan header Authorization.**
 * Badan permintaan berisi kata sandi pada `/login`, dan header Authorization
 * berisi token yang masih berlaku. Log yang memuat keduanya adalah kebocoran
 * yang dibuat sendiri - dan log sering disalin ke tempat yang jauh lebih longgar
 * daripada database.
 *
 * Hanya permintaan yang berakhir >= 400 yang dicatat. Mencatat setiap 200 akan
 * menenggelamkan yang penting, dan di Render gratis kuota log-nya terbatas.
 */
class CatatPermintaanGagal
{
    public function handle(Request $request, Closure $next): Response
    {
        $mulai = microtime(true);

        try {
            $respons = $next($request);
        } catch (Throwable $e) {
            // Penolakan yang paling sering terjadi justru DILEMPAR, bukan
            // dikembalikan: 401 dari `auth:sanctum`, 403 dari `wewenang:`, 429
            // dari pembatas percobaan, 422 dari validasi. Middleware yang hanya
            // membaca nilai kembalian `$next()` akan mencatat nol dari
            // keempatnya - dan yang tersisa di log hanya galat 500, yaitu
            // kejadian yang justru sudah dicatat Laravel sendiri.
            //
            // Galatnya diteruskan ulang apa adanya. Middleware ini mengamati,
            // bukan menangani.
            $this->catat($request, $mulai, $this->statusDari($e));

            throw $e;
        }

        if ($respons->getStatusCode() < 400) {
            return $respons;
        }

        $this->catat($request, $mulai, $respons->getStatusCode(), $this->kode($respons));

        return $respons;
    }

    private function catat(Request $request, float $mulai, int $status, ?string $kode = null): void
    {
        Log::warning('permintaan.gagal', [
            // `path`, bukan `url`: URL lengkap memuat query string, dan query
            // string pada endpoint pencarian berisi nama penghuni.
            'metode' => $request->method(),
            'jalur' => '/'.$request->path(),
            'status' => $status,
            'peran' => $request->user()?->peran,
            'pengguna_id' => $request->user()?->id,
            // IP ikut dicatat: tanpa itu, 429 berulang dari satu sumber tidak
            // bisa dibedakan dari 429 wajar akibat satu pengguna yang lupa
            // sandinya.
            'ip' => $request->ip(),
            'ms' => (int) round((microtime(true) - $mulai) * 1000),
            // Kode galat aplikasi bila ada - mis. `wajib_ganti_sandi`.
            'kode' => $kode,
        ]);
    }

    /**
     * Kode status dari exception yang dilempar.
     *
     * `HttpExceptionInterface` membawa statusnya sendiri. Exception lain -
     * termasuk `QueryException` - adalah 500.
     */
    private function statusDari(Throwable $e): int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        return 500;
    }

    /**
     * Kode aplikasi dari exception yang dilempar.
     *
     * `ValidationException` tidak membawa badan JSON; ia membawa daftar galat
     * per medan. Yang diambil hanya kunci `kode` bila ada di `errors` -
     * mis. `wajib_ganti_sandi` yang dikirim `AuthController` lewat
     * `ValidationException::withMessages(...)->status(...)`.
     */
    private function kodeDari(Throwable $e): ?string
    {
        if ($e instanceof ValidationException) {
            $errors = $e->errors();

            foreach ($errors as $daftar) {
                foreach ((array) $daftar as $pesan) {
                    if (is_string($pesan) && str_starts_with($pesan, 'kode:')) {
                        return substr($pesan, 5);
                    }
                }
            }
        }

        return null;
    }

    private function kode(Response $respons): ?string
    {
        $isi = $respons->getContent();

        if (! is_string($isi) || $isi === '' || $isi[0] !== '{') {
            return null;
        }

        $data = json_decode($isi, true);

        return is_array($data) && isset($data['kode']) && is_string($data['kode'])
            ? $data['kode']
            : null;
    }
}
