<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\PencatatAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanFasilitasRequest;
use App\Http\Resources\FasilitasResource;
use App\Models\Fasilitas;
use App\Models\Kamar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fasilitas kos (T18), berjejak sejak REV-DAT-05.
 *
 * Fasilitas menjawab "kamar ini PUNYA apa". Tidak ada satu pun endpoint di sini
 * yang menyentuh tagihan: mencentang AC tidak menagih apa pun. Yang menagih
 * adalah `komponen_biaya`, dan itu tetap dikelola di tempatnya sendiri.
 *
 * Fasilitas boleh diubah dan dihapus, berbeda dari `tarif_sewa` dan
 * `komponen_biaya` yang append-only. Alasannya: tidak ada angka uang yang
 * bergantung pada baris fasilitas, jadi tidak ada riwayat yang perlu dilindungi
 * di basis data. Yang tetap dijaga adalah **jejak siapa mengubahnya**: katalog
 * fasilitas dipakai di portal penghuni, dan penghuni yang menanyakan "sejak
 * kapan kamar saya tidak lagi punya AC" berhak mendapat jawaban.
 */
class FasilitasController extends Controller
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function index(): JsonResponse
    {
        $daftar = Fasilitas::query()
            ->withCount('kamar')
            ->orderBy('urutan')
            ->orderBy('nama')
            ->get();

        return response()->json(['data' => FasilitasResource::collection($daftar)]);
    }

    public function store(SimpanFasilitasRequest $request): JsonResponse
    {
        $fasilitas = DB::transaction(function () use ($request) {
            $fasilitas = Fasilitas::create($request->validated());

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_FASILITAS_TAMBAH,
                entitas: 'fasilitas',
                entitasId: $fasilitas->id,
                nilaiBaru: $fasilitas->only(['nama', 'ikon', 'urutan']),
            );

            return $fasilitas;
        });

        return response()->json(['data' => new FasilitasResource($fasilitas)], 201);
    }

    public function update(SimpanFasilitasRequest $request, Fasilitas $fasilitas): JsonResponse
    {
        DB::transaction(function () use ($request, $fasilitas) {
            $sebelum = $fasilitas->only(['nama', 'ikon', 'urutan']);

            $fasilitas->update($request->validated());

            $sesudah = $fasilitas->only(['nama', 'ikon', 'urutan']);
            $berubah = array_keys(array_diff_assoc(
                array_map('strval', $sesudah),
                array_map('strval', $sebelum),
            ));

            if ($berubah === []) {
                return;
            }

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_FASILITAS_UBAH,
                entitas: 'fasilitas',
                entitasId: $fasilitas->id,
                nilaiLama: array_intersect_key($sebelum, array_flip($berubah)),
                nilaiBaru: array_intersect_key($sesudah, array_flip($berubah)),
            );
        });

        return response()->json(['data' => new FasilitasResource($fasilitas)]);
    }

    public function destroy(Fasilitas $fasilitas): JsonResponse
    {
        DB::transaction(function () use ($fasilitas) {
            // Disalin sebelum barisnya hilang: sesudah `delete()`, `nama`
            // masih terbaca dari objek di memori, tetapi membaca dari objek
            // yang sudah dihapus adalah kebiasaan yang cepat menjadi bug saat
            // barisnya diambil ulang di kemudian hari.
            $nilai = $fasilitas->only(['nama', 'ikon', 'urutan']);
            $id = $fasilitas->id;

            // Pivot memakai `cascadeOnDelete`, jadi menghapus fasilitas otomatis
            // melepasnya dari semua kamar. Itu memang yang diinginkan: fasilitas
            // yang sudah tidak ada tidak boleh tetap tercatat di kamar mana pun.
            $fasilitas->delete();

            // Jejaknya menyebut nama, bukan hanya id: id baris yang sudah
            // dihapus tidak bisa dicari lagi, dan "fasilitas 7 dihapus" tidak
            // menjawab pertanyaan apa pun.
            $this->audit->catat(
                aksi: PencatatAudit::AKSI_FASILITAS_HAPUS,
                entitas: 'fasilitas',
                entitasId: $id,
                nilaiLama: $nilai,
            );
        });

        return response()->json(['data' => ['id' => $fasilitas->id]]);
    }

    /**
     * Mengganti seluruh fasilitas satu kamar.
     *
     * Memakai PUT dan bukan POST per fasilitas: layar menampilkan daftar
     * centang, dan yang dikirim adalah keadaan akhir daftar itu. Dengan POST
     * per item, menambah dan melepas menjadi dua operasi berbeda yang bisa
     * gagal separuh jalan, dan kamar berakhir dengan daftar yang tidak pernah
     * dipilih siapa pun.
     *
     * Perubahannya berjejak sejak REV-DAT-05. `sync` mengembalikan tiga daftar
     * (`attached`, `detached`, `updated`) dan ketiganya dipakai: jejak yang
     * hanya menyebut daftar akhir tidak bisa menjawab "AC kamar ini dilepas
     * kapan", karena daftar akhir tidak memuat apa yang hilang.
     */
    public function pasangUntukKamar(Request $request, Kamar $kamar): JsonResponse
    {
        $data = $request->validate([
            'fasilitas' => ['present', 'array'],
            'fasilitas.*' => ['integer', 'distinct', 'exists:fasilitas,id'],
        ], [
            'fasilitas.present' => 'Daftar fasilitas wajib dikirim (boleh kosong).',
            'fasilitas.*.exists' => 'Ada fasilitas yang tidak dikenal.',
            'fasilitas.*.distinct' => 'Ada fasilitas yang dikirim dua kali.',
        ]);

        DB::transaction(function () use ($kamar, $data) {
            // `sync` menghitung sendiri selisihnya: yang sudah terpasang
            // dibiarkan, yang baru ditambahkan, yang tidak ada di daftar
            // dilepas. Menulis `detach` lalu `attach` akan menghapus dan
            // membuat ulang baris yang sama, sehingga `created_at` pivot
            // berubah tanpa ada yang berubah.
            $perubahan = $kamar->fasilitas()->sync($data['fasilitas']);

            // Tidak ada yang berubah berarti tidak ada yang perlu dicatat.
            if ($perubahan['attached'] === [] && $perubahan['detached'] === []) {
                return;
            }

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_FASILITAS_UBAH,
                entitas: 'kamar',
                entitasId: $kamar->id,
                nilaiLama: ['fasilitas_dilepas' => $this->namaFasilitas($perubahan['detached'])],
                nilaiBaru: ['fasilitas_dipasang' => $this->namaFasilitas($perubahan['attached'])],
            );
        });

        $kamar->load('fasilitas');

        return response()->json([
            'data' => [
                'kamar_id' => $kamar->id,
                'fasilitas' => FasilitasResource::collection($kamar->fasilitas),
            ],
        ]);
    }

    /**
     * Nama fasilitas dari daftar id.
     *
     * Nama, bukan id: pembaca jejak audit enam bulan kemudian tidak akan
     * mencari id 7 di tabel fasilitas - dan barisnya mungkin sudah dihapus.
     *
     * @param  list<int>  $id
     * @return list<string>
     */
    private function namaFasilitas(array $id): array
    {
        if ($id === []) {
            return [];
        }

        return Fasilitas::query()
            ->whereIn('id', $id)
            ->orderBy('nama')
            ->pluck('nama')
            ->all();
    }
}
