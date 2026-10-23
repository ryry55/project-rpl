<?php

namespace App\Http\Controllers\Api;

use App\Domain\Notifikasi\Pemberitahu;
use App\Domain\Pembayaran\MetodePembayaran;
use App\Domain\Pembayaran\PencatatVerifikasi;
use App\Domain\Pembayaran\RekapPendapatan;
use App\Domain\Pembayaran\VerifikasiDitolak;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\VerifikasiPembayaranRequest;
use App\Http\Resources\PembayaranResource;
use App\Models\Pembayaran;
use App\Models\User;
use App\Support\WaktuSistem;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pemeriksaan pembayaran dan rekap pendapatan (T20, T21).
 *
 * Dipisahkan dari `TagihanController` karena yang diurus di sini bukan tagihan,
 * melainkan baris uang: satu tagihan bisa punya beberapa pembayaran, dan satu
 * pembayaran bisa diperiksa beberapa kali.
 */
class PembayaranController extends Controller
{
    public function __construct(
        private readonly PencatatVerifikasi $verifikator,
        private readonly RekapPendapatan $rekap,
        private readonly WaktuSistem $waktu,
        private readonly Pemberitahu $pemberitahu,
    ) {}

    /**
     * Daftar pembayaran pada satu rentang tanggal.
     *
     * Rentang wajib, bukan opsional dengan bawaan "bulan ini": rekap yang
     * diam-diam memilih rentang sendiri adalah rekap yang menipu saat
     * dipertanyakan. Kalau layar ingin bulan ini, layar yang menyebutnya.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dari' => ['required', 'date_format:Y-m-d'],
            'sampai' => ['required', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);

        $daftar = Pembayaran::query()
            ->whereBetween('tanggal_pembayaran', [$data['dari'], $data['sampai']])
            ->with([
                'verifikasi',
                'pencatat',
                'penerima',
                // Nama penghuni dan nomor kamar ikut dimuat: daftar uang tanpa
                // "dari siapa" tidak bisa dipakai untuk menjawab pertanyaan
                // pertama saat rekonsiliasi.
                'tagihan.kontrak.penghuni',
                'tagihan.kontrak.kamar',
            ])
            ->orderByDesc('tanggal_pembayaran')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => PembayaranResource::collection($daftar),
            'meta' => [
                'dari' => $data['dari'],
                'sampai' => $data['sampai'],
                'metode' => MetodePembayaran::label(),
            ],
        ]);
    }

    /**
     * Rekap pendapatan per metode pada satu rentang tanggal (T21).
     *
     * `dari`/`sampai` opsional di sini dan bawaannya bulan berjalan, berbeda
     * dari `index`. Alasannya: rekap adalah layar yang dibuka untuk melihat
     * "bagaimana bulan ini", dan menuntut dua tanggal sebelum menampilkan apa
     * pun membuat halaman kosong pada kunjungan pertama. Layar tetap boleh
     * menyebut rentang lain.
     */
    public function rekap(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
        ]);

        $hariIni = $this->waktu->hariIni();
        $dari = isset($data['dari'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $data['dari'])
            : $hariIni->startOfMonth();
        $sampai = isset($data['sampai'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $data['sampai'])
            : $hariIni;

        return response()->json([
            'data' => $this->rekap->susun($dari, $sampai),
        ]);
    }

    /**
     * Akun yang bisa tercatat sebagai pemegang uang tunai.
     *
     * Isinya pemilik dan seluruh pengelola - yaitu orang-orang yang secara
     * wajar menerima uang tunai di lokasi. Penghuni sengaja tidak ikut: ia
     * pihak yang membayar, bukan yang memegang setoran.
     *
     * Endpoint tersendiri, bukan bagian dari `/pengelola`, karena `/pengelola`
     * hanya untuk pemilik. Pengelola yang mencatat pembayaran perlu daftar ini
     * untuk menyebut "uangnya di Sari", dan menutupnya dengan wewenang
     * `kelola_penghuni` atau peran pemilik akan membuatnya mencatat semua uang
     * tunai atas namanya sendiri - justru fakta yang ingin dihindari.
     */
    public function penerima(): JsonResponse
    {
        $daftar = User::query()
            ->whereIn('peran', [User::PERAN_PEMILIK, User::PERAN_PENGELOLA])
            ->orderBy('name')
            ->get(['id', 'name', 'peran']);

        return response()->json([
            'data' => $daftar->map(fn (User $u) => [
                'id' => $u->id,
                'nama' => $u->name,
                'peran' => $u->peran,
            ])->values(),
        ]);
    }

    /**
     * Periksa satu pembayaran: terima atau tolak, dengan catatan.
     *
     * Pembayaran diambil lewat `pembayaran`, bukan `tagihan`: yang diperiksa
     * adalah baris uang tertentu. Endpoint ini tidak mengubah angka apa pun -
     * ia menambah baris pemeriksaan, dan yang berlaku adalah baris terakhir.
     */
    public function verifikasi(VerifikasiPembayaranRequest $request, Pembayaran $pembayaran): JsonResponse
    {
        $keputusan = $request->string('keputusan')->value();
        $catatan = $request->filled('catatan') ? $request->string('catatan')->value() : null;

        try {
            $baris = $keputusan === 'terima'
                ? $this->verifikator->terima($pembayaran, $catatan)
                : $this->verifikator->tolak($pembayaran, (string) $catatan);
        } catch (VerifikasiDitolak $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['catatan' => [$e->getMessage()]],
            ], 422);
        }

        $pembayaran->refresh()->load(['verifikasi', 'pencatat', 'penerima']);

        // Pemeriksaan tidak mengubah angka apa pun, jadi tidak ada transaksi
        // yang perlu ditunggu. Yang diberi tahu adalah pencatatnya - dialah yang
        // perlu tahu catatannya diterima atau ditolak - plus pengurus lain.
        $tagihan = $pembayaran->tagihan;

        $this->pemberitahu->pembayaranDiverifikasi(
            pelaku: $request->user(),
            pencatat: $pembayaran->pencatat,
            ringkasan: sprintf(
                'pembayaran Rp %s%s',
                number_format((int) $pembayaran->jumlah, 0, ',', '.'),
                $tagihan !== null ? ' untuk tagihan '.$tagihan->periode->translatedFormat('F Y') : '',
            ),
            diterima: $keputusan === 'terima',
        );

        return response()->json([
            'data' => [
                'verifikasi' => [
                    'id' => $baris->id,
                    'keputusan' => $baris->keputusan,
                    'catatan' => $baris->catatan,
                    'diverifikasi_pada' => $baris->created_at?->toIso8601String(),
                ],
                'pembayaran' => new PembayaranResource($pembayaran),
            ],
        ], 201);
    }
}
