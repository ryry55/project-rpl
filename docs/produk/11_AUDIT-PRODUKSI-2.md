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

> **Status per 26 Okt 2026: A1, A2, A4, A5 sudah DIPERBAIKI dan dibuktikan
> dengan uji yang bisa gagal.** Hanya **A3** (penjadwal vs container tidur)
> yang masih terbuka, dan itu memang menuntut Render sungguhan.
>
> Bukti yang dipegang, bukan sekadar klaim:
> `docs/uji/uji-proxy.mjs` (5/5 lulus dengan perbaikan, **0/5 gagal** saat
> cacatnya disuntikkan kembali), `docs/uji/uji-pagar-fase0.sh` (17 pemeriksaan),
> image produksi dibangun dan boot bersih (`/api/health` 200,
> `opcache.validate_timestamps => Off`), dan pagar email menyala dengan kode
> keluar 1 pada image produksi sungguhan.


Temuan **A1–A5 tidak ada** di `utang-teknis.md`. Itu bukan kesalahan penulisan
dokumen itu: kelimanya hanya muncul di lingkungan yang **tidak punya proxy TLS**,
**tidak pernah tidur**, dan **tidak memakai layanan email sungguhan**. Seluruh
pengujian selama ini berjalan di laptop, dan laptop tidak pernah begitu.

**Pelajaran yang berlaku umum:** pagar yang hanya diuji di satu lingkungan akan
lolos tepat pada hal yang hanya muncul di lingkungan lain.

> **Koreksi (lihat A1).** Kalimat di atas, sebagaimana ditulis pada audit
> pertama, terlalu luas. Lingkungan demo **memang** memuat cacat yang sama —
> yang tidak ada bukan lingkungannya, melainkan **ujinya**. Demo diuji lewat
> layar, sedangkan cacat ini hanya muncul di header, batas laju, dan skema URL.
> Lihat koreksi lengkap di A1.

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

**KOREKSI PENTING (26 Okt 2026, setelah diuji ulang).** Klaim di atas — bahwa
cacat ini "hanya muncul di lingkungan yang punya proxy TLS" — **terlalu luas,
dan salah**. `web/docker/nginx/default.conf:47-51` di laptop **juga** sebuah
proxy, dan ia mengirim `X-Forwarded-For`/`X-Forwarded-Proto` persis seperti
Render. Cacat ini **sudah ada di lingkungan demo**, dan bisa direproduksi di
sini tanpa menunggu deploy.

Bukti, diukur lewat `http://localhost:3000` (jalur proxy) pada 26 Okt 2026:

```
25 permintaan /api/periksa-token, masing-masing dengan X-Forwarded-For BERBEDA:
  1..20  -> HTTP 200
  21..25 -> HTTP 429   <-- ditolak, padahal IP-nya berbeda semua
```

Dan log aplikasi mencatat `"ip":"172.20.0.4"` — yaitu IP container `web`, bukan
IP klien. Dua IP klien yang berbeda (`203.0.113.45`, `198.51.100.77`) sama-sama
tercatat sebagai `172.20.0.4`.

**Mengapa semua pengujian selama ini tidak menangkapnya.** Tidak ada satu pun
uji yang mengirim header proxy:

- `grep -r "X-Forwarded" api/tests/` → **nol hasil**
- `grep -r "X-Forwarded" docs/uji/*.mjs` → **nol hasil**

E2E menguji perilaku yang **tampak** di layar, dan layar tidak menampilkan IP.
Cacat ini hanya terlihat lewat pembatas laju, yang baru menyala pada percobaan
ke-21 dalam satu menit — dan tidak ada skrip yang melakukannya.

**Pelajaran yang berlaku umum, dan lebih tepat daripada klaim saya sebelumnya:**
*lingkungan demo bisa memuat cacat produksi, tetapi hanya kalau ada uji yang
menyentuh jalur yang sama.* Demo yang hanya diuji lewat layar tidak akan
menemukan cacat yang hanya muncul di header, batas laju, atau skema URL.

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

**Perbaikan.** **DUA bagian, bukan satu** — dan rencana awal hanya menyebut yang
pertama. Keduanya baru terbukti cukup setelah diukur.

Bagian 1, `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(
        at: ['127.0.0.1', '172.16.0.0/12', '192.168.0.0/16', '10.0.0.0/8', '::1'],
        headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
    );
    // ...
})
```

**`at: '*'` TIDAK dipakai, dan ini koreksi atas rekomendasi saya sendiri.**
Di Laravel, `'*'` bukan "percayai semua proxy"; ia berarti "percayai HANYA
alamat yang sedang memanggil" (`setTrustedProxyIpAddressesToTheCallingIp`).
Untuk satu hop itu cukup, tetapi jalur demo punya **dua** hop — klien → `web` →
`api-web` → php-fpm — sehingga yang memanggil php-fpm adalah `api-web`,
sedangkan entri terakhir `X-Forwarded-For` adalah gateway `web`. Diukur
langsung lewat tinker:

```
REMOTE_ADDR = 172.20.0.5, XFF = "203.0.113.1, 172.20.0.1"
  at: '*'               -> ip() = 172.20.0.1    (gateway, SALAH)
  at: [rentang privat]  -> ip() = 203.0.113.1   (klien, BENAR)
```

Rentang privat dipakai karena seluruh hop memang berada di jaringan privat
(Docker di demo, internal Render di produksi). Alamat publik **tidak**
dipercaya, sehingga `X-Forwarded-For` dari internet tidak bisa dipakai
memalsukan IP. Bila kelak dipasang di belakang proxy ber-alamat publik
(mis. Cloudflare tanpa jaringan privat), alamatnya harus ditambahkan —
dan menambahkannya sembarangan membuka pemalsuan IP.

`HEADER_X_FORWARDED_HOST` dan `..._PORT` sengaja **tidak** dipercaya: keduanya
bisa dipakai menyuntikkan `Host` sembarang, dan tidak satu pun tautan di
aplikasi ini dibangun dari `Host` permintaan — tautan memakai
`config('app.url_spa')`.

Bagian 2, `api/docker/nginx/default.conf` **dan**
`docker/prod/nginx.conf.template`:

```nginx
fastcgi_param HTTP_X_FORWARDED_FOR $http_x_forwarded_for;
fastcgi_param HTTP_X_FORWARDED_PROTO $http_x_forwarded_proto;
```

**Tanpa bagian 2, bagian 1 tidak bekerja sama sekali.** `include
fastcgi_params` hanya mengoper `REMOTE_ADDR` — nol header HTTP (diperiksa:
`grep -c HTTP_ /etc/nginx/fastcgi_params` → **0**). Artinya nginx membuang
`X-Forwarded-For` yang sudah dikirim `web` sebelum sampai ke Laravel, dan
`trustProxies` tidak punya apa pun untuk dipercaya.

Ini bukan dugaan: uji `docs/uji/uji-proxy.mjs` gagal **0/5** ketika hanya
bagian 1 terpasang, lalu lulus **5/5** setelah bagian 2 ditambahkan. Cacat yang
sama ada di nginx produksi, yang hanya meneruskan `PROTO` dan tidak `FOR`.

**Dijaga tes.** `docs/uji/uji-proxy.mjs` — dijalankan lewat port **proxy**
(`:3000`), bukan langsung ke API, karena pada jalur langsung tidak ada yang
menambahkan `X-Forwarded-For` sehingga ujinya akan lulus meski perbaikannya
tidak ada. Tiga hal diperiksa: 25 IP berbeda → 25×200; satu IP sama → tetap
429 pada percobaan ke-21 (membuktikan pembatasnya tidak ikut dimatikan); dan
log mencatat IP klien, bukan IP container. Ditambah
`docs/uji/uji-pagar-fase0.sh` yang memeriksa penerusan header di kedua berkas
nginx.

> **Pelajaran dari versi pertama uji ini, yang SALAH.** Versi pertamanya
> memeriksa "log tidak boleh memuat IP `172.x` sama sekali", dan itu **gagal
> di gate penuh** — bukan karena perbaikannya rusak, melainkan karena
> pemeriksaannya terlalu luas. Permintaan yang **tidak** membawa
> `X-Forwarded-For` (mayoritas lalu lintas sungguhan, termasuk seluruh 20
> skrip E2E yang menembak dari host) memang tercatat dengan IP sebenarnya dari
> sudut pandang kontainer, yaitu gateway Docker `172.20.0.1`. Itu perilaku
> yang **benar**.
>
> Yang benar-benar perlu dibuktikan adalah kebalikannya: permintaan tanpa
> header tidak boleh kehilangan IP-nya. Kalau `ip` menjadi kosong atau `null`,
> pembatas laju kehilangan kuncinya dan semua permintaan tanpa proxy berbagi
> satu kunci kosong — cacat yang lebih buruk daripada yang sedang diperbaiki.
> Pemeriksaannya diganti ke itu.
>
> **Pelajaran yang berlaku umum:** asersi yang terlalu luas menghasilkan
> kegagalan yang menyalahkan kode yang benar. Uji yang gagal harus dibaca
> sebagai pertanyaan "apakah kodenya salah, atau pemeriksaannya?", bukan
> langsung sebagai "kodenya salah".



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
- ~~**Tidak ada satu pun perintah restore** di repo ini.~~ **Keliru sejak
  T27-T29:** `api/docker/backup.sh --uji-pulih` memulihkan ke `kos_pulih`,
  menghitung tujuh tabel, lalu menghapusnya. Lihat koreksi di bawah.
- ~~**Tidak pernah ada latihan restore.**~~ Dijalankan **29 Oktober 2026**:
  kode keluar 0, ketujuh tabel terhitung.

**Koreksi 29 Oktober 2026.** Dua butir di atas sudah tidak benar. Perintah
restore **ada** (`api/docker/backup.sh --uji-pulih`, dipakai `8_OPERATIONS_SOP.md`
§3.2), dan latihan restore sudah dijalankan sekali di laptop dengan kode keluar
0. Yang tetap benar: **belum pernah ada pemulihan dari Neon**, karena dump Neon
baru ada setelah deploy; dan tujuan `storage/backups` tetap hilang saat container
restart di Render gratis. Jadi "backup resmi" tetap pemulihan titik-waktu Neon,
bukan berkas lokal.

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

**Status 29 Oktober 2026: SELESAI untuk bagian yang penting.** `Pemberitahu`
kini menulis `log_audit` dengan aksi `email.gagal` (entitas `email`, memuat
penerima, peristiwa, dan sebabnya) setiap kali `Mail::send()` melempar. Barisnya
bisa disaring di layar Jejak audit, dan penulisannya dilindungi `try` sendiri
supaya database yang sedang tidak sehat tidak mengubah kegagalan email menjadi
galat permintaan. Dijaga `NotifikasiTest::test_email_gagal_tidak_membatalkan_perubahan`
(terbukti bisa gagal: dengan pemanggilan itu dikomentari, asersinya merah).
Kuota Brevo sendiri masih belum dihitung otomatis — yang sudah ada sekarang
adalah **datanya**, bukan peringatannya.

### B4. `PembatasMasuk` berubah perilaku setelah `trustProxies` diperbaiki

**Bukti.** `PembatasMasuk` memakai `email + IP` (5/menit, 20/jam) dan
`IP saja` (20/menit untuk `periksa-token`).

**Akibat.** Setelah A1 diperbaiki, IP asli terbaca. Ini **membaikkan** keamanan,
tetapi mengubah perilaku: satu keluarga/ kos dengan satu IP publik berbagi batas
`periksa-token` 20/menit. Batas itu cukup longgar untuk pemakaian wajar, tetapi
harus **diuji ulang**, bukan diasumsikan tetap sama.

**Perbaikan.** Setelah A1, jalankan ulang uji pembatas dan tambahkan satu tes
yang memastikan dua IP berbeda tidak saling mengunci.

**Status: SELESAI.** `docs/uji/uji-proxy.mjs` menutup persis dua sisi yang
diminta B4:

1. **Dua IP berbeda tidak saling mengunci** — 25 permintaan dari 25 IP berbeda,
   seluruhnya 200.
2. **Satu IP yang sama TETAP dibatasi** — 22 permintaan dari satu IP, 429
   pertama tepat pada percobaan ke-21.

Butir kedua itu penting dan mudah terlewat: perbaikan yang membuat semuanya 200
selamanya sama saja dengan menghapus pembatasnya, dan itu regresi yang lebih
buruk daripada cacat aslinya. Keduanya diuji dalam satu skrip supaya tidak
mungkin yang satu diperbaiki dengan mengorbankan yang lain.


### B5. Tidak ada CSP, dan `upgrade-insecure-requests` tidak ada

**Bukti.** `nginx.conf.template` memasang `nosniff`, `X-Frame-Options`,
`Referrer-Policy`. **Tidak ada** `Content-Security-Policy`, tidak ada HSTS.

**Akibat.** XSS yang lolos akan berjalan tanpa batas. HSTS tidak ada berarti
kunjungan pertama lewat `http://` bisa dicegat sebelum dialihkan.

**Perbaikan.** HSTS satu baris. CSP perlu kehati-hatian karena SPA Vue sering
butuh `style-src 'unsafe-inline'`; mulai dengan `Content-Security-Policy-
Report-Only` supaya tidak mematahkan aplikasi, lalu naikkan.

**Status 29 Oktober 2026: SELESAI untuk HSTS dan CSP mode lapor.** Keduanya
dipasang di `docker/prod/nginx.conf.template` dan diperiksa pada image produksi
sungguhan (`GET /` → `Strict-Transport-Security: max-age=31536000` dan
`Content-Security-Policy-Report-Only` berisi 11 direktif). Dua hal yang
ditemukan saat mengerjakannya:

1. **`add_header` di nginx tidak diwarisi oleh lokasi yang punya `add_header`
   sendiri.** Halaman SPA disajikan lewat internal redirect `try_files` ke
   `location = /index.html`, yang sudah punya `add_header Cache-Control`.
   Akibatnya — **sebelum** perbaikan ini — justru halaman utama yang tidak
   membawa `nosniff`, `X-Frame-Options`, maupun `Referrer-Policy`. Header-nya
   kini diulang di lokasi itu, dengan komentar supaya tidak dihapus.
2. **CSP-nya masih lapor, belum menegakkan.** FASE 4.3 menuntut satu minggu
   pemantauan tanpa pelanggaran berarti, dan itu tidak bisa dipercepat dari
   laptop. Jangan naikkan ke menegakkan tanpa membaca laporan lebih dulu.

**Status lanjutan 29 Oktober 2026: kolektor laporan ada.** Mode lapor tanpa
`report-uri` tidak mengumpulkan apa pun - pelanggaran hanya muncul di konsol
peramban pengguna, jadi kalimat "tunggu seminggu laporan bersih" tidak bisa
dijalankan. Sekarang: `report-uri /csp-laporan`, dilayani
`App\Http\Controllers\CspLaporanController`, dibatasi `throttle:30,1`, dan
menulis satu baris log berawalan **`csp.lapor`** (terbaca di dashboard log
Render karena `LOG_CHANNEL=stderr`). Tiga sifat yang dijaga, masing-masing
dibuktikan bisa gagal oleh `CspLaporanTest`: selalu 204 (peramban tidak membaca
jawaban; 4xx hanya memicu kirim ulang), hanya medan yang dikenal dan sudah
dipotong yang dicatat (isinya datang dari luar tanpa autentikasi), dan
pengiriman berulang kena 429. Rutenya sengaja di luar `/api/` supaya kontrak 86
endpoint dan peta use case tidak berubah - ia infrastruktur, bukan use case.
Membacanya: cari `csp.lapor` di log, lihat `blocked-uri` dan
`violated-directive`. Laporan yang muncul berulang dari `unsafe-inline` berarti
masih ada gaya/script sebaris sebelum CSP bisa ditegakkan.

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
| C1 | ~~`client_max_body_size 2m` vs validasi `max:2048` tepat 2 MB~~ **SELESAI 29 Okt 2026** | Berkas 2 MB + overhead multipart → **413 dari nginx** sebelum Laravel memberi pesan ramah. Kini `4m` di ketiga nginx (prod, `api-web`, `web`). Diukur: 1,47 MB → **201** (sebelumnya **413**), 2,4 MB → **422** dengan pesan "Gambar maksimal 2 MB", 5 MB → **413**. Catatan yang ditemukan saat mengukur: di laptop batasnya bahkan bukan 2 MB melainkan **1 MB**, karena `api-web` dan `web` tidak menyetel batas sama sekali dan memakai bawaan nginx |
| C2 | ~~`SANCTUM_EXPIRATION` tidak ada di `.env.example`~~ **SELESAI 29 Okt 2026** | Bawaannya 14 hari; harus jadi keputusan sadar, bukan bawaan. Kini ada di `api/.env.example` beserta alasannya |
| C3 | `GET /penghuni` & `GET /rekening` tanpa paginasi | Benar untuk 5–30 kamar. **Jangan** tambahkan paginasi; ini catatan, bukan tugas |
| C4 | Argumen DAT-03 perlu dikoreksi | Alasan yang tercatat ("satu layanan tidak bisa worker persisten") **tidak berlaku**: supervisord sudah menjalankan proses ketiga. Kode tidak perlu diubah, tetapi alasannya jangan dikutip sebagai kebenaran |
| C5 | `APP_LOCALE=en` padahal seluruh UI berbahasa Indonesia | Pesan validasi bawaan berbahasa Inggris. Kecil, tetapi terlihat |
| C6 | `pm.max_requests = 500` | Sudah benar (mencegah kebocoran memori). Dicatat sebagai hal yang **jangan** dinaikkan tanpa mengukur |
| C7 | ~~`Cache-Control` untuk `/api/*`~~ **SELESAI 29 Okt 2026** | Temuan ini menulis "tidak ada", padahal bawaan Laravel sudah mengirim `no-cache, private` — yang belum tertutup adalah cache peramban. `TanpaCache` kini menyetel `no-store, private` untuk jawaban JSON; ekspor CSV dan gambar QRIS (`max-age=300`) tidak ditimpa, dan keduanya dijaga dua tes |
| C8 | ~~Kerentanan dependensi~~ **SELESAI 29 Okt 2026** | `composer audit --locked` semula melaporkan satu advisori pada `symfony/mime` 7.4.19; kini **7.4.22** (+ `polyfill-mbstring` 1.43.3) dan audit bersih. `npm audit --omit=dev`: 0. Efek samping yang wajar: `composer update` di repo ini juga membuang tiga paket yang hanya ada di lockfile (`laravel/sail`, `laravel/pail`, `symfony/yaml`) — tidak satu pun disebut di kode, `docs`, atau skrip |
| C9 | ~~Daftar tagihan bisa menjadi N+1 tanpa ketahuan~~ **SELESAI 29 Okt 2026** | Uji beban 390 tagihan = 550 KB / 297 ms; angkanya masih wajar, tetapi tidak ada yang menahan `->load()` di dalam loop. `TagihanTest::test_daftar_tagihan_tidak_menambah_kueri_per_baris` menyamakan jumlah kueri untuk 2 dan 6 baris (kontra-uji: satu kueri per baris membuatnya **7 → 11**) |

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
