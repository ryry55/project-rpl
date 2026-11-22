# Serah-terima antar sesi

> **Ditimpa setiap fase selesai.** Isinya: apa yang barusan dikerjakan, tiga aksi
> berikutnya, dan apa yang tidak boleh diubah. Riwayatnya di `git log`.
> **Maksimal 120 baris** — lebih dari itu ia menjadi `SPEC.md` kedua.

Terakhir diperbarui: 22 November 2026, Fase 9-10 selesai.

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Sesi terakhir | **Fase 9-10** — performa (temuan 5, 6, 8) + pemolesan (9, 10, 11, 12) |
| Rencana berikutnya | **Review charter oleh pemilik** — satu-satunya sisa; tidak ada fase kode lagi |
| Suite PHP | **927 lulus / 4177 asersi** (dari 918/4142); pint & eslint bersih; 19 vitest |
| Gate penuh | **SEMUA LULUS** 22 Nov (692 detik, 34 skrip / 942 langkah browser) |
| Keputusan pemilik | **K-19, K-20 dijawab & diterapkan**; K-01..K-18 tidak berubah |
| Tunnel demo | **hidup 22 Nov** — URL cepat berganti, salin dari `.env` (cara di §6) |
| Paket serahan | **`dokumen/`** — segarkan dengan `dokumen\segarkan.ps1` |
| CI GitHub | **hijau** pada `4850f6b` (run `45041520685`, 11m55s) — kode Fase 9-10 tervalidasi |
| Working tree | Bersih sesudah commit sesi ini |

## 2. Yang sesi ini kerjakan (22 Nov 2026)

Fase 9-10 selesai dan ter-commit (`4850f6b`); rincian per temuan di
`docs/rencana-fase-8-10.md`, `docs/fase-akhir.md`, dan `git log`. Intinya:
N+1 penjumlahan hilang (`withSum` di tunggakan + rekap), `per_page` opsional di
`GET /pembayaran`, `now()` → `WaktuSistem` di enam titik tulis, composable
`useMuatDaftar` (satu view dimigrasikan), kode mati dibuang, tiga port demo ke
`127.0.0.1`, dan `X-Powered-By` disembunyikan + dipagari di
`periksa-profil-produksi.sh`.

Suite **918 → 927 tes / 4142 → 4177 asersi**. Gate penuh 22 Nov: SEMUA LULUS,
692 detik, 34 skrip / **942 langkah**.

**Keputusan pemilik sesudah Fase 9-10**

8. **`pm.max_children` demo = 8, `pm = static`** (`api/docker/php/zz-fpm.conf`),
   disamakan dengan profil produksi. Uji beban 50 bersamaan: p95 **707 ms**
   (run pertama sesudah restart) dan 721 ms, 77 permintaan/detik, 0 galat.
   Dengan `dynamic`, run pertama menanggung kelahiran worker (p95 1510 ms).
9. **Tunnel demo hidup** kembali (`cloudflared` → port 3000; SPA meneruskan
   `/api` ke api-web).
10. **Diagram urutan TIDAK masuk Bab 4 PDF** — Bab 4 adalah laporan Sistem
    Basis Data (normalisasi/ERD/LRS/spesifikasi); 14 diagram urutan tetap
    diserahkan sebagai berkas terpisah `urutan-UC-*.svg/png`. Alasan di
    `docs/akademik/README.md`.

## 3. Tiga aksi berikutnya

1. **Review charter `docs/produk/KONTEKS_PROYEK.md` oleh pemilik** — angka
   sudah disegarkan (927 tes), yang dicek: kejujuran ruang lingkup, tabel
   "Yang sudah selesai", dan klaim demo. Ini satu-satunya pekerjaan tersisa.
2. **Demo sudah siap** — tunnel hidup (URL di `.env` dan §1), data demo terisi.
   Kalau URL berganti, jalankan ulang `isi-demo.mjs` dan `cache:clear` (§6).
3. **Putuskan diagram urutan** — sudah diputuskan: **tidak masuk Bab 4 PDF**;
   alasannya di `docs/akademik/README.md` (14 diagram urutan tetap diserahkan
   sebagai berkas terpisah).

## 4. Keputusan terkunci (baru)

- **K-19 "kunci dua pintu + catat"**: batal mematikan tautan; uang pasca-batal
  dicatat berlabel — BUKAN menolak pembatalan, BUKAN idempotency.
- **K-20 "bekukan denda di nominal tautan"**: bayar penuh = lunas;
  `total_beku` nullable (baris lama pakai total hidup).

## 5. Jebakan yang sudah menggigit (baru)

- **Closure `use` lupa menangkap parameter baru** → `ErrorException` → 500
  tanpa jejak jelas. Dua regresi dari satu pola (`$sebelum`, `$totalBeku`/
  `$terimaPadaBatal`). Selalu periksa daftar `use` saat menambah parameter.
- **Tes balapan via hook `retrieved`**: hook menembak saat baris dikunci
  (`lockForUpdate`) — kalau hook membuat kontrak aktif kedua SEBELUM kontrak
  lama berstatus `selesai`, indeks `kontrak_satu_aktif_per_kamar` yang
  menolak, bukan kode. Letakkan hook pada titik yang mau diuji (setelah
  kontrak selesai, sebelum cek `masihAktif`).
- **`kelas-data.js` menurunkan atribut dari `skema.json`**: migrasi kolom
  membuat `kelas-c.svg` basi OTOMATIS — gate merah di "diagram kelas dan
  urutan tidak basi". Perbaikannya `node docs/akademik/build-diagram.js` +
  `export-png.ps1`, bukan menyunting SVG.
- **Gate berjalan sambil disunting, dan dipipa**: `Select-Object -Last N`
  membuang semua output kecuali N baris terakhir (waktu/jumlah langkah tak bisa
  diverifikasi), dan menyunting skrip yang sedang dibaca bash membuatnya gagal
  di tengah baris. Sunting dulu, jalankan kemudian; jangan pipa bila butuh
  angkanya — pipa juga mengubah `node ... 2>&1` menjadi galat fatal di PS 5.1.
- **Klaim status di `dokumen/README.md` dibuktikan ke pemeriksa gate dulu**:
  "metode kelas menunggu sinkron" ternyata salah diagnosis — metode diagram
  kelas diturunkan otomatis dari model, dan kelas layanan domain memang tidak
  digambar (aturan `kelas-data.js`: tiap kelas wajib punya tabel di skema).
- **Hitung trigger dari basis data hidup, bukan dari teks migrasi**:
  `parameter_denda_append_only` ikut mati bersama tabelnya di `hapus_deposit`
  — migrasi memuat 9 nama, DB hidup punya 8. Query langsung
  `docker compose exec db psql -U kos -d kos -tAc "..."` (jangan lewat
  `sh -c` — baris hasilnya tertelan).
- **p95 uji beban mengukur antrean php-fpm, bukan aplikasi**: worker 5 (bawaan
  image) membuat 50 bersamaan antre dan p95 1064–1356 ms; serial 94 ms. Kini
  demo memakai 8 worker `static`. Dengan `dynamic`, run pertama sesudah
  restart menanggung kelahiran worker (p95 1510 ms) — itu yang paling
  menyesatkan, karena run kedua langsung 704 ms.
- **`git stash` + build ulang = satu-satunya A/B yang sah di repo ini**: kode
  dibakar ke image, jadi membandingkan kode lama dan baru menuntut build ulang
  di antara keduanya. Tanpa itu, "kode lama" yang diukur adalah image baru.
- **Uji beban butuh data setara, bukan data sisa gate**: 390 tagihan dibuat
  lewat `PenerbitTagihan` (bukan INSERT mentah) sebelum mengukur; sesudahnya
  `migrate:fresh --seed` + `isi-demo.mjs` supaya data demo tidak tercemar
  tiga kamar `B1..B3` dan tagihan 2016.

## 6. Verifikasi klaim berkas ini

```powershell
docker compose exec api php artisan test        # 927 tes, 4177 asersi
node docs/uji/cek-angka-dokumen.js              # SEMUA ANGKA COCOK
node docs/uji/cek-erd-lrs.js                     # 26 tabel, 229 kolom
node docs/uji/cek-diagram.js                    # SVG = data, PNG = 2x SVG
bash docs/uji/periksa-profil-produksi.sh        # PROFIL PRODUKSI LULUS (25 baris)
docker compose ps                               # port hanya 127.0.0.1
curl -sI http://localhost:8081/api/health       # tanpa X-Powered-By
node docs/beban/uji-beban.mjs                      # 50 bersamaan: p95 707 ms, 0 galat
```

Tunnel demo (arahkan ke SPA; nginx SPA meneruskan `/api`):

```powershell
Get-Process cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force
& "$env:LOCALAPPDATA\cloudflared\cloudflared.exe" tunnel --url http://localhost:3000
# URL baru -> .env (APP_URL, APP_URL_SPA), lalu:
docker compose up -d api; docker compose exec api php artisan cache:clear
node "$env:TEMP\opencode\isi-demo.mjs" "<url-baru>"
```

Mailpit untuk email demo tetap di `http://localhost:8025` (tidak lewat tunnel).
