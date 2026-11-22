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
| Tunnel demo | URL 17 Nov **mati** (laptop restart); nyalakan ulang sebelum demo (§6) |
| Paket serahan | **`dokumen/`** — segarkan dengan `dokumen\segarkan.ps1` |
| CI GitHub | **hijau** pada `4850f6b` (run `45041520685`, 11m55s) — kode Fase 9-10 tervalidasi |
| Working tree | Bersih sesudah commit Fase 9-10 |

## 2. Yang sesi Fase 9-10 kerjakan (22 Nov 2026)

Fase 8 (K-19/K-20, lima temuan jalur uang) ditutup di commit `3c8f7db`;
rinciannya di `git log` dan `docs/rencana-fase-8-10.md`.

**Fase 9 — performa & konsistensi**

1. **#5** `withSum('pembayaran as total_dibayar', 'jumlah')` di
   `DaftarTunggakan::susun` dan `RekapPendapatan::piutang` — N+1 penjumlahan
   hilang. Bukti: dua tes jumlah kueri, merah dulu (6 → 8 kueri).
2. **#6** `per_page` opsional di `GET /pembayaran`, pola K-05; tanpa
   `per_page` respons tidak berubah.
3. **#8** `now()` → `WaktuSistem` di **enam** titik: `PenerbitTagihan`,
   `KontrakController` (status `dipesan` + `tgl_keluar`),
   `LaporanKerusakanController` (`selesai_pada`), `PemutusUsulan`
   (setujui + tolak). Enam tes `SimulasiTanggalTulisTest` merah dulu.

**Fase 10 — pemolesan**

4. **#9** composable `web/src/composables/useMuatDaftar.js`; `PenghuniView`
   dimigrasikan sebagai bukti, `uji-galat-muat.mjs` tetap hijau (30 langkah).
5. **#10** kode mati dibuang: `MidtransGateway::clientKey()` + parameter
   `clientKey`, injeksi `WaktuSistem` di `PencatatPembayaran`,
   `Pembayaran::asal()`. `TransaksiGateway::STATUS_DIBATALKAN` **dipakai** K-19.
6. **#11** tiga port demo dipatok `127.0.0.1` (API, SPA, Mailpit).
7. **#12** `fastcgi_hide_header X-Powered-By` di nginx demo + template produksi,
   **dipagari** dua pemeriksaan baru di `periksa-profil-produksi.sh`
   (kontrol negatif: satu baris dihapus → GAGAL, dipulihkan → LULUS).

Suite **918 → 927 tes / 4142 → 4177 asersi**. Gate penuh 22 Nov: SEMUA LULUS,
692 detik, 34 skrip / **942 langkah** (angkanya tidak berubah).

## 3. Tiga aksi berikutnya

1. **Review charter `docs/produk/KONTEKS_PROYEK.md` oleh pemilik** — angka
   sudah disegarkan (927 tes), yang dicek: kejujuran ruang lingkup, tabel
   "Yang sudah selesai", dan klaim demo. Ini satu-satunya pekerjaan tersisa.
2. **Hidupkan demo sebelum sidang** (§6) — tunnel 17 Nov mati, URL baru harus
   masuk `.env` lalu `isi-demo.mjs` dijalankan ulang.
3. **Putuskan `pm.max_children` demo** (opsional): 5 worker bawaan image
   membuat p95 gagal di 50 bersamaan. Bukti dan alasannya di
   `docs/fase-akhir.md` §2 — angkanya 161 ms pada 5 bersamaan, 1064+ pada 50.
   Jangan diubah hanya agar angka lulus; ubah hanya bila demo memang akan
   dihantam banyak orang sekaligus.

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
- **p95 uji beban mengukur antrean php-fpm, bukan aplikasi**: container demo
  memakai `pm.max_children = 5` bawaan image. Pada 50 bersamaan, 500 permintaan
  antre dan p95 menjadi 1064–1356 ms; pada 5 bersamaan p95 161 ms. Uji A/B
  membuktikan kode Fase 9 **tidak** lebih lambat (serial 94 vs 96 ms). Jangan
  menyimpulkan regresi dari angka p95 tanpa memeriksa jumlah worker.
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
$env:BEBAN_BERSAMAAN='5'; node docs/beban/uji-beban.mjs   # p95 128 ms (22 Nov, 390 tagihan)
```

Hidupkan demo (tunnel 17 Nov sudah mati):

```powershell
Get-Process cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force
& "$env:LOCALAPPDATA\cloudflared\cloudflared.exe" tunnel --url http://localhost:8081
& "$env:LOCALAPPDATA\cloudflared\cloudflared.exe" tunnel --url http://localhost:8025
# URL baru -> .env (APP_URL, APP_URL_SPA), lalu:
node "$env:TEMP\opencode\isi-demo.mjs" "<url-api-baru>"
docker compose exec api php artisan cache:clear
```
