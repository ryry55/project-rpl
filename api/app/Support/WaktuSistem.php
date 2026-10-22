<?php

namespace App\Support;

use App\Http\Middleware\SimulasiTanggal;
use Carbon\CarbonImmutable;

/**
 * "Hari ini" menurut sistem — satu-satunya tempat waktu sekarang dibaca.
 *
 * Kenapa tidak memanggil `now()` langsung di tempat yang butuh? Karena denda
 * bergantung pada "hari ini", dan satu-satunya cara mendemokan denda 37 hari
 * dalam sidang 15 menit adalah menggeser "hari ini". Kalau `now()` tersebar di
 * banyak tempat, tidak ada satu titik pun yang bisa digeser.
 *
 * Simulasi tanggal **hanya** aktif bila `APP_DEMO_MODE=true`. Di mode normal,
 * header diabaikan sepenuhnya dan `hariIni()` selalu waktu sebenarnya — jadi
 * tidak ada jalan bagi klien untuk memundurkan tanggal dan menghapus denda.
 *
 * Instans ini singleton dan di-reset di awal setiap permintaan oleh middleware
 * {@see SimulasiTanggal}. Tanpa reset, tanggal simulasi
 * dari satu permintaan akan bocor ke permintaan berikutnya.
 */
class WaktuSistem
{
    private ?CarbonImmutable $simulasi = null;

    public function reset(): void
    {
        $this->simulasi = null;
    }

    /**
     * Coba pakai tanggal simulasi dari header.
     *
     * Mengembalikan `false` bila header tidak dipakai — baik karena mode demo
     * mati, maupun karena formatnya tidak sah. Pemanggil yang menentukan mana
     * dari keduanya yang perlu dilaporkan ke pengguna.
     */
    public function pakaiTanggalSimulasi(?string $tanggal): bool
    {
        if (trim((string) $tanggal) === '') {
            return false;
        }

        // Di luar mode demo, header tidak pernah berlaku. Bukan error — hanya
        // diabaikan, supaya klien yang sama bisa dipakai di mode normal.
        if (! config('app.demo_mode')) {
            return false;
        }

        $teks = trim((string) $tanggal);

        // Format ketat: hanya YYYY-MM-DD. `strtotime` menerima "besok" dan
        // "next monday", dan menerima teks bebas di jalur yang seharusnya
        // hanya menerima tanggal pasti adalah cara mudah memasukkan bug.
        //
        // Carbon 3 **melempar** exception untuk masukan yang tidak bisa diurai,
        // bukan mengembalikan null seperti Carbon 2. Tanpa try/catch di sini,
        // salah ketik tanggal di tengah demo berubah menjadi 500 — dan itu
        // justru yang paling tidak boleh terjadi saat presentasi.
        try {
            $hasil = CarbonImmutable::createFromFormat('!Y-m-d', $teks);
        } catch (\Throwable) {
            return false;
        }

        if ($hasil === null || $hasil->format('Y-m-d') !== $teks) {
            return false;
        }

        $this->simulasi = $hasil->startOfDay();

        return true;
    }

    /**
     * Apakah permintaan ini memakai tanggal simulasi.
     */
    public function sedangSimulasi(): bool
    {
        return $this->simulasi !== null;
    }

    /**
     * Hari ini, sebagai tanggal murni tanpa jam.
     *
     * Selalu tanggal murni karena semua perbandingan umur tunggakan adalah
     * selisih hari kalender. Membandingkan datetime membuat tagihan yang jatuh
     * tempo hari ini pukul 00.00 dianggap terlambat beberapa jam.
     */
    public function hariIni(): CarbonImmutable
    {
        return $this->simulasi ?? CarbonImmutable::now()->startOfDay();
    }

    /**
     * Selisih hari kalender antara dua tanggal, bertanda.
     *
     * Positif berarti `$sampai` setelah `$awal`. Dihitung dari selisih epoch
     * dibagi 86.400, bukan lewat `diffInDays`, karena arti tanda pada
     * `diffInDays` berbeda antar versi Carbon — dan salah tanda di sini membuat
     * denda muncul untuk tagihan yang belum jatuh tempo.
     *
     * Pembagian ini eksak: zona waktu aplikasi Asia/Jakarta tidak mengenal
     * musim panas, jadi tidak ada hari yang panjangnya 23 atau 25 jam. Karena
     * itu `intdiv` cukup — tidak perlu pembulatan float sama sekali.
     */
    public function selisihHari(CarbonImmutable $awal, CarbonImmutable $sampai): int
    {
        $detik = $sampai->startOfDay()->getTimestamp() - $awal->startOfDay()->getTimestamp();

        return intdiv($detik, 86_400);
    }
}
