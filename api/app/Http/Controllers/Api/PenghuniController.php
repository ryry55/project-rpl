<?php

namespace App\Http\Controllers\Api;

use App\Domain\Akun\PembuatAkun;
use App\Domain\Akun\TautanAkun;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanPenghuniRequest;
use App\Http\Resources\PenghuniResource;
use App\Models\Penghuni;
use App\Models\TokenAkun;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Data induk penghuni dan akun login-nya.
 *
 * Sejak T27 endpoint ini **tidak menerima kata sandi**. Pemilik membuat akun,
 * sistem mengirim tautan aktivasi ke email penghuni, dan penghuni menetapkan
 * sandinya sendiri. Pemilik tidak pernah tahu sandi itu - dan itu bukan
 * kesopanan, melainkan syarat agar klaim isolasi R-07 punya arti: isolasi
 * terhadap orang yang memegang sandinya bukan isolasi.
 */
class PenghuniController extends Controller
{
    public function __construct(
        private readonly PembuatAkun $pembuat,
        private readonly TautanAkun $tautan,
    ) {}

    public function index(): JsonResponse
    {
        $daftar = Penghuni::with(['kontrakAktif.kamar', 'user'])->orderBy('nama')->get();

        return response()->json(['data' => PenghuniResource::collection($daftar)]);
    }

    public function store(SimpanPenghuniRequest $request): JsonResponse
    {
        // Penghuni dan akun login-nya dibuat bersama: penghuni tanpa akun tidak
        // bisa melihat tagihannya sendiri (R-07), jadi keduanya satu kesatuan.
        [$penghuni, $user] = DB::transaction(function () use ($request) {
            $penghuni = Penghuni::create($request->validated());
            $user = $this->pembuat->untukPenghuni($penghuni);

            return [$penghuni, $user];
        });

        // Email dikirim SETELAH transaksi selesai, dan kegagalannya tidak
        // membatalkan pembuatan akun. Kalau dibalik, SMTP yang mati akan
        // menghapus penghuni yang datanya sudah benar - dan pemilik akan
        // mencoba lagi, menghasilkan dua baris penghuni untuk orang yang sama.
        $terkirim = $this->tautan->kirimAman($user, TokenAkun::JENIS_AKTIVASI);

        return response()->json([
            'data' => new PenghuniResource($penghuni->setRelation('user', $user)),
            'meta' => [
                // Layar memakai ini untuk membedakan "tautan sudah dikirim"
                // dari "akun dibuat tetapi emailnya gagal". Tanpa itu, pemilik
                // mengira penghuni sudah menerima email padahal tidak, dan
                // penghuni menunggu tautan yang tidak pernah datang.
                'tautan_aktivasi_terkirim' => $terkirim,
                'email' => $user->email,
            ],
        ], 201);
    }

    public function show(Penghuni $penghuni): JsonResponse
    {
        $penghuni->load(['kontrakAktif.kamar', 'user']);

        return response()->json(['data' => new PenghuniResource($penghuni)]);
    }

    public function update(SimpanPenghuniRequest $request, Penghuni $penghuni): JsonResponse
    {
        $penghuni->update($request->validated());

        // Nama dan email di akun login ikut disesuaikan supaya tidak berbeda
        // dari data induknya.
        //
        // Mengganti email berarti membatalkan aktivasi sebelumnya: tautan lama
        // dikirim ke alamat yang mungkin bukan milik penghuni ini, jadi ia
        // tidak boleh lagi bisa dipakai. Akunnya dikembalikan ke keadaan belum
        // aktif, dan tautan baru dikirim ke alamat yang baru.
        $user = $penghuni->user;
        $emailBerubah = $user && $user->email !== $penghuni->email;

        if ($user) {
            $user->update([
                'name' => $penghuni->nama,
                'email' => $penghuni->email,
            ]);

            if ($emailBerubah) {
                $user->forceFill(['email_verified_at' => null])->save();
                $user->tokens()->delete();
            }
        }

        $terkirim = $emailBerubah
            ? $this->tautan->kirimAman($user, TokenAkun::JENIS_AKTIVASI)
            : null;

        return response()->json([
            'data' => new PenghuniResource($penghuni->setRelation('user', $user)),
            'meta' => [
                'tautan_aktivasi_terkirim' => $terkirim,
                'email' => $penghuni->email,
            ],
        ]);
    }

    /**
     * Kirim ulang tautan aktivasi.
     *
     * Padanan `POST /pengelola/{id}/kirim-ulang-aktivasi`, dan alasannya sama:
     * pemilik salah mengetik alamat email, atau emailnya masuk folder spam lalu
     * terhapus. Tanpa endpoint ini satu-satunya jalan keluar adalah mengubah
     * alamatnya menjadi alamat lain lalu mengubahnya kembali - dan langkah itu
     * memutus sesi serta meninggalkan dua jejak audit untuk satu maksud.
     *
     * Hanya untuk akun yang belum aktif. Akun yang sudah aktif dan lupa
     * sandinya memakai alur "lupa sandi" miliknya sendiri: pemilik yang bisa
     * mengirim ulang tautan aktivasi untuk akun aktif sama saja dengan pemilik
     * yang bisa mengambil alih akun itu.
     */
    public function kirimUlangAktivasi(Penghuni $penghuni): JsonResponse
    {
        $user = $penghuni->user;

        if (! $user) {
            throw ValidationException::withMessages([
                'penghuni' => ['Penghuni ini tidak punya akun masuk.'],
            ])->status(409);
        }

        if (! $user->belumAktif()) {
            throw ValidationException::withMessages([
                'penghuni' => ['Akun ini sudah aktif. Penghuni memakai "lupa kata sandi" di halaman masuk.'],
            ])->status(409);
        }

        $terkirim = $this->tautan->kirimAman($user, TokenAkun::JENIS_AKTIVASI);

        return response()->json([
            'data' => ['pesan' => 'Tautan aktivasi dikirim ulang.'],
            'meta' => [
                'tautan_aktivasi_terkirim' => $terkirim,
                'email' => $user->email,
            ],
        ]);
    }
}
