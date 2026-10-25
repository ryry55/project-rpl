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
use App\Http\Controllers\Api\PembayaranGatewayController;
use App\Http\Controllers\Api\PengaturanNotifikasiController;
use App\Http\Controllers\Api\PengelolaController;
use App\Http\Controllers\Api\PenghuniController;
use App\Http\Controllers\Api\PortalPenghuniController;
use App\Http\Controllers\Api\RekeningController;
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

// Notifikasi payment gateway (T22). Tanpa `auth:sanctum` karena Midtrans bukan
// pengguna aplikasi ini - ia tidak punya akun, dan tidak akan pernah punya.
//
// Satu-satunya bukti bahwa permintaan ini sah adalah `signature_key` di
// badannya, yang dihitung dari `server_key` yang hanya diketahui kita dan
// Midtrans. Karena itu endpoint ini TIDAK boleh ditambahkan autentikasi lain
// yang "lebih aman": menambahkan token statis di URL akan membuat token itu
// ikut tercatat di log akses dan dashboard gateway, dan setelah itu ia bukan
// rahasia lagi.
//
// `throttle` sengaja longgar (120/menit): gateway bisa mengirim banyak
// notifikasi sekaligus saat tagihan massal dibayar beruntun, dan notifikasi
// yang ditolak karena batas akan diulang terus - memperpanjang kemacetan.
Route::post('/webhook/midtrans', [PembayaranGatewayController::class, 'webhook'])
    ->middleware('throttle:120,1')
    ->name('api.webhook.midtrans');

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

        // Gambar QRIS tujuan pembayaran (utang teknis yang ditutup).
        //
        // Didaftarkan DI LUAR ketiga grup peran, karena ketiganya butuh:
        // penghuni memindainya untuk membayar, pengelola perlu memeriksanya
        // saat menagih, pemilik mengunggahnya. Yang berbeda antar peran bukan
        // "boleh membaca atau tidak", melainkan rekening MANA yang boleh
        // dibaca - dan itu disaring di controller, bukan oleh middleware yang
        // hanya tahu peran.
        //
        // Dibaca lewat `GET /rekening/{rekening}/gambar`, bukan URL statis:
        // `public/` read-only dan Render free tidak punya disk persisten,
        // sehingga gambarnya tinggal di kolom `bytea` dan hanya bisa keluar
        // lewat rute ini.
        Route::get('/rekening/{rekening}/gambar', [RekeningController::class, 'gambar'])
            ->name('rekening.gambar');

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

            // Rekening tujuan pembayaran (REV-UI-05). Endpoint terpisah dari
            // `/rekening` karena dua alasan. Pertama, penghuni hanya boleh
            // melihat yang aktif - rekening lama yang sudah tidak dipakai tidak
            // boleh muncul, sebab penghuni yang mentransfer ke sana uangnya
            // hilang. Kedua, `/rekening` mengirim `meta.jenis` dan
            // `label_jenis` untuk layar pengelolaan, dan respons yang berbentuk
            // berbeda menurut peran adalah kelas kebocoran yang sulit terlihat.
            Route::get('/penghuni/rekening', [PortalPenghuniController::class, 'rekening'])
                ->name('penghuni.rekening');

            // Pembayaran daring (T22). Penghuni memulai pembayaran untuk
            // tagihannya sendiri. Diletakkan di sini, bukan di grup
            // `catat_pembayaran`, karena penghuni memang tidak punya wewenang
            // mencatat pembayaran - yang ia lakukan adalah MEMBUKA tautan
            // bayar, dan uangnya baru tercatat setelah webhook gateway
            // menyatakan settlement.
            //
            // Controller tetap memeriksa kepemilikan tagihan: rute ini hanya
            // menjaga peran, bukan kepemilikan baris.
            Route::post('/tagihan/{tagihan}/bayar-daring', [PembayaranGatewayController::class, 'mulai'])
                ->name('tagihan.bayar-daring');
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

            // Rekap pendapatan (T21). Sejak REV-SEC-06 hanya pemilik.
            //
            // Rekap adalah gambaran utuh keuangan kos: pendapatan per metode,
            // piutang denda, dan setoran tiap pemegang uang. Pengelola yang
            // membacanya mengetahui penghasilan pemiliknya tanpa pernah diminta,
            // dan itu bukan pengetahuan yang dibutuhkan untuk mencatat
            // pembayaran. Yang dibutuhkan pengelola adalah "berapa uang tunai
            // yang saya pegang", dan itu disediakan `/pembayaran/tunai-saya`
            // dengan cakupan yang jauh lebih sempit.
            //
            // `peran:pemilik`, bukan `wewenang:`: tidak ada wewenang yang bisa
            // diberikan untuk membuka rekap, karena rekap bukan tugas yang bisa
            // didelegasikan - ia adalah hak pemilik atas usahanya sendiri.
            Route::get('/rekap', [PembayaranController::class, 'rekap'])
                ->name('rekap.index');

            // Pemaafan denda (REV-FIN-07).
            //
            // `peran:pemilik`, bukan `wewenang:`: memaafkan denda berarti
            // melepaskan uang yang seharusnya diterima pemilik. Pengelola yang
            // boleh melakukannya bisa memaafkan denda temannya sendiri tanpa
            // ada yang mengetahui, dan tidak ada satu pun catatan operasional
            // yang membutuhkannya. Sama seperti `/rekap`, ini hak pemilik atas
            // usahanya sendiri, bukan tugas yang bisa didelegasikan.
            //
            // Berbeda dari tarif dan kebijakan: keduanya pengaturan yang
            // berlaku untuk semua penghuni, jadi pengelola yang menemukan
            // keadaan lapangan berhak mengusulkannya. Pemaafan menyangkut satu
            // tagihan satu orang, dan usulannya tidak akan lebih cepat daripada
            // pemilik mengerjakannya sendiri.
            Route::post('/tagihan/{tagihan}/maaf-denda', [TagihanController::class, 'maafkanDenda'])
                ->name('tagihan.maaf-denda');

            // Rekening tujuan pembayaran (REV-UI-05).
            //
            // `peran:pemilik`, bukan `wewenang:` — dan ini yang paling tegas di
            // antara semuanya. Nomor rekening adalah alamat uang masuk. Pengelola
            // yang boleh mengubahnya bisa mengarahkan seluruh setoran penghuni ke
            // rekeningnya sendiri, dan tidak ada satu pun pemeriksaan lain yang
            // menangkapnya: uangnya masuk, tagihannya ditandai lunas oleh orang
            // yang sama, dan tidak ada yang perlu disetujui siapa pun. Membaca
            // tetap dibuka untuk pengelola dan penghuni; yang ditutup hanya
            // menulis.
            Route::apiResource('rekening', RekeningController::class)
                ->only(['store', 'update', 'destroy']);
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

            // Rekening tujuan pembayaran (REV-UI-05). Dibaca semua yang
            // mengurus kos: pengelola yang menagih perlu tahu ke mana penghuni
            // harus mentransfer, dan itu pertanyaan yang sama dengan yang
            // ditanyakan penghuni.
            Route::apiResource('rekening', RekeningController::class)->only(['index']);

            Route::get('/tarif', [TarifController::class, 'index'])->name('tarif.index');
            Route::get('/kamar/{kamar}/tarif-berlaku', [TarifController::class, 'berlaku'])
                ->name('tarif.berlaku');

            Route::get('/komponen-biaya', [KomponenBiayaController::class, 'index'])
                ->name('komponen.index');

            Route::get('/kebijakan', [KebijakanController::class, 'index'])->name('kebijakan.index');

            Route::get('/tagihan', [TagihanController::class, 'index'])->name('tagihan.index');
            Route::get('/tunggakan', [TunggakanController::class, 'index'])
                ->name('tunggakan.index');

            // Daftar pembayaran (T20). Terbuka untuk semua yang mengurus kos,
            // sama seperti daftar tagihan: pengelola yang mencatat pembayaran
            // memang perlu memeriksa catatannya sendiri, dan tanpa daftar ini
            // ia tidak bisa menemukan baris yang perlu diperiksa.
            Route::get('/pembayaran', [PembayaranController::class, 'index'])
                ->name('pembayaran.index');
            // Daftar pemegang uang tunai yang mungkin. Didaftarkan SEBELUM
            // `/pembayaran/{pembayaran}/verifikasi` bukan karena bertabrakan - beda
            // metode HTTP - melainkan supaya letaknya berdekatan dengan pemakainya.
            Route::get('/pembayaran/penerima', [PembayaranController::class, 'penerima'])
                ->name('pembayaran.penerima');
            // Uang tunai milik pemanggil sendiri (REV-SEC-06). Pengganti sempit
            // untuk pengelola setelah `/rekap` ditutup: yang dibuka bukan rekap
            // yang dipersempit, melainkan pertanyaan yang memang miliknya.
            Route::get('/pembayaran/tunai-saya', [PembayaranController::class, 'tunaiSaya'])
                ->name('pembayaran.tunai-saya');

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

            // Pembatalan tagihan (REV-FIN-02).
            //
            // Wewenangnya sama dengan menerbitkan, karena membatalkan adalah
            // kebalikan langsung dari menerbitkan: orang yang boleh membuat
            // tagihan yang salah harus boleh membereskannya. Memberi wewenang
            // terpisah hanya untuk itu akan membuat pengelola yang menemukan
            // salah terbit menunggu pemilik, sementara periodenya tetap terkunci.
            Route::post('/tagihan/{tagihan}/batal', [TagihanController::class, 'batal'])
                ->name('tagihan.batal');
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
        //
        // Sejak REV-FIN-03 pemisahan itu juga berlaku untuk akun yang sama:
        // pengelola yang mencatat sebuah pembayaran tidak boleh memeriksanya
        // sendiri. Tanpa batas itu, "pemisahan wewenang" hanya berlaku bagi
        // pengelola yang kebetulan tidak punya wewenang kedua.
        Route::middleware(PastikanWewenang::class.':verifikasi_pembayaran')->group(function () {
            Route::post('/pembayaran/{pembayaran}/verifikasi', [PembayaranController::class, 'verifikasi'])
                ->name('pembayaran.verifikasi');

            // Menarik kembali pembayaran yang salah catat (REV-FIN-01).
            //
            // Wewenangnya sama dengan memeriksa, karena keduanya sama-sama
            // tindakan koreksi atas uang yang sudah tercatat - dan orang yang
            // menemukan angkanya tidak cocok justru orang yang memeriksa. Yang
            // membedakan hanya akibatnya: pemeriksaan menempelkan label,
            // pembalikan mengubah angka. Karena itu endpointnya terpisah.
            Route::post('/pembayaran/{pembayaran}/pembalik', [PembayaranController::class, 'balik'])
                ->name('pembayaran.pembalik');
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
