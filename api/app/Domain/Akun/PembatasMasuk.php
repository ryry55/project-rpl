<?php

namespace App\Domain\Akun;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Pembatas percobaan masuk dan permintaan tautan (T27).
 *
 * Tanpa ini, satu skrip dapat mencoba sepuluh ribu kata sandi per menit, dan
 * "kos123" jebol dalam hitungan menit. Itu bukan skenario teoretis: alamat
 * aplikasi ini akan dipindai dalam hitungan jam setelah dipasang di internet.
 *
 * Tiga keputusan yang membedakannya dari sekadar `throttle:5,1`:
 *
 *   1. **Kuncinya email + IP, bukan IP saja.** Membatasi per IP menghukum
 *      seluruh kos yang berbagi satu alamat IP publik - dan di Indonesia itu
 *      keadaan biasa. Membatasi per email saja membuat penyerang bisa mengunci
 *      akun orang lain dengan sengaja salah memasukkan sandinya berulang kali
 *      (denial of service yang murah). Pasangan keduanya membatasi keduanya
 *      tanpa membuka yang lain.
 *
 *   2. **Dua lapis, bukan satu.** Lapis pertama ketat dan pendek (5 kali per
 *      menit untuk pasangan email+IP). Lapis kedua longgar dan panjang (20 kali
 *      per jam untuk satu email dari IP mana pun). Lapis pertama menghentikan
 *      penebakan cepat; lapis kedua menghentikan penebakan lambat yang
 *      sengaja diperlambat untuk menghindari lapis pertama.
 *
 *   3. **Yang dihitung hanya percobaan GAGAL.** Pengguna yang berhasil masuk
 *      tidak menghabiskan jatahnya, sehingga pemilik yang membuka aplikasi
 *      berkali-kali sehari tidak pernah terkunci. `hit()` dipanggil di jalur
 *      gagal, bukan di awal permintaan.
 */
class PembatasMasuk
{
    public const MAKS_PER_MENIT = 5;

    public const MAKS_PER_JAM = 20;

    /**
     * Hash tiruan untuk menyamakan waktu, dibuat sekali per proses.
     *
     * @see userTidakDikenal()
     */
    private static ?string $sandiTiruan = null;

    /**
     * Apakah permintaan ini harus ditolak lebih dulu.
     *
     * @return int detik tunggu, atau 0 bila boleh lanjut
     */
    public function tungguSebelum(string $email, string $ip): int
    {
        foreach ($this->kunci($email, $ip) as $kunci => $batas) {
            if (RateLimiter::tooManyAttempts($kunci, $batas)) {
                return RateLimiter::availableIn($kunci);
            }
        }

        return 0;
    }

    public function catatGagal(string $email, string $ip): void
    {
        foreach (array_keys($this->kunci($email, $ip)) as $kunci) {
            RateLimiter::hit($kunci, 3600);
        }
    }

    /**
     * Percobaan berhasil: bersihkan hitungan.
     *
     * Hanya lapis email+IP yang dibersihkan. Lapis per-email sengaja dibiarkan
     * sampai kedaluwarsa sendiri, karena ia dimaksudkan menghitung percobaan
     * gagal lintas IP - dan keberhasilan dari satu IP tidak membuktikan apa pun
     * tentang IP lain yang sedang menebak.
     */
    public function bersihkan(string $email, string $ip): void
    {
        RateLimiter::clear($this->kunciPasangan($email, $ip));
    }

    /**
     * @return array<string, int> kunci => batas
     */
    private function kunci(string $email, string $ip): array
    {
        return [
            $this->kunciPasangan($email, $ip) => self::MAKS_PER_MENIT,
            $this->kunciEmail($email) => self::MAKS_PER_JAM,
        ];
    }

    /**
     * Email dinormalkan sebelum dipakai sebagai kunci.
     *
     * Tanpa ini, `Budi@kos.test` dan `budi@kos.test` dihitung sebagai dua
     * penyerang berbeda, padahal keduanya menyerang akun yang sama - dan
     * penyerang cukup mengganti huruf besar-kecil untuk melipatgandakan
     * jatahnya.
     */
    private function kunciPasangan(string $email, string $ip): string
    {
        return 'masuk:'.Str::lower($email).'|'.$ip;
    }

    private function kunciEmail(string $email): string
    {
        return 'masuk-email:'.Str::lower($email);
    }

    /**
     * Kunci untuk permintaan tautan (lupa sandi, kirim ulang aktivasi).
     *
     * Lebih ketat daripada masuk, karena setiap permintaan mengirim email ke
     * alamat orang lain. Tanpa batas, endpoint ini menjadi alat untuk
     * membanjiri kotak masuk siapa pun - dan biaya pengirimannya ditanggung
     * pemilik kos.
     */
    public function bolehMintaTautan(string $email): bool
    {
        $kunci = 'tautan:'.Str::lower($email);

        if (RateLimiter::tooManyAttempts($kunci, 3)) {
            return false;
        }

        RateLimiter::hit($kunci, 3600);

        return true;
    }

    public function tungguTautan(string $email): int
    {
        return RateLimiter::availableIn('tautan:'.Str::lower($email));
    }

    /**
     * Selalu dijalankan, berapa pun hasilnya.
     *
     * Dipakai pada jalur "lupa sandi" untuk menghabiskan waktu yang sama
     * apakah emailnya terdaftar atau tidak. Tanpa ini, jawaban 200 untuk email
     * yang ada dan 200 untuk yang tidak ada tetap bisa dibedakan dari lamanya
     * respons - dan perbedaan waktu adalah cara paling mudah mengetahui alamat
     * mana yang terdaftar.
     *
     * Hash tiruannya **dibuat dengan biaya yang sedang dikonfigurasi**, bukan
     * angka yang ditulis di sini. Hash dengan cost 04 yang ditulis mati akan
     * memakan 1,5 ms di mesin pengembangan dan tetap 1,5 ms di produksi -
     * sementara pemeriksaan sandi sungguhan di sana memakai cost 12 dan
     * memakan 250 ms. Selisih 250 ms itu justru satu-satunya yang ingin
     * disembunyikan, dan hash yang ditulis mati menyembunyikannya hanya di
     * mesin yang tidak dipakai siapa pun.
     *
     * Dibuat sekali per proses, bukan setiap panggilan: `Hash::make` itu
     * sendiri satu putaran bcrypt, dan menjalankannya di sini akan menghitung
     * dua kali lipat.
     */
    public function userTidakDikenal(): void
    {
        self::$sandiTiruan ??= Hash::make('sandi-tiruan-untuk-menyamakan-waktu');

        Hash::check('satu', self::$sandiTiruan);
    }

    /**
     * Pemilik akun yang sedang masuk, untuk keperluan pesan.
     */
    public function pesanTunggu(int $detik): string
    {
        if ($detik < 60) {
            return 'Terlalu banyak percobaan masuk. Coba lagi dalam '.$detik.' detik.';
        }

        return 'Terlalu banyak percobaan masuk. Coba lagi dalam '
            .(int) ceil($detik / 60).' menit.';
    }
}
