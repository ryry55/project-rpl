<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Jadwal berkala
|--------------------------------------------------------------------------
|
| Dua tugas yang sebelumnya hanya bisa dijalankan tangan. Keduanya sengaja
| memakai `withoutOverlapping`, karena tugas yang berjalan dua kali bersamaan
| pada data yang sama menghasilkan tagihan ganda - dan tagihan ganda tidak
| bisa dihapus, hanya dibatalkan.
|
| Penjadwalnya (`php artisan schedule:work`) dijalankan supervisord di
| container produksi. Di laptop `schedule:work` tidak berjalan otomatis; jalankan
| sendiri bila ingin menguji.
|
*/

/*
 * Terbitkan tagihan bulanan untuk semua kontrak aktif.
 *
 * Tanggal 1 pukul 00:10 WIB, bukan 00:00: pada tengah malam tepat, zona waktu
 * dan pergantian bulan masih bisa berbeda di antara server. Sepuluh menit
 * sesudahnya, "bulan ini" sudah tidak ambigu.
 *
 * Perintah `tagihan:terbitkan` WAJIB diberi periode, jadi yang dijadwalkan
 * adalah perintah shell kecil yang menyusun tanggalnya dari "hari ini" di
 * zona waktu Jakarta. Tanpa itu, jadwal tidak bisa memanggil perintahnya
 * sama sekali - dan kegagalan itu baru terlihat pada tanggal 1.
 *
 * Perintahnya idempoten: tagihan yang sudah ada untuk periode itu dilewati,
 * jadi menjalankannya dua kali tidak menggandakan tagihan.
 */
Schedule::call(function () {
    $periode = now('Asia/Jakarta')->startOfMonth()->toDateString();

    Artisan::call('tagihan:terbitkan', ['periode' => $periode]);
})
    ->name('terbitkan-tagihan-bulanan')
    ->monthlyOn(1, '00:10')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/jadwal-tagihan.log'));

/*
 * Backup harian ke disk lokal container.
 *
 * Peringatan yang harus dibaca: di Render free tidak ada disk persisten, jadi
 * backup ini HILANG saat container restart. Ia berguna untuk pemulihan cepat
 * dalam satu masa hidup container, bukan sebagai arsip. Arsip sungguhan harus
 * diunduh keluar (lihat docs/produk/8_OPERATIONS_SOP.md).
 *
 * Pukul 02:00 WIB, saat pemakaian paling sepi.
 */
Schedule::command('kos:backup')
    ->dailyAt('02:00')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/jadwal-backup.log'));

/*
 * Mulai kontrak yang tanggal masuknya sudah tiba (S1.9).
 *
 * Pukul 00:05, lebih awal daripada penerbitan tagihan tanggal 1 (00:10).
 * Urutannya penting: penerbitan hanya menyaring kontrak `aktif`, jadi kontrak
 * yang mulai tepat tanggal 1 harus sudah berubah status sebelum tagihan
 * diterbitkan - kalau tidak, penghuni baru tidak ditagih pada bulan pertamanya
 * dan baru muncul bulan berikutnya.
 */
Schedule::command('kontrak:mulai')
    ->dailyAt('00:05')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/jadwal-kontrak.log'));
