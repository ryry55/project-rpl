# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: Audit janji-dokumen vs aplikasi, enam temuan ditutup — 28 Oktober 2026

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Audit janji dokumen**: enam temuan diperbaiki (lihat §4) |
| Berikutnya | **Fase 1: Render + Neon** (butuh akun pemilik), lalu Midtrans sandbox |
| Suite PHP | **799 lulus / 3674 asersi**, 45 berkas tes; pint & eslint bersih |
| Migrasi / endpoint / tabel domain | **41** / **85** / **23** (total 32 di skema `public`) |
| Skrip uji | **29** `.mjs`: **28** (26 browser + 2 balapan) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **792 langkah** dalam **416 detik** |
| Gate lokal | **SEMUA LULUS** — 799 tes PHP + 28 skrip + jalur proxy |

## 2. Tiga aksi berikutnya

1. **Fase 1: Render + Neon.** Butuh akun pemilik: `RENDER_DEPLOY_HOOK`, `APP_URL`,
   dan environment GitHub `staging`/`production`. `deploy.yml` masih mode
   peringatan (`siap=0`); rincian di `docs/produk/9_DEPLOY.md`.
2. **Verifikasi Midtrans sandbox.** Butuh `MIDTRANS_SERVER_KEY` asli; tanpa itu
   hanya jalur simulasi yang terbukti. `POST /tagihan/{id}/bayar-daring` juga
   masih mengirim tanpa `item_details` (O-8 `deskripsi_tagihan` belum dipakai).
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

**Dua keputusan baru (28 Okt 2026), berlaku sejak sekarang.**

- **`occupancy_rate` = hari-kamar**: `hari_terisi / hari_tersedia`, kedua ujung
  tanggal inklusif, kontrak yang belum ditutup dihitung sampai `sampai`.
  Bukan "jumlah kamar terisi hari ini" dan bukan "rata-rata akhir bulan".
- **Kuitansi tanpa nomor urut.** Penandanya id pembayaran + periode tagihan.
  Boleh dicetak **pemilik, pengelola, dan penghuni**. Baris pembalik tidak
  punya kuitansi. Tidak ada kolom baru di skema.

**Bahasa visual (redesign, 28 Okt 2026).** Arah **"buku besar tanpa warna"**.
Arah pertama ("kartu indeks katalog": manila, biru tinta, cap karet) **ditolak
pemilik** dan tidak boleh dihidupkan lagi. Satu tombol hampir hitam per layar,
warna hanya untuk arti, tanpa bayangan saat diam. `nav a`, `.tabel`,
`.lencana-status`, `.aksi-kepala`, `.merek p`, `.kartu-kelompok`, `.kendali`
dipakai skrip uji dan harus tetap ada. Rincian: `DESIGN.md`,
`docs/produk/2_DESIGN_SYSTEM.md`.

**Narasi tetap 4 halaman, bukan 3.** Template dosen hanya punya 10 aliran dan 5
entitas; sistem ini 16 aliran, 12 entitas data, 7 aturan. **Harga Charter tetap
Rp 50.000.000**, jadwal mengikuti pertemuan kuliah mulai 8 September 2026.

## 4. Yang ditutup sesi ini

- **UC-04 "komponen biaya" akhirnya punya layar.** Panel "Tarif & komponen kamar N"
  di `KamarView.vue`: tabel komponen yang menagih bulan ini + formulir tambah,
  dengan pilihan "berlaku untuk semua kamar". Dua teks bantuan yang dulu menunjuk
  halaman Tagihan kini menunjuk panel ini.
- **Cacat 500 laten**: `satuan` null ditolak basis data (`NOT NULL`), bukan 422.
  Diperbaiki sekali di `PenerapPerubahan::komponen()`; ada tes regresinya.
- **O-6 hunian**: `RekapPendapatan::hunian()` + panel Hunian di halaman Rekap.
- **O-1 kontrak**: `GET /penghuni/kontrak` + satu baris di portal penghuni.
- **O-5 kuitansi**: `web/src/kuitansi.js`, tombol di panel rincian tagihan dan
  portal penghuni; dicetak peramban (tanpa PDF di server).
- **Angka dokumen** disegarkan dan `docs/uji/cek-angka-dokumen.js` diperluas dari
  3 berkas menjadi 10, memeriksa **setiap** kemunculan, bukan yang pertama.

## 5. Cara memeriksa keadaan

Satu perintah memeriksa seluruh repo; urutannya menentukan (build image dulu,
`cache:clear` di samping `migrate:fresh`), jadi jangan menjalankan tes satu per
satu. Tanpa uji browser: `.\periksa.ps1 -Cepat`. Di Linux/CI `./periksa.sh`;
keduanya wajib memuat daftar `.mjs` yang identik dan `uji.yml` memeriksa itu.

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1    # SEMUA LULUS
node docs/uji/cek-angka-dokumen.js                     # SEMUA ANGKA COCOK
git status --short                                     # harus bersih
```

`cek-angka-dokumen.js` **tidak** memeriksa jumlah tes, asersi, dan langkah E2E:
ketiganya hanya diketahui dari keluaran `phpunit`/skrip, bukan dari bentuk berkas.
Ketiganya disegarkan manual sesudah gate selesai — itulah sebabnya angka di §1
dan di dokumen lain harus diubah **sesudah** tes terakhir ditulis.

Baca **`docs/demo-sidang.md`** sebelum sidang: urutan demo 5 menit dan jawaban
atas pertanyaan yang paling sering muncul, dengan angka yang sudah dijalankan.

## 6. Jebakan yang menggigit di sesi ini

Tercatat juga di `AGENTS.md`; diulang di sini karena mahal.

1. **Build image tanpa `up -d` = menguji kode lama.** "8 tes lulus" padahal
   seharusnya 9, tanpa galat apa pun.
2. **Kolom `nullable` di validasi bisa `NOT NULL` di basis data.** Null eksplisit
   mengalahkan `default`, hasilnya 500 bukan 422. Buang kuncinya sebelum `create()`.
3. **`print()` tidak bisa diasersikan dari CDP** (dialog cetak memblokir). Yang
   diuji adalah tombolnya ada; isi dokumen diperiksa di `web/src/kuitansi.js`
   lewat `htmlKuitansi()` yang diekspor.
4. **PowerShell bukan alat pengganti teks** — `-replace` di dalam loop mengenai
   karakter, bukan string, tanpa galat. Pakai `edit`, atau skrip Node yang
   menghitung kemunculan lebih dulu.
5. **Asersi bertanggal akan gagal sendiri.** Sematkan `X-Simulasi-Tanggal`, atau
   bandingkan dengan nilai dari API — jangan mengetik angka.
6. **Satu komponen yang dirender dua kali punya dua state.** Pilih lewat `v-if`,
   jangan sembunyikan dengan CSS.
7. **`docs/uji/*.png` ditulis ulang setiap E2E.** Kembalikan yang tidak relevan
   (`git checkout -- docs/uji/*.png`), dan sebutkan di pesan commit bila
   perubahannya memang disengaja.
