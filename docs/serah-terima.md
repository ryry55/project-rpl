# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.
> Keputusan yang terkunci **dirujuk**, tidak disalin.

Terakhir diperbarui: LANGKAH 11 + T22 selesai - 26 Oktober 2026

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir yang selesai | **LANGKAH 11 (produksi) + T22 (Midtrans) + S1.9** |
| Berikutnya | **CI/CD**, lalu live - sesuai urutan pemilik |
| Suite PHP | **746 lulus / 3516 asersi**, 39 berkas tes |
| Pint / ESLint / build | bersih |
| Migrasi / endpoint / tabel domain | **39** / **80** / **23** (total 32 di skema `public`) |
| Skrip uji | **24** (22 browser + 2 balapan), **730 langkah**; `periksa.ps1` SEMUA LULUS |
| `periksa-profil-produksi.sh` | **22/22 LULUS**; working tree bersih (`revisi.md` di `.gitignore`) |

---

## 2. Tiga aksi berikutnya

1. **CI/CD.** `deploy.yml` masih mode peringatan (`siap=false`) karena target
   Render/Neon belum ada. CI E2E **menggantung 17+ menit di runner** sementara
   lulus ~390 detik di lokal - itu **utang teknis yang diterima**, jangan
   dikejar.
2. **Verifikasi Midtrans sandbox.** Butuh `MIDTRANS_SERVER_KEY` sungguhan. Kode
   dan 16 tesnya lulus dengan kredensial palsu; yang belum pernah terjadi adalah
   transaksi sungguhan ke sandbox.
3. **Baca `docs/akademik/README.md`** sebelum menyentuh apa pun di sana. Semua
   berkas akademik **dibangkitkan**; suntingan tangan hilang pada build
   berikutnya. Ubah `usecase-data.js` / `dokumen-data.js` / `dfd-data.js`, lalu
   `node docs/akademik/build.js && node docs/akademik/build-dokumen.js`.

---

## 3. Yang tidak boleh diubah

Jangan menyimpang dari ini tanpa keputusan pemilik.

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-07 | `SPEC.md` bagian 4 |
| Wewenang pengelola (8) dan Model A | `SPEC.md` bagian 5 |
| Konvensi T24-T29 | `AGENTS.md` |
| Sumber tunggal DFD | `docs/dfd/dfd-data.js` |
| Sumber tunggal use case | `docs/akademik/usecase-data.js` |
| Keputusan terbuka | `docs/keputusan-terbuka.md` |
| Langkah deploy manual | `docs/produk/9_DEPLOY.md` |

**Narasi tetap 4 halaman, bukan 3** - sudah diputuskan pemilik. Template dosen
hanya punya 10 aliran dan 5 entitas; sistem ini 14 aliran, 12 entitas, 7 aturan.
Diukur `ukur-halaman.ps1`: 4 halaman. Jangan "merapikannya" tanpa bertanya.
Kedua PDF sudah diekspor: narasi 4 halaman, diagram tepat 2 halaman.

**Harga Charter tetap Rp 50.000.000**, jadwal mengikuti pertemuan kuliah mulai
8 September 2026.

---

## 4. Cacat yang sudah ditutup sesi ini

**LANGKAH 11 - produksi satu layanan.** `Dockerfile.prod` tiga tahap (SPA di
dalam `public/` Laravel), nginx + php-fpm + penjadwal di bawah supervisord,
entrypoint dengan tiga pagar keras (`APP_DEBUG`, `APP_DEMO_MODE`, `APP_KEY`) yang
masing-masing **dibuktikan bisa gagal**. Diverifikasi lokal: `GET /` menyajikan
SPA, `/assets/*` immutable, `/sw.js` `no-store`, deep link `/tagihan` 200,
`/api/health` `database: ok`, dan login + `/api/me` + `/api/tagihan`
mengembalikan data sungguhan dari **satu origin**.

**T22 - pembayaran daring Midtrans.** Tabel `transaksi_gateway`,
`MidtransGateway` (SHA512 + `hash_equals`), webhook tanpa auth dengan tanda
tangan sebagai satu-satunya bukti, `POST /api/tagihan/{id}/bayar-daring` untuk
penghuni, dan tombol "Bayar daring". Tiga pengaman - tanda tangan, idempotensi,
pencocokan nominal - **dibuktikan bisa gagal** dengan menyuntikkan cacat.

**S1.9 - kontrak bertanggal masa depan.** Status `dipesan` untuk kontrak dan
kamar; `kontrak:mulai` mempromosikannya, pemesanan ganda ditolak indeks unik.

---

## 5. Cara memeriksa keadaan

```powershell
git status --short                              # harus bersih
docker compose exec -T api php artisan test      # 746 lulus / 3516 asersi
cd api; php vendor\bin\pint --test                # di HOST, bukan container
cd web; npx eslint src; npm run build             # bersih
bash docs/uji/periksa-profil-produksi.sh          # 22/22 LULUS
powershell -ExecutionPolicy Bypass -File periksa.ps1   # SEMUA LULUS
node docs/akademik/build.js                       # 80 endpoint terpetakan habis
node docs/akademik/build-dokumen.js               # 3 dokumen dibangkitkan
```

---

## 6. Jebakan yang menggigit di sesi ini

Tercatat juga di `AGENTS.md`; diulang di sini karena mahal.

1. **`docker compose exec api` menjalankan kode dari IMAGE, bukan dari disk.**
   Setelah menyunting `api/`, **build image dulu**, kalau tidak tes menjalankan
   versi lama. **Pint harus dijalankan di host** - di dalam container ia
   memformat salinan di container, bukan berkas di host.
2. **`git add -A` menelan berkas audit.** Keduanya kini di `.gitignore`.
3. **PowerShell `>` menulis UTF-16**, dan `JSON.parse` Node menolaknya. Pakai
   `[System.IO.File]::WriteAllText(..., UTF8Encoding($false))`.
4. **Mengetik angka di dokumen selalu tertinggal** - tiga kali di sesi ini.
   Generator akademik sekarang **menghitung migrasi dari direktori** dan
   endpoint/tabel dari snapshot JSON.
5. **PowerShell `-replace` dan heredoc rusak tanpa suara.** Tulis berkas `.py`.
6. **Chrome menolak `--print-to-pdf` dengan path relatif.** Pakai path absolut.
