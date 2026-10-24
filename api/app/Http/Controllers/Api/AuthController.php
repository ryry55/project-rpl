<?php

namespace App\Http\Controllers\Api;

use App\Domain\Akun\PembatasMasuk;
use App\Domain\Akun\TautanAkun;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\PersetujuanPdp;
use App\Models\TokenAkun;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Masuk, keluar, dan pemulihan akses (T27).
 *
 * Empat lubang yang ditutup di sini, semuanya akan dieksploitasi kalau aplikasi
 * ini dipasang di internet apa adanya:
 *
 *   1. **Tidak ada pembatas percobaan.** Sekarang 5 kali per menit per pasangan
 *      email+IP dan 20 kali per jam per email - lihat {@see PembatasMasuk}.
 *
 *   2. **Token tidak pernah kedaluwarsa.** Sekarang token berlaku 14 hari
 *      (config/sanctum.php) dan setiap login memutus token terlama bila jumlah
 *      sesi melewati batas. Token dari laptop warnet tidak lagi berlaku
 *      selamanya.
 *
 *   3. **Tidak ada jalan pulih saat sandi lupa.** Sekarang ada alur lupa sandi
 *      lewat email. Tanpa itu, satu-satunya pemulihan adalah pemilik mengetik
 *      sandi baru - persis kebiasaan yang T27 hapus.
 *
 *   4. **Akun lama yang sandinya diketahui pemilik.** Sekarang ditandai
 *      `wajib_ganti_sandi` dan ditahan oleh middleware sampai diganti.
 */
class AuthController extends Controller
{
    /**
     * Batas jumlah sesi aktif per akun.
     *
     * Ponsel, laptop, dan komputer kos: tiga sudah cukup untuk satu orang.
     * Tanpa batas, akun yang tokennya pernah bocor terus menumpuk sesi, dan
     * tidak ada satu pun layar yang menunjukkan berapa banyak yang masih hidup.
     */
    private const MAKS_SESI = 3;

    public function __construct(
        private readonly PembatasMasuk $pembatas,
        private readonly TautanAkun $tautan,
    ) {}

    /**
     * Terbitkan token untuk kredensial yang benar.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = $request->string('email')->lower()->value();
        $ip = (string) $request->ip();

        $tunggu = $this->pembatas->tungguSebelum($email, $ip);

        if ($tunggu > 0) {
            // 429, bukan 401. Bedanya penting bagi layar: 401 berarti
            // "kredensialnya salah, periksa lagi", sedangkan 429 berarti
            // "berhenti mencoba, tunggu". Menjawab 401 di sini membuat
            // pengguna yang salah ketik tiga kali mengira sandinya berubah.
            throw ValidationException::withMessages([
                'email' => [$this->pembatas->pesanTunggu($tunggu)],
            ])->status(429);
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            // Waktu disamakan sebelum menjawab. Tanpa ini, "email tidak
            // terdaftar" dijawab dalam 2 ms sedangkan "sandi salah" dalam
            // 250 ms - dan selisih itu sudah cukup untuk memeriksa alamat mana
            // yang punya akun, tanpa satu pun percobaan yang tercatat gagal.
            $this->pembatas->userTidakDikenal();
            $this->pembatas->catatGagal($email, $ip);

            throw ValidationException::withMessages([
                'email' => ['Email atau kata sandi salah.'],
            ])->status(401);
        }

        if (! Hash::check($request->string('password')->value(), $user->password)) {
            $this->pembatas->catatGagal($email, $ip);

            throw ValidationException::withMessages([
                'email' => ['Email atau kata sandi salah.'],
            ])->status(401);
        }

        // Akun yang belum diaktifkan tidak bisa dipakai masuk, dan pesannya
        // menyebutkan tautan aktivasi - bukan "sandi salah". Kalau dijawab
        // "sandi salah", penghuni akan mencoba terus dengan sandi yang memang
        // tidak pernah ia tetapkan, dan pemilik tidak akan pernah tahu ada
        // tautan yang tidak sampai.
        if ($user->belumAktif()) {
            throw ValidationException::withMessages([
                'email' => ['Akun ini belum diaktifkan. Buka tautan aktivasi yang dikirim ke email Anda.'],
            ])->status(403);
        }

        $this->pembatas->bersihkan($email, $ip);

        // Dimuat lebih dulu: UserResource membaca relasi ini untuk mengisi
        // daftar wewenang, dan tanpa eager load setiap login menjalankan satu
        // query tambahan setelah token dibuat.
        $user->load('kewenangan');

        $this->batasiSesi($user);

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['pesan' => 'Berhasil keluar.']]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('kewenangan');

        return response()->json([
            'data' => new UserResource($user),
            'meta' => [
                'wajib_ganti_sandi' => $user->wajibGantiSandi(),
                'perlu_persetujuan_pdp' => ! $user->sudahMenyetujuiPdp(),
                'versi_pdp' => PersetujuanPdp::VERSI_BERLAKU,
            ],
        ]);
    }

    /**
     * Lupa sandi: kirim tautan reset.
     *
     * **Selalu dijawab sama**, apakah emailnya terdaftar atau tidak. Jawaban
     * yang berbeda menjadikan endpoint ini alat untuk memeriksa alamat mana yang
     * punya akun di kos ini - dan daftar penghuni adalah data pribadi.
     */
    public function lupaSandi(Request $request): JsonResponse
    {
        $request->validate(
            ['email' => ['required', 'email']],
            [
                'email.required' => 'Email wajib diisi.',
                'email.email' => 'Format email tidak valid.',
            ]
        );

        $email = $request->string('email')->lower()->value();

        if (! $this->pembatas->bolehMintaTautan($email)) {
            throw ValidationException::withMessages([
                'email' => ['Terlalu banyak permintaan tautan untuk email ini. Coba lagi dalam '
                    .(int) ceil($this->pembatas->tungguTautan($email) / 60).' menit.'],
            ])->status(429);
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            // Akun yang belum aktif tidak dikirimi tautan reset: tautan reset
            // untuk akun yang belum pernah dipakai masuk akan menjadi jalan
            // pintas aktivasi, dan pemilik yang salah mengetik email bisa
            // mengirim tautan reset ke orang yang tidak seharusnya.
            if ($user->belumAktif()) {
                $this->tautan->kirimAman($user, TokenAkun::JENIS_AKTIVASI);
            } else {
                $this->tautan->kirimAman($user, TokenAkun::JENIS_RESET);
            }
        } else {
            // Waktu respons disamakan. Tanpa ini, jawaban 200 yang sama tetap
            // bisa dibedakan dari lamanya - dan perbedaan waktu sudah cukup.
            $this->pembatas->userTidakDikenal();
        }

        return response()->json([
            'data' => [
                'pesan' => 'Bila email itu terdaftar, tautan untuk mengatur kata sandi sudah dikirim.',
            ],
        ]);
    }

    /**
     * Periksa token sebelum layar menyuruh orang mengetik sandi baru.
     *
     * Tanpa endpoint ini, tautan yang sudah kedaluwarsa baru ketahuan setelah
     * pengguna mengetik sandi baru dua kali - dan galatnya muncul setelah
     * usaha, bukan sebelum.
     *
     * **Dibatasi 20 kali per menit per IP (REV-SEC-05).** Endpoint ini publik,
     * tanpa autentikasi, dan menjawab "token ini sah" untuk sebuah token acak
     * 64 karakter. Tanpa batas, ia adalah mesin penebak yang boleh berjalan
     * sesuka hati; dengan batas, jumlah percobaan yang mungkin dalam masa
     * berlaku tautan (60 menit untuk reset) jauh di bawah jumlah kemungkinan
     * token. Kuncinya IP saja karena email justru yang sedang dicari - lihat
     * catatan di {@see PembatasMasuk::bolehPeriksaToken()}.
     */
    public function periksaToken(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        $ip = (string) $request->ip();

        if (! $this->pembatas->bolehPeriksaToken($ip)) {
            throw ValidationException::withMessages([
                'token' => ['Terlalu banyak pemeriksaan tautan. Coba lagi dalam '
                    .$this->pembatas->tungguPeriksaToken($ip).' detik.'],
            ])->status(429);
        }

        $jenis = $request->string('jenis')->value() ?: TokenAkun::JENIS_RESET;
        $token = $this->tautan->pakai($request->string('token')->value(), $jenis);

        return response()->json([
            'data' => [
                'berlaku' => $token !== null,
                // Nama pemilik akun ditampilkan supaya orang yang membuka
                // tautan dari kotak masuk bersama tahu akun siapa yang sedang
                // diatur - dan bukan akun orang lain. Namanya tidak disamarkan:
                // itulah satu-satunya gunanya layar ini, dan orang yang
                // memegang tokennya memang sudah membuka kotak surat akun itu.
                'nama' => $token?->user?->name,
                // Emailnya disamarkan (REV-SEC-05). Alamat lengkap di sini
                // menambah satu tempat lagi yang menyimpan PII tanpa menambah
                // kegunaan: yang perlu dikenali hanya "akun siapa", dan itu
                // sudah dijawab namanya. Sisanya cukup untuk memastikan
                // alamatnya memang milik orang yang membuka layar.
                'email' => $token?->user?->email === null
                    ? null
                    : $this->samarkanEmail($token->user->email),
            ],
        ]);
    }

    /**
     * Samarkan bagian lokal sebuah email: `budi@kos.test` -> `bu***@kos.test`.
     *
     * Domainnya dibiarkan utuh. Kalau ikut disamarkan, satu-satunya petunjuk
     * yang tersisa adalah dua huruf pertama - dan itu terlalu sedikit untuk
     * membedakan akun kantor dari akun pribadi saat seseorang punya dua
     * alamat di domain berbeda.
     */
    private function samarkanEmail(string $email): string
    {
        $bagian = explode('@', $email, 2);

        if (count($bagian) === 1) {
            return str_repeat('*', max(3, mb_strlen($email)));
        }

        [$lokal, $domain] = $bagian;

        // Dua huruf pertama dipertahankan supaya orangnya masih bisa
        // mengenalinya; sisanya diganti bintang. Minimal tiga bintang agar
        // panjang aslinya tidak terbaca dari jumlah bintang pada alamat pendek.
        $awal = mb_substr($lokal, 0, 2);

        return $awal.str_repeat('*', max(3, mb_strlen($lokal) - mb_strlen($awal))).'@'.$domain;
    }

    /**
     * Tetapkan sandi baru dari tautan aktivasi atau reset.
     */
    public function aturSandi(Request $request): JsonResponse
    {
        $request->validate(
            [
                'token' => ['required', 'string'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ],
            [
                'token.required' => 'Tautan tidak lengkap.',
                'password.required' => 'Kata sandi baru wajib diisi.',
                'password.min' => 'Kata sandi minimal 8 karakter.',
                'password.confirmed' => 'Ulangan kata sandi tidak sama.',
            ]
        );

        $jenis = $request->string('jenis')->value() ?: TokenAkun::JENIS_RESET;
        $token = $this->tautan->pakai($request->string('token')->value(), $jenis);

        if (! $token) {
            throw ValidationException::withMessages([
                'token' => ['Tautan ini sudah tidak berlaku. Minta tautan baru lewat "Lupa kata sandi".'],
            ])->status(410);
        }

        $user = $this->tautan->setelSandi($token, $request->string('password')->value());

        return response()->json([
            'data' => [
                'pesan' => 'Kata sandi tersimpan. Silakan masuk dengan kata sandi baru Anda.',
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * Ganti sandi akun yang sedang masuk.
     *
     * Dipakai dua keadaan: pengguna yang ingin mengganti sandinya sendiri, dan
     * akun lama yang ditahan `wajib_ganti_sandi`. Bedanya hanya pada apakah
     * sandi lama ditanyakan - dan pada keadaan kedua sandi lama memang sudah
     * diketahui orang lain, jadi menanyakannya tidak melindungi apa pun.
     */
    public function gantiSandi(Request $request): JsonResponse
    {
        $user = $request->user();

        $aturan = [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];

        // Sandi lama hanya diwajibkan bagi yang tidak sedang dipaksa mengganti.
        // Menanyakannya pada akun yang wajib ganti akan mengunci orang yang
        // lupa sandi lamanya - padahal justru itu alasan ia ada di layar ini.
        if (! $user->wajibGantiSandi()) {
            $aturan['password_lama'] = ['required', 'string'];
        }

        $request->validate($aturan, [
            'password_lama.required' => 'Kata sandi lama wajib diisi.',
            'password.required' => 'Kata sandi baru wajib diisi.',
            'password.min' => 'Kata sandi minimal 8 karakter.',
            'password.confirmed' => 'Ulangan kata sandi tidak sama.',
        ]);

        if (! $user->wajibGantiSandi()
            && ! Hash::check($request->string('password_lama')->value(), $user->password)) {
            throw ValidationException::withMessages([
                'password_lama' => ['Kata sandi lama tidak cocok.'],
            ])->status(401);
        }

        $user->forceFill([
            'password' => Hash::make($request->string('password')->value()),
            'wajib_ganti_sandi' => false,
        ])->save();

        // Token lain diputus, token yang sedang dipakai dipertahankan: memutus
        // semuanya berarti pengguna yang baru saja berhasil mengganti sandi
        // langsung terlempar ke halaman masuk, dan itu terbaca sebagai gagal.
        $user->tokens()
            ->where('id', '!=', $user->currentAccessToken()?->id)
            ->delete();

        return response()->json([
            'data' => ['pesan' => 'Kata sandi diperbarui.'],
        ]);
    }

    /**
     * Catat persetujuan pemrosesan data pribadi (T28).
     *
     * Boleh dipanggil berkali-kali; versi yang sama tidak menambah baris kedua.
     * Layar boleh memanggilnya tanpa memeriksa lebih dulu - pemeriksaan ganda
     * di klien dan server hanya menambah satu jalan yang bisa berbeda hasilnya.
     */
    public function setujuiPdp(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->persetujuanPdp()->firstOrCreate(
            ['versi' => PersetujuanPdp::VERSI_BERLAKU],
            [
                'disetujui_pada' => now(),
                'ip' => $request->ip(),
            ]
        );

        return response()->json([
            'data' => [
                'pesan' => 'Persetujuan tersimpan.',
                'versi' => PersetujuanPdp::VERSI_BERLAKU,
            ],
        ]);
    }

    /**
     * Putus sesi terlama bila jumlahnya melewati batas.
     *
     * `tokens()` sudah berurutan menurut id, dan id naik menurut waktu dibuat -
     * jadi "terlama" berarti id terkecil. Tidak memakai `last_used_at` karena
     * kolom itu baru terisi setelah token dipakai, dan token yang baru dibuat
     * tetapi belum dipakai akan terlihat paling tua.
     */
    private function batasiSesi(User $user): void
    {
        $berlebih = $user->tokens()->count() - (self::MAKS_SESI - 1);

        if ($berlebih <= 0) {
            return;
        }

        $user->tokens()
            ->orderBy('id')
            ->limit($berlebih)
            ->delete();
    }
}
