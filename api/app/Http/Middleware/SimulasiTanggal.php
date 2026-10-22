<?php

namespace App\Http\Middleware;

use App\Support\WaktuSistem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menggeser "hari ini" lewat header `X-Simulasi-Tanggal`.
 *
 * Kenapa lewat middleware, bukan langsung di controller denda? Karena "hari ini"
 * dipakai di lebih dari satu tempat (denda, umur tunggakan), dan menggeser
 * tanggal hanya untuk satu endpoint akan membuat dua layar menampilkan umur
 * tunggakan yang berbeda untuk tagihan yang sama. Satu titik masuk, satu
 * kebenaran.
 *
 * Middleware ini **selalu** me-reset tanggal simulasi lebih dulu. Objek
 * `WaktuSistem` singleton hidup selama satu proses PHP; pada server dengan
 * banyak permintaan berurutan (Octane, atau tes yang berjalan dalam satu
 * proses), tanggal simulasi dari permintaan sebelumnya akan bocor ke
 * permintaan berikutnya kalau tidak direset.
 *
 * Header yang tidak sah **tidak** menghasilkan error: ia diabaikan, dan respons
 * memberi tahu lewat header `X-Simulasi-Dipakai: false` bahwa tanggal yang
 * diminta tidak berlaku. Mengembalikan 400 akan membuat demo gagal di tengah
 * sidang hanya karena salah ketik tanggal.
 */
class SimulasiTanggal
{
    public function __construct(private readonly WaktuSistem $waktu) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->waktu->reset();

        $diminta = $request->header('X-Simulasi-Tanggal');
        $dipakai = $this->waktu->pakaiTanggalSimulasi($diminta);

        $respons = $next($request);

        // Diumumkan di setiap respons supaya tidak ada keraguan apakah angka
        // yang terlihat di layar berasal dari tanggal sungguhan atau simulasi.
        // Tanpa ini, demo denda bisa terlihat seperti bug.
        $respons->headers->set('X-Simulasi-Dipakai', $dipakai ? 'true' : 'false');

        if ($dipakai) {
            $respons->headers->set('X-Tanggal-Sistem', $this->waktu->hariIni()->toDateString());
        }

        return $respons;
    }
}
