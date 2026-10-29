# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: deploy dibatalkan pemilik (29 Oktober 2026), sesudah Fase 5
(5.1 navigasi bawah, 5.4 pesan Indonesia, 5.5 antrean email) + K-05 opsi C

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Fase 5.1 / 5.4 / 5.5** + **K-05 opsi C**, semuanya disetujui pemilik 29 Okt |
| Suite PHP | **809 lulus / 3790 asersi** (709 Feature / 100 Unit), 47 berkas; pint & eslint bersih |
| Migrasi / endpoint / tabel domain | **41** / **86** / **23** (total 32 di skema `public`) |
| Skrip uji | **29** `.mjs`: **28** (25 browser + 2 balapan + 1 jalur proxy) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **806 langkah**, gate penuh **434 detik** |
| Gate lokal | **SEMUA LULUS** (29 Okt: 809 tes, pint, eslint, angka dokumen, ERD/LRS, 28 skrip) |

Catatan: `POST /csp-laporan` **tidak** masuk hitungan 86 endpoint - ia di luar
`/api/` dan sengaja tidak masuk kontrak. `route:list --path=api` tetap 86.

## 2. Tiga aksi berikutnya

**Tidak ada pekerjaan deploy.** Pemilik membatalkan deploy 29 Oktober 2026:
penilaian dan demo cukup dari laptop lewat `docker compose`. `9_DEPLOY.md`,
`Dockerfile.prod`, dan `deploy.yml` tetap di repo sebagai bukti kerja, tidak
dipakai.

1. **Gladi demo** menurut `docs/demo-sidang.md`, dengan database segar
   (`migrate:fresh --seed`). Siapkan jawaban jujur untuk pertanyaan "sudah
   dipakai penghuni sungguhan?" — belum, demo lokal.
2. **Jaga gate tetap hijau.** Setiap perubahan kode lewat `periksa.ps1`; angka di
   dokumen hanya diubah sesudah gate, karena jumlah tes dan langkah E2E hanya
   diketahui dari keluarannya.
3. **Bila pemilik berubah pikiran soal deploy**, buka K-06 dulu (ia ditutup,
   bukan dijawab), lalu mulai dari Fase 1 di `10_RENCANA-PERBAIKAN.md`.

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
| Keputusan pemilik | `docs/keputusan-terbuka.md` (K-01..K-05 `dijawab`, K-06 `ditutup`) |
| Langkah deploy manual | `docs/produk/9_DEPLOY.md` |

**K-05 dijawab 29 Okt dengan pilihan C** (pemilik: "harus bisa diekspor"):
`GET /api/tagihan?per_page=1..200` dihormati hanya bila dikirim, `page`
mengikuti paginator. Tanpa `per_page` respons **persis** seperti sebelumnya -
layar Tagihan dan ekspor tidak berubah. Di luar batas → 422.

**Keputusan 28 Oktober (pilihan A semua).** K-01: Midtrans mengirim
`item_details`. K-02: `SPEC.md` disunting faktual saja. K-03:
`GET /api/penghuni/kontrak` masuk UC-13. K-04: gate dan CI menyegarkan
`rute-api.json` dari `route:list` sebelum memeriksa dokumen.

**Dua keputusan lama yang tetap berlaku.** **`occupancy_rate` = hari-kamar**.
**Kuitansi tanpa nomor urut** — penandanya id pembayaran + periode.

**Bahasa visual: "buku besar tanpa warna".** Arah "kartu indeks katalog"
**ditolak pemilik**. Satu tombol hampir hitam per layar; `nav a`, `.tabel`,
`.lencana-status`, `.aksi-kepala`, `.merek p`, `.kartu-kelompok`, `.kendali`
harus tetap ada. **Narasi tetap 4 halaman**; **harga Charter Rp 50.000.000**.

## 4. Yang ditutup sesi ini

| Hal | Hasil |
|---|---|
| 5.1 navigasi bawah ponsel | `TataLetakPemilik.vue`: tiga tujuan + tombol Menu, hanya muncul di bawah 900px, label diambil dari larik `menu` yang sama (~ `.bawah`). Diuji `uji-tata-letak.mjs` 360/390/768px |
| 5.4 pesan validasi Indonesia | `api/lang/id/validation.php` (kunci lengkap + peta nama kolom), `APP_LOCALE=id`/`APP_FALLBACK_LOCALE=id` di `.env.example`, baris env di `9_DEPLOY.md`. Dijaga `DataIndukTest::test_pesan_validasi_bawaan_berbahasa_indonesia` |
| 5.5 antrean email | `App\Jobs\KirimPemberitahuan` (satu per penerima), `Pemberitahu::kirimEmail`, `[program:antrean]`, produksi `QUEUE_CONNECTION=database`. Dijaga `NotifikasiTest::test_email_notifikasi_masuk_antrean` (`Queue::fake()`) |
| Rotasi log | `.env.example` `LOG_STACK=daily` (14 hari). Produksi tetap `stderr` - platform yang memutar |
| `supervisorctl` | `[unix_http_server]` + `[rpcinterface:supervisor]` ditambahkan; sebelumnya perintah pemulihan di `8_OPERATIONS_SOP.md` §2.4 **tidak bisa dijalankan** |

**Kontra-uji ketiga penjaga:** `per_page` dicabut → 1 gagal/8 asersi;
`lang/id` disembunyikan → 1 gagal/2 asersi; `Pemberitahu` kembali ke
`Mail::send()` → `The expected [KirimPemberitahuan] job was not pushed`.

**Bukti image produksi:** `supervisorctl status` → empat program RUNNING; satu
job di-dispatch → `jobs` 1 lalu **0 dalam 8 detik**, `failed_jobs` 0;
`supervisorctl restart antrean` berhasil.

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

1. **`Queue::fake()` wajib untuk menguji antrean.** Dengan `sync`, pekerjaan
   berjalan di dalam permintaan dan `Mail::assertSent` tetap lulus - antre dan
   sinkron terlihat sama. Tanpa `Queue::fake()`, tes antrean tidak menguji apa pun.
2. **`supervisord.conf` tanpa `[unix_http_server]` + `[rpcinterface:supervisor]`
   tidak bisa dikendalikan `supervisorctl`.** Tanpa `rpcinterface` jawabannya
   "did not recognize the supervisor namespace commands"; tanpa `unix_http_server`
   malah "does not include supervisorctl section".
3. **`php -l` menangkap `kirimSekarang` ganda** - nama metode baru harus dicek
   terhadap yang sudah ada sebelum menambah.
4. **`Set-Content -Encoding UTF8` (PowerShell 5.1) menulis BOM** dan mematikan
   berkas PHP; build gagal lalu tes menguji image **lama** (lihat `AGENTS.md`).
5. **`node -e "..."` lewat PowerShell merusak tanda kutip** — tulis berkas `.js`
   dulu. Terjadi lagi di sesi ini saat menyisipkan §13.
6. **Menambah berkas pengendali/kelas mengubah angka dokumen**; jalankan
   `cek-angka-dokumen.js` sebelum commit.
7. **Gate dulu menelan kegagalan `migrate:fresh`.** Satu container uji
   (`docker run` image produksi) yang masih terkoneksi ke database `kos` membuat
   penyegaran gagal, data skrip sebelumnya tertinggal, dan `e2e-t3` merah dengan
   "opsi kontrak tidak ada". Kini `SegarkanDatabase`/`segarkan` berhenti dengan
   pesan jelas; hapus container uji sebelum menjalankan gate.
