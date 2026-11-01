# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: 1 November 2026, sesudah putaran audit dokumen terakhir —
tabel bentuk LRS diukur ulang seluruhnya dan angka milimeter kini dijaga pemeriksa,
bukan diketik.

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **T14 laporan kerusakan** (#13) + K-13 dijawab 31 Okt |
| Suite PHP | **830 lulus / 3883 asersi**, **48 berkas**; pint & eslint bersih |
| Suite Vitest | **19 tes** (2 berkas) untuk fungsi murni: format uang/tanggal, pemotong halaman |
| Migrasi / endpoint / tabel domain | **42** / **89** / **24** (total 33 di skema `public`) |
| Skema | **209 kolom, 29 kunci asing, 8 trigger** (tidak ada trigger baru) |
| Skrip uji | **31** `.mjs`: **30** (27 browser + 2 balapan + 1 jalur proxy) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **852** di 30 skrip; `e2e-t2` **26/26**, delapan di antaranya T14 |
| Ticket GitHub | **#11, #12, #13, #14 tertutup.** Tidak ada issue terbuka |
| ERD/LRS | `cek-erd-lrs.js` **LULUS**: 24 tabel / 209 kolom / 29 kunci asing; L3 terbaca 2,74 mm di A3 |

Catatan: `POST /csp-laporan` di luar `/api/`, sengaja tidak masuk hitungan 89 endpoint.

## 2. Dua aksi berikutnya

**Tidak ada pekerjaan deploy.** Pemilik membatalkan deploy 29 Oktober 2026: demo dari laptop
lewat `docker compose`; `9_DEPLOY.md`, `Dockerfile.prod`, `deploy.yml` tinggal sebagai bukti.

**Rinciannya, beserta tiga pertanyaan yang akan ditanya penguji dan urutan H-1:
`docs/fase-akhir.md`.**

1. **Gladi demo bertiga, di layar** — tiga kali, dihitung waktunya
   (`docs/demo-sidang.md`). Ini satu-satunya pekerjaan yang tersisa.
2. **Fase 6 (laporan) menunggu format dosen**; bila datang, hanya `docs/akademik/*` yang dibuat.

**Tidak ada keputusan pemilik yang masih menunggu.** K-01..K-13 dijawab atau
ditutup. Yang terakhir (31 Okt, **K-13**): tabel tersendiri, kamar `perbaikan`
tetap ditagih penuh, pemilik **dan** pengelola boleh menutup laporan, dan biaya
perbaikan tidak pernah masuk tagihan penghuni.

Tidak dikerjakan dan tidak akan ditawarkan lagi: Render/Neon, Midtrans sungguhan, pinger uptime,
latihan restore, verifikasi domain Brevo, domain `.my.id`, dan menegakkan CSP.

## 3. Yang tidak boleh diubah

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-07 | `SPEC.md` bagian 4 |
| Wewenang pengelola (8) dan Model A | `SPEC.md` bagian 5 |
| Konvensi T24-T29 | `AGENTS.md` |
| Sumber tunggal DFD / use case | `docs/dfd/dfd-data.js`, `docs/akademik/usecase-data.js` |
| Keputusan pemilik | `docs/keputusan-terbuka.md` (K-01..K-13) |
| Langkah deploy manual | `docs/produk/9_DEPLOY.md` |

**Keputusan lama tetap berlaku:** `occupancy_rate` = hari-kamar; kuitansi tanpa
nomor urut (penandanya id pembayaran + periode); **deposit dihapus seluruhnya**.

**Bahasa visual: "buku besar tanpa warna".** Arah "kartu indeks katalog"
**ditolak pemilik**. Satu tombol hampir hitam per layar; `nav a`, `.tabel`,
`.lencana-status`, `.aksi-kepala`, `.merek p`, `.kartu-kelompok`, `.kendali`
tetap ada. **Narasi tetap 4 halaman**; **harga Charter Rp 50.000.000**.

## 4. Yang dibangun di T14

| Hal | Hasil |
|---|---|
| Tabel | `laporan_kerusakan` (8 kolom): `kamar_id`, `deskripsi`, `dilaporkan_oleh`, `selesai_pada`, `ditutup_oleh`. Indeks unik **parsial** `laporan_kerusakan_satu_terbuka_per_kamar` |
| Status kamar | CHECK `kamar.status_huni` kini menerima `perbaikan` |
| Endpoint | `GET /api/laporan-kerusakan`, `POST /api/laporan-kerusakan`, `POST /api/laporan-kerusakan/{laporan}/selesai` |
| Pagar di jalur lain | Kontrak baru pada kamar `perbaikan` → 422; `akhiri` kontrak **tidak** mengosongkan kamar yang diperbaiki; `MulaiKontrak` tetap `perbaikan`; ubah `status_huni` manual ditolak selama laporan terbuka |
| Jejak audit | `kerusakan.lapor` / `kerusakan.selesai`, menempel pada entitas `kamar` |
| Layar | `KamarView.vue`: panel "Laporkan kerusakan", tombol "Laporkan rusak" / "Selesai diperbaiki", `.status-perbaikan`, hitungan "diperbaiki" terpisah dari "kosong" |

**Kontra-uji T14:** `KerusakanTest` menembak indeks unik parsial **langsung ke
basis data** (tanpa HTTP) dan mengharapkan `QueryException` — pemeriksaan
`exists()` di controller punya celah balapan, dan hanya indeks itu yang menutupnya.

## 5. Cara memeriksa keadaan

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1    # SEMUA LULUS
node docs/uji/cek-angka-dokumen.js                     # SEMUA ANGKA COCOK
```

Tanpa uji browser: `-Cepat`. Di Linux/CI: `./periksa.sh`. Bila mengubah
`web/src`: `npm run build`, `docker compose build web`, lalu `up -d web` — kode
dibakar ke image. Angka §1 diubah **sesudah** gate.

Membangun ulang dokumen: `docs/erd/README.md` §"Urutan membangun", `docs/akademik/README.md`.

## 6. Jebakan yang menggigit di sesi ini

1. **Menambah tabel membuat 4 garis LRS berimpit tanpa ada yang merah.**
   `build-tingkat.js` mencetak `LULUS`, tetapi `cek-erd-lrs.js` menemukan empat
   pasang garis berjarak **1,2 px** — terbaca sebagai satu garis di kertas.
   Sebabnya `panjangBerimpit()` di `papan.js` memakai toleransi 0,5 px sedangkan
   pemeriksa memakai 3 px. Sudah disamakan; jangan kembalikan ke 0,5.
2. **Angka yang diketik tangan akan tertinggal.** `bab4-data.js` sempat menulis
   "23 tabel / 201 kolom"; kini memakai `${jumlahKolom}`/`${SKEMA.jumlah_tabel}`.
   Tinggi huruf LRS (2,74 dan 1,94 mm) juga pernah diketik di empat berkas dan
   tertinggal dari hasil ukur. Kini diukur `build-tingkat.js`, disimpan di
   `manifest.json`, dan dibandingkan `cek-angka-dokumen.js`.
3. **Gate memanggang kode ke image.** `php artisan test` tanpa
   `docker compose build api` menguji kode **lama** dan memberi hijau yang tidak
   menguji apa pun. Berlaku juga untuk `web`.
4. **`Queue::fake()` wajib untuk menguji antrean.** Dengan `sync`, pekerjaan
   berjalan di dalam permintaan dan `Mail::assertSent` tetap lulus.
5. **Komentar ber-backtick memutus template literal** (termasuk di dalam
   `evaluasi()`); `Set-Content -Encoding UTF8` (PS 5.1) menulis BOM dan mematikan
   berkas PHP. Jalankan `node --check`, sunting PHP dengan tool `edit`.
6. **Menutup tab terakhir mematikan Chrome**, bukan hanya tabnya — dan `fetch
   failed` di E2E terbaca seperti aplikasi mati. Bila CDP perlu hidup lagi:
   `chrome --headless=new --remote-debugging-port=9222 --user-data-dir=<temp>`.
