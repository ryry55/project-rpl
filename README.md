# Sistem Informasi Operasional Kos-Kosan

Aplikasi untuk pemilik kos kecil (5–30 kamar) mengelola tagihan sewa: prorata penghuni baru,
denda keterlambatan, dan umur tunggakan. Lihat `SPEC.md` di akar repo untuk kontrak lengkap.

- **API**: Laravel 12, PHP 8.3, PostgreSQL 16, Sanctum
- **Web**: Vue 3 + Vite, dilayani nginx
- **Demo**: Docker Compose

## Menjalankan

```bash
cp .env.example .env      # Windows: Copy-Item .env.example .env
docker compose up --build
```

Tunggu sampai `db` berstatus sehat dan container `api` menulis `[entrypoint] siap.`

| Alamat | Isi |
|---|---|
| http://localhost:3000 | Aplikasi (SPA + proxy `/api`) |
| http://localhost:8081 | API langsung |

**Akun demo**

| Peran | Email | Kata sandi |
|---|---|---|
| Pemilik | `pemilik@kos.test` | `password` |
| Penghuni | `penghuni@kos.test` | `password` |

Dari keadaan bersih (tanpa volume), migrasi dan seeder berjalan otomatis. Tidak ada langkah manual.

## Menghentikan

```bash
docker compose down        # hentikan, data tetap
docker compose down -v     # hentikan dan hapus data (kembali ke keadaan bersih)
```

## Menjalankan tes

Tes memakai PostgreSQL, bukan SQLite — tiket berikutnya bergantung pada unique index parsial,
SQLSTATE `23505`, dan trigger append-only, yang perilakunya berbeda di SQLite.

```bash
docker compose exec api php artisan test
```

Database pengujian `kos_test` dibuat otomatis oleh `docker/postgres/init-test-db.sql`, terpisah
dari database demo supaya data sidang tidak pernah terhapus oleh tes.

## Struktur

```
.
├── api/                  Laravel 12 (API-only)
│   ├── app/Http/         Controller, Request, Resource, Middleware
│   ├── app/Models/       User, Penghuni
│   ├── docker/php/       php.ini, opcache.ini, entrypoint.sh
│   └── docker/nginx/     Konfigurasi nginx untuk API
├── web/                  Vue 3 + Vite
│   ├── src/views/        LoginView, DashboardPemilik, DashboardPenghuni
│   └── docker/nginx/     Konfigurasi nginx untuk SPA
├── docker/postgres/      Init database pengujian
├── docker-compose.yml
└── SPEC.md
```

## Konvensi API

Semua respons sukses: `{ "data": ... }`. Semua error: `{ "message": "...", "errors": {...} }`.

| Kode | Kapan |
|---|---|
| 401 | Belum login / token tidak valid |
| 403 | Peran tidak berhak |
| 404 | Tidak ada, **atau** bukan miliknya (R-07) |
| 409 | Duplikat periode tagihan (R-02) |
| 422 | Validasi gagal |

Semua `/api/*` selalu dijawab JSON, termasuk untuk klien yang tidak mengirim
`Accept: application/json`. Dijamin oleh middleware `PaksaJson`.

## Catatan versi

**Laravel 12, bukan Laravel 11.** Rencana awal memakai Laravel 11, tetapi Composer memblokir
seluruh rilis 11.x karena 7 *security advisory* (`PKSA-d5tc-s1qs-h781` dan lain-lain). Laravel 12
adalah rilis stabil terdekat yang bersih dari advisory dan masih mendukung PHP 8.3. Perbedaan
API-nya kecil untuk kebutuhan proyek ini, dan charter tidak menyebut versi framework.

## Zona waktu

`APP_TIMEZONE=Asia/Jakarta` di `config/app.php`, `date.timezone` di `php.ini` container, dan `TZ`
pada container PostgreSQL. Ketiganya harus sama: kalau berbeda, `hari_terlambat` pada R-03 bisa
meleset satu hari tepat di sekitar tengah malam.
