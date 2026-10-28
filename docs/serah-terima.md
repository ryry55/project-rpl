# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: Audit janji-dokumen vs aplikasi, K-01..K-04 dikerjakan — 28 Oktober 2026

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Audit dokumen**: 27 temuan; dokumen diperbaiki, K-01..K-04 dikerjakan |
| Suite PHP | **799 lulus / 3674 asersi** (Feature 699 / Unit 100), 45 berkas; pint & eslint bersih |
| Migrasi / endpoint / tabel domain | **41** / **86** / **23** (total 32 di skema `public`) |
| Skrip uji | **29** `.mjs`: **28** (25 browser + 2 balapan + 1 jalur proxy) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **792 langkah** (jumlah `langkah lulus` dari gate), gate penuh **482 detik** |
| Gate lokal | **SEMUA LULUS** kecuali satu: lihat catatan di bawah |

Catatan jujur: putaran 28 Oktober berhenti dengan **1 langkah merah** — pemeriksa
angka, karena berkas ini masih menulis 85 endpoint saat ia membacanya. Angka di
atas sudah diperbaiki; putaran berikutnya harus hijau. Kalau tidak, jangan
percaya tabel ini — jalankan `node docs/uji/cek-angka-dokumen.js` dan lihat barisnya.

## 2. Tiga aksi berikutnya

1. **Jalankan gate sekali lagi** (`periksa.ps1`) dan pastikan `SEMUA LULUS`.
   Yang perlu dibuktikan cuma satu: pemeriksa angka berhenti merah setelah §1.
2. **Fase 1: Render + Neon.** Butuh akun pemilik: `RENDER_DEPLOY_HOOK`,
   `APP_URL`, environment GitHub `staging`/`production`. Rincian: `9_DEPLOY.md`.
3. **Verifikasi Midtrans sandbox.** Butuh `MIDTRANS_SERVER_KEY` asli. Yang sudah
   terbukti: `item_details` terkirim (harga × jumlah = `gross_amount`).

Terbuka dan **jangan ditebak**: jadwal A3 vs container tidur (cron eksternal
atau plan berbayar). Ini keputusan pemilik, dicatat di `11_AUDIT-PRODUKSI-2.md`.

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

**Empat keputusan 28 Oktober 2026 (pilihan A semua).**

- **K-01**: `MidtransGateway` mengirim `item_details`; keterangannya "Sewa kamar
  <nomor> - <bulan tahun>". Harga item = nominal yang sama, tidak dihitung ulang.
- **K-02**: `SPEC.md` disunting **faktual** saja: 86 endpoint, tujuh endpoint
  hilang ditambahkan ke §7, `/audit-log` jadi `wewenang:baca_audit`.
- **K-03**: `GET /api/penghuni/kontrak` masuk UC-13, sehingga klaim "86 endpoint
  terpakai habis" kembali benar.
- **K-04**: gate menyegarkan `rute-api.json` dari `route:list` sebelum memeriksa
  dokumen; CI memindahkan pemeriksaan itu ke job `uji`.

**Dua keputusan lama yang tetap berlaku.**

- **`occupancy_rate` = hari-kamar**: `hari_terisi / hari_tersedia`, kedua ujung
  tanggal inklusif, kontrak yang belum ditutup dihitung sampai `sampai`.
- **Kuitansi tanpa nomor urut.** Penandanya id pembayaran + periode tagihan.
  Boleh dicetak **pemilik, pengelola, dan penghuni**; baris pembalik tidak punya.

**Bahasa visual: "buku besar tanpa warna".** Arah pertama ("kartu indeks katalog")
**ditolak pemilik** dan tidak boleh dihidupkan lagi. Satu tombol hampir hitam per
layar, tanpa bayangan saat diam; `nav a`, `.tabel`, `.lencana-status`,
`.aksi-kepala`, `.merek p`, `.kartu-kelompok`, `.kendali` harus tetap ada.

**Narasi tetap 4 halaman**; **harga Charter tetap Rp 50.000.000**.

## 4. Yang ditutup sesi ini

- **Dokumen yang menyangkal fitur**: Midtrans ("tidak ada kodenya"), backup dan
  tagihan terjadwal, `Dockerfile.prod`, "dua peran", 11 folder domain.
- **Angka**: 85→86 endpoint di sepuluh dokumen; 792→799 tes; langkah per skrip
  diambil dari keluaran gate, bukan dari kira-kira.
- **Pemeriksa yang tidak menahan**: `cek-angka-dokumen.js` dan
  `cek-cakupan-kontrak.js` dulu **selalu keluar 0**; sekarang `process.exitCode`.
  Yang kedua kini juga memeriksa baris **Total** §9 dan menganggap
  `/webhook/midtrans` tercatat lewat §8.0.1. Kontrak §8 = 11 endpoint, §9 = 86.

## 5. Cara memeriksa keadaan

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1    # SEMUA LULUS
node docs/uji/cek-angka-dokumen.js                     # SEMUA ANGKA COCOK
git status --short                                     # bersih (kecuali .png)
```

Tanpa uji browser: `-Cepat`. Di Linux/CI: `./periksa.sh`; `uji.yml` memeriksa
daftar skrip di keduanya identik. Bila mengubah `web/src`: `npm run build`,
`docker compose build web`, lalu `up -d web` — kode dibakar ke image.

`cek-angka-dokumen.js` tidak tahu jumlah tes, asersi, dan langkah E2E; ketiganya
hanya dari keluaran `phpunit`/skrip, jadi angka §1 diubah **sesudah** gate.

## 6. Jebakan yang menggigit di sesi ini

1. **Pemeriksa dokumen bisa hijau karena kode keluarnya salah.** `periksa.ps1`
   menilai `$LASTEXITCODE`; skrip Node tanpa `process.exitCode` selalu lulus —
   rusak satu angka dulu untuk membuktikan pemeriksa baru benar-benar merah.
2. **Potret rute bukan sumber kebenaran.** `rute-api.json` hanya sah dibaca
   setelah disegarkan dari `route:list`; gate dan CI melakukannya sendiri.
3. **Menulis potret lewat `>` di PowerShell menghasilkan UTF-16.** Pakai
   `[IO.File]::WriteAllText(..., UTF8Encoding($false))`.
4. **Hitungan statis `lapor(` ≠ langkah sebenarnya** (610 vs 792): sebagian
   bersyarat. Hanya keluaran `langkah lulus` yang sah.
5. **Angka per tiket di `docs/tiket.md` dan tabel riwayat `audit-ketertelusuran.md`
   adalah potret saat tiket selesai**, bukan keadaan sekarang. Yang harus cocok
   dengan gate: baris R-01..R-07 dan §4.2 katalog.
6. **`docs/uji/*.png` ditulis ulang setiap E2E** — kembalikan yang tidak relevan
   (`git checkout -- docs/uji/*.png`); `print()` tidak bisa diasersikan dari CDP.
