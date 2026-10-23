<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\PencatatAudit;
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
use Illuminate\Support\Facades\DB;

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
        private readonly PencatatAudit $audit,
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
                // Matriks "kolom angka mana yang wajib" untuk setiap pasangan
                // tipe+nilai. Dikirim, bukan ditulis ulang di Vue, karena
                // matriks yang sama dipakai validasi dan CHECK constraint:
                // salinan ketiga di JavaScript adalah salinan yang paling
                // sering tertinggal saat bentuk denda bertambah.
                'kolom' => collect(PilihanKebijakan::menu())
                    ->map(fn (array $nilai, string $tipe) => collect($nilai)
                        ->map(fn ($_, string $kode) => PilihanKebijakan::kolomAngka($tipe, $kode))
                        ->all())
                    ->all(),
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
        //
        // Sejak T17 kolom yang relevan **disimpan**, bukan dibuang: denda persen
        // membawa tarif, plafon, dan tenggang; denda nominal membawa nominal.
        // Sebelumnya keempatnya selalu dikosongkan karena belum ada tipe yang
        // memakainya.
        $kolom = PilihanKebijakan::kolomAngka($data['tipe'], $data['nilai']);

        $data['persen'] = in_array('persen', $kolom['wajib'], true)
            ? $this->desimal($data['persen'] ?? null)
            : null;
        $data['plafon_persen'] = in_array('plafon_persen', $kolom['wajib'], true)
            ? $this->desimal($data['plafon_persen'] ?? null)
            : null;
        $data['masa_tenggang'] = in_array('masa_tenggang', $kolom['wajib'], true)
            ? (int) $data['masa_tenggang']
            : null;
        $data['nominal'] = in_array('nominal', $kolom['wajib'], true)
            ? (int) $data['nominal']
            : null;
        $data['tanggal'] = in_array('tanggal', $kolom['wajib'], true)
            ? (int) $data['tanggal']
            : null;

        try {
            $kebijakan = DB::transaction(function () use ($data) {
                $kebijakan = KebijakanKos::create($data);

                // Kebijakan menentukan angka uang (denda, deposit), sama
                // seperti tarif. Karena itu penambahannya dicatat: pertanyaan
                // "sejak kapan denda kos ini 2%/hari" harus bisa dijawab dari
                // jejak, bukan dari ingatan pemilik.
                $this->audit->catat(
                    aksi: PencatatAudit::AKSI_KEBIJAKAN_TAMBAH,
                    entitas: 'kebijakan_kos',
                    entitasId: $kebijakan->id,
                    nilaiBaru: [
                        'tipe' => $kebijakan->tipe,
                        'nilai' => $kebijakan->nilai,
                        'kamar_id' => $kebijakan->kamar_id,
                        'persen' => $kebijakan->persen,
                        'plafon_persen' => $kebijakan->plafon_persen,
                        'masa_tenggang' => $kebijakan->masa_tenggang,
                        'nominal' => $kebijakan->nominal,
                        'tanggal' => $kebijakan->tanggal,
                        'berlaku_dari' => $kebijakan->berlaku_dari->toDateString(),
                    ],
                );

                return $kebijakan;
            });
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

    /**
     * Persentase disimpan sebagai decimal(5,2), jadi nilainya dinormalkan ke dua
     * desimal di sini. Tanpa normalisasi, "1" dan "1.00" akan tersimpan sebagai
     * dua bentuk berbeda dan perbandingan riwayat jadi membingungkan.
     */
    private function desimal(mixed $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        return number_format((float) $nilai, 2, '.', '');
    }
}
