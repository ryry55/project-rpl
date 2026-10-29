# Utang teknis

Hal-hal yang diketahui kurang, disengaja ditunda, dan alasannya. Daftar ini ada
supaya penundaan tetap tercatat, bukan supaya terlupakan.

Status per 29 Oktober 2026 (audit putaran 2 + penutupan temuan B/C).

---

## 1. Uji browser di CI menggantung - SELESAI, dan penyebabnya bukan yang diduga

**Gejala yang tercatat.** Job `uji` pada GitHub Actions masuk ke langkah "Uji
browser dan uji balapan" lalu tidak selesai: 17+ menit, padahal di laptop
langkah yang sama memakan 257-283 detik. Job akhirnya berhenti karena
`timeout-minutes: 30`, bukan karena uji gagal.

**Ternyata bukan itu yang terjadi.** Ketika daftar kegagalan CI diperiksa satu
per satu, **setiap** push berakhir merah dalam **34-41 detik** - jauh sebelum
17 menit. Yang gagal adalah langkah **paritas daftar skrip**, yang berjalan
sebelum E2E sama sekali:

```
Daftar skrip uji berbeda antara periksa.ps1 dan periksa.sh.
```

`periksa.ps1` saat itu memuat 26 skrip, `periksa.sh` hanya 24. Dua yang hilang:
`uji-pembalik.mjs` (13 langkah) dan `uji-qris.mjs` (15 langkah) - keduanya
ditambahkan pada fase terakhir dan hanya dimasukkan ke berkas PowerShell.

Akibatnya lebih buruk daripada CI merah: **gate yang dipakai di Linux - dan
karena itu satu-satunya gate yang berjalan di CI - tidak pernah menjalankan uji
untuk tombol "Tarik kembali" maupun unggah gambar QRIS.** Dua fitur terakhir
yang dikerjakan justru yang paling tidak terjaga. Pagar yang tidak jalan di CI
bukan pagar, dan yang lebih buruk, ia terlihat seperti pagar.

Diperbaiki dengan menyamakan daftarnya (26 = 26, diverifikasi dengan
mengekstrak nama berkas dari kedua sumber lalu membandingkannya, bukan dengan
mata). Sesudah itu CI **hijau untuk pertama kalinya**, dan job utamanya selesai
**10m51s** - bukti bahwa E2E benar-benar berjalan, bukan menggantung. Dugaan
"menggantung" di atas tidak pernah terbukti dan tidak perlu dikejar lagi.

**`continue-on-error` sudah DICABUT.** Sesudah lima kegagalan di 2b diperbaiki,
satu putaran CI membuktikan 26/26 suite lulus di runner (375 detik), jadi
alasan satu-satunya untuk mempertahankannya - "belum dipahami" - sudah hilang.
Kini CI hijau **benar-benar** berarti E2E lulus; sebelumnya tidak.

**`timeout 240 node ...` per skrip** tetap dipasang di `periksa.sh` dan tetap
berguna: ia mengubah "menggantung tanpa kabar" menjadi "gagal dengan nama
skrip". `periksa.ps1` sengaja belum - padanan `Start-Process` + `WaitForExit`
sudah dicoba dan justru menggantung sendiri saat diuji dengan proses yang
sengaja dibuat macet, sehingga obatnya lebih berbahaya daripada penyakitnya.



---

## 2. Tiga kegagalan E2E yang ditemukan CI dan sudah diperbaiki

Dicatat karena cara temuannya penting: ketiganya **lulus di laptop** dan hanya
muncul di runner yang lebih lambat. Commit `f2c73f0`.

| Skrip | Sebab | Perbaikan |
|---|---|---|
| `e2e-t9.mjs` | Menunggu `.tabel tbody tr`, dan baris "Memuat tagihan…" juga cocok dengan selektor itu | Menunggu `.lencana-status`, yang hanya ada pada baris sungguhan |
| `e2e-t19.mjs` | Menunggu `h1` (muncul sebelum tabel terisi) + `setTimeout(1400)` tetap | Menunggu baris yang memuat judul, lalu menunggu status berubah (maks 15 detik) |
| `uji-tata-letak.mjs` | **Cacat layout nyata**: 363px pada viewport 360px | `.kepala` diberi `flex-wrap`, `.sisi` diberi `min-width: 0` |

Pelajaran yang berlaku umum: **asersi yang menunggu "ada elemen" harus menunggu
elemen yang membuktikan data sudah tiba**, bukan elemen pertama yang muncul.
Penanda memuat selalu lebih dulu ada.

**Koreksi untuk baris ketiga.** Perbaikan `.kepala { flex-wrap: wrap }` di atas
**tidak cukup**, dan itu baru terbukti belakangan: 363px muncul lagi di CI.
Sebabnya, `flex-wrap` pada induk hanya memindahkan SELURUH kelompok tombol ke
barisnya sendiri; ia tidak memampatkan isi kelompok itu. Karena `.aksi-kepala`
masih `flex-shrink: 0`, kotaknya tetap selebar max-content dan luberannya keluar
ke dokumen. Lihat 2b untuk perbaikan yang benar.

---

## 2b. Lima kegagalan E2E yang tertutup `continue-on-error`, dan perbaikannya

Sesudah paritas daftar skrip diperbaiki (1), E2E akhirnya benar-benar berjalan
di CI - dan ternyata **lima suite gagal di sana padahal lulus di laptop**.
Selama ini semuanya tersembunyi karena `continue-on-error: true`.

Dua sebab yang berbeda, dan membedakannya penting: **tiga di antaranya cacat
skrip uji**, **satu cacat aplikasi yang nyata**, dan satu lagi cacat yang sama
dengan yang kedua.

| Skrip | Gejala di CI | Sebab sebenarnya | Perbaikan |
|---|---|---|---|
| `e2e-t18.mjs` | 25/26, "tabel fasilitas terisi dari seeder - **1 baris**" | Menghitung `<tr>` sekali jalan. Selama data belum tiba tabel merender **satu `<tr>` kosong** (`BarisKosong.vue`), jadi hitungannya 1 | Menunggu baris yang `textContent`-nya tidak kosong, maks 15 detik |
| `e2e-t19.mjs` | 22/23, "tabel aturan terisi dari seeder - **1 baris**" | Sama | Sama |
| `uji-qris.mjs` | 10/15, "medan gambar tidak muncul" | `<option>` dibangun dari `meta` yang dimuat **async** di `onMounted`. Uji menunggu `#rekening-nama` (ada sejak awal), lalu menulis `pilih.value = 'qris'` - pada `<select>` yang belum punya opsi itu penulisan **gagal senyap**, nilainya tidak berubah | Menunggu `option[value=qris]` ada sebelum mengganti jenis; jadi 16 langkah |
| `uji-tata-letak.mjs` | 93/94, dokumen 363 > 360 di halaman **Tagihan** | **Cacat aplikasi nyata.** Lihat di bawah | `.aksi-kepala` diberi `flex-wrap` dan `flex-shrink: 0`-nya dibuang; `.simulasi-kendali` diberi `flex-wrap` |
| `uji-kartu-tabel.mjs` | 64/65, `innerWidth` 363 | Sebab yang sama | Sama |

**Cacat layout itu nyata, bukan artefak CI.** Diukur dengan probe pada viewport
200px: dokumen berhenti menyusut di **328px**, dan yang menetapkan lantai itu
adalah `.aksi-kepala` (291px - dua tombol yang tidak boleh mengecil) dan
`.simulasi-kendali` (input tanggal + dua tombol sebaris). Lantai itu bergeser
mengikuti metrik fon, jadi di runner CI ia melewati 360px sementara di Windows
tidak. Artinya: **di ponsel 360px yang fonnya sedikit lebih lebar, halaman
Tagihan memang bergulir mendatar.** Lulus di laptop hanya berarti fonnya lebih
sempit.

Sesudah perbaikan, lantai halaman Tagihan turun dari 307px ke **218px** - sama
dengan halaman Kamar yang tidak pernah bermasalah. Diukur ulang, bukan diasumsikan.

Pelajaran kedua yang berlaku umum: **`flex-wrap` pada induk tidak pernah cukup
kalau anaknya `flex-shrink: 0`.** Membungkus hanya berguna bila kotaknya boleh
menjadi lebih sempit daripada isinya; `min-width: 0` sering ikut diperlukan
karena item flex bawaannya `min-width: auto`.

---

## 3. Cacat fungsional yang diketahui, belum diperbaiki

**Sudah ditutup:** **REV-SEC-03** - tautan email menunjuk ke alamat API, bukan
alamat SPA, sehingga tautan aktivasi dan reset sandi menjawab 404 saat diklik di
laptop. Kini ada `config('app.url_spa')` yang bawaannya jatuh ke `APP_URL`
(aman untuk produksi satu layanan, yang memang satu origin), dan `.env`
lokal mengisinya `http://localhost:3000`. Dijaga dua tes di
`AktivasiAkunTest`: satu memeriksa alamat LENGKAP di badan email, satu
memeriksa rantai `?:` saat variabelnya kosong. Keduanya dibuktikan bisa gagal
dengan mengembalikan `url_spa` menjadi `url`.

| Cacat | Akibat | Catatan |
|---|---|---|
| ~~`TerbitkanTagihanMassal` tidak terjadwal~~ | ~~Tagihan bulanan harus diterbitkan manual lewat layar~~ | **Sudah diperbaiki (LANGKAH 11).** Dijadwalkan lewat `Schedule::call` di `routes/console.php` (tanggal 1, 00:10 WIB) dan dijalankan `schedule:work` sebagai program supervisord di image produksi. `kontrak:mulai` berjalan 00:05 lebih dulu supaya kontrak yang mulai tanggal 1 sudah berstatus `aktif` saat tagihan diterbitkan |
| ~~**S1.9 / REV-FIN-04** — tanggal masuk tidak boleh di masa depan~~ | ~~Pemilik tidak bisa mencatat penghuni yang sudah menyanggupi kamar untuk bulan depan~~ | **Sudah diperbaiki.** Batasnya kini satu tahun (menahan salah ketik tahun), dan tanggal masa depan melahirkan kontrak berstatus `dipesan` dengan kamar berstatus `dipesan`. Kontrak `dipesan` **tidak ditagih** dan **tidak dihitung menempati kamar**; `kontrak:mulai` (harian 00:05 WIB) mengubahnya menjadi `aktif`/`terisi` saat harinya tiba. Pemesanan ganda ditolak **indeks unik** `(kamar_id) WHERE status IN ('aktif','dipesan')`, bukan pemeriksaan aplikasi yang punya celah balapan. Dijaga 3 tes di `DataIndukTest` + 5 tes di `MulaiKontrakTest`, dan ketiganya dibuktikan bisa gagal dengan menyuntikkan cacat |
| ~~Deposit dibaca saat kontrak dibuat, tidak dipakai lagi~~ | ~~Deposit tidak pernah mengurangi tagihan akhir~~ | **Sudah ditutup - deposit dihapus seluruhnya (fase terakhir).** Bukan dilengkapi, melainkan dibuang: deposit dikumpulkan saat kontrak dibuat tetapi tidak pernah dicatat sebagai uang masuk, tidak pernah mengurangi tagihan, dan tidak pernah dikembalikan saat penghuni keluar. Dua jalan keluarnya adalah merekamnya sebagai pembayaran sah lalu mengembalikan lewat baris negatif, atau berhenti berpura-pura menanganinya. Pemilik memilih yang kedua, dengan alasan yang layak dikutip: **kolom yang menyimpan angka tanpa akibat lebih buruk daripada kolom yang tidak ada, karena ia membuat orang mengira uangnya terjaga.** Yang dihapus: kolom `kontrak.deposit`, tipe kebijakan `deposit` beserta tiga nilainya, kelas `PenentuDeposit`, medan formulir dan kolom tabel di layar Kontrak, dan seluruh rujukannya di dokumen. Migrasi `2026_11_20_120000_hapus_deposit` melepas trigger append-only secara sadar untuk membuang baris kebijakan deposit, menyempitkan tiga CHECK, lalu memasang trigger kembali. Dijaga `MigrasiHapusDepositTest` (5 tes) dan `KebijakanKosTest::test_tipe_deposit_ditolak_validasi_maupun_constraint` |
| Akun yang pernah menangani uang tidak bisa dihapus | `RESTRICT` pada FK `dicatat_oleh`/`diterima_oleh` | Disengaja: jejak uang tidak boleh yatim |
| ~~**DAT-03** - email dikirim sinkron (`QUEUE_CONNECTION=sync`), tanpa queue worker~~ | ~~Terbit massal mengirim satu email per penghuni, berurutan dalam satu permintaan~~ | **Ditutup 29 Oktober 2026 (Fase 5.5, disetujui pemilik).** Produksi memakai `QUEUE_CONNECTION=database` + `[program:antrean]` (`queue:work --sleep=3 --max-time=3600`) di `docker/prod/supervisord.conf`. `Pemberitahu::setelahTransaksi` menaruh satu `App\Jobs\KirimPemberitahuan` per penerima; kegagalan kirim tetap ditangkap dan dicatat ke `log_audit` (`Pemberitahu::kirimEmail`), jadi B3 tidak hilang saat pengiriman pindah ke pekerja. Di laptop tetap `sync` - pekerjaan berjalan seketika, dan itulah yang diuji `NotifikasiTest::test_email_notifikasi_masuk_antrean` (memakai `Queue::fake()`, sebab dengan `sync` saja antre dan sinkron terlihat sama) |

**Temuan samping saat menutup utang CI: `.env.example` dulu menulis
`QUEUE_CONNECTION=database`.** Nilai itu adalah bawaan Laravel, dan kalau
seseorang menyiapkan deploy dengan menyalin berkas contoh itu, setiap pekerjaan
yang ANTRI akan ditaruh di tabel `jobs` dan menunggu `queue:work` yang tidak
pernah dijalankan - image produksi waktu itu hanya menjalankan php-fpm, dan
pekerjaan seperti itu tidak gagal; ia diam, dan diam adalah kegagalan yang
paling sulit ditemukan.

Saat itu diperbaiki menjadi `sync`. **Status 29 Oktober 2026 (Fase 5.5): email
memang diantrikan sekarang, jadi kedua nilai itu hidup lagi dan harus berubah
bersama** - laptop `sync` (tidak ada pekerja di sana; pekerjaan berjalan di
dalam permintaan), produksi `database` + program `antrean`. Komentar di
`.env.example` menyebut pasangan itu, karena `database` tanpa pekerja tetap
berarti email yang diam di tabel `jobs` tanpa satu pun galat.

| ~~Pembalik pembayaran belum punya tombol di layar~~ | ~~Koreksi salah catat masih lewat API (`POST /pembayaran/{id}/pembalik`)~~ | **Sudah ditutup (fase terakhir).** Tombol **Tarik kembali** ada di panel rincian tagihan, dengan formulir alasan wajib (server menolak 422 bila kosong, dan formulirnya tetap terbuka supaya pemilik tidak mengira penarikannya berhasil). Riwayat menampilkan baris negatif bertanda "ditarik kembali", dan tombolnya hilang untuk baris yang sudah ditarik. `TagihanResource` kini mengirim `adalah_pembalik` dan `sudah_dibalik` per baris; `null` pada yang kedua berarti relasinya tidak dimuat, dan layar hanya menawarkan tombol saat nilainya benar-benar `false`. Dijaga `uji-pembalik.mjs` (13 langkah) dan dua tes baru di `PembalikPembayaranTest`. Antrean email (DAT-03) juga sudah ditutup - lihat barisnya di atas |
| Pemaafan denda tidak punya halaman riwayat sendiri | Pemaafan hanya terlihat saat membuka satu tagihan, dan di `log_audit` | Disengaja: pemaafan adalah kolom pada `tagihan`, bukan entitas. Riwayat lengkapnya ada di Jejak audit dengan aksi `denda.maafkan` |
| ~~Rekening tidak menyimpan gambar QRIS~~ | ~~`jenis: qris` hanya menyimpan teks (nama merchant dan nomor bila ada)~~ | **Sudah ditutup (fase terakhir).** Gambarnya disimpan di kolom **`bytea`** (`rekening.gambar`), bukan berkas di disk: `public/` dipasang read-only ke nginx dan Render free tidak punya disk persisten, sehingga berkas yang ditulis hari ini hilang saat restart - dan gambar QRIS yang hilang lebih buruk daripada tidak ada, karena pemilik mengira sudah mengunggah. Disajikan lewat `GET /api/rekening/{id}/gambar` dengan `Content-Type`, `nosniff`, dan CSP; penghuni hanya bisa membuka gambar rekening **aktif** (404, bukan 403). Hanya PNG/JPG/WEBP yang diterima - **SVG ditolak** karena bisa memuat skrip. Dijaga `GambarQrisRekeningTest` (19 tes) dan `uji-qris.mjs` (16 langkah). **Jebakan yang ditemukan saat mengerjakannya:** menulis `bytea` sebagai parameter teks biasa menyimpan **0 byte tanpa galat apa pun** - unggahan tampak berhasil, `gambar_ukuran` terisi, gambarnya kosong. Satu-satunya jalur tulis yang terbukti utuh adalah `decode(?, 'base64')`, dan karena itu `gambar` sengaja **tidak** masuk `$fillable`

---

## 3b. Temuan turunan REV-UI-04 yang ditemukan saat R5

REV-UI-04 aslinya hanya menunjuk satu layar (`DashboardPenghuni.vue`). Saat
memperbaikinya, pola cacat yang sama ditemukan di **sembilan layar pemilik**:
`AturanView`, `KamarView`, `KontrakView`, `FasilitasView`, `PengelolaView`,
`PenghuniView`, `RekeningView`, `AuditLogView`, `RekapView`, ditambah
`komponen/TabelTagihan.vue` yang dipakai `TagihanView`.

Cacatnya satu bentuk: baris "Belum ada ..." hanya bergantung pada
`!memuat && daftar.length === 0`, tanpa memeriksa `galatMuat`. Saat pemuatan
gagal, layar memberi tahu pemilik bahwa datanya kosong — padahal yang terjadi
adalah gagal ambil. Pada layar tagihan, itu bisa menyembunyikan tagihan yang
sudah jatuh tempo.

**Kesepuluhnya sudah diperbaiki di R5** dengan menambahkan `&& !galatMuat`.
`TabelTagihan` mendapat prop baru `galatMuat` karena ia komponen bersama dan
tidak punya akses ke state induk.

Dijaga `docs/uji/uji-galat-muat.mjs` — 30 langkah, 10 layar × 3 asersi. Blokade
500 dipasang lewat CDP `Fetch.fulfillRequest`, jadi tidak mengubah database.
Pagar ini **sudah dibuktikan bisa gagal**: satu layar sengaja dikembalikan ke
kondisi lama, dan tepat satu asersi menjadi merah.

---

## 4. Kesiapan produksi (LANGKAH 11) — diuji di laptop, deploy dibatalkan

> **Keputusan 29 Oktober 2026: deploy tidak dikerjakan.** Bagian ini tetap
> disimpan karena isinya bukti kerja, bukan janji: seluruh butir di bawah benar
> berjalan di laptop. Yang tidak akan pernah ada adalah pembuktiannya di Render.

> **Koreksi (audit putaran 2, 26 Okt 2026).** Judul bagian ini semula
> "SELESAI, dengan tiga batas yang jujur". Kata "selesai" itu terlalu luas, dan
> audit kedua membuktikannya: lima penghalang produksi ditemukan yang **tidak
> tercatat di sini**, karena kelimanya hanya muncul di lingkungan yang punya
> proxy TLS, pernah tidur, dan memakai layanan email sungguhan. Lihat
> `11_AUDIT-PRODUKSI-2.md` dan `10_RENCANA-PERBAIKAN.md`.
>
> Yang benar: seluruh butir di bawah terbukti berjalan **di laptop**. Itu
> pencapaian yang nyata, tetapi bukan hal yang sama dengan siap di Render.

Seluruh butir di bawah **sudah dikerjakan dan dibuktikan berjalan** lewat
`docker build -f Dockerfile.prod` + `docker run` terhadap database lokal.
Bukti yang dipegang: image boot, empat proses (`php-fpm`, `nginx`, `penjadwal`,
`antrean`) masuk RUNNING, `GET /` menyajikan HTML SPA, `/assets/*` ber-cache
immutable,
`/sw.js` ber-cache `no-store`, deep link `/tagihan` menjawab 200 HTML,
`/api/health` menjawab `database: ok`, dan `POST /api/login` + `GET /api/me` +
`GET /api/tagihan` mengembalikan data sungguhan — semuanya dari **satu origin**.

| Butir | Status |
|---|---|
| `Dockerfile.prod` satu layanan (SPA di dalam `public/`) | **Selesai.** Tiga tahap: SPA (`node:22-alpine`), dependensi PHP (`composer --no-dev`), lalu `php:8.3-fpm-alpine` + nginx + supervisord |
| Sesi dan cache hilang setiap restart | **Selesai.** `SESSION_DRIVER=database`, `CACHE_STORE=database`; tabel `sessions`/`cache`/`cache_locks` sudah ada sejak migrasi awal |
| Akun demo terbawa ke produksi | **Selesai, berlapis tiga.** Seeder menolak di `APP_ENV=production`; entrypoint memaksa `RUN_SEEDER=false`; `smoke-produksi.sh` memastikan login `pemilik@kos.test` gagal |
| `APP_DEBUG`/`APP_DEMO_MODE`/`APP_KEY` salah | **Selesai.** Entrypoint berhenti keras. Ketiganya dibuktikan bisa gagal: masing-masing dijalankan dan keluar dengan kode 1 disertai alasannya |
| Backup tidak terjadwal | **Selesai, dengan batas.** `kos:backup` berjalan harian 02:00 WIB lewat penjadwal. Di Render free tidak ada disk persisten, jadi hasilnya hilang saat restart — ia untuk pemulihan cepat, bukan arsip |
| Target deploy | **Dibatalkan pemilik 29 Okt 2026.** Tidak ada Render/Neon; demo dari laptop. `deploy.yml` tetap melaporkan `siap=false` alih-alih gagal, dan `9_DEPLOY.md` disimpan sebagai rujukan |
| T22 Midtrans | **Selesai, kecuali verifikasi sandbox — dan itu tidak akan dikerjakan.** Kode lengkap: `MidtransGateway` (tanda tangan SHA512, `hash_equals`, pemetaan status), `PembayaranGateway` (buat transaksi + tangani webhook), tabel `transaksi_gateway`, endpoint `POST /api/webhook/midtrans` (tanpa auth, tanda tangan sebagai satu-satunya bukti) dan `POST /api/tagihan/{id}/bayar-daring`, plus tombol "Bayar daring" di portal penghuni. 16 tes berjalan dengan kredensial palsu; tiga pengaman (tanda tangan, idempotensi, pencocokan nominal) dibuktikan bisa gagal. **Yang tidak akan ada:** transaksi sungguhan ke sandbox Midtrans, karena webhook butuh alamat HTTPS publik |

Dua hal di atas yang masih "belum" bukan kelalaian yang disembunyikan:
keduanya hanya bisa ditutup dengan akun pihak ketiga, dan pemilik memutuskan
tidak membukanya. Menulisnya sebagai selesai akan membuat audit berikutnya
mencari kode yang tidak pernah ada; menghapusnya akan membuat keputusan itu
hilang jejaknya.

### 4b. FASE 0 — SELESAI dan dibuktikan bisa gagal (26 Okt 2026)

Empat dari lima penghalang produksi sudah ditutup. A3 (penjadwal mati bersama
container) **ditutup 29 Okt 2026 tanpa diperbaiki**: deploy dibatalkan pemilik,
jadi tidak ada container yang tidur.

| Butir | Status | Bukti |
|---|---|---|
| **A1** `trustProxies` | **Selesai, DUA bagian** | `bootstrap/app.php` + `fastcgi_param` di kedua berkas nginx. `uji-proxy.mjs`: 5/5 lulus; dengan cacat disuntikkan kembali **0/5 gagal** |
| **A2** `DB_SSLMODE` | **Selesai** | `env('DB_SSLMODE', 'prefer')`; `DB_SSLMODE=require` terbaca `require`, tanpa env tetap `prefer` |
| **A3** penjadwal vs tidur | **Ditutup 29 Okt 2026** | Deploy dibatalkan; tidak ada container yang tidur. Fase 1 batal |
| **A4** `MAIL_FROM_ADDRESS` | **Selesai** | Pagar menyala (kode keluar 1) pada image produksi sungguhan; pesannya terbaca |
| **A5** OPcache | **Selesai** | Berkas terpisah `opcache-prod.ini`; di image produksi `validate_timestamps => Off` |

**Yang membuat Fase 0 berbeda dari sekadar "menulis kode":** setiap perbaikan
dibuktikan bisa **gagal**. A1 diuji dengan menyuntikkan cacatnya kembali, lalu
dikembalikan. Pagar email diuji empat kasus (alamat contoh, alamat sungguhan,
Mailpit, alamat kosong) - dua harus ditolak, dua harus diloloskan.

**Tiga hal yang rencana awal salah** dan baru ketahuan saat dikerjakan:

1. **`at: '*'` tidak bekerja.** Di Laravel `'*'` berarti "percayai HANYA alamat
   pemanggil", bukan "percayai semua proxy". Demo punya dua hop, sehingga
   hasilnya IP gateway (`172.20.0.1`), bukan IP klien. Diganti rentang privat.
2. **`trustProxies` saja tidak cukup.** `include fastcgi_params` di nginx hanya
   mengoper `REMOTE_ADDR` — nol header HTTP. Tanpa `fastcgi_param
   HTTP_X_FORWARDED_*`, nginx membuang header sebelum Laravel melihatnya.
   Terbukti: 0/5 sebelum bagian ini, 5/5 sesudahnya.
3. **Pagar email tidak boleh memakai `APP_ENV`.** Profil produksi memang bisa
   dijalankan di laptop, dan di sana `mailpit` + `kos@contoh.test` adalah
   keadaan yang benar. Pagar yang menyala di situ akan memaksa orang
   mematikannya. Karena itu pagarnya memeriksa `MAIL_HOST`.

### 4c. FASE 3.5 — SELESAI dan dibuktikan bisa gagal (26 Okt 2026)

Fase ini mengubah aplikasi dari "benar saat diuji" menjadi "bisa dipakai orang
yang baru pertama melihatnya". Isinya bukan fitur baru, melainkan empat hal yang
selama ini hanya ada di kepala pengembang.

| Butir | Status | Bukti |
|---|---|---|
| **1. `kos:buat-pemilik`** | **Selesai** | 10 tes, 35 asersi. `uji-pagar-buat-pemilik.sh`: **4 cacat, 4 tertangkap** |
| **2. `GET /penyiapan`** | **Selesai** | 12 tes, 40 asersi. `uji-pagar-penyiapan.sh`: **4 cacat, 4 tertangkap** |
| **3. Ekspor CSV** | **Selesai** | 16 tes, 50 asersi. `uji-pagar-ekspor.sh`: **6 cacat, 6 tertangkap**. `uji-pagar-ekspor-bersih.sh`: blok ringkasan dikembalikan, 2 tes gagal |
| **4. Panduan penyiapan + halaman Bantuan** | **Selesai** | `e2e-fase35.mjs` 18/18, termasuk berkas yang benar-benar terunduh (1183 byte, BOM terverifikasi) |
| **5. SOP email gagal** | **Selesai** | `8_OPERATIONS_SOP.md` §7.4.1; perintah pemulihannya **dijalankan** dan menghasilkan tautan yang sah |

**Empat hal yang rencana awal salah:**

1. **Tes HTTP tidak bisa menguji netralisasi angka di CSV.** Controller mengirim
   `(int) $jumlah`, jadi nilainya sudah bertipe int saat mencapai `EksporCsv` dan
   cabang "string yang isinya angka" tidak pernah dilewati. Ketika cacatnya
   disuntikkan, seluruh tes HTTP tetap lulus — terbaca sebagai "pagar terpasang"
   padahal pagarnya tidak pernah diuji. Diperbaiki dengan tes penulis langsung.

2. **`trustProxies` bukan satu-satunya tempat IP hilang, dan `uji-proxy.mjs`
   hanya didaftarkan di `periksa.ps1`.** Pagar paritas CI karena itu gagal sejak
   `c331d60`, dan di Linux/CI cacat A1 tidak pernah diuji. Kegagalan yang terlihat
   seperti "daftar berbeda" sebenarnya berarti "satu pagar tidak pernah dipasang".
   `periksa.sh` sekarang menjalankan bagian "Jalur proxy" yang setara.

3. **`sed` dengan regex ber-backslash ganda adalah cara menyuntikkan cacat yang
   gagal secara diam.** Percobaan pertama menghasilkan `bad regex`, suntikan tidak
   terpasang, lalu `set -e` menghentikan skrip sebelum cacat 5 dan 6 diuji — dan
   keluarannya terbaca seperti "hanya 4 cacat yang perlu diuji". Diganti `php -r`.

4. **`Browser.setDownloadBehavior` menerima path menurut OS peramban, bukan OS
   skrip.** Chrome berjalan di Windows sementara skripnya menulis `/tmp/...`;
   akibatnya `downloadWillBegin` tetap terkirim — jadi "unduhan dimulai" lulus —
   tetapi `receivedBytes` tetap 0 dan status `completed` tidak pernah datang.
   Diperbaiki dengan `os.tmpdir()`.

5. **Menunggu `h1` bukan berarti halamannya sudah terisi.** `e2e-t26` menunggu
   judul "Kamar" (dirender seketika) lalu langsung membaca `tbody tr` (baru
   terisi setelah `GET /kamar` selesai). Di laptop selalu menang; di runner CI
   kadang kalah, dan gejalanya "baris kamar tidak ditemukan" — terbaca seperti
   kamarnya hilang. Balapan ini sudah ada sebelumnya dan menjadi lebih sering
   ketika halaman Kamar memuat satu permintaan tambahan (`/penyiapan`). Yang
   diperbaiki bukan waktunya, melainkan syarat tunggunya: tes harus menunggu hal
   yang benar-benar dibacanya. **Pelajaran umumnya: menambah satu permintaan ke
   sebuah halaman bisa memunculkan balapan yang sudah lama mengintai di skrip
   ujinya, dan itu bukan salah skrip ujinya sendirian.**

**Keputusan yang diambil, dan alasannya:**

- **`kos:buat-pemilik` adalah perintah artisan, bukan endpoint.** Tidak ada cara
  membuktikan bahwa pemanggil sebuah endpoint berhak membuat akun pemilik
  pertama — pada saat itu belum ada pemilik yang bisa memberi izin. Setiap
  endpoint semacam itu adalah pintu belakang yang tidak bisa ditutup.
- **`StatusPenyiapan` menghitung dari data hidup, bukan menyimpan penanda.**
  Penanda tersimpan bisa bernilai benar sementara datanya sudah hilang, dan
  pemulihan dari cadangan tidak akan pernah membersihkannya.
- **Ekspor `rekap` berbentuk laporan (berjudul); `tunggakan` dan `pembayaran`
  berbentuk tabel bersih.** Rekap memang dokumen dua bagian yang untuk dibaca;
  dua lainnya untuk diimpor ke pembukuan yang sudah ada, dan blok ringkasan di
  atas judul kolom membuat seluruh berkas terbaca sebagai satu tabel dengan
  kolom bergeser. Bukti bahwa pembedaan ini dijaga: `uji-pagar-ekspor-bersih.sh`
  mengembalikan blok itu ke `tunggakan`, dan kedua tesnya gagal.
- **Tombol "Ekspor pembayaran" hanya di layar rekap pemilik.** Layar pengelola
  berjudul "Uang tunai saya", sedangkan berkasnya berisi seluruh pembayaran —
  tombol yang menyesatkan, walaupun datanya sudah bisa ia baca lewat `/pembayaran`.
- **Halaman Bantuan tidak punya tombol "hubungi dukungan".** Tidak ada tim
  dukungan di balik pemasangan mandiri; tombol yang membuka surel ke alamat yang
  tidak pernah dibaca lebih buruk daripada tidak ada tombol.

### 4d. Temuan B/C audit putaran 2 yang ditutup 29 Oktober 2026

Lima temuan yang bisa dikerjakan tanpa akun. Masing-masing dibuktikan bisa
gagal, bukan hanya "sudah ditulis".

| Temuan | Yang dikerjakan | Bukti |
|---|---|---|
| **C1** batas unggah 2 MB vs bawaan nginx | `client_max_body_size 4m` di ketiga nginx | 1,47 MB → **413 sebelum**, **201 sesudah**; 2,4 MB → **422** dengan pesan Indonesia; 5 MB → 413. Yang ditemukan saat mengukur: di laptop batasnya **1 MB**, bukan 2 MB, karena `web` dan `api-web` tidak menyetel apa pun |
| **C2** `SANCTUM_EXPIRATION` tak tercatat | Ditambahkan ke `api/.env.example` dengan alasannya | `config/sanctum.php:65` memang membacanya; bawaannya 14 hari |
| **C7** `Cache-Control` API | Middleware `TanpaCache`: `no-store, private` untuk jawaban JSON | `HeaderCacheTest` (2 tes) merah bila middleware dicabut; gambar QRIS tetap `max-age=300` |
| **B3** email gagal hanya masuk log | `log_audit` aksi `email.gagal`, entitas `email`, memuat penerima + sebab | Asersi di `NotifikasiTest` merah bila pencatatannya dikomentari |
| **B5** tanpa HSTS/CSP | HSTS + `Content-Security-Policy-Report-Only` di nginx produksi, **dan kolektor laporannya** | Diperiksa pada image produksi: `GET /` membawa keduanya. Kolektornya `POST /csp-laporan` (`CspLaporanController`, `throttle:30,1`), sengaja **di luar `/api/`** supaya kontrak 86 endpoint dan peta 14 use case tetap benar; `CspLaporanTest` (4 tes) merah bila daftar putih medan, pemotongan, atau pembatasnya dicabut. **Temuan samping: `add_header` nginx tidak diwarisi** oleh lokasi yang punya `add_header` sendiri, sehingga halaman utama selama ini juga kehilangan `nosniff`/`X-Frame-Options`/`Referrer-Policy`; ketiganya kini diulang di `location = /index.html` |

**Yang masih terbuka dari daftar itu:** CSP belum menegakkan — dan sejak deploy
dibatalkan 29 Oktober 2026, tidak akan: `Report-Only` + kolektornya tetap
terpasang sebagai bukti kerja. Pemantau uptime dan latihan restore Neon ikut
batal bersama deploy. `APP_LOCALE=id` **sudah selesai** 29 Oktober (Fase 5.4,
`api/lang/id/validation.php`). **Uji beban dan uji tiga-peran tidak punya skrip
sendiri di `docs/uji`** — kalimat lama di sini menyebutnya "di luar gate",
padahal berkasnya tidak pernah ada. Yang benar: pengukuran beban dilakukan
manual (390 tagihan = 550 KB / 297 ms, `11_AUDIT-PRODUKSI-2.md` C9), dan ketiga
peran dipakai bergantian oleh skrip E2E (pengelola di `e2e-r3.mjs` dan
`e2e-fase35.mjs`, penghuni di `e2e-t1/t19/t24`, sisanya pemilik) — tidak ada
satu skrip yang memakai ketiganya bersamaan. Kerentanan dependensi ditutup 29 Oktober:
`composer audit --locked` bersih setelah `symfony/mime` 7.4.19 → 7.4.22,
`npm audit --omit=dev` 0.

**Jebakan yang menggigit saat mengerjakannya:** label saringan itu semula
"Email **gagal** terkirim", dan `uji-galat-muat.mjs` memakai pola
`/gagal|galat|kesalahan|tidak dapat|error/i` pada **seluruh teks halaman**.
Label di `<select>` ikut terbaca, sehingga kontrol negatif uji itu merah padahal
tidak ada galat apa pun. Diganti "Email **tidak** terkirim". Kalau kelak ada
label lain yang memuat kata "gagal", uji itu akan menangkapnya lagi — perbaiki
labelnya, jangan polanya, kecuali memang ingin menajamkan pola itu.

---

## 5. Konsistensi dokumen (LANGKAH 11, T23)

| Berkas | Masalah |
|---|---|
| ~~`SPEC.md` §6 L374~~ | **Sudah diperbaiki di R3.** Kini menulis `UNIQUE (nama, berlaku_dari, kamar_id) NULLS NOT DISTINCT` |
| ~~`SPEC.md` L259/281/380, `docs/uji/audit-ketertelusuran.md`~~ | **Sudah diperbaiki di R3.** Seluruh sebutan `parameter_denda` diganti `kebijakan_kos`, termasuk nama tes di `pemetaan-kriteria.md` |
| ~~DFD + SPEC menyebut 6 data store~~ | **Bukan cacat.** Memang 6 store; yang salah adalah sebutan `parameter_denda` di dalamnya, dan itu sudah diganti |
| ~~`3_ARCHITECTURE.md:30` dan `KONTEKS_PROYEK.md:17` menulis Laravel 11~~ | **Diverifikasi di R7.** Keduanya kini menulis Laravel 12.70.1 / PHP 8.3.36, cocok dengan `composer.lock` (`v12.70.1`) dan `php artisan --version` di container. Tiga sebutan "Laravel 11" yang tersisa (`docs/build-plan.md:11`, `README.md:496`, `docs/utang-teknis.md:145`) semuanya menceritakan riwayat keputusan, bukan klaim versi yang salah |
| `revisi.md` dan `docs/revisi.md` | Masih di repo tetapi **tidak di-commit** — keduanya masukan audit, bukan deliverable |
| Laporan | Menunggu format dari dosen |

---

## 6. U2 — mobile dan PWA (LANGKAH 10) — SEBAGIAN BESAR SELESAI

| Kurang | Status |
|---|---|
| Tabel 4+ kolom harus digeser ke samping di ponsel | **Selesai.** Direktif `v-kartu-tabel` melipat baris menjadi kartu di bawah 620px; dijaga `uji-kartu-tabel.mjs` 65 langkah |
| Tidak ada manifest, service worker, theme-color | **Selesai.** `manifest.webmanifest`, `sw.js`, 4 ikon PNG, `theme-color`; dijaga `uji-pwa.mjs` 26 langkah |
| Kepala pemilik memakan 360px dari 800px layar | **Selesai.** Terukur turun ke 203px |
| Target sentuh di bawah 44px | **Selesai** untuk `(pointer: coarse)` |
| Luberan mendatar di 360px | **Selesai.** Dijaga `uji-tata-letak.mjs` 104/104 dan `uji-kartu-tabel.mjs` 65/65 di runner CI maupun lokal. Perbaikan `f2c73f0` ternyata **tidak cukup** - lihat catatan di bawah |
| Orientasi lanskap di ponsel | **Selesai (uji).** `uji-tata-letak.mjs` mengukur 640x360 pada tiga halaman dengan elemen terlebar (Tagihan, Rekening, Pengelola). Kontrol negatifnya: satu `div` 2000px disisipkan - tiga asersi merah |
| Uji luring sungguhan (matikan jaringan) | **Selesai (uji).** `uji-pwa.mjs` §5 menghapus cache HTTP, mematikan jaringan untuk halaman **dan** service worker, lalu membuka `/pemilik/tagihan` dari Cache Storage saja |

**Sisa yang belum dikerjakan:**

| Kurang | Dampak | Catatan |
|---|---|---|
| Navigasi bawah (bottom bar) khusus ponsel | Menu masih berupa deret mendatar yang digeser | Bukan cacat, tetapi pola ponsel yang lazim |
| `uji-pwa.mjs` memakai build produksi | Service worker tidak didaftarkan saat `npm run dev` | `periksa.sh` sudah memakai image, jadi aman di sana |

**Dua batas yang diketahui dari uji luring** (dicatat supaya tidak dikira bug):

1. Tanpa jaringan, aplikasi mendarat di `/masuk?lanjut=<tujuan>` - bukan di
   halaman tujuan. Sesi dipulihkan lewat `GET /me`, dan itu tidak bisa dijawab
   tanpa jaringan. Tokennya **tidak** dihapus (hanya 401 yang menghapus), jadi
   sesi pulih sendiri begitu jaringan kembali; keduanya diasersikan di skrip.
   Menyimpan identitas terakhir ke cache berarti mengubah perilaku yang dilihat
   pengguna - menunggu keputusan pemilik, bukan diputuskan sendiri.
2. Yang terbukti bisa dibuka luring hanyalah **kerangka aplikasi**. Tidak ada
   data yang di-cache, dan itu memang aturan R-07, bukan kekurangan.

**Jebakan yang menggigit saat mengerjakannya.** `Network.emulateNetworkConditions`
berlaku **per target**, dan service worker adalah target tersendiri. Mematikan
jaringan di target halaman tidak mematikan `fetch()` di dalam `sw.js`:
navigasi "luring" tetap dilayani jaringan, dan uji luring lulus tanpa
membuktikan apa pun. Gejalanya senyap - nginx mencatat `GET /` dari `sw.js`
tepat pada navigasi yang seharusnya luring. Terbukti dari kontrol negatif:
seluruh isi Cache Storage dihapus lebih dulu (terukur `["kos-kerangka-v1",
"kos-aset-v1"]` menjadi `[]`) dan halaman **tetap terbuka**. Perbaikannya:
sambung ke target **peramban** (`/json/version`), `Target.attachToTarget
{ flatten: true }` pada target `service_worker`, lalu
`Network.emulateNetworkConditions` dikirim dengan `sessionId` itu. Melepas
sesinya mengembalikan jaringan (diukur). `Network.clearBrowserCache` juga wajib
didahulukan, kalau tidak salinan HTML di memori peramban menutupi peran cache
service worker.

**Catatan penting - dan koreksi.** Luberan mendatar di 360px **sudah**
diperbaiki, tetapi bukan oleh commit `f2c73f0` seperti yang tertulis sebelumnya.
Perbaikan itu (`.kepala { flex-wrap: wrap }`) hanya memindahkan kelompok tombol
ke barisnya sendiri tanpa memampatkan isinya, sehingga lantai min-content tetap
lebih lebar daripada layar dan luberannya hanya muncul di runner CI yang
metrik fonnya berbeda. Yang benar-benar menutupnya: membuang `flex-shrink: 0`
dari `.aksi-kepala` dan memberi `flex-wrap` pada `.aksi-kepala` maupun
`.simulasi-kendali`. Diukur ulang: lantai halaman Tagihan turun 307px -> 218px.

Jadi aplikasi sekarang *muat* di ponsel; yang belum adalah *nyaman*.

---

## 7. Temuan audit yang DITOLAK, beserta alasannya

`revisi.md` memuat 36 temuan. Empat di antaranya tidak benar dan tidak
dikerjakan. Dicatat di sini supaya tidak ada yang mengusulkannya lagi tanpa
memeriksa ulang.

| Kode | Klaim | Kenapa ditolak |
|---|---|---|
| **REV-FIN-06** | Ada status tagihan `perbaikan` yang tidak pernah ditangani | **Status itu tidak ada.** Tidak ada satu pun kemunculan `perbaikan` di `api/`: tidak di `Tagihan::STATUS_*`, tidak di CHECK `tagihan_status_dikenal`, tidak di seeder, tidak di frontend. Status yang benar-benar ada: `belum_bayar`, `sebagian`, `lunas`, `dibatalkan`. Sprint 3.7 dibatalkan seluruhnya. |
| **REV-ACA-03** | Berkas `~$oject_Charter_Kos-Kosan.docx` ter-commit | **Tidak ter-commit.** `.gitignore` sudah memuat `~$*` sejak awal, dan `git ls-files` tidak memuatnya. Tidak ada yang perlu diperbaiki. |
| **REV-SEC-05** (sebagian) | Kebocoran nama+email adalah lubang serius | **Terlalu keras.** Endpoint hanya membocorkan PII bila pemanggil sudah memegang token 64 karakter acak yang sah — dan pemegang token itu bisa langsung menetapkan sandi baru lewat `/atur-sandi` tanpa membaca emailnya sama sekali. Tetap dikeraskan (batas 20/menit per IP + penyamaran email), tetapi sebagai pertahanan berlapis, bukan penutupan lubang kritis. |
| **REV-UI-02** (premis) | Ada rujukan ke rute `komponen-biaya` yang sudah tidak ada | **Premisnya salah.** Tidak ada rujukan `komponen-biaya` di `web/src` sama sekali. Celah yang dilaporkan (tidak ada layar untuk komponen biaya) memang nyata, tetapi bukan karena rujukan yang rusak. |

## 8. Keputusan lama yang DIBATALKAN atas permintaan pemilik

Dua keputusan di dokumen ini sempat diambil dengan alasan yang masuk akal, lalu
dibatalkan. Yang lama tetap dicatat supaya alasan pembatalannya terbaca.

| Keputusan lama | Diganti menjadi | Alasan pembatalan |
|---|---|---|
| `GET /rekap` terbuka untuk pengelola — "membaca uang yang sudah masuk bukan tindakan yang merugikan siapa pun, dan pengelola yang mencatat pembayaran perlu memeriksa catatannya sendiri" | `/rekap` **`peran:pemilik`**; pengelola mendapat `GET /pembayaran/tunai-saya` | Alasan lama tidak bertahan setelah diperiksa: rekap adalah satu-satunya tempat seluruh pendapatan kos terlihat sebagai satu angka, dan pengelola yang memegangnya bisa menyimpulkan penghasilan pemiliknya. Yang dibutuhkan pekerjaannya sudah tersedia di tempat lain — daftar pembayaran tetap terbuka, dan uang tunai di tangannya punya endpoint sendiri. |
| Verifikasi pembayaran menerima catatan sendiri | Ditolak **403** `verifikasi_catatan_sendiri` bila pelakunya `pengelola`; **pemilik tetap boleh** | Pemisahan wewenang pencatatan dan pemeriksaan hanya berlaku di atas kertas bila orang yang sama boleh melakukan keduanya. Pengecualian pemilik disengaja: pada kos kecil ia sering satu-satunya orang yang memegang uang, dan melarangnya memeriksa catatannya sendiri berarti tidak ada yang bisa memeriksa sama sekali. |
