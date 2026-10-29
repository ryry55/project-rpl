# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: Verifikasi lapis 2 (CVE, luring, lanskap, N+1, kolektor CSP) — 29 Oktober 2026

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Verifikasi lapis 2**: kerentanan dependensi ditutup, uji luring PWA dibuktikan bisa gagal, lanskap 640x360 diuji, penjaga N+1 ditambahkan, kolektor laporan CSP dibuat |
| Suite PHP | **806 lulus / 3767 asersi** (Feature 706 / Unit 100), 47 berkas; pint & eslint bersih |
| Migrasi / endpoint / tabel domain | **41** / **86** / **23** (total 32 di skema `public`) |
| Skrip uji | **29** `.mjs`: **28** (25 browser + 2 balapan + 1 jalur proxy) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **802 langkah** (jumlah `langkah lulus` dari gate), gate penuh **455 detik** |
| Gate lokal | **SEMUA LULUS** (29 Okt: 806 tes, pint, eslint, dokumen, ERD/LRS, 28 skrip) |

Catatan: `POST /csp-laporan` **tidak** masuk hitungan 86 endpoint - ia di luar
`/api/` dan sengaja tidak masuk kontrak. `route:list --path=api` tetap 86.

## 2. Tiga aksi berikutnya

1. **Fase 1: Render + Neon.** Butuh akun pemilik: `RENDER_DEPLOY_HOOK`,
   `APP_URL`, environment GitHub `staging`/`production`. Rincian: `9_DEPLOY.md`.
2. **Verifikasi Midtrans sandbox.** Butuh `MIDTRANS_SERVER_KEY` asli. Yang sudah
   terbukti: `item_details` terkirim (harga × jumlah = `gross_amount`).
3. **Jawab K-05 dan K-06** di `docs/keputusan-terbuka.md` (paginasi
   `/api/tagihan`; A3 + penanda "periode ini belum terbit"). Keduanya
   keputusan pemilik, bukan pekerjaan yang bisa ditebak.

Sesudah aplikasi hidup: cari `csp.lapor` di log Render selama seminggu. Kalau
kosong atau hanya `blocked-uri: inline`, naikkan CSP ke menegakkan (FASE 4.3).
Itu pekerjaan pemilik, bukan tebakan sesi berikutnya.

## 3. Yang tidak boleh diubah

Jangan menyimpang dari ini tanpa keputusan pemilik.

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-07 | `SPEC.md` bagian 4 |
| Wewenang pengelola (8) dan Model A | `SPEC.md` bagian 5 |
| Konvensi T24-T29 | `AGENTS.md` |
| Sumber tunggal DFD / use case | `docs/dfd/dfd-data.js`, `docs/akademik/usecase-data.js` |
| Keputusan pemilik | `docs/keputusan-terbuka.md` (K-01..K-04 `dijawab`, K-05/K-06 `menunggu`) |
| Langkah deploy manual | `docs/produk/9_DEPLOY.md` |

**Empat keputusan 28 Oktober 2026 (pilihan A semua).** **K-01**: Midtrans
mengirim `item_details` (harga = nominal yang sama, tidak dihitung ulang).
**K-02**: `SPEC.md` disunting faktual saja — 86 endpoint, tujuh baris hilang
masuk §7, `/audit-log` jadi `wewenang:baca_audit`. **K-03**:
`GET /api/penghuni/kontrak` masuk UC-13. **K-04**: gate dan CI menyegarkan
`rute-api.json` dari `route:list` sebelum memeriksa dokumen.

**Dua keputusan lama yang tetap berlaku.** **`occupancy_rate` = hari-kamar**
(`hari_terisi / hari_tersedia`, kedua ujung inklusif). **Kuitansi tanpa nomor
urut** — penandanya id pembayaran + periode; boleh dicetak pemilik, pengelola,
dan penghuni; baris pembalik tidak punya.

**Bahasa visual: "buku besar tanpa warna".** Arah pertama ("kartu indeks katalog")
**ditolak pemilik** dan tidak boleh dihidupkan lagi. Satu tombol hampir hitam per
layar, tanpa bayangan saat diam; `nav a`, `.tabel`, `.lencana-status`,
`.aksi-kepala`, `.merek p`, `.kartu-kelompok`, `.kendali` harus tetap ada.
**Narasi tetap 4 halaman**; **harga Charter tetap Rp 50.000.000**.

## 4. Yang ditutup sesi ini

| Hal | Hasil |
|---|---|
| Kerentanan dependensi | `symfony/mime` 7.4.19 → **7.4.22**, `polyfill-mbstring` 1.43.3; `composer audit --locked` **bersih**; `npm audit --omit=dev` 0. Efek samping wajar: `laravel/sail`, `laravel/pail`, `symfony/yaml` (hanya ada di lockfile) terbuang |
| Uji luring PWA | `uji-pwa.mjs` 26 langkah: cache HTTP dibersihkan, jaringan dimatikan di halaman **dan** service worker, aplikasi dibuka dari Cache Storage saja |
| Lanskap 640x360 | `uji-tata-letak.mjs` 100 langkah; tiga halaman terlebar. Kontrol negatif (`div` 2000px) membuat 3 asersi merah |
| Penjaga N+1 | `TagihanTest::test_daftar_tagihan_tidak_menambah_kueri_per_baris`; suntikan satu kueri per baris membuatnya **7 → 11** |
| Kolektor CSP | `POST /csp-laporan` + `throttle:30,1`, log `csp.lapor`, `CspLaporanTest` 4 tes |

**Batas yang diketahui:** tanpa jaringan, aplikasi mendarat di
`/masuk?lanjut=<tujuan>` karena `GET /me` tidak bisa dijawab; token tidak
dihapus dan sesi pulih saat jaringan kembali (keduanya diasersikan). Menyimpan
identitas terakhir ke cache = perilaku yang dilihat pengguna = keputusan pemilik.

## 5. Cara memeriksa keadaan

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1    # SEMUA LULUS
node docs/uji/cek-angka-dokumen.js                     # SEMUA ANGKA COCOK
```

Tanpa uji browser: `-Cepat`. Di Linux/CI: `./periksa.sh`. Bila mengubah
`web/src`: `npm run build`, `docker compose build web`, lalu `up -d web` — kode
dibakar ke image. Angka §1 diubah **sesudah** gate, karena jumlah tes dan langkah
E2E hanya diketahui dari keluaran `phpunit`/skrip.

## 6. Jebakan yang menggigit di sesi ini

1. **`Network.emulateNetworkConditions` berlaku per target**, dan service worker
   adalah target tersendiri. Uji luring hijau walau seluruh Cache Storage
   dihapus, karena `fetch()` di `sw.js` tetap sampai ke server. Sudah naik ke
   `AGENTS.md` beserta cara menumpang sesinya (`Target.attachToTarget`).
2. **`Set-Content -Encoding UTF8` (PowerShell 5.1) menulis BOM** dan itu
   mematikan berkas PHP (`Namespace declaration ...`). Build gagal, lalu tes
   berjalan terhadap image **lama** dan memberi hijau. Sudah naik ke `AGENTS.md`.
3. **`add_header` nginx tidak diwarisi** oleh lokasi yang punya `add_header`
   sendiri (lihat `AGENTS.md`).
4. **Menambah berkas pengendali di luar `Api/` mengubah angka dokumen**
   (`3_ARCHITECTURE.md`, `KONTEKS_PROYEK.md`). `cek-angka-dokumen.js` menangkapnya
   - jalankan sebelum commit.
5. **Pemeriksa dokumen bisa hijau karena kode keluarnya salah.** `periksa.ps1`
   menilai `$LASTEXITCODE`; skrip Node tanpa `process.exitCode` selalu lulus.
6. **`docker compose exec` lewat PowerShell bisa dilaporkan gagal** karena
   `psql` menulis `NOTICE` ke stderr — baca kode dari dalam:
   `sh -c '...; echo kode=$?'`.
