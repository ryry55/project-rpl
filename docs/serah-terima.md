# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: Penutupan temuan B/C audit putaran 2 — 29 Oktober 2026

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Penutupan temuan B/C** audit putaran 2: 5 temuan ditutup (C1, C2, C7, B3, B5) + 2 lapis header keamanan |
| Suite PHP | **801 lulus / 3684 asersi** (Feature 701 / Unit 100), 46 berkas; pint & eslint bersih |
| Migrasi / endpoint / tabel domain | **41** / **86** / **23** (total 32 di skema `public`) |
| Skrip uji | **29** `.mjs`: **28** (25 browser + 2 balapan + 1 jalur proxy) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **792 langkah** (jumlah `langkah lulus` dari gate), gate penuh **415 detik** |
| Gate lokal | **SEMUA LULUS** (`-Cepat`, 29 Okt: 801 tes, pint, eslint, dokumen, ERD/LRS) |

## 2. Tiga aksi berikutnya

1. **Fase 1: Render + Neon.** Butuh akun pemilik: `RENDER_DEPLOY_HOOK`,
   `APP_URL`, environment GitHub `staging`/`production`. Rincian: `9_DEPLOY.md`.
2. **Verifikasi Midtrans sandbox.** Butuh `MIDTRANS_SERVER_KEY` asli. Yang sudah
   terbukti: `item_details` terkirim (harga × jumlah = `gross_amount`).
3. **Jawab K-05 dan K-06** di `docs/keputusan-terbuka.md` (paginasi
   `/api/tagihan`; A3 + penanda "periode ini belum terbit"). Keduanya
   keputusan pemilik, bukan pekerjaan yang bisa ditebak.

## 3. Yang tidak boleh diubah

Jangan menyimpang dari ini tanpa keputusan pemilik.

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-07 | `SPEC.md` bagian 4 |
| Wewenang pengelola (8) dan Model A | `SPEC.md` bagian 5 |
| Konvensi T24-T29 | `AGENTS.md` |
| Sumber tunggal DFD / use case | `docs/dfd/dfd-data.js`, `docs/akademik/usecase-data.js` |
| Keputusan pemilik | `docs/keputusan-terbuka.md` (K-01..K-04 sudah `dijawab`) |
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

**Lima temuan audit putaran 2** (`11_AUDIT-PRODUKSI-2.md` §B/§C), semuanya
dibuktikan bisa gagal — bukan hanya ditulis:

| Temuan | Hasil |
|---|---|
| C1 batas unggah | `client_max_body_size 4m` di tiga nginx. 1,47 MB **413 → 201**; 2,4 MB → **422** berpesan Indonesia; 5 MB → 413. Di laptop batasnya ternyata **1 MB**, bukan 2 MB |
| C7 cache API | Middleware `TanpaCache`: `no-store, private` untuk JSON; CSV dan gambar QRIS tidak ditimpa |
| B3 email gagal | `log_audit` aksi `email.gagal` + saringan di layar Jejak audit |
| B5 HSTS + CSP | HSTS dan CSP **mode lapor** di image produksi; `GET /` membawa keduanya |
| C2 token | `SANCTUM_EXPIRATION` masuk `.env.example` dan `9_DEPLOY.md` |

**Bukti 29 Oktober 2026** (semua di laptop; skrip sekali pakai, bukan gate):

| Yang dibuktikan | Hasil |
|---|---|
| Restore backup | `backup.sh --uji-pulih` kode 0; `users` 5, `penghuni` 3, `kamar` 2, `kontrak` 2, `tagihan` 2, `pembayaran` 4, `log_audit` 17 |
| Image produksi | `Dockerfile.prod` terbangun; tiga proses RUNNING; `smoke-produksi.sh` **LULUS** |
| Pagar entrypoint | `APP_DEBUG=true`, `APP_DEMO_MODE=true`, SMTP jauh + alamat contoh — ketiganya keluar 1 |
| Uji beban | 30 kamar × 13 periode = 390 tagihan; terbit massal 640 ms rata-rata; daftar tagihan 550 KB / 297 ms; DB 12 MB |
| Tiga peran + papan ketik | Pemilik 15 menu, pengelola 11 (tanpa Kebijakan/Rekening/Pengelola), penghuni portal; `/api/tagihan` 403 untuk penghuni. 419 elemen: fokus selalu terlihat, tidak ada tombol tanpa nama |

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

1. **`add_header` nginx tidak diwarisi** oleh lokasi yang punya `add_header`
   sendiri. Halaman SPA disajikan lewat `try_files` → `location = /index.html`,
   jadi header di level `server` tidak sampai ke HTML; harus diulang di lokasi
   itu. Sudah naik ke `AGENTS.md`.
2. **Konfigurasi `api-web` dipasang sebagai volume, `web` dibakar ke image.**
   Mengubah `api/docker/nginx/default.conf` cukup `restart api-web`; mengubah
   `web/docker/nginx/default.conf` wajib `build web`. Tanpa itu hasil uji
   membaca konfigurasi lama dan terlihat seperti perbaikan yang tidak bekerja.
3. **Uji batas unggah harus lewat `web`**, bukan langsung ke `api-web`: dua
   lapis nginx punya batas masing-masing, dan yang paling ketat yang menang.
4. **Pemeriksa dokumen bisa hijau karena kode keluarnya salah.** `periksa.ps1`
   menilai `$LASTEXITCODE`; skrip Node tanpa `process.exitCode` selalu lulus.
5. **`docker compose exec` lewat PowerShell bisa dilaporkan gagal** karena
   `psql` menulis `NOTICE` ke stderr — baca kode dari dalam:
   `sh -c '...; echo kode=$?'`. Dan "sudah masuk" diuji lewat formulir, bukan
   dengan menyuntik token ke `localStorage`.
6. **Tanpa `DB_CONNECTION=pgsql`, migrasi produksi jatuh ke SQLite** dengan galat
   trigger yang menyesatkan. Lihat `9_DEPLOY.md` §2.
