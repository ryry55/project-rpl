<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\PencatatAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanRekeningRequest;
use App\Http\Resources\RekeningResource;
use App\Models\Rekening;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Rekening dan QRIS tujuan pembayaran (REV-UI-05).
 *
 * Sebelum ini portal penghuni hanya menyebut angka yang harus dibayar tanpa
 * menyebut ke mana uangnya dikirim. Penghuni lalu bertanya lewat WhatsApp, dan
 * pemilik mengetik ulang nomornya untuk tiap orang - pekerjaan yang berulang,
 * rawan salah ketik, dan tidak meninggalkan bukti nomor mana yang berlaku kapan.
 *
 * **Tidak append-only**, berbeda dari tarif dan kebijakan. Tidak ada angka
 * tagihan yang bergantung pada baris ini: mengganti nomor rekening hari ini
 * tidak mengubah tagihan bulan lalu, karena tagihan tidak pernah membacanya.
 * Yang tetap dijaga adalah jejaknya - setiap tambah, ubah, dan hapus menulis
 * `log_audit`, dan itulah tempat yang benar untuk pertanyaan "dulu saya
 * transfer ke mana".
 */
class RekeningController extends Controller
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Rekening::query();

        // Portal penghuni memakai `?aktif=1`; layar pemilik melihat semuanya
        // supaya rekening lama masih bisa diaktifkan lagi tanpa dibuat ulang.
        if ($request->filled('aktif')) {
            $query->where('aktif', $request->boolean('aktif'));
        }

        $daftar = $query->orderBy('urutan')->orderBy('id')->get();

        return response()->json([
            'data' => RekeningResource::collection($daftar),
            'meta' => [
                'jenis' => Rekening::SEMUA_JENIS,
                'label_jenis' => Rekening::LABEL_JENIS,
            ],
        ]);
    }

    public function store(SimpanRekeningRequest $request): JsonResponse
    {
        $rekening = DB::transaction(function () use ($request) {
            $baris = Rekening::create([
                'jenis' => $request->string('jenis')->value() ?: Rekening::JENIS_TRANSFER,
                'nama' => $request->string('nama')->value(),
                'nomor' => $request->filled('nomor') ? $request->string('nomor')->value() : null,
                'atas_nama' => $request->filled('atas_nama') ? $request->string('atas_nama')->value() : null,
                'catatan' => $request->filled('catatan') ? $request->string('catatan')->value() : null,
                'aktif' => $request->has('aktif') ? $request->boolean('aktif') : true,
                'urutan' => $request->integer('urutan'),
            ]);

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_REKENING_TAMBAH,
                entitas: 'rekening',
                entitasId: $baris->id,
                nilaiBaru: $this->salinan($baris),
            );

            return $baris;
        });

        return response()->json(['data' => new RekeningResource($rekening)], 201);
    }

    public function update(SimpanRekeningRequest $request, Rekening $rekening): JsonResponse
    {
        $lama = $this->salinan($rekening);

        DB::transaction(function () use ($request, $rekening, $lama) {
            $rekening->update($request->only([
                'jenis',
                'nama',
                'nomor',
                'atas_nama',
                'catatan',
                'aktif',
                'urutan',
            ]));

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_REKENING_UBAH,
                entitas: 'rekening',
                entitasId: $rekening->id,
                // Nilai lama dikirim LENGKAP, bukan hanya id: barisnya bisa
                // saja sudah dihapus saat pertanyaan "dulu nomornya berapa"
                // datang, dan id yang tidak menunjuk apa pun tidak menjawab.
                nilaiLama: $lama,
                nilaiBaru: $this->salinan($rekening->fresh()),
            );
        });

        return response()->json(['data' => new RekeningResource($rekening->fresh())]);
    }

    public function destroy(Rekening $rekening): JsonResponse
    {
        $lama = $this->salinan($rekening);

        DB::transaction(function () use ($rekening, $lama) {
            $rekening->delete();

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_REKENING_HAPUS,
                entitas: 'rekening',
                entitasId: $lama['id'],
                nilaiLama: $lama,
            );
        });

        return response()->json(['data' => ['id' => $lama['id']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function salinan(Rekening $rekening): array
    {
        return [
            'id' => $rekening->id,
            'jenis' => $rekening->jenis,
            'nama' => $rekening->nama,
            'nomor' => $rekening->nomor,
            'atas_nama' => $rekening->atas_nama,
            'catatan' => $rekening->catatan,
            'aktif' => (bool) $rekening->aktif,
            'urutan' => (int) $rekening->urutan,
        ];
    }
}
