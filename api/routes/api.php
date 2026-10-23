<?php

use App\Http\Controllers\Api\AturanController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DendaController;
use App\Http\Controllers\Api\FasilitasController;
use App\Http\Controllers\Api\KamarController;
use App\Http\Controllers\Api\KebijakanController;
use App\Http\Controllers\Api\KomponenBiayaController;
use App\Http\Controllers\Api\KontrakController;
use App\Http\Controllers\Api\PenghuniController;
use App\Http\Controllers\Api\PortalPenghuniController;
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

    // Portal penghuni (T9). Wajib didaftarkan SEBELUM `apiResource('penghuni')`,
    // kalau tidak `/penghuni/tagihan` ditangkap `GET /penghuni/{penghuni}` dan
    // "tagihan" diperlakukan sebagai id penghuni — route model binding lalu
    // mencari `penghuni.id = 'tagihan'` dan PostgreSQL menolaknya dengan
    // `22P02`. Sama kelasnya dengan `/tagihan/terbitkan` di T4.
    Route::middleware(PastikanPeran::class.':penghuni')->group(function () {
        Route::get('/penghuni/tagihan', [PortalPenghuniController::class, 'tagihan'])
            ->name('penghuni.tagihan');

        // Aturan kos (T19). Disaring `aktif` di server: aturan yang
        // dinonaktifkan pemilik tidak boleh pernah sampai ke peramban penghuni.
        Route::get('/penghuni/aturan', [PortalPenghuniController::class, 'aturan'])
            ->name('penghuni.aturan');
    });

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

        // --- Fasilitas (T18) ---------------------------------------------
        // Tidak append-only: tidak ada angka uang yang bergantung padanya.
        //
        // `parameters()` wajib: pembentukan otomatis memakai bentuk tunggal
        // bahasa Inggris, dan "fasilitas" menjadi `{fasilita}` - bukan
        // `{fasilitas}`. Akibatnya `$request->route('fasilitas')` selalu null
        // (sehingga nama sendiri dianggap duplikat saat update) dan model
        // binding menyuntik model kosong (sehingga DELETE menjawab 200 tanpa
        // menghapus apa pun). Dua kegagalan yang tidak menimbulkan galat.
        Route::apiResource('fasilitas', FasilitasController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->parameters(['fasilitas' => 'fasilitas']);
        // Mengganti seluruh daftar fasilitas satu kamar sekaligus. Memakai PUT
        // karena yang dikirim adalah keadaan akhir daftar centang, bukan satu
        // tambahan. Didaftarkan sebelum `apiResource('kamar')` yang lebih
        // spesifik supaya `{kamar}` tidak menangkap "fasilitas".
        Route::put('/kamar/{kamar}/fasilitas', [FasilitasController::class, 'pasangUntukKamar'])
            ->name('kamar.fasilitas');

        // --- Aturan kos (T19) --------------------------------------------
        // Bukan append-only: tidak ada uang yang bergantung padanya, jadi
        // memperbaiki salah ketik tidak merusak dokumen mana pun.
        Route::apiResource('aturan', AturanController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        // Kebijakan kos (T16). Append-only: hanya index dan store, tidak ada
        // update atau delete. Gerbangnya ada di POST /tagihan dan
        // POST /tagihan/terbitkan, bukan di sini.
        Route::get('/kebijakan', [KebijakanController::class, 'index'])->name('kebijakan.index');
        Route::post('/kebijakan', [KebijakanController::class, 'store'])->name('kebijakan.store');

        // --- Tagihan (T3) -------------------------------------------------
        Route::get('/tagihan', [TagihanController::class, 'index'])->name('tagihan.index');
        Route::post('/tagihan', [TagihanController::class, 'store'])->name('tagihan.store');

        // Terbit massal (T4). Wajib didaftarkan SEBELUM `/tagihan/{tagihan}`,
        // kalau tidak "terbitkan" akan ditangkap sebagai id tagihan dan
        // menghasilkan 404 dari route model binding.
        Route::post('/tagihan/terbitkan', [TagihanController::class, 'terbitkan'])
            ->name('tagihan.terbitkan');

        // Pembayaran (T5). Proses 4.0 di DFD: uang masuk, status berubah.
        Route::post('/tagihan/{tagihan}/pembayaran', [TagihanController::class, 'bayar'])
            ->name('tagihan.bayar');

        // --- Tunggakan per umur (T7) --------------------------------------
        Route::get('/tunggakan', [TunggakanController::class, 'index'])
            ->name('tunggakan.index');

        // --- Log audit (T9) ------------------------------------------------
        // Hanya baca. Tidak ada endpoint tulis: jejak ditulis PencatatAudit di
        // dalam transaksi perubahan, dan endpoint tulis akan memungkinkan
        // seseorang menambahkan jejak palsu.
        Route::get('/audit-log', [AuditLogController::class, 'index'])
            ->name('audit-log.index');
    });

    /*
    | Pintu bersama pemilik dan penghuni.
    |
    | `GET /tagihan/{id}` dan `/denda` terbuka untuk keduanya, tetapi isinya
    | disaring scope `Tagihan::terlihatOleh`: penghuni hanya melihat tagihannya
    | sendiri, dan id milik orang lain menghasilkan 404 — bukan 403, karena 403
    | membocorkan bahwa tagihan itu ada (R-07).
    */
    Route::get('/tagihan/{tagihan}', [TagihanController::class, 'show'])->name('tagihan.show');
    Route::get('/tagihan/{tagihan}/denda', [DendaController::class, 'show'])->name('tagihan.denda');
});
