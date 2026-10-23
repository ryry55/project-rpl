<?php

namespace App\Http\Controllers\Api;

use App\Domain\Notifikasi\Pemberitahu;
use App\Domain\Persetujuan\JenisUsulan;
use App\Domain\Persetujuan\PemutusUsulan;
use App\Domain\Persetujuan\UsulanTidakDapatDiputuskan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TolakUsulanRequest;
use App\Http\Resources\UsulanResource;
use App\Models\UsulanPerubahan;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Antrean usulan perubahan dan keputusannya (T26).
 *
 * Tidak ada endpoint untuk **mengajukan** usulan di sini. Pengajuan memakai
 * endpoint yang sama dengan penetapan - `POST /tarif` dan `POST /kebijakan` -
 * dan yang berbeda hanya akibatnya menurut peran pemanggil:
 *
 *   pemilik   -> baris tarif/kebijakan ditulis, 201
 *   pengelola -> baris usulan ditulis, 202, tarif BELUM berubah
 *
 * Kenapa begitu, bukan endpoint usulan tersendiri. Niatnya satu - "tarif kamar
 * ini menjadi sekian" - dan yang berbeda hanya siapa yang berwenang
 * menetapkannya. Dua endpoint berarti dua jalan menuju perubahan angka uang,
 * dan cepat atau lambat salah satunya lupa memeriksa wewenang. Satu endpoint
 * berarti satu tempat memeriksa, dan pemeriksaan itu tidak bisa dilewati.
 *
 * Kelas ini hanya menangani sisi **antrean**: membaca daftar, menyetujui,
 * menolak. Ketiganya tindakan yang hanya masuk akal bagi pemilik atau bagi
 * orang yang mengusulkan.
 */
class UsulanController extends Controller
{
    public function __construct(
        private readonly PemutusUsulan $pemutus,
        private readonly Pemberitahu $pemberitahu,
    ) {}

    /**
     * Daftar usulan.
     *
     * Pemilik melihat semua; pengelola hanya melihat usulannya sendiri. Bukan
     * kerahasiaan - pengelola memang boleh tahu apa yang diusulkan rekannya -
     * melainkan supaya daftar yang ia buka menjawab pertanyaan yang ia punya:
     * "apa yang terjadi dengan usulan saya".
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:menunggu,disetujui,ditolak,kedaluwarsa'],
        ]);

        $user = $request->user();

        $query = UsulanPerubahan::query()->with(['pengusul', 'pemutus']);

        if (! $user->isPemilik()) {
            $query->where('diajukan_oleh', $user->getKey());
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->value();

            // Kedaluwarsa tidak punya barisnya sendiri di basis data, jadi
            // penyaringannya memakai dua syarat: masih `menunggu` DAN batas
            // waktunya sudah lewat. Menyaring `status = 'kedaluwarsa'` akan
            // selalu menghasilkan daftar kosong, dan daftar kosong terbaca
            // sebagai "tidak ada yang kedaluwarsa".
            if ($status === UsulanPerubahan::STATUS_KEDALUWARSA) {
                $query->where('status', UsulanPerubahan::STATUS_MENUNGGU)
                    ->where('kedaluwarsa_pada', '<', now());
            } elseif ($status === UsulanPerubahan::STATUS_MENUNGGU) {
                $query->where('status', UsulanPerubahan::STATUS_MENUNGGU)
                    ->where('kedaluwarsa_pada', '>=', now());
            } else {
                $query->where('status', $status);
            }
        }

        $daftar = $query->orderByDesc('dibuat_pada')->orderByDesc('id')->get();

        // Jumlah yang menunggu dihitung dari cakupan yang SAMA dengan daftar,
        // bukan dari seluruh tabel. Untuk pemilik hasilnya sama; untuk pengelola
        // angka seluruh tabel akan membocorkan berapa usulan rekan lain yang
        // sedang menunggu - dan layarnya akan menulis "menunggu keputusan Anda"
        // untuk sesuatu yang bukan miliknya dan bukan keputusannya.
        $menunggu = UsulanPerubahan::query()
            ->where('status', UsulanPerubahan::STATUS_MENUNGGU)
            ->where('kedaluwarsa_pada', '>=', now());

        if (! $user->isPemilik()) {
            $menunggu->where('diajukan_oleh', $user->getKey());
        }

        return response()->json([
            'data' => UsulanResource::collection($daftar),
            'meta' => [
                'tipe' => JenisUsulan::SEMUA,
                'label_tipe' => collect(JenisUsulan::SEMUA)
                    ->mapWithKeys(fn (string $t) => [$t => JenisUsulan::label($t)])
                    ->all(),
                'jumlah_menunggu' => $menunggu->count(),
            ],
        ]);
    }

    /**
     * Setujui satu usulan.
     *
     * 409 dipakai untuk usulan yang sudah diputuskan atau sudah kedaluwarsa,
     * bukan 422. Bedanya: 422 berarti "kiriman Anda salah dan bisa diperbaiki",
     * sedangkan di sini kirimannya benar - keadaannya yang sudah berubah.
     * Pemilik yang melihat pesan 422 akan mencari kesalahan pada formulir yang
     * tidak ada.
     */
    public function setujui(Request $request, UsulanPerubahan $usulan): JsonResponse
    {
        try {
            $surat = $this->pemutus->setujui($usulan, $request->user());
        } catch (UsulanTidakDapatDiputuskan $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (QueryException $e) {
            // SQLSTATE 23505: tanggal berlaku yang diusulkan sudah dipakai
            // tarif atau kebijakan lain sejak usulan dibuat. Usulannya sengaja
            // TIDAK ditutup: pengelola bisa mengajukan ulang dengan tanggal
            // lain, dan jejak bahwa usulan ini pernah ada tetap utuh.
            if ($e->getCode() === '23505') {
                return response()->json([
                    'message' => 'Perubahan ini tidak bisa diterapkan karena sudah ada baris lain '
                        .'dengan tanggal berlaku yang sama. Usulan tetap menunggu; minta pengelola '
                        .'mengajukan ulang dengan tanggal lain.',
                ], 409);
            }

            throw $e;
        }

        foreach ($surat as $satu) {
            $this->pemberitahu->setelahTransaksi($satu);
        }

        $usulan->refresh()->load(['pengusul', 'pemutus']);

        return response()->json(['data' => new UsulanResource($usulan)]);
    }

    /**
     * Tolak satu usulan.
     *
     * Alasan wajib - lihat `TolakUsulanRequest`. Penolakan tanpa alasan membuat
     * pengelola mengajukan ulang hal yang sama persis.
     */
    public function tolak(TolakUsulanRequest $request, UsulanPerubahan $usulan): JsonResponse
    {
        try {
            $surat = $this->pemutus->tolak($usulan, $request->user(), $request->alasan());
        } catch (UsulanTidakDapatDiputuskan $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        foreach ($surat as $satu) {
            $this->pemberitahu->setelahTransaksi($satu);
        }

        $usulan->refresh()->load(['pengusul', 'pemutus']);

        return response()->json(['data' => new UsulanResource($usulan)]);
    }
}
