<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\PembayaranDitolak;
use App\Domain\Pembayaran\GatewayMenolak;
use App\Domain\Pembayaran\GatewayTidakSiap;
use App\Domain\Pembayaran\PembayaranGateway;
use App\Domain\Pembayaran\TandaTanganTidakSah;
use App\Http\Controllers\Controller;
use App\Models\Tagihan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Pembayaran daring (T22).
 *
 * Dua endpoint dengan sifat yang sangat berbeda, dan bedanya menentukan
 * seluruh bentuk kelas ini:
 *
 *   - `mulai` dipanggil penghuni yang sudah masuk. Ia punya sesi, punya
 *     tagihan yang jelas, dan boleh diberi pesan yang spesifik.
 *
 *   - `webhook` dipanggil Midtrans, yang **tidak punya akun di aplikasi ini**.
 *     Ia harus terbuka tanpa autentikasi, jadi satu-satunya bukti bahwa
 *     permintaan itu sah adalah tanda tangan di badannya. Karena itu webhook
 *     tidak pernah mengembalikan pesan yang menjelaskan KENAPA gagal -
 *     penyerang yang mencoba-coba tidak boleh belajar apa pun dari jawabannya.
 */
class PembayaranGatewayController extends Controller
{
    public function __construct(private readonly PembayaranGateway $layanan) {}

    /**
     * Mulai pembayaran daring untuk satu tagihan milik penghuni yang masuk.
     */
    public function mulai(Request $request, Tagihan $tagihan): JsonResponse
    {
        // Isolasi R-07: tagihan orang lain dijawab 404, bukan 403. 403 akan
        // memberi tahu bahwa tagihan itu ada - dan itu sendiri sudah
        // kebocoran.
        $this->pastikanMilikSendiri($request, $tagihan);

        try {
            $hasil = $this->layanan->mulai($tagihan);
        } catch (GatewayTidakSiap $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'kode' => 'gateway_belum_siap',
            ], 503);
        } catch (GatewayMenolak $e) {
            // Pesan aslinya untuk log, bukan untuk penghuni: ia menyebut nama
            // parameter internal Midtrans.
            Log::warning('midtrans.mulai_ditolak', ['pesan' => $e->getMessage()]);

            return response()->json([
                'message' => 'Pembayaran daring sedang tidak dapat diproses. Coba lagi beberapa saat, atau bayar melalui transfer/QRIS.',
                'kode' => 'gateway_menolak',
            ], 502);
        } catch (PembayaranDitolak $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'kode' => 'pembayaran_ditolak',
            ], 422);
        }

        $transaksi = $hasil['transaksi'];

        return response()->json([
            'data' => [
                'order_id' => $transaksi->order_id,
                'jumlah' => (int) $transaksi->jumlah,
                'redirect_url' => $transaksi->redirect_url,
                'kedaluwarsa_pada' => $transaksi->kedaluwarsa_pada?->toIso8601String(),
                // `dibuat=false` berarti tautan yang dikembalikan sudah ada
                // sebelumnya. Klien bisa memakai ini untuk tidak menampilkan
                // pesan "tautan baru dibuat".
                'baru' => $hasil['dibuat'],
            ],
        ], $hasil['dibuat'] ? 201 : 200);
    }

    /**
     * Notifikasi dari Midtrans.
     *
     * **Tanpa `auth:sanctum` dan tanpa CSRF** - Midtrans bukan pengguna
     * aplikasi. Keamanannya bertumpu sepenuhnya pada `signature_key`, yang
     * dihitung dari `server_key` yang hanya diketahui kita dan Midtrans.
     *
     * Jawaban yang selalu 200 untuk notifikasi sah, termasuk yang tidak
     * mengubah apa pun: gateway mengulang notifikasi yang tidak dijawab 2xx,
     * dan mengulang notifikasi `pending` selamanya bukan hal yang diinginkan.
     */
    public function webhook(Request $request): JsonResponse
    {
        $notifikasi = $request->all();

        try {
            $hasil = $this->layanan->tanganiNotifikasi($notifikasi);
        } catch (TandaTanganTidakSah $e) {
            Log::warning('midtrans.tanda_tangan_tidak_sah', [
                'order_id' => $notifikasi['order_id'] ?? null,
                'ip' => $request->ip(),
            ]);

            // 401 tanpa penjelasan. Pesan "tanda tangan tidak sah" akan
            // memberi tahu penyerang bahwa formatnya sudah benar tetapi
            // kuncinya salah - dan itu mempercepat tebakan berikutnya.
            return response()->json(['message' => 'Tidak sah.'], 401);
        }

        if ($hasil->keadaan === 'perlu_tindakan') {
            Log::warning('midtrans.perlu_tindakan', [
                'order_id' => $notifikasi['order_id'] ?? null,
                'alasan' => $hasil->alasan,
            ]);
        }

        return response()->json([
            'message' => $hasil->alasan,
            'keadaan' => $hasil->keadaan,
        ]);
    }

    /**
     * Penghuni hanya boleh membayar tagihannya sendiri; pemilik dan pengelola
     * boleh memulai atas nama siapa pun yang ada di kosnya.
     *
     * Penghuninya dibaca lewat `kontrak`, bukan kolom di `tagihan`: tabel
     * tagihan tidak menyimpan `penghuni_id` (yang menempel padanya adalah
     * kontrak). Membacanya langsung menghasilkan null, dan perbandingan
     * `(int) null` dengan id penghuni selalu tidak sama - sehingga SETIAP
     * permintaan penghuni menjadi 404, termasuk tagihannya sendiri.
     */
    private function pastikanMilikSendiri(Request $request, Tagihan $tagihan): void
    {
        $pengguna = $request->user();

        if (! $pengguna->isPenghuni()) {
            return;
        }

        $pemilikTagihan = (int) $tagihan->kontrak()->value('penghuni_id');

        if ($pemilikTagihan !== (int) $pengguna->penghuni_id) {
            abort(404);
        }
    }
}
