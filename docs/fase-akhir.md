# Fase akhir — yang tersisa, dan hanya Anda yang bisa mengerjakannya

Diperbarui 13 November 2026. Semua yang bisa diperiksa mesin sudah diperiksa:
927 tes PHP, 19 tes Vitest, 34 skrip / 942 langkah browser, `uji-aksesibilitas.mjs`
27/27 di laptop **dan** di CI, gladi demo 39/39 pada database segar, uji beban
lulus. Empat pagar baru dibuktikan bisa **merah** lebih dulu, bukan hanya hijau.

Yang tertinggal di bawah ini bukan pekerjaan kode. Tidak ada yang bisa
dikerjakan agen tanpa Anda: semuanya butuh manusia, waktu, atau mesin yang
berbeda.

---

## 1. Latihan mengucapkan, tiga kali

Skripnya `docs/demo-sidang.md`. Lima menit, dihitung dengan pengatur waktu.

- [ ] Latihan 1 — sendirian, boleh berhenti dan membaca ulang.
- [ ] Latihan 2 — bertiga, dengan pembagian peran yang sudah disepakati.
- [ ] Latihan 3 — bertiga, tanpa berhenti, dihitung waktunya.

Empat hal di skrip itu baru saja diperbaiki karena **tidak cocok dengan layar**
saat dijalankan sungguhan. Jangan mengucapkan versi lama:

| Yang salah diucapkan | Yang benar di layar |
|---|---|
| "tekan Terbitkan tagihan" saat kebijakan kosong | tombolnya **tidak ada**; yang tampil panel "Kebijakan kos belum lengkap" + tautan "Isi kebijakan dulu" |
| "alasan kosong ditolak 422" | yang menolak lebih dulu **peramban** (`required`); 422 ada di server dan dibuktikan `uji-pembalik.mjs` |
| "jatuh tempo 16 September 2026" | di layar tertulis **"16 Sep 2026"** |
| "statusnya kembali menunggak" | lencananya berbunyi huruf kecil **"belum bayar"** |

## 2. Uji beban di laptop yang dipakai sidang

```powershell
node docs/beban/uji-beban.mjs
```

Lulus bila `p95` di bawah 1.000 ms dan `galat: 0`. Tiga pengukuran di mesin ini:
**464 ms**, **840 ms**, dan **603 ms** (31 Oktober 2026, 103 permintaan/detik,
0 galat) — selisihnya besar karena keadaan mesin, jadi ukur ulang sebelum sidang.

- [x] Diukur 31 Oktober 2026: p95 603 ms, lulus.
- [x] Diukur 13 November 2026: p95 610 ms, 129 permintaan/detik, 0 galat.
- [x] **22 November 2026, 390 tagihan, 8 worker (`pm = static`): p95 707 ms
      (run pertama sesudah restart) dan 721 ms (ulangan), 77 permintaan/detik,
      0 galat — LULUS pada 50 bersamaan.**

Bila p95 melewati ambang: tutup Chrome dan tab lain lebih dulu, lalu ulangi.
Yang diukur adalah mesin yang sedang dipakai, bukan sifat aplikasi.

### Angka 22 November yang gagal — lalu diperbaiki

Pengukuran ulang sesudah Fase 9 memberi p95 **1064 ms** (lalu 1283 dan 1356 ms
pada dua ulangan), dan `galat: 0` di ketiganya. Diulang sekali lagi dari keadaan
bersih: **50 bersamaan 1081 ms (gagal), 5 bersamaan 128 ms (lulus)**. Sebelum
itu disalahkan pada kode, tiga pemeriksaan dilakukan:

| Pemeriksaan | Hasil | Artinya |
|---|---|---|
| Latensi serial (`BEBAN_BERSAMAAN=1`, 50 permintaan) | p50 **94 ms** | satu permintaan tetap cepat |
| Kode sebelum Fase 9 (HEAD `0b12032`), serial | p50 **96 ms** | perubahannya **tidak** memperlambat |
| Jumlah worker php-fpm di container demo | **5** (`www.conf`, bawaan image) | 500 × 94 ms ÷ 5 ≈ 9,4 detik — persis waktu yang terukur |
| Konkurensi disamakan dengan jumlah worker (5 bersamaan, 500 permintaan) | p50 **95–96 ms**, p95 **128–161 ms**, 48–51 permintaan/detik, 0 galat | **LULUS** — antrean hilang, aplikasinya sama |

Jadi p95 pada 50 bersamaan mengukur **antrean di depan lima worker**, bukan
kecepatan aplikasi. Atas keputusan pemilik, demo kemudian **disamakan dengan
profil produksi**: `pm.max_children = 8` di `api/docker/php/zz-fpm.conf`.

Dua percobaan pada 8 worker:

| Bentuk | Run pertama sesudah restart | Ulangan | Kesimpulan |
|---|---|---|---|
| `pm = dynamic` | p95 **1510 ms** (gagal) | 980 dan 704 ms | worker baru lahir saat beban datang; run pertama menanggungnya |
| `pm = static` | p95 **707 ms** (lulus) | 721 ms | 8 worker siap sejak start; 77 permintaan/detik, 0 galat |

Karena itu berkas demo memakai `pm = static`. Biayanya terukur: container
**12,6 MB** saat idle dengan 8 worker — opcache dan kode dibagi, bukan disalin
per worker.

Batas yang harus disebut bila ditanya: pada **lebih dari 8 bersamaan** angka
p95 kembali bercerita tentang panjang antrean, bukan kecepatan aplikasi. Beban
itu sintetis untuk satu kos berisi paling banyak 30 kamar.

**Cara mengukur ulang sebelum sidang:** `node docs/beban/uji-beban.mjs`
(bawaan 50 bersamaan). Untuk melihat latensi tanpa antrean, pakai
`BEBAN_BERSAMAAN=1`.

## 3. Tiga pertanyaan yang hampir pasti ditanya

Jawab apa adanya. Sistem ini demo lokal, dan itu bukan kelemahan yang perlu
disamarkan.

- **"Sudah dipakai penghuni sungguhan?"** — Belum. Yang ada: demo lokal dengan
  data contoh, 927 tes, dan uji browser yang menjalankan alurnya di peramban.
- **"Kalau servernya mati, datanya hilang?"** — Basis data PostgreSQL di dalam
  Docker, dan cadangannya sudah **diuji pulih**, bukan hanya dibuat:
  `docker compose exec -T api sh /var/www/html/docker/backup.sh --uji-pulih`
  memulihkan ke basis data sementara lalu menghapusnya lagi (kode keluar 0, tujuh
  tabel terhitung). Pemeriksaan 31 Oktober juga membandingkan isinya: dump data
  asli dan hasil pulih identik, dan kedelapan trigger ikut terbawa. Yang belum:
  menjalankan aplikasi terhadap data hasil pulih, dan pemulihan dari Neon (tidak
  ada deploy).
- **"Kenapa tidak online?"** — Deploy dibatalkan pemilik 29 Oktober 2026;
  penilaian dari laptop. Berkas deploy dan CI-nya tetap ada sebagai bukti kerja.

## 4. H-1, sekali saja

```powershell
docker compose up --build        # tunggu "[entrypoint] siap."
docker compose exec api php artisan migrate:fresh --seed
docker compose exec api php artisan cache:clear
```

Lalu pastikan tiga akun bisa masuk: `pemilik@kos.test`, `pengelola@kos.test`,
`penghuni@kos.test`, sandinya `password`. Jangan jalankan `migrate:fresh` lagi
sesudah gladi terakhir — data demo yang dipakai saat sidang harus yang terakhir
Anda lihat sendiri.

## 5. Membagikan lewat Cloudflare Tunnel (dibuktikan 15 November 2026)

Untuk kelompok yang jauh, atau saat sidang daring. Tunnel meneruskan ke
tumpukan **demo** di laptop, jadi `APP_DEMO_MODE` dan panel simulasi tanggal
tetap hidup — hal yang hilang bila memakai `Dockerfile.prod`.

```powershell
winget install --id Cloudflare.cloudflared -e   # atau unduh .exe, tanpa admin
cloudflared tunnel --url http://localhost:3000  # cetak https://xxx.trycloudflare.com
```

Lalu isi **dua** variabel di `.env` dengan URL itu — `APP_URL` saja tidak cukup,
karena tautan aktivasi dan lupa-sandi dibaca dari `APP_URL_SPA`:

```
APP_URL=https://xxx.trycloudflare.com
APP_URL_SPA=https://xxx.trycloudflare.com
```

`docker compose up -d api && docker compose restart api-web && docker compose exec api php artisan config:clear`

`APP_URL` tidak memengaruhi foto laporan kerusakan: `LampiranKerusakan::ringkas()`
membangun tautannya dengan `absolute: false`, jadi peramban memakai origin
halaman. Yang bergantung pada `URL_SPA` adalah **tautan di email** — aktivasi
akun, lupa sandi, dan notifikasi. Salah isi di situ berarti penghuni baru
menerima tautan ke alamat yang tidak bisa dibuka dari luar laptop.

Tujuh jebakan yang sudah menggigit, semuanya senyap:

- **URL berganti setiap tunnel dijalankan ulang.** Kalau lupa memperbarui
  `APP_URL_SPA`, email aktivasi menaut ke alamat mati dan akun baru tidak pernah
  bisa dipakai. `APP_URL` yang tertinggal juga membuat `config('cors')` di
  profil produksi menolak origin yang salah — di profil demo tidak berpengaruh
  karena satu origin.
- **Proses `cloudflared` yang masih hidup BUKAN bukti tunnel hidup.** 16
  November: koneksi QUIC ke tepi Cloudflare putus (jaringan sesaat), tunnel
  cepat dilepas, dan hostname menjadi NXDOMAIN — tetapi prosesnya terus
  jalan berjam-jam, mengulang-ulang "Tunnel not found" di log tanpa satu
  galat di layar. Gejalanya: kelompok mendapat "situs tidak dapat dijangkau"
  padahal `Get-Process cloudflared` masih ada. Periksa DNS, bukan prosesnya:
  `nslookup <host> 8.8.8.8`; NXDOMAIN = jalankan ulang kedua tunnel,
  perbarui `APP_URL`/`APP_URL_SPA`, lalu `up -d api` + `restart api-web` +
  `config:clear`. Berkasnya: `C:\Users\ACER\AppData\Local\cloudflared\`
  (tidak di PATH — `Start-Process cloudflared` gagal "file not found").
- **`APP_DEBUG=true` saat URL dibagikan.** Jawaban galat memuat jejak tumpukan:
  potongan kode, path berkas, nama kueri — semuanya terbaca siapa pun yang
  memegang URL. Sejak 16 November `docker-compose.yml` memakai
  `${APP_DEBUG:-false}`; nyalakan sementara lewat `.env` hanya saat menelusuri
  bug di laptop, lalu matikan lagi sebelum membagikan URL.
- **Laptop tidur = 502.** Cloudflare menjawab 502, bukan galat yang menjelaskan.
- **Batas login 20×/jam per email** (`PembatasMasuk`). Sepuluh orang bergantian
  memakai `pemilik@kos.test` akan kena 429 dengan sandi yang benar. Jalankan
  `docker compose exec api php artisan cache:clear` sebelum sesi, atau bagikan
  akun berbeda per orang.
- **`migrate:fresh --seed` tidak mengisi kamar, kontrak, atau tagihan** — seeder
  sengaja berhenti di kebijakan yang belum diisi. Tanpa data contoh, semua layar
  kosong dan terbaca seperti aplikasi rusak.
- **Tidak perlu membuka `api-web` (8081).** SPA memakai `VITE_API_BASE=/api`,
  jadi satu tunnel ke `:3000` melayani halaman dan API sekaligus.

Akun demo tetap `pemilik@kos.test` / `pengelola@kos.test` / `penghuni@kos.test`,
sandi `password`. Jangan pernah memakai data sungguhan di tumpukan ini: URL-nya
publik, dan siapa pun yang memegangnya bisa masuk dengan sandi itu.

Bila kelompok harus **mencoba membuat akun**, tambahkan tunnel kedua ke Mailpit
(`cloudflared tunnel --url http://localhost:8025`). Tanpa itu, email aktivasi
berhenti di kotak surat laptop dan penghuni baru tidak akan pernah bisa masuk -
layarnya akan menjawab "Akun ini belum diaktifkan". Alurnya sudah dibuktikan
15 November 2026: buat penghuni, buka tautan dari Mailpit, tetapkan sandi,
lalu masuk.

Mailpit tidak punya kata sandi. Siapa pun yang memegang URL-nya bisa membaca
seluruh email demo, termasuk tautan atur-sandi milik akun lain. Untuk kelas itu
dapat diterima; untuk apa pun yang menyentuh data sungguhan, jangan.

Batasnya: tunnel bukan deploy. Tidak ada penjadwal tagihan otomatis, dan semua
berhenti saat laptop dimatikan.

---

## Yang sengaja tidak dikerjakan

Sebutkan sebagai batas bila ditanya, jangan dijanjikan. Rinciannya di
`docs/utang-teknis.md` §6.

- Deploy ke hosting, Midtrans sungguhan, email Brevo sungguhan.
- Pemasangan PWA di ponsel asli, audit pembaca layar sungguhan (NVDA/VoiceOver).
- Penegakan CSP di peramban sungguhan.

**Fase 6 (laporan) masih menunggu format dari dosen.** Bila formatnya datang,
yang perlu dibuat hanya `docs/akademik/*` — berkas itu dibangkitkan dari data
yang sudah ada (`usecase-data.js`, `dfd-data.js`, hasil uji), bukan ditulis
ulang dari nol.

## T14 selesai — laporan kerusakan kamar

**#13 ditutup 31 Oktober 2026.** Kamar rusak kini punya tabelnya sendiri
(`laporan_kerusakan`) dan status `perbaikan`; kamar yang sedang diperbaiki tidak
muncul di pilihan kontrak, dan layar Kamar menjelaskan **apa** yang rusak di
bawah lencananya.

Empat keputusan pemilik yang membentuknya:

| Pertanyaan | Jawaban |
|---|---|
| Bentuk | tabel `laporan_kerusakan` tersendiri, bukan kolom `catatan` |
| Tagihan kamar `perbaikan` | **tetap ditagih penuh** — tidak ada rumus baru |
| Siapa menutup laporan | pemilik **dan** pengelola, lewat `kelola_kamar` |
| Biaya perbaikan | **tidak pernah** masuk tagihan penghuni; tabelnya tidak punya kolom nominal |

Angka skema karena itu berubah: **25 tabel, 217 kolom, 30 kunci asing, 33 tabel
`public`** (trigger tetap 8). Dokumen, ERD, LRS, dan narasi ikut dibangun ulang.

Tiga tiket lain ditutup 31 Oktober 2026 setelah diperiksa, bukan dibatalkan:
**#11** satuan sewa per kamar (mingguan/harian) ternyata sudah ada di
`Kalkulator` dan layar Kebijakan, **#12** mode jatuh tempo + saklar denda +
pembebasan denda sudah ada di `kebijakan_kos`, dan **#14** deposit tidak berlaku
karena deposit dihapus seluruhnya.
