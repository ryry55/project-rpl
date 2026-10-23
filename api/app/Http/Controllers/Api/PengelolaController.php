<?php

namespace App\Http\Controllers\Api;

use App\Domain\Akses\Wewenang;
use App\Domain\Akun\PembuatAkun;
use App\Domain\Akun\TautanAkun;
use App\Domain\Audit\PencatatAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SimpanPengelolaRequest;
use App\Http\Resources\PengelolaResource;
use App\Models\TokenAkun;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Akun pengelola dan wewenangnya (T24).
 *
 * Kenapa endpoint ini hanya untuk PEMILIK, bukan untuk pemilik dan pengelola
 * dengan wewenang tertentu. Wewenang yang bisa diberikan adalah wewenang yang
 * bisa dicabut, dan siapa pun yang boleh memberikan wewenang pada dasarnya
 * boleh memberi dirinya sendiri wewenang apa pun - termasuk mengubah tarif.
 * Pembatasan wewenang menjadi tidak berarti bila pemberi wewenang ada di dalam
 * lingkaran yang dibatasinya.
 *
 * Jejak audit untuk pembuatan dan perubahan akun ditulis di sini, bukan hanya
 * pada perubahan uang. Pertanyaan "siapa yang memberi izin ini" sama
 * pentingnya dengan "siapa yang mengubah tarif".
 */
class PengelolaController extends Controller
{
    public function __construct(
        private readonly PencatatAudit $audit,
        private readonly PembuatAkun $pembuat,
        private readonly TautanAkun $tautan,
    ) {}

    public function index(): JsonResponse
    {
        $daftar = User::query()
            ->where('peran', User::PERAN_PENGELOLA)
            ->with('kewenangan')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => PengelolaResource::collection($daftar),
            'meta' => [
                // Daftar wewenang dikirim server supaya layar tidak menyimpan
                // salinan kedua. Salinan di peramban adalah salinan yang paling
                // sering tertinggal saat wewenang baru ditambahkan, dan
                // gejalanya adalah kotak centang yang tidak berpengaruh apa pun.
                'semua_wewenang' => Wewenang::SEMUA,
                'label_wewenang' => Wewenang::label(),
                'wewenang_sensitif' => Wewenang::SENSITIF,
            ],
        ]);
    }

    public function store(SimpanPengelolaRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Akunnya dibuat dengan sandi acak yang tidak diketahui siapa pun -
        // termasuk pemilik yang menekan tombol ini. Lihat catatan kelas
        // PembuatAkun: pemilik yang tahu sandi pengelola bisa mengubah tarif
        // atas namanya, dan jejak audit lalu menulis nama orang yang tidak
        // melakukannya.
        $pengelola = DB::transaction(function () use ($data, $request) {
            $pengelola = $this->pembuat->untukPengelola(
                nama: $data['nama'],
                email: $data['email'],
                // Diambil dari sesi, bukan dari isian formulir: pengelola yang
                // menunjuk pemilik lain tidak punya arti dalam model satu-kos,
                // dan menerimanya dari klien berarti membuka jalan akun
                // pengelola tanpa atasan.
                pemilikId: $request->user()->id,
            );

            $this->simpanWewenang($pengelola, $data['wewenang'] ?? []);

            $this->audit->catat(
                aksi: 'pengelola.tambah',
                entitas: 'users',
                entitasId: $pengelola->id,
                // Sengaja TANPA `nama` dan `email` (T28).
                //
                // `entitas_id` sudah menunjuk akun ini, dan namanya bisa dibaca
                // dari sana selama akunnya ada. Menyalinnya ke sini menciptakan
                // salinan KEDUA data pribadi di tabel yang tidak bisa diubah -
                // trigger `log_audit` hanya mengizinkan perubahan `aktor_*`,
                // sehingga penghapusan data pribadi (T28) tidak akan pernah
                // mencapai salinan ini. Yang tertinggal sesudah penghuni
                // menghapus akunnya adalah justru hal yang ia minta dihapus.
                //
                // Bedanya dengan `aktor_nama`, yang memang dibekukan: aktor
                // adalah pertanggungjawaban - "siapa yang melakukan ini" harus
                // tetap terbaca walau namanya berubah kemudian. Subjek bukan
                // pertanggungjawaban; ia hanya orang yang datanya tercatat.
                nilaiBaru: [
                    'wewenang' => $data['wewenang'] ?? [],
                ],
            );

            return $pengelola;
        });

        $pengelola->load('kewenangan');

        $terkirim = $this->tautan->kirimAman($pengelola, TokenAkun::JENIS_AKTIVASI);

        return response()->json([
            'data' => new PengelolaResource($pengelola),
            'meta' => [
                'tautan_aktivasi_terkirim' => $terkirim,
                'email' => $pengelola->email,
            ],
        ], 201);
    }

    public function show(User $pengelola): JsonResponse
    {
        $this->pastikanPengelola($pengelola);

        $pengelola->load('kewenangan');

        return response()->json(['data' => new PengelolaResource($pengelola)]);
    }

    public function update(SimpanPengelolaRequest $request, User $pengelola): JsonResponse
    {
        $this->pastikanPengelola($pengelola);

        $data = $request->validated();

        $emailBerubah = $pengelola->email !== $data['email'];

        DB::transaction(function () use ($data, $emailBerubah, $pengelola) {
            $lama = [
                'wewenang' => $pengelola->kewenangan()->pluck('wewenang')->sort()->values()->all(),
            ];

            $perubahan = [
                'name' => $data['nama'],
                'email' => $data['email'],
            ];

            $pengelola->update($perubahan);

            // `email_verified_at` TIDAK ikut di `$perubahan`: kolom itu tidak
            // ada di `$fillable`, dan menaruhnya di sana akan diabaikan diam-diam
            // oleh `update()`. Akibatnya mengganti email tidak membatalkan
            // aktivasi lama, sementara tautan aktivasi baru tetap terkirim -
            // dua keadaan yang saling bertentangan tanpa satu pun galat.
            //
            // Mengganti email membatalkan aktivasi sebelumnya: tautan lama
            // dikirim ke alamat yang mungkin bukan milik orang ini.
            if ($emailBerubah) {
                $pengelola->forceFill(['email_verified_at' => null])->save();
                $pengelola->tokens()->delete();
            }

            $this->simpanWewenang($pengelola, $data['wewenang'] ?? []);

            $pengelola->load('kewenangan');

            $this->audit->catat(
                aksi: 'pengelola.ubah',
                entitas: 'users',
                entitasId: $pengelola->id,
                nilaiLama: $lama,
                // Tanpa `nama`/`email` - alasannya sama dengan `pengelola.tambah`.
                nilaiBaru: [
                    'wewenang' => $pengelola->kewenangan->pluck('wewenang')->sort()->values()->all(),
                ],
            );
        });

        $terkirim = $emailBerubah
            ? $this->tautan->kirimAman($pengelola, TokenAkun::JENIS_AKTIVASI)
            : null;

        return response()->json([
            'data' => new PengelolaResource($pengelola),
            'meta' => [
                'tautan_aktivasi_terkirim' => $terkirim,
                'email' => $pengelola->email,
            ],
        ]);
    }

    /**
     * Kirim ulang tautan aktivasi.
     *
     * Endpoint tersendiri, bukan efek samping PUT. Kasusnya nyata dan sering:
     * pemilik salah mengetik alamat email pengelola baru, atau emailnya masuk
     * folder spam lalu terhapus. Tanpa endpoint ini, satu-satunya jalan keluar
     * adalah menghapus akunnya - dan akun yang sudah tercatat di jejak audit
     * tidak bisa dihapus.
     *
     * Hanya untuk akun yang belum aktif. Akun yang sudah aktif dan lupa
     * sandinya memakai alur "lupa sandi" miliknya sendiri, bukan jalur ini:
     * pemilik yang bisa mengirim ulang tautan aktivasi untuk akun aktif
     * sama saja dengan pemilik yang bisa mengambil alih akun itu.
     */
    public function kirimUlangAktivasi(User $pengelola): JsonResponse
    {
        $this->pastikanPengelola($pengelola);

        if (! $pengelola->belumAktif()) {
            throw ValidationException::withMessages([
                'pengelola' => ['Akun ini sudah aktif. Minta pemiliknya memakai "lupa kata sandi" di halaman masuk.'],
            ])->status(409);
        }

        $terkirim = $this->tautan->kirimAman($pengelola, TokenAkun::JENIS_AKTIVASI);

        return response()->json([
            'data' => ['pesan' => 'Tautan aktivasi dikirim ulang.'],
            'meta' => [
                'tautan_aktivasi_terkirim' => $terkirim,
                'email' => $pengelola->email,
            ],
        ]);
    }

    /**
     * Mencabut akses pengelola.
     *
     * Akunnya **dinonaktifkan, bukan dihapus** - dan bentuknya adalah
     * pemindahan peran menjadi penghuni tanpa penghuni_id, bukan tombol hapus.
     *
     * Alasannya: log_audit menyimpan `aktor_id` sebagai angka tanpa foreign key,
     * sehingga menghapus user tidak merusak jejak. Tetapi token Sanctum yang
     * masih dipegang orangnya akan tetap berlaku sampai kedaluwarsa, dan
     * `DELETE /users` tidak mencabutnya. Menurunkan peran membuat seluruh
     * pemeriksaan wewenang gagal pada permintaan berikutnya, tanpa menunggu
     * token kedaluwarsa.
     *
     * Yang TIDAK dilakukan: menghapus baris `users` sama sekali. Akun yang
     * pernah mencatat pembayaran harus tetap bisa disebut namanya di laporan.
     */
    public function destroy(Request $request, User $pengelola): JsonResponse
    {
        $this->pastikanPengelola($pengelola);

        DB::transaction(function () use ($pengelola) {
            $wewenang = $pengelola->kewenangan()->pluck('wewenang')->sort()->values()->all();

            $pengelola->kewenangan()->delete();
            $pengelola->update([
                'peran' => User::PERAN_PENGHUNI,
                'pemilik_id' => null,
            ]);

            // Token dicabut, bukan hanya wewenangnya. Wewenang dicabut membuat
            // permintaan berikutnya ditolak; mencabut token membuat orangnya
            // tidak bisa lagi memakai akun itu sama sekali, yang memang maksud
            // dari "cabut akses".
            $pengelola->tokens()->delete();

            $this->audit->catat(
                aksi: 'pengelola.cabut',
                entitas: 'users',
                entitasId: $pengelola->id,
                nilaiLama: [
                    // Tanpa `nama`/`email` - alasannya sama dengan
                    // `pengelola.tambah`. `entitas_id` sudah menunjuk akunnya.
                    'wewenang' => $wewenang,
                ],
                nilaiBaru: ['peran' => User::PERAN_PENGHUNI, 'wewenang' => []],
            );
        });

        return response()->json([
            'data' => ['pesan' => 'Akses pengelola dicabut. Akunnya tidak bisa mengurus kos lagi.'],
        ]);
    }

    /**
     * Mengganti seluruh daftar wewenang.
     *
     * `delete` lalu `insert`, bukan `sync` pada relasi: daftar wewenang tidak
     * punya kolom tambahan, sehingga menghapus lalu menulis ulang lebih
     * sederhana daripada mencocokkan satu per satu. Dilakukan di dalam
     * transaksi pemanggil, jadi tidak ada saat di mana pengelola kehilangan
     * seluruh wewenangnya lalu mendapatkannya kembali.
     *
     * Duplikat dari klien dibuang di sini. Validasi tidak menolaknya karena
     * mengirim daftar berganda bukan kesalahan pengguna yang perlu dilaporkan -
     * hasilnya sama saja, dan pesan galat hanya akan membingungkan.
     *
     * @param  list<string>  $wewenang
     */
    private function simpanWewenang(User $pengelola, array $wewenang): void
    {
        $unik = array_values(array_unique($wewenang));

        $pengelola->kewenangan()->delete();

        if ($unik === []) {
            return;
        }

        $pengelola->kewenangan()->createMany(
            array_map(fn (string $w) => ['wewenang' => $w], $unik)
        );
    }

    /**
     * Tolak akun yang bukan pengelola.
     *
     * Tanpa ini, `PUT /pengelola/{id}` dengan id pemilik akan mengubah nama dan
     * kata sandi akun pemilik - dan bisa menurunkannya menjadi penghuni lewat
     * endpoint pencabutan. Route model binding mencari `users.id` apa adanya,
     * jadi pemeriksaan peran harus eksplisit di sini.
     */
    private function pastikanPengelola(User $user): void
    {
        if (! $user->isPengelola()) {
            throw ValidationException::withMessages([
                'pengelola' => ['Akun ini bukan pengelola.'],
            ])->status(404);
        }
    }
}
