# 12 - Opsi hosting: Hostinger shared vs VPS vs Render

Dokumen ini menjawab satu pertanyaan konkret: **apakah domain dan hosting
Hostinger yang sudah dimiliki bisa dipakai, atau harus VPS?**

Jawaban singkat: **hosting shared-nya tidak bisa, domainnya bisa.** Alasannya
bukan selera, dan bukan soal kualitas Hostinger — melainkan satu ketergantungan
tunggal yang tidak bisa ditawar.

---

## 1. Penghalang tunggal: PostgreSQL

Aplikasi ini **tidak bisa** berjalan di atas MySQL. Itu bukan preferensi; ia
memakai fitur yang tidak ada padanannya di MySQL, dan jumlahnya besar:

| Fitur PostgreSQL | Jumlah | Kalau dipaksa ke MySQL |
|---|---|---|
| `DB::statement` (SQL mentah) | **108** | Setiap satuan harus ditulis ulang |
| `CHECK (...)` constraint | **48** | MySQL 8 punya CHECK, tetapi sintaksnya berbeda dan riwayat migrasinya bercabang |
| Partial unique index (`CREATE UNIQUE INDEX ... WHERE ...`) | **6** | **Tidak ada padanan.** Ini yang menjaga "satu kontrak aktif per kamar" dan "satu tagihan per periode" |
| Kolom `bytea` (gambar QRIS) | **6** | Harus jadi `BLOB`, plus jalur tulis `decode(?, 'base64')` ditulis ulang |
| `NULLS NOT DISTINCT` (PostgreSQL 15+) | **2** | Tidak ada padanan |

Partial unique index adalah yang paling menentukan. Kalimat ini:

```sql
CREATE UNIQUE INDEX kontrak_satu_aktif_per_kamar
  ON kontrak (kamar_id) WHERE status = 'aktif'
```

adalah **satu-satunya** yang mencegah dua kontrak aktif pada kamar yang sama.
Aplikasi mengandalkannya lewat penanganan SQLSTATE `23505`. MySQL tidak punya
`WHERE` pada unique index, jadi jaminan itu hilang — dan digantikan pemeriksaan
aplikasi yang punya celah balapan.

**Dan Hostinger shared hosting tidak menyediakan PostgreSQL.** Ini pernyataan
Hostinger sendiri, di halaman dukungan "Is PostgreSQL Supported at Hostinger?":

> *"So make sure to select any of our VPS plans to start using PostgreSQL!"*

Hostinger **Web** dan **Cloud** hosting hanya menyediakan **MySQL/MariaDB**.

---

## 2. Kenapa "pakai Neon saja, hostingnya di Hostinger" juga tidak

Ini jalan yang tampak paling masuk akal: taruh database di Neon (gratis), dan
taruh kode PHP di Hostinger yang sudah dibayar.

**Tetap gagal, karena dua hal:**

1. **`pdo_pgsql` tidak tersedia di paket Web/Cloud Hostinger.** Tanpa ekstensi
   itu, PHP tidak bisa berbicara dengan PostgreSQL sama sekali — bukan lambat,
   tetapi `could not find driver`. Ekstensi yang bisa dinyalakan di hPanel
   terbatas pada yang sudah terpasang, dan PostgreSQL bukan salah satunya
   (konsisten dengan poin 1: mereka tidak menjual PostgreSQL di paket itu).
2. **`shell_exec` dan `exec` dinonaktifkan** di shared hosting. Aplikasi ini
   memakai keduanya di `BackupDatabase.php:76,90` untuk memanggil `pg_dump`.
   Akibatnya perintah `kos:backup` akan gagal — atau lebih buruk, jatuh ke dump
   berbasis PHP yang **tidak memuat DDL** sehingga tidak bisa dipulihkan.

**Yang perlu diperiksa sendiri sebelum memutuskan** (saya tidak bisa
memastikannya dari sini, dan ini menentukan): buka hPanel → PHP Configuration →
lihat daftar ekstensi. Cari `pdo_pgsql`. Kalau ada, kabari saya — analisisnya
berubah. Kalau tidak ada (paling mungkin), jawabannya sudah pasti.

---

## 3. Tiga opsi, dengan konsekuensinya

### Opsi A — Render (gratis) + Neon — **paling murah, paling cepat mulai**

| | |
|---|---|
| Biaya | Rp 0 |
| Yang dipakai | Image Docker yang **sudah ada dan sudah terbukti** (`Dockerfile.prod`) |
| Kerja | Ikuti `9_DEPLOY.md`; tidak ada kode baru |
| Kelemahan | Tidur setelah 15 menit → permintaan pertama ~50 detik |
| Kelemahan | Penjadwal mati saat tidur (temuan A3) → butuh pinger atau cron |

**Paling cocok kalau:** kos ini untuk tugas akhir / dipakai sambil diawasi, dan
Anda tidak ingin membayar apa pun dulu.

### Opsi B — Render ($7/bln) + Neon — **paling sedikit kerja, paling sedikit risiko**

| | |
|---|---|
| Biaya | ~Rp 115.000/bulan |
| Kerja | Sama dengan Opsi A, ditambah satu klik "upgrade" |
| Kelebihan | Tidak tidur → cold start hilang, penjadwal hidup, A3 selesai sendiri |

**Paling cocok kalau:** kos ini sungguhan dan pemiliknya memakai tiap hari.
Ini yang saya sarankan bila tujuannya "dipakai", bukan "dinilai".

### Opsi C — Hostinger VPS — **bisa, tetapi paling banyak kerja**

| | |
|---|---|
| Biaya | VPS Hostinger (mulai ~Rp 50.000–100.000/bulan) |
| Yang dipakai | `docker compose -f docker-compose.yml -f docker-compose.prod.yml` |
| Kelebihan | PostgreSQL 16 bisa dipasang sendiri; domain & SSL mudah; kendali penuh |
| Kelemahan | **Anda** yang mengurus OS, pembaruan keamanan, Docker, TLS, pemantauan, dan pemulihan |
| Kelemahan | Satu VPS = satu titik gagal tanpa penjaga. Kalau mati pukul 2 pagi, tidak ada yang menghidupkan |

**Paling cocok kalau:** Anda memang ingin belajar mengoperasikan server, atau
ada kebutuhan lain di VPS yang sama.

**Catatan jujur:** untuk satu kos dengan 5–30 kamar, VPS adalah pekerjaan
operasional yang tidak sepadan. Render ada supaya Anda tidak perlu menjadi
administrator sistem.

### Opsi D — Ubah aplikasi agar jalan di MySQL — **jangan**

Bukan karena mustahil, tetapi karena harganya: 41 migrasi, 108 `DB::statement`,
dan **6 jaminan integritas yang hilang** dan harus diganti kode aplikasi. Itu
pekerjaan berminggu-minggu yang **menurunkan** mutu sistem, untuk menghemat
biaya hosting yang lebih kecil daripada waktu yang dipakai mengerjakannya.

Kalau suatu hari benar-benar perlu: itu proyek tersendiri, bukan fase.

---

## 4. Domain Hostinger tetap terpakai di semua opsi

Ini bagian yang sering disalahpahami: **domain dan hosting adalah dua hal
berbeda**, dan Anda tidak perlu membuang domainnya.

Untuk Opsi A/B (Render):

1. Di hPanel → DNS Zone, tambahkan:
   - `CNAME` nama `www` → `nama-layanan.onrender.com`
   - `A` atau `ALIAS` nama `@` → IP yang diberikan Render
2. Di Render → Settings → Custom Domain, tambahkan `kos.domain-anda.my.id`.
3. Render menerbitkan sertifikat TLS otomatis (Let's Encrypt).
4. Tunggu propagasi DNS (5 menit–24 jam).

Untuk Opsi C (VPS): arahkan `A` record ke IP VPS, lalu pakai Caddy atau
Certbot untuk TLS.

**Yang penting dan mudah terlewat:** setelah domain aktif, isi juga
`APP_URL=https://kos.domain-anda.my.id` dan `APP_URL_SPA` dengan nilai yang
sama. Kalau tidak, tautan aktivasi di email akan menunjuk alamat yang salah —
dan itu memutus alur masuk penghuni.

**Email (Brevo) juga butuh DNS.** Untuk pengirim `noreply@domain-anda.my.id`,
tambahkan record SPF dan DKIM yang diberikan Brevo ke DNS Zone yang sama. Tanpa
itu surat masuk spam atau ditolak, dan alur aktivasi penghuni ikut mati.

---

## 5. Rekomendasi

**Mulai dari Opsi A, lalu naik ke Opsi B setelah pemilik kos benar-benar
memakainya.**

Alasannya bukan "gratis itu bagus", melainkan urutan risiko:

1. Opsi A membuktikan seluruh sistem berjalan di internet sungguhan dengan
   biaya nol. Kalau ada yang salah, Anda tidak sedang membayar untuk kesalahan.
2. Begitu terbukti dan pemilik mulai bergantung padanya, naik ke Opsi B. Yang
   berubah hanya satu tombol; tidak ada kode, tidak ada migrasi, tidak ada
   deploy ulang.
3. VPS (Opsi C) hanya kalau ada alasan lain — bukan karena aplikasi ini
   membutuhkannya.

Domain Hostinger tetap dipakai di ketiganya. Tidak ada yang terbuang.

---

## 6. Yang belum bisa dipastikan dari sini

| Hal | Status |
|---|---|
| Apakah paket Hostinger Anda menyediakan `pdo_pgsql` | **Perlu Anda periksa** di hPanel → PHP Configuration. Saya tidak punya akses ke akun Anda |
| Ketersediaan PostgreSQL di paket Anda | Menurut dokumentasi Hostinger: hanya VPS. Periksa juga di hPanel → Databases |
| Cold start Render yang sebenarnya | Belum diukur; ~50 detik adalah perkiraan dari dokumentasi |

Menulis ketiganya sebagai "sudah pasti" akan membuat keputusan Anda dibangun di
atas tebakan.
