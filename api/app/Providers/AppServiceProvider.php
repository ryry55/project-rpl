<?php

namespace App\Providers;

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
    }

    public function boot(): void
    {
        //
    }
}
