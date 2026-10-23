<?php

namespace App\Domain\Notifikasi;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Siapa yang diberi tahu tentang sebuah peristiwa (T25).
 *
 * Dikumpulkan di satu kelas karena aturannya sama di semua tempat, dan aturan
 * yang tersebar akan berbeda-beda. Tiga aturan yang berlaku:
 *
 *   1. **Pelaku tidak diberi tahu tentang perbuatannya sendiri.** Pemilik yang
 *      baru saja mengubah tarif tidak perlu membaca "tarif diubah" satu detik
 *      kemudian. Notifikasi untuk diri sendiri adalah kebisingan yang membuat
 *      notifikasi orang lain ikut diabaikan.
 *
 *   2. **Pemilik selalu menerima hal yang mengubah uang**, karena dialah yang
 *      menanggung akibatnya bila salah.
 *
 *   3. **Penghuni hanya menerima hal yang menyangkut dirinya.** Ia tidak perlu
 *      tahu bahwa tarif kamar sebelah naik.
 */
final class Penerima
{
    /**
     * Semua akun yang mengurus kos: pemilik dan pengelola.
     *
     * @return Collection<int, User>
     */
    public static function pengurus(?User $kecuali = null): Collection
    {
        return User::query()
            ->whereIn('peran', [User::PERAN_PEMILIK, User::PERAN_PENGELOLA])
            ->when($kecuali !== null, fn ($q) => $q->whereKeyNot($kecuali->getKey()))
            ->orderBy('id')
            ->get();
    }

    /**
     * Pemilik saja.
     *
     * @return Collection<int, User>
     */
    public static function pemilik(?User $kecuali = null): Collection
    {
        return User::query()
            ->where('peran', User::PERAN_PEMILIK)
            ->when($kecuali !== null, fn ($q) => $q->whereKeyNot($kecuali->getKey()))
            ->orderBy('id')
            ->get();
    }

    /**
     * Akun milik seorang penghuni, bila penghuni itu memang punya akun.
     *
     * Penghuni yang datanya dicatat pemilik tetapi belum pernah dibuatkan akun
     * tidak punya kotak masuk. Mengembalikan koleksi kosong di sini lebih
     * jujur daripada mengarang penerima.
     *
     * @return Collection<int, User>
     */
    public static function akunPenghuni(?int $penghuniId): Collection
    {
        if ($penghuniId === null) {
            return collect();
        }

        return User::query()
            ->where('peran', User::PERAN_PENGHUNI)
            ->where('penghuni_id', $penghuniId)
            ->get();
    }
}
