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
node docs/uji/e2e-t4.mjs   # terbit massal + penolakan duplikat
node docs/uji/e2e-t5.mjs   # catat pembayaran sebagian lalu lunas
```

Uji balapan (bukan browser, jadi tidak butuh Chrome):

```bash
node docs/uji/uji-balapan-t4.mjs   # dua permintaan HTTP serentak → satu 201, satu 409
node docs/uji/uji-balapan-t5.mjs   # dua pembayaran serentak → satu 201, satu 422
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

## Menerbitkan tagihan sebulan sekaligus

Halaman **Tagihan** punya tombol **Terbitkan semua**. Satu tagihan per kontrak aktif untuk periode
yang dipilih; kontrak yang sudah punya tagihan periode itu dilewati, bukan diterbitkan dua kali.

Hasilnya memisahkan tiga nasib, karena artinya berbeda bagi pemilik:

| Hasil | Arti | Perlu tindakan? |
|---|---|---|
| **Terbit** | Tagihan baru dibuat | Tidak |
| **Dilewati** | Sudah punya tagihan periode ini, atau kontraknya belum mulai | Tidak |
| **Gagal** | Kamar belum punya tarif, atau parameter denda belum ditetapkan | Ya |

Menekan tombol dua kali untuk periode yang sama menghasilkan **HTTP 409**, bukan tagihan kedua.
Aturan ini ditegakkan unique index `(kontrak_id, periode)` di basis data, bukan hanya pengecekan di
aplikasi — pengecekan di aplikasi punya celah balapan, dan dua permintaan yang tiba bersamaan bisa
lolos keduanya.

Lewat API:

```bash
curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
     -d '{"periode":"2026-11-01"}' \
     http://localhost:8081/api/tagihan/terbitkan
```

Atau lewat perintah, untuk penerbitan terjadwal:

```bash
docker compose exec api php artisan tagihan:terbitkan 2026-11-01
```

## Mencatat pembayaran

Buka **Rincian** pada salah satu tagihan, lalu **Catat pembayaran**. Status tagihan diturunkan dari
jumlah uang yang benar-benar tercatat: `belum bayar` → `sebagian` → `lunas`.

| Yang dilakukan | Hasil |
|---|---|
| Bayar kurang dari sisa | Status `sebagian`, sisa berkurang |
| Bayar pas sisa | Status `lunas`, sisa nol |
| Bayar melebihi sisa | **422** dengan pesan yang menyebut sisa — tidak disimpan sebagai saldo |
| Bayar tagihan yang dibatalkan | **422** |
| Tanggal pembayaran di masa depan | **422** |

Sisa tagihan = `pokok + denda − total pembayaran`. Dendanya tetap dihitung dari pokok penuh, jadi
membayar Rp 1 tidak menurunkan denda.

Dua pembayaran yang tiba bersamaan tidak bisa membuat total melebihi tagihan: baris `tagihan`
dikunci `FOR UPDATE` sebelum sisa diperiksa.

```bash
curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
     -d '{"jumlah":500000,"tanggal_pembayaran":"2026-11-10","catatan":"transfer BCA"}' \
     http://localhost:8081/api/tagihan/1/pembayaran
```

Catatan penting: tabel `pembayaran` bersifat **append-only** (trigger menolak UPDATE dan DELETE),
dan baris pembalik belum ada. Salah input jumlah karena itu **tidak bisa diperbaiki dari
aplikasi** — lihat batasan yang dicatat di `SPEC.md` §5.

## Jejak audit

Setiap tagihan yang terbit, setiap pembayaran yang tercatat, setiap tarif baru, dan setiap parameter
denda baru dicatat di tabel `log_audit` — aktor, aksi, entitas, id, waktu, nilai lama, dan nilai
baru. Tabel itu **append-only**: trigger basis data menolak UPDATE dan DELETE, jadi riwayatnya tidak
bisa diubah lewat SQL langsung sekalipun.

Jejak ditulis di dalam transaksi yang sama dengan perubahannya. Kalau ditulis setelah commit, ada
jendela waktu di mana data sudah berubah tetapi jejaknya belum ada.

Endpoint baca `GET /api/audit-log` dan halaman log untuk pemilik masuk di T9.

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
│   ├── app/Domain/Billing/ Kalkulator (murni), PenerbitTagihan, PenerbitanMassal,
│   │                       PenghitungDenda, PencatatPembayaran
│   ├── app/Domain/Audit/ PencatatAudit — penulis log_audit
│   ├── app/Http/         Controller, Request, Resource, Middleware
│   ├── app/Models/       User, Kamar, Penghuni, Kontrak, TarifSewa, KomponenBiaya,
│   │                     ParameterDenda, Tagihan, TagihanRincian, Pembayaran, LogAudit
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
| 422 | Validasi gagal; juga pembayaran melebihi sisa atau pada tagihan yang dibatalkan |

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
