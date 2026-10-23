# Skrip uji

Dua puluh berkas di folder ini menguji aplikasi dari luar: delapan belas lewat
browser sungguhan (termasuk satu yang menyapu seluruh halaman dan satu yang
mengukur tata letak di lebar ponsel), dua menguji balapan HTTP, dan satu berkas
bersama yang dipakai semuanya.

Semuanya memakai **database demo** (`kos`), bukan `kos_test`. Kecuali dinyatakan
lain, tiap skrip **mengubah** isinya.

`asap-semua-halaman.mjs` adalah pengecualian: ia mencari dulu sebelum membuat,
jadi boleh dijalankan berulang tanpa `migrate:fresh` dan tidak menambah baris
saat dijalankan dua kali.

## Menjalankan semuanya

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1
```

Itu urutan yang benar: build image → tes PHP → pint → seluruh skrip di bawah,
dengan `migrate:fresh --seed` dan pembersihan tab Chrome sebelum masing-masing.
`-Cepat` melewati uji browser, `-TanpaBuild` melewati build image.

Menjalankan skrip satu per satu boleh, tapi tiga hal ini wajib dipenuhi lebih
dulu:

1. **Image sudah dibangun ulang** kalau kode berubah:
   `docker compose build api web && docker compose up -d api web`.
   Kode dibakar ke image, jadi tanpa ini skrip menguji kode lama.
2. **Chrome hidup** dengan `--remote-debugging-port=9222` (semua `e2e-*` dan
   `asap-*`). Uji balapan tidak butuh Chrome.
3. **Database baru di-seed**: `docker compose exec api php artisan migrate:fresh --seed`.
   Seeder hanya membuat user dan satu penghuni — **nol kamar, nol kontrak, dan
   sengaja nol kebijakan**. Kebijakan kos dibiarkan kosong supaya gerbang
   "wajib diisi dulu" benar-benar terlihat saat demo: tagihan pertama ditolak
   sampai pemilik menetapkan satuan sewa, jatuh tempo, dan denda. Skrip yang
   butuh kontrak membuatnya sendiri lewat API, lalu memenuhi ketiga tipe itu
   lewat `pastikanKebijakan()` di `_cdp.mjs`.
4. **Mailpit hidup** (`docker compose up -d mailpit`, UI di
   `http://localhost:8025`) untuk `e2e-t25`, `e2e-t27`, dan `e2e-t28`. Ketiganya
   membaca tautan aktivasi dan email pemberitahuan dari sana, bukan dari basis
   data - yang diuji adalah surat yang benar-benar terkirim, bukan baris tabel.
   Setiap skrip mencatat satu cap waktu saat mulai dan hanya menerima surat yang
   lebih baru daripadanya, sehingga surat sisa percobaan sebelumnya tidak pernah
   terbaca. `periksa.ps1` **tidak** mengosongkan Mailpit di antara skrip.

## Indeks

### Menyapu seluruh halaman

| Berkas | Yang dibuktikan | Butuh |
|---|---|---|
| `asap-semua-halaman.mjs` | Setiap halaman terbuka sebagai pemilik dan penghuni tanpa galat konsol dan tanpa HTTP 5xx; alamat tak dikenal menampilkan halaman 404, bukan layar kosong | Chrome |

Jalankan ini lebih dulu setelah perubahan besar. Ia menangkap halaman yang rusak
diam-diam — kelas kesalahan yang tidak terlihat oleh tes per tiket, karena tes
per tiket tidak pernah membuka halaman itu.

### Tata letak di layar sempit

| Berkas | Yang dibuktikan | Butuh |
|---|---|---|
| `uji-tata-letak.mjs` | Sepuluh halaman pemilik dan portal penghuni tidak meluber mendatar pada 360px, 390px, dan 768px; emulasi lebarnya diperiksa lebih dulu supaya asersinya tidak lulus tanpa mengukur | Chrome, seed |

Skrip ini ada karena seluruh uji lain memeriksa isi DOM dan jawaban HTTP, tidak
pernah ukuran. Halaman yang benar isinya tetapi lebih lebar daripada layar lolos
sepenuhnya: tidak ada galat konsol, tidak ada permintaan gagal, tidak ada teks
hilang — hanya halaman yang bergulir ke samping, dan tombol paling kanan yang
paling sering hilang dari pandangan.

Cacat yang mendorongnya ditulis: `.tata-letak` dan `.halaman` memakai
`grid-template-columns: 1fr`, yang berarti `minmax(auto, 1fr)`. Minimum `auto`
mengambil lebar min-content, sehingga sidebar dengan menu mendatar memaksa
halaman selebar 753px di layar 390px.

**`mobile: false`, bukan `mobile: true`.** Dengan `mobile: true` Chrome memakai
layout viewport bawaan ponsel (980px) alih-alih lebar yang diminta, lalu
memperkecil hasilnya. `window.innerWidth` menjadi 980 dan pemeriksaan "tidak ada
gulir mendatar" lulus tanpa menguji apa pun. Emulasi juga dilepas setiap kali
halaman baru dimuat, jadi harus dipasang ulang setelah `Page.navigate`.

### Alur bisnis per tiket

| Berkas | Yang dibuktikan | Butuh |
|---|---|---|
| `e2e-t1.mjs` | Login, token tersimpan, pengalihan peran, keluar membersihkan token | Chrome, seed |
| `e2e-t2.mjs` | Data induk lewat UI: 8 kamar, 6 penghuni, kontrak | Chrome, seed |
| `e2e-t3.mjs` | Terbit tagihan, rincian, prorata bulan masuk | Chrome, seed |
| `e2e-t4.mjs` | Terbit massal, penolakan duplikat periode, ringkasan tiga nasib | Chrome, seed |
| `e2e-t5.mjs` | Catat pembayaran sebagian lalu lunas, kelebihan bayar ditolak | Chrome, seed |
| `e2e-t6.mjs` | Denda 0 → 15.000 → 450.000 lewat simulasi tanggal | Chrome, seed |
| `e2e-t7.mjs` | Empat kelompok umur tunggakan, konsisten dengan denda | Chrome, seed |
| `e2e-t9.mjs` | Isolasi portal penghuni (404 bukan 403), log audit hanya-baca | Chrome, seed |
| `e2e-t18.mjs` | Fasilitas: kelola katalog, centang per kamar, dan mencentang fasilitas **tidak** membuat komponen biaya maupun baris tagihan | Chrome, seed |
| `e2e-t19.mjs` | Aturan kos: kelola, sembunyikan lewat sakelar, dan aturan nonaktif **tidak ada sama sekali** di HTML portal penghuni | Chrome, seed |
| `e2e-t20.mjs` | Metode bayar wajib (tanpa nilai bawaan), pemegang uang hanya untuk tunai, verifikasi pembayaran yang berlaku adalah baris terakhir, dan rekap yang **memisahkan denda sebagai piutang** alih-alih menambahkannya ke pendapatan | Chrome, seed |
| `e2e-t24.mjs` | Pengelola dan wewenang: pemilik membuat akun berwewenang terbatas, pengelola masuk dan bekerja sebatas itu, dan setiap penolakan diuji dengan **menembak API langsung** memakai token pengelola - bukan sekadar memeriksa tombolnya hilang | Chrome, seed |
| `e2e-t25.mjs` | Notifikasi: tiap peristiwa sampai ke kotak masuk penerima yang benar dan **tidak** ke pelakunya sendiri; mematikan satu kanal hanya mematikan kanal itu; email benar-benar terkirim (diperiksa lewat Mailpit) dan kegagalan kirim tidak membatalkan transaksinya | Chrome, seed, Mailpit |
| `e2e-t26.mjs` | Usulan perubahan: pengelola mengajukan tarif/kebijakan dan menerima **202** dengan `meta.diajukan`, bukan 201; pemilik menyetujui atau menolak; usulan yang kedaluwarsa tidak bisa diputuskan walau statusnya masih `menunggu` | Chrome, seed |
| `e2e-t27.mjs` | Aktivasi akun: formulir penghuni tidak punya kolom sandi, akun baru tidak bisa masuk sebelum tautannya dibuka, tautan mati setelah dipakai, lupa sandi menjawab sama untuk email terdaftar dan tidak, dan akun lama dipaksa mengganti sandi sebelum boleh bekerja | Chrome, seed, Mailpit |
| `e2e-t28.mjs` | UU PDP: gerbang persetujuan bisa ditolak, halaman Data pribadi menampilkan apa yang tercatat, penghapusan ditahan selama kontrak aktif, konfirmasi tertulis wajib, dan sesudah dilepas **tagihannya tetap utuh sedangkan namanya hilang** - termasuk dari jejak audit pemilik | Chrome, seed, Mailpit |

### Balapan

| Berkas | Yang dibuktikan | Butuh |
|---|---|---|
| `uji-balapan-t4.mjs` | Dua permintaan terbit serentak → satu 201, satu 409 | seed |
| `uji-balapan-t5.mjs` | Dua pembayaran serentak → satu 201, satu 422 yang menyebut sisa | seed |

Balapan tidak bisa diuji di dalam satu proses PHP: yang membuktikan `FOR UPDATE`
bekerja adalah pesan dari permintaan yang **kalah**, yang angkanya hanya bisa
muncul kalau ia menunggu transaksi pertama commit lalu membaca ulang.

### Berkas bersama

| Berkas | Isi |
|---|---|
| `_cdp.mjs` | Helper CDP: `mulaiUji`, `masukSebagai`, `panggilApi`, `teksMemuat`, `tangkapLayar`, `bersihkanTab`, `aktifkanAkun`, `tautanAkunTerakhir`, `panggilApiPublik`, `lewatiPersetujuanPdp` |

**Jangan menyalin helper CDP ke berkas baru.** Dua kesalahan berulang lahir
justru di bagian yang dulu disalin ke delapan berkas: regex `/15\\.000/` yang
berarti "backslash literal" (T5, T6, T9), dan nama pemilik dari seeder yang
ditulis tetap lalu gagal setiap kali data contoh berubah. Pakai
`teksMemuat(teks, '15.000')`.

### Pemeriksa profil dan rilis

Dua berkas shell, bukan `.mjs`, karena yang diperiksa bukan aplikasi yang
sedang berjalan melainkan berkas dan lingkungannya.

| Berkas | Yang diperiksa | Butuh |
|---|---|---|
| `periksa-profil-produksi.sh` | `docker-compose.prod.yml` benar-benar mematikan debug, mode demo, dan seeder; tidak ada `migrate:fresh`; tidak ada port tambahan; lapisan compose diterima dan menimpa nilai dasarnya | Docker (untuk memeriksa gabungan) |
| `smoke-produksi.sh` | Dijalankan SESUDAH deploy: `/api/health` 200 dengan database `ok`, akun demo tidak bisa masuk, `X-Simulasi-Dipakai` menjawab `false`, CORS tidak menjawab `*` | aplikasi yang hidup di URL mana pun |

Keduanya **dibuktikan bisa gagal**, bukan hanya bisa lulus. Dijalankan terhadap
profil demo, `smoke-produksi.sh` gagal pada enam pemeriksaan; terhadap
`docker-compose.prod.yml`, ia lulus. Pemeriksaan yang tidak pernah terbukti bisa
gagal bukan pemeriksaan - ia hanya upacara.

`periksa-profil-produksi.sh` tidak bisa menjadi tes PHPUnit: berkasnya berada di
akar repo, sedangkan image api hanya berisi direktori `api/`. Tes yang berjalan
di dalam container tidak akan pernah bisa membacanya.

## Tiga jebakan yang sudah memakan korban

**Kotak centang `v-model` butuh satu putaran di antaranya.** Dua event `change`
yang jatuh sebelum Vue sempat render ulang sama-sama membaca array nilai yang
**lama**, sehingga centang kedua menimpa yang pertama. Gejalanya sunyi: formulir
terkirim, tidak ada galat, dan hanya satu dari dua wewenang yang tersimpan.
Klik satu per satu dengan jeda ~120ms �?" itu juga yang dilakukan manusia, jadi
yang diuji tetap perilaku pemakaian sebenarnya.

**Backtick di dalam komentar pun memutus template literal.** Seluruh pemeriksaan
DOM dikirim sebagai template literal; satu backtick di komentar membuat
`node --check` gagal dengan pesan yang menunjuk baris yang salah. Jalankan
`node --check` pada setiap berkas `.mjs` baru sebelum menjalankannya.

**Gerbang PDP berdiri di depan setiap akun baru.** Sejak T28, akun yang baru
diaktifkan mendarat di `/persetujuan-pdp` sekali sebelum boleh memakai
aplikasi. Skrip yang menguji hal lain - isolasi tagihan, wewenang pengelola,
notifikasi - lalu gagal pada langkah pertamanya, dan pesannya terbaca seperti
kebocoran data atau tombol yang hilang. Pakai `lewatiPersetujuanPdp(ws)` tepat
sesudah `masukSebagai()` untuk akun yang baru dibuat; ia tidak melakukan apa pun
bila gerbangnya tidak muncul, jadi aman dipanggil pada akun yang sudah menyetujui.

## Variabel lingkungan

| Variabel | Bawaan |
|---|---|
| `CDP_BASE` | `http://127.0.0.1:9222` |
| `APP_URL` | `http://localhost:3000` |
| `API_URL` | `http://localhost:8081/api` |

## Berkas lain di folder ini

- `audit-ketertelusuran.md` — peta aturan R-01..R-07 → implementasi → tes → status.
- `T*-*.png` — tangkapan layar bukti, dipakai di laporan dan di komentar issue.
- `screenshot-t1.cjs` — skrip lama berbasis Playwright dari T1; tidak dipakai lagi.

Skrip yang diawali `_` adalah perkakas sementara (migrasi, diagnosa) dan tidak
dijalankan sebagai bagian dari pemeriksaan rutin.
