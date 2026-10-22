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

**Data demo.** Seeder hanya membuat akun, satu penghuni, dan parameter denda awal (1%/hari, plafon
30%, tenggang 7 hari). Kamar, kontrak, dan tagihan belum diisi otomatis — isi lewat menu **Kamar →
Tambah kamar**, **Penghuni → Tambah penghuni**, **Kontrak → Buat kontrak**, lalu **Tagihan →
Terbitkan tagihan**.

Untuk data contoh tanpa mengetik, jalankan skrip E2E yang melakukan persis alur itu:

```bash
node docs/uji/e2e-t2.mjs   # 8 kamar, 6 penghuni, 1 kontrak
node docs/uji/e2e-t3.mjs   # 2 kontrak + tagihan prorata & penuh
node docs/uji/e2e-t6.mjs   # tagihan + demo denda lewat simulasi tanggal
node docs/uji/e2e-t7.mjs   # empat kelompok umur tunggakan
```

Untuk kembali ke keadaan bersih:

```bash
docker compose exec api php artisan migrate:fresh --seed
```

## Mendemokan denda tanpa menunggu 37 hari

Denda bergantung pada "hari ini", jadi menunggu plafon 30% berarti menunggu lebih dari sebulan.
Karena itu ada kendali **Simulasi tanggal** di halaman **Tagihan**: isi tanggal, klik Terapkan,
dan seluruh angka denda dihitung ulang terhadap tanggal itu.

Lewat API, sama halnya:

```bash
curl -H "X-Simulasi-Tanggal: 2026-11-21" \
     -H "Authorization: Bearer <token>" \
     http://localhost:8081/api/tagihan/1/denda
```

Header ini **hanya berlaku bila `APP_DEMO_MODE=true`** (default di `docker-compose.yml`).
Di mode normal header diabaikan sepenuhnya, dan respons mengumumkannya lewat
`X-Simulasi-Dipakai: false` serta `meta.simulasi` di badan respons.

Urutan yang cocok untuk sidang, untuk tagihan pokok Rp 1.500.000 yang jatuh tempo 15 Okt:

| Tanggal simulasi | Hari terlambat | Denda |
|---|---|---|
| 2026-10-22 | 7 | Rp 0 (masih tenggang) |
| 2026-10-23 | 8 | Rp 15.000 |
| 2026-11-14 | 30 | Rp 345.000 |
| 2026-11-21 | 37 | Rp 450.000 (plafon) |
| 2027-10-15 | 365 | Rp 450.000 (tetap plafon) |

## Membaca tunggakan

Halaman **Tunggakan** menjawab pertanyaan yang muncul tiap bulan: siapa yang menunggak, dan sudah
separah apa. Empat kartu di atas daftar menunjukkan jumlah dan total rupiah per kelompok umur;
menekan salah satu kartu menyaring daftar ke kelompok itu.

| Kelompok | Arti | Yang biasanya dilakukan |
|---|---|---|
| Belum jatuh tempo | Belum waktunya bayar | Tidak ada — bukan penunggak |
| 0–30 hari | Baru terlambat | Ingatkan |
| 31–60 hari | Perlu tindakan | Tagih langsung |
| Lebih dari 60 hari | Sudah lama | Putuskan: tegur atau akhiri kontrak |

Tagihan `lunas` dan `dibatalkan` tidak pernah muncul di sini. Tagihan `sebagian` **tetap** muncul —
dibayar sebagian belum berarti lunas.

Angka denda di halaman ini dihitung dengan kalkulator yang sama dengan endpoint
`GET /api/tagihan/{id}/denda`, bukan dengan rumus salinan; ada tes yang memastikan keduanya
identik untuk tagihan yang sama di beberapa tanggal acuan.

Lewat API:

```bash
curl -H "X-Simulasi-Tanggal: 2026-11-21" \
     -H "Authorization: Bearer <token>" \
     http://localhost:8081/api/tunggakan
```

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

**Kode dibakar ke image, bukan di-mount.** `docker-compose.yml` sengaja tidak memasang `./api`
sebagai volume — container harus menjalankan apa yang benar-benar dibangun, bukan apa yang ada di
host. Konsekuensinya: setelah mengubah kode PHP, **jalankan `docker compose build api && docker
compose up -d api`** sebelum menguji. Tanpa itu, `php artisan test` akan menguji kode lama dan
hasilnya hijau padahal perubahan belum masuk — pernah terjadi, dan gejalanya menyesatkan (tes baru
tidak muncul di daftar sama sekali). Hal yang sama berlaku untuk `web`.

Database pengujian `kos_test` dibuat otomatis oleh `docker/postgres/init-test-db.sql`, terpisah
dari database demo supaya data sidang tidak pernah terhapus oleh tes.

## Struktur

```
.
├── api/                  Laravel 12 (API-only)
│   ├── app/Domain/Tarif/ PencariTarif — tarif efektif per periode (R-06 lapis 1)
│   ├── app/Http/         Controller, Request, Resource, Middleware
│   ├── app/Models/       User, Kamar, Penghuni, Kontrak, TarifSewa, KomponenBiaya, ParameterDenda
│   ├── database/migrations/  Termasuk trigger append-only untuk tabel riwayat
│   ├── docker/php/       php.ini, opcache.ini, entrypoint.sh
│   └── docker/nginx/     Konfigurasi nginx untuk API
├── web/                  Vue 3 + Vite
│   ├── src/views/pemilik/    KamarView, PenghuniView, KontrakView
│   ├── src/komponen/         TataLetakPemilik (sidebar + navigasi)
│   ├── src/format.js         Rupiah, tanggal, dan ringkasan galat validasi
│   └── docker/nginx/     Konfigurasi nginx untuk SPA
├── docker/postgres/      Init database pengujian
├── docs/uji/             Skrip E2E browser + screenshot bukti
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
