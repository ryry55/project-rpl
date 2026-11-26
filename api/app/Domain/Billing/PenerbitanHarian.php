<?php

namespace App\Domain\Billing;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Penerbitan tagihan harian: satu perilaku, dua pemicu.
 *
 * Dipanggil oleh penjadwal container (`schedule:work`) dan oleh endpoint cron
 * di lingkungan yang penjadwalnya tidak hidup - Vercel menjalankan container
 * sebagai fungsi yang tidur, sehingga `schedule:work` tidak bisa diandalkan
 * di sana.
 *
 * Tanpa kelas ini, endpoint akan menyalin closure penjadwal, dan dua salinan
 * aturan "periode apa yang dipakai" akan menyimpang: satu memakai tanggal
 * Jakarta, yang lain memakai tanggal server.
 *
 * Kunci `Cache::lock` dipakai karena kedua pemicu bisa menyala berdekatan.
 * Perintahnya sendiri idempoten - tagihan bulan yang sama dilewati - tetapi
 * dua proses yang berjalan bersamaan tetap bisa menabrak indeks unik dan
 * menghasilkan galat yang tidak perlu.
 */
class PenerbitanHarian
{
    /** Nama kunci dan lama tahan: cukup untuk satu putaran penerbitan massal. */
    private const KUNCI = 'penerbitan-tagihan-harian';

    private const DETIK = 600;

    /**
     * Jalankan penerbitan untuk "hari ini" WIB.
     *
     * @return array{dijalankan: bool, keluaran: string}
     */
    public function jalankan(): array
    {
        $hariIni = now('Asia/Jakarta');

        $kunci = Cache::lock(self::KUNCI, self::DETIK);

        if (! $kunci->get()) {
            return ['dijalankan' => false, 'keluaran' => 'Penerbitan lain sedang berjalan.'];
        }

        try {
            Artisan::call('tagihan:terbitkan', [
                'periode' => $hariIni->toDateString(),
                '--hari-ini' => $hariIni->toDateString(),
            ]);

            return ['dijalankan' => true, 'keluaran' => trim(Artisan::output())];
        } finally {
            $kunci->release();
        }
    }
}
