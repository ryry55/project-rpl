<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\PencatatAudit;
use App\Domain\Notifikasi\Pemberitahu;
use App\Domain\Persetujuan\PenerapPerubahan;
use App\Domain\Persetujuan\PengajuUsulan;
use App\Domain\Tarif\PencariTarif;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanTarifRequest;
use App\Http\Resources\TarifSewaResource;
use App\Http\Resources\UsulanResource;
use App\Models\Kamar;
use App\Models\TarifSewa;
use App\Models\UsulanPerubahan;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Riwayat tarif sewa — lapis pertama R-06.
 *
 * Tidak ada endpoint PUT/PATCH/DELETE. Mengubah tarif berarti menambah baris
 * baru; baris lama dipertahankan, dan trigger basis data menolak perubahan
 * langsung. Tanpa ini, "tarif tidak berlaku surut" hanya janji di dokumen.
 *
 * Sejak T26 endpoint ini punya dua nasib menurut siapa yang memanggilnya:
 *
 *   pemilik   -> baris tarif ditulis sekarang (201)
 *   pengelola -> usulan dicatat, tarif BELUM berubah (202)
 *
 * Keduanya sengaja berada di endpoint yang sama. Niatnya sama - "tarif kamar
 * ini menjadi sekian" - dan yang berbeda hanya siapa yang berwenang
 * menetapkannya. Memisahkan menjadi endpoint lain berarti layar harus tahu
 * peran sebelum memilih tombol, dan pemilik yang melihat tombol berbeda dari
 * pengelola adalah pemilik yang bertanya-tanya apakah ia memakai aplikasi yang
 * sama.
 */
class TarifController extends Controller
{
    public function __construct(
        private readonly PencariTarif $pencari,
        private readonly PengajuUsulan $pengaju,
        private readonly PenerapPerubahan $penerap,
        private readonly Pemberitahu $pemberitahu,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = TarifSewa::query()->with('kamar');

        if ($request->filled('kamar_id')) {
            $query->where('kamar_id', $request->integer('kamar_id'));
        }

        $daftar = $query->orderBy('kamar_id')->orderByDesc('berlaku_dari')->get();

        return response()->json(['data' => TarifSewaResource::collection($daftar)]);
    }

    public function store(SimpanTarifRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Pengelola mengusulkan; pemilik menetapkan. Diperiksa dari peran,
        // bukan dari wewenang: pemilik selalu punya `ubah_tarif`, dan wewenang
        // itulah yang membuka pintu ke sini untuk kedua peran.
        if (! $request->user()->isPemilik()) {
            $hasil = $this->pengaju->ajukan(
                tipe: UsulanPerubahan::TIPE_TARIF,
                payload: $data,
                pengusul: $request->user(),
            );

            $this->pemberitahu->setelahTransaksi($hasil->surat);

            $hasil->usulan->load(['pengusul', 'pemutus']);

            return response()->json([
                'data' => new UsulanResource($hasil->usulan),
                'meta' => [
                    'diajukan' => true,
                    'pesan' => 'Usulan terkirim. Tarif belum berubah sampai pemilik menyetujuinya.',
                ],
            ], 202);
        }

        try {
            $hasil = $this->penerap->tarif($data, PencatatAudit::AKSI_TARIF_TAMBAH);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return response()->json([
                    'message' => 'Kamar ini sudah punya tarif yang berlaku dari tanggal tersebut.',
                    'errors' => ['berlaku_dari' => ['Sudah ada tarif dengan tanggal berlaku ini.']],
                ], 422);
            }

            throw $e;
        }

        // Email setelah transaksi selesai. Lihat catatan di Pemberitahu.
        if ($hasil->surat !== null) {
            $this->pemberitahu->setelahTransaksi($hasil->surat);
        }

        $tarif = $hasil->baris;
        $tarif->load('kamar');

        return response()->json([
            'data' => new TarifSewaResource($tarif),
            'meta' => ['diajukan' => false],
        ], 201);
    }

    /**
     * Tarif yang berlaku untuk sebuah periode.
     *
     * Dipakai UI dan, nanti di T3, oleh penerbitan tagihan. Endpoint terpisah
     * supaya "tarif mana yang dipakai" bisa diperiksa tanpa menerbitkan tagihan.
     */
    public function berlaku(Request $request, Kamar $kamar): JsonResponse
    {
        $request->validate([
            'periode' => ['required', 'date_format:Y-m-d'],
        ]);

        $periode = Carbon::parse($request->string('periode')->value());
        $tarif = $this->pencari->sewaUntuk($kamar, $periode);

        if (! $tarif) {
            return response()->json([
                'message' => 'Belum ada tarif untuk kamar ini pada periode tersebut.',
                'errors' => ['periode' => ['Belum ada tarif yang berlaku.']],
            ], 404);
        }

        return response()->json([
            'data' => [
                'kamar_id' => $kamar->id,
                'periode' => $periode->copy()->startOfMonth()->toDateString(),
                'tarif_id' => $tarif->id,
                'nilai' => (int) $tarif->nilai,
                'berlaku_dari' => $tarif->berlaku_dari->toDateString(),
            ],
        ]);
    }
}
