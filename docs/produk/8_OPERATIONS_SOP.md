# 8. Operations SOP

> Dokumen ini menjawab: **bagaimana menjalankan, mencadangkan, memulihkan, dan
> memperbaiki sistem ini — termasuk saat sedang rusak dan tidak ada waktu
> membaca kode.**
>
> Ditulis untuk orang yang tidak menulis aplikasi ini. Setiap perintah di sini
> pernah dijalankan.

Versi 1.0 · 26 Oktober 2026

---

## 1. Lingkungan

| Lingkungan | Ada di mana | Database | Email |
|---|---|---|---|
| **Lokal** | `docker compose` di laptop | PostgreSQL 16 (container) | Mailpit |
| **CI** | GitHub Actions | PostgreSQL 16 (service) | — |
| **Produksi** | Render (satu layanan) + Neon | PostgreSQL 16 | Brevo |

### 1.1 Port lokal

| Port | Isi |
|---|---|
| `3000` | SPA (nginx) — **buka ini** |
| `8081` | API saja (nginx → PHP-FPM) |
| `8025` | Mailpit UI — semua email tertangkap di sini |
| `9222` | Chrome DevTools Protocol (untuk uji) |
| `5432` | PostgreSQL (internal, tidak diekspos) |

`APP_URL` = `http://localhost:8081` (origin API), sehingga tautan di email
menunjuk ke host API. Uji E2E memindahkan path-nya ke `APP` (port 3000).

### 1.2 Menyalakan

```bash
docker compose up -d
```

Entrypoint menjalankan `migrate --force` lalu `db:seed` secara otomatis.
Idempoten: aman dijalankan setiap start.

```bash
# Keadaan bersih total (HANYA di lokal)
docker compose exec -T api php artisan migrate:fresh --seed
```

**`migrate:fresh` menghapus seluruh tagihan dan pembayaran.** Tidak ada satu pun
keadaan produksi yang membutuhkannya. Entrypoint tidak pernah menjalankannya.

### 1.3 Membangun ulang setelah mengubah kode

Kode **dibake** ke dalam image, bukan di-bind-mount.

```bash
docker compose build api web && docker compose up -d api web
docker compose restart api-web    # WAJIB setelah container api dibuat ulang
```

**Kenapa `restart api-web` wajib.** nginx menyimpan IP upstream saat start.
Setelah container `api` dibuat ulang, IP-nya berubah dan nginx terus mengarah ke
IP lama — semua `/api` mengembalikan **502** tanpa satu pun galat di log PHP.
Gejalanya sangat mudah disalahartikan sebagai kerusakan backend.

---

## 2. Kesehatan

### 2.1 `GET /api/health`

Publik, tanpa auth. Tiga jawaban:

| Status | Badan | Arti |
|---|---|---|
| `200` | `{"data":{"status":"ok",…}}` | Aplikasi dan database sehat |
| `503` | `{"data":{"status":"down","database":"tidak_terjangkau"}}` | Database tidak terjangkau |
| `503` | `{"data":{"status":"down","database":"migrasi_belum_jalan"}}` | Koneksi hidup, tabel `users` belum ada |

Badan juga memuat `zona_waktu` dan `waktu` — zona waktu diumumkan karena
seluruh perhitungan denda bergantung padanya, dan container yang salah waktu
akan menghasilkan denda yang salah tanpa galat apa pun.

**Kenapa bukan `/up` bawaan Laravel.** `/up` hanya menjawab "aplikasi hidup".
Aplikasi yang hidup tetapi tidak bisa menghubungi database akan menjawab `200`
sambil menggagalkan setiap layar. Pertanyaan sebenarnya adalah "apakah
permintaan yang menyentuh database bisa dilayani".

Isinya sengaja hanya status — tanpa nama tabel, versi, pesan galat, atau
hitungan baris. Pemantau uptime tidak butuh lebih, dan pesan galat di endpoint
publik adalah peta jalan bagi penyerang.

### 2.2 Memeriksa manual

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8081/api/health
docker compose ps
docker compose logs --tail=50 api
```

---

## 3. Backup

### 3.1 Membuat

```bash
docker compose exec -T api sh /var/www/html/docker/backup.sh
```

Menulis `/var/backups/kos/kos-<UTC>.sql` dengan `pg_dump --clean --if-exists
--no-owner --no-privileges`, lalu menghapus berkas lebih tua dari 14 hari.

| Variabel | Bawaan | Arti |
|---|---|---|
| `BACKUP_DIR` | `/var/backups/kos` | Tujuan |
| `BACKUP_SIMPAN_HARI` | `14` | Retensi |

**Berkas nol byte adalah kegagalan paling berbahaya** — ia terlihat seperti
backup, berumur benar, dan baru ketahuan rusak saat dibutuhkan. Skrip
memeriksanya dan menghapus berkas kosong sambil keluar dengan kode 1.

### 3.2 Membuktikan backup bisa dipulihkan

```bash
docker compose exec -T api sh /var/www/html/docker/backup.sh --uji-pulih
```

Memulihkan backup terbaru ke database sementara `kos_pulih`, menghitung baris
di `users`, `penghuni`, `kamar`, `kontrak`, `tagihan`, `pembayaran`, `log_audit`,
lalu menghapus database sementara itu.

`ON_ERROR_STOP=1` wajib: tanpa itu `psql` melanjutkan setelah galat dan skrip
melaporkan "berhasil" untuk pemulihan yang kehilangan separuh tabel.

**Backup yang belum pernah dibuka bukan backup.** Jalankan `--uji-pulih` setelah
setiap perubahan skema, dan minimal sebulan sekali.

### 3.3 Yang **belum** dilakukan

**Backup tidak terjadwal.** Tidak ada cron yang memanggilnya. Di Render gratis
tidak ada proses latar yang persisten. Ini dicatat sebagai utang teknis, dan
sampai ditutup, backup adalah tindakan manual yang harus diingat orang.

---

## 4. Pemulihan

### 4.1 Ke database lokal

```bash
# 1. Hentikan penulisan
docker compose stop api

# 2. Pulihkan
docker compose exec -T db psql -U kos -d kos -v ON_ERROR_STOP=1 < kos-20261026T030000Z.sql

# 3. Nyalakan
docker compose start api && docker compose restart api-web
```

`--clean --if-exists` di berkas dump berarti ia menuliskan `DROP` sebelum
`CREATE`, sehingga bisa dipulihkan ke database yang sudah berisi.

### 4.2 Verifikasi setelah pemulihan

```bash
curl -s http://localhost:8081/api/health
docker compose exec -T db psql -U kos -d kos -c "select count(*) from tagihan"
docker compose exec -T db psql -U kos -d kos -c "select count(*) from pembayaran"
```

Angka-angka itu harus masuk akal terhadap waktu backup dibuat. Kalau `tagihan`
ada tetapi `pembayaran` nol, pemulihan kehilangan tabel — dan itu berarti
`ON_ERROR_STOP` tidak dipakai.

### 4.3 Yang **tidak** bisa dipulihkan

Tujuh tabel append-only menyimpan riwayat yang tidak bisa di-`UPDATE`:

`tarif_sewa`, `komponen_biaya`, `kebijakan_kos`, `log_audit`, `pembayaran`,
`verifikasi_pembayaran`, `persetujuan_pdp`.

Setelah pemulihan, baris-baris itu kembali ke keadaan saat backup — termasuk
yang dibuat setelahnya hilang. Itu memang arti pemulihan, tetapi perlu
dipahami: **pemulihan bukan pembatalan.** Pembayaran yang tercatat setelah
backup tidak kembali, dan tidak ada cara "membatalkannya" di aplikasi.

---

## 5. Deploy

### 5.1 Alur

```
tag v*  →  rilis.yml  →  bangun image → dorong ke GHCR
                      →  deploy.yml (staging)
                      →  deploy.yml (produksi)
```

`uji.yml` berjalan pada setiap push: lint → 687 tes PHP → skrip browser di Chrome
headless.

| Workflow | Pemicu |
|---|---|
| `uji.yml` | push, pull request |
| `rilis.yml` | tag `v*`, `workflow_dispatch` |
| `deploy.yml` | `workflow_call` (dipanggil dua kali oleh `rilis.yml`) |

`deploy.yml` mengeluarkan `::warning::` dan `siap=false` bila lingkungannya belum
dikonfigurasi — bukan gagal. Alasannya: target Render/Neon belum ada sampai
LANGKAH 11, dan pipeline yang merah karena hal yang belum ada akan diabaikan
orang.

### 5.2 Sebelum deploy

```bash
bash periksa.sh              # seluruh guardrail
docker compose build api web # pastikan image terbangun
```

### 5.3 Setelah deploy

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://<domain>/api/health
```

`503` berarti database tidak terjangkau — biasanya `DATABASE_URL` salah atau
Neon sedang tidur.

### 5.4 Yang **belum** ada

**`Dockerfile.prod` belum dibuat.** Layanan Render tunggal yang menyajikan SPA
dari `public/` Laravel adalah rencana LANGKAH 11 (T32b). Sampai itu ada,
`deploy.yml` tetap dalam mode peringatan.

---

## 6. Batas layanan gratis

Ini bukan keluhan; ini yang menentukan apa yang boleh dijanjikan.

| Batas | Angka | Akibat |
|---|---|---|
| Render RAM | 512 MB | PHP-FPM dibatasi; jangan naikkan `pm.max_children` |
| Render tidur | setelah 15 menit idle | Permintaan pertama **~50 detik** |
| Render disk | tidak persisten | Backup hilang saat redeploy |
| Neon compute | tidur setelah idle | Koneksi pertama lambat |
| Brevo | 300 email/hari | Penerbitan massal 30 penghuni = 30 email |
| **Layanan** | 1 | SPA harus di dalam `public/` Laravel |

**Cold start 50 detik adalah batas yang paling terasa.** Setiap layar harus
punya keadaan memuat yang jujur — bukan spinner yang berputar tanpa penjelasan.
Pemantau uptime yang memanggil `/api/health` setiap 5 menit akan mencegah tidur,
tetapi itu berarti kuota jam Render terpakai terus.

**Disk tidak persisten** berarti backup di `/var/backups/kos` **hilang** saat
redeploy. Backup produksi harus disimpan di luar layanan (mis. diunggah ke
storage lain), dan itu belum dikerjakan.

---

## 7. Insiden

### 7.1 Semua `/api` → 502 (lokal)

**Penyebab tersering:** container `api` dibuat ulang, nginx memegang IP lama.

```bash
docker compose restart api-web
```

Kalau masih 502:

```bash
docker compose ps                 # api benar-benar Up?
docker compose logs --tail=30 api # PHP-FPM gagal start?
```

### 7.2 Semua `/api` → 502 (produksi)

Periksa `/api/health`. Kalau `503`, database yang bermasalah, bukan nginx.

### 7.3 Login mengembalikan 429 pada kredensial yang benar

Pembatas menyimpan hitungannya di cache, dan `migrate:fresh` tidak
membersihkannya. Uji yang sengaja gagal login berulang kali meninggalkan
hitungan yang menahan pengguna berikutnya.

```bash
docker compose exec -T api php artisan cache:clear
```

Di produksi, `CACHE_STORE` masih `file` — hitungan hilang saat redeploy. Itu
kebetulan menguntungkan, tetapi bukan pengamanan yang bisa diandalkan.

### 7.4 Email tidak terkirim

```bash
# Lokal: buka Mailpit
start http://localhost:8025
```

Kalau Mailpit kosong:

1. `MAIL_MAILER` menunjuk ke `smtp` dan `MAIL_HOST=mailpit`?
2. Apakah notifikasinya memang seharusnya terkirim? `Pemberitahu` tidak pernah
   memberi tahu pelaku tentang tindakannya sendiri.
3. `pengaturan_notifikasi` pengguna — tidak adanya baris berarti **bawaan kode**,
   bukan mati.

Kegagalan email **tidak pernah** menggagalkan permintaan. Ia ditangkap dan
`Log::warning`. Jadi tagihan yang terbit dengan email gagal tetap terbit —
periksa log, bukan respons HTTP.

### 7.5 Data tidak terlihat setelah login

1. `GET /api/me` → periksa `peran` dan `wewenang[]`.
2. Pengelola hanya melihat menu yang wewenangnya ia punya.
3. Penghuni hanya melihat miliknya; data orang lain → **404**, bukan 403.
4. `wajib_ganti_sandi = true` → semua endpoint kecuali `/me`, `/ganti-sandi`,
   `/notifikasi`, `/persetujuan-pdp` mengembalikan **403**.

### 7.6 Halaman menampilkan versi lama setelah deploy

Service worker menyajikan kerangka dari cache.

1. Pastikan `sw.js` disajikan `no-store` (sudah di `default.conf`).
2. Versi baru memanggil `skipWaiting()`, jadi seharusnya langsung berlaku.
3. Kalau tetap: hapus data situs di peramban. Ini **tidak boleh** menjadi
   langkah yang direkomendasikan kepada pengguna — kalau perlu, berarti ada
   bug pada service worker.

### 7.7 Uji E2E menggantung

Sudah dicatat sebagai utang teknis. Di lokal 299 detik; di runner CI 17+ menit
tanpa selesai. Penyebabnya tidak bisa direproduksi di laptop.

Langkah di CI: `continue-on-error: true` dan `timeout-minutes: 20`. **Jangan
menebak pada CI** — pipeline yang merah karena hal yang belum dipahami akan
diabaikan orang.

---

## 8. Variabel lingkungan

| Variabel | Bawaan | Catatan |
|---|---|---|
| `APP_ENV` | `local` | `production` mematikan seeder |
| `APP_URL` | `http://localhost:8081` | Origin API; dipakai tautan email |
| `DB_*` | `kos`/`kos`/`kos` | |
| `APP_DEMO_MODE` | `false` | `true` menggeser "hari ini" untuk demo denda |
| `RUN_MIGRATIONS` | `true` | Entrypoint menjalankan `migrate --force` |
| `RUN_SEEDER` | `true` | **Diabaikan** bila `APP_ENV=production` |
| `SANCTUM_EXPIRATION` | `20160` (14 hari) | Menit |
| `CORS_ALLOWED_ORIGINS` | — | Dipisah koma |
| `MAIL_*` | Mailpit | Brevo di produksi |
| `SESSION_DRIVER` | `file` | Belum dipindah |
| `CACHE_STORE` | `file` | Belum dipindah |
| `QUEUE_CONNECTION` | `sync` | Email dikirim di dalam permintaan |

**`RUN_SEEDER` adalah yang paling berbahaya.** Seeder menulis ulang sandi pemilik
menjadi `password`. Di Render yang tidur setiap 15 menit, itu berarti sandi
pemilik kembali ke `password` setiap kali layanan bangun. Entrypoint menolaknya
di `APP_ENV=production`, dan `DatabaseSeeder` juga menolak — dua lapis, karena
satu lapis pernah tidak cukup.

---

## 9. Rutin

| Kapan | Apa |
|---|---|
| Setiap perubahan kode | `bash periksa.sh` |
| Setiap perubahan skema | `backup.sh --uji-pulih` |
| Setiap minggu | Periksa `/api/health`; baca `log_audit` untuk tindakan tak dikenal |
| Setiap bulan | `backup.sh --uji-pulih`; periksa kuota Brevo; periksa `usulan` yang menunggu |
| Sebelum demo | `migrate:fresh --seed`; pastikan gerbang kebijakan **muncul** (itu fitur) |

**Periksa `usulan` yang menunggu setiap bulan.** Usulan kedaluwarsa dalam 7 hari
dan kedaluwarsanya **dihitung, tidak ditulis** — tidak ada notifikasi yang
memberi tahu bahwa usulan sudah mati. Pengelola yang mengajukan akan melihat
statusnya berubah sendiri, dan pemilik yang tidak pernah membuka `/usulan` tidak
akan tahu ada yang menunggu.

---

## 10. Yang belum ada, dan risikonya

| Belum ada | Risiko yang diterima |
|---|---|
| Backup terjadwal | Backup hanya terjadi kalau ada yang mengingat |
| Backup di luar layanan | Disk Render tidak persisten; backup hilang saat redeploy |
| `Dockerfile.prod` | Deploy belum bisa dijalankan sungguhan |
| Antrean email | Penerbitan massal 30 penghuni = 30 pengiriman berurutan |
| Penerbitan tagihan terjadwal | Pemilik harus menekan tombol setiap bulan |
| Pemantauan uptime | Layanan tidur tanpa ada yang tahu |
| Rotasi log | `CatatPermintaanGagal` menulis satu baris JSON per respons ≥400 |

Semuanya tercatat di `docs/utang-teknis.md` dengan sebab dan rencana penutupan.
Daftar yang tidak dicatat akan terlupakan — dan yang terlupakan akan ditemukan
pada saat paling buruk.
