# Utang teknis

Hal-hal yang diketahui kurang, disengaja ditunda, dan alasannya. Daftar ini ada
supaya penundaan tetap tercatat, bukan supaya terlupakan.

Status per LANGKAH 9 (commit `f2c73f0`).

---

## 1. Uji browser di CI menggantung — BELUM SELESAI

**Gejala.** Job `uji` pada GitHub Actions masuk ke langkah "Uji browser dan uji
balapan" lalu tidak selesai: 17+ menit, padahal di laptop langkah yang sama
memakan 257–283 detik. Job akhirnya berhenti karena `timeout-minutes: 30`, bukan
karena uji gagal.

**Yang sudah terbukti bekerja di CI:**

| Langkah | Status |
|---|---|
| pint, eslint, vite build | lulus |
| Paritas daftar E2E `periksa.ps1` vs `periksa.sh` | lulus |
| Profil produksi (`periksa-profil-produksi.sh`) | lulus |
| 713 tes PHP di dalam container | lulus |
| Chrome headless membuka port 9222 | lulus |
| `periksa.sh` benar-benar dijalankan (`bash periksa.sh`) | lulus |

Jadi pagar yang menegakkan kebenaran kode **sudah** berjalan di CI. Yang belum
adalah bagian E2E-nya.

**Dugaan penyebab, berurut menurut kemungkinan.**

1. **Penantian tanpa batas di skrip yang tidak punya `timeout`.** `periksa.sh`
   memanggil `node docs/uji/<skrip>.mjs` tanpa `timeout`. Satu skrip yang
   menggantung menahan seluruh rangkaian, dan tidak ada yang mencetak nama
   skripnya karena `catat` baru dipanggil setelah `node` selesai. Perbaikan yang
   tepat: bungkus dengan `timeout 180 node ...` supaya skrip yang menggantung
   gagal sendiri dan namanya muncul di ringkasan.
2. **`docker compose exec` menunggu selamanya** bila container `api` sedang
   sibuk atau socket-nya tertinggal.
3. **Tab Chrome yatim** yang tidak tertutup oleh `tutup_tab` karena
   `CDP_BASE` di dalam `node -e` berbeda dari yang dipakai skrip.

**Kenapa ditunda.** Penyebabnya belum bisa direproduksi di laptop: rangkaian yang
sama lulus `SEMUA LULUS (283 detik)` lewat Git Bash dengan Chrome headless di
port 9333. Tanpa reproduksi, setiap perbaikan adalah tebakan, dan tebakan pada
pipa CI lebih mahal daripada manfaatnya saat ini.

**Rencana penutupan.** Tambahkan `timeout` per skrip. Itu mengubah "menggantung
tanpa kabar" menjadi "gagal dengan nama skrip", yang cukup untuk menemukan
penyebab sebenarnya pada percobaan berikutnya.

**Sudah dikerjakan sebagian.** `periksa.sh` (jalur CI) kini memakai
`timeout 240 node ...` per skrip, dengan cadangan tanpa `timeout` bila
perintahnya tidak ada. `periksa.ps1` **belum** - dan itu disengaja: padanan
`Start-Process` + `WaitForExit` sudah dicoba dan justru menggantung sendiri saat
diuji dengan proses yang sengaja dibuat macet, sehingga obatnya lebih berbahaya
daripada penyakitnya. Batas waktu di PowerShell perlu pendekatan lain
(mis. job + `Wait-Job -Timeout`), dan itu pekerjaan tersendiri.

**Sementara ini.** Langkah E2E di `uji.yml` diberi `continue-on-error: true`
sehingga pipeline tidak merah karena hal yang belum dipahami. **Ini harus
dicabut** begitu penyebabnya ditemukan — pagar yang tidak menahan lebih buruk
daripada tidak ada pagar, karena ia terlihat seperti pagar.

---

## 2. Tiga kegagalan E2E yang ditemukan CI dan sudah diperbaiki

Dicatat karena cara temuannya penting: ketiganya **lulus di laptop** dan hanya
muncul di runner yang lebih lambat. Commit `f2c73f0`.

| Skrip | Sebab | Perbaikan |
|---|---|---|
| `e2e-t9.mjs` | Menunggu `.tabel tbody tr`, dan baris "Memuat tagihan…" juga cocok dengan selektor itu | Menunggu `.lencana-status`, yang hanya ada pada baris sungguhan |
| `e2e-t19.mjs` | Menunggu `h1` (muncul sebelum tabel terisi) + `setTimeout(1400)` tetap | Menunggu baris yang memuat judul, lalu menunggu status berubah (maks 15 detik) |
| `uji-tata-letak.mjs` | **Cacat layout nyata**: 363px pada viewport 360px | `.kepala` diberi `flex-wrap`, `.sisi` diberi `min-width: 0` |

Pelajaran yang berlaku umum: **asersi yang menunggu "ada elemen" harus menunggu
elemen yang membuktikan data sudah tiba**, bukan elemen pertama yang muncul.
Penanda memuat selalu lebih dulu ada.

---

## 3. Cacat fungsional yang diketahui, belum diperbaiki

| Cacat | Akibat | Catatan |
|---|---|---|
| `TerbitkanTagihanMassal` tidak terjadwal | Tagihan bulanan harus diterbitkan manual lewat layar | Disengaja; penjadwalan butuh cron yang tidak tersedia di Render free |
| Deposit dibaca saat kontrak dibuat, tidak dipakai lagi | Deposit tidak pernah mengurangi tagihan akhir | Perlu keputusan produk, bukan perbaikan kode |
| Akun yang pernah menangani uang tidak bisa dihapus | `RESTRICT` pada FK `dicatat_oleh`/`diterima_oleh` | Disengaja: jejak uang tidak boleh yatim |
| **DAT-03** — email dikirim sinkron (`QUEUE_CONNECTION=sync`), tanpa queue worker | Terbit massal mengirim satu email per penghuni, berurutan dalam satu permintaan: 30 penghuni = 30 email sebelum respons kembali, dan SMTP yang lambat memperlambat layar, bukan hanya emailnya | **Diterima sebagai utang, bukan cacat yang harus ditutup.** Satu layanan Render tidak bisa menjalankan queue worker persisten, dan `sync` adalah konsekuensi arsitektur itu. Kegagalan kirim sudah tidak membatalkan transaksi (R3). Jangan "memperbaikinya" dengan worker yang tidak akan hidup di produksi |
| Pembalik pembayaran belum punya tombol di layar | Koreksi salah catat masih lewat API (`POST /pembayaran/{id}/pembalik`) | Endpoint dan penjaganya sudah ada dan teruji. R3 menambahkan tombol **batalkan tagihan** dan **maafkan denda**, jadi koreksi uang kini punya layarnya kecuali pembalik pembayaran |
| Pemaafan denda tidak punya halaman riwayat sendiri | Pemaafan hanya terlihat saat membuka satu tagihan, dan di `log_audit` | Disengaja: pemaafan adalah kolom pada `tagihan`, bukan entitas. Riwayat lengkapnya ada di Jejak audit dengan aksi `denda.maafkan` |
| Rekening tidak menyimpan gambar QRIS | `jenis: qris` hanya menyimpan teks (nama merchant dan nomor bila ada) | Butuh penyimpanan berkas; Render free tidak punya disk persisten |

---

## 3b. Temuan turunan REV-UI-04 yang ditemukan saat R5

REV-UI-04 aslinya hanya menunjuk satu layar (`DashboardPenghuni.vue`). Saat
memperbaikinya, pola cacat yang sama ditemukan di **sembilan layar pemilik**:
`AturanView`, `KamarView`, `KontrakView`, `FasilitasView`, `PengelolaView`,
`PenghuniView`, `RekeningView`, `AuditLogView`, `RekapView`, ditambah
`komponen/TabelTagihan.vue` yang dipakai `TagihanView`.

Cacatnya satu bentuk: baris "Belum ada ..." hanya bergantung pada
`!memuat && daftar.length === 0`, tanpa memeriksa `galatMuat`. Saat pemuatan
gagal, layar memberi tahu pemilik bahwa datanya kosong — padahal yang terjadi
adalah gagal ambil. Pada layar tagihan, itu bisa menyembunyikan tagihan yang
sudah jatuh tempo.

**Kesepuluhnya sudah diperbaiki di R5** dengan menambahkan `&& !galatMuat`.
`TabelTagihan` mendapat prop baru `galatMuat` karena ia komponen bersama dan
tidak punya akses ke state induk.

Dijaga `docs/uji/uji-galat-muat.mjs` — 30 langkah, 10 layar × 3 asersi. Blokade
500 dipasang lewat CDP `Fetch.fulfillRequest`, jadi tidak mengubah database.
Pagar ini **sudah dibuktikan bisa gagal**: satu layar sengaja dikembalikan ke
kondisi lama, dan tepat satu asersi menjadi merah.

---

## 4. Kesiapan produksi yang belum dikerjakan (LANGKAH 11)

| Kurang | Akibat bila live sekarang |
|---|---|
| `Dockerfile.prod` satu layanan (SPA di dalam `public/` Laravel) | Dua origin, CORS harus dibuka, dua cold start |
| `docker-compose.prod.yml` belum memakai Neon (masih service `db`) | Database hilang setiap restart — Render free tidak punya disk |
| `SESSION_DRIVER`/`CACHE_STORE` masih `file` | Sesi dan pembatas login hilang setiap restart |
| Akun demo belum dihapus dari database produksi | `pemilik@kos.test` / `password` = seluruh data kos terbuka |
| Backup tidak terjadwal | `backup.sh` ada dan terbukti bisa memulihkan, tetapi tidak dipanggil cron mana pun |
| Target deploy belum ada | `deploy.yml` melaporkan `siap=false`, tidak gagal |
| T22 Midtrans belum bisa diverifikasi | Butuh kredensial sandbox |

---

## 5. Konsistensi dokumen (LANGKAH 11, T23)

| Berkas | Masalah |
|---|---|
| ~~`SPEC.md` §6 L374~~ | **Sudah diperbaiki di R3.** Kini menulis `UNIQUE (nama, berlaku_dari, kamar_id) NULLS NOT DISTINCT` |
| ~~`SPEC.md` L259/281/380, `docs/uji/audit-ketertelusuran.md`~~ | **Sudah diperbaiki di R3.** Seluruh sebutan `parameter_denda` diganti `kebijakan_kos`, termasuk nama tes di `pemetaan-kriteria.md` |
| ~~DFD + SPEC menyebut 6 data store~~ | **Bukan cacat.** Memang 6 store; yang salah adalah sebutan `parameter_denda` di dalamnya, dan itu sudah diganti |
| ~~`3_ARCHITECTURE.md:30` dan `KONTEKS_PROYEK.md:17` menulis Laravel 11~~ | **Diverifikasi di R7.** Keduanya kini menulis Laravel 12.70.1 / PHP 8.3.36, cocok dengan `composer.lock` (`v12.70.1`) dan `php artisan --version` di container. Tiga sebutan "Laravel 11" yang tersisa (`docs/build-plan.md:11`, `README.md:496`, `docs/utang-teknis.md:145`) semuanya menceritakan riwayat keputusan, bukan klaim versi yang salah |
| `revisi.md` dan `docs/revisi.md` | Masih di repo tetapi **tidak di-commit** — keduanya masukan audit, bukan deliverable |
| Laporan | Menunggu format dari dosen |

---

## 6. U2 — mobile dan PWA (LANGKAH 10) — SEBAGIAN BESAR SELESAI

| Kurang | Status |
|---|---|
| Tabel 4+ kolom harus digeser ke samping di ponsel | **Selesai.** Direktif `v-kartu-tabel` melipat baris menjadi kartu di bawah 620px; dijaga `uji-kartu-tabel.mjs` 65 langkah |
| Tidak ada manifest, service worker, theme-color | **Selesai.** `manifest.webmanifest`, `sw.js`, 4 ikon PNG, `theme-color`; dijaga `uji-pwa.mjs` 22 langkah |
| Kepala pemilik memakan 360px dari 800px layar | **Selesai.** Terukur turun ke 203px |
| Target sentuh di bawah 44px | **Selesai** untuk `(pointer: coarse)` |
| Luberan mendatar di 360px | **Selesai** sejak commit `f2c73f0`, dijaga `uji-tata-letak.mjs` 94/94 |

**Sisa yang belum dikerjakan:**

| Kurang | Dampak | Catatan |
|---|---|---|
| Navigasi bawah (bottom bar) khusus ponsel | Menu masih berupa deret mendatar yang digeser | Bukan cacat, tetapi pola ponsel yang lazim |
| Orientasi lanskap di ponsel | Belum diuji; `manifest` mengunci `portrait-primary` | Perlu uji tersendiri di 640x360 |
| Uji luring sungguhan (matikan jaringan) | Kerangka ter-cache, tetapi belum pernah dibuktikan bisa dibuka tanpa jaringan | Bisa diuji dengan `Network.emulateNetworkConditions` |
| `uji-pwa.mjs` memakai build produksi | Service worker tidak didaftarkan saat `npm run dev` | `periksa.sh` sudah memakai image, jadi aman di sana |

**Catatan penting.** Luberan mendatar di 360px **sudah** diperbaiki (commit
`f2c73f0`) dan dijaga `uji-tata-letak.mjs` 94/94. Jadi aplikasi sekarang *muat*
di ponsel; yang belum adalah *nyaman*.

---

## 7. Temuan audit yang DITOLAK, beserta alasannya

`revisi.md` memuat 36 temuan. Empat di antaranya tidak benar dan tidak
dikerjakan. Dicatat di sini supaya tidak ada yang mengusulkannya lagi tanpa
memeriksa ulang.

| Kode | Klaim | Kenapa ditolak |
|---|---|---|
| **REV-FIN-06** | Ada status tagihan `perbaikan` yang tidak pernah ditangani | **Status itu tidak ada.** Tidak ada satu pun kemunculan `perbaikan` di `api/`: tidak di `Tagihan::STATUS_*`, tidak di CHECK `tagihan_status_dikenal`, tidak di seeder, tidak di frontend. Status yang benar-benar ada: `belum_bayar`, `sebagian`, `lunas`, `dibatalkan`. Sprint 3.7 dibatalkan seluruhnya. |
| **REV-ACA-03** | Berkas `~$oject_Charter_Kos-Kosan.docx` ter-commit | **Tidak ter-commit.** `.gitignore` sudah memuat `~$*` sejak awal, dan `git ls-files` tidak memuatnya. Tidak ada yang perlu diperbaiki. |
| **REV-SEC-05** (sebagian) | Kebocoran nama+email adalah lubang serius | **Terlalu keras.** Endpoint hanya membocorkan PII bila pemanggil sudah memegang token 64 karakter acak yang sah — dan pemegang token itu bisa langsung menetapkan sandi baru lewat `/atur-sandi` tanpa membaca emailnya sama sekali. Tetap dikeraskan (batas 20/menit per IP + penyamaran email), tetapi sebagai pertahanan berlapis, bukan penutupan lubang kritis. |
| **REV-UI-02** (premis) | Ada rujukan ke rute `komponen-biaya` yang sudah tidak ada | **Premisnya salah.** Tidak ada rujukan `komponen-biaya` di `web/src` sama sekali. Celah yang dilaporkan (tidak ada layar untuk komponen biaya) memang nyata, tetapi bukan karena rujukan yang rusak. |

## 8. Keputusan lama yang DIBATALKAN atas permintaan pemilik

Dua keputusan di dokumen ini sempat diambil dengan alasan yang masuk akal, lalu
dibatalkan. Yang lama tetap dicatat supaya alasan pembatalannya terbaca.

| Keputusan lama | Diganti menjadi | Alasan pembatalan |
|---|---|---|
| `GET /rekap` terbuka untuk pengelola — "membaca uang yang sudah masuk bukan tindakan yang merugikan siapa pun, dan pengelola yang mencatat pembayaran perlu memeriksa catatannya sendiri" | `/rekap` **`peran:pemilik`**; pengelola mendapat `GET /pembayaran/tunai-saya` | Alasan lama tidak bertahan setelah diperiksa: rekap adalah satu-satunya tempat seluruh pendapatan kos terlihat sebagai satu angka, dan pengelola yang memegangnya bisa menyimpulkan penghasilan pemiliknya. Yang dibutuhkan pekerjaannya sudah tersedia di tempat lain — daftar pembayaran tetap terbuka, dan uang tunai di tangannya punya endpoint sendiri. |
| Verifikasi pembayaran menerima catatan sendiri | Ditolak **403** `verifikasi_catatan_sendiri` bila pelakunya `pengelola`; **pemilik tetap boleh** | Pemisahan wewenang pencatatan dan pemeriksaan hanya berlaku di atas kertas bila orang yang sama boleh melakukan keduanya. Pengecualian pemilik disengaja: pada kos kecil ia sering satu-satunya orang yang memegang uang, dan melarangnya memeriksa catatannya sendiri berarti tidak ada yang bisa memeriksa sama sekali. |
