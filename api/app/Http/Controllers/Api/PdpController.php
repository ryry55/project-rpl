<?php

namespace App\Http\Controllers\Api;

use App\Domain\Pdp\LayananPdp;
use App\Http\Controllers\Controller;
use App\Models\PersetujuanPdp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pemenuhan hak subjek data pribadi (T28).
 *
 * Seluruh endpoint di sini bekerja pada **akun pemanggil sendiri**. Tidak ada
 * parameter id, dan itu disengaja: endpoint `GET /pdp/{user}` akan menjadi satu
 * kesalahan pemeriksaan wewenang jauh dari membocorkan data pribadi seluruh
 * penghuni. "Diri sendiri" adalah satu-satunya subjek yang tidak perlu
 * diperiksa.
 */
class PdpController extends Controller
{
    public function __construct(private readonly LayananPdp $pdp) {}

    /**
     * Hak akses: seluruh data pribadi yang tercatat tentang pemanggil.
     */
    public function dataSaya(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->pdp->dataPribadi($request->user()),
            'meta' => [
                'versi_pdp' => PersetujuanPdp::VERSI_BERLAKU,
                'sudah_menyetujui' => $request->user()->sudahMenyetujuiPdp(),
            ],
        ]);
    }

    /**
     * Hak penghapusan: anonimisasi, bukan penghapusan baris.
     *
     * Jawabannya 409 bila masih ada kontrak aktif, dengan alasannya. Bukan 403:
     * pengguna berhak meminta, hanya waktunya belum tepat - dan 403 terbaca
     * seperti "hak Anda ditolak" padahal yang benar "tunggu kontraknya selesai".
     */
    public function hapusSaya(Request $request): JsonResponse
    {
        $user = $request->user();

        // Konfirmasi tertulis DIPERIKSA LEBIH DULU, sebelum keadaan kontrak.
        //
        // Urutannya bukan kerapian. Menghapus identitas tidak bisa dibatalkan,
        // dan satu klik yang salah tidak boleh cukup untuk melakukannya - jadi
        // permintaan yang bentuknya salah harus dijawab sebagai permintaan yang
        // bentuknya salah (422), bukan sebagai "tunggu kontraknya selesai"
        // (409).
        //
        // Urutan sebaliknya pernah ada di sini dan menghasilkan jawaban yang
        // menyesatkan: penghuni dengan kontrak aktif yang salah mengetik
        // konfirmasi diberi tahu bahwa masalahnya adalah kontraknya, lalu
        // memperbaiki hal yang tidak salah. Yang lebih berbahaya, ia tidak
        // pernah tahu bahwa pagar konfirmasinya tidak pernah diperiksa.
        $request->validate(
            ['konfirmasi' => ['required', 'in:HAPUS']],
            [
                'konfirmasi.required' => 'Ketik HAPUS untuk mengonfirmasi.',
                'konfirmasi.in' => 'Ketik HAPUS (huruf besar) untuk mengonfirmasi.',
            ]
        );

        $alasan = $this->pdp->alasanTidakBolehHapus($user);

        if ($alasan !== null) {
            return response()->json([
                'message' => $alasan,
                'kode' => 'penghapusan_belum_dapat_dilakukan',
            ], 409);
        }

        $this->pdp->hapus($user);

        return response()->json([
            'data' => [
                'pesan' => 'Identitas Anda sudah dilepas dari catatan kos ini. '
                    .'Riwayat pembayaran tetap tersimpan tanpa nama, karena catatan keuangan '
                    .'tidak boleh dihapus.',
            ],
        ]);
    }
}
