# Skrip uji

Tiga puluh skrip di folder ini menguji aplikasi dari luar: dua puluh tujuh
lewat browser sungguhan (termasuk satu yang menyapu seluruh halaman, satu yang
mengukur tata letak di lebar ponsel, satu yang mensimulasikan pemuatan gagal,
satu yang memakai ketiga peran bergantian, dan satu yang menjalankan axe-core), dua
menguji balapan HTTP, dan satu menguji jalur proxy. Ditambah satu berkas bersama
(`_cdp.mjs`) yang dipakai semuanya, jadi ada tiga puluh satu berkas `.mjs`.

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
| `uji-tata-letak.mjs` | Sepuluh halaman pemilik dan portal penghuni tidak meluber mendatar pada 360px, 390px, dan 768px, ditambah tiga halaman terlebar pada lanskap 640x360; navigasi bawah harus muncul di 360/390/768px dan hilang di 1280px; emulasi lebarnya diperiksa lebih dulu supaya asersinya tidak lulus tanpa mengukur | Chrome, seed |
| `uji-kartu-tabel.mjs` | Tabel 4+ kolom berubah menjadi kartu di 360px dan 390px: setiap sel berlabel sesuai `<th>`-nya, `thead` tidak terlihat, dan kartunya tidak meluber. Di layar lebar tabelnya kembali normal | Chrome, seed |
| `uji-pwa.mjs` | Manifest terbaca dan semua ikonnya tersaji; service worker terdaftar, aktif, dan mengendalikan halaman; **tidak ada respons `/api/` yang masuk cache** (R-07); aplikasi **benar-benar terbuka tanpa jaringan** dengan jaringan dimatikan di halaman *dan* service worker | Chrome, build produksi, seed |
| `uji-galat-muat.mjs` | Sepuluh layar yang memuat daftar: saat endpointnya dijawab 500, pesan galat tampil dan baris "Belum ada ..." **tidak** tampil. Kontrol negatif mencabut blokade dan memastikan galatnya hilang, supaya asersi tidak lulus karena halaman tidak pernah dimuat (REV-UI-04) | Chrome, seed |

`uji-kartu-tabel.mjs` menutup celah yang sengaja ditinggalkan
`uji-tata-letak.mjs`. Uji itu melewati elemen di dalam `.tabel-bungkus`, karena
bungkus itulah yang memang menggulir — sehingga tabel yang masih berupa tabel di
layar 360px **selalu lolos**. Ia tidak meluber; ia hanya harus digeser ke
samping. Dua asersi berbeda untuk dua cacat berbeda.

`uji-pwa.mjs` memeriksa pagar R-07 secara langsung: cache dikosongkan, satu
permintaan `/api` dibuat, lalu `caches.keys()` dibaca. Asersinya sudah
dibuktikan menahan — dengan blok pagar `/api` di `sw.js` dicabut sementara, uji
ini **gagal** dengan `bocor: /api/me`. Uji yang tidak pernah terbukti bisa gagal
bukan uji.

Bagian luringnya juga sudah dibuktikan bisa gagal, dan pembuktian itu
menemukan satu jebakan: `Network.emulateNetworkConditions` berlaku **per
target**, dan service worker adalah target tersendiri. Sebelum diperbaiki,
menghapus seluruh isi Cache Storage pun tetap membuat uji hijau — karena
`fetch()` di `sw.js` masih sampai ke server. Perbaikannya menumpang sesi target
`service_worker` dari koneksi ke target peramban (`Target.attachToTarget
{ flatten: true }`), dan melepas sesi itu mengembalikan jaringannya.

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
| `e2e-t1.mjs` | Login, token tersimpan, pengalihan peran, keluar membersihkan token; **token yang dicabut di server selagi sesi terbuka** mengeluarkan pengguna ke `/masuk` dengan pesan "Sesi Anda berakhir" dan `lanjut` berisi halaman yang terputus (REV-UI-01) | Chrome, seed |
| `e2e-t2.mjs` | Data induk lewat UI: 8 kamar, 6 penghuni, kontrak | Chrome, seed |
| `e2e-t3.mjs` | Terbit tagihan, rincian, prorata bulan masuk | Chrome, seed |
| `e2e-t4.mjs` | Terbit massal, penolakan duplikat periode, ringkasan tiga nasib; kendali halaman memotong 52 tagihan menjadi 50 + 2 sementara kepala halaman tetap menghitung seluruh 52 | Chrome, seed |
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
| `e2e-t28.mjs` | UU PDP: gerbang persetujuan bisa ditolak, halaman Data pribadi menampilkan apa yang tercatat, penghapusan ditahan selama kontrak aktif **maupun selama masih ada tagihan belum lunas**, konfirmasi tertulis wajib, dan sesudah dilepas **tagihannya tetap utuh sedangkan namanya hilang** - termasuk dari jejak audit pemilik | Chrome, seed, Mailpit |
| `e2e-r3.mjs` | R3: tagihan yang salah terbit bisa **dibatalkan tanpa dihapus** dan periodenya diterbitkan ulang, pemaafan denda membekukan denda tanpa menghapus jejaknya, tombol WhatsApp menyusun tautan `wa.me` dengan teks dari server, dan rekening tujuan pembayaran hanya bisa ditulis pemilik | Chrome, seed |
| `uji-pembalik.mjs` | Tombol **Tarik kembali** menulis baris **negatif** yang menunjuk baris aslinya; alasan kosong ditolak 422 dan formulirnya tetap terbuka; baris pembalik tampil sebagai angka negatif bertanda "ditarik kembali", bukan disembunyikan; sesudah ditarik tagihan kembali menunggak dan **tagihan yang tadinya buntu karena uangnya menempel kini bisa dibatalkan** | Chrome, seed |
| `uji-qris.mjs` | Medan unggah gambar hanya muncul untuk jenis QRIS; pratinjau tampil **sebelum** disimpan; sesudah tersimpan daftar menyebut gambarnya ada; penghuni melihat gambarnya **benar-benar termuat** (`naturalWidth` > 0, disajikan sebagai blob, bukan URL bertoken); gambar pada rekening transfer ditolak 422 dengan pesan yang bisa dibaca; penghuni tidak bisa membuka gambar rekening nonaktif (404) | Chrome, seed |
| `e2e-fase35.mjs` | Penanda penyiapan benar-benar dirender untuk pemilik; ekspor benar-benar terunduh sebagai berkas (diperiksa dari peristiwa unduhan CDP, bukan kode status); pengelola tidak melihat tombol ekspor rekap yang pasti ditolak server; halaman bantuan terbuka untuk kedua peran | Chrome, seed |
| `uji-tiga-peran.mjs` | Pemilik, pengelola, dan penghuni bergantian dalam **satu** sesi peramban. Yang dibuktikan: endpoint pemilik-saja menolak pengelola **dan** penghuni dengan **403** (bukan 401, bukan 404 - tiga kode itu tiga arti berbeda); portal penghuni menolak pemilik dan pengelola; pengelola tetap bisa membaca data kos; token peran sebelumnya tetap menjawab peran asalnya dan **tidak** mewarisi hak peran yang baru masuk; penghuni mendapat **404** (bukan 403) untuk tagihan penghuni lain, dan portalnya tidak memuat tagihan itu; sesudah ketiga peran bergantian, pemilik masuk lagi dan batasnya kembali utuh | Chrome, seed |
| `uji-aksesibilitas.mjs` | axe-core pada 21 halaman (publik, 15 layar pemilik, portal penghuni, dan halaman 404) **ditambah tiga keadaan yang baru muncul dari tindakan pengguna** - panel rincian, form catat pembayaran, dan pesan galat validasi - dengan aturan WCAG 2.0/2.1 level A/AA: nama yang terbaca pembaca layar, label medan, kontras teks, peran ARIA, judul, bahasa dokumen. Dua kontrol negatif ikut dijalankan: empat cacat yang disisipkan (tautan tanpa nama, gambar tanpa teks alternatif, medan tanpa label, teks kontras rendah) **wajib** terdeteksi, dan halaman wajib kembali bersih sesudah cacatnya dibuang | Chrome, seed, `npm ci` di `web/` (axe-core) |

### Balapan

| Berkas | Yang dibuktikan | Butuh |
|---|---|---|
| `uji-balapan-t4.mjs` | Dua permintaan terbit serentak → satu 201, satu 409 | seed |
| `uji-balapan-t5.mjs` | Dua pembayaran serentak → satu 201, satu 422 yang menyebut sisa | seed |

Balapan tidak bisa diuji di dalam satu proses PHP: yang membuktikan `FOR UPDATE`
bekerja adalah pesan dari permintaan yang **kalah**, yang angkanya hanya bisa
muncul kalau ia menunggu transaksi pertama commit lalu membaca ulang.

### Jalur proxy

| Berkas | Yang dibuktikan | Butuh |
|---|---|---|
| `uji-proxy.mjs` | Permintaan lewat port proxy (bawaan `:3000`) membawa IP klien yang benar, bukan IP proxy: pembatas laju tidak menghitung semua pengguna sebagai satu IP, dan `log_audit` mencatat IP pelaku. Dijalankan lewat proxy karena pada jalur langsung tidak ada yang menambahkan header `X-Forwarded-For` - cacat A1 lolos 748 tes PHP tanpa ketahuan | aplikasi hidup |

### Berkas bersama

| Berkas | Isi |
|---|---|
| `_cdp.mjs` | Helper CDP: `mulaiUji`, `masukSebagai`, `panggilApi`, `teksMemuat`, `tangkapLayar`, `bersihkanTab`, `aktifkanAkun`, `tautanAkunTerakhir`, `panggilApiPublik`, `lewatiPersetujuanPdp` |

### Uji fungsi murni (Vitest)

Sepuluh detik, tanpa browser, dan dijalankan setiap gate (`periksa.ps1`,
`periksa.sh`, `uji.yml`):

| Berkas | Yang dibuktikan |
|---|---|
| `web/src/format.test.js` | Rupiah bertitik ribuan tanpa desimal, nol tetap "Rp 0" sementara nilai kosong "—", persen berkoma, tanggal murni tidak bergeser sehari karena zona waktu, ringkasan galat validasi |
| `web/src/halaman.test.js` | Pemotong halaman: halaman kedua mulai dari baris ke-51, halaman terakhir berisi sisanya, halaman di luar rentang kosong (bukan mengulang halaman lain) |

Yang diuji sengaja fungsi murni, bukan komponen: layar sudah diperiksa oleh
skrip browser sungguhan, dan menambah render komponen di Node berarti dua
rangkian uji yang akan berbeda pendapat - alasan yang sama dengan penolakan
Playwright. Diverifikasi bisa gagal: `potong()` dibuat selalu mulai dari baris
pertama → 3 asersi merah.

### Uji beban (manual, bukan gate)

`docs/beban/uji-beban.mjs` mengukur `GET /api/tagihan` dengan 50 permintaan
bersamaan (500 total) dan gagal bila p95 melewati 1.000 ms atau ada jawaban
non-2xx. Letaknya di luar folder ini karena **tidak** dijalankan gate: ambang
waktu di runner bersama mengukur tetangga, bukan aplikasi, dan gerbang yang
merah acak berhenti dibaca. Ambangnya ditetapkan pemilik (K-10).

```powershell
node docs/beban/uji-beban.mjs          # LULUS / GAGAL
$env:AMBANG_P95='1'; node docs/beban/uji-beban.mjs   # kontrol negatif
```

### Kenapa `tungguSampai` menahan sebagian galat

`tungguSampai` menjalankan predikatnya berulang kali, dan predikat itu sering
menyentuh `document.body` tepat setelah `Page.navigate` — saat dokumen lama
sudah hilang dan dokumen baru belum punya `body`. Ketika itu ekspresinya
melempar `Cannot read properties of null (reading 'textContent')`.

Dulu galat itu diteruskan, sehingga seluruh skrip uji berhenti. Gejalanya
menipu: **lulus di laptop, gagal di CI**, karena runner lebih lambat dan
kalah balapan dengan navigasi. Yang salah bukan aplikasinya, melainkan
pemeriksanya — dan pesan galatnya menunjuk ke tempat yang salah.

Sekarang galat "dokumen belum siap" ditahan lalu dicoba lagi. Yang ditahan
hanya itu: salah tulis predikat (misalnya memanggil fungsi yang tidak ada)
tetap dilempar keluar seketika, supaya tidak berubah menjadi penantian
15 detik yang berakhir dengan pesan "tidak muncul".

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

### Pemeriksa kesesuaian dokumen

Empat berkas `.js` yang memeriksa **dokumen terhadap kode**, bukan aplikasi yang
sedang berjalan. Semuanya tidak butuh E2E, dan selesai dalam hitungan detik.

| Berkas | Yang diperiksa |
|---|---|
| `cek-angka-dokumen.js` | Angka yang tertulis di `KONTEKS_PROYEK.md`, `serah-terima.md`, dan `SPEC.md` dibandingkan dengan angka nyata: jumlah migrasi, model, berkas tes, dan endpoint. Potret rute disegarkan dari aplikasi tepat sebelum pemeriksaan ini, jadi yang dibandingkan adalah rute hidup - bukan potret yang bisa basi |
| `cek-cakupan-kontrak.js` | Setiap rute nyata muncul di **tepat satu** baris tabel `6_TOOLS_CONTRACTS.md`; tidak ada yang belum tercatat, tidak ada yang tercatat dua kali; judul `(N endpoint)` tiap bagian dan baris **Total** §9 cocok dengan isinya |
| `cek-erd-lrs.js` | `docs/erd/skema.json` masih cocok dengan basis data; setiap tabel punya bagian di `03-lrs.md`; setiap kunci asing muncul di tabel relasi LRS dengan tujuan yang sama; jumlah tabel/kunci asing yang ditulis cocok dengan yang dihitung; `manifest.json` hasil build cocok dengan skema; setiap SVG berjenjang yang dicatat manifest ada, berisi SVG, dan tidak terpotong; **PNG pasangan setiap SVG ada dan berukuran tepat 2x atribut width/height SVG-nya**; tingkat 2 dan 3 masing-masing memuat seluruh tabel domain tanpa ganda |
| `cek-dfd.js` | `periksaSemantik()` bersih: setiap aliran punya rute di tingkat tempat ia digambar, tidak ada rute yatim, setiap penyimpanan ditulis **dan** dibaca, setiap proses punya masukan dan keluaran, setiap entitas terhubung, dan aliran batas sistem seimbang antara level 0 dan level 1. Ditambah: keempat SVG DFD (Gane-Sarson dan Yourdon) **sama persis** dengan hasil penggambaran `dfd-data.js` sekarang, dan PNG pasangannya ada serta benar-benar PNG |

```powershell
node docs/uji/cek-angka-dokumen.js
node docs/uji/cek-cakupan-kontrak.js
node docs/uji/cek-erd-lrs.js
node docs/uji/cek-dfd.js
```

`cek-erd-lrs.js` adalah satu-satunya dari keempatnya yang **butuh container `db`
hidup**, karena ia membandingkan dokumen dengan basis data yang berjalan, bukan
dengan berkas. Itu disengaja: dokumen ERD/LRS yang dibandingkan dengan salinan
skema hanya membuktikan salinan itu konsisten dengan dirinya sendiri.

**Kenapa `cek-dfd.js` ada, padahal `build.js` sudah memeriksa.**
`docs/dfd/build.js` memang menolak menulis bila ada black hole, miracle, grey
hole, aliran tanpa rute, atau aliran batas yang tidak seimbang antar tingkat -
tetapi ia **tidak pernah dipanggil gerbang**. Sampai 29 Oktober 2026, satu-
satunya cara mengetahui DFD masih sehat adalah menjalankannya dengan tangan.
Akibatnya delapan temuan audit DFD berstatus "selesai" hanya bersandar pada
pembacaan manusia, dan regresi berikutnya tidak akan berbunyi. `cek-dfd.js`
juga membandingkan SVG yang di-commit dengan hasil penggambaran data sekarang,
sehingga menangkap kelas kesalahan yang dulu menghasilkan PDF dan PNG basi:
data diubah, gambar lupa dibangun.

**Kenapa ada.** Keduanya lahir dari kesalahan yang benar-benar terjadi pada
26 Oktober 2026: `KONTEKS_PROYEK.md` menyebut 71 endpoint padahal aplikasinya
sudah 85, dan tiga judul bagian di `6_TOOLS_CONTRACTS.md` salah jumlah. Angka
yang diketik tangan akan tertinggal; angka yang dibaca dari sumbernya tidak.

**Keempatnya dibuktikan bisa gagal**, bukan hanya bisa lulus.
Dengan satu angka di `KONTEKS_PROYEK.md` diubah kembali menjadi 71,
`cek-angka-dokumen.js` melaporkan `ADA ANGKA YANG BEDA`. Dengan judul bagian 5
dikembalikan ke `(16 endpoint)`, `cek-cakupan-kontrak.js` melaporkan
`bagian 5: 20  SALAH (tulis 16, harus 20)`. Dengan menghapus tabel `rekening`
dan kunci asing `pembayaran.pembalik_id` dari `skema.json`, `cek-erd-lrs.js`
melaporkan kolom-kolom `rekening.*` yang hilang dan keluar dengan kode 1.
Dengan menghapus satu rute dari `RUTE_LEVEL1` di `dfd-data.js`, `cek-dfd.js`
melaporkan `aliran batas I-1 ... tidak punya rute di DFD level 1`; dengan
membuang seluruh aliran yang membaca `D1`, ia melaporkan
`penyimpanan D1 tidak pernah dibaca (black hole)`; dan dengan satu byte
ditambahkan ke `01-dfd-konteks.svg`, ia melaporkan bahwa berkasnya
`BEDA dari hasil penggambaran data sekarang`. Keempatnya keluar dengan kode 1.

Keempatnya mencari akar repo dari lokasi berkasnya sendiri, bukan dari direktori
kerja, sehingga bisa dijalankan dari mana saja.

**Ketiganya sempat tidak pernah berjalan di CI, dan ketika berjalan pun tidak menahan.** Ditemukan 27 Oktober 2026: `periksa.sh --hanya-e2e` - satu-satunya cara CI memanggil gerbang - melewati bagian "Kesesuaian dokumen", dan job `lint` tidak memanggil pemeriksa itu sama sekali. Selama berbulan-bulan ketiga pagar ini hanya hidup di laptop; ia tampak terpasang karena tercantum di `periksa.ps1`.

Audit 28 Oktober 2026 menemukan cacat kedua yang lebih dalam: `cek-angka-dokumen.js` dan `cek-cakupan-kontrak.js` **selalu keluar dengan kode 0**, berapa pun temuannya, jadi `periksa.ps1` melaporkan `LULUS` untuk pemeriksaan yang mencetak `ADA ANGKA YANG BEDA`. Keduanya juga membaca potret rute yang tidak pernah dibuat ulang. Sekarang keduanya memakai `process.exitCode`, dan `periksa.ps1`, `periksa.sh`, serta job `uji` menyegarkan `docs/akademik/rute-api.json` dari `route:list` tepat sebelum memeriksanya. `cek-erd-lrs.js` tetap di job `uji` karena butuh container `db`.

**`cek-dfd.js` adalah kasus yang sama, ketiga kalinya.** Ditemukan 29 Oktober 2026: `periksaSemantik()` sudah lama ada dan sudah menolak menulis gambar yang cacat, tetapi tidak ada satu pun pemanggil di `periksa.ps1`, `periksa.sh`, maupun `uji.yml`. Karena itu delapan temuan audit DFD berstatus "selesai" hanya bersandar pada pembacaan manusia - pagar yang tidak pernah dipanggil terlihat persis seperti pagar yang terpasang. Sekarang ia langkah tersendiri di ketiga tempat, dengan kontrol negatif yang tercatat di atas.

### Bukti pagar Fase 0.5

Empat berkas yang **menyuntikkan cacatnya kembali** ke dalam container, menjalankan
tes yang seharusnya menangkapnya, lalu mengembalikan berkasnya. Semuanya
dijalankan manual - tidak ikut `periksa.ps1`/`periksa.sh`, karena setiap
jalannya mengubah kode di dalam container selama beberapa detik.

| Berkas | Cacat yang disuntikkan | Hasil |
|---|---|---|
| `uji-pagar-buat-pemilik.sh` | pagar pemilik kedua, unique email, `min:8`, penanda wajib-ganti-sandi | 4 dari 4 tertangkap |
| `uji-pagar-penyiapan.sh` | rekening nonaktif dihitung, kebijakan bulan depan, tarif tak diperiksa, penghuni dijadikan wajib | 4 dari 4 tertangkap |
| `uji-pagar-ekspor.sh` | pemisah koma, BOM dihapus, netralisasi rumus dicabut, angka negatif dikutip, rekap dibuka untuk pengelola, `no-store` dihapus | 6 dari 6 tertangkap |
| `uji-pagar-ekspor-bersih.sh` | blok ringkasan dikembalikan ke `tunggakan` | 2 tes gagal |

Jalankan dari dalam container:

```bash
docker compose cp docs/uji/uji-pagar-ekspor.sh api:/tmp/u.sh
docker compose exec -T api sh /tmp/u.sh
```

**Kenapa dijalankan manual, bukan di gate.** Skrip ini mengubah berkas kode di
dalam container. Menjalankannya otomatis berarti gate sesekali menguji kode yang
sedang disuntik - dan bila salah satu skrip mati di tengah jalan, cacatnya
tertinggal. Yang otomatis adalah **tesnya** (812 tes PHP, 30 skrip browser);
yang manual adalah **bukti bahwa tesnya bisa gagal**, dijalankan setiap kali
sebuah pagar baru ditambahkan.

Dua catatan yang mahal untuk ditemukan ulang:

1. **`sed` dengan regex ber-backslash ganda gagal secara diam.** Percobaan
   pertama pada `uji-pagar-ekspor.sh` menghasilkan `bad regex`, suntikan tidak
   terpasang, lalu `set -e` menghentikan skrip sebelum dua cacat terakhir diuji -
   dan keluarannya terbaca seperti "hanya empat cacat yang perlu diuji".
   Suntikan yang berisi pola regex karena itu memakai `php -r`, bukan `sed`.
2. **`sed` yang menyisipkan kunci array PHP di DEPAN kunci yang sudah ada tidak
   menyuntikkan apa pun.** Kunci ganda di PHP: yang terakhir menang. Gejalanya
   adalah "tes lulus padahal cacat terpasang" - dan itu terbaca sebagai pagar
   yang bekerja.

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
- `T*-*.png`, `QR-*.png`, `r3-*.png`, `PB-*.png` — tangkapan layar bukti yang
  dihasilkan skrip uji, dipakai di laporan dan di komentar issue. Setiap `.png`
  di sini punya skrip yang membuatnya; kalau tidak, berkas itu dihapus.

Tangkapan layar lama yang skrip pembuatnya sudah tidak ada (**T1-\*.png**,
**T16-\*.png**, **kartu-\*-360.png**) sudah dibuang supaya tidak ada dua versi
kebenaran. Tes T1 tetap berjalan lewat `e2e-t1.mjs`, hanya gambarnya tidak lagi
disimpan.

Skrip yang diawali `_` adalah perkakas sementara (migrasi, diagnosa) dan tidak
dijalankan sebagai bagian dari pemeriksaan rutin.
