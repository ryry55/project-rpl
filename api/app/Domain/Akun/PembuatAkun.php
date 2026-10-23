<?php

namespace App\Domain\Akun;

use App\Models\Penghuni;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Membuat akun yang sandinya tidak diketahui siapa pun (T27).
 *
 * Sebelum ini, pemilik mengetik sandi penghuni dan pengelolanya. Dua akibat,
 * dan yang kedua lebih merusak daripada yang pertama:
 *
 *   1. Pemilik bisa masuk sebagai penghuni dan melihat - atau mengaku tidak
 *      pernah melihat - apa pun. Klaim isolasi R-07 menjadi tidak berarti,
 *      karena isolasi terhadap orang yang memegang sandinya bukan isolasi.
 *
 *   2. Pemilik bisa masuk sebagai pengelola, lalu mengubah tarif. Jejak audit
 *      akan menulis nama pengelola itu, dan tidak ada satu pun bukti yang bisa
 *      membantahnya. Seluruh gunanya memisahkan `ubah_tarif` dari pemilik
 *      hilang di titik itu.
 *
 * Sandi di sini adalah 64 karakter acak yang **tidak pernah dikembalikan,
 * dicatat, atau dikirim ke mana pun**. Ia hanya memenuhi kolom NOT NULL sampai
 * pemiliknya sendiri menetapkan sandi lewat tautan aktivasi. Tidak ada kode
 * yang bisa membacanya kembali, dan tidak ada yang perlu bisa.
 */
class PembuatAkun
{
    public function __construct(private readonly TautanAkun $tautan) {}

    /**
     * Buat akun penghuni dan kirim tautan aktivasi.
     *
     * Dipanggil di dalam transaksi pembuatan penghuni. Email dikirim setelah
     * transaksi selesai oleh pemanggil - lihat catatan di controller - karena
     * SMTP yang menggantung tidak boleh menahan transaksi data induk.
     */
    public function untukPenghuni(Penghuni $penghuni): User
    {
        return User::create([
            'name' => $penghuni->nama,
            'email' => $penghuni->email,
            'password' => $this->sandiAcak(),
            'peran' => User::PERAN_PENGHUNI,
            'penghuni_id' => $penghuni->id,
            // Belum aktif: tautan aktivasi belum dibuka, jadi belum ada yang
            // membuktikan alamat email ini benar-benar milik penghuni itu.
            'email_verified_at' => null,
        ]);
    }

    /**
     * Buat akun pengelola dan kirim tautan aktivasi.
     *
     * @param  list<string>  $wewenang
     */
    public function untukPengelola(string $nama, string $email, int $pemilikId): User
    {
        return User::create([
            'name' => $nama,
            'email' => $email,
            'password' => $this->sandiAcak(),
            'peran' => User::PERAN_PENGELOLA,
            'pemilik_id' => $pemilikId,
            'email_verified_at' => null,
        ]);
    }

    /**
     * Sandi acak yang tidak diketahui siapa pun, termasuk pemanggilnya.
     *
     * `Str::random` memakai `random_bytes`, bukan `rand`. Panjangnya 64 supaya
     * tidak ada gunanya menebak, dan tidak ada jalan pintas "sandi awal yang
     * mudah diingat" - sandi awal yang mudah diingat adalah sandi yang dipakai
     * berbulan-bulan karena tidak ada yang merasa perlu menggantinya.
     */
    public function sandiAcak(): string
    {
        return Hash::make(Str::random(64));
    }
}
