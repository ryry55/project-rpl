<?php

use App\Http\Controllers\Api\AturanController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DendaController;
use App\Http\Controllers\Api\FasilitasController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\KamarController;
use App\Http\Controllers\Api\KebijakanController;
use App\Http\Controllers\Api\KomponenBiayaController;
use App\Http\Controllers\Api\KontrakController;
use App\Http\Controllers\Api\NotifikasiController;
use App\Http\Controllers\Api\PdpController;
use App\Http\Controllers\Api\PembayaranController;
use App\Http\Controllers\Api\PengaturanNotifikasiController;
use App\Http\Controllers\Api\PengelolaController;
use App\Http\Controllers\Api\PenghuniController;
use App\Http\Controllers\Api\PortalPenghuniController;
use App\Http\Controllers\Api\TagihanController;
use App\Http\Controllers\Api\TarifController;
use App\Http\Controllers\Api\TunggakanController;
use App\Http\Controllers\Api\UsulanController;
use App\Http\Middleware\PastikanPeran;
use App\Http\Middleware\PastikanWewenang;
use App\Http\Middleware\WajibGantiSandi;
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

// Health check publik (T29). Tanpa auth karena pemantau uptime tidak punya akun;
// isinya sengaja hanya status, tanpa nama tabel, versi, atau pesan galat.
Route::get('/health', HealthController::class)->name('api.health');

// Pemulihan akses (T27). Tanpa `auth:sanctum`: seluruh gunanya adalah untuk
// orang yang tidak bisa masuk. Ketiganya dibatasi `PembatasMasuk` di dalam
// controller, dan endpoint permintaan tautan dibatasi lebih ketat lagi karena
// setiap panggilan mengirim email ke alamat orang lain.
Route::post('/lupa-sandi', [AuthController::class, 'lupaSandi'])->name('api.lupa-sandi');
Route::post('/periksa-token', [AuthController::class, 'periksaToken'])->name('api.periksa-token');
Route::post('/atur-sandi', [AuthController::class, 'aturSandi'])->name('api.atur-sandi');

Route::middleware('auth:sanctum')->group(function () {
    // Gerbang sandi dan gerbang PDP. Keduanya sengaja berada DI LUAR
    // `WajibGantiSandi`: kalau keduanya ikut ditahan, tidak akan ada jalan
    // keluar dari penahanan itu - pengguna yang wajib mengganti sandi tidak
    // bisa memanggil endpoint untuk menggantinya.
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::post('/ganti-sandi', [AuthController::class, 'gantiSandi'])->name('api.ganti-sandi');
    Route::post('/persetujuan-pdp', [AuthController::class, 'setujuiPdp'])
        ->name('api.persetujuan-pdp');

    // Hak subjek data (T28). Selalu tentang akun pemanggil sendiri - tidak ada
    // parameter id, sehingga tidak ada pemeriksaan wewenang yang bisa lupa.
    Route::get('/pdp/data-saya', [PdpController::class, 'dataSaya'])->name('pdp.data-saya');
    Route::delete('/pdp/data-saya', [PdpController::class, 'hapusSaya'])->name('pdp.hapus-saya');

    // Notifikasi ikut dikecualikan dari penahanan: pesannya bisa menjelaskan
    // kenapa akun ini ditahan, dan kotak masuk yang tidak bisa dibuka akan
    // menyembunyikan penjelasan itu.
    Route::get('/notifikasi', [NotifikasiController::class, 'index'])->name('notifikasi.index');
    Route::post('/notifikasi/baca-semua', [NotifikasiController::class, 'tandaiSemuaDibaca'])
        ->name('notifikasi.baca-semua');
    Route::post('/notifikasi/{notifikasi}/baca', [NotifikasiController::class, 'tandaiDibaca'])
        ->name('notifikasi.baca');

    Route::get('/pengaturan-notifikasi', [PengaturanNotifikasiController::class, 'show'])
        ->name('pengaturan-notifikasi.show');
    Route::put('/pengaturan-notifikasi', [PengaturanNotifikasiController::class, 'update'])
        ->name('pengaturan-notifikasi.update');

    // Mulai di sini, akun yang wajib mengganti sandi ditahan (T27).
    Route::middleware(WajibGantiSandi::class)->group(function () {

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

            // Kirim ulang tautan aktivasi (T27). Endpoint tersendiri, bukan efek
            // samping PUT: kasus "email salah ketik" dan "email masuk spam"
            // terlalu sering terjadi untuk ditangani dengan mengubah lalu
            // menyimpan ulang seluruh formulir.
            Route::post('/pengelola/{pengelola}/kirim-ulang-aktivasi',
                [PengelolaController::class, 'kirimUlangAktivasi'])
                ->name('pengelola.kirim-ulang-aktivasi');

            // Keputusan atas usulan perubahan (T26).
            //
            // `peran:pemilik`, bukan `wewenang:ubah_tarif`. Inilah inti T26: akun
            // yang boleh MENGUSULKAN tidak boleh MEMUTUSKAN usulannya sendiri.
            // Memakai `wewenang:` di sini akan mengembalikan wewenang yang baru
            // saja dipisahkan - pengelola dengan `ubah_tarif` bisa menyetujui
            // usulannya sendiri, dan seluruh layar persetujuan menjadi formalitas.
            Route::post('/usulan/{usulan}/setujui', [UsulanController::class, 'setujui'])
                ->name('usulan.setujui');
            Route::post('/usulan/{usulan}/tolak', [UsulanController::class, 'tolak'])
                ->name('usulan.tolak');
        });

        // --- Kotak masuk dan pengaturan notifikasi (T25) -------------------
        //
        // Sudah didaftarkan di atas, di luar penahanan wajib-ganti-sandi.

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

            // Rekap dan daftar pembayaran (T20, T21). Terbuka untuk semua yang
            // mengurus kos, sama seperti daftar tagihan: membaca uang yang sudah
            // masuk bukan tindakan yang merugikan siapa pun, dan pengelola yang
            // mencatat pembayaran memang perlu memeriksa catatannya sendiri.
            Route::get('/pembayaran', [PembayaranController::class, 'index'])
                ->name('pembayaran.index');
            // Daftar pemegang uang tunai yang mungkin. Didaftarkan SEBELUM
            // `/pembayaran/{pembayaran}/verifikasi` bukan karena bertabrakan - beda
            // metode HTTP - melainkan supaya letaknya berdekatan dengan pemakainya.
            Route::get('/pembayaran/penerima', [PembayaranController::class, 'penerima'])
                ->name('pembayaran.penerima');
            Route::get('/rekap', [PembayaranController::class, 'rekap'])
                ->name('rekap.index');

            // Usulan perubahan (T26). Dibaca oleh pemilik dan pengelola - pengelola
            // perlu tahu nasib usulannya sendiri. Yang tidak boleh dibaca pengelola
            // hanyalah usulan rekan kerjanya, dan itu disaring di controller, bukan
            // oleh middleware: satu endpoint yang menyaring hasil lebih jujur
            // daripada dua endpoint yang isinya sama.
            //
            // Tidak ada `POST /usulan`. Pengajuan usulan memakai endpoint yang sama
            // dengan penetapan - `POST /tarif` dan `POST /kebijakan` - dan yang
            // berbeda hanya akibatnya menurut peran. Endpoint usulan tersendiri akan
            // membuat dua jalan menuju perubahan tarif, dan cepat atau lambat salah
            // satunya lupa memeriksa wewenang.
            Route::get('/usulan', [UsulanController::class, 'index'])->name('usulan.index');
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

            // Kirim ulang tautan aktivasi penghuni (T27). Padanan endpoint
            // pengelola, dan harus didaftarkan SEBELUM `apiResource` di atas
            // tidak berlaku - beda metode HTTP, jadi tidak bertabrakan. Tetap
            // diletakkan berdekatan dengan pemakainya.
            Route::post('/penghuni/{penghuni}/kirim-ulang-aktivasi',
                [PenghuniController::class, 'kirimUlangAktivasi'])
                ->name('penghuni.kirim-ulang-aktivasi');

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

        // Verifikasi pembayaran (T20) dipisahkan dari pencatatannya.
        //
        // Itu bukan kerapian: orang yang menerima uang tunai dan orang yang
        // memeriksa setorannya sebaiknya bukan satu akun, dan pemisahan wewenang
        // inilah satu-satunya cara pemilik kos yang tidak tinggal di lokasi bisa
        // memeriksa pekerjaan pengelolanya tanpa memeriksa catatan bank sendiri.
        // Akun yang punya keduanya tetap bisa melakukan keduanya - yang dicegah
        // adalah memberi satu wewenang lalu diam-diam mendapat yang lain.
        Route::middleware(PastikanWewenang::class.':verifikasi_pembayaran')->group(function () {
            Route::post('/pembayaran/{pembayaran}/verifikasi', [PembayaranController::class, 'verifikasi'])
                ->name('pembayaran.verifikasi');
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
});
