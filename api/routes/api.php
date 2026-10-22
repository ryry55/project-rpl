<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DendaController;
use App\Http\Controllers\Api\KamarController;
use App\Http\Controllers\Api\KomponenBiayaController;
use App\Http\Controllers\Api\KontrakController;
use App\Http\Controllers\Api\PenghuniController;
use App\Http\Controllers\Api\ParameterDendaController;
use App\Http\Controllers\Api\TagihanController;
use App\Http\Controllers\Api\TarifController;
use App\Http\Controllers\Api\TunggakanController;
use App\Http\Middleware\PastikanPeran;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API — Sistem Informasi Operasional Kos-Kosan
|--------------------------------------------------------------------------
|
| Semua respons sukses berbentuk { "data": ... }, semua error berbentuk
| { "message": "...", "errors": {...} }. Konvensi ini ditetapkan di T1 dan
| diikuti seluruh tiket berikutnya (SPEC §7).
|
| Seluruh data induk hanya boleh disentuh pemilik. Penghuni hanya membaca
| data miliknya sendiri (R-07, dibangun di T9).
|
*/

Route::post('/login', [AuthController::class, 'login'])->name('api.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');

    Route::middleware(PastikanPeran::class.':pemilik')->group(function () {
        Route::get('/pemilik/saja', fn () => response()->json([
            'data' => ['pesan' => 'Hanya pemilik yang bisa membaca ini.'],
        ]))->name('api.pemilik-saja');

        // --- Data induk (T2) ---------------------------------------------
        Route::apiResource('kamar', KamarController::class)
            ->only(['index', 'store', 'show', 'update']);

        Route::apiResource('penghuni', PenghuniController::class)
            ->only(['index', 'store', 'show', 'update']);

        Route::apiResource('kontrak', KontrakController::class)
            ->only(['index', 'store', 'show']);
        Route::post('/kontrak/{kontrak}/akhiri', [KontrakController::class, 'akhiri'])
            ->name('kontrak.akhiri');

        // Tarif: append-only. Tidak ada update, tidak ada delete.
        Route::get('/tarif', [TarifController::class, 'index'])->name('tarif.index');
        Route::post('/tarif', [TarifController::class, 'store'])->name('tarif.store');
        Route::get('/kamar/{kamar}/tarif-berlaku', [TarifController::class, 'berlaku'])
            ->name('tarif.berlaku');

        Route::get('/komponen-biaya', [KomponenBiayaController::class, 'index'])
            ->name('komponen.index');
        Route::post('/komponen-biaya', [KomponenBiayaController::class, 'store'])
            ->name('komponen.store');

        Route::get('/parameter-denda', [ParameterDendaController::class, 'index'])
            ->name('parameter-denda.index');
        Route::get('/parameter-denda/berlaku', [ParameterDendaController::class, 'show'])
            ->name('parameter-denda.berlaku');
        Route::post('/parameter-denda', [ParameterDendaController::class, 'store'])
            ->name('parameter-denda.store');

        // --- Tagihan (T3) -------------------------------------------------
        Route::get('/tagihan', [TagihanController::class, 'index'])->name('tagihan.index');
        Route::post('/tagihan', [TagihanController::class, 'store'])->name('tagihan.store');

        // Terbit massal (T4). Wajib didaftarkan SEBELUM `/tagihan/{tagihan}`,
        // kalau tidak "terbitkan" akan ditangkap sebagai id tagihan dan
        // menghasilkan 404 dari route model binding.
        Route::post('/tagihan/terbitkan', [TagihanController::class, 'terbitkan'])
            ->name('tagihan.terbitkan');

        Route::get('/tagihan/{tagihan}', [TagihanController::class, 'show'])->name('tagihan.show');

        // Pembayaran (T5). Proses 4.0 di DFD: uang masuk, status berubah.
        Route::post('/tagihan/{tagihan}/pembayaran', [TagihanController::class, 'bayar'])
            ->name('tagihan.bayar');

        // --- Denda (T6) ---------------------------------------------------
        Route::get('/tagihan/{tagihan}/denda', [DendaController::class, 'show'])
            ->name('tagihan.denda');

        // --- Tunggakan per umur (T7) --------------------------------------
        Route::get('/tunggakan', [TunggakanController::class, 'index'])
            ->name('tunggakan.index');
    });
});
