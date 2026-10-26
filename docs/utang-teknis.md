# Utang teknis

Hal-hal yang diketahui kurang, disengaja ditunda, dan alasannya. Daftar ini ada
supaya penundaan tetap tercatat, bukan supaya terlupakan.

Status per LANGKAH 9 (commit `f2c73f0`).

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

`periksa.ps1` memuat 26 skrip, `periksa.sh` hanya 24. Dua yang hilang:
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
| **DAT-03** — email dikirim sinkron (`QUEUE_CONNECTION=sync`), tanpa queue worker | Terbit massal mengirim satu email per penghuni, berurutan dalam satu permintaan: 30 penghuni = 30 email sebelum respons kembali, dan SMTP yang lambat memperlambat layar, bukan hanya emailnya | **Diterima sebagai utang, bukan cacat yang harus ditutup.** Satu layanan Render tidak bisa menjalankan queue worker persisten, dan `sync` adalah konsekuensi arsitektur itu. Kegagalan kirim sudah tidak membatalkan transaksi (R3). Jangan "memperbaikinya" dengan worker yang tidak akan hidup di produksi |
| ~~Pembalik pembayaran belum punya tombol di layar~~ | ~~Koreksi salah catat masih lewat API (`POST /pembayaran/{id}/pembalik`)~~ | **Sudah ditutup (fase terakhir).** Tombol **Tarik kembali** ada di panel rincian tagihan, dengan formulir alasan wajib (server menolak 422 bila kosong, dan formulirnya tetap terbuka supaya pemilik tidak mengira penarikannya berhasil). Riwayat menampilkan baris negatif bertanda "ditarik kembali", dan tombolnya hilang untuk baris yang sudah ditarik. `TagihanResource` kini mengirim `adalah_pembalik` dan `sudah_dibalik` per baris; `null` pada yang kedua berarti relasinya tidak dimuat, dan layar hanya menawarkan tombol saat nilainya benar-benar `false`. Dijaga `uji-pembalik.mjs` (13 langkah) dan dua tes baru di `PembalikPembayaranTest`. Tinggal **antrean email** (DAT-03) yang masih terbuka |
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

## 4. Kesiapan produksi (LANGKAH 11) — SELESAI, dengan tiga batas yang jujur

Seluruh butir di bawah **sudah dikerjakan dan dibuktikan berjalan** lewat
`docker build -f Dockerfile.prod` + `docker run` terhadap database lokal.
Bukti yang dipegang: image boot, tiga proses (`php-fpm`, `nginx`, `penjadwal`)
masuk RUNNING, `GET /` menyajikan HTML SPA, `/assets/*` ber-cache immutable,
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
| Target deploy belum ada | **Belum, dan tidak bisa dikerjakan dari sini.** Butuh akun Render + Neon. `deploy.yml` tetap melaporkan `siap=false` alih-alih gagal, dan langkah manualnya ada di `docs/produk/9_DEPLOY.md` |
| T22 Midtrans | **Selesai, kecuali verifikasi sandbox.** Kode lengkap: `MidtransGateway` (tanda tangan SHA512, `hash_equals`, pemetaan status), `PembayaranGateway` (buat transaksi + tangani webhook), tabel `transaksi_gateway`, endpoint `POST /api/webhook/midtrans` (tanpa auth, tanda tangan sebagai satu-satunya bukti) dan `POST /api/tagihan/{id}/bayar-daring`, plus tombol "Bayar daring" di portal penghuni. 16 tes berjalan dengan kredensial palsu; tiga pengaman (tanda tangan, idempotensi, pencocokan nominal) dibuktikan bisa gagal. **Yang belum:** transaksi sungguhan ke sandbox Midtrans - butuh `MIDTRANS_SERVER_KEY` |

Tiga hal di atas yang masih "belum" bukan kelalaian yang disembunyikan:
masing-masing menunggu sesuatu yang tidak ada di repo ini (akun, kredensial,
disk). Menulisnya sebagai selesai akan membuat audit berikutnya mencari kode
yang tidak pernah ada.

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
| Tidak ada manifest, service worker, theme-color | **Selesai.** `manifest.webmanifest`, `sw.js`, 4 ikon PNG, `theme-color`; dijaga `uji-pwa.mjs` 22 langkah |
| Kepala pemilik memakan 360px dari 800px layar | **Selesai.** Terukur turun ke 203px |
| Target sentuh di bawah 44px | **Selesai** untuk `(pointer: coarse)` |
| Luberan mendatar di 360px | **Selesai** sejak commit `f2c73f0`, dijaga `uji-tata-letak.mjs` 94/94 |

**Sisa yang belum dikerjakan:**

| Kurang | Dampak | Catatan |
|---|---|---|
| Navigasi bawah (bottom bar) khusus ponsel | Menu masih berupa deret mendatar yang digeser | Bukan cacat, tetapi pola ponsel yang lazim |
| Orientasi lanskap di ponsel | Belum diuji; `manifest` mengunci `portrait-primary` | Perlu uji tersendiri di 640x360 |
| Uji luring sungguhan (matikan jaringan) | Kerangka ter-cache, tetapi belum pernah dibuktikan bisa dibuka tanpa jaringan | Bisa diuji dengan `Network.emulateNetworkConditions` |
| `uji-pwa.mjs` memakai build produksi | Service worker tidak didaftarkan saat `npm run dev` | `periksa.sh` sudah memakai image, jadi aman di sana |

**Catatan penting.** Luberan mendatar di 360px **sudah** diperbaiki (commit
`f2c73f0`) dan dijaga `uji-tata-letak.mjs` 94/94. Jadi aplikasi sekarang *muat*
di ponsel; yang belum adalah *nyaman*.

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
