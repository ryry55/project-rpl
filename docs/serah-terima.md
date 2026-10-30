# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: 30 Oktober 2026 (malam), sesudah lima keputusan pemilik
dieksekusi: REV-UI-01 ditutup, kendali halaman, Vitest, dan skrip uji beban

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Fase 5.1 / 5.4 / 5.5** + **K-05..K-11**, dijawab pemilik 29-30 Okt |
| Suite PHP | **812 lulus / 3807 asersi** (712 Feature / 100 Unit), 47 berkas; pint & eslint bersih |
| Suite Vitest | **19 tes** (2 berkas) untuk fungsi murni: format uang/tanggal, pemotong halaman |
| Migrasi / endpoint / tabel domain | **41** / **86** / **23** (total 32 di skema `public`) |
| Skrip uji | **31** `.mjs`: **30** (27 browser + 2 balapan + 1 jalur proxy) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **867 langkah**, gate penuh **479 detik** |
| Gate lokal | **SEMUA LULUS** (30 Okt: 812 tes, 19 Vitest, pint, eslint, 4 pemeriksa dokumen, 30 skrip) |

Catatan: `POST /csp-laporan` di luar `/api/`, sengaja tidak masuk hitungan 86 endpoint.

## 2. Tiga aksi berikutnya

**Tidak ada pekerjaan deploy.** Pemilik membatalkan deploy 29 Oktober 2026:
penilaian dan demo dari laptop lewat `docker compose`; `9_DEPLOY.md`,
`Dockerfile.prod`, dan `deploy.yml` tinggal sebagai bukti kerja, tidak dipakai.

1. **Gladi demo** menurut `docs/demo-sidang.md` dengan database segar
   (`migrate:fresh --seed`); siapkan jawaban jujur untuk "sudah dipakai penghuni
   sungguhan?" — belum, demo lokal.
2. **Jaga gate tetap hijau.** Setiap perubahan kode lewat `periksa.ps1`; angka
   dokumen hanya diubah sesudah gate, karena jumlah tes dan langkah E2E hanya
   diketahui dari keluarannya.
3. **Uji beban manual sebelum demo** di laptop yang dipakai sidang:
   `node docs/beban/uji-beban.mjs` (50 bersamaan, 500 permintaan, p95 < 1.000 ms).
   Terakhir p95 840 ms — lulus tipis, jadi ukur lagi bila mesinnya berbeda.

**Tidak ada keputusan pemilik yang masih menunggu.** K-01..K-11 dijawab atau
ditutup; yang terakhir (30 Okt): REV-UI-01, kendali halaman, Vitest, ambang uji
beban, dan `docs/revisi.md` tidak disentuh.

Yang **tidak** dikerjakan dan tidak akan ditawarkan lagi: Render/Neon, Midtrans
sungguhan, pinger uptime, latihan restore, verifikasi domain Brevo, domain
`.my.id`, dan menegakkan CSP.

## 3. Yang tidak boleh diubah

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-07 | `SPEC.md` bagian 4 |
| Wewenang pengelola (8) dan Model A | `SPEC.md` bagian 5 |
| Konvensi T24-T29 | `AGENTS.md` |
| Sumber tunggal DFD / use case | `docs/dfd/dfd-data.js`, `docs/akademik/usecase-data.js` |
| Keputusan pemilik | `docs/keputusan-terbuka.md` (K-01..K-11, semuanya `dijawab` atau `ditutup`) |
| Langkah deploy manual | `docs/produk/9_DEPLOY.md` |

**K-05 dijawab 29 Okt (pilihan C, pemilik: "harus bisa diekspor"):**
`GET /api/tagihan?per_page=1..200` dihormati hanya bila dikirim; tanpa `per_page`
respons persis seperti sebelumnya, di luar batas → 422. **K-08 (30 Okt)**
menambah kendali halaman **di layar** (50 baris), bukan `per_page`, supaya angka
uang di kepala halaman tetap berarti seluruh tagihan.

**Keputusan 28 Oktober (pilihan A semua).** K-01: Midtrans mengirim `item_details`.
K-02: `SPEC.md` disunting faktual saja. K-03: `GET /api/penghuni/kontrak` masuk
UC-13. K-04: gate dan CI menyegarkan `rute-api.json` dari `route:list`.

**Keputusan lama tetap berlaku:** `occupancy_rate` = hari-kamar; kuitansi tanpa nomor urut (penandanya id pembayaran + periode).

**Bahasa visual: "buku besar tanpa warna".** Arah "kartu indeks katalog"
**ditolak pemilik**. Satu tombol hampir hitam per layar; `nav a`, `.tabel`,
`.lencana-status`, `.aksi-kepala`, `.merek p`, `.kartu-kelompok`, `.kendali`
tetap ada. **Narasi tetap 4 halaman**; **harga Charter Rp 50.000.000**.

## 4. Yang ditutup 30 Oktober 2026

| Hal | Hasil |
|---|---|
| Status 36 temuan audit | `docs/status-revisi.md`: **33 selesai, 0 selesai tanpa tes, 3 tidak berlaku, 0 terbuka**. REV-UI-01 yang terakhir ditutup |
| REV-UI-01 (401 di tengah pemakaian) | `sesiHabis()` di `web/src/sesi.js`: 401 selama token masih ada → sesi dibersihkan, muat ulang ke `/masuk?pesan=berakhir&lanjut=…`. Dijaga `e2e-t1.mjs` |
| Kendali halaman Tagihan (K-08) | `web/src/halaman.js` + `TagihanView.vue`: `daftar` tetap utuh (kepala halaman menjumlahkan semua), `tampil` dipotong 50 baris. Dijaga `e2e-t4.mjs` (52 → 50 + 2) |
| Vitest | `vitest` saja (tanpa jsdom): `format.test.js` + `halaman.test.js`, 19 tes, langkah tersendiri di `periksa.ps1`/`periksa.sh`/`uji.yml` |
| Uji beban (K-10) | `docs/beban/uji-beban.mjs`, **manual** (bukan gate): 50 bersamaan / 500 permintaan, gagal bila p95 > 1.000 ms. Terukur p95 840 ms, 77 rps, 0 galat |

**Kontra-uji, semuanya sudah dijalankan:** `per_page` dicabut → 1 gagal/8 asersi;
`lang/id` disembunyikan → 1 gagal/2 asersi; `Pemberitahu` kembali ke `Mail::send()`
→ `job was not pushed`; penjaga tarif dimatikan → pengelola 201 (bukan 422);
`->whereHas('pembayaran')` disuntikkan → tagihan tanpa pembayaran hilang dari
tunggakan; DFD (rute dihapus / `D1` black hole / SVG disentuh) → ketiganya merah;
penanganan 401 dimatikan → 4 asersi E2E merah; `:daftar="daftar"` → 2 asersi merah;
`potong()` selalu mulai baris 1 → 3 tes Vitest merah; `AMBANG_P95=1` → GAGAL.

**Putaran sebelumnya** (5.1, 5.4, 5.5, rotasi log, `supervisorctl`, batas waktu
per skrip, penjaga DFD, uji tiga-peran): lihat `docs/audit-dokumen-vs-fitur.md` §13-§14.

## 5. Cara memeriksa keadaan

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1    # SEMUA LULUS
node docs/uji/cek-angka-dokumen.js                     # SEMUA ANGKA COCOK
```

Tanpa uji browser: `-Cepat`. Di Linux/CI: `./periksa.sh`. Bila mengubah
`web/src`: `npm run build`, `docker compose build web`, lalu `up -d web` — kode
dibakar ke image. Angka §1 diubah **sesudah** gate.

## 6. Jebakan yang menggigit di sesi ini

1. **`Queue::fake()` wajib untuk menguji antrean.** Dengan `sync`, pekerjaan berjalan di dalam permintaan dan `Mail::assertSent` tetap lulus.
2. **`supervisord.conf` tanpa `[unix_http_server]` + `[rpcinterface:supervisor]` tidak bisa dikendalikan `supervisorctl`** ("does not include supervisorctl section").
3. **Komentar ber-backtick di dalam template literal memutus string** — juga di dalam `evaluasi()` skrip browser. Terjadi saat menulis `uji-tiga-peran.mjs`.
4. **Asumsi tentang aplikasi bisa salah, dan tes yang menguji asumsi itu merah pada perilaku yang benar.** Login tidak mencabut token lama (`MAKS_SESI = 3`), dan `POST /tagihan` butuh `kontrak_id`, bukan `periode`. Perbaiki skripnya, bukan aplikasinya.
5. **`Set-Content -Encoding UTF8` (PS 5.1) menulis BOM** dan mematikan berkas PHP; build gagal lalu tes menguji image **lama**.
6. **`node -e "..."` lewat PowerShell merusak tanda kutip** — tulis berkas `.js` dulu.
7. **Gate dulu menelan kegagalan `migrate:fresh`.** Container lain yang masih terkoneksi ke database `kos` membuat penyegaran gagal dan data skrip sebelumnya tertinggal. `SegarkanDatabase`/`segarkan` kini berhenti dengan pesan jelas; hapus container uji sebelum gate.
