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
| Fase terakhir selesai | **Redesign UI/UX**, arah "buku besar tanpa warna", 25 layar |
| Berikutnya | **Fase 1: Render + Neon** (butuh akun pemilik), lalu Midtrans sandbox |
| Suite PHP | **792 lulus / 3654 asersi**, 43 berkas tes; pint & eslint bersih |
| Migrasi / endpoint / tabel domain | **41** / **85** / **23** (total 32 di skema `public`) |
| Skrip uji | **29** `.mjs`: 28 dijalankan gate + `_cdp.mjs` (pustaka bersama) |
| Gate lokal | **SEMUA LULUS (418 detik)** — 792 tes PHP + 28 skrip browser + balapan |

## 2. Tiga aksi berikutnya

1. **Fase 1: Render + Neon.** Butuh akun pemilik: `RENDER_DEPLOY_HOOK`, `APP_URL`,
   dan environment GitHub `staging`/`production`. `deploy.yml` masih mode
   peringatan (`siap=0`); rincian di `docs/produk/9_DEPLOY.md`.
2. **Verifikasi Midtrans sandbox.** Butuh `MIDTRANS_SERVER_KEY` asli; tanpa itu
   hanya jalur simulasi yang terbukti.
3. **Jadwal A3 vs container tidur.** Render free tidur setelah 15 menit, jadi
   penjadwal Laravel tidak bisa dibuktikan di laptop: cron eksternal atau plan
   berbayar. **Keputusan pemilik, jangan ditebak.**

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

**Bahasa visual (redesign, 28 Okt 2026).** Arah **"buku besar tanpa warna"**.
Arah pertama ("kartu indeks katalog": manila, biru tinta, cap karet) **ditolak
pemilik** — terlalu kuning dan kuno, birunya bersaing dengan angka — dan tidak
boleh dihidupkan lagi. Kerangka sepenuhnya tinta dan abu-abu, **satu** tombol
hampir hitam per layar, warna hanya untuk arti (hijau lunas, amber perlu
ditindak, merah terlambat, satu biru data), Geist dan Geist Mono di-host sendiri,
tanpa bayangan saat diam, kepala tabel dibaca sebagai kalimat. Nama kelas dan
markup boleh berubah, tetapi `nav a`, `.tabel`, `.lencana-status`, `.aksi-kepala`,
`.merek p`, `.kartu-kelompok`, `.kendali` dipakai skrip uji dan harus tetap ada.
Rincian yang mengikat: `DESIGN.md`, `docs/produk/2_DESIGN_SYSTEM.md`, `PRODUCT.md`.

**Narasi tetap 4 halaman, bukan 3.** Template dosen hanya punya 10 aliran dan 5
entitas; sistem ini 16 aliran, 12 entitas data, 7 aturan. Kedua PDF sudah
diekspor. **Harga Charter tetap Rp 50.000.000**, jadwal mengikuti pertemuan kuliah
mulai 8 September 2026.

## 4. Cacat yang ditutup sesi ini

- **Lonceng notifikasi dirender dua kali** (bilah atas + sidebar) sehingga punya
  dua penghitung belum-dibaca: menandai terbaca di panel yang satu tidak mengubah
  lencana yang lain. `e2e-t25` menangkapnya; kini satu instance lewat `v-if` dari
  `matchMedia` (`layarSempit`).
- **Dua asersi membandingkan kapitalisasi, bukan keadaan.** Label status kini
  kalimat ("Lunas", "Nonaktif"), jadi `e2e-t5` dan `e2e-t19` membandingkan tanpa
  membedakan huruf besar-kecil.
- **`periksa.ps1` mati di tengah gate bila sebuah skrip menulis ke stderr** —
  sisa uji tidak pernah dilaporkan, hasilnya terbaca seperti crash. Semua perintah
  luar kini lewat `Jalankan`.
- **Tangkapan layar `docs/uji/*.png` diperbarui seluruhnya dengan sengaja.**

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

Baca **`docs/demo-sidang.md`** sebelum sidang: urutan demo 5 menit dan jawaban
atas pertanyaan yang paling sering muncul, dengan angka yang sudah dijalankan.

## 6. Jebakan yang menggigit di sesi ini

Tercatat juga di `AGENTS.md`; diulang di sini karena mahal.

1. **PowerShell bukan alat pengganti teks** - `-replace` di dalam loop mengenai
   karakter, bukan string, tanpa galat. Pakai `edit`, atau skrip Node yang
   menghitung kemunculan lebih dulu; `node -e` juga merusak tanda kutip.
2. **`docker compose exec api` menjalankan kode dari IMAGE.** Build image dulu
   setelah menyunting `api/` atau `web/`. **Pint dijalankan di host.**
3. **Pembatas percobaan masuk menyimpan hitungan di CACHE.** Login gagal yang
   disengaja membuat skrip berikutnya menerima 429 pada login yang benar.
4. **Asersi bertanggal akan gagal sendiri.** Sematkan `X-Simulasi-Tanggal`, atau
   bandingkan dengan nilai dari API - jangan mengetik angka.
5. **Satu komponen yang dirender dua kali punya dua state.** Gejalanya menyesatkan
   (tampak seperti bug API); pilih lewat `v-if`, jangan sembunyikan dengan CSS.
6. **`node ... 2>&1` langsung di `periksa.ps1` mematikan gate**, bukan
   menggagalkannya. Selalu lewat `Jalankan`.
7. **Mojibake tidak terlihat di konsol.** Periksa byte dengan Node, atau lihat
   potret layarnya. `Get-Content`/`Set-Content` merusak em dash dan tanda kutip
   lengkung; tulis berkas lewat tool, jangan lewat PowerShell.
8. **`docs/uji/*.png` ditulis ulang setiap E2E.** Kembalikan yang tidak relevan
   (`git checkout -- docs/uji/*.png`), dan sebutkan di pesan commit bila
   perubahannya memang disengaja.
