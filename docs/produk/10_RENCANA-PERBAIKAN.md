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

| # | Pekerjaan | Bukti yang harus dipegang |
|---|---|---|
| 0.1 | Pasang `trustProxies(at: '*')` di `bootstrap/app.php` | Tes: request dengan `X-Forwarded-Proto: https` → `isSecure()` true; `X-Forwarded-For` → `ip()` mengembalikan IP asli |
| 0.2 | Perbaiki komentar bohong di `docker/prod/nginx.conf.template:80` | Komentar menyebut di mana `trustProxies` benar-benar dipasang |
| 0.3 | `'sslmode' => env('DB_SSLMODE', 'prefer')` | Tes: `DB_SSLMODE=require` benar-benar terbaca; tambahkan ke `.env.example` + `docker-compose.prod.yml` |
| 0.4 | Pagar `MAIL_FROM_ADDRESS` di `entrypoint-prod.sh` | Dijalankan dengan nilai `kos@contoh.test` → keluar kode 1 dengan alasan terbaca |
| 0.5 | `opcache.validate_timestamps = 0` + komentar alasannya | Container produksi boot, `/api/health` 200 |

**Definisi selesai:** `periksa.ps1` lulus; `docker build -f Dockerfile.prod` +
`docker run` boot bersih; keempat pagar 0.1/0.3/0.4/0.5 dibuktikan bisa gagal
dengan menyuntikkan cacatnya kembali.

**Mengapa paling dulu:** 0.1 mengubah cara seluruh aplikasi membaca IP dan
skema. Mengerjakannya setelah fase lain berarti menguji ulang semuanya.

---

## FASE 1 — Deploy sungguhan ke Render + Neon (1 hari, butuh akun)

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

## FASE 2 — Uang sungguhan: Midtrans (1–2 hari, butuh kredensial)

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

---

## FASE 5 — Kenyamanan dan umur panjang (opsional, 1–2 hari)

Bukan syarat produksi. Kerjakan hanya kalau ada waktu, dan **jangan** sebelum
Fase 0–3 selesai.

| # | Pekerjaan | Alasan |
|---|---|---|
| 5.1 | Navigasi bawah khusus ponsel | Pemilik kos memakai ponsel, bukan laptop |
| 5.2 | Uji lanskap 640×360 | `manifest` mengunci portrait; sebagian pengguna memutar layar |
| 5.3 | Uji luring sungguhan (`Network.emulateNetworkConditions`) | Kerangka ter-cache tetapi belum pernah dibuktikan |
| 5.4 | `APP_LOCALE=id` + pesan validasi Indonesia (C5) | Seluruh UI Indonesia, pesan galat Inggris |
| 5.5 | Pertimbangkan queue worker keempat di supervisord | Alasan lama gugur (C4); nilainya baru terasa bila email > 100/hari |

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
| Pinger vs cron vs manual (A3) | Menyangkut kuota dan kebiasaan operasional Anda |
| Domain `.my.id` mana yang dipakai | Menentukan `APP_URL`, SPF/DKIM, dan tautan email |
| Naik paket Brevo atau tidak (B3) | Menyangkut biaya |
| Apakah Midtrans langsung produksi atau sandbox dulu | Menyangkut risiko uang sungguhan |

Sisanya pekerjaan teknis yang bisa saya kerjakan.

---

## Ukuran keberhasilan

Bukan "semua fase selesai". Ukurannya satu kalimat:

> **Seorang pemilik kos yang tidak pernah melihat kode ini bisa menambah
> penghuni, menerbitkan tagihan, menerima pembayaran, dan tahu bahwa uangnya
> tercatat — tanpa menelepon Anda.**

Selama kalimat itu belum benar, aplikasi ini masih tugas kuliah yang bagus.
