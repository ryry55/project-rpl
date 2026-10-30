# Audit: dokumen vs fitur

> Untuk apa: sebelum sidang, dosen membaca **dokumen**. Kalau dokumen menyangkal
> fitur yang ada, atau menjanjikan yang tidak ada, pertanyaannya tidak bisa
> dijawab dengan "sudah dikerjakan" — karena tertulis sebaliknya.
>
> Cara menyusun: setiap baris dibuktikan dari **kode dan runtime**, bukan dari
> dokumen lain. Perintah pemeriksanya ada di §9.

Tanggal: 28 Oktober 2026. Keadaan: commit `dbe32e0`, gate terakhir **SEMUA LULUS**
(419 s), CI `40583473207` hijau.

## 1. Ringkasan

| Jenis | Jumlah | Boleh dikerjakan sendiri? |
|---|---|---|
| Dokumen menyangkal fitur yang sudah ada (A) | 4 | Ya — hanya kalimat yang diperbaiki |
| Dokumen menjanjikan yang belum dikirim (B) | 1 | Tidak — mengubah kode dan tampilan penghuni |
| Endpoint tidak tercatat / tercatat salah (C) | 5 | Sebagian — lihat K-02, K-03 |
| Angka basi atau bertentangan (D, E) | 13 | Ya |
| Pemeriksa yang tidak benar-benar memeriksa (F) | 4 | Tidak — lihat K-04 |
| Sudah diperiksa dan **cocok** (§8) | 8 | — |

Empat pertanyaan untuk pemilik ada di `docs/keputusan-terbuka.md` (K-01..K-04).

## 2. A — Fitur sudah ada, dokumen bilang belum ada

Ini yang paling merugikan: dosen bisa menyimpulkan pekerjaan tidak dilakukan
padahal ada kode, tes, dan layarnya.

| # | Dokumen bilang | Kenyataan | Bukti |
|---|---|---|---|
| A-01 | `9_DEPLOY.md:179` — "Midtrans — **Tidak ada kodenya.**" | Kode lengkap, 16 tes, dua endpoint, tombol **Bayar daring** di portal penghuni. `utang-teknis.md:217` menulis "selesai, kecuali verifikasi sandbox" — dua dokumen ini bertentangan | `api/app/Domain/Pembayaran/MidtransGateway.php`, `PembayaranGateway.php`, tabel `transaksi_gateway`, `POST /api/tagihan/{tagihan}/bayar-daring`, `POST /api/webhook/midtrans` |
| A-02 | `8_OPERATIONS_SOP.md:143` — "Backup tidak terjadwal. Tidak ada cron yang memanggilnya." | Backup berjalan tiap hari 02:00 lewat penjadwal di image produksi | `api/routes/console.php:64`, `docker/prod/supervisord.conf` (`[program:penjadwal]`) |
| A-03 | `8_OPERATIONS_SOP.md:485`, `1_PRD.md:145`, `3_ARCHITECTURE.md:393`, `KONTEKS_PROYEK.md:166,168` — penerbitan tagihan bulanan tidak terjadwal | Terjadwal tanggal 1 pukul 00:10 WIB; `kontrak:mulai` 00:05 lebih dulu. Batas yang benar: penjadwal **ada**, tetapi di Render gratis container tidur sehingga bisa tidak berbunyi — sudah dicatat di `11_AUDIT-PRODUKSI-2.md` A3 | `api/routes/console.php:42,79` |
| A-04 | `8_OPERATIONS_SOP.md:235` — "**`Dockerfile.prod` belum dibuat.**" | Ada di akar repo, dipakai `9_DEPLOY.md` §3, menyalin supervisord dan entrypoint | `Dockerfile.prod:139-143` |

Perbaikan A-02/A-03 bukan sekadar "ada": tulis "ada, dengan batas paket gratis" —
menghapus kalimatnya saja akan membuat dokumen menjanjikan yang tidak dijamin.

## 3. B — Dijanjikan dokumen, belum dikirim

| # | Janji | Kenyataan | Akibat |
|---|---|---|---|
| B-01 | `docs/dfd/README.md:96,209` dan `docs/akademik/dfd-konteks.md:54`: aliran O-8 membawa `order_id, gross_amount, nama_penghuni, **deskripsi_tagihan**` | `MidtransGateway` hanya mengirim `transaction_details` + `customer_details`; tidak ada `item_details` maupun keterangan tagihan | Penghuni mendarat di halaman Midtrans tanpa keterangan "sewa kamar A1 — November 2026". Dosen yang membuka O-8 lalu memeriksa payload akan menemukan satu field yang tidak pernah dikirim |

Pilihannya di **K-01**: kirim `item_details`, atau hapus `deskripsi_tagihan` dari DFD.
Salah satu harus dilakukan — membiarkan keduanya berarti dokumen berdiri sendiri.

## 4. C — Endpoint

| # | Temuan | Bukti |
|---|---|---|
| C-01 | Rute hidup **86**, potret `docs/akademik/rute-api.json` **85**. Selisihnya satu: `GET api/penghuni/kontrak` | `php artisan route:list --json` dibandingkan dengan potret; tidak ada selisih lain di kedua arah |
| C-02 | `SPEC.md:536` menulis "Daftar lengkap **85 endpoint**", dan tabel §7 hanya memuat 79 dari 86. Tujuh yang tidak ada: `GET /ekspor/{pembayaran,rekap,tunggakan}`, `GET /penyiapan`, `GET /rekening/{rekening}/gambar`, `POST /tagihan/{tagihan}/bayar-daring`, `POST /webhook/midtrans` | pencocokan otomatis tabel §7 terhadap `route:list` |
| C-03 | `6_TOOLS_CONTRACTS.md:1027` — total **80**, padahal jumlah barisnya per bagian 12+34+20+3+6+10 = **85**. Total 80 melewatkan ekspor (3), penyiapan (1), gambar rekening (1). `GET /api/penghuni/kontrak` tidak ada di §4.6, §8, maupun §9 | `node docs/uji/cek-cakupan-kontrak.js` hanya memeriksa §3-§8, jadi baris total tidak pernah diperiksa |
| C-04 | `SPEC.md:597` menulis `/audit-log` bergerbang `peran:pemilik`; middleware hidup `wewenang:baca_audit`. `6_TOOLS_CONTRACTS.md:627` sudah benar | `route:list` menunjukkan `PastikanWewenang:baca_audit` |
| C-05 | Pemetaan use case tidak mengenal `penghuni.kontrak`. `docs/akademik/use-case-diagram.md:33` menjanjikan "85 endpoint … terpakai habis" — janji itu hanya benar terhadap potret yang basi | `node docs/akademik/build.js` mencetak `periksa: OK`; bila potret disegarkan, build gagal karena ada endpoint tanpa use case |

C-04 penting bukan karena salah ketik: ia menyatakan akses lebih sempit daripada
kenyataan. Pengelola yang diberi wewenang `baca_audit` **bisa** membaca jejak
audit, dan dokumen akademik mengatakan tidak bisa.

## 5. D — Angka basi

| # | Tertulis | Nyata | Di mana |
|---|---|---|---|
| D-01 | 792 tes PHP | **799** (3674 asersi) | `AGENTS.md:94`, `PRODUCT.md:83`, `docs/ci-cd.md:39`, `.github/workflows/uji.yml:11` |
| D-02 | 715 tes | 799 | `.github/workflows/rilis.yml:47` |
| D-03 | "Dua puluh lima berkas … dua puluh dua lewat browser" | 29 berkas `.mjs`, 29 skrip uji: 25 browser, 2 balapan, 1 proxy | `docs/uji/README.md:3-7` |
| D-04 | Katalog §4.2 berisi 26 baris; dua skrip yang benar-benar berjalan tidak ada di sana — `e2e-fase35.mjs` dan `uji-proxy.mjs` | 29 skrip di gate | `docs/produk/7_TESTING_EVALS.md` §4.2 |
| D-05 | "52 kelas, **11 folder**" | **13** folder (`Laporan/`, `Penyiapan/`, `Rekening/` tidak tertulis) | `docs/produk/3_ARCHITECTURE.md:409`; tabel `:76-89` hanya 10 baris |
| D-06 | "85 endpoint" | 86 | `docs/akademik/README.md:109` |
| D-07 | `26 skrip`, `24 skrip`, `375 detik` sebagai keadaan sekarang | 29 skrip, 419 s; kalimat itu riwayat | `docs/utang-teknis.md:26,43` |
| D-08 | Jumlah langkah per skrip: `uji-balapan-t4` 9, `uji-balapan-t5` 11, `e2e-t9` 23, `e2e-fase35` 18, `uji-proxy` 5 | 11, 13, 26, 19, 6 pemanggilan laporan di skripnya | `audit-ketertelusuran.md`, `SPEC.md` §10, `tiket.md` |
| D-09 | "792 langkah" | tidak ada yang memverifikasi angka ini. Hitungan statis pemanggilan laporan = 610; angka 792 hanya bisa datang dari satu putaran tertentu | `docs/produk/7_TESTING_EVALS.md:7` |

D-09 adalah angka yang paling rapuh: ia tidak bisa dibuktikan dari berkas, dan
tidak ada pemeriksa yang menjaganya. Aman: ganti dengan "ratusan langkah, total
dilaporkan gate tiap putaran" atau hapus angkanya.

## 6. E — Dokumen bertentangan dengan dokumen lain

| # | Pertentangan |
|---|---|
| E-01 | `1_PRD.md:62` — "**Dua peran.** Bukan tiga, bukan empat" … sementara §2.3 dokumen yang sama, `use-case-diagram.md` §2, dan middleware hidup menyebut **tiga** aktor (`pemilik`, `pengelola`, `penghuni`) |
| E-02 | `8_OPERATIONS_SOP.md:235` ("Dockerfile.prod belum dibuat") vs `9_DEPLOY.md` §3 (memakai berkas itu) |
| E-03 | `7_TESTING_EVALS.md:7` ("26 browser + 2 balapan") vs `:74` ("22 browser + 2 balapan") vs §3.3 ("Dua puluh lima tes Feature" — nyatanya 39 berkas Feature, 699 tes; Unit 100) vs §6 U-5 (`uji-pwa` "23/23" — katalognya 22) |
| E-04 | `3_ARCHITECTURE.md:76-89` (10 baris tabel) vs `:409` ("11 folder") vs nyata 13 |

## 7. F — Pemeriksa yang tidak benar-benar memeriksa

Ini bukan salah angka, melainkan **salah jaminan**: gerbang mencetak "COCOK"
untuk hal yang tidak dibandingkannya.

| # | Pemeriksa | Cacat |
|---|---|---|
| F-01 | `cek-angka-dokumen.js`, `cek-cakupan-kontrak.js`, `build.js` | Ketiganya membaca potret `rute-api.json`, dan **tidak ada satu pun langkah di `periksa.ps1`, `periksa.sh`, atau workflow** yang membuat ulang potret itu (`route:list` tidak dipanggil di mana pun). Endpoint baru otomatis lolos: yang diperiksa dokumen terhadap dirinya sendiri |
| F-02 | `cek-cakupan-kontrak.js` | Hanya memeriksa bagian §3-§8. Baris total §9 tidak diperiksa — karena itu "80" lolos meski bagian-bagiannya berjumlah 85 |
| F-03 | `cek-angka-dokumen.js` | Angka yang ditulis sebagai kata ("dua puluh dua") tidak terlihat polanya; jumlah tes dilaporkan `?` karena hanya phpunit yang tahu |
| F-04 | `build.js` | Mencetak `periksa: OK` pada potret basi. "Terpakai habis" hanya diuji terhadap potret, bukan terhadap rute yang hidup |

Akibat praktis: `dbe32e0` menambah satu endpoint dan menutup enam temuan, gate
tetap hijau, dan angka 85 bertahan di sepuluh dokumen.

## 8. G — Sudah diperiksa dan cocok (jangan diutak-atik)

| Yang diklaim | Hasil |
|---|---|
| 23 tabel domain / 201 kolom / 26 kunci asing / 8 trigger / 32 tabel `public` | `psql` menghitung angka yang sama |
| 812 tes, 3807 asersi, 47 berkas | keluaran phpunit (angka terakhir: §14) |
| Unit 100 tes / 1056 asersi; `KalkulatorTest` 22 tes / 160 asersi | keluaran phpunit |
| Token warna `achromatic-ledger` (`#f8f8f8`, `#ffffff`, `#0a0a0a`, `#6b6b6b`, `#e6e6e6`, `#f0f0f0`, `#141414`) | `web/src/style.css:32-40` = `2_DESIGN_SYSTEM.md:91-101` |
| 12+34+20+3+6+10 = 85 di §3-§8 kontrak | `cek-cakupan-kontrak.js` |
| `smoke-produksi.sh` 9 pemeriksaan dalam 4 kelompok | skripnya (tabel dokumen meringkas 9 menjadi 7 baris — bukan salah angka, hanya lebih kasar) |
| Bukti O-1, O-5, O-6 (kontrak di portal, kuitansi, panel hunian) | `GET /penghuni/kontrak`, `web/src/kuitansi.js`, `RekapPendapatan::hunian()` |
| 29 skrip terdaftar di gate, 26 browser + 2 balapan + 1 proxy | `periksa.ps1:392-427` dan `periksa.sh` |

## 9. H — Cara mengulang pemeriksaan ini

```powershell
# 1. rute hidup vs potret (86 vs 85)
cmd /c "docker compose exec -T api php artisan route:list --json > $env:TEMP\rute.json 2>nul"

# 2. cakupan kontrak (memperlihatkan 85/84 dan baris total yang tidak diperiksa)
node docs/uji/cek-cakupan-kontrak.js

# 3. angka dokumen (memperlihatkan 85 untuk endpoint)
node docs/uji/cek-angka-dokumen.js

# 4. jumlah folder domain (13)
(Get-ChildItem api\app\Domain -Directory).Count
```

Bandingkan isi `$env:TEMP\rute.json` dengan `docs/akademik/rute-api.json` memakai
`name` rute, bukan urutan baris.

## 10. I — Urutan perbaikan yang disarankan

1. **Dokumen-saja** (A, D, E): kalimat dan angka. Tidak mengubah perilaku, tidak
   perlu keputusan. Kerjakan lebih dulu supaya dokumen berhenti berbohong.
2. **Keputusan pemilik** (K-01..K-04): B-01 (payload Midtrans), C-02/C-04
   (menyunting `SPEC.md`), C-05 (peta use case), F (gerbang rute hidup).
3. **Kode lebih dulu, angka terakhir**: setelah kode berubah, jalankan gate,
   hitung ulang seluruh angka, baru perbarui dokumen — supaya angka tidak basi
   dua kali dalam satu putaran.
4. `docs/serah-terima.md` ditimpa di akhir, menyebut keputusan yang masih menunggu.

## 11. Status penutupan — 28 Oktober 2026

Semua temuan ditutup. Yang berubah **kode**: B-01 dan F-01..F-04. Sisanya
dokumen. Tidak ada aturan R-01..R-07 yang disentuh.

| Temuan | Ditutup dengan |
|---|---|
| A-01..A-04 | Kalimat diganti fakta + batasnya: `9_DEPLOY.md` §5, `8_OPERATIONS_SOP.md` §3.3/§5.4/§10, `1_PRD.md` §2/§3.2, `3_ARCHITECTURE.md` §9, `KONTEKS_PROYEK.md` |
| B-01 | `MidtransGateway::buatTransaksi(?string $keterangan)` mengirim `item_details`; `PembayaranGateway::mulai()` mengisi "Sewa kamar <nomor> - <bulan>"; dijaga `PembayaranGatewayTest` (`price × quantity = gross_amount`) |
| C-02, C-04 | `SPEC.md` §7: 86 endpoint, delapan baris ditambahkan, `/audit-log` → `wewenang:baca_audit`; `6_TOOLS_CONTRACTS.md` §8 (11) dan §9 (86) |
| C-05 | UC-13 memuat `penghuni.kontrak`; potret rute disegarkan; `build-dokumen.js` mencetak 86 |
| D-01..D-09 | Angka disegarkan di sepuluh dokumen dari keluaran gate. **D-08/D-09 diperbaiki dengan data, bukan taksiran**: jumlah langkah per skrip = `langkah lulus` dari gate (`uji-balapan-t4` 10, `t5` 12, `e2e-t9` 26, `fase35` 18, `uji-proxy` 5), totalnya **792** — angka lama kebetulan benar |
| E-01..E-04 | "Tiga peran"; `Dockerfile.prod` diakui ada; piramida 699 Feature / 100 Unit; 13 folder domain |
| F-01..F-04 | `periksa.ps1`/`periksa.sh`/job `uji` menyegarkan `rute-api.json` lewat `route:list`; `cek-angka-dokumen.js` dan `cek-cakupan-kontrak.js` menyetel `process.exitCode` (dulu selalu 0); `cek-cakupan-kontrak.js` memeriksa baris Total §9 dan §8.0.1 |

Perintah di §9 di atas masih berguna sebagai bukti **apa yang dulu terlihat**;
dijalankan sekarang, keduanya mencetak `COCOK` / `SEMUA ANGKA COCOK`. Gate
penuh 28 Oktober: **29/29 skrip lulus, 792 langkah, 482 detik**; satu-satunya
langkah merah adalah pemeriksa angka, karena `serah-terima.md` masih menulis 85
saat ia membacanya — berkas itu sudah ditimpa sesudahnya.

## 12. Putaran 29 Oktober 2026 — angka bergeser lagi, dan dua penjaga baru

Gate penuh 29 Oktober: **806 tes PHP / 3767 asersi / 47 berkas**, **29/29 skrip
lulus, 802 langkah, 455 detik**. *(Angka di bagian ini adalah keadaan pagi 29
Oktober. Angka terakhir ada di §14.)* Yang menggeser angka: `CspLaporanTest` (4 tes,
kolektor laporan CSP) dan `TagihanTest::test_daftar_tagihan_tidak_menambah_kueri_per_baris`
(1 tes, penjaga N+1). Tambahan langkah: `uji-pwa.mjs` 25 → 26 (uji luring
sungguhan) dan `uji-tata-letak.mjs` 94 → 100 (lanskap 640x360).

Dua hal yang perlu dibaca dosen, dan keduanya **bukan** kenaikan angka semata:

1. **Kolektor CSP berada di luar `/api/`.** `POST /csp-laporan` sengaja tidak
   masuk kontrak 86 endpoint, karena ia infra yang dipanggil peramban, bukan use
   case. Karena itu `route:list --path=api` (dan seluruh hitungan endpoint di
   dokumen ini) tetap 86 — bukan angka yang tertinggal.
2. **Dua asersi lama ternyata bisa lulus tanpa mengukur apa pun.** Uji luring
   PWA hijau walau seluruh Cache Storage dihapus (emulasi jaringan berlaku per
   target; service worker target tersendiri), dan uji lanskap belum pernah
   dijalankan sama sekali. Keduanya kini punya kontrol negatif yang benar-benar
   merah.

---

## 13. Putaran 29 Oktober 2026 (sore) — tiga janji yang tadinya hanya tulisan

Gate penuh: **812 tes PHP / 3807 asersi / 47 berkas**, **29/29 skrip lulus, 806
langkah, 434 detik**. *(Angka putaran sore ini; angka terakhir ada di §14.)* Tiga tambahan, dan masing-masing menutup satu kalimat di
dokumen yang sebelumnya tidak punya bukti:

| Janji di dokumen | Yang sekarang membuktikannya | Kontra-uji |
|---|---|---|
| "Ekspor butuh daftar utuh" (K-05) | `GET /api/tagihan?per_page=1..200` + `page`. **Tanpa** `per_page` responsnya tidak berubah sama sekali, jadi layar Tagihan dan ekspor tidak tersentuh; di luar batas dijawab 422, bukan dipotong | Kembalikan `TagihanController::index()` ke versi lama: 1 gagal / 8 asersi |
| Seluruh UI berbahasa Indonesia (C5) | `api/lang/id/validation.php` (kunci lengkap + peta nama kolom), `APP_LOCALE=id`, `APP_FALLBACK_LOCALE=id`. Pesan yang muncul kini `tipe tidak boleh lebih dari 40 karakter.` | Sembunyikan `lang/id` di dalam container: 1 gagal / 2 asersi |
| "Antrean email" (DAT-03) | `App\Jobs\KirimPemberitahuan` + `[program:antrean]`; produksi `QUEUE_CONNECTION=database`. Diuji dengan `Queue::fake()`, sebab dengan `sync` saja antre dan sinkron terlihat sama | Kembalikan `Pemberitahu::setelahTransaksi()` ke `Mail::send()`: `The expected [KirimPemberitahuan] job was not pushed` |

Bukti di luar tes, dijalankan pada image `Dockerfile.prod` terhadap database
lokal: `supervisorctl status` menunjukkan **empat** program RUNNING (`php-fpm`,
`nginx`, `penjadwal`, `antrean`); satu `KirimPemberitahuan` yang di-dispatch
membuat tabel `jobs` berisi 1 lalu **0 dalam 8 detik**, `failed_jobs` tetap 0,
dan `supervisorctl restart antrean` berhasil. Dua bagian (`[unix_http_server]`,
`[rpcinterface:supervisor]`) ditambahkan ke `supervisord.conf` karena tanpa
keduanya perintah pemulihan yang tertulis di `8_OPERATIONS_SOP.md` tidak bisa
dijalankan sama sekali — `supervisorctl` menjawab "does not include supervisorctl
section".

Yang **tidak** dikerjakan, dan alasannya: K-06 (penjadwal di dalam container
yang bisa tidur) **ditutup 29 Oktober 2026** karena deploy dibatalkan pemilik —
tidak ada Render, jadi tidak ada container yang tidur. A3 ikut ditutup, bukan
diperbaiki.

---

## 14. Putaran 29 Oktober 2026 (malam) — yang tinggal hanya pembacaan manusia

Gate penuh: **812 tes PHP / 3807 asersi / 47 berkas**, **29/29 skrip lulus, 834
langkah, 517 detik**. Angka ini menggantikan §13; §12 dan §13 sengaja dibiarkan
sebagai catatan keadaan pagi dan sore hari yang sama.

Tiga tambahan, dan dua di antaranya menutup temuan audit yang sebelumnya
berstatus "selesai tanpa tes" — artinya perbaikannya ada, tetapi tidak ada yang
akan menangkap bila dicabut:

| Yang ditutup | Bukti | Kontra-uji |
|---|---|---|
| **REV-SEC-02** — pengelola menetapkan tarif lewat formulir kontrak | `PengelolaTest::test_pengelola_tidak_bisa_menetapkan_tarif_pertama_lewat_formulir_kontrak` menuntut 422 `tarif_perlu_pemilik` **plus** nol baris `tarif_sewa` dan nol baris `kontrak`; pasangannya `test_pemilik_boleh_...` menuntut pemilik tetap 201 | Penjaganya dimatikan (`if (false && ...)`): tes pengelola merah `Expected 422 but received 201`, tes pemilik tetap hijau |
| **REV-DFD-05** — denda/umur digantung pada bukti pembayaran | `DendaHttpTest::test_denda_dan_umur_dihitung_tanpa_satu_pun_pembayaran`: denda 450.000 dan kelompok `31_60` terisi pada tagihan dengan **nol baris pembayaran**, dan tetap nol sesudahnya | Cacat DFD lamanya disuntikkan kembali (`->whereHas('pembayaran')` di `DaftarTunggakan`): merah `tagihan tanpa pembayaran tidak masuk daftar tunggakan` |
| **Delapan temuan DFD** yang "selesai" hanya karena pernah dilihat | `docs/uji/cek-dfd.js` kini langkah tersendiri di `periksa.ps1`, `periksa.sh`, dan `uji.yml`: `periksaSemantik()` bersih **dan** keempat SVG sama persis dengan hasil penggambaran `dfd-data.js` sekarang | Rute `I-1` dihapus → `tidak punya rute di DFD level 1`; pembacaan `D1` dibuang → `black hole`; satu baris ditambahkan ke SVG → `BEDA dari hasil penggambaran data sekarang`; lewat gerbangnya sendiri `periksa.ps1 -Cepat` mencetak `GAGAL  DFD konsisten dan gambarnya tidak basi` |
| **Uji tiga-peran** yang dulu disebut ada padahal tidak | `docs/uji/uji-tiga-peran.mjs` (28 langkah): pemilik, pengelola, penghuni bergantian dalam **satu** sesi | `peran:penghuni` pada `/penghuni/tagihan` dilonggarkan jadi `peran:penghuni,pemilik` → 27/28, merah `portal penghuni kembali ditolak untuk pemilik - status 200` |

**Yang skrip tiga-peran itu sendiri koreksi.** Asumsi pertama saya salah dan
skripnya yang membuktikannya: login **tidak** mencabut token lama, karena
`AuthController::MAKS_SESI = 3` sengaja mengizinkan tiga sesi hidup bersamaan
dan `batasiSesi()` hanya memutus yang terlama ketika jumlahnya melewati batas.
Yang benar diuji bukan "token lama mati", melainkan **token lama tidak berubah
peran** — token pemilik tetap menjawab `pemilik` setelah pengelola masuk.
Menguji yang pertama akan menghasilkan tes yang merah pada perilaku yang benar.

Sesudah putaran ini tidak ada lagi temuan yang "selesai tanpa tes":
`docs/status-revisi.md` mencatat **32 selesai, 0 selesai tanpa tes, 3 tidak
berlaku, 1 masih terbuka (REV-UI-01)**.

## 15. Putaran 30 Oktober 2026 — temuan terakhir ditutup, empat penjaga baru

Gate penuh: **812 tes PHP / 3807 asersi / 47 berkas**, **19 tes Vitest**,
**29/29 skrip lulus, 844 langkah, 544 detik**. Angka ini menggantikan §14; §11-§14
sengaja dibiarkan sebagai riwayat putaran sebelumnya.

Pemilik menjawab lima pertanyaan pada 30 Oktober (K-07..K-11 di
`docs/keputusan-terbuka.md`), dan kelimanya dieksekusi di putaran ini:

| Yang ditutup | Bukti | Kontra-uji |
|---|---|---|
| **REV-UI-01** — 401 di tengah pemakaian tidak mengeluarkan sesi. Temuan Kritis **terakhir** yang terbuka | `sesiHabis()` di `web/src/sesi.js`, dipanggil `panggil()` dan `unduhBerkas()` saat 401 selama token masih ada: sesi dibersihkan, muat ulang ke `/masuk?pesan=berakhir&lanjut=…`. `e2e-t1.mjs` naik 9 → 15 langkah: token dicabut **di server** selagi sesi terbuka, lalu menu Tunggakan diklik (tanpa muat ulang halaman) | Penanganan 401 dimatikan (`if (false && …)`) → 11/15, empat asersi merah, pengguna **tertinggal** di `/pemilik/tunggakan` dengan token masih tersimpan |
| **K-08 kendali halaman** di layar Tagihan | `web/src/halaman.js` (`PER_HALAMAN = 50`, `jumlahHalaman`, `potong`) + `TagihanView.vue`: `daftar` tetap utuh, `tampil` yang dirender | `:daftar="daftar"` → 18/20, merah `52 baris` yang seharusnya `50`; Vitest: `potong()` selalu mulai baris 1 → 3 tes merah |
| **K-09 Vitest** | `vitest` 5.0.5 tanpa jsdom, 19 tes di `web/src/format.test.js` dan `halaman.test.js`; langkah tersendiri di `periksa.ps1`, `periksa.sh`, `uji.yml` | `potong()` dibuat selalu mulai dari baris pertama → 3 merah (`expected 1 to be 51`, panjang 50 bukan 20, halaman di luar rentang tidak kosong) |
| **K-10 uji beban** | `docs/beban/uji-beban.mjs`: 500 permintaan / 50 bersamaan pada `GET /tagihan`, gagal bila p95 > 1.000 ms atau ada non-2xx. Manual, **bukan** gate | `AMBANG_P95=1` → `GAGAL p95 787 ms melewati ambang 1 ms`, kode keluar 1 |

**Hasil uji beban 30 Oktober:** 500 permintaan dalam 6,5 detik, 77 permintaan/detik,
p50/p95/p99 = 596/840/936 ms, 0 galat — **lulus dengan sisa 160 ms**. Angka ini
diukur di laptop yang sama dengan gate, tanpa Chrome; ia bukan jaminan untuk mesin
lain, jadi jalankan ulang sebelum demo (tercatat di `docs/serah-terima.md` §2).

**Satu bug gerbang ditemukan oleh gerbangnya sendiri.** Langkah Vitest pertama
kali ditulis **setelah** `Pop-Location`, jadi ia berjalan di akar repo yang tidak
punya `package.json`: `npm test` gagal karena alasan yang tidak ada hubungannya
dengan kode, dan gate penuh berakhir `GAGAL: 1 langkah (533 detik) - vitest`
padahal `cd web; npm test` hijau 19/19 saat dijalankan tangan. Perbaikannya
memindahkan blok itu **ke dalam** `try` setelah `Push-Location`. Ini alasan
langkah baru wajib lewat gate penuh, bukan hanya `-Cepat`.

**Efek samping yang perlu dicatat:** `TagihanView.vue` sekarang memuat dua
`computed` baru, dan `format.js` komentarnya dikoreksi — ia **selalu** membulatkan
ke rupiah terdekat lewat `Intl` (`maximumFractionDigits: 0`), jadi komentar lama
"tidak pernah membulatkan" salah. Uji yang menuntut pecahan tampil sebagai pecahan
karena itu dihapus, bukan dipaksakan.


## 16. Putaran 30 Oktober 2026 (kedua) — utang aksesibilitas ditutup, dan cacat kontras yang ditemukannya

Gate penuh: **812 tes PHP / 3807 asersi / 47 berkas**, **19 tes Vitest**,
**30/30 skrip lulus, 871 langkah, 479 detik**. Angka ini menggantikan §15;
§11-§15 dibiarkan sebagai riwayat.

Yang dikerjakan adalah satu-satunya utang teknis yang masih berupa kode, yaitu
**uji aksesibilitas otomatis (axe)** yang selama ini hanya tercatat di
`3_ARCHITECTURE.md` §9 dan `7_TESTING_EVALS.md` §6:

| Yang ditutup | Bukti | Kontra-uji |
|---|---|---|
| **Uji aksesibilitas otomatis** | `docs/uji/uji-aksesibilitas.mjs` (27 langkah): axe-core pada 21 halaman - 2 publik, 15 layar pemilik, portal penghuni, halaman Data pribadi, dan halaman 404 - **ditambah tiga keadaan yang baru muncul dari tindakan pengguna** (panel rincian terbuka, form catat pembayaran terbuka, pesan galat validasi), dengan aturan WCAG 2.0/2.1 level A/AA. Terdaftar di `periksa.ps1`, `periksa.sh`, dan `uji.yml` | Skripnya membawa **dua** kontrol negatif: empat cacat yang disisipkan (tautan tanpa nama, gambar tanpa teks alternatif, medan tanpa label, teks kontras rendah) wajib terdeteksi 4/4, dan halaman wajib kembali bersih setelah cacatnya dibuang |
| **Cacat kontras yang ditemukannya** | Kode peristiwa di `PengaturanNotifikasiView.vue` memakai `opacity: 0.8` di atas `--teks-lemah` (#6b6b6b) sehingga menjadi #898989 di atas putih: **3,49:1**, di bawah 4,5:1 yang dituntut AA. Transparansinya dibuang, jadi warnanya kembali 5,3:1 | Transparansinya dikembalikan → 22/23, tepat satu halaman merah dengan pesan `color-contrast (serious, 9x)` |
| **Cacat yang ditemukan keadaan baru** | Tiga keadaan tambahan (panel rincian, form bayar, galat validasi) langsung menemukan cacat kedua: pesan galat tidak pernah diumumkan pembaca layar. `PesanBaris.vue` hanya memasang `role` bila pemanggil menulis `peran="alert"`, dan **0 dari 39** pemanggil menulisnya. Kini `PesanBaris` memasang `role="alert"` sendiri untuk `jenis="galat"` | Diukur pada build lama: `.pesan-galat` punya `role` **null** (`peran: null` dari evaluasi DOM), sementara asersi baru menuntut `[role="alert"]` ada. Pesan galat juga tampil di `.galat-bidang` yang memang tidak ber-`role`, jadi tidak ada elemen lain yang bisa membuat asersi itu lolos |
| **Baris dokumen yang basi** | `docs/utang-teknis.md` §6 masih menulis "navigasi bawah ponsel: belum dikerjakan", padahal `nav.bawah` sudah ada di `TataLetakPemilik.vue:340` sejak Fase 5.1 dan dijaga `uji-tata-letak.mjs` | - |
| **Mojibake yang tertinggal** | `api/tests/Feature/KebijakanKosTest.php` (5x `Ã—` -> `×`) dan `web/src/views/PengaturanPdpView.vue` (2x `Memuatâ€¦` -> `Memuat…`, terlihat pengguna) | Penggantian memakai skrip Node yang menghitung kemunculan lebih dulu dan berhenti bila angkanya tidak sesuai; berkas PHP diperiksa tidak ber-BOM lalu image dibangun ulang |

**Dua mojibake yang SENGAJA tidak diperbaiki:** `api/app/Domain/Laporan/EksporCsv.php`
menulis `"Ayu NurhalizÃ¢"` dan `docs/produk/6_TOOLS_CONTRACTS.md` menulis `Ã©`
sebagai **contoh** hasil rusaknya - keduanya menggambarkan cacat, bukan
mengalaminya. Pembersihan menyeluruh berikutnya jangan "membetulkannya".

**Pelajaran yang berlaku umum:** pemeriksa yang baru dipasang sebaiknya dicoba
pada aplikasi yang **belum** diperbaiki. Axe menemukan satu cacat nyata dalam
hitungan detik pada kode yang sudah lolos 844 langkah E2E dan pembacaan manual
sebelumnya - karena tidak ada skrip yang pernah mengukur kontras.