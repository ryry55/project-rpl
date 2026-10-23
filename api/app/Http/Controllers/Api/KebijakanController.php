<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\PencatatAudit;
use App\Domain\Kebijakan\PenyelesaiKebijakan;
use App\Domain\Kebijakan\PilihanKebijakan;
use App\Domain\Notifikasi\Pemberitahu;
use App\Domain\Persetujuan\PenerapPerubahan;
use App\Domain\Persetujuan\PengajuUsulan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanKebijakanRequest;
use App\Http\Resources\KebijakanResource;
use App\Http\Resources\UsulanResource;
use App\Models\Kamar;
use App\Models\KebijakanKos;
use App\Models\UsulanPerubahan;
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
 * Sejak T26, `store` punya dua nasib menurut peran - sama seperti tarif.
 * Kebijakan mengubah cara seluruh kos menagih, jadi ia justru perubahan yang
 * paling perlu disetujui pemilik: satu baris di sini bisa mengubah tagihan
 * semua penghuni bulan depan.
 */
class KebijakanController extends Controller
{
    public function __construct(
        private readonly PenyelesaiKebijakan $penyelesai,
        private readonly WaktuSistem $waktu,
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
                // Tunjukkan apakah akun ini hanya boleh mengusulkan. Layar
                // memakai ini untuk mengubah kalimat tombol dari "Simpan"
                // menjadi "Ajukan perubahan" - tombol yang berbunyi sama untuk
                // dua akibat berbeda adalah tombol yang menipu.
                'boleh_menetapkan' => $request->user()->isPemilik(),
            ],
        ]);
    }

    public function store(SimpanKebijakanRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! $request->user()->isPemilik()) {
            $hasil = $this->pengaju->ajukan(
                tipe: UsulanPerubahan::TIPE_KEBIJAKAN,
                payload: $data,
                pengusul: $request->user(),
            );

            $this->pemberitahu->setelahTransaksi($hasil->surat);

            $hasil->usulan->load(['pengusul', 'pemutus']);

            return response()->json([
                'data' => new UsulanResource($hasil->usulan),
                'meta' => [
                    'diajukan' => true,
                    'pesan' => 'Usulan terkirim. Kebijakan belum berubah sampai pemilik menyetujuinya.',
                ],
            ], 202);
        }

        try {
            $hasil = $this->penerap->kebijakan($data, PencatatAudit::AKSI_KEBIJAKAN_TAMBAH);
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

        if ($hasil->surat !== null) {
            $this->pemberitahu->setelahTransaksi($hasil->surat);
        }

        return response()->json([
            'data' => new KebijakanResource($hasil->baris),
            'meta' => ['diajukan' => false],
        ], 201);
    }
}
