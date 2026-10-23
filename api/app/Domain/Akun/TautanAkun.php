<?php

namespace App\Domain\Akun;

use App\Mail\TautanAkunKos;
use App\Models\TokenAkun;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tautan sekali pakai untuk aktivasi akun dan reset sandi (T27).
 *
 * Satu kelas untuk dua alur karena keduanya berbeda hanya pada umur tautan dan
 * kalimat emailnya. Memisahkannya menjadi dua kelas akan menduplikasi
 * penerbitan, penyimpanan hash, dan pemeriksaan masa berlaku - tiga tempat yang
 * harus benar bersamaan, dan yang paling sering salah adalah yang paling jarang
 * dibaca.
 *
 * **Email di sini tidak bisa dimatikan penggunanya.** Berbeda dari notifikasi
 * T25 yang punya layar pengaturan, surat ini adalah satu-satunya jalan masuk ke
 * akun. Kalau bisa dimatikan, mematikannya mengunci orang dari akunnya sendiri,
 * dan pemulihannya menuntut pemilik kos mengetik sandi - persis lubang yang
 * T27 tutup.
 */
class TautanAkun
{
    /**
     * Umur tautan aktivasi: 7 hari.
     *
     * Panjang dengan sengaja. Pemilik membuat akun penghuni pada hari kerja,
     * sedangkan penghuni sering baru membuka emailnya akhir pekan. Tautan
     * aktivasi yang mati dalam satu jam berarti pemilik harus membuat ulang
     * akun - dan pembuatan ulang itulah yang mendorong orang kembali ke
     * kebiasaan mengetik sandi untuk penghuni.
     */
    public const UMUR_AKTIVASI_MENIT = 60 * 24 * 7;

    /**
     * Umur tautan reset sandi: 60 menit.
     *
     * Pendek, karena tautan reset adalah kunci penuh atas akun yang sudah
     * berisi data. Tautan aktivasi hanya bisa dipakai sekali dan hanya untuk
     * akun yang belum pernah dipakai masuk; tautan reset bisa dipakai untuk
     * mengambil alih akun yang sedang aktif.
     */
    public const UMUR_RESET_MENIT = 60;

    /**
     * Terbitkan token baru dan kirim tautannya lewat email.
     *
     * Token lama yang belum dipakai dihapus lebih dulu. Dua tautan aktif untuk
     * satu akun berarti dua kesempatan masuk, dan yang lebih tua tidak pernah
     * dipakai siapa pun - ia hanya menambah permukaan serangan.
     *
     * @return string token mentah, hanya untuk keperluan uji; di produksi nilainya
     *                tidak pernah dibaca siapa pun setelah email terkirim
     */
    public function terbitkan(User $user, string $jenis): string
    {
        $mentah = Str::random(64);

        TokenAkun::query()
            ->where('user_id', $user->id)
            ->where('jenis', $jenis)
            ->whereNull('dipakai_pada')
            ->delete();

        TokenAkun::create([
            'user_id' => $user->id,
            'jenis' => $jenis,
            'token_hash' => hash('sha256', $mentah),
            'kedaluwarsa_pada' => now()->addMinutes($this->umurMenit($jenis)),
            'dibuat_pada' => now(),
        ]);

        return $mentah;
    }

    /**
     * Terbitkan lalu kirim emailnya.
     *
     * Kegagalan pengiriman **dilempar ulang** di sini, berbeda dari notifikasi
     * T25 yang hanya mencatat peringatan. Alasannya: pada notifikasi, perubahan
     * yang sah sudah tersimpan dan email hanyalah kesopanan. Di sini email
     * adalah satu-satunya cara orangnya bisa masuk. Menelannya diam-diam
     * menghasilkan pemilik yang mengira akun sudah dibuat sementara penghuni
     * tidak pernah menerima apa pun - dan tidak ada satu pun layar yang
     * mengatakan itu.
     */
    public function kirim(User $user, string $jenis): void
    {
        $mentah = $this->terbitkan($user, $jenis);

        Mail::to($user->email)->send(new TautanAkunKos(
            jenis: $jenis,
            nama: $user->name,
            // `jenis` ikut di tautan, bukan hanya di server. Tanpa itu layar
            // tidak bisa tahu apakah token ini untuk aktivasi atau reset, dan
            // `POST /atur-sandi` akan menebaknya sebagai `reset` - sehingga
            // setiap tautan aktivasi ditolak 410 oleh pemeriksaan jenis.
            tautan: '/atur-sandi?token='.$mentah.'&jenis='.$jenis,
            berlakuSampai: now()->addMinutes($this->umurMenit($jenis)),
        ));
    }

    /**
     * Kirim tanpa melempar: dipakai di jalur yang perubahan datanya sudah
     * tersimpan dan tidak boleh dibatalkan.
     *
     * Kasusnya nyata: pemilik menekan "kirim ulang tautan aktivasi" untuk
     * penghuni yang emailnya salah ketik. Akunnya sudah ada dan benar; yang
     * gagal hanya pengirimannya, dan membatalkan seluruh permintaan akan
     * menghapus akun yang sebenarnya sudah siap dipakai setelah emailnya
     * diperbaiki.
     */
    public function kirimAman(User $user, string $jenis): bool
    {
        try {
            $this->kirim($user, $jenis);

            return true;
        } catch (Throwable $e) {
            Log::warning('Tautan akun gagal dikirim.', [
                'jenis' => $jenis,
                'penerima' => $user->email,
                'sebab' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Pakai token: kembalikan akunnya bila token sah, atau null.
     *
     * Tidak melempar dan tidak membedakan "tidak ada", "sudah dipakai", dan
     * "kedaluwarsa". Ketiganya dijawab sama di layar: tautan tidak berlaku
     * lagi, minta yang baru. Membedakannya hanya memberi tahu penebak bahwa
     * token yang ia coba pernah ada - dan token yang pernah ada adalah token
     * yang layak ditebak lebih keras.
     */
    public function pakai(string $mentah, string $jenis): ?TokenAkun
    {
        if (trim($mentah) === '') {
            return null;
        }

        $token = TokenAkun::query()
            ->where('token_hash', hash('sha256', $mentah))
            ->first();

        if (! $token || ! $token->masihBerlakuUntuk($jenis)) {
            return null;
        }

        return $token;
    }

    /**
     * Tandai token terpakai dan setel sandi baru.
     *
     * Ditandai terpakai, bukan dihapus: baris yang tersisa menjawab "kapan
     * tautan ini dipakai", pertanyaan yang muncul saat seseorang mengaku
     * tautannya tidak berfungsi padahal tercatat sudah dipakai.
     */
    public function setelSandi(TokenAkun $token, string $sandiBaru): User
    {
        $user = $token->user;

        $user->forceFill([
            'password' => Hash::make($sandiBaru),
            // Aktivasi berarti alamat emailnya terbukti: tautannya hanya bisa
            // dibuka dari kotak surat itu.
            'email_verified_at' => $user->email_verified_at ?? now(),
            // Sandi barunya hanya diketahui pemilik akun. Ini yang membuat
            // kolom wajib_ganti_sandi tidak lagi berlaku.
            'wajib_ganti_sandi' => false,
        ])->save();

        $token->forceFill(['dipakai_pada' => now()])->save();

        // Seluruh sesi lama diputus. Kalau akun ini sebelumnya pernah dipakai
        // masuk oleh orang lain - termasuk pemilik yang mengetik sandinya
        // sebelum T27 - token Sanctum miliknya masih berlaku, dan mengganti
        // sandi tanpa memutusnya hanya mengganti pintu depan sementara pintu
        // belakangnya tetap terbuka.
        $user->tokens()->delete();

        return $user;
    }

    private function umurMenit(string $jenis): int
    {
        return $jenis === TokenAkun::JENIS_AKTIVASI
            ? self::UMUR_AKTIVASI_MENIT
            : self::UMUR_RESET_MENIT;
    }
}
