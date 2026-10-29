<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Penerima laporan pelanggaran CSP (FASE 4.2).
 *
 * **Kenapa endpoint ini ada.** `Content-Security-Policy-Report-Only` tanpa
 * `report-uri` tidak mengumpulkan apa pun: pelanggaran hanya muncul di konsol
 * peramban orang yang sedang membuka halaman itu, dan pemilik tidak akan pernah
 * melihatnya. Rencana "tegakkan CSP setelah seminggu laporan bersih" karena itu
 * tidak bisa dijalankan tanpa tempat laporan itu mendarat.
 *
 * **Kenapa BUKAN di bawah `/api/`.** Ia bukan bagian kontrak 86 endpoint yang
 * dipakai SPA dan 14 use case; ia infrastruktur yang dipanggil peramban.
 * Menaruhnya di `/api/` memaksa ia masuk kontrak dan diagram use case, tempat ia
 * tidak punya arti - dan membuat angka "86 endpoint, semuanya dipakai" berubah
 * menjadi "87, satu di antaranya bukan use case".
 *
 * Tiga hal yang dijaga, karena isinya datang dari luar dan tanpa autentikasi:
 *
 *   1. **Tidak pernah menggagalkan permintaan.** Badan yang tidak berbentuk
 *      laporan tetap dijawab 204. Peramban tidak membaca jawabannya, jadi 4xx
 *      hanya menghasilkan pengiriman ulang yang sama.
 *   2. **Hanya medan yang dikenal yang dicatat, dan dipotong.** Tanpa daftar
 *      putih, satu permintaan bisa menulis badan sebesar apa pun ke log.
 *   3. **Dibatasi laju di rute.** Satu halaman yang salah bisa menghasilkan
 *      ratusan laporan; tanpa pembatas, satu tab memenuhi log dan tabel cache.
 */
class CspLaporanController extends Controller
{
    /**
     * Medan yang dicatat, beserta batas panjangnya.
     *
     * `script-sample` sengaja dipotong paling pendek: isinya potongan kode
     * sebaris yang bisa memuat data dari formulir.
     */
    private const MEDAN = [
        'document-uri' => 200,
        'blocked-uri' => 200,
        'violated-directive' => 80,
        'effective-directive' => 80,
        'source-file' => 200,
        'line-number' => 8,
        'script-sample' => 80,
        'disposition' => 20,
    ];

    public function __invoke(Request $request): Response
    {
        // Dibaca langsung dari badan, bukan lewat `$request->input()`.
        // Peramban mengirim `Content-Type: application/csp-report`, dan Laravel
        // hanya mengurai badan sebagai JSON bila jenis isinya mengandung
        // `json` - sehingga `input('csp-report')` akan selalu kosong.
        $badan = json_decode((string) $request->getContent(), true);
        $laporan = is_array($badan) ? ($badan['csp-report'] ?? null) : null;

        if (is_array($laporan)) {
            $tercatat = [];

            foreach (self::MEDAN as $medan => $batas) {
                $nilai = $laporan[$medan] ?? null;

                if (is_scalar($nilai)) {
                    $tercatat[$medan] = mb_substr((string) $nilai, 0, $batas);
                }
            }

            if ($tercatat !== []) {
                // Satu baris per laporan, dengan awalan `csp.lapor` supaya bisa
                // disaring di dashboard log Render (LOG_CHANNEL=stderr).
                Log::warning('csp.lapor', $tercatat);
            }
        }

        return response()->noContent();
    }
}
