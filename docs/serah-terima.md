# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: Redesign UI/UX selesai — 28 Oktober 2026

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Redesign UI/UX** (arah "kartu indeks katalog", seluruh layar) |
| Berikutnya | **Fase 1: Render + Neon** (butuh akun pemilik), lalu Midtrans sandbox |
| Suite PHP | **792 lulus / 3654 asersi**, 43 berkas tes; pint & eslint bersih |
| Migrasi / endpoint / tabel domain | **41** / **85** / **23** (total 32 di skema `public`) |
| Skrip uji | **29** `.mjs`: 28 dijalankan gate + `_cdp.mjs` (pustaka bersama) |
| Gate lokal | **SEMUA LULUS (577 detik)** — 792 tes PHP + 28 skrip browser + balapan |

---

## 2. Tiga aksi berikutnya

1. **Fase 1: Render + Neon.** Butuh akun pemilik. `deploy.yml` masih mode
   peringatan (`siap=0`), jadi tidak menyentuh produksi sebelum pemilik mengisi
   `RENDER_DEPLOY_HOOK`, `APP_URL`, dan environment GitHub `staging`/`production`.
   Rincian: `docs/produk/9_DEPLOY.md`.
2. **Verifikasi Midtrans sandbox.** Butuh `MIDTRANS_SERVER_KEY` asli; tanpa itu hanya
   jalur simulasi yang terbukti.
3. **Jadwal A3 vs container tidur.** Render free tidur setelah 15 menit; penjadwal
   Laravel tidak bisa dibuktikan di laptop. Pilihannya cron eksternal atau plan
   berbayar. **Keputusan pemilik, jangan ditebak.**

---

## 3. Yang tidak boleh diubah

Jangan menyimpang dari ini tanpa keputusan pemilik.

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-07 | `SPEC.md` bagian 4 |
| Wewenang pengelola (8) dan Model A | `SPEC.md` bagian 5 |
| Konvensi T24-T29 | `AGENTS.md` |
| Sumber tunggal DFD / use case | `docs/dfd/dfd-data.js`, `docs/akademik/usecase-data.js` |
| Keputusan terbuka | `docs/keputusan-terbuka.md` |
| Langkah deploy manual | `docs/produk/9_DEPLOY.md` |

**Bahasa visual (redesign, 27-28 Okt 2026).** Arahnya "kartu indeks katalog":
tipografi sebagai pembawa watak, palet kertas, kerapatan tinggi, dan **satu**
gerakan khas - status sebagai **cap** (huruf besar, renggang, bingkai tipis),
bukan pil. Angka tetap lebih penting daripada hiasan. Garis kiri **2px menandai
pilihan** (nav aktif, pemisah butir), **3px menandai keadaan** (gerbang, piutang,
wewenang sensitif, kotak pembatalan). 16 token warna; satu nuansa baru diturunkan
dari tetangganya (`color-mix`), bukan token ke-17. Nama kelas dan markup **boleh
berubah**. Rincian: `DESIGN.md`, `PRODUCT.md`, `2_DESIGN_SYSTEM.md` §1.1.

**Narasi tetap 4 halaman, bukan 3.** Template dosen hanya punya 10 aliran dan 5
entitas; sistem ini 16 aliran, 12 entitas data, 7 aturan. Kedua PDF sudah
diekspor. **Harga Charter tetap Rp 50.000.000**, jadwal mengikuti pertemuan kuliah
mulai 8 September 2026.

---

## 4. Cacat yang sudah ditutup sesi ini

**Tujuh berkas rusak oleh skrip PowerShell, bukan oleh redesign.** Semua
dikembalikan ke HEAD, diterapkan ulang dengan `edit`, dan divalidasi UTF-8 sebelum
commit. **`kolom-aksi` hilang saat revert** sehingga tombol "Rincian" ikut
tergulir; dipasang ulang, lalu diukur 90 kombinasi, 0 cacat.

Sisanya keputusan desain: "Lupa kata sandi?" turun menjadi `.tautan-teks`;
petunjuk akun demo di balik `VITE_MODE_DEMO`; `impeccable detect` 0 temuan karena
tiga `broken-image` diperbaiki di sumbernya dan empat `side-tab` didaftarkan
beserta alasannya di `.impeccable/config.json`.

---

## 5. Cara memeriksa keadaan

Satu perintah memeriksa seluruh repo; urutannya menentukan (build image dulu,
`cache:clear` di samping `migrate:fresh`), jadi jangan menjalankan tes satu per
satu. Tanpa uji browser: `.\periksa.ps1 -Cepat`. Di Linux/CI `./periksa.sh`;
keduanya wajib memuat daftar `.mjs` yang identik dan `uji.yml` memeriksa itu.

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1
git status --short                                # harus bersih
docker compose exec -T api php artisan test       # 792 lulus / 3654 asersi
cd api; php vendor\bin\pint --test                # di HOST, bukan container
cd web; npx eslint src; npm run build             # bersih
```

Baca **`docs/demo-sidang.md`** sebelum sidang: urutan demo 5 menit dan jawaban atas
pertanyaan yang paling sering muncul, dengan angka yang sudah dijalankan.

---

## 6. Jebakan yang menggigit di sesi ini

Tercatat juga di `AGENTS.md`; diulang di sini karena mahal.

1. **PowerShell bukan alat pengganti teks** - `-replace` di dalam loop mengenai
   karakter, bukan string, tanpa galat. Pakai `edit` atau skrip Node yang
   menghitung kemunculan lebih dulu; `node -e` juga merusak tanda kutip.
2. **`docker compose exec api` menjalankan kode dari IMAGE.** Build image dulu
   setelah menyunting `api/` atau `web/`. **Pint dijalankan di host.**
3. **Pembatas percobaan masuk menyimpan hitungan di CACHE.** Uji login gagal yang
   disengaja membuat skrip berikutnya menerima 429 pada login yang benar;
   `periksa` sudah menjalankan `cache:clear`.
4. **Asersi bertanggal akan gagal sendiri.** Sematkan `X-Simulasi-Tanggal`, atau
   bandingkan dengan nilai dari API - jangan mengetik angka.
5. **Mojibake tidak terlihat di konsol.** Periksa byte dengan Node, atau lihat potret layarnya.
6. **`docs/uji/*.png` ditulis ulang setiap E2E.** Kembalikan yang tidak relevan
   (`git checkout -- docs/uji/*.png`); kalau berubah karena redesign, sebutkan di
   pesan commit.
