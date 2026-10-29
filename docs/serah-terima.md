# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: Audit ulang + bukti restore, beban, image produksi, tiga peran — 29 Oktober 2026

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Audit ulang**: 1 klaim basi ditemukan; 5 pekerjaan yang tidak butuh akun diselesaikan |
| Suite PHP | **799 lulus / 3674 asersi** (Feature 699 / Unit 100), 45 berkas; pint & eslint bersih |
| Migrasi / endpoint / tabel domain | **41** / **86** / **23** (total 32 di skema `public`) |
| Skrip uji | **29** `.mjs`: **28** (25 browser + 2 balapan + 1 jalur proxy) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **792 langkah** (jumlah `langkah lulus` dari gate), gate penuh **482 detik** |
| Gate lokal | **SEMUA LULUS** (`-Cepat`, 29 Okt: 799 tes, pint, eslint, dokumen, ERD/LRS) |

Catatan: putaran 28 Oktober sempat merah di pemeriksa angka karena berkas ini
masih menulis 85 endpoint; sudah diperbaiki dan `-Cepat` 29 Oktober lulus.

## 2. Tiga aksi berikutnya

1. **Fase 1: Render + Neon.** Butuh akun pemilik: `RENDER_DEPLOY_HOOK`,
   `APP_URL`, environment GitHub `staging`/`production`. Rincian: `9_DEPLOY.md`.
2. **Verifikasi Midtrans sandbox.** Butuh `MIDTRANS_SERVER_KEY` asli. Yang sudah
   terbukti: `item_details` terkirim (harga × jumlah = `gross_amount`).
3. **Putuskan A3** (cron eksternal / plan berbayar / terbit manual) **dan**
   penanda dasbor "periode ini belum terbit" — keduanya keputusan pemilik.

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

- **Dokumen yang menyangkal fitur** (Midtrans, backup dan tagihan terjadwal,
  `Dockerfile.prod`, "dua peran", 11 folder) dan **angka basi** (85→86 endpoint,
  792→799 tes, langkah per skrip dari keluaran gate) — 22 temuan, dokumen saja.
- **Pemeriksa yang tidak menahan**: dua skrip selalu keluar 0; sekarang
  `process.exitCode`. `cek-cakupan-kontrak.js` kini memeriksa baris **Total** §9
  dan menganggap `/webhook/midtrans` tercatat lewat §8.0.1. Kontrak §9 = 86.

**Bukti baru 29 Oktober 2026** (semua di laptop; skrip sekali pakai, bukan gate):

| Yang dibuktikan | Hasil |
|---|---|
| Restore backup | `backup.sh --uji-pulih` kode 0; `users` 5, `penghuni` 3, `kamar` 2, `kontrak` 2, `tagihan` 2, `pembayaran` 4, `log_audit` 17 |
| Image produksi | `Dockerfile.prod` terbangun (948 MB); tiga proses RUNNING; `smoke-produksi.sh` **LULUS** 4/4 |
| Pagar entrypoint | `APP_DEBUG=true`, `APP_DEMO_MODE=true`, SMTP jauh + alamat contoh — ketiganya keluar 1 |
| Uji beban | 30 kamar × 13 periode = 390 tagihan; terbit massal 640 ms rata-rata; daftar tagihan 550 KB / 297 ms; DB 12 MB |
| Tiga peran + papan ketik | Tiga profil Chrome: pemilik 15 menu, pengelola 11 (tanpa Kebijakan/Rekening/Pengelola), penghuni portal; `/api/tagihan` 403 untuk penghuni. 419 elemen: fokus selalu terlihat, tidak ada tombol tanpa nama |
Klaim basi yang ditemukan dan sudah diperbaiki: `11_AUDIT-PRODUKSI-2.md` §B1
("tidak ada perintah restore", "tidak pernah ada latihan restore") — salah sejak T27-T29.

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

1. **Pemeriksa dokumen bisa hijau karena kode keluarnya salah.** `periksa.ps1`
   menilai `$LASTEXITCODE`; skrip Node tanpa `process.exitCode` selalu lulus.
2. **Potret rute bukan sumber kebenaran**; `rute-api.json` hanya sah dibaca
   setelah disegarkan dari `route:list`. Gate dan CI melakukannya sendiri.
3. **`>` di PowerShell menulis UTF-16.** Pakai
   `[IO.File]::WriteAllText(..., UTF8Encoding($false))`.
4. **Hitungan statis `lapor(` ≠ langkah sebenarnya** (610 vs 792). Hanya
   keluaran `langkah lulus` yang sah.
5. **Angka per tiket di `docs/tiket.md` dan tabel riwayat
   `audit-ketertelusuran.md` adalah potret saat tiket selesai.** Yang harus cocok
   dengan gate: baris R-01..R-07 dan §4.2 katalog.
6. **`docs/uji/*.png` ditulis ulang setiap E2E** — kembalikan yang tidak relevan
   (`git checkout -- docs/uji/*.png`); `print()` tidak bisa diasersikan dari CDP.
7. **Dua jebakan saat menguji:** `docker compose exec` lewat PowerShell bisa
   dilaporkan gagal karena `psql` menulis `NOTICE` ke stderr — baca kode dari
   dalam (`sh -c '...; echo kode=$?'`); dan "sudah masuk" diuji lewat formulir,
   bukan dengan menyuntik token ke `localStorage`.
8. **Tanpa `DB_CONNECTION=pgsql`, migrasi produksi jatuh ke SQLite** dengan galat
   trigger yang menyesatkan. Lihat `9_DEPLOY.md` §2.
