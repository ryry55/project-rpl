<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\PencatatAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanPerangkatRequest;
use App\Http\Resources\PerangkatResource;
use App\Models\Perangkat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kredensial perangkat kos (K-16): WiFi, CCTV, RFID.
 *
 * **Wewenangnya `kelola_kamar`, bukan wewenang baru.** K-16 menyerahkan bentuk
 * wewenangnya ke desain fase ini. Wewenang pengelola berjumlah delapan dan
 * daftarnya terkunci di SPEC §5; menambah yang kesembilan berarti mengubah
 * keputusan yang sudah disetujui pemilik. Perangkat kos adalah bagian dari
 * mengurus kamar - sama seperti aturan kos, yang juga memakai `kelola_kamar`.
 *
 * **Sandi tidak pernah ikut di daftar.** `GET /perangkat` mengirim
 * `ada_sandi`, bukan sandinya. Yang mengirim sandi hanya
 * `GET /perangkat/{id}/sandi`, dan hanya untuk pemilik/pengelola. Penghuni
 * punya jalurnya sendiri (`GET /penghuni/perangkat`) yang hanya memuat WiFi.
 *
 * **Penghuni tanpa kontrak aktif tidak melihat apa pun.** K-16 menyebut "kos
 * yang ia tinggali"; akun yang kontraknya sudah berakhir bukan lagi penghuni
 * kos ini, dan sandi WiFi yang masih berlaku bukan untuknya.
 */
class PerangkatController extends Controller
{
    public function __construct(private readonly PencatatAudit $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = Perangkat::query();

        // Layar pemilik melihat semuanya supaya perangkat lama masih bisa
        // diaktifkan lagi tanpa dibuat ulang.
        if ($request->filled('aktif')) {
            $query->where('aktif', $request->boolean('aktif'));
        }

        $daftar = $query->orderBy('urutan')->orderBy('id')->get();

        return response()->json([
            'data' => PerangkatResource::collection($daftar),
            'meta' => [
                'jenis' => Perangkat::SEMUA_JENIS,
                'label_jenis' => Perangkat::LABEL_JENIS,
            ],
        ]);
    }

    public function store(SimpanPerangkatRequest $request): JsonResponse
    {
        $perangkat = DB::transaction(function () use ($request) {
            $baris = Perangkat::create([
                'jenis' => $request->string('jenis')->value() ?: Perangkat::JENIS_WIFI,
                'nama' => $request->string('nama')->value(),
                'lokasi' => $request->filled('lokasi') ? $request->string('lokasi')->value() : null,
                'nama_pengguna' => $request->filled('nama_pengguna')
                    ? $request->string('nama_pengguna')->value()
                    : null,
                'sandi' => $request->filled('sandi') ? $request->string('sandi')->value() : null,
                'catatan' => $request->filled('catatan') ? $request->string('catatan')->value() : null,
                'aktif' => $request->has('aktif') ? $request->boolean('aktif') : true,
                'urutan' => $request->integer('urutan'),
            ]);

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_PERANGKAT_TAMBAH,
                entitas: 'perangkat',
                entitasId: $baris->id,
                nilaiBaru: $this->salinan($baris),
            );

            return $baris;
        });

        return response()->json(['data' => new PerangkatResource($perangkat)], 201);
    }

    public function update(SimpanPerangkatRequest $request, Perangkat $perangkat): JsonResponse
    {
        $sebelum = $this->salinan($perangkat);

        DB::transaction(function () use ($request, $perangkat, $sebelum) {
            $data = $request->validated();

            // Sandi kosong berarti "jangan ganti", bukan "hapus". Tanpa ini,
            // memperbaiki letak perangkat akan menghapus sandinya - dan
            // penghuni yang meminta sandi WiFi sesudahnya tidak mendapat apa
            // pun, tanpa satu pun pesan yang menjelaskan sebabnya.
            $sandiDiganti = isset($data['sandi']) && $data['sandi'] !== null && $data['sandi'] !== '';
            if (! $sandiDiganti) {
                unset($data['sandi']);
            }

            $perangkat->update($data);

            $sesudah = $this->salinan($perangkat->fresh());

            // Perubahan sandi tidak boleh ditulis ke log audit - log dibaca
            // manusia dan disimpan lama, dan sandi di dalamnya membatalkan
            // seluruh gunanya enkripsi. Yang dicatat hanya bahwa sandinya
            // berganti.
            if ($sandiDiganti) {
                $sesudah['sandi_diganti'] = true;
            }

            $berubah = array_keys(array_diff_assoc(
                array_map('strval', $sesudah),
                array_map('strval', $sebelum),
            ));

            if ($berubah === []) {
                return;
            }

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_PERANGKAT_UBAH,
                entitas: 'perangkat',
                entitasId: $perangkat->id,
                nilaiLama: array_intersect_key($sebelum, array_flip($berubah)),
                nilaiBaru: array_intersect_key($sesudah, array_flip($berubah)),
            );
        });

        return response()->json(['data' => new PerangkatResource($perangkat->fresh())]);
    }

    public function destroy(Perangkat $perangkat): JsonResponse
    {
        $lama = $this->salinan($perangkat);

        DB::transaction(function () use ($perangkat, $lama) {
            $perangkat->delete();

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_PERANGKAT_HAPUS,
                entitas: 'perangkat',
                entitasId: $lama['id'],
                nilaiLama: $lama,
            );
        });

        return response()->json(['data' => ['id' => $lama['id']]]);
    }

    /**
     * Sandi satu perangkat, saat benar-benar diminta (K-16).
     *
     * Terpisah dari daftar supaya sandi tidak melintas di setiap pembukaan
     * halaman. Hanya pemilik dan pengelola: rute ini tidak didaftarkan untuk
     * penghuni, dan penghuni memakai `/penghuni/perangkat` yang hanya memuat
     * WiFi.
     */
    public function sandi(Perangkat $perangkat): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $perangkat->id,
                'sandi' => $perangkat->sandi,
            ],
        ]);
    }

    /**
     * Perangkat yang boleh dilihat penghuni (K-16).
     *
     * Hanya `wifi` dan hanya yang aktif. CCTV dan RFID tidak pernah ikut:
     * nomor kartu yang bocor bisa dipakai orang lain untuk masuk, dan sandi
     * CCTV membuka rekaman yang bukan untuk penghuni.
     *
     * Sandi ikut di sini - itulah gunanya. Yang dijaga bukan bentuk responsnya,
     * melainkan siapa yang boleh meminta: rute ini hanya untuk peran penghuni
     * yang masih punya kontrak aktif.
     */
    public function indexPenghuni(Request $request): JsonResponse
    {
        $kontrak = $request->user()->penghuni?->kontrakAktif()->first();

        if ($kontrak === null) {
            return response()->json(['data' => []]);
        }

        $daftar = Perangkat::query()
            ->whereIn('jenis', Perangkat::JENIS_TERLIHAT_PENGHUNI)
            ->where('aktif', true)
            ->orderBy('urutan')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $daftar->map(fn (Perangkat $p) => [
                'id' => $p->id,
                'nama' => $p->nama,
                'lokasi' => $p->lokasi,
                'nama_pengguna' => $p->nama_pengguna,
                'sandi' => $p->sandi,
                'catatan' => $p->catatan,
            ])->values(),
        ]);
    }

    /**
     * Salinan untuk log audit - tanpa sandi.
     *
     * @return array<string, mixed>
     */
    private function salinan(Perangkat $perangkat): array
    {
        return [
            'id' => $perangkat->id,
            'jenis' => $perangkat->jenis,
            'nama' => $perangkat->nama,
            'lokasi' => $perangkat->lokasi,
            'nama_pengguna' => $perangkat->nama_pengguna,
            'catatan' => $perangkat->catatan,
            'ada_sandi' => $perangkat->sandi !== null && $perangkat->sandi !== '',
            'aktif' => (bool) $perangkat->aktif,
            'urutan' => (int) $perangkat->urutan,
        ];
    }
}
