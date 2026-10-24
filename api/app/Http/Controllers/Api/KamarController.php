<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\PencatatAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanKamarRequest;
use App\Http\Resources\KamarResource;
use App\Models\Kamar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data induk kamar (T2), berjejak sejak REV-DAT-05.
 *
 * Sebelumnya kamar satu-satunya data yang bisa berubah tanpa meninggalkan
 * jejak apa pun. Itu janggal justru karena kamar adalah data yang paling
 * menentukan: nomor kamar muncul di tagihan, dan tarif menempel padanya.
 * Pertanyaan "siapa yang mengganti nomor kamar 3 menjadi 3A" hanya bisa
 * dijawab kalau perubahannya tercatat.
 *
 * **Tidak ada `destroy`.** Kamar yang punya riwayat tarif tidak bisa dihapus
 * (REV-DAT-01 memasang `RESTRICT`), dan kamar yang belum punya riwayat pun
 * lebih baik dinonaktifkan daripada dihapus - nomornya mungkin sudah disebut
 * ke penghuni. Menyediakan tombol hapus yang hanya kadang berhasil lebih
 * membingungkan daripada tidak menyediakannya.
 */
class KamarController extends Controller
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Kamar::query()->with(['kontrakAktif.penghuni', 'tarifTerakhir', 'fasilitas']);

        if ($request->filled('status_huni')) {
            $query->where('status_huni', $request->string('status_huni')->value());
        }

        $daftar = $query->orderBy('nomor')->get();

        return response()->json(['data' => KamarResource::collection($daftar)]);
    }

    public function store(SimpanKamarRequest $request): JsonResponse
    {
        $kamar = DB::transaction(function () use ($request) {
            $kamar = Kamar::create($request->validated());

            // `nilai_baru` memuat seluruh kolom yang tersimpan, bukan hanya
            // yang dikirim klien: yang dibaca enam bulan kemudian adalah
            // keadaan barisnya, dan kolom yang tidak dikirim (mis.
            // `status_huni` bawaannya) juga bagian dari keadaan itu.
            $this->audit->catat(
                aksi: PencatatAudit::AKSI_KAMAR_TAMBAH,
                entitas: 'kamar',
                entitasId: $kamar->id,
                nilaiBaru: $kamar->only(['nomor', 'tipe', 'status_huni', 'catatan']),
            );

            return $kamar;
        });

        return response()->json(['data' => new KamarResource($kamar)], 201);
    }

    public function show(Kamar $kamar): JsonResponse
    {
        $kamar->load(['kontrakAktif.penghuni', 'tarif', 'tarifTerakhir', 'fasilitas']);

        return response()->json(['data' => new KamarResource($kamar)]);
    }

    public function update(SimpanKamarRequest $request, Kamar $kamar): JsonResponse
    {
        DB::transaction(function () use ($request, $kamar) {
            // Disalin SEBELUM update, dan hanya kolom yang benar-benar
            // berubah yang dikirim: `nilai_lama` yang berisi seluruh baris
            // membuat setiap perubahan terlihat besar, dan pembaca berhenti
            // membedakan mana yang sungguh berubah.
            $sebelum = $kamar->only(['nomor', 'tipe', 'status_huni', 'catatan']);

            $kamar->update($request->validated());
            $sesudah = $kamar->only(['nomor', 'tipe', 'status_huni', 'catatan']);

            $berubah = array_keys(array_diff_assoc(
                array_map('strval', $sesudah),
                array_map('strval', $sebelum),
            ));

            // Tidak ada yang berubah berarti tidak ada yang perlu dicatat.
            // Baris "kamar diubah" tanpa perubahan akan membuat jejak penuh
            // oleh penekanan tombol simpan yang tidak mengubah apa pun.
            if ($berubah === []) {
                return;
            }

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_KAMAR_UBAH,
                entitas: 'kamar',
                entitasId: $kamar->id,
                nilaiLama: array_intersect_key($sebelum, array_flip($berubah)),
                nilaiBaru: array_intersect_key($sesudah, array_flip($berubah)),
            );
        });

        return response()->json(['data' => new KamarResource($kamar)]);
    }
}
