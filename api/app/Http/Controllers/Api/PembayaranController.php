<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\PembayaranDitolak;
use App\Domain\Notifikasi\Pemberitahu;
use App\Domain\Pembayaran\MetodePembayaran;
use App\Domain\Pembayaran\PembalikPembayaran;
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
        private readonly PembalikPembayaran $pembalik,
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
                // Menandai baris yang sudah ditarik kembali (REV-FIN-01).
                // Dimuat sekali untuk seluruh halaman, bukan satu query per
                // baris - dan tanpa ini layar akan menawarkan tombol "Balik"
                // untuk pembayaran yang sudah dibalik.
                'pembalik',
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
     * Uang tunai yang tercatat diterima **pemanggil sendiri** (REV-SEC-06).
     *
     * Ada karena `/rekap` ditutup untuk pengelola. Menutup rekap tanpa
     * menyediakan pengganti berarti pengelola kehilangan satu-satunya cara
     * menjawab pertanyaan yang memang tanggung jawabnya: berapa uang tunai yang
     * masih ia pegang. Yang dibuka di sini bukan rekap yang dipersempit,
     * melainkan pertanyaan yang berbeda - dan tanpa nama penghuni, tanpa metode
     * lain, tanpa piutang.
     */
    public function tunaiSaya(Request $request): JsonResponse
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

        $user = $request->user();

        return response()->json([
            'data' => $this->rekap->tunaiSaya((int) $user->getKey(), $dari, $sampai),
            'meta' => [
                'penerima_id' => (int) $user->getKey(),
                'penerima_nama' => $user->name,
                'simulasi' => $this->waktu->sedangSimulasi(),
                'demo_mode' => (bool) config('app.demo_mode'),
            ],
        ]);
    }

    /**
     * Tarik kembali satu pembayaran yang ternyata salah catat (REV-FIN-01).
     *
     * Kenapa endpoint tersendiri, bukan bagian dari `verifikasi`. Pemeriksaan
     * dan pembalikan menjawab dua pertanyaan yang berbeda: "apakah uang ini
     * cocok dengan mutasi rekening?" dan "apakah uang ini memang pernah
     * diterima?". Pemeriksaan hanya menempelkan label dan sengaja tidak
     * menyentuh angka; pembalikan mengubah angka. Menggabungkannya akan membuat
     * satu tombol berbuat dua hal yang berbeda tanpa pemakainya tahu.
     *
     * Alasan wajib diisi, karena baris negatif di rekap tanpa keterangan adalah
     * pertanyaan baru, bukan jawaban.
     */
    public function balik(Request $request, Pembayaran $pembayaran): JsonResponse
    {
        $data = $request->validate([
            'alasan' => ['required', 'string', 'max:255'],
        ]);

        try {
            $baris = $this->pembalik->balik(
                $pembayaran,
                $data['alasan'],
                (int) $request->user()->getKey(),
            );
        } catch (PembayaranDitolak $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['alasan' => [$e->getMessage()]],
            ], 422);
        }

        // `pembalik` ikut dimuat supaya `sudah_dibalik` benar-benar dikirim:
        // resource itu memakai `whenLoaded('pembalik')`, dan tanpa relasinya
        // kuncinya hilang sama sekali dari respons - bukan bernilai `false`.
        $pembayaran->refresh()->load(['verifikasi', 'pencatat', 'penerima', 'tagihan', 'pembalik']);

        return response()->json([
            'data' => [
                'pembalik' => new PembayaranResource($baris),
                'pembayaran' => new PembayaranResource($pembayaran),
                'status_tagihan' => $pembayaran->tagihan?->status,
            ],
        ], 201);
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
        // REV-FIN-03: pengelola tidak boleh memeriksa catatannya sendiri.
        //
        // Tanpa batas ini, pemisahan wewenang pencatatan dan pemeriksaan hanya
        // berlaku di atas kertas: pengelola dengan kedua wewenang mencatat
        // angka apa pun lalu mengesahkannya sendiri. Pemilik dikecualikan -
        // pada kos kecil ia sering satu-satunya orang yang memegang uang, dan
        // melarangnya memeriksa catatannya sendiri berarti tidak ada yang bisa
        // memeriksa sama sekali.
        if ($request->user()->isPengelola() && (int) $pembayaran->dicatat_oleh === (int) $request->user()->getKey()) {
            return response()->json([
                'message' => 'Anda tidak bisa memeriksa pembayaran yang Anda catat sendiri. '
                    .'Minta pemilik atau pengelola lain yang memeriksanya.',
                'kode' => 'verifikasi_catatan_sendiri',
            ], 403);
        }

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
