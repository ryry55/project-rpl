<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\PencatatAudit;
use App\Domain\Notifikasi\Pemberitahu;
use App\Domain\Persetujuan\PenerapPerubahan;
use App\Domain\Persetujuan\PengajuUsulan;
use App\Domain\Tarif\PencariTarif;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanKomponenBiayaRequest;
use App\Http\Resources\KomponenBiayaResource;
use App\Http\Resources\UsulanResource;
use App\Models\Kamar;
use App\Models\KomponenBiaya;
use App\Models\UsulanPerubahan;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Komponen biaya tetap — append-only seperti tarif sewa.
 *
 * Menghentikan komponen dilakukan dengan menambah baris baru bernilai 0, bukan
 * dengan menonaktifkan baris lama. Lihat catatan di migrasi 130300.
 *
 * Sejak REV-SEC-07 endpoint tulis punya dua nasib menurut siapa yang
 * memanggilnya, persis seperti `POST /tarif`:
 *
 *   pemilik   -> baris komponen ditulis sekarang (201)
 *   pengelola -> usulan dicatat, komponen BELUM berubah (202)
 *
 * Sebelumnya jalur ini menulis langsung untuk kedua peran. Akibatnya
 * `ubah_tarif` yang seharusnya berarti "boleh mengusulkan" menjadi "boleh
 * menambah biaya bulanan ke semua kamar tanpa persetujuan" - dan jalur yang
 * tidak dijaga selalu menjadi jalur yang dipakai.
 */
class KomponenBiayaController extends Controller
{
    public function __construct(
        private readonly PencariTarif $pencari,
        private readonly PengajuUsulan $pengaju,
        private readonly PenerapPerubahan $penerap,
        private readonly Pemberitahu $pemberitahu,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'berlaku_pada' => ['nullable', 'date_format:Y-m-d'],
            'kamar_id' => ['nullable', 'integer', 'exists:kamar,id'],
        ]);

        // T11: `kamar_id` mempersempit hasil ke komponen yang benar-benar
        // berlaku bagi kamar itu (global + khusus kamar). Tanpa `kamar_id`,
        // hanya komponen global yang tampil, supaya daftar di UI tidak
        // mencampur komponen milik kamar lain.
        $kamar = $request->filled('kamar_id')
            ? Kamar::find($request->integer('kamar_id'))
            : null;

        if ($request->filled('berlaku_pada')) {
            $periode = Carbon::parse($request->string('berlaku_pada')->value());

            return response()->json([
                'data' => KomponenBiayaResource::collection(
                    $this->pencari->komponenUntuk($periode, $kamar)
                ),
            ]);
        }

        $daftar = KomponenBiaya::query()
            ->when($kamar !== null, fn ($q) => $q->where('kamar_id', $kamar->id))
            ->when($kamar === null, fn ($q) => $q->whereNull('kamar_id'))
            ->orderBy('nama')
            ->orderByDesc('berlaku_dari')
            ->get();

        return response()->json(['data' => KomponenBiayaResource::collection($daftar)]);
    }

    public function store(SimpanKomponenBiayaRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Pengelola mengusulkan; pemilik menetapkan. Diperiksa dari peran,
        // bukan dari wewenang: pemilik selalu punya `ubah_tarif`, dan wewenang
        // itulah yang membuka pintu ke sini untuk kedua peran.
        if (! $request->user()->isPemilik()) {
            $hasil = $this->pengaju->ajukan(
                tipe: UsulanPerubahan::TIPE_KOMPONEN,
                payload: $data,
                pengusul: $request->user(),
            );

            $this->pemberitahu->setelahTransaksi($hasil->surat);

            $hasil->usulan->load(['pengusul', 'pemutus']);

            return response()->json([
                'data' => new UsulanResource($hasil->usulan),
                'meta' => [
                    'diajukan' => true,
                    'pesan' => 'Usulan terkirim. Komponen biaya belum berubah sampai pemilik menyetujuinya.',
                ],
            ], 202);
        }

        try {
            $hasil = $this->penerap->komponen($data, PencatatAudit::AKSI_KOMPONEN_TAMBAH);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return response()->json([
                    'message' => 'Komponen dengan nama dan tanggal berlaku itu sudah ada.',
                    'errors' => ['berlaku_dari' => ['Sudah ada baris dengan tanggal berlaku ini.']],
                ], 422);
            }

            throw $e;
        }

        // Email setelah transaksi selesai. Lihat catatan di Pemberitahu.
        if ($hasil->surat !== null) {
            $this->pemberitahu->setelahTransaksi($hasil->surat);
        }

        /** @var KomponenBiaya $komponen */
        $komponen = $hasil->baris;
        $komponen->load('kamar');

        return response()->json([
            'data' => new KomponenBiayaResource($komponen),
            'meta' => ['diajukan' => false],
        ], 201);
    }
}
