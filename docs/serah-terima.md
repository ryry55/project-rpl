# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang harus dikerjakan berikutnya, dan apa yang
> tidak boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> Aturan: **maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md`
> kedua dan gunanya hilang. Keputusan yang terkunci **dirujuk**, tidak disalin -
> salinan akan menyimpang dari sumbernya.

Terakhir diperbarui: fase R6/R7 selesai, REV-SEC-03 ditutup - 25 Oktober 2026

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir yang selesai | **R1-R7 tuntas**, plus REV-SEC-03 |
| Fase yang sedang dikerjakan | - (berikutnya LANGKAH 11: produksi) |
| Suite PHP | **715 lulus / 3395 asersi**, 36 berkas tes |
| Pint / ESLint | bersih |
| Migrasi | **36** |
| Endpoint `/api` | **78** |
| Tabel domain | **22** |
| Skrip uji | **24** (22 browser + 2 balapan, di luar `_cdp.mjs`), **730 langkah** |
| `periksa.ps1` | **SEMUA LULUS** (395 detik, 25 skrip) |

**Working tree bersih** kecuali `revisi.md` dan `docs/revisi.md`, yang **sengaja
tidak di-commit**: keduanya masukan audit, bukan deliverable.

---

## 2. Tiga aksi berikutnya

1. **LANGKAH 11 - produksi.** `Dockerfile.prod` satu layanan (SPA di dalam
   `public/` Laravel), Neon, `SESSION_DRIVER`/`CACHE_STORE` dari `file`,
   hapus akun demo, cron backup, T22 Midtrans. Ini yang mengubah proyek dari
   "jalan di laptop" menjadi "bisa dibuka dosen lewat URL".
2. **Baca `docs/akademik/README.md`** sebelum menyentuh apa pun di sana.
   Semua berkas akademik **dibangkitkan**; suntingan tangan hilang pada build
   berikutnya. Ubah `usecase-data.js` / `dokumen-data.js` lalu bangun ulang.
3. **Dua kolom mati** (`gerbang`, `konsekuensi`) di `usecase-data.js`. Bukan
   bug - calon pembersihan, bukan perbaikan mendesak.

---

## 3. Yang tidak boleh diubah

Jangan menyimpang dari ini tanpa keputusan pemilik. Rujukannya:

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-07 | `SPEC.md` bagian 4 |
| Wewenang pengelola (8) dan Model A | `SPEC.md` bagian 5 |
| Konvensi T24-T29 | `AGENTS.md` |
| Sumber tunggal DFD | `docs/dfd/dfd-data.js` |
| Sumber tunggal use case | `docs/akademik/usecase-data.js` |
| Keputusan terbuka | `docs/keputusan-terbuka.md` |

**Sudah diputuskan pemilik: narasi tetap 4 halaman, bukan 3.** Template dosen
memakai 3 halaman, tetapi template hanya punya 10 aliran data dan 5 entitas,
sedangkan sistem ini 14 aliran, 12 entitas, dan 7 aturan. Diukur dengan
`ukur-halaman.ps1`: 4 halaman. Memaksa 3 berarti membuang isi yang diminta
template sendiri. Jangan "merapikannya" menjadi 3 tanpa bertanya.

**Kedua PDF sudah diekspor** dan bentuknya mengikuti template: narasi 4 halaman,
diagram tepat 2 halaman (satu diagram per halaman, tanpa tabel penjelas - tabel
matriks tinggal di narasi).

---

## 4. Cacat yang sudah ditutup sesi ini

**REV-SEC-03 - tautan email menunjuk ke API, bukan SPA.** Tautan aktivasi dan
reset sandi dibangun dari `config('app.url')` = `http://localhost:8081`, padahal
`/atur-sandi` hanya ada di SPA port 3000. Penghuni mengklik tautan dan menerima
404 dari API. Lolos dari 713 tes karena semuanya membaca JALUR, tidak ada yang
membaca ALAMAT lengkap.

Sekarang ada `config('app.url_spa')` yang **bawaannya jatuh ke `APP_URL`**
(aman untuk produksi satu layanan, yang memang satu origin). `.env` lokal
mengisinya `http://localhost:3000`. CORS sengaja tidak diubah. Dijaga dua tes di
`AktivasiAkunTest`, keduanya dibuktikan bisa gagal.

---

## 5. Cara memeriksa keadaan

Dari akar repo:

```powershell
git status --short                              # harus bersih kecuali 2 berkas audit
docker compose exec -T api php artisan test      # 715 lulus / 3395 asersi
powershell -ExecutionPolicy Bypass -File periksa.ps1   # SEMUA LULUS
node docs/akademik/uji-validator.js              # 4/4 kasus gagal sebagaimana mestinya
powershell -ExecutionPolicy Bypass -File docs/akademik/ukur-halaman.ps1  # 4 halaman
```

---

## 6. Jebakan yang menggigit di sesi ini

Tercatat juga di `AGENTS.md`; diulang di sini karena mahal.

1. **`docker compose exec api` menjalankan kode dari IMAGE, bukan dari disk.**
   Menjalankan `pint` di dalam container memformat salinan di container, bukan
   berkas di host - `periksa.ps1` lalu tetap gagal. **Pint harus dijalankan di
   host** (`cd api; php vendor\bin\pint`). Hal yang sama berlaku untuk tes:
   setelah menyunting `api/`, **build image dulu**, kalau tidak tes menjalankan
   versi lama dan hasilnya menyesatkan.
2. **Menyapu angka usang dengan pola digit saja tidak cukup.** "kedelapan belas
   skrip" lolos dari `Select-String '18 skrip'`. Periksa juga ejaan angka.
3. **Satuan bisa tertukar tanpa terlihat salah.** Piramida tes sempat menulis
   "32 tes Feature / 4 tes Unit" - itu jumlah BERKAS, lalu dijumlahkan dengan
   "713 tes total" di baris yang sama. Cocokkan satuan, bukan hanya angkanya.
4. **`config()` tidak mengevaluasi `env()`.** Tes yang menulis
   `config(['app.url_spa' => null])` lulus secara palsu. Untuk menguji rantai
   `?:` di `config/app.php`, muat ulang berkasnya lewat Dotenv dengan repositori
   **mutable** - `immutable` akan menolak menimpa nilai container.
5. **PowerShell merusak kutip di `py -c` dengan semicolon.** Tulis berkas `.py`.
6. **Chrome menolak `--print-to-pdf` dengan path relatif.** Pakai path absolut.
