<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\PencatatAudit;
use App\Domain\Berkas\PenyimpanLampiran;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanLaporanKerusakanRequest;
use App\Http\Resources\LaporanKerusakanResource;
use App\Models\Kamar;
use App\Models\LampiranKerusakan;
use App\Models\LaporanKerusakan;
use App\Support\WaktuSistem;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Laporan kerusakan kamar (T14, diperluas K-15).
 *
 * **Tiga keputusan pemilik 31 Oktober 2026 yang membentuk kelas ini:**
 *
 * 1. Kamar berstatus `perbaikan` **tetap ditagih penuh**. Karena itu tidak ada
 *    satu baris pun di sini yang menyentuh `tagihan`, `Kalkulator`, atau
 *    `PenerbitanMassal`. Perbaikan adalah keadaan fisik kamar, bukan alasan
 *    menghentikan sewa - kontraknya masih berjalan.
 * 2. Yang boleh menutup laporan adalah **pemilik dan pengelola**, mengikuti
 *    wewenang `kelola_kamar` yang sudah ada. Tidak ada wewenang baru: orang
 *    yang mengurus kamar memang yang tahu perbaikannya sudah selesai.
 * 3. Biaya perbaikan **tidak pernah** masuk tagihan penghuni. Tidak ada kolom
 *    nominal di tabelnya, jadi tidak ada angka yang bisa bocor ke tagihan
 *    tanpa disengaja.
 *
 * **K-15 (13 November 2026) menambahkan sisi pelapor.** Sebelumnya alurnya
 * setengah: pencatatan dan penyelesaian ada, tetapi penghuni yang menemukan
 * kerusakan tidak punya jalan melapor - ia mengirim WhatsApp, dan fotonya hilang
 * ditelan percakapan. Sekarang penghuni melapor dari portal, `kamar_id` diambil
 * server dari kontrak aktifnya (bukan dari request), dan laporannya bisa
 * membawa sampai tiga foto.
 *
 * **Tidak ada `destroy`.** Laporan yang dihapus akan membuat kamar berstatus
 * `perbaikan` tanpa penjelasan apa pun - dan yang menemukannya enam bulan
 * kemudian hanya melihat kamar yang tidak bisa disewa tanpa tahu kenapa.
 */
class LaporanKerusakanController extends Controller
{
    public function __construct(
        private readonly PencatatAudit $audit,
        private readonly PenyimpanLampiran $lampiran,
        private readonly WaktuSistem $waktu,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = LaporanKerusakan::query()->with(['kamar', 'pelapor', 'penutup', 'lampiran']);

        if ($request->filled('status')) {
            $request->string('status')->value() === 'terbuka'
                ? $query->whereNull('selesai_pada')
                : $query->whereNotNull('selesai_pada');
        }

        // Yang masih terbuka lebih dulu: daftar ini dibuka untuk mencari
        // pekerjaan yang belum selesai, bukan untuk membaca sejarah.
        $daftar = $query
            ->orderByRaw('selesai_pada IS NOT NULL')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => LaporanKerusakanResource::collection($daftar)]);
    }

    public function store(SimpanLaporanKerusakanRequest $request): JsonResponse
    {
        $kamar = Kamar::findOrFail($request->integer('kamar_id'));

        return $this->buat(
            $kamar,
            $request->string('deskripsi')->value(),
            $request->file('foto', []),
            $request->user()->getAuthIdentifier(),
        );
    }

    /**
     * Laporan milik penghuni yang sedang login (K-15).
     *
     * Disaring `dilaporkan_oleh`, bukan `kamar_id`. Dua alasan: penghuni yang
     * sudah pindah kamar tetap bisa membaca laporan yang ia buat dulu, dan
     * penghuni baru tidak mewarisi foto kamar dari penghuni sebelumnya - foto
     * kamar adalah data pribadi orang yang memotretnya.
     */
    public function indexPenghuni(Request $request): JsonResponse
    {
        $daftar = LaporanKerusakan::query()
            ->where('dilaporkan_oleh', $request->user()->getAuthIdentifier())
            ->with(['kamar', 'penutup', 'lampiran'])
            ->orderByRaw('selesai_pada IS NOT NULL')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => LaporanKerusakanResource::collection($daftar)]);
    }

    /**
     * Penghuni melaporkan kerusakan kamarnya sendiri (K-15).
     *
     * `kamar_id` **tidak** dibaca dari request. Kalau dibaca, penghuni bisa
     * menandai kamar orang lain sebagai `perbaikan` - dan itu mengubah data
     * yang dilihat pemilik, bukan sekadar mengirim laporan.
     */
    public function storePenghuni(Request $request): JsonResponse
    {
        $data = $request->validate(
            SimpanLaporanKerusakanRequest::aturanIsi(),
            SimpanLaporanKerusakanRequest::pesanIsi(),
        );

        $kontrak = $request->user()->penghuni?->kontrakAktif()->first();

        if ($kontrak === null) {
            return response()->json([
                'message' => 'Akun Anda belum terhubung ke kontrak sewa yang aktif, '
                    .'jadi belum ada kamar yang bisa dilaporkan. Hubungi pemilik kos.',
                'errors' => ['kamar_id' => ['Tidak ada kontrak aktif untuk akun ini.']],
            ], 422);
        }

        return $this->buat(
            $kontrak->kamar,
            $data['deskripsi'],
            $request->file('foto', []),
            $request->user()->getAuthIdentifier(),
        );
    }

    public function selesai(Request $request, LaporanKerusakan $laporan): JsonResponse
    {
        if (! $laporan->masihTerbuka()) {
            return response()->json([
                'message' => 'Laporan ini sudah ditutup.',
                'errors' => ['selesai_pada' => ['Laporan ini sudah ditutup.']],
            ], 422);
        }

        DB::transaction(function () use ($request, $laporan) {
            // Kunci baris kamar lebih dulu (temuan #4): status setelah
            // perbaikan dibaca dari kontrak-kontraknya, dan penulis status
            // kamar lain (`akhiri`, kontrak baru) mengunci baris yang sama.
            // Membaca tanpa kunci berarti status bisa ditulis dari bacaan
            // yang kedaluwarsa.
            $kamar = Kamar::query()->whereKey($laporan->kamar_id)->lockForUpdate()->firstOrFail();

            $laporan->update([
                'selesai_pada' => $this->waktu->sekarang(),
                'ditutup_oleh' => $request->user()->getAuthIdentifier(),
            ]);

            $statusBaru = $kamar->statusSetelahPerbaikan();
            $kamar->update(['status_huni' => $statusBaru]);

            $this->audit->catat(
                aksi: PencatatAudit::AKSI_KERUSAKAN_SELESAI,
                entitas: 'kamar',
                entitasId: $kamar->id,
                nilaiLama: ['status_huni' => 'perbaikan'],
                nilaiBaru: ['status_huni' => $statusBaru, 'laporan_id' => $laporan->id],
            );
        });

        $laporan->load(['kamar', 'pelapor', 'penutup', 'lampiran']);

        return response()->json(['data' => new LaporanKerusakanResource($laporan)]);
    }

    /**
     * Sajikan satu foto lampiran (K-15).
     *
     * **Tanpa `auth:sanctum`, dengan sengaja.** Tag `<img>` tidak bisa mengirim
     * header `Authorization`, dan memaksakannya menghasilkan ikon rusak tanpa
     * pesan. Yang menjaga bukan token melainkan tanda tangan berbatas waktu
     * yang hanya dibuat untuk pelapor dan pengurus kos - lihat
     * `LampiranKerusakan::ringkas()`.
     *
     * `id` diterima sebagai angka, bukan lewat route model binding: binding
     * berjalan sebelum middleware `signed`, sehingga penebak tanpa tanda tangan
     * bisa membedakan "id ada" (403) dari "id tidak ada" (404) dan memetakan
     * berapa banyak foto yang tersimpan.
     */
    public function lampiran(int $id): Response
    {
        $lampiran = LampiranKerusakan::findOrFail($id);
        $isi = $this->lampiran->isi($lampiran);

        if ($isi === null) {
            abort(404, 'Lampiran ini tidak punya isi.');
        }

        $akhiran = LampiranKerusakan::MIME[$lampiran->mime] ?? 'bin';

        return response($isi, 200, [
            'Content-Type' => $lampiran->mime,
            'Content-Disposition' => 'inline; filename="foto-'.$lampiran->id.'.'.$akhiran.'"',
            // Sama seperti gambar QRIS: boleh disimpan peramban sebentar supaya
            // panel tidak mengunduh ulang setiap kali dibuka, tetapi tidak boleh
            // masuk cache bersama.
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Satu jalur pembuatan laporan untuk dua pelapor.
     *
     * Yang berbeda hanya dari mana `kamar` dan `pelapor` datang; sisanya -
     * penolakan laporan ganda, penandaan kamar, jejak audit, dan penyimpanan
     * foto - harus sama persis. Menyalinnya berarti dua tempat yang bisa
     * menyimpang soal apakah kamar ditandai `perbaikan`.
     *
     * @param  array<int, UploadedFile>  $foto
     */
    private function buat(Kamar $kamar, string $deskripsi, array $foto, ?int $pelaporId): JsonResponse
    {
        // Diperiksa lebih dulu supaya pesannya jelas. Yang benar-benar menjaga
        // adalah indeks unik parsial di basis data - dua permintaan bersamaan
        // bisa sama-sama lolos pemeriksaan ini, dan yang kedua ditangkap di
        // bawah sebagai 23505.
        if ($kamar->laporanTerbuka()->exists()) {
            return $this->tolakLaporanGanda($kamar);
        }

        try {
            $laporan = DB::transaction(function () use ($kamar, $deskripsi, $foto, $pelaporId) {
                $laporan = LaporanKerusakan::create([
                    'kamar_id' => $kamar->id,
                    'deskripsi' => $deskripsi,
                    'dilaporkan_oleh' => $pelaporId,
                ]);

                // Di dalam transaksi: laporan yang gagal menyimpan fotonya tidak
                // boleh tertinggal sebagai laporan tanpa bukti.
                foreach ($foto as $berkas) {
                    $this->lampiran->simpan($laporan, $berkas);
                }

                $sebelum = $kamar->status_huni;
                $kamar->update(['status_huni' => 'perbaikan']);

                // Jejaknya menempel pada KAMAR, bukan pada laporan: yang
                // berubah dan yang dibaca orang lain adalah status kamarnya.
                // `laporan_id` disertakan supaya pembaca bisa menelusuri
                // alasannya tanpa menebak.
                $this->audit->catat(
                    aksi: PencatatAudit::AKSI_KERUSAKAN_LAPOR,
                    entitas: 'kamar',
                    entitasId: $kamar->id,
                    nilaiLama: ['status_huni' => $sebelum],
                    nilaiBaru: [
                        'status_huni' => 'perbaikan',
                        'laporan_id' => $laporan->id,
                        'deskripsi' => $laporan->deskripsi,
                        'jumlah_foto' => count($foto),
                    ],
                );

                return $laporan;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return $this->tolakLaporanGanda($kamar);
            }

            throw $e;
        } catch (RuntimeException $e) {
            // Lapis kedua `PenyimpanLampiran`. Validasi HTTP sudah menolak
            // berkas yang bukan gambar, jadi jalur ini hanya tercapai bila
            // pemeriksaan itu dilewati - dan 422 lebih jujur daripada 500.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['foto' => [$e->getMessage()]],
            ], 422);
        }

        $laporan->load(['kamar', 'pelapor', 'lampiran']);

        return response()->json(['data' => new LaporanKerusakanResource($laporan)], 201);
    }

    private function tolakLaporanGanda(Kamar $kamar): JsonResponse
    {
        return response()->json([
            'message' => sprintf(
                'Kamar %s sudah punya laporan kerusakan yang belum selesai. '
                .'Tandai laporan itu selesai lebih dulu.',
                $kamar->nomor,
            ),
            'errors' => ['kamar_id' => ['Kamar ini masih punya laporan kerusakan terbuka.']],
        ], 422);
    }
}
