<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\PencatatAudit;
use App\Domain\Persetujuan\PenerapPerubahan;
use App\Domain\Tarif\PencariTarif;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanKontrakRequest;
use App\Http\Resources\KontrakResource;
use App\Models\Kamar;
use App\Models\Kontrak;
use App\Models\LaporanKerusakan;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class KontrakController extends Controller
{
    public function __construct(
        private readonly PencariTarif $pencari,
        private readonly PenerapPerubahan $penerap,
        private readonly PencatatAudit $audit,
    ) {}

    public function index(): JsonResponse
    {
        $daftar = Kontrak::with(['kamar', 'penghuni'])
            ->orderByDesc('tgl_masuk')
            ->get();

        return response()->json(['data' => KontrakResource::collection($daftar)]);
    }

    public function store(SimpanKontrakRequest $request): JsonResponse
    {
        $tglMasuk = Carbon::parse($request->string('tgl_masuk')->value());
        $kamar = Kamar::findOrFail($request->integer('kamar_id'));

        // Kamar yang sedang diperbaiki tidak boleh disewakan (T14). Layar
        // kontrak memang hanya menawarkan kamar `kosong`, tetapi formulir
        // bukan satu-satunya jalan masuk - dan kamar yang disewakan saat
        // diperbaiki akan kehilangan status perbaikannya tanpa jejak.
        if ($kamar->status_huni === 'perbaikan') {
            return response()->json([
                'message' => sprintf(
                    'Kamar %s sedang diperbaiki. Tandai laporan kerusakannya selesai lebih dulu di layar Kamar.',
                    $kamar->nomor,
                ),
                'errors' => ['kamar_id' => ['Kamar sedang diperbaiki.']],
            ], 422);
        }

        // Tarif kontrak harus berasal dari satu tempat: riwayat `tarif_sewa`.
        // Kalau kamar sudah punya tarif yang berlaku pada tanggal masuk, itulah
        // yang dipakai — angka yang diketik di formulir tidak boleh diam-diam
        // menimpanya, karena itu akan membuat dua sumber kebenaran untuk tarif
        // yang sama. Ketidakcocokan dilaporkan, bukan diabaikan.
        $tarifBerlaku = $this->pencari->sewaUntuk($kamar, $tglMasuk);
        $tarifDiminta = $request->has('tarif') ? $request->integer('tarif') : null;

        if ($tarifBerlaku !== null && $tarifDiminta !== null && (int) $tarifBerlaku->nilai !== $tarifDiminta) {
            return response()->json([
                'message' => sprintf(
                    'Kamar %s sudah punya tarif %s yang berlaku sejak %s. Untuk mengubah tarif, pakai menu Atur tarif pada kamar tersebut.',
                    $kamar->nomor,
                    'Rp '.number_format((int) $tarifBerlaku->nilai, 0, ',', '.'),
                    $tarifBerlaku->berlaku_dari->toDateString(),
                ),
                'errors' => ['tarif' => ['Tarif tidak sama dengan tarif yang sudah berlaku untuk kamar ini.']],
            ], 422);
        }

        if ($tarifBerlaku === null && $tarifDiminta === null) {
            return response()->json([
                'message' => 'Tarif kamar belum ditetapkan. Isi tarif pada formulir, atau atur tarif kamar lebih dulu.',
                'errors' => ['tarif' => ['Tarif wajib diisi karena kamar ini belum punya tarif.']],
            ], 422);
        }

        // REV-SEC-02: menetapkan tarif pertama sebuah kamar adalah penetapan
        // tarif, bukan pelengkap formulir kontrak.
        //
        // Sebelum batas ini, pengelola dengan wewenang `kelola_penghuni` bisa
        // menetapkan tarif kamar apa pun lewat formulir kontrak - melewati
        // seluruh alur usulan T26 tanpa pernah menyentuh layar Atur tarif.
        // Akibatnya `ubah_tarif` yang "hanya boleh mengusulkan" tidak berarti
        // apa-apa: jalur yang tidak dijaga selalu menjadi jalur yang dipakai.
        //
        // Pemilik tetap boleh, karena di sinilah tarif kamar baru wajar
        // ditetapkan - saat penghuni pertama masuk, dalam satu langkah.
        if ($tarifBerlaku === null && $tarifDiminta !== null && ! $request->user()->isPemilik()) {
            return response()->json([
                'message' => sprintf(
                    'Kamar %s belum punya tarif. Penetapan tarif adalah wewenang pemilik: '
                    .'minta pemilik menetapkannya lewat menu Atur tarif, atau ajukan usulan tarif dari menu itu.',
                    $kamar->nomor,
                ),
                'kode' => 'tarif_perlu_pemilik',
                'errors' => ['tarif' => ['Kamar ini belum punya tarif, dan Anda bukan pemilik yang berwenang menetapkannya.']],
            ], 422);
        }

        try {
            $kontrak = DB::transaction(function () use ($request, $tglMasuk, $tarifBerlaku, $tarifDiminta) {
                // Kamar belum punya tarif: baris pertama riwayat dibuat dari
                // angka yang diketik pemilik.
                //
                // Ditulis lewat `PenerapPerubahan`, bukan `TarifSewa::create`,
                // supaya penetapan tarif ini ikut meninggalkan jejak audit
                // seperti penetapan tarif dari menu Atur tarif. Sebelumnya baris
                // tarif lahir di sini tanpa satu pun catatan - dan tarif yang
                // muncul tanpa jejak adalah tarif yang tidak bisa
                // dipertanggungjawabkan saat ditanyakan.
                //
                // `beritahu: false` karena pemberitahuan "tarif diubah" untuk
                // setiap kontrak baru akan menjadi kabar yang selalu sama dan
                // akhirnya berhenti dibaca. Yang perlu diketahui pengurus sudah
                // tampak di daftar kamar dan kontrak.
                if ($tarifBerlaku === null) {
                    $tarifBerlaku = $this->penerap->tarif([
                        'kamar_id' => $request->integer('kamar_id'),
                        'nilai' => $tarifDiminta,
                        'berlaku_dari' => $tglMasuk->toDateString(),
                    ], beritahu: false)->baris;
                }

                $kontrak = Kontrak::create([
                    'kamar_id' => $request->integer('kamar_id'),
                    'penghuni_id' => $request->integer('penghuni_id'),
                    'tgl_masuk' => $tglMasuk->toDateString(),
                    'tarif_awal' => (int) $tarifBerlaku->nilai,
                    // S1.9: kontrak yang mulai di masa depan lahir sebagai
                    // `dipesan`, bukan `aktif`. Bedanya bukan sekadar label:
                    // kontrak `dipesan` tidak ditagih (PenerbitanMassal hanya
                    // menyaring `aktif`) dan tidak membuat penghuni dianggap
                    // menempati kamar. Tanpa ini, mencatat penghuni bulan
                    // depan berarti menerbitkan tagihan untuk bulan yang
                    // belum ia tempati.
                    'status' => $tglMasuk->isAfter(now('Asia/Jakarta')->startOfDay()) ? 'dipesan' : 'aktif',
                ]);

                // Status kamar mengikuti kontraknya, supaya daftar kamar
                // tidak pernah bertentangan dengan daftar kontrak.
                //
                // `dipesan` penting ada di sini: tanpa itu, kamar yang sudah
                // dijanjikan tetap tampil `kosong`, muncul di daftar pilihan
                // formulir kontrak, dan bisa dipesan penghuni kedua.
                Kamar::whereKey($kontrak->kamar_id)->update([
                    'status_huni' => $kontrak->status === 'aktif' ? 'terisi' : 'dipesan',
                ]);

                // REV-DAT-05: kontrak berjejak. Ini baris yang menentukan
                // berapa yang ditagih setiap bulan, jadi "sejak kapan ia
                // menempati kamar ini dan dengan tarif berapa" harus bisa
                // dijawab tanpa membaca tabel yang bisa berubah.
                //
                // `tarif_awal` di-cast ke int karena kolomnya ber-cast
                // `decimal:0` dan Eloquent mengembalikannya sebagai string.
                // Membiarkannya berarti satu baris jejak memuat `"1500000"`
                // sementara baris tarif di sebelahnya memuat `1500000` - dua
                // bentuk untuk angka yang sama, dan pembaca log tidak punya
                // cara tahu mana yang disengaja.
                $this->audit->catat(
                    aksi: PencatatAudit::AKSI_KONTRAK_TAMBAH,
                    entitas: 'kontrak',
                    entitasId: $kontrak->id,
                    nilaiBaru: [
                        'kamar_id' => $kontrak->kamar_id,
                        'penghuni_id' => $kontrak->penghuni_id,
                        'tgl_masuk' => $kontrak->tgl_masuk->toDateString(),
                        'tarif_awal' => (int) $kontrak->tarif_awal,
                        'status' => $kontrak->status,
                    ],
                );

                return $kontrak;
            });
        } catch (QueryException $e) {
            // Partial unique index kontrak_satu_aktif_per_kamar, yang sejak
            // S1.9 juga mencakup kontrak `dipesan`. Pengecekan exists()
            // sebelumnya punya celah balapan, jadi constraint-lah yang jadi
            // pengaman; di sini hanya diterjemahkan ke pesan yang jelas.
            //
            // Pesannya menyebut "sudah dipesan", bukan hanya "sudah punya
            // kontrak aktif": kamar yang dipesan belum berpenghuni, dan
            // pemilik yang membaca "kontrak aktif" akan mencari penghuni yang
            // tidak ada.
            if ($e->getCode() === '23505') {
                return response()->json([
                    'message' => 'Kamar ini sudah punya kontrak aktif atau sudah dipesan. Pilih kamar lain, atau akhiri kontrak yang ada.',
                    'errors' => ['kamar_id' => ['Kamar ini sudah punya kontrak aktif atau sudah dipesan.']],
                ], 422);
            }

            throw $e;
        }

        $kontrak->load(['kamar', 'penghuni']);

        return response()->json(['data' => new KontrakResource($kontrak)], 201);
    }

    public function show(Kontrak $kontrak): JsonResponse
    {
        $kontrak->load(['kamar', 'penghuni']);

        return response()->json(['data' => new KontrakResource($kontrak)]);
    }

    /**
     * Akhiri kontrak. Tidak ada endpoint DELETE: kontrak yang dihapus akan
     * menghilangkan jejak tagihan yang pernah terbit untuknya.
     */
    public function akhiri(Kontrak $kontrak): JsonResponse
    {
        if ($kontrak->status === 'selesai') {
            return response()->json([
                'message' => 'Kontrak ini sudah berstatus selesai.',
                'errors' => ['status' => ['Kontrak ini sudah berstatus selesai.']],
            ], 422);
        }

        DB::transaction(function () use ($kontrak) {
            $sebelum = $kontrak->only(['status', 'tgl_keluar']);

            $kontrak->update([
                'status' => 'selesai',
                'tgl_keluar' => $kontrak->tgl_keluar ?? now()->toDateString(),
            ]);

            // Kamar kembali kosong hanya bila tidak ada kontrak aktif lain.
            $masihAktif = Kontrak::where('kamar_id', $kontrak->kamar_id)
                ->where('status', 'aktif')
                ->exists();

            if (! $masihAktif) {
                // Kamar yang sedang diperbaiki tetap `perbaikan` (T14).
                // Menyetelnya `kosong` akan membuat kamar yang plafonnya bocor
                // muncul di daftar pilihan kontrak, dan bisa disewa sebelum
                // perbaikannya selesai.
                $sedangDiperbaiki = LaporanKerusakan::where('kamar_id', $kontrak->kamar_id)
                    ->whereNull('selesai_pada')
                    ->exists();

                Kamar::whereKey($kontrak->kamar_id)->update([
                    'status_huni' => $sedangDiperbaiki ? 'perbaikan' : 'kosong',
                ]);
            }

            // REV-DAT-05: berakhirnya kontrak adalah kejadian yang mengubah
            // tagihan berikutnya - kamar itu berhenti ditagih mulai bulan
            // depan. Tanggal keluarnya ikut dicatat karena itulah yang dipakai
            // menghitung bulan terakhir yang masih harus dibayar.
            //
            // Tanggalnya ditulis sebagai `Y-m-d`, bukan objek tanggal: jejak
            // audit dibaca manusia dan disimpan sebagai JSON, dan
            // `2026-10-31T00:00:00.000000Z` di sana hanya menambah kebisingan.
            $this->audit->catat(
                aksi: PencatatAudit::AKSI_KONTRAK_AKHIR,
                entitas: 'kontrak',
                entitasId: $kontrak->id,
                nilaiLama: [
                    'status' => $sebelum['status'],
                    'tgl_keluar' => $sebelum['tgl_keluar']?->toDateString(),
                ],
                nilaiBaru: [
                    'status' => $kontrak->status,
                    'tgl_keluar' => $kontrak->tgl_keluar?->toDateString(),
                ],
            );
        });

        return response()->json(['data' => new KontrakResource($kontrak->fresh(['kamar', 'penghuni']))]);
    }
}
