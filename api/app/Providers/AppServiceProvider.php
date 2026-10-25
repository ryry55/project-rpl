<?php

namespace App\Providers;

use App\Domain\Pembayaran\MidtransGateway;
use App\Support\WaktuSistem;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton, bukan binding biasa: middleware menulis tanggal simulasi ke
        // instans ini, dan seluruh lapisan di bawahnya harus membaca instans
        // yang sama. Dengan binding transien, controller akan mendapat objek
        // baru yang tidak tahu apa-apa soal simulasi.
        $this->app->singleton(WaktuSistem::class);

        // MidtransGateway dibangun dari konfigurasi, bukan di-autowire.
        // Konstruktornya menerima `?string, ?string, bool` - tiga nilai yang
        // tidak bisa diselesaikan container tanpa nama parameter yang cocok,
        // dan `bool $produksi` tidak punya tipe yang bisa ditebak. Tanpa
        // binding ini setiap penyelesaian kelas gagal dengan
        // "Unresolvable dependency".
        //
        // `bind`, bukan `singleton`: konfigurasinya bisa berubah di tengah
        // proses (tes menyetel kunci berbeda per kasus), dan gateway tidak
        // menyimpan keadaan apa pun yang perlu dibagi.
        $this->app->bind(MidtransGateway::class, fn () => MidtransGateway::dariKonfigurasi());
    }

    public function boot(): void
    {
        //
    }
}
