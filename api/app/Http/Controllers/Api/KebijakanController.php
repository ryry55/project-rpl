<?php

namespace App\Http\Controllers\Api;

use App\Domain\Kebijakan\PenyelesaiKebijakan;
use App\Domain\Kebijakan\PilihanKebijakan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanKebijakanRequest;
use App\Http\Resources\KebijakanResource;
use App\Models\Kamar;
use App\Models\KebijakanKos;
use App\Support\WaktuSistem;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kebijakan kos — append-only seperti tarif sewa dan komponen biaya.
 *
 * Tidak ada endpoint update atau delete. Mengubah kebijakan berarti menambah
 * baris baru dengan `berlaku_dari` baru; baris lama tetap ada sebagai riwayat,
 * dan tagihan yang sudah terbit tetap memakai salinan yang dibekukan saat
 * terbit (R-06). Menyediakan endpoint ubah akan membuat riwayat itu bohong.
 *
 * `GET` mengembalikan dua hal sekaligus, dan pemisahannya disengaja:
 *
 *   - `data`  — seluruh baris, terbaru dulu. Ini riwayat, untuk layar.
 *   - `meta`  — apa yang **berlaku** untuk satu kamar pada satu periode, plus
 *               daftar tipe yang masih kurang. Ini jawaban untuk pertanyaan
 *               "boleh menagih atau tidak", dan hanya bisa dijawab dengan
 *               memilih kamar dan periode lebih dulu.
 *
 * Tanpa `meta`, layar harus menyusun sendiri aturan "khusus kamar menimpa
 * default" di JavaScript — aturan yang sama yang sudah ditulis di
 * {@see PenyelesaiKebijakan}, dan yang paling mudah salah saat disalin.
 */
class KebijakanController extends Controller
{
    public function __construct(
        private readonly PenyelesaiKebijakan $penyelesai,
        private readonly WaktuSistem $waktu,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'berlaku_pada' => ['nullable', 'date_format:Y-m-d'],
            'kamar_id' => ['nullable', 'integer', 'exists:kamar,id'],
        ]);

        $periode = $request->filled('berlaku_pada')
            ? Carbon::parse($request->string('berlaku_pada')->value())
            : Carbon::parse($this->waktu->hariIni()->toDateString());

        $kamar = $request->filled('kamar_id')
            ? Kamar::find($request->integer('kamar_id'))
            : null;

        // Riwayat: seluruh baris, bukan hanya yang berlaku. Layar butuh
        // keduanya — "apa yang berlaku sekarang" untuk memutuskan, dan "apa
        // yang pernah ditetapkan" untuk menjelaskan tagihan lama.
        $riwayat = KebijakanKos::query()
            ->when($kamar !== null, fn ($q) => $q->where(
                fn ($q2) => $q2->whereNull('kamar_id')->orWhere('kamar_id', $kamar->id)
            ))
            ->when($kamar === null, fn ($q) => $q->whereNull('kamar_id'))
            ->orderByDesc('berlaku_dari')
            ->orderByDesc('id')
            ->get();

        $berlaku = $this->penyelesai->semuaBerlaku($periode, $kamar);
        $kurang = $this->penyelesai->tipeYangKurang($periode, $kamar);

        return response()->json([
            'data' => KebijakanResource::collection($riwayat),
            'meta' => [
                'berlaku_pada' => $periode->copy()->startOfMonth()->toDateString(),
                'kamar_id' => $kamar?->id,
                // Dipetakan per tipe supaya layar bisa menampilkan "belum
                // ditetapkan" untuk tipe yang kosong tanpa menebak dari
                // panjang array.
                'berlaku' => collect(PilihanKebijakan::TIPE_TERSEDIA)
                    ->mapWithKeys(fn (string $tipe) => [
                        $tipe => isset($berlaku[$tipe])
                            ? new KebijakanResource($berlaku[$tipe])
                            : null,
                    ])
                    ->all(),
                'kurang' => $kurang,
                'lengkap' => $kurang === [],
                'tipe' => PilihanKebijakan::TIPE_TERSEDIA,
                // Menu pilihan dikirim server supaya layar tidak menyimpan
                // salinan kedua daftar nilai yang sah.
                'pilihan' => PilihanKebijakan::menu(),
                'label_tipe' => collect(PilihanKebijakan::TIPE_TERSEDIA)
                    ->mapWithKeys(fn (string $tipe) => [$tipe => PilihanKebijakan::labelTipe($tipe)])
                    ->all(),
            ],
        ]);
    }

    public function store(SimpanKebijakanRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Kolom angka yang tidak relevan dikosongkan, bukan disimpan apa adanya.
        // Validasi sudah melarangnya, tetapi menormalkan di sini membuat
        // perilaku tetap benar bila aturan itu dilonggarkan nanti.
        $data['persen'] = null;
        $data['plafon_persen'] = null;
        $data['masa_tenggang'] = null;
        $data['nominal'] = null;
        $data['tanggal'] = PilihanKebijakan::butuhTanggal($data['tipe'], $data['nilai'])
            ? (int) $data['tanggal']
            : null;

        try {
            $kebijakan = KebijakanKos::create($data);
        } catch (QueryException $e) {
            // SQLSTATE 23505: unique (tipe, kamar_id, berlaku_dari). Pemeriksaan
            // di FormRequest punya celah balapan, dan constraint yang menang.
            if ($e->getCode() === '23505') {
                return response()->json([
                    'message' => 'Kebijakan untuk jenis dan tanggal berlaku itu sudah ada.',
                    'errors' => ['berlaku_dari' => ['Sudah ada baris dengan tanggal berlaku ini.']],
                ], 422);
            }

            throw $e;
        }

        return response()->json(['data' => new KebijakanResource($kebijakan)], 201);
    }
}
