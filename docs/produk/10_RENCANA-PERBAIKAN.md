# 10 - Rencana perbaikan menuju siap produksi

Dokumen ini menerjemahkan temuan `11_AUDIT-PRODUKSI-2.md` menjadi **fase kerja
yang berurutan**. Urutannya bukan selera: setiap fase dibangun di atas fase
sebelumnya, dan mengerjakannya mundur akan menghasilkan pekerjaan yang harus
diulang.

**Aturan umum yang berlaku di semua fase:**

1. Satu fase = satu commit (atau beberapa, tetapi satu pull request).
2. Setiap perbaikan **wajib punya pagar** yang terbukti bisa gagal. Pagar yang
   tidak pernah terbukti gagal bukan pagar, ia hanya kode yang menemani.
3. `periksa.ps1` / `periksa.sh` harus lulus sebelum fase ditutup.
4. Temuan yang tidak bisa diuji dari laptop **ditandai belum dapat diuji**, tidak
   ditulis "selesai".

---

## FASE 0 — Fondasi yang menentukan deploy berhasil atau tidak (½ hari)

Tanpa fase ini, deploy pertama akan **berhasil secara teknis tetapi salah secara
perilaku**. Inilah fase yang harus dikerjakan lebih dulu, bukan yang paling
menarik.

| # | Pekerjaan | Bukti yang harus dipegang | Status |
|---|---|---|---|
| 0.1 | Pasang `trustProxies` di `bootstrap/app.php` | **`at:` diisi rentang privat, BUKAN `'*'`.** Lihat catatan di bawah — `'*'` tidak bekerja untuk dua hop | **SELESAI** |
| 0.1b | **Uji yang menyentuh jalur proxy** — inilah yang selama ini tidak ada | `docs/uji/uji-proxy.mjs`: 25 permintaan dengan `X-Forwarded-For` berbeda harus 25×200; satu IP sama harus tetap kena 429 di percobaan ke-21; log harus mencatat IP klien, bukan IP container | **SELESAI** |
| 0.1c | **`fastcgi_param HTTP_X_FORWARDED_FOR` di nginx** — tidak ada di rencana awal | Tanpa ini `trustProxies` tidak punya apa pun untuk dipercaya. Ditemukan karena uji 0.1b gagal 0/5 meski 0.1 sudah terpasang | **SELESAI** |
| 0.2 | Perbaiki komentar bohong di `docker/prod/nginx.conf.template:80` | Komentar kini menyebut pasangannya di `bootstrap/app.php` dan **mengapa** `fastcgi_param` wajib | **SELESAI** |
| 0.3 | `'sslmode' => env('DB_SSLMODE', 'prefer')` | `DB_SSLMODE=require` terbaca `require`; tanpa env tetap `prefer`. Ditambahkan ke `.env.example` | **SELESAI** |
| 0.4 | Pagar `MAIL_FROM_ADDRESS` di `entrypoint-prod.sh` | Menyala hanya bila `MAIL_HOST` bukan penangkap lokal — **bukan** berdasarkan `APP_ENV`, supaya profil produksi tetap bisa dijalankan di laptop | **SELESAI** |
| 0.5 | `opcache.validate_timestamps = 0` | Berkas **terpisah** (`opcache-prod.ini`) agar image pengembangan tidak ikut terkena | **SELESAI** |

**Definisi selesai:** `periksa.ps1` lulus; `docker build -f Dockerfile.prod` +
`docker run` boot bersih; keempat pagar 0.1/0.3/0.4/0.5 dibuktikan bisa gagal
dengan menyuntikkan cacatnya kembali.

> ### Tiga hal yang rencana awal salah, dan baru ketahuan saat dikerjakan
>
> **1. `at: '*'` tidak menyelesaikan masalah.** Di Laravel, `'*'` TIDAK berarti
> "percayai semua proxy"; ia berarti "percayai HANYA alamat yang sedang
> memanggil". Untuk satu hop itu cukup. Demo ini punya **dua** hop — klien →
> `web` → `api-web` → php-fpm — sehingga entri terakhir di `X-Forwarded-For`
> adalah gateway `web`. Diukur langsung:
>
> ```
> at: '*'                -> ip() = 172.20.0.1    (gateway, SALAH)
> at: [rentang privat]   -> ip() = 203.0.113.1   (klien, BENAR)
> ```
>
> Karena itu `at:` berisi rentang privat (`127.0.0.1`, `172.16/12`,
> `192.168/16`, `10/8`, `::1`). Alamat publik **tidak** dipercaya, sehingga
> `X-Forwarded-For` dari internet tidak bisa dipakai memalsukan IP.
>
> **2. `trustProxies` saja tidak cukup.** `include fastcgi_params` di nginx
> hanya mengoper `REMOTE_ADDR`, **tidak satu pun** header HTTP. Tanpa
> `fastcgi_param HTTP_X_FORWARDED_FOR` dan `..._PROTO`, nginx membuang header
> yang sudah dikirim `web` sebelum sampai ke Laravel. Ini terbukti: uji 0.1b
> gagal **0/5** meski 0.1 sudah terpasang, lalu lulus 5/5 setelah 0.1c
> ditambahkan. Rencana awal hanya menyebut 0.1.
>
> **3. Pagar email tidak boleh memakai `APP_ENV`.** Profil produksi memang
> dirancang bisa dijalankan di laptop untuk menguji pagar-pagarnya, dan di sana
> `MAIL_HOST=mailpit` dengan `kos@contoh.test` adalah keadaan yang **benar**.
> Pagar yang menyala di situ akan memaksa orang mematikan pagar itu sendiri.
> Karena itu pagarnya memeriksa `MAIL_HOST`: menyala hanya bila tujuannya SMTP
> jarak jauh.


**Mengapa paling dulu:** 0.1 mengubah cara seluruh aplikasi membaca IP dan
skema. Mengerjakannya setelah fase lain berarti menguji ulang semuanya.

> **Fase 0 TIDAK butuh akun, dan tidak butuh menunggu deploy.** Seluruh
> perbaikannya bisa dibuktikan di lingkungan demo — A1 bahkan **sudah**
> direproduksi di sana lewat port proxy `:3000`. Yang kurang selama ini bukan
> lingkungan, melainkan uji yang menyentuh jalur proxy (0.1b).

---

## FASE 0.5 — Agar bisa dipakai orang yang baru pertama melihatnya (½ hari)

**Dikerjakan 26 Okt 2026, sebelum Fase 1, karena tidak butuh akun apa pun.**

Fase ini menjawab satu pertanyaan yang tidak dijawab tes mana pun: *apakah orang
yang baru menerima aplikasi ini bisa memakainya tanpa bertanya?* Semua tes yang
ada menjawab "apakah aplikasinya benar", dan jawabannya ya. Yang tidak dijawab
adalah "apakah orangnya tahu harus mulai dari mana".

| # | Pekerjaan | Bukti |
|---|---|---|
| 0.5.1 | Perintah `kos:buat-pemilik`, menggantikan blok `tinker` di `9_DEPLOY.md` | 10 tes; 4 cacat disuntikkan, 4 tertangkap |
| 0.5.2 | `GET /penyiapan` — status penyiapan dihitung dari data hidup | 12 tes; 4 cacat, 4 tertangkap |
| 0.5.3 | Ekspor CSV rekap / tunggakan / pembayaran | 16 tes; 6 cacat, 6 tertangkap; berkas terunduh diverifikasi 1183 byte + BOM |
| 0.5.4 | Penanda penyiapan di halaman muka + halaman Bantuan | `e2e-fase35.mjs` 18/18 |
| 0.5.5 | SOP pemulihan email gagal (`8_OPERATIONS_SOP.md` §7.4.1) | Perintahnya dijalankan dan menghasilkan tautan sah |

> **Batas yang harus dikatakan.** Fase ini **tidak** memperbaiki A3 (penjadwal
> mati bersama container) dan **tidak** menggantikan Fase 1. Yang ia lakukan
> adalah memastikan bahwa setelah deploy pertama berhasil, pemilik kos bisa
> menyiapkan kosnya sendiri tanpa membaca kode.

**Dua temuan yang muncul di fase ini dan berlaku di luar fase ini:**

1. **`uji-proxy.mjs` hanya didaftarkan di `periksa.ps1`.** Pagar paritas CI sudah
   merah sejak `c331d60`, dan di Linux/CI cacat A1 tidak pernah diuji sama sekali.
   Kegagalan yang terbaca "daftar skrip berbeda" sebenarnya berarti "satu pagar
   tidak pernah dipasang". `periksa.sh` sekarang setara.
2. **Tes HTTP tidak bisa menguji netralisasi angka CSV.** Controller mengirim
   `(int)`, sehingga cabang yang diuji tidak pernah dilewati dan cacatnya lulus.
   Ditutup dengan tes penulis langsung.

---

> **FASE 1, FASE 2, 3.1, 3.2, 3.4, dan 4.3 DIBATALKAN 29 Oktober 2026.**
> Pemilik memutuskan aplikasi **tidak di-deploy**: penilaian dan demo cukup dari
> laptop lewat `docker compose`. Kode deploy tetap ada di repo sebagai bukti
> kerja, tetapi tidak ada akun Render/Neon/domain, tidak ada transaksi Midtrans
> sungguhan, dan tidak ada container yang bisa tidur — jadi K-06 (A3) tidak lagi
> punya pertanyaan. Bagian di bawah disimpan apa adanya sebagai rujukan kalau
> keputusan itu berubah.

## FASE 1 — Deploy sungguhan ke Render + Neon (1 hari, butuh akun) — **DIBATALKAN**

Ini bukan pekerjaan kode. Ini **pengujian yang belum pernah dijalankan**, dan
justru di sinilah dua bug terbesar bersembunyi.

| # | Pekerjaan | Bukti |
|---|---|---|
| 1.1 | Buat proyek Neon; ambil connection string dengan `sslmode=require` | `psql` dari laptop berhasil, `SHOW ssl` = on |
| 1.2 | Buat layanan Render dari image GHCR; setel seluruh env | `GET /api/health` → `database: ok`, `zona_waktu: Asia/Jakarta` |
| 1.3 | Setel `APP_URL` + `APP_URL_SPA` ke domain `.my.id` sungguhan | Tautan aktivasi di email menunjuk domain itu, bukan localhost |
| 1.4 | Verifikasi domain Brevo (SPF + DKIM) | Email aktivasi sampai ke Gmail, **tidak** di folder spam |
| 1.5 | Putuskan nasib penjadwal (pinger / cron / manual) lalu **uji** | Tagihan periode berikutnya benar-benar terbit tanpa disentuh |
| 1.6 | Isi environment GitHub `staging`/`production` | `deploy.yml` melaporkan `siap=true`, bukan `siap=false` |
| 1.7 | Jalankan `smoke-produksi.sh` terhadap domain sungguhan | Semua butir lulus |

**Definisi selesai:** satu penghuni uji benar-benar menerima email aktivasi di
kotak masuknya, mengaktifkan akun, masuk, dan melihat tagihannya — dari ponsel,
lewat internet, tanpa laptop Anda.

**Risiko yang harus diterima:** Render free tidur. Catat waktu cold start
sungguhan (bukan perkiraan 50 detik) setelah diukur.

---

## FASE 2 — Uang sungguhan: Midtrans (1–2 hari, butuh kredensial) — **DIBATALKAN**

| # | Pekerjaan | Bukti |
|---|---|---|
| 2.1 | Transaksi sandbox pertama dengan `MIDTRANS_SERVER_KEY` asli | `transaksi_gateway` terisi, `redirect_url` bisa dibuka |
| 2.2 | Webhook publik lewat HTTPS (butuh Fase 1 selesai) | Midtrans mengirim notifikasi, tanda tangan diterima, status berubah |
| 2.3 | Uji notifikasi **palsu** dan **berulang** | Tanda tangan salah → 401 tanpa penjelasan; notifikasi ganda → tidak menggandakan pembayaran |
| 2.4 | Uji nominal tidak cocok | Ditolak, tercatat |
| 2.5 | Naik ke produksi Midtrans, kunci produksi disimpan sebagai rahasia Render | Satu transaksi Rp 1 sungguhan berhasil |

**Definisi selesai:** uang benar-benar masuk dan tercatat, dan notifikasi palsu
tidak bisa menandai tagihan lunas.

**Jangan lewati 2.3.** Webhook adalah satu-satunya pintu di aplikasi ini yang
terbuka tanpa autentikasi. Tanda tangan adalah satu-satunya bukti.

---

## FASE 3 — Agar kegagalan terlihat (½ hari)

Fase ini yang membedakan "sudah deploy" dari "bisa dioperasikan".

| # | Pekerjaan | Bukti |
|---|---|---|
| 3.1 | Pinger uptime memeriksa `/api/health`, memberi kabar bila bukan 200 | Matikan database sementara → pemberitahuan masuk |
| 3.2 | Penanda "tagihan periode ini belum terbit" di dasbor pemilik | Periode kosong → penanda muncul |
| 3.3 | Catat kegagalan kirim email ke `log_audit` | SMTP dimatikan → baris audit bertambah, bukan hilang senyap |
| 3.4 | Latihan restore dari Neon ke database percobaan | Data kembali; langkahnya ditulis di `8_OPERATIONS_SOP.md` |
| 3.5 | Hapus data uji Midtrans dari database lokal | `bayar-*@kos.test` tidak ada lagi |

**Definisi selesai:** Anda bisa menjawab "bagaimana saya tahu kalau aplikasi ini
rusak?" tanpa mengandalkan ingatan.

**Status 29 Oktober 2026.** 3.3 **selesai** (`log_audit` aksi `email.gagal`,
dijaga satu tes yang terbukti bisa gagal). 3.5 **sudah bersih** — diperiksa
langsung di basis data demo: `users` hanya berisi tiga akun demo, tidak ada
`bayar-*@kos.test`. 3.1, 3.2, dan 3.4 **dibatalkan** bersama deploy: pemantau
uptime, penanda periode, dan latihan restore hanya bermakna bila ada layanan
yang berjalan terus.

---

## FASE 4 — Pengerasan keamanan (½ hari)

| # | Pekerjaan | Bukti |
|---|---|---|
| 4.1 | `Strict-Transport-Security` | Header muncul di respons produksi |
| 4.2 | CSP mode `Report-Only` lebih dulu, minimal 1 minggu | Tidak ada pelanggaran berarti di konsol |
| 4.3 | Naikkan CSP ke menegakkan; izinkan domain Midtrans | Halaman bayar daring tetap bekerja |
| 4.4 | `client_max_body_size 4m` (menutup C1) | Unggah QRIS 1,9 MB berhasil dengan pesan ramah |
| 4.5 | `Cache-Control: no-store` untuk `/api/*` terautentikasi | Respons tidak tersimpan di cache proxy |
| 4.6 | Uji ulang pembatas login setelah `trustProxies` (B4) | Dua IP berbeda tidak saling mengunci |
| 4.7 | `report-uri` + kolektor laporan CSP | Laporan pelanggaran masuk log, bukan hanya konsol peramban |

**Status 29 Oktober 2026.** 4.1, 4.2, 4.4, 4.5, 4.7 **selesai**, semuanya
diperiksa pada image produksi sungguhan, bukan pada konfigurasi laptop. 4.6
sudah tertutup sejak Fase 0 (`uji-proxy.mjs`: 25 IP berbeda → 25×200, satu IP →
429 di percobaan ke-21). 4.3 **dibatalkan** bersama deploy: menegakkan CSP
menuntut seminggu laporan dari layanan sungguhan, dan tidak ada layanan yang
dijalankan. `Report-Only` + kolektornya tetap terpasang sebagai bukti kerja.

4.7 dikerjakan karena 4.3 tidak bisa dijalankan tanpanya: `Report-Only` tanpa
`report-uri` hanya menulis ke konsol pengguna, sehingga "seminggu laporan
bersih" tidak akan pernah bisa diperiksa dari sini. Kolektornya
`POST /csp-laporan` (`CspLaporanController`, `throttle:30,1`), menulis satu baris
log berawalan `csp.lapor`, dan sengaja **di luar `/api/`** supaya kontrak 86
endpoint tidak berubah. Catatan lama di sini berbunyi "yang harus dilakukan
pemilik setelah aplikasi hidup: cari `csp.lapor` selama seminggu" — itu batal
bersama deploy. Kolektornya tetap ada dan tetap diuji.

---

## FASE 5 — Kenyamanan dan umur panjang (opsional, 1–2 hari)

Bukan syarat produksi. Kerjakan hanya kalau ada waktu, dan **jangan** sebelum
Fase 0–3 selesai.

**Status 29 Oktober 2026: 5.1, 5.2, 5.3, 5.4, 5.5 kelima-limanya SELESAI,**
setelah pemilik menyetujui tiga yang menyentuh perilaku yang dilihat pengguna.

| # | Pekerjaan | Alasan |
|---|---|---|
| 5.1 | Navigasi bawah khusus ponsel — **SELESAI 29 Okt** | Pemilik kos memakai ponsel, bukan laptop. `TataLetakPemilik.vue` merender tiga tujuan (Tagihan, Tunggakan, Rekap/Tunai saya) + tombol Menu dari larik `menu` yang sama, jadi label per peran tetap satu sumber. Dijaga `uji-tata-letak.mjs` pada 360/390/768px, ditambah asersi bahwa ia hilang di 1280px |
| 5.2 | Uji lanskap 640×360 — **SELESAI** | `manifest` mengunci portrait; sebagian pengguna memutar layar |
| 5.3 | Uji luring sungguhan (`Network.emulateNetworkConditions`) — **SELESAI** | Kerangka ter-cache tetapi belum pernah dibuktikan |
| 5.4 | `APP_LOCALE=id` + pesan validasi Indonesia (C5) — **SELESAI 29 Okt** | Seluruh UI Indonesia, pesan galat Inggris. `api/lang/id/validation.php` (kunci lengkap + peta nama kolom), `APP_LOCALE=id` di `.env.example` dan daftar env `9_DEPLOY.md`. Dijaga `DataIndukTest::test_pesan_validasi_bawaan_berbahasa_indonesia` |
| 5.5 | Queue worker keempat di supervisord — **SELESAI 29 Okt** | `[program:antrean]` + `QUEUE_CONNECTION=database` di produksi. `Pemberitahu::setelahTransaksi` menaruh satu `KirimPemberitahuan` per penerima, jadi 30 penghuni bukan lagi 30 SMTP berurutan. Kegagalan kirim tetap masuk `log_audit` (B3) karena ditangkap di `Pemberitahu::kirimEmail`. Dijaga `NotifikasiTest::test_email_notifikasi_masuk_antrean` |

---

## FASE 6 — Laporan (menunggu dosen)

Tidak dikerjakan sampai format dari dosen ada. Bukan utang teknis; ini utang
informasi.

---

## Peta ketergantungan

```
FASE 0 ──► FASE 1 ──► FASE 2 ──► FASE 3 ──► FASE 4 ──► FASE 5
(fondasi)  (deploy)   (uang)     (terlihat)   (keras)     (nyaman)
              │
              └──► FASE 3 bisa dikerjakan paralel dengan FASE 2

FASE 6: mandiri, menunggu pihak luar
```

Fase 2 **tidak bisa** dimulai sebelum Fase 1: webhook Midtrans menuntut alamat
HTTPS publik. Fase 4 **tidak boleh** mendahului Fase 1: tidak ada gunanya
memasang header keamanan pada layanan yang belum ada.

---

## Yang harus diputuskan pemilik, bukan dikerjakan sendiri

| Keputusan | Mengapa harus Anda |
|---|---|
| ~~Pinger vs cron vs manual (A3)~~ | **Tidak berlaku** — deploy dibatalkan 29 Okt 2026 |
| ~~Domain `.my.id` mana yang dipakai~~ | **Tidak berlaku** — tidak ada domain |
| ~~Naik paket Brevo atau tidak (B3)~~ | **Tidak berlaku** — email hanya lewat Mailpit |
| ~~Midtrans langsung produksi atau sandbox dulu~~ | **Tidak berlaku** — gateway hanya diuji dengan payload tiruan |

Sisanya pekerjaan teknis yang bisa saya kerjakan.

---

## Ukuran keberhasilan

Bukan "semua fase selesai". Ukurannya satu kalimat:

> **Seorang pemilik kos yang tidak pernah melihat kode ini bisa menambah
> penghuni, menerbitkan tagihan, menerima pembayaran, dan tahu bahwa uangnya
> tercatat — tanpa menelepon Anda.**

Selama kalimat itu belum benar, aplikasi ini masih tugas kuliah yang bagus.
