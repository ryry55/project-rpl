# Audit kesiapan produksi — putaran 2 (26 Okt 2026)

Dokumen ini adalah **hasil audit kedua**, dikerjakan setelah `utang-teknis.md`
ditulis. Tujuannya satu: memisahkan "cukup untuk dinilai" dari "cukup untuk
dipakai kos sungguhan". Ambangnya berbeda, dan selisihnya tidak kecil.

Semua temuan di bawah diperiksa langsung terhadap kode dan konfigurasi di repo
ini pada commit `43124d8`. Yang tidak dapat diuji dari laptop (deploy Render,
koneksi Neon, transaksi Midtrans) ditandai **belum dapat diuji** — bukan
diklaim selesai.

---

## 0. Ringkasan

| Tingkat | Jumlah | Arti |
|---|---|---|
| **A. Penghalang produksi** | 5 | Aplikasi akan berperilaku salah atau gagal senyap di produksi |
| **B. Risiko tinggi** | 6 | Tidak menjatuhkan aplikasi, tetapi menimbulkan kerugian nyata |
| **C. Pengerasan** | 7 | Layak dikerjakan, tidak mendesak |
| **D. Sengaja tidak dikerjakan** | 4 | Bukan cacat; mencatatnya mencegah pengulangan |

Temuan **A1–A5 tidak ada** di `utang-teknis.md`. Itu bukan kesalahan penulisan
dokumen itu: kelimanya hanya muncul di lingkungan yang **tidak punya proxy TLS**,
**tidak pernah tidur**, dan **tidak memakai layanan email sungguhan**. Seluruh
pengujian selama ini berjalan di laptop, dan laptop tidak pernah begitu.

**Pelajaran yang berlaku umum:** pagar yang hanya diuji di satu lingkungan akan
lolos tepat pada hal yang hanya muncul di lingkungan lain.

---

## A. Penghalang produksi

### A1. `trustProxies` tidak ada — IP semua pengguna menjadi IP proxy

**Bukti.** `grep -r trustProxies api/` → **nol hasil**. Diukur di container:

```
trusted proxies : []
ip()            : 10.9.9.9        (X-Forwarded-For diabaikan)
isSecure()      : false
url('/')        : http://10.0.0.1/api/x
```

Setelah `trustProxies` dipasang:

```
isSecure()      : true
url('/')        : https://kos-kosan.my.id/api/x
```

**Komentar di `docker/prod/nginx.conf.template:80` menulis** *"`X-Forwarded-Proto`
sudah diteruskan Laravel lewat trustProxies"*. **Komentar itu tidak benar.** Ia
menggambarkan sesuatu yang tidak pernah ditulis.

**Akibat nyata di Render** (yang meneruskan TLS dari proxy di depannya):

1. `$request->ip()` berisi IP proxy Render — **sama untuk semua pengguna**.
2. `PembatasMasuk::bolehPeriksaToken()` dibatasi **20/menit per IP**. Karena
   semua orang berbagi satu IP, satu pengunjung dengan tautan rusak bisa
   **mengunci aktivasi akun semua penghuni** selama 60 detik. Ini bukan teori:
   batasnya per-IP, dan IP-nya kini satu.
3. `log_audit` mencatat IP yang salah. Untuk proyek yang seluruh tesisnya
   akuntabilitas, kolom bukti yang isinya IP proxy tidak membuktikan apa pun.
4. Link email **tidak** rusak hari ini (memakai `config('app.url_spa')` dari
   `APP_URL` statis), tetapi ini ranjau laten: begitu ada `signedUrl`, tautannya
   akan memakai skema yang salah dan ditolak.

**Perbaikan.** Di `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: '*');
    // ...
})
```

`'*'` aman **karena** container tidak bisa dijangkau langsung di Render; satu-
satunya pintu masuk adalah proxy Render. Bila nanti dipublikasikan langsung,
ganti dengan rentang IP proxy.

**Dijaga tes.** Satu tes yang membuat request dengan `X-Forwarded-Proto: https`
dan `X-Forwarded-For` lalu memastikan `$request->isSecure() === true` dan
`$request->ip()` sama dengan IP asli. Tanpa tes ini, perbaikannya bisa hilang
lagi tanpa ada yang tahu — persis seperti yang terjadi pada `continue-on-error`.

---

### A2. `DB_SSLMODE` didokumentasikan tetapi tidak pernah dibaca

**Bukti.** `api/config/database.php:97` menulis mati:

```php
'sslmode' => 'prefer',
```

`grep -r DB_SSLMODE api/` → **hanya** `database.php:97`. Padahal
`docs/produk/9_DEPLOY.md` baris 48 **mewajibkan**:

| `DB_SSLMODE` | `require` |

dan baris 50 menambahkan *"`DB_SSLMODE=require` wajib. Neon menolak koneksi
tanpa TLS"*.

**Akibat.** Seseorang mengikuti dokumen, menyetel `DB_SSLMODE=require` di Render,
dan variabel itu **diabaikan tanpa peringatan**. Yang dipakai tetap `prefer`.
Untuk Neon hari ini `prefer` kebetulan berhasil karena Neon mendukung TLS —
tetapi `prefer` berarti "coba TLS, kalau gagal lanjut tanpa TLS", yang justru
**membatalkan** jaminan yang ingin dipaksakan. Kalau Neon mengubah kebijakan
atau ada MITM, koneksi berjalan tanpa enkripsi dan tidak ada yang tahu.

Ini kelas cacat yang sama dengan `QUEUE_CONNECTION=database` yang sudah Anda
temukan sendiri: **variabel yang dihormati dokumen tetapi tidak dihormati kode.**

**Perbaikan.** `'sslmode' => env('DB_SSLMODE', 'prefer')`. Tambahkan ke
`api/.env.example` dan `docker-compose.prod.yml`, lalu masukkan ke
`periksa-profil-produksi.sh` sebagai pemeriksaan wajib.

---

### A3. Penjadwal mati bersama container — tagihan tanggal 1 tidak terbit

**Bukti.** `docker/prod/supervisord.conf` menjalankan tiga program: `php-fpm`,
`nginx`, `penjadwal` (`php artisan schedule:work`). Render gratis mematikan
layanan setelah ~15 menit tanpa lalu lintas (dicatat sendiri di
`8_OPERATIONS_SOP.md:248`).

**Akibat.** `Schedule::call(...)->monthlyOn(1, '00:10')` **tidak berjalan** kalau
tidak ada lalu lintas pada tanggal 1 pukul 00:10 WIB. Tagihan bulanan tidak
terbit, tanpa galat, tanpa email, tanpa apa pun. Pemilik baru sadar ketika
penghuni tidak membayar — atau tidak sadar sama sekali.

Ini pola kegagalan yang paling berbahaya: **diam**.

**Catatan penting.** `8_OPERATIONS_SOP.md:256` sudah menyebut pinger uptime
sebagai pencegah tidur, tetapi menyebutnya sebagai *opsi dengan biaya kuota*,
bukan sebagai **syarat agar penagihan berjalan**. Perbedaannya besar: yang satu
catatan, yang lain prasyarat.

**Pilihan, harus dipilih sadar:**

| Pilihan | Kelebihan | Kekurangan |
|---|---|---|
| Pinger `/api/health` tiap 5–10 menit | Menghangatkan + menjadwalkan jalan | Memakai kuota jam Render; 750 jam/bulan cukup untuk 1 layanan |
| Render Cron Job | Tidak bergantung pada tidur | Butuh konfigurasi terpisah; free tier terbatas |
| Terima: terbitkan manual | Sederhana, endpoint sudah ada & idempoten | Pemilik harus ingat tiap bulan |

Apa pun pilihannya, **wajib ada penanda di dasbor**: "tagihan periode ini belum
terbit". Kegagalan yang terlihat jauh lebih murah daripada yang diam.

---

### A4. `MAIL_FROM_ADDRESS` default `kos@contoh.test` — email aktivasi tidak akan sampai

**Bukti.** `docker-compose.prod.yml`:

```yaml
MAIL_FROM_ADDRESS: ${MAIL_FROM_ADDRESS:-kos@contoh.test}
```

`api/config/mail.php:17` → `'default' => env('MAIL_MAILER', 'log')`.

**Akibat.** Dua kegagalan terpisah, keduanya senyap:

1. **Lupa mengisi** → Brevo menolak semua surat. Alur aktivasi penghuni putus
   total: pemilik membuat akun, email tidak pernah datang, penghuni tidak bisa
   masuk. Satu-satunya penanda adalah `tautan_aktivasi_terkirim: false` di layar
   pemilik — yang bagus, tetapi hanya terlihat kalau pemilik memperhatikan.
2. **Domain belum diverifikasi Brevo** (SPF/DKIM) → surat terkirim tetapi masuk
   spam atau ditolak. Ini lebih buruk: `terkirim: true`, pemilik mengira beres.

**Perbaikan.** Pagar di `entrypoint-prod.sh`, sejalan dengan `APP_KEY`:

```sh
if [ "${APP_ENV}" = "production" ]; then
    case "${MAIL_FROM_ADDRESS:-}" in
        *@contoh.test|*@example.com|"")
            echo "[entrypoint] BERHENTI: MAIL_FROM_ADDRESS belum diisi dengan domain sungguhan." >&2
            exit 1
            ;;
    esac
fi
```

Lebih baik container mati dan terlihat daripada hidup sambil mengirim surat yang
tidak pernah sampai.

**Belum dapat diuji dari sini:** verifikasi SPF/DKIM Brevo hanya bisa dilakukan
dengan akun sungguhan.

---

### A5. `opcache.validate_timestamps = 1` membatalkan gunanya opcache

**Bukti.** `api/docker/php/opcache.ini`:

```
opcache.validate_timestamps = 1
opcache.revalidate_freq = 0
```

**Akibat.** Dengan `validate_timestamps=1` dan `revalidate_freq=0`, PHP
memeriksa `stat()` setiap berkas pada **setiap permintaan**. Image produksi
bersifat **immutable** — kode dibekukan saat build, tidak pernah berubah selama
container hidup. Jadi pemeriksaan itu murni pemborosan, dan pada Render free
dengan disk jaringan, `stat()` bukan gratis.

Ini bukan bug yang menjatuhkan aplikasi, tetapi ia membayar biaya tanpa membeli
apa pun — dan pada 512 MB RAM dengan `pm.max_children = 8`, setiap milidetik
terasa saat cold start.

**Perbaikan.** `opcache.validate_timestamps = 0`. Aman **hanya karena** image
tidak pernah diubah saat berjalan. Beri komentar yang menyebut alasan itu,
supaya tidak ada yang "memperbaikinya" kembali.

---

## B. Risiko tinggi

### B1. Backup produksi tidak bisa dipulihkan penuh, dan tidak pernah diuji

**Bukti.** `BackupDatabase.php` memakai `pg_dump` bila tersedia, dan
`Dockerfile.prod` **memang** memasang `postgresql16-client` — jadi jalurnya
benar. Tetapi:

- Tujuan bawaan `storage/backups`, dan **Render free tidak punya disk
  persisten** → hilang saat restart (sudah diakui di `utang-teknis.md`).
- Cadangan berbasis PHP dipakai bila `pg_dump` tidak ada — dan dump itu
  **tidak memuat DDL**, jadi tidak bisa memulihkan skema.
- **Tidak ada satu pun perintah restore** di repo ini.
- **Tidak pernah ada latihan restore.**

**Akibat.** Backup yang belum pernah dipulihkan bukan backup; ia hanya berkas
yang membuat orang merasa aman.

**Perbaikan.** Neon punya pemulihan titik-waktu bawaan — jadikan **itu** backup
resmi, dan lakukan **satu kali latihan restore** ke database percobaan sebelum
dipakai kos sungguhan. Catat langkahnya di SOP. Backup lokal tetap berguna untuk
pemulihan cepat dalam satu masa hidup container, tetapi jangan pernah menyebutnya
"backup" tanpa kualifikasi.

### B2. Tidak ada pemantauan galat — kegagalan hanya terlihat kalau ada yang melihat

**Bukti.** `LOG_CHANNEL=stderr`, ditambah `appendOutputTo` untuk jadwal. Log
Render gratis **dipotong**. Tidak ada Sentry, tidak ada pemberitahuan.

**Akibat.** Galat 500 pada malam hari baru diketahui ketika pemilik membuka
aplikasi besok pagi. Untuk aplikasi yang menagih uang, itu terlambat.

**Perbaikan minimal:** pinger uptime yang memeriksa `/api/health` **dan**
mengirim surel/Telegram saat jawabannya bukan 200. Endpoint-nya sudah ada dan
sudah mengembalikan 503 saat database tidak terjangkau — itu desain yang benar,
tinggal dipakai.

### B3. Kuota Brevo 300 email/hari dapat terlampaui tanpa peringatan

**Bukti.** `8_OPERATIONS_SOP.md:251`: *"Brevo | 300 email/hari | Penerbitan
massal 30 penghuni = 30 email"*.

**Akibat.** Terbit massal (30) + notifikasi tagihan + aktivasi + reset bisa
mendekati batas. Karena email dikirim **sinkron** (DAT-03) dan kegagalan kirim
**sengaja tidak membatalkan transaksi**, tagihan tetap terbit sementara emailnya
hilang — dan tidak ada yang tahu email mana yang gagal.

**Perbaikan.** Hitung ulang kebutuhan harian; kalau mepet, kirim ringkasan
(1 email per pemilik, bukan per tagihan) atau naikkan paket. Yang lebih penting:
**catat kegagalan kirim** ke `log_audit` supaya bisa dihitung.

### B4. `PembatasMasuk` berubah perilaku setelah `trustProxies` diperbaiki

**Bukti.** `PembatasMasuk` memakai `email + IP` (5/menit, 20/jam) dan
`IP saja` (20/menit untuk `periksa-token`).

**Akibat.** Setelah A1 diperbaiki, IP asli terbaca. Ini **membaikkan** keamanan,
tetapi mengubah perilaku: satu keluarga/ kos dengan satu IP publik berbagi batas
`periksa-token` 20/menit. Batas itu cukup longgar untuk pemakaian wajar, tetapi
harus **diuji ulang**, bukan diasumsikan tetap sama.

**Perbaikan.** Setelah A1, jalankan ulang uji pembatas dan tambahkan satu tes
yang memastikan dua IP berbeda tidak saling mengunci.

### B5. Tidak ada CSP, dan `upgrade-insecure-requests` tidak ada

**Bukti.** `nginx.conf.template` memasang `nosniff`, `X-Frame-Options`,
`Referrer-Policy`. **Tidak ada** `Content-Security-Policy`, tidak ada HSTS.

**Akibat.** XSS yang lolos akan berjalan tanpa batas. HSTS tidak ada berarti
kunjungan pertama lewat `http://` bisa dicegat sebelum dialihkan.

**Perbaikan.** HSTS satu baris. CSP perlu kehati-hatian karena SPA Vue sering
butuh `style-src 'unsafe-inline'`; mulai dengan `Content-Security-Policy-
Report-Only` supaya tidak mematahkan aplikasi, lalu naikkan.

### B6. Data uji dan akun demo berada di database yang sama dengan data sungguhan

**Bukti.** Seeder menolak di `APP_ENV=production` dan `RUN_SEEDER=false`
dipaksa — ini **sudah benar**. Tetapi database lokal Anda saat ini memuat
`bayar-1793003764616@kos.test` dan `bayar-1793003766007@kos.test`, sisa uji
Midtrans.

**Akibat.** Rendah untuk produksi (database Neon baru akan kosong), tetapi
**berbahaya** bila seseorang menyalin database lokal ke Neon "supaya ada
datanya". Itu akan membawa akun demo, termasuk `pemilik@kos.test`/`password`.

**Perbaikan.** Jangan pernah menyalin database lokal ke produksi. Kalau butuh
data contoh, buat kos baru lewat layar.

---

## C. Pengerasan (layak, tidak mendesak)

| # | Hal | Alasan |
|---|---|---|
| C1 | `client_max_body_size 2m` vs validasi `max:2048` tepat 2 MB | Berkas 2 MB + overhead multipart → **413 dari nginx** sebelum Laravel memberi pesan ramah. Naikkan nginx ke `4m` |
| C2 | `SANCTUM_EXPIRATION` tidak ada di `.env.example` | Bawaannya 14 hari; harus jadi keputusan sadar, bukan bawaan |
| C3 | `GET /penghuni` & `GET /rekening` tanpa paginasi | Benar untuk 5–30 kamar. **Jangan** tambahkan paginasi; ini catatan, bukan tugas |
| C4 | Argumen DAT-03 perlu dikoreksi | Alasan yang tercatat ("satu layanan tidak bisa worker persisten") **tidak berlaku**: supervisord sudah menjalankan proses ketiga. Kode tidak perlu diubah, tetapi alasannya jangan dikutip sebagai kebenaran |
| C5 | `APP_LOCALE=en` padahal seluruh UI berbahasa Indonesia | Pesan validasi bawaan berbahasa Inggris. Kecil, tetapi terlihat |
| C6 | `pm.max_requests = 500` | Sudah benar (mencegah kebocoran memori). Dicatat sebagai hal yang **jangan** dinaikkan tanpa mengukur |
| C7 | `Cache-Control` untuk `/api/*` | Tidak ada; jawaban API bisa di-cache proxy. Tambahkan `no-store` untuk respons terautentikasi |

---

## D. Sengaja tidak dikerjakan

Dicatat supaya tidak ada yang mengusulkannya lagi tanpa membaca alasannya.

| Hal | Kenapa tidak |
|---|---|
| Paginasi daftar kecil | 30 kamar; paginasi menambah kerumitan tanpa manfaat |
| Role `admin` | Sudah dijawab: tidak ada di kode, pagar menolak entitas tanpa aliran, dan ia menyiratkan Model C yang ditolak di §3.6 |
| Queue worker terpisah | Alasan lama gugur (C4), tetapi manfaatnya belum sepadan: 30 email < 10 detik |
| Multi-kos / banyak pemilik | Model C ditolak dengan alasan tertulis |

---

## Urutan pengerjaan yang disarankan

Lihat `docs/produk/10_RENCANA-PERBAIKAN.md` untuk pembagian fasenya.

Ringkas: **A1 → A2 → A4 → A3 → B1**, lalu deploy. Sisanya setelah aplikasi
benar-benar melayani satu kos.
