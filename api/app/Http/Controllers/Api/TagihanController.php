<?php

namespace App\Http\Controllers\Api;

use App\Domain\Billing\PemaafDenda;
use App\Domain\Billing\PembatalTagihan;
use App\Domain\Billing\PembayaranDitolak;
use App\Domain\Billing\PencatatPembayaran;
use App\Domain\Billing\PenerbitanMassal;
use App\Domain\Billing\PenerbitTagihan;
use App\Domain\Billing\TagihanTidakDapatDiterbitkan;
use App\Domain\Kebijakan\KebijakanBelumLengkap;
use App\Domain\Kebijakan\PenyelesaiKebijakan;
use App\Domain\Notifikasi\Pemberitahu;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CatatPembayaranRequest;
use App\Http\Requests\Api\TerbitkanMassalRequest;
use App\Http\Requests\Api\TerbitkanTagihanRequest;
use App\Http\Resources\PembayaranResource;
use App\Http\Resources\TagihanResource;
use App\Models\Kontrak;
use App\Models\Tagihan;
use App\Support\WaktuSistem;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TagihanController extends Controller
{
    public function __construct(
        private readonly PenerbitTagihan $penerbit,
        private readonly PenerbitanMassal $penerbitanMassal,
        private readonly PencatatPembayaran $pencatatPembayaran,
        private readonly PenyelesaiKebijakan $penyelesaiKebijakan,
        private readonly PembatalTagihan $pembatal,
        private readonly PemaafDenda $pemaaf,
        private readonly WaktuSistem $waktu,
        private readonly Pemberitahu $pemberitahu,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Tagihan::query()
            // R-07: isolasi diterapkan di query, bukan setelah data diambil.
            ->terlihatOleh($request->user())
            ->with(['rincian', 'kontrak.kamar', 'kontrak.penghuni'])
            // Satu query penjumlahan untuk seluruh halaman, bukan satu per
            // tagihan. Tanpa ini, layar daftar menjalankan N+1 query hanya untuk
            // menghitung "dibayar" dan "sisa".
            ->withSum('pembayaran as total_dibayar', 'jumlah');

        if ($request->filled('kontrak_id')) {
            $query->where('kontrak_id', $request->integer('kontrak_id'));
        }

        if ($request->filled('periode')) {
            $request->validate(['periode' => ['date_format:Y-m-d']]);
            $query->whereDate('periode', Carbon::parse($request->string('periode')->value())->startOfMonth());
        }

        // Diumumkan di badan respons, bukan hanya lewat header: header
        // lintas-origin tidak terbaca JavaScript tanpa exposed_headers,
        // dan bergantung pada itu membuat UI tidak bisa menjelaskan
        // apakah tanggal simulasi benar-benar berlaku.
        $meta = [
            'tanggal_acuan' => $this->waktu->hariIni()->toDateString(),
            'simulasi' => $this->waktu->sedangSimulasi(),
            'demo_mode' => (bool) config('app.demo_mode'),
        ];

        // K-05: berhalaman HANYA bila diminta. Tanpa `per_page`, daftar tetap
        // utuh seperti sebelumnya - layar Tagihan tidak berubah dan ekspor
        // tidak perlu memanggil endpoint lain. Dengan `per_page`, pemanggil
        // berpindah halaman lewat `page` biasa.
        if ($request->filled('per_page')) {
            $request->validate(['per_page' => ['integer', 'min:1', 'max:200']]);

            $halaman = $query->orderByDesc('periode')->orderByDesc('id')->paginate($request->integer('per_page'));

            return response()->json([
                'data' => TagihanResource::collection($halaman->items()),
                'meta' => $meta + [
                    'per_page' => $halaman->perPage(),
                    'halaman' => $halaman->currentPage(),
                    'halaman_terakhir' => $halaman->lastPage(),
                    'total' => $halaman->total(),
                ],
            ]);
        }

        $daftar = $query->orderByDesc('periode')->orderByDesc('id')->get();

        return response()->json([
            'data' => TagihanResource::collection($daftar),
            'meta' => $meta,
        ]);
    }

    public function store(TerbitkanTagihanRequest $request): JsonResponse
    {
        $kontrak = Kontrak::with('kamar')->findOrFail($request->integer('kontrak_id'));
        $periode = Carbon::parse($request->string('periode')->value());

        try {
            $tagihan = $this->penerbit->terbitkan($kontrak, $periode);
        } catch (TagihanTidakDapatDiterbitkan $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['periode' => [$e->getMessage()]],
            ], 422);
        } catch (QueryException $e) {
            // R-02: unique index (kontrak_id, periode). Pengecekan exists() di
            // aplikasi punya celah balapan — dua klik cepat bisa lolos keduanya.
            // Constraint yang jadi pengaman; di sini hanya diterjemahkan ke 409.
            if ($e->getCode() === '23505') {
                return response()->json([
                    'message' => 'Tagihan untuk kontrak ini pada periode tersebut sudah ada.',
                    'errors' => ['periode' => ['Tagihan periode ini sudah pernah diterbitkan.']],
                ], 409);
            }

            throw $e;
        }

        $tagihan->load(['rincian', 'kontrak.kamar', 'kontrak.penghuni']);

        // Pemberitahuan dikirim di sini, di batas HTTP, bukan di
        // `PenerbitTagihan`: transaksi penulisan tagihan sudah selesai, jadi
        // tidak ada lagi yang bisa dibatalkan oleh kegagalan email.
        $this->pemberitahu->tagihanTerbit(
            pelaku: $request->user(),
            penghuniId: (int) $kontrak->penghuni_id,
            ringkasan: sprintf(
                'Tagihan %s kamar %s sebesar Rp %s',
                $periode->copy()->translatedFormat('F Y'),
                $kontrak->kamar?->nomor ?? "id {$kontrak->kamar_id}",
                number_format((int) $tagihan->pokok, 0, ',', '.'),
            ),
            jatuhTempo: $tagihan->jatuh_tempo->toDateString(),
        );

        return response()->json(['data' => new TagihanResource($tagihan)], 201);
    }

    /**
     * Rincian satu tagihan.
     *
     * `Tagihan $tagihan` dari route model binding sengaja **tidak** dipakai
     * langsung: binding mengambil baris tanpa memandang pemiliknya, sehingga
     * penghuni bisa membaca tagihan orang lain hanya dengan menebak id. Baris
     * diambil ulang lewat scope `terlihatOleh`, dan yang tidak terlihat
     * menghasilkan 404 — bukan 403, karena 403 membocorkan bahwa tagihan itu
     * ada.
     */
    public function show(Request $request, Tagihan $tagihan): JsonResponse
    {
        $tagihan = Tagihan::query()
            ->terlihatOleh($request->user())
            ->with([
                'rincian',
                // Pemeriksaan dan penerima ikut dimuat di sini supaya riwayat
                // di panel tidak menjalankan dua query per baris pembayaran.
                'pembayaran.verifikasi',
                'pembayaran.penerima',
                // Sejak tombol pembalik dipasang di panel rincian, riwayat ini
                // juga harus bisa menjawab "baris mana yang sudah ditarik".
                // Tanpa relasinya, `sudah_dibalik` hilang dari respons dan layar
                // menawarkan tombol Balik untuk pembayaran yang sudah dibalik.
                'pembayaran.pembalik',
                'kontrak.kamar',
                'kontrak.penghuni',
                // Pelaku pembatalan dan pemaafan ikut dimuat: tanpa ini,
                // `whenLoaded` menghilangkan namanya dan layar hanya bisa
                // menulis "dibatalkan" tanpa siapa.
                'pembatal',
                'pemaafDenda',
            ])
            ->withSum('pembayaran as total_dibayar', 'jumlah')
            ->findOrFail($tagihan->getKey());

        return response()->json(['data' => new TagihanResource($tagihan)]);
    }

    /**
     * Catat uang yang benar-benar diterima (proses 4.0).
     *
     * Seluruh keputusan yang butuh data tagihan — melebihi sisa, sudah lunas,
     * dibatalkan — berada di {@see PencatatPembayaran}, di dalam transaksi dan di
     * bawah kunci baris. Controller hanya menerjemahkan penolakan menjadi 422
     * dengan pesan yang menyebut angka konkret.
     */
    public function bayar(CatatPembayaranRequest $request, Tagihan $tagihan): JsonResponse
    {
        try {
            $hasil = $this->pencatatPembayaran->catat(
                tagihan: $tagihan,
                jumlah: $request->integer('jumlah'),
                tanggalPembayaran: Carbon::parse($request->string('tanggal_pembayaran')->value()),
                metode: $request->string('metode')->value(),
                diterimaOleh: $request->filled('diterima_oleh') ? $request->integer('diterima_oleh') : null,
                referensi: $request->filled('referensi') ? $request->string('referensi')->value() : null,
                catatan: $request->filled('catatan') ? $request->string('catatan')->value() : null,
            );
        } catch (PembayaranDitolak $e) {
            return response()->json([
                'message' => $e->getMessage(),
                // Bidang yang disalahkan dipilih sesuai pesannya: kelebihan
                // bayar adalah soal `jumlah`, bukan soal tanggal. Metode yang
                // tidak dikenal dan tunai tanpa pemegang masing-masing
                // menunjuk bidangnya sendiri, supaya layar bisa menyorot medan
                // yang benar alih-alih menampilkan galat di atas formulir.
                'errors' => [$this->bidangGalat($e) => [$e->getMessage()]],
            ], 422);
        }

        $tagihan->refresh()->load(['rincian', 'pembayaran.verifikasi', 'pembayaran.pencatat', 'pembayaran.penerima', 'pembayaran.pembalik', 'kontrak.kamar', 'kontrak.penghuni']);

        $this->pemberitahu->pembayaranDicatat(
            pelaku: $request->user(),
            penghuniId: (int) $tagihan->kontrak?->penghuni_id,
            ringkasan: sprintf(
                'Rp %s untuk tagihan %s kamar %s',
                number_format($hasil->pembayaran->jumlah, 0, ',', '.'),
                $tagihan->periode->translatedFormat('F Y'),
                $tagihan->kontrak?->kamar?->nomor ?? 'tanpa nomor',
            ),
        );

        return response()->json([
            'data' => [
                'pembayaran' => new PembayaranResource($hasil->pembayaran),
                'status_sebelum' => $hasil->statusSebelum,
                'status_sesudah' => $hasil->statusSesudah,
                'total' => $hasil->total,
                'dibayar' => $hasil->dibayar,
                'sisa' => $hasil->sisa,
                'tagihan' => new TagihanResource($tagihan),
            ],
            'meta' => [
                'tanggal_acuan' => $this->waktu->hariIni()->toDateString(),
                'simulasi' => $this->waktu->sedangSimulasi(),
                'demo_mode' => (bool) config('app.demo_mode'),
            ],
        ], 201);
    }

    /**
     * Bidang formulir yang disalahkan sebuah penolakan.
     *
     * Ditentukan dari pesannya, bukan dari kode galat tersendiri. Penolakan
     * datang sebagai exception berpesan Indonesia, dan menambahkan enum alasan
     * hanya untuk keperluan pemetaan ini berarti dua daftar alasan yang harus
     * dijaga sinkron. Tiga bidang yang ada sekarang sudah menutup semua
     * penolakan yang mungkin terjadi di `PencatatPembayaran`.
     */
    private function bidangGalat(PembayaranDitolak $e): string
    {
        $pesan = $e->getMessage();

        if (str_contains($pesan, 'Metode pembayaran')) {
            return 'metode';
        }

        if (str_contains($pesan, 'tunai harus menyebut')) {
            return 'diterima_oleh';
        }

        return 'jumlah';
    }

    /**
     * Batalkan tagihan yang salah terbit (REV-FIN-02).
     *
     * Pembatalan **tidak** menghapus baris: statusnya berubah menjadi
     * `dibatalkan` dan alasan, waktu, serta pelakunya dicatat. Yang dibebaskan
     * adalah periodenya — index unik `(kontrak_id, periode)` sejak migrasi ini
     * parsial terhadap `status <> 'dibatalkan'`, sehingga periode itu bisa
     * diterbitkan ulang tanpa menabrak tagihan lama.
     *
     * Ditolak 422 bila tagihan sudah menerima uang. Membatalkannya akan
     * meninggalkan uang yang tidak menempel pada tagihan mana pun; jalan yang
     * benar adalah menarik pembayarannya lebih dulu lewat baris pembalik.
     */
    public function batal(Request $request, Tagihan $tagihan): JsonResponse
    {
        $data = $request->validate([
            'alasan' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'alasan.required' => 'Sebutkan alasan pembatalan tagihan ini.',
            'alasan.min' => 'Alasan pembatalan terlalu pendek. Jelaskan sedikitnya lima karakter.',
            'alasan.max' => 'Alasan pembatalan terlalu panjang (maksimal 500 karakter).',
        ]);

        try {
            $tagihan = $this->pembatal->batalkan($tagihan, $data['alasan']);
        } catch (PembayaranDitolak $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['alasan' => [$e->getMessage()]],
            ], 422);
        }

        $tagihan->load([
            'rincian',
            'kontrak.kamar',
            'kontrak.penghuni',
            'pembatal',
        ]);

        return response()->json(['data' => new TagihanResource($tagihan)]);
    }

    /**
     * Maafkan sebagian atau seluruh denda sebuah tagihan (REV-FIN-07).
     *
     * `jumlah` boleh melebihi denda hari ini: pemilik yang memaafkan Rp 500.000
     * saat denda baru Rp 300.000 sedang memaafkan denda yang akan datang juga.
     * Hasil akhirnya dijepit ke nol di `PenghitungDenda`, bukan ditolak di
     * sini — menolaknya memaksa pemilik menghitung sendiri berapa denda pada
     * hari pelunasan.
     *
     * `jumlah: 0` menarik kembali pemaafan. Itu sengaja diizinkan: pemaafan
     * yang diberikan keliru harus bisa dibatalkan tanpa menyunting basis data.
     */
    public function maafkanDenda(Request $request, Tagihan $tagihan): JsonResponse
    {
        $data = $request->validate([
            'jumlah' => ['required', 'integer', 'min:0'],
            // Alasan hanya dituntut bila ada yang dimaafkan. `required_if` tidak
            // bisa menyatakan "lebih besar dari nol", jadi aturannya ditulis
            // sebagai closure — dan `PemaafDenda` tetap memeriksanya sekali
            // lagi, karena validator hanya menjaga jalur HTTP.
            'alasan' => [
                'nullable',
                'string',
                'max:500',
                Rule::requiredIf(fn () => (int) $request->input('jumlah', 0) > 0),
            ],
        ], [
            'jumlah.required' => 'Sebutkan berapa rupiah denda yang dimaafkan. Isi 0 untuk membatalkan pemaafan.',
            'jumlah.min' => 'Jumlah yang dimaafkan tidak boleh negatif.',
            'alasan.required' => 'Sebutkan alasan pemaafan dendanya.',
            'alasan.max' => 'Alasan pemaafan terlalu panjang (maksimal 500 karakter).',
        ]);

        try {
            $tagihan = $this->pemaaf->maafkan(
                tagihan: $tagihan,
                jumlah: (int) $data['jumlah'],
                alasan: (string) ($data['alasan'] ?? ''),
            );
        } catch (PembayaranDitolak $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['jumlah' => [$e->getMessage()]],
            ], 422);
        }

        $tagihan->load(['rincian', 'pembayaran.verifikasi', 'pembayaran.penerima', 'pembayaran.pembalik', 'kontrak.kamar', 'kontrak.penghuni', 'pemaafDenda']);
        $tagihan->loadSum('pembayaran as total_dibayar', 'jumlah');

        return response()->json(['data' => new TagihanResource($tagihan)]);
    }

    /**
     * Terbitkan tagihan untuk semua kontrak aktif pada satu periode (R-02).
     *
     * Kode statusnya dipilih untuk menjawab pertanyaan yang sebenarnya
     * ditanyakan pemilik: "apakah aksi saya tadi mengubah sesuatu?"
     *
     *   201 — ada tagihan baru terbit.
     *   409 — tidak ada yang terbit karena semuanya sudah punya tagihan periode
     *         ini. Ini yang terjadi saat tombol ditekan dua kali, dan ia harus
     *         terlihat sebagai penolakan, bukan sebagai sukses kosong.
     *   200 — tidak ada yang terbit karena kontraknya memang belum mulai.
     *         Bukan kesalahan; tidak ada yang perlu diperbaiki pemilik.
     *   422 — prasyarat belum terpenuhi: belum ada kontrak aktif sama sekali.
     */
    public function terbitkan(TerbitkanMassalRequest $request): JsonResponse
    {
        if (! Kontrak::query()->where('status', 'aktif')->exists()) {
            return response()->json([
                'message' => 'Belum ada kontrak aktif. Tambahkan kontrak lebih dulu.',
                'errors' => ['periode' => ['Belum ada kontrak aktif.']],
            ], 422);
        }

        $periode = Carbon::parse($request->string('periode')->value());

        // Gerbang kebijakan (T16) diperiksa sekali untuk default kos, sebelum
        // satu pun kontrak diproses. Tanpa pemeriksaan di sini, batch tanpa
        // kebijakan akan menghasilkan 30 baris "gagal" bernasib sama dan kode
        // status 200 — yang menurut arti kode itu sendiri berarti "tidak ada
        // yang perlu dikerjakan", padahal justru semuanya perlu dikerjakan.
        //
        // Gerbang per kamar tetap berlaku di dalam PenerbitTagihan: kamar yang
        // punya penimpa sendiri harus lengkap walau default kos sudah diisi.
        $kurang = $this->penyelesaiKebijakan->tipeYangKurang($periode, null);

        if ($kurang !== []) {
            $pesan = KebijakanBelumLengkap::dariTipe($kurang)->getMessage();

            return response()->json([
                'message' => $pesan,
                'errors' => ['periode' => [$pesan]],
            ], 422);
        }

        $hasil = $this->penerbitanMassal->jalankan($periode);

        $status = match (true) {
            $hasil['terbit'] > 0 => 201,
            $hasil['dilewati_duplikat'] > 0 => 409,
            default => 200,
        };

        // Dikirim juga saat tidak ada yang terbit, selama batch-nya benar-benar
        // dijalankan. Yang perlu diketahui pemilik adalah "tombolnya sudah
        // ditekan dan inilah hasilnya" - termasuk hasil yang kosong. Diam saja
        // saat 0 terbit akan membuatnya menekan tombol yang sama sekali lagi.
        //
        // Penghuni diberi tahu satu per satu (REV-DAT-02): sebelum ini mereka
        // tidak menerima apa pun, sehingga penerbitan massal - satu-satunya cara
        // wajar menagih sebulan sekaligus - justru jalan yang paling senyap.
        // Yang gagal tidak ikut: tidak ada tagihan yang bisa disebut nominalnya.
        $perPenghuni = [];

        foreach ($hasil['rincian'] as $baris) {
            if ($baris['status'] !== PenerbitanMassal::TERBIT) {
                continue;
            }

            $perPenghuni[] = [
                'penghuni_id' => $baris['penghuni_id'] ?? null,
                'ringkasan' => sprintf(
                    'Tagihan periode %s sebesar Rp %s sudah terbit',
                    $periode->copy()->startOfMonth()->translatedFormat('F Y'),
                    number_format($baris['pokok'], 0, ',', '.'),
                ),
                'jatuh_tempo' => $baris['jatuh_tempo'],
            ];
        }

        $this->pemberitahu->tagihanMassal(
            pelaku: $request->user(),
            periode: $periode->copy()->startOfMonth()->translatedFormat('F Y'),
            terbit: $hasil['terbit'],
            gagal: $hasil['gagal'],
            perPenghuni: $perPenghuni,
        );

        return response()->json([
            'data' => $hasil,
            'meta' => [
                'tanggal_acuan' => $this->waktu->hariIni()->toDateString(),
                'simulasi' => $this->waktu->sedangSimulasi(),
                'demo_mode' => (bool) config('app.demo_mode'),
            ],
        ], $status);
    }
}
