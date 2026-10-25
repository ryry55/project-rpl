<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\PencatatAudit;
use App\Domain\Rekening\GambarQrisDitolak;
use App\Domain\Rekening\PenyimpanGambarQris;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanRekeningRequest;
use App\Http\Resources\RekeningResource;
use App\Models\Rekening;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
    public function __construct(
        private readonly PencatatAudit $audit,
        private readonly PenyimpanGambarQris $gambar,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Kolom `gambar` sengaja TIDAK diambil: daftar rekening hanya perlu tahu
        // apakah gambarnya ada. Menarik `bytea` untuk setiap baris berarti
        // mengirim ratusan kilobyte yang langsung dibuang layar.
        $query = Rekening::query()->select([
            'id', 'jenis', 'nama', 'nomor', 'atas_nama', 'catatan',
            'gambar_mime', 'gambar_ukuran', 'aktif', 'urutan', 'created_at', 'updated_at',
        ]);

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
                'batas_gambar' => Rekening::BATAS_GAMBAR,
                'mime_gambar' => array_keys(Rekening::MIME_GAMBAR),
            ],
        ]);
    }

    public function store(SimpanRekeningRequest $request): JsonResponse
    {
        try {
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

                $this->simpanGambarBilaAda($request, $baris);

                $this->audit->catat(
                    aksi: PencatatAudit::AKSI_REKENING_TAMBAH,
                    entitas: 'rekening',
                    entitasId: $baris->id,
                    nilaiBaru: $this->salinan($baris),
                );

                return $baris;
            });
        } catch (GambarQrisDitolak $e) {
            // Transaksi sudah digulung balik: tidak ada rekening setengah jadi
            // yang tertinggal tanpa gambarnya.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['gambar' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json(['data' => new RekeningResource($rekening)], 201);
    }

    public function update(SimpanRekeningRequest $request, Rekening $rekening): JsonResponse
    {
        $lama = $this->salinan($rekening);

        try {
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

                $this->simpanGambarBilaAda($request, $rekening);

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
        } catch (GambarQrisDitolak $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['gambar' => [$e->getMessage()]],
            ], 422);
        }

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
     * Sajikan gambar QRIS satu rekening (utang teknis yang ditutup).
     *
     * **Kenapa endpoint, bukan URL statis.** `public/` dipasang read-only ke
     * nginx, dan Render free tidak punya disk persisten - berkas yang ditulis
     * hari ini hilang saat restart. Gambar karena itu tinggal di kolom `bytea`,
     * dan hanya bisa keluar lewat sini.
     *
     * **Kenapa terpisah dari `/rekening`.** Satu rekening dengan gambar 300 kB
     * akan membuat daftar lima rekening menjadi 1,5 MB respons, padahal layar
     * daftar hanya butuh nama dan nomor. Yang diambil di sini hanya satu gambar,
     * saat benar-benar ditampilkan.
     *
     * **Kenapa penghuni hanya melihat yang aktif.** Sama persis dengan alasan
     * `/penghuni/rekening` menyaring `aktif`: penghuni yang memindai QRIS lama
     * yang sudah tidak dipakai kehilangan uangnya, dan tidak ada kode yang bisa
     * mengembalikannya. Menyaring daftarnya tetapi tidak gambarnya akan membuat
     * gambar itu tetap bisa dibuka lewat URL yang pernah terlihat.
     */
    public function gambar(Request $request, Rekening $rekening): Response
    {
        $pengguna = $request->user();

        if ($pengguna?->peran === User::PERAN_PENGHUNI && ! $rekening->aktif) {
            // 404, bukan 403: penghuni tidak berhak tahu bahwa rekening lama
            // itu masih ada. 403 akan menjawab "ada, tapi bukan untukmu".
            abort(404);
        }

        $isi = $this->gambar->isi($rekening);

        if ($isi === null) {
            abort(404, 'Rekening ini tidak punya gambar.');
        }

        $mime = $rekening->gambar_mime ?: 'application/octet-stream';

        return response($isi, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($isi),
            // `private`: gambar tujuan bayar sebuah kos tidak perlu tersimpan di
            // cache bersama (proxy), dan `no-transform` mencegah proxy besar
            // mengubahnya. `max-age` sedang karena pemilik bisa menggantinya
            // kapan saja, tetapi tidak setiap permintaan.
            'Cache-Control' => 'private, max-age=300, no-transform',
            // Tanpa ini, peramban lama menebak tipe dari isi dan bisa
            // memperlakukannya sebagai HTML - satu jalan XSS lewat unggahan.
            'X-Content-Type-Options' => 'nosniff',
            // SVG sudah ditolak saat unggah, tetapi header ini menjaga kalau
            // daftar tipe suatu saat dilonggarkan.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; sandbox",
            'Content-Disposition' => 'inline; filename="qris-'.$rekening->id.'.'
                .(Rekening::MIME_GAMBAR[$mime] ?? 'bin').'"',
        ]);
    }

    /**
     * Simpan gambar kiriman, bila ada - dan buang bila jenisnya bukan QRIS.
     *
     * Dipanggil di dalam transaksi controller, supaya rekening yang gagal
     * menyimpan gambarnya tidak tertinggal setengah jadi.
     */
    private function simpanGambarBilaAda(SimpanRekeningRequest $request, Rekening $rekening): void
    {
        // `hapus_gambar` dipakai tombol "Buang gambar" di layar. Tanpa ini,
        // mengganti gambar hanya bisa dengan mengunggah yang baru, dan gambar
        // yang salah tidak pernah bisa dihilangkan tanpa menghapus rekeningnya.
        if ($request->boolean('hapus_gambar')) {
            $rekening->hapusGambar();

            return;
        }

        if (! $request->hasFile('gambar')) {
            return;
        }

        // Gambar pada rekening transfer akan disajikan portal sebagai "cara
        // membayar" yang tidak berlaku. Lebih jujur menolaknya di sini daripada
        // menyimpannya dan berharap layar mengabaikannya.
        if ($rekening->jenis !== Rekening::JENIS_QRIS) {
            throw GambarQrisDitolak::hanyaUntukQris();
        }

        $hasil = $this->gambar->periksa($request->file('gambar'));
        $this->gambar->simpan($rekening, $hasil['isi'], $hasil['mime']);
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
            // Isi gambarnya tidak ikut disalin ke log audit: log dibaca manusia
            // dan disimpan lama, sedangkan bytea ratusan kilobyte di dalamnya
            // hanya membuat tabel jejak membengkak tanpa menjawab pertanyaan
            // siapa pun. Yang perlu terjawab adalah "apakah gambarnya ada".
            'punya_gambar' => $rekening->punyaGambar(),
            'gambar_mime' => $rekening->gambar_mime,
            'gambar_ukuran' => $rekening->gambar_ukuran,
            'aktif' => (bool) $rekening->aktif,
            'urutan' => (int) $rekening->urutan,
        ];
    }
}
