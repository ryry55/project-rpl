# Audit Ketertelusuran

Peta aturan bisnis → implementasi → tes → status. **Dirawat berjalan**, diperbarui setiap tiket
selesai, dan diaudit formal sekali di akhir. Lihat `SPEC.md` §10.

Status: ✅ selesai · ⚠ sebagian · ⏳ belum · ⏸ ditunda

## R-01..R-07

| Aturan | Implementasi | Tes | Status |
|---|---|---|---|
| **R-01** prorata bulan pertama | — | — | ⏳ T3 |
| **R-02** satu tagihan per kontrak per periode | — | — | ⏳ T4 |
| **R-03** denda 1%/hari, plafon 30% | — | — | ⏳ T6 |
| **R-04** umur tunggakan | — | — | ⏳ T7 |
| **R-05** deposit hanya untuk kerusakan | — | — | ⏸ ditunda (T8) |
| **R-06** tarif tidak berlaku surut | — | — | ⏳ T2 (lapis 1), T3 (lapis 2) |
| **R-07** isolasi penghuni + log audit | — | — | ⏳ T9 |

## Konvensi HTTP (seam 2)

Bukan aturan bisnis, tapi kontrak yang harus berlaku sejak tiket pertama.

| Kontrak | Implementasi | Tes | Status |
|---|---|---|---|
| Respons sukses `{ "data": ... }` | `AuthController`, `UserResource` | `test_login_dengan_kredensial_benar_mengembalikan_token` | ✅ |
| Error `{ "message", "errors" }` | `LoginRequest`, handler Laravel | `test_login_tanpa_email_ditolak_422` | ✅ |
| 401 tanpa token | middleware `auth:sanctum` | `test_me_tanpa_token_ditolak_401` | ✅ |
| 401 token tidak valid | middleware `auth:sanctum` | `test_me_dengan_token_tidak_valid_ditolak_401` | ✅ |
| 403 peran salah | `PastikanPeran` | `test_penghuni_mengakses_endpoint_pemilik_ditolak_403` | ✅ |
| 422 validasi gagal | `LoginRequest` | `test_login_tanpa_email_ditolak_422` | ✅ |
| Selalu JSON, walau tanpa `Accept` | `PaksaJson` | `test_tanpa_header_accept_tetap_401_bukan_500` | ✅ |
| 422 walau tanpa `Accept` (bukan 302) | `PaksaJson` | `test_validasi_gagal_tanpa_header_accept_tetap_422_bukan_302` | ✅ |
| Zona waktu `Asia/Jakarta` | `config/app.php`, `php.ini`, `TZ` Postgres | `test_zona_waktu_aplikasi_asia_jakarta` | ✅ |

## Jejak tiket

| Tiket | Issue | Bukti |
|---|---|---|
| T1 Walking skeleton | #2 | 14 tes hijau, 9/9 langkah E2E browser, screenshot dashboard |

## Cara menjalankan ulang bukti

```bash
docker compose up -d
docker compose exec api php artisan test          # 14 tes
node docs/uji/e2e-t1.mjs                          # perlu Chrome --remote-debugging-port=9222
```

## Catatan audit

**Dua bug ditemukan lewat E2E, bukan lewat tes fitur.** Keduanya layak dicatat karena menunjukkan
nilai menguji lewat browser, bukan hanya lewat `$this->postJson()`:

1. **401 menjadi 500.** Klien tanpa header `Accept: application/json` diperlakukan sebagai
   permintaan web; middleware `auth` mencoba me-redirect ke route bernama `login` yang tidak ada
   di aplikasi API-only. Diperbaiki dengan middleware `PaksaJson`. Tes regresi ditambahkan.

2. **Tes menghapus database demo.** `RefreshDatabase` menjalankan `migrate:fresh`, dan
   `DB_DATABASE` dari environment Docker menang atas `<env>` di `phpunit.xml` karena Laravel
   membaca `$_SERVER` lebih dulu. Akibatnya tes menulis ke database `kos` — data demo hilang, dan
   gejalanya menyesatkan: login gagal padahal kode benar. Diperbaiki dengan `<server>` (bukan
   `<env>`) dan pagar di `tests/TestCase.php` yang menolak berjalan bila nama database tidak
   berakhiran `_test`.
