<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\PembayaranDitolak;
use App\Domain\Billing\PencatatPembayaran;
use App\Domain\Billing\PenerbitanMassal;
use App\Domain\Billing\PenerbitTagihan;
use App\Domain\Billing\TagihanTidakDapatDiterbitkan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CatatPembayaranRequest;
use App\Http\Requests\Api\TerbitkanMassalRequest;
use App\Http\Requests\Api\TerbitkanTagihanRequest;
use App\Http\Resources\PembayaranResource;
use App\Http\Resources\TagihanResource;
use App\Models\Kontrak;
use App\Models\Tagihan;
use App\Support\WaktuSistem;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagihanController extends Controller
{
    public function __construct(
        private readonly PenerbitTagihan $penerbit,
        private readonly PenerbitanMassal $penerbitanMassal,
        private readonly PencatatPembayaran $pencatatPembayaran,
        private readonly WaktuSistem $waktu,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Tagihan::query()
            // R-07: isolasi diterapkan di query, bukan setelah data diambil.
            ->terlihatOleh($request->user())
            ->with(['rincian', 'kontrak.kamar', 'kontrak.penghuni'])
            // Satu query penjumlahan untuk seluruh halaman, bukan satu per
            // tagihan. Tanpa ini, layar daftar menjalankan N+1 query hanya untuk
            // menghitung "dibayar" dan "sisa".
            ->withSum('pembayaran as total_dibayar', 'jumlah');

        if ($request->filled('kontrak_id')) {
            $query->where('kontrak_id', $request->integer('kontrak_id'));
        }

        if ($request->filled('periode')) {
            $request->validate(['periode' => ['date_format:Y-m-d']]);
            $query->whereDate('periode', Carbon::parse($request->string('periode')->value())->startOfMonth());
        }

        $daftar = $query->orderByDesc('periode')->orderByDesc('id')->get();

        return response()->json([
            'data' => TagihanResource::collection($daftar),
            // Diumumkan di badan respons, bukan hanya lewat header: header
            // lintas-origin tidak terbaca JavaScript tanpa exposed_headers,
            // dan bergantung pada itu membuat UI tidak bisa menjelaskan
            // apakah tanggal simulasi benar-benar berlaku.
            'meta' => [
                'tanggal_acuan' => $this->waktu->hariIni()->toDateString(),
                'simulasi' => $this->waktu->sedangSimulasi(),
                'demo_mode' => (bool) config('app.demo_mode'),
            ],
        ]);
    }

    public function store(TerbitkanTagihanRequest $request): JsonResponse
    {
        $kontrak = Kontrak::with('kamar')->findOrFail($request->integer('kontrak_id'));
        $periode = Carbon::parse($request->string('periode')->value());

        try {
            $tagihan = $this->penerbit->terbitkan($kontrak, $periode);
        } catch (TagihanTidakDapatDiterbitkan $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['periode' => [$e->getMessage()]],
            ], 422);
        } catch (QueryException $e) {
            // R-02: unique index (kontrak_id, periode). Pengecekan exists() di
            // aplikasi punya celah balapan — dua klik cepat bisa lolos keduanya.
            // Constraint yang jadi pengaman; di sini hanya diterjemahkan ke 409.
            if ($e->getCode() === '23505') {
                return response()->json([
                    'message' => 'Tagihan untuk kontrak ini pada periode tersebut sudah ada.',
                    'errors' => ['periode' => ['Tagihan periode ini sudah pernah diterbitkan.']],
                ], 409);
            }

            throw $e;
        }

        $tagihan->load(['rincian', 'kontrak.kamar', 'kontrak.penghuni']);

        return response()->json(['data' => new TagihanResource($tagihan)], 201);
    }

    /**
     * Rincian satu tagihan.
     *
     * `Tagihan $tagihan` dari route model binding sengaja **tidak** dipakai
     * langsung: binding mengambil baris tanpa memandang pemiliknya, sehingga
     * penghuni bisa membaca tagihan orang lain hanya dengan menebak id. Baris
     * diambil ulang lewat scope `terlihatOleh`, dan yang tidak terlihat
     * menghasilkan 404 — bukan 403, karena 403 membocorkan bahwa tagihan itu
     * ada.
     */
    public function show(Request $request, Tagihan $tagihan): JsonResponse
    {
        $tagihan = Tagihan::query()
            ->terlihatOleh($request->user())
            ->with(['rincian', 'pembayaran', 'kontrak.kamar', 'kontrak.penghuni'])
            ->withSum('pembayaran as total_dibayar', 'jumlah')
            ->findOrFail($tagihan->getKey());

        return response()->json(['data' => new TagihanResource($tagihan)]);
    }

    /**
     * Catat uang yang benar-benar diterima (proses 4.0).
     *
     * Seluruh keputusan yang butuh data tagihan — melebihi sisa, sudah lunas,
     * dibatalkan — berada di {@see PencatatPembayaran}, di dalam transaksi dan di
     * bawah kunci baris. Controller hanya menerjemahkan penolakan menjadi 422
     * dengan pesan yang menyebut angka konkret.
     */
    public function bayar(CatatPembayaranRequest $request, Tagihan $tagihan): JsonResponse
    {
        try {
            $hasil = $this->pencatatPembayaran->catat(
                tagihan: $tagihan,
                jumlah: $request->integer('jumlah'),
                tanggalPembayaran: Carbon::parse($request->string('tanggal_pembayaran')->value()),
                catatan: $request->filled('catatan') ? $request->string('catatan')->value() : null,
            );
        } catch (PembayaranDitolak $e) {
            return response()->json([
                'message' => $e->getMessage(),
                // Bidang yang disalahkan dipilih sesuai pesannya: kelebihan
                // bayar adalah soal `jumlah`, bukan soal tanggal.
                'errors' => ['jumlah' => [$e->getMessage()]],
            ], 422);
        }

        $tagihan->refresh()->load(['rincian', 'pembayaran', 'kontrak.kamar', 'kontrak.penghuni']);

        return response()->json([
            'data' => [
                'pembayaran' => new PembayaranResource($hasil->pembayaran),
                'status_sebelum' => $hasil->statusSebelum,
                'status_sesudah' => $hasil->statusSesudah,
                'total' => $hasil->total,
                'dibayar' => $hasil->dibayar,
                'sisa' => $hasil->sisa,
                'tagihan' => new TagihanResource($tagihan),
            ],
            'meta' => [
                'tanggal_acuan' => $this->waktu->hariIni()->toDateString(),
                'simulasi' => $this->waktu->sedangSimulasi(),
                'demo_mode' => (bool) config('app.demo_mode'),
            ],
        ], 201);
    }

    /**
     * Terbitkan tagihan untuk semua kontrak aktif pada satu periode (R-02).
     *
     * Kode statusnya dipilih untuk menjawab pertanyaan yang sebenarnya
     * ditanyakan pemilik: "apakah aksi saya tadi mengubah sesuatu?"
     *
     *   201 — ada tagihan baru terbit.
     *   409 — tidak ada yang terbit karena semuanya sudah punya tagihan periode
     *         ini. Ini yang terjadi saat tombol ditekan dua kali, dan ia harus
     *         terlihat sebagai penolakan, bukan sebagai sukses kosong.
     *   200 — tidak ada yang terbit karena kontraknya memang belum mulai.
     *         Bukan kesalahan; tidak ada yang perlu diperbaiki pemilik.
     *   422 — prasyarat belum terpenuhi: belum ada kontrak aktif sama sekali.
     */
    public function terbitkan(TerbitkanMassalRequest $request): JsonResponse
    {
        if (! Kontrak::query()->where('status', 'aktif')->exists()) {
            return response()->json([
                'message' => 'Belum ada kontrak aktif. Tambahkan kontrak lebih dulu.',
                'errors' => ['periode' => ['Belum ada kontrak aktif.']],
            ], 422);
        }

        $periode = Carbon::parse($request->string('periode')->value());
        $hasil = $this->penerbitanMassal->jalankan($periode);

        $status = match (true) {
            $hasil['terbit'] > 0 => 201,
            $hasil['dilewati_duplikat'] > 0 => 409,
            default => 200,
        };

        return response()->json([
            'data' => $hasil,
            'meta' => [
                'tanggal_acuan' => $this->waktu->hariIni()->toDateString(),
                'simulasi' => $this->waktu->sedangSimulasi(),
                'demo_mode' => (bool) config('app.demo_mode'),
            ],
        ], $status);
    }
}
