# 9 — Deploy ke produksi (Render + Neon)

Dokumen ini adalah langkah manual yang **tidak bisa** dikerjakan dari dalam
repo: membuat akun, menyalin kredensial, dan menekan tombol di dashboard.
Yang bisa dikerjakan dari repo sudah dikerjakan — image produksi, penjadwal,
pagar, dan smoke test.

Bacalah bagian **"Yang belum terbukti"** di bawah sebelum menganggap dokumen
ini selesai.

---

## 1. Yang dibuat

| Layanan | Peran | Paket |
|---|---|---|
| **Neon** | PostgreSQL 16 | Free (0,5 GB) |
| **Render** | Satu layanan web dari `Dockerfile.prod` | Free (512 MB, tidur setelah 15 menit) |
| **Brevo** | SMTP untuk email aktivasi dan notifikasi | Free (300 email/hari) |

Kenapa **satu** layanan Render, bukan dua (API + SPA): Render free hanya
memberi satu layanan web per akun untuk paket gratis yang dipakai di sini.
Selain itu, dua origin berarti CORS harus dibuka, cookie sesi lintas situs, dua
cold start (~50 detik masing-masing), dan satu deploy menjadi dua langkah yang
bisa berbeda versi. Dengan satu layanan, browser hanya mengenal satu origin —
dan seluruh kelas masalah itu hilang, bukan diselesaikan.

---

## 2. Neon

1. Buat proyek di <https://console.neon.tech>. Pilih region terdekat
   (Singapore, `ap-southeast-1`).
2. Salin connection string. Bentuknya:
   ```
   postgresql://USER:PASSWORD@ep-xxxx.ap-southeast-1.aws.neon.tech/neondb?sslmode=require
   ```
3. Pecah menjadi variabel Render:

   | Variabel | Nilai |
   |---|---|
   | `DB_CONNECTION` | `pgsql` |
   | `DB_HOST` | `ep-xxxx.ap-southeast-1.aws.neon.tech` |
   | `DB_PORT` | `5432` |
   | `DB_DATABASE` | `neondb` |
   | `DB_USERNAME` | `USER` |
   | `DB_PASSWORD` | `PASSWORD` |
   | `DB_SSLMODE` | `require` |

**`DB_SSLMODE=require` wajib.** Neon menolak koneksi tanpa TLS, dan tanpa
variabel ini entrypoint akan menunggu 120 detik lalu gagal dengan pesan yang
tidak menyebut TLS sama sekali.

> **Koreksi (FASE 0.3, 26 Okt 2026).** Sampai FASE 0, baris di atas **tidak
> benar**: `api/config/database.php:97` menulis `'sslmode' => 'prefer'` secara
> mati, sehingga menyetel `DB_SSLMODE` tidak berpengaruh apa pun. Seseorang bisa
> mengikuti dokumen ini dengan benar, merasa sudah mengamankan koneksi, dan
> sebenarnya tidak. Sekarang nilainya benar-benar dibaca
> (`env('DB_SSLMODE', 'prefer')`), dan `docs/uji/uji-pagar-fase0.sh`
> membuktikannya: `DB_SSLMODE=require` terbaca `require`, dan tanpa variabel
> tetap `prefer` sehingga PostgreSQL lokal tanpa TLS tetap tersambung.


---

## 3. Render

1. **New → Web Service → Build and deploy from a Git repository.**
2. Repo `ryry55/project-rpl`, branch `main`.
3. Runtime: **Docker**. Dockerfile path: `Dockerfile.prod`.
   Docker build context: `.` (akar repo — image ini butuh `web/` dan `api/`).
4. Health check path: `/api/health`.
5. Environment variables:

   | Variabel | Nilai | Kenapa |
   |---|---|---|
   | `APP_ENV` | `production` | Mengaktifkan `--force` pada migrasi |
   | `APP_KEY` | `base64:...` | **Hasilkan di laptop**, jangan di server |
   | `APP_DEBUG` | `false` | Entrypoint **berhenti** bila `true` |
   | `APP_DEMO_MODE` | `false` | Entrypoint **berhenti** bila `true` |
   | `APP_URL` | `https://kos-kosan.my.id` | Basis URL |
   | `APP_URL_SPA` | `https://kos-kosan.my.id` | Satu origin, jadi sama |
   | `SESSION_DRIVER` | `database` | Sesi bertahan saat container restart |
   | `CACHE_STORE` | `database` | Pembatas login bertahan saat restart |
   | `QUEUE_CONNECTION` | `sync` | Lihat DAT-03 di `docs/utang-teknis.md` |
   | `RUN_SEEDER` | `false` | Seeder menulis sandi `password` |
   | `LOG_CHANNEL` | `stderr` | Log terbaca dashboard Render |
   | `MAIL_MAILER` | `smtp` | Brevo |
   | `MAIL_HOST` | `smtp-relay.brevo.com` | |
   | `MAIL_PORT` | `587` | |
   | `MAIL_USERNAME` / `MAIL_PASSWORD` | dari Brevo | |
   | `MAIL_FROM_ADDRESS` | alamat terverifikasi di Brevo | |

6. **Membuat `APP_KEY`:**
   ```bash
   docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
   ```
   Salin hasilnya. **Jangan** biarkan entrypoint membuatnya: kunci yang dibuat
   ulang setiap restart memutus semua sesi dan membuat data terenkripsi lama
   tidak terbaca. Entrypoint sengaja berhenti bila `APP_KEY` kosong.

7. Setelah deploy pertama hijau, ambil **Deploy Hook** dari
   *Settings → Deploy Hook*, lalu isikan di GitHub:
   - *Settings → Environments → production* → variabel `APP_URL`
   - rahasia `RENDER_DEPLOY_HOOK`

   Tanpa keduanya, `deploy.yml` melaporkan `siap=false` dan tidak melakukan
   apa pun. Itu disengaja: workflow yang selalu merah karena rahasia yang
   memang belum ada akan diabaikan orang, dan setelah diabaikan ia tidak lagi
   menjaga apa pun.

---

## 4. Setelah deploy pertama

```bash
# 1. Kesehatan dan database
curl -s https://kos-kosan.my.id/api/health

# 2. Akun demo TIDAK boleh bisa masuk
curl -s -o /dev/null -w '%{http_code}\n' \
  -X POST https://kos-kosan.my.id/api/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"pemilik@kos.test","password":"password"}'
# harus 401/422, BUKAN 200

# 3. Smoke test lengkap (4 bahaya produksi)
bash docs/uji/smoke-produksi.sh https://kos-kosan.my.id
```

Lalu **buat akun pemilik sungguhan**. Produksi tidak punya akun apa pun setelah
seeder dimatikan, dan tanpa akun tidak ada yang bisa masuk:

```bash
# Jalankan sekali dari dashboard Render → Shell
php artisan kos:buat-pemilik
```

Perintah itu menanyakan nama, email, dan sandi satu per satu. Sandi **tidak
ditampilkan** saat diketik dan tidak masuk ke riwayat shell. Bila sandi
dibiarkan kosong, sistem membuatkan sandi acak dan menampilkannya sekali -
dalam hal itu akun ditandai wajib ganti sandi saat pertama masuk.

**Kenapa bukan `php artisan tinker` lagi.** Blok lama membuat baris `User`
langsung dari shell, dan itu melewati empat hal yang semuanya penting:

| Yang dilewati | Akibatnya |
|---|---|
| Pemeriksaan jumlah pemilik | Dua pemilik bisa ada tanpa ada yang menyadarinya, dan keduanya melihat data yang sama |
| Aturan panjang sandi | Sandi satu huruf diterima, padahal jalur HTTP menolaknya - jadi aturan yang diuji bukan aturan yang dipakai |
| Penandaan wajib ganti sandi | Sandi yang diketik di shell orang lain tetap berlaku selamanya |
| Jejak audit `pemilik.tambah` | Tidak ada catatan bahwa akun pemilik pernah dibuat, oleh siapa, dan kapan |

Perintah ini juga menolak berjalan bila sudah ada pemilik, kecuali diberi
`--paksa`. Penolakan itu disengaja: akun pemilik kedua adalah perubahan
kepemilikan, bukan penambahan pengguna biasa.

```bash
# Menambah pemilik kedua (mis. kos diwariskan) - harus disengaja
php artisan kos:buat-pemilik --paksa
```

Perintah ini **tidak punya endpoint HTTP**, dan itu keputusan, bukan kekurangan.
Tidak ada cara membuktikan bahwa pemanggil sebuah endpoint berhak membuat akun
pemilik pertama - pada saat itu belum ada pemilik yang bisa memberi izin. Setiap
endpoint semacam itu adalah pintu belakang yang tidak bisa ditutup. Yang bisa
memberi izin adalah akses ke server itu sendiri, dan siapa pun yang punya akses
itu sudah bisa membaca seluruh basis data secara langsung.

---

## 5. Yang belum terbukti, dan harus dikatakan

| Hal | Keadaan |
|---|---|
| Deploy sungguhan ke Render | **Belum pernah dijalankan.** Tidak ada akun Render di lingkungan pengembangan ini. Yang sudah dibuktikan: image berhasil dibangun dan berjalan terhadap database lokal, menyajikan SPA dan API dari satu origin, serta menolak tiga konfigurasi berbahaya |
| Koneksi ke Neon | **Belum diuji.** Entrypoint mendukung `DB_SSLMODE`, tetapi koneksi sungguhan ke Neon belum pernah terjadi |
| Pengiriman email lewat Brevo | **Belum diuji.** Kode memakai SMTP standar; Mailpit lokal yang dipakai selama pengembangan |
| Midtrans | **Kodenya ada, transaksi sungguhan belum.** `MidtransGateway` (tanda tangan SHA512, pemetaan status), `PembayaranGateway`, tabel `transaksi_gateway`, dua endpoint, dan tombol **Bayar daring** sudah berjalan; 16 tes memakai kredensial palsu. Yang belum: transaksi ke sandbox Midtrans — butuh `MIDTRANS_SERVER_KEY`. Lihat `docs/utang-teknis.md` §4 |

Menuliskan ini sebagai selesai akan membuat audit berikutnya mencari bukti yang
tidak ada.

---

## 6. Batas paket gratis yang harus diketahui pemilik kos

| Batas | Akibat nyata |
|---|---|
| Render tidur setelah 15 menit tanpa pengunjung | Pengguna pertama pagi hari menunggu ~50 detik |
| 512 MB RAM | Cukup untuk satu kos (≤30 kamar); bukan untuk banyak kos sekaligus |
| Tidak ada disk persisten | Backup harian **hilang** saat restart. Unduh manual bila perlu arsip |
| Neon 0,5 GB | Cukup untuk bertahun-tahun data satu kos; pantau di dashboard |
| Brevo 300 email/hari | Terbit massal 30 penghuni = 30 email. Dua kali terbit per hari masih aman |
