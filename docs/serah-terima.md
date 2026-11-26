# Serah-terima antar sesi

> **Ditimpa setiap fase selesai.** Isinya: apa yang barusan dikerjakan, tiga aksi
> berikutnya, dan apa yang tidak boleh diubah. Riwayatnya di `git log`.
> **Maksimal 120 baris** — lebih dari itu ia menjadi `SPEC.md` kedua.

Terakhir diperbarui: 26 November 2026, sesudah cron, region, dan perbaikan CI.

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Sesi terakhir | **Deploy Vercel, cron, region sin1, perbaikan hitungan endpoint** (26 Nov 2026) |
| Rencana berikutnya | **Email Gmail SMTP** + isi sampul proposal + tabel blackbox; tidak ada fase kode lagi |
| Suite PHP | **931 lulus / 4188 asersi**; pint & eslint bersih; 19 vitest |
| Gate penuh | **SEMUA LULUS** 22 Nov (692 detik, 34 skrip / 942 langkah browser) |
| Keputusan pemilik | **K-19, K-20 dijawab & diterapkan**; K-01..K-18 tidak berubah |
| Tunnel demo | **hidup** 26 Nov — URL di `.env` (cepat berganti; §6) |
| **Deploy live** | **`https://kos-kosan-rosy.vercel.app`** — region **sin1**, cron tagihan aktif |
| **Proposal RPL** | `dokumen/11-proposal/Proposal_RPL_Huni.docx` — 33 hlm, model **Waterfall**, sampul placeholder |
| Paket serahan | **`dokumen/`** — segarkan dengan `dokumen\segarkan.ps1` |
| CI GitHub | **hijau** pada `1f0a7e6` (run `45770705344`, 11m51s, 931 tes + 34 skrip) |
| Working tree | Bersih sesudah commit sesi ini |

## 2. Yang sesi ini kerjakan (22 Nov 2026)

Fase 9-10 selesai dan ter-commit (`4850f6b`); rincian per temuan di
`docs/rencana-fase-8-10.md`, `docs/fase-akhir.md`, dan `git log`. Intinya:
N+1 penjumlahan hilang (`withSum` di tunggakan + rekap), `per_page` opsional di
`GET /pembayaran`, `now()` → `WaktuSistem` di enam titik tulis, composable
`useMuatDaftar` (satu view dimigrasikan), kode mati dibuang, tiga port demo ke
`127.0.0.1`, dan `X-Powered-By` disembunyikan + dipagari di
`periksa-profil-produksi.sh`.

Suite **918 → 931 tes / 4142 → 4188 asersi**. Gate penuh 22 Nov: SEMUA LULUS,
692 detik, 34 skrip / **942 langkah**.

**Keputusan pemilik sesudah Fase 9-10**

8. **`pm.max_children` demo = 8, `pm = static`** (`api/docker/php/zz-fpm.conf`);
   uji beban 50 bersamaan: p95 707 ms dan 721 ms (77 permintaan/detik).
9. **Diagram urutan TIDAK masuk Bab 4 PDF** — Bab 4 adalah laporan Sistem
   Basis Data; 14 diagram tetap diserahkan terpisah (`docs/akademik/README.md`).

**Deploy Vercel (25 November 2026)** — `https://kos-kosan-rosy.vercel.app`,
Container Runtime (image `Dockerfile.vercel` apa adanya) + Postgres Supabase.
Sandi pemilik live: `%TEMP%\opencode\sandi-pemilik-live.txt`. Tidak ikut:
simulasi tanggal (`APP_DEMO_MODE` ditolak entrypoint) dan email sungguhan
(`MAIL_MAILER=log`). Empat jebakannya di `docs/vercel.md`; yang terpenting:
**`api/vendor` wajib di `.vercelignore`**, tanpa itu Vercel menolak
"12 Serverless Functions" walau memakai Container.

**Cron + region (26 November 2026)** — `GET /api/cron/tagihan` dijaga
`CRON_SECRET`, dipanggil Vercel Cron 00:10 WIB; memakai `PenerbitanHarian`,
kelas yang sama dengan penjadwal container. Deployment pertama berjalan di
`iad1` (Virginia) sementara basis data di Singapura: p50 8.282 ms, 1
permintaan/detik. Sesudah `"regions": ["sin1"]`: p95 **225 ms**, 31
permintaan/detik, 0 galat. Pada 50 bersamaan: p95 1.019 ms + 28 galat 500
(batas pooler Supabase). Angkanya di `docs/fase-akhir.md` §2.

**Proposal RPL (25 November 2026)** — `dokumen/11-proposal/Proposal_RPL_Huni.docx`,
33 halaman, model **Waterfall** (wajib mata kuliah). Isinya dari repo: 28 FR,
12 NFR, 9 aturan bisnis, 14 use case, dan 18 gambar. Tiga diagram baru dibuat
(activity ×3, arsitektur, waterfall) dan dijaga pemeriksa tata letak. Sampul
masih placeholder; halaman diagram memakai landscape agar huruf ≥ 6 pt.

## 3. Tiga aksi berikutnya

1. **Pasang email Gmail SMTP** sesudah App Password tersedia di
   `%TEMP%\opencode\google-smtp.txt`; uji aktivasi, reset, dan notifikasi.
2. **Isi sampul proposal** (kelompok, 4 nama + NIM, prodi), lalu bangun ulang
   DOCX + PDF. Angka di proposal sudah 931 tes / 4.188 asersi.
3. **Buat tabel pengujian blackbox** dari 9 sub-proses DFD level 2; sumber
   skripnya `docs/uji/` (34 skrip, 942 langkah), belum ada dokumen serahannya.
4. **Review charter** dan **rotasi sandi Supabase** sebelum sidang.

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
- **Gate berjalan sambil disunting, dan dipipa**: `Select-Object -Last N` membuang
  output (waktu/langkah tak bisa diverifikasi), dan menyunting skrip yang sedang
  dibaca bash membuatnya gagal di tengah baris. Sunting dulu, jalankan kemudian.
- **Klaim status di `dokumen/README.md` dibuktikan ke pemeriksa gate dulu**:
  "metode kelas menunggu sinkron" ternyata salah diagnosis — metode diagram
  kelas diturunkan otomatis dari model, dan kelas layanan domain memang tidak
  digambar (aturan `kelas-data.js`: tiap kelas wajib punya tabel di skema).
- **Hitung trigger dari basis data hidup, bukan dari teks migrasi**:
  `parameter_denda_append_only` ikut mati bersama tabelnya di `hapus_deposit`
  — migrasi memuat 9 nama, DB hidup punya 8. Query langsung
  `docker compose exec db psql -U kos -d kos -tAc "..."` (jangan lewat
  `sh -c` — baris hasilnya tertelan).
- **p95 mengukur antrean atau jarak, bukan kode**: demo 5 worker → p95 1064 ms;
  `dynamic` menanggung kelahiran worker (1510 ms), karena itu `static`. Di
  Vercel, container `iad1` + basis data Singapura → p50 8.282 ms; `sin1` → p95
  225 ms. Periksa jumlah worker DAN region sebelum menyimpulkan regresi.
- **`git stash` + build ulang = satu-satunya A/B yang sah**: kode dibakar ke
  image. Uji beban juga butuh data setara (390 tagihan lewat `PenerbitTagihan`),
  lalu `migrate:fresh --seed` + `isi-demo.mjs` sesudahnya.

## 6. Verifikasi klaim berkas ini

```powershell
docker compose exec api php artisan test        # 931 tes, 4188 asersi
node docs/uji/cek-angka-dokumen.js              # SEMUA ANGKA COCOK
node docs/uji/cek-erd-lrs.js                     # 26 tabel, 229 kolom
node docs/uji/cek-diagram.js                    # SVG = data, PNG = 2x SVG
bash docs/uji/periksa-profil-produksi.sh        # PROFIL PRODUKSI LULUS (25 baris)
docker compose ps                               # port hanya 127.0.0.1
curl -sI http://localhost:8081/api/health       # tanpa X-Powered-By
node docs/beban/uji-beban.mjs                      # 50 bersamaan: p95 707 ms, 0 galat
```

Tunnel demo (arahkan ke SPA; nginx SPA meneruskan `/api`):

```powershell
Get-Process cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force
& "$env:LOCALAPPDATA\cloudflared\cloudflared.exe" tunnel --url http://localhost:3000
# URL baru -> .env (APP_URL, APP_URL_SPA), lalu:
docker compose up -d api; docker compose exec api php artisan cache:clear
node "$env:TEMP\opencode\isi-demo.mjs" "<url-baru>"
```

Mailpit untuk email demo tetap di `http://localhost:8025` (tidak lewat tunnel).
