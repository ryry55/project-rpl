# Skrip uji

Dua puluh lima berkas di folder ini menguji aplikasi dari luar: dua puluh dua
lewat browser sungguhan (termasuk satu yang menyapu seluruh halaman, satu yang
mengukur tata letak di lebar ponsel, dan satu yang mensimulasikan pemuatan
gagal), dua menguji balapan HTTP, dan satu berkas bersama yang dipakai
semuanya.

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
| `uji-kartu-tabel.mjs` | Tabel 4+ kolom berubah menjadi kartu di 360px dan 390px: setiap sel berlabel sesuai `<th>`-nya, `thead` tidak terlihat, dan kartunya tidak meluber. Di layar lebar tabelnya kembali normal | Chrome, seed |
| `uji-pwa.mjs` | Manifest terbaca dan semua ikonnya tersaji; service worker terdaftar, aktif, dan mengendalikan halaman; **tidak ada respons `/api/` yang masuk cache** (R-07) | Chrome, build produksi, seed |
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
| `e2e-t28.mjs` | UU PDP: gerbang persetujuan bisa ditolak, halaman Data pribadi menampilkan apa yang tercatat, penghapusan ditahan selama kontrak aktif **maupun selama masih ada tagihan belum lunas**, konfirmasi tertulis wajib, dan sesudah dilepas **tagihannya tetap utuh sedangkan namanya hilang** - termasuk dari jejak audit pemilik | Chrome, seed, Mailpit |
| `e2e-r3.mjs` | R3: tagihan yang salah terbit bisa **dibatalkan tanpa dihapus** dan periodenya diterbitkan ulang, pemaafan denda membekukan denda tanpa menghapus jejaknya, tombol WhatsApp menyusun tautan `wa.me` dengan teks dari server, dan rekening tujuan pembayaran hanya bisa ditulis pemilik | Chrome, seed |
| `uji-pembalik.mjs` | Tombol **Tarik kembali** menulis baris **negatif** yang menunjuk baris aslinya; alasan kosong ditolak 422 dan formulirnya tetap terbuka; baris pembalik tampil sebagai angka negatif bertanda "ditarik kembali", bukan disembunyikan; sesudah ditarik tagihan kembali menunggak dan **tagihan yang tadinya buntu karena uangnya menempel kini bisa dibatalkan** | Chrome, seed |
| `uji-qris.mjs` | Medan unggah gambar hanya muncul untuk jenis QRIS; pratinjau tampil **sebelum** disimpan; sesudah tersimpan daftar menyebut gambarnya ada; penghuni melihat gambarnya **benar-benar termuat** (`naturalWidth` > 0, disajikan sebagai blob, bukan URL bertoken); gambar pada rekening transfer ditolak 422 dengan pesan yang bisa dibaca; penghuni tidak bisa membuka gambar rekening nonaktif (404) | Chrome, seed |

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

### Pemeriksa kesesuaian dokumen

Tiga berkas `.js` yang memeriksa **dokumen terhadap kode**, bukan aplikasi yang
sedang berjalan. Ketiganya tidak butuh E2E, dan selesai dalam hitungan detik.

| Berkas | Yang diperiksa |
|---|---|
| `cek-angka-dokumen.js` | Angka yang tertulis di `KONTEKS_PROYEK.md`, `serah-terima.md`, dan `SPEC.md` dibandingkan dengan angka nyata: jumlah migrasi, model, berkas tes, dan endpoint di potret rute |
| `cek-cakupan-kontrak.js` | Setiap rute nyata muncul di **tepat satu** baris tabel `6_TOOLS_CONTRACTS.md`; tidak ada yang belum tercatat, tidak ada yang tercatat dua kali; judul `(N endpoint)` tiap bagian cocok dengan isinya |
| `cek-erd-lrs.js` | `docs/erd/skema.json` masih cocok dengan basis data; setiap tabel punya bagian di `03-lrs.md`; setiap kunci asing muncul di tabel relasi LRS dengan tujuan yang sama; jumlah tabel/kunci asing yang ditulis cocok dengan yang dihitung; berkas SVG hasil build ada dan tidak kosong |

```powershell
node docs/uji/cek-angka-dokumen.js
node docs/uji/cek-cakupan-kontrak.js
node docs/uji/cek-erd-lrs.js
```

`cek-erd-lrs.js` adalah satu-satunya dari ketiganya yang **butuh container `db`
hidup**, karena ia membandingkan dokumen dengan basis data yang berjalan, bukan
dengan berkas. Itu disengaja: dokumen ERD/LRS yang dibandingkan dengan salinan
skema hanya membuktikan salinan itu konsisten dengan dirinya sendiri.

**Kenapa ada.** Keduanya lahir dari kesalahan yang benar-benar terjadi pada
26 Oktober 2026: `KONTEKS_PROYEK.md` menyebut 71 endpoint padahal aplikasinya
sudah 85, dan tiga judul bagian di `6_TOOLS_CONTRACTS.md` salah jumlah. Angka
yang diketik tangan akan tertinggal; angka yang dibaca dari sumbernya tidak.

**Ketiganya dibuktikan bisa gagal**, bukan hanya bisa lulus.
Dengan satu angka di `KONTEKS_PROYEK.md` diubah kembali menjadi 71,
`cek-angka-dokumen.js` melaporkan `ADA ANGKA YANG BEDA`. Dengan judul bagian 5
dikembalikan ke `(16 endpoint)`, `cek-cakupan-kontrak.js` melaporkan
`bagian 5: 20  SALAH (tulis 16, harus 20)`. Dengan menghapus tabel `rekening`
dan kunci asing `pembayaran.pembalik_id` dari `skema.json`, `cek-erd-lrs.js`
melaporkan kolom-kolom `rekening.*` yang hilang dan keluar dengan kode 1.

Ketiganya mencari akar repo dari lokasi berkasnya sendiri, bukan dari direktori
kerja, sehingga bisa dijalankan dari mana saja.

**Ketiganya sempat tidak pernah berjalan di CI.** Ditemukan 27 Oktober 2026:
`periksa.sh --hanya-e2e` — satu-satunya cara CI memanggil gerbang — melewati
bagian "Kesesuaian dokumen", dan job `lint` tidak memanggil pemeriksa itu sama
sekali. Selama berbulan-bulan ketiga pagar ini hanya hidup di laptop; ia tampak
terpasang karena tercantum di `periksa.ps1`. Sekarang `cek-angka-dokumen.js` dan
`cek-cakupan-kontrak.js` berjalan di job `lint`, dan `cek-erd-lrs.js` di job
`uji` sesudah container `db` hidup.

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
tertinggal. Yang otomatis adalah **tesnya** (791 tes PHP, 30 skrip browser);
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
- `T*-*.png` — tangkapan layar bukti, dipakai di laporan dan di komentar issue.
- `screenshot-t1.cjs` — skrip lama berbasis Playwright dari T1; tidak dipakai lagi.

Skrip yang diawali `_` adalah perkakas sementara (migrasi, diagnosa) dan tidak
dijalankan sebagai bagian dari pemeriksaan rutin.
