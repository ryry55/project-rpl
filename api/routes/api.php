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
use App\Http\Controllers\Api\PengelolaController;
use App\Http\Controllers\Api\PenghuniController;
use App\Http\Controllers\Api\PortalPenghuniController;
use App\Http\Controllers\Api\TagihanController;
use App\Http\Controllers\Api\TarifController;
use App\Http\Controllers\Api\TunggakanController;
use App\Http\Middleware\PastikanPeran;
use App\Http\Middleware\PastikanWewenang;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API - Sistem Informasi Operasional Kos-Kosan
|--------------------------------------------------------------------------
|
| Semua respons sukses berbentuk { "data": ... }, semua error berbentuk
| { "message": "...", "errors": {...} }. Konvensi ini ditetapkan di T1 dan
| diikuti seluruh tiket berikutnya (SPEC A7).
|
| Sejak T24 ada tiga peran: pemilik, pengelola, penghuni.
|
|   `peran:...`    memeriksa JENIS AKUN. Dipakai untuk endpoint yang memang
|                  tidak mungkin didelegasikan, dan untuk MEMBACA.
|   `wewenang:...` memeriksa IZIN untuk MENGUBAH. Pemilik selalu lolos,
|                  pengelola lolos bila wewenangnya tercatat, penghuni 403.
|
| Kenapa membaca dipisahkan dari mengubah:
|
|   Layar Tagihan perlu membaca daftar kamar dan kontrak untuk mengisi
|   pilihan. Kalau membaca kamar menuntut wewenang `kelola_kamar`, pengelola
|   yang tugasnya hanya menerbitkan tagihan akan melihat daftar kosong dan
|   tidak bisa bekerja - padahal ia tidak sedang mengubah apa pun. Wewenang
|   membatasi TINDAKAN, bukan PENGETAHUAN; membatasi pengetahuan tidak
|   melindungi apa pun di sini, karena orang yang mencatat pembayaran memang
|   harus melihat nama penghuni dan nomor kamarnya.
|
|   Pengecualiannya jejak audit: isinya bukan data kos, melainkan catatan
|   siapa mengubah apa. Itu diberikan tersendiri lewat `baca_audit`.
|
| Yang tetap `peran:pemilik` hanya akun dan izin itu sendiri. Siapa pun yang
| boleh memberi wewenang pada dasarnya boleh memberi dirinya wewenang apa pun,
| jadi pemberian wewenang tidak bisa didelegasikan.
|
*/

Route::post('/login', [AuthController::class, 'login'])->name('api.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');

    // Portal penghuni (T9). Wajib didaftarkan SEBELUM `apiResource('penghuni')`,
    // kalau tidak `/penghuni/tagihan` ditangkap `GET /penghuni/{penghuni}` dan
    // "tagihan" diperlakukan sebagai id penghuni - route model binding lalu
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

    // --- Akun dan izin: hanya pemilik ----------------------------------
    Route::middleware(PastikanPeran::class.':pemilik')->group(function () {
        Route::get('/pemilik/saja', fn () => response()->json([
            'data' => ['pesan' => 'Hanya pemilik yang bisa membaca ini.'],
        ]))->name('api.pemilik-saja');

        Route::apiResource('pengelola', PengelolaController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy']);
    });

    // --- Membaca data kos: pemilik dan pengelola ----------------------
    //
    // Rute baca didaftarkan lebih dulu supaya nama seperti `/tagihan/terbitkan`
    // dan `/penghuni/tagihan` sudah tertangkap sebelum `{tagihan}` dan
    // `{penghuni}`.
    Route::middleware(PastikanPeran::class.':pemilik,pengelola')->group(function () {
        Route::apiResource('kamar', KamarController::class)->only(['index', 'show']);
        Route::apiResource('penghuni', PenghuniController::class)->only(['index', 'show']);
        Route::apiResource('kontrak', KontrakController::class)->only(['index', 'show']);
        Route::apiResource('fasilitas', FasilitasController::class)
            ->only(['index'])
            ->parameters(['fasilitas' => 'fasilitas']);
        Route::apiResource('aturan', AturanController::class)->only(['index']);

        Route::get('/tarif', [TarifController::class, 'index'])->name('tarif.index');
        Route::get('/kamar/{kamar}/tarif-berlaku', [TarifController::class, 'berlaku'])
            ->name('tarif.berlaku');

        Route::get('/komponen-biaya', [KomponenBiayaController::class, 'index'])
            ->name('komponen.index');

        Route::get('/kebijakan', [KebijakanController::class, 'index'])->name('kebijakan.index');

        Route::get('/tagihan', [TagihanController::class, 'index'])->name('tagihan.index');
        Route::get('/tunggakan', [TunggakanController::class, 'index'])
            ->name('tunggakan.index');
    });

    // --- Mengubah data kos: wewenang ----------------------------------

    Route::middleware(PastikanWewenang::class.':kelola_kamar')->group(function () {
        Route::apiResource('kamar', KamarController::class)->only(['store', 'update']);

        // Fasilitas (T18) - tidak append-only: tidak ada angka uang yang
        // bergantung padanya.
        //
        // `parameters()` wajib: pembentukan otomatis memakai bentuk tunggal
        // bahasa Inggris, dan "fasilitas" menjadi `{fasilita}` - bukan
        // `{fasilitas}`. Akibatnya `$request->route('fasilitas')` selalu null
        // (sehingga nama sendiri dianggap duplikat saat update) dan model
        // binding menyuntik model kosong (sehingga DELETE menjawab 200 tanpa
        // menghapus apa pun). Dua kegagalan yang tidak menimbulkan galat.
        Route::apiResource('fasilitas', FasilitasController::class)
            ->only(['store', 'update', 'destroy'])
            ->parameters(['fasilitas' => 'fasilitas']);
        // Mengganti seluruh daftar fasilitas satu kamar sekaligus. Memakai PUT
        // karena yang dikirim adalah keadaan akhir daftar centang, bukan satu
        // tambahan.
        Route::put('/kamar/{kamar}/fasilitas', [FasilitasController::class, 'pasangUntukKamar'])
            ->name('kamar.fasilitas');

        // Aturan kos (T19) ikut wewenang kelola kamar: aturan adalah keterangan
        // tentang kos, bukan angka uang, dan orang yang mengurus kamar memang
        // yang tahu aturannya.
        Route::apiResource('aturan', AturanController::class)
            ->only(['store', 'update', 'destroy']);
    });

    Route::middleware(PastikanWewenang::class.':kelola_penghuni')->group(function () {
        Route::apiResource('penghuni', PenghuniController::class)->only(['store', 'update']);

        Route::apiResource('kontrak', KontrakController::class)->only(['store']);
        Route::post('/kontrak/{kontrak}/akhiri', [KontrakController::class, 'akhiri'])
            ->name('kontrak.akhiri');
    });

    Route::middleware(PastikanWewenang::class.':ubah_tarif')->group(function () {
        // Tarif: append-only. Tidak ada update, tidak ada delete.
        Route::post('/tarif', [TarifController::class, 'store'])->name('tarif.store');

        Route::post('/komponen-biaya', [KomponenBiayaController::class, 'store'])
            ->name('komponen.store');
    });

    Route::middleware(PastikanWewenang::class.':ubah_kebijakan')->group(function () {
        // Kebijakan kos (T16). Append-only: hanya index dan store, tidak ada
        // update atau delete. Gerbangnya ada di POST /tagihan dan
        // POST /tagihan/terbitkan, bukan di sini.
        Route::post('/kebijakan', [KebijakanController::class, 'store'])->name('kebijakan.store');
    });

    Route::middleware(PastikanWewenang::class.':terbitkan_tagihan')->group(function () {
        Route::post('/tagihan', [TagihanController::class, 'store'])->name('tagihan.store');

        // Terbit massal (T4). Wajib didaftarkan SEBELUM `/tagihan/{tagihan}`,
        // kalau tidak "terbitkan" akan ditangkap sebagai id tagihan dan
        // menghasilkan 404 dari route model binding.
        Route::post('/tagihan/terbitkan', [TagihanController::class, 'terbitkan'])
            ->name('tagihan.terbitkan');
    });

    Route::middleware(PastikanWewenang::class.':catat_pembayaran')->group(function () {
        // Pembayaran (T5). Proses 4.0 di DFD: uang masuk, status berubah.
        Route::post('/tagihan/{tagihan}/pembayaran', [TagihanController::class, 'bayar'])
            ->name('tagihan.bayar');
    });

    Route::middleware(PastikanWewenang::class.':baca_audit')->group(function () {
        // Log audit (T9). Hanya baca. Tidak ada endpoint tulis: jejak ditulis
        // PencatatAudit di dalam transaksi perubahan, dan endpoint tulis akan
        // memungkinkan seseorang menambahkan jejak palsu.
        Route::get('/audit-log', [AuditLogController::class, 'index'])
            ->name('audit-log.index');
    });

    /*
    | Pintu bersama pemilik, pengelola, dan penghuni.
    |
    | `GET /tagihan/{id}` dan `/denda` terbuka untuk ketiganya, tetapi isinya
    | disaring scope `Tagihan::terlihatOleh`: penghuni hanya melihat tagihannya
    | sendiri, dan id milik orang lain menghasilkan 404 - bukan 403, karena 403
    | membocorkan bahwa tagihan itu ada (R-07).
    */
    Route::get('/tagihan/{tagihan}', [TagihanController::class, 'show'])->name('tagihan.show');
    Route::get('/tagihan/{tagihan}/denda', [DendaController::class, 'show'])->name('tagihan.denda');
});
