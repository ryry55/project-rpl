# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: 30 Oktober 2026 (malam), sesudah penjaga DFD masuk gate,
dua tes pengganti "selesai tanpa tes", dan skrip tiga-peran

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Fase 5.1 / 5.4 / 5.5** + **K-05 opsi C**, disetujui pemilik 29 Okt |
| Suite PHP | **812 lulus / 3807 asersi** (712 Feature / 100 Unit), 47 berkas; pint & eslint bersih |
| Migrasi / endpoint / tabel domain | **41** / **86** / **23** (total 32 di skema `public`) |
| Skrip uji | **30** `.mjs`: **29** (26 browser + 2 balapan + 1 jalur proxy) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **834 langkah**, gate penuh **517 detik** |
| Gate lokal | **SEMUA LULUS** (30 Okt: 812 tes, pint, eslint, 4 pemeriksa dokumen, 29 skrip) |

Catatan: `POST /csp-laporan` **tidak** masuk hitungan 86 endpoint - ia di luar
`/api/` dan sengaja tidak masuk kontrak. `route:list --path=api` tetap 86.

## 2. Tiga aksi berikutnya

**Tidak ada pekerjaan deploy.** Pemilik membatalkan deploy 29 Oktober 2026:
penilaian dan demo dari laptop lewat `docker compose`; `9_DEPLOY.md`,
`Dockerfile.prod`, dan `deploy.yml` tinggal sebagai bukti kerja, tidak dipakai.

1. **Gladi demo** menurut `docs/demo-sidang.md`, dengan database segar
   (`migrate:fresh --seed`). Siapkan jawaban jujur untuk "sudah dipakai
   penghuni sungguhan?" — belum, demo lokal.
2. **Jaga gate tetap hijau.** Setiap perubahan kode lewat `periksa.ps1`; angka di
   dokumen hanya diubah sesudah gate, karena jumlah tes dan langkah E2E hanya
   diketahui dari keluarannya.
3. **Menunggu keputusan pemilik** (jangan dikerjakan sendiri): perbaikan
   REV-UI-01 (401 di tengah pemakaian belum mengeluarkan sesi — perilaku yang
   dilihat pengguna), kendali halaman K-05 opsi A di `TagihanView.vue`, Vitest
   (dependensi baru), skrip uji beban beserta ambangnya, dan anotasi
   `docs/revisi.md` (berkas input audit, gitignored).

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
`GET /api/tagihan?per_page=1..200` dihormati hanya bila dikirim, `page` mengikuti
paginator. Tanpa `per_page` respons **persis** seperti sebelumnya; di luar batas → 422.

**Keputusan 28 Oktober (pilihan A semua).** K-01: Midtrans mengirim `item_details`.
K-02: `SPEC.md` disunting faktual saja. K-03: `GET /api/penghuni/kontrak` masuk
UC-13. K-04: gate dan CI menyegarkan `rute-api.json` dari `route:list`.

**Dua keputusan lama yang tetap berlaku.** **`occupancy_rate` = hari-kamar**.
**Kuitansi tanpa nomor urut** — penandanya id pembayaran + periode.

**Bahasa visual: "buku besar tanpa warna".** Arah "kartu indeks katalog"
**ditolak pemilik**. Satu tombol hampir hitam per layar; `nav a`, `.tabel`,
`.lencana-status`, `.aksi-kepala`, `.merek p`, `.kartu-kelompok`, `.kendali`
tetap ada. **Narasi tetap 4 halaman**; **harga Charter Rp 50.000.000**.

## 4. Yang ditutup sesi ini

| Hal | Hasil |
|---|---|
| 5.1 navigasi bawah ponsel | `TataLetakPemilik.vue`: tiga tujuan + tombol Menu, hanya di bawah 900px, label dari larik `menu` yang sama. Diuji `uji-tata-letak.mjs` 360/390/768px |
| 5.4 pesan validasi Indonesia | `api/lang/id/validation.php`, `APP_LOCALE=id`/`APP_FALLBACK_LOCALE=id`. Dijaga `DataIndukTest::test_pesan_validasi_bawaan_berbahasa_indonesia` |
| 5.5 antrean email | `App\Jobs\KirimPemberitahuan`, `[program:antrean]`, produksi `QUEUE_CONNECTION=database`. Dijaga `NotifikasiTest::test_email_notifikasi_masuk_antrean` (`Queue::fake()`) |
| Rotasi log | `.env.example` `LOG_STACK=daily` (14 hari). Produksi tetap `stderr` |
| `supervisorctl` | `[unix_http_server]` + `[rpcinterface:supervisor]` ditambahkan; sebelumnya perintah pemulihan di `8_OPERATIONS_SOP.md` §2.4 **tidak bisa dijalankan** |
| Batas waktu per skrip | `JalankanBerbatas` memakai `ProcessStartInfo` (bukan `Start-Process -PassThru`, yang `ExitCode`-nya kosong di PS 5.1). 240 detik; `KOS_BATAS_SKRIP` untuk mengujinya. Dibuktikan: menggantung dipotong di 2 dtk → GAGAL, normal kode 0, gagal kode 4 |
| Status 36 temuan audit | `docs/status-revisi.md`: 32 selesai, **0 selesai tanpa tes**, 3 tidak berlaku, **1 masih terbuka (REV-UI-01)** |
| Penjaga DFD masuk gate | `docs/uji/cek-dfd.js` dijalankan `periksa.ps1`, `periksa.sh`, `uji.yml`: semantik bersih + 4 SVG identik dengan hasil penggambaran data. Dulu `periksaSemantik()` tidak pernah dipanggil gerbang |
| Uji tiga-peran | `docs/uji/uji-tiga-peran.mjs` (28 langkah): ketiga peran bergantian dalam satu sesi; batas dibuktikan di server, 403 vs 404 dibedakan |
| Dua tes pengganti "selesai tanpa tes" | `PengelolaTest` (REV-SEC-02, pasangan tolak-pengelola / izinkan-pemilik) dan `DendaHttpTest` (REV-DFD-05: denda & umur terhitung pada tagihan tanpa pembayaran) |

**Kontra-uji kelima penjaga:** `per_page` dicabut → 1 gagal/8 asersi;
`lang/id` disembunyikan → 1 gagal/2 asersi; `Pemberitahu` kembali ke
`Mail::send()` → `job was not pushed`; penjaga tarif dimatikan → pengelola 201
(bukan 422) sedangkan pemilik tetap 201; `->whereHas('pembayaran')` disuntikkan →
tagihan tanpa pembayaran hilang dari tunggakan. DFD: rute dihapus / `D1` jadi
black hole / SVG disentuh → ketiganya merah.

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
