# 7. Testing & Evals

> Dokumen ini menjawab: **apa yang dibuktikan tes, bagaimana menjalankannya, dan
> bagaimana menambah tes yang benar-benar menahan.**
>
> Angka diambil dari aplikasi yang berjalan: **830 tes PHP (3883 asersi)** dalam
> 48 berkas, **19 tes Vitest** untuk fungsi murni frontend, dan **30 skrip uji**
> (27 browser + 2 balapan HTTP + 1 jalur proxy) dengan **879 langkah**.

Versi 1.0 · 26 Oktober 2026

---

## 1. Prinsip

### 1.1 Uji yang tidak pernah terbukti bisa gagal bukan uji

Ini prinsip yang paling mahal dipelajari di proyek ini, dan yang paling sering
dilanggar tanpa disadari.

Contoh nyata: `uji-pwa.mjs` versi pertama memeriksa "tidak ada respons `/api` di
cache" — dan **lulus meski pagar `/api` di `sw.js` dicabut**. Penyebabnya: ia
membaca cache yang kosong, dan "tidak ada `/api` di cache kosong" juga benar.

Perbaikannya dua lapis:

1. Cache dikosongkan **tepat sebelum** pengukuran.
2. Satu permintaan `/api` dibuat **sengaja**, lalu hasilnya diperiksa.

Setelah itu, dengan pagar dicabut, uji melaporkan `bocor: /api/me`. **Baru
setelah itu asersinya bermakna.**

**Aturan:** setiap asersi yang menjaga keamanan atau uang harus pernah dilihat
merah. Kalau tidak, ia mungkin hanya mengukur keadaan kosong.

### 1.2 Uji harus bisa dijalankan berulang

Jalan kedua tidak boleh gagal karena jalan pertama meninggalkan data.

```js
// SALAH - jalan kedua gagal pada unique index, dan terbaca seperti fitur rusak
nomor: 'KT-1'

// BENAR
const cap = Date.now().toString(36).slice(-4).toUpperCase()
nomor: `KT-${cap}`
```

### 1.3 Tunggu bukti data tiba, bukan elemen pertama yang muncul

```js
// SALAH - "Memuat tagihan…" juga cocok
await tungguSampai(ws, `document.querySelectorAll('.tabel tbody tr').length > 0`)

// BENAR - lencana status hanya ada pada baris sungguhan
await tungguSampai(ws, `document.querySelector('.tabel tbody tr .lencana-status')`)
```

Penanda memuat selalu lebih dulu ada. Menunggu elemen pertama berarti mengukur
keadaan kosong.

### 1.4 Asersi tata letak harus memeriksa bahwa pengukurannya berlaku

`uji-tata-letak.mjs` memeriksa `innerWidth === lebar` **sebelum** memeriksa
luberan. Tanpa itu, emulasi yang gagal akan membuat halaman diukur pada lebar
desktop, dan asersi "tidak meluber" lulus tanpa mengukur apa pun.

---

## 2. Piramida

```
        ┌──────────────────────────────────────┐
        │  30 skrip uji                        │  paling lambat, tetapi
        │  (26 browser + 2 balapan + 1 proxy)  │  satu-satunya yang melihat
        └──────────────────────────────────────┘
      ┌──────────────────────────────────────┐
      │  712 tes Feature                     │  ~42 dtk, jalur penuh
      │  (validasi, izin, isolasi, balapan)  │  lewat middleware nyata
      └──────────────────────────────────────┘
    ┌──────────────────────────────────────┐
    │  100 tes Unit                        │  cepat, murni logika
    │  (prorata, denda, umur tunggakan)    │  tanpa database
    └──────────────────────────────────────┘
```

Yang **tidak** ada: tes komponen Vue yang merender markup. Sejak 30 Oktober ada
Vitest, tetapi isinya fungsi murni (format uang/tanggal, pemotong halaman) yang
tidak butuh DOM. Setiap komponen yang berharga sudah diuji lewat browser, dan
tes komponen yang merender akan menguji implementasi, bukan perilaku.

---

## 3. Tes PHP - 830 tes, 3883 asersi

### 3.1 Menjalankan

```bash
docker compose exec -T api php artisan test
docker compose exec -T api php artisan test --filter=KalkulatorTest
docker compose exec -T api php artisan test tests/Unit/KalkulatorTest.php
```

Rata-rata 23 detik untuk seluruhnya.

### 3.2 Empat tes Unit

| Berkas | Yang dibuktikan | Kenapa unit, bukan feature |
|---|---|---|
| `KalkulatorTest` | R-01 prorata: hari dalam bulan, batas hari ikut dihitung, pembulatan setengah ke atas, bulan 28/29/30/31 | Murni aritmetika. Butuh 22 kasus batas yang tidak mungkin ditulis lewat HTTP |
| `DendaTest` | R-03: 1%/hari, plafon 30%, mulai hari ke-8, plafon tercapai hari ke-37 | Sama — butuh pergeseran waktu |
| `DendaBentukTest` | Empat bentuk denda: `persen_harian`, `persen_bulanan`, `nominal_tetap`, `tanpa_denda` | Setiap bentuk punya aturan sendiri; `tanpa_denda` **bukan** galat |
| `UmurTunggakanTest` | R-04 empat keranjang umur | Batas 30/31 dan 60/61 adalah off-by-one yang mudah salah |

### 3.3 Tes Feature

712 tes Feature, dikelompokkan menurut apa yang dijaga. Tabel di bawah menyoroti
berkas yang menjaga aturan, bukan seluruhnya:

**Uang dan tagihan**

| Berkas | Menjaga |
|---|---|
| `TagihanTest` | Terbit, snapshot R-06, status `belum_bayar`→`sebagian`→`lunas` |
| `PenerbitanMassalTest` | Yang sudah ada dilewati, bukan digagalkan; **REV-DAT-02**: tiap penghuni yang tertagih menerima notifikasi tagihannya sendiri, yang gagal tidak |
| `PenerbitanBalapanTest` | R-02 di bawah permintaan bersamaan (SQLSTATE 23505 → 409) |
| `PembayaranTest` | Jumlah tidak boleh melebihi sisa; tunai wajib ada penerima |
| `PembayaranMetodeTest` | `metode` wajib di API, boleh NULL di baris lama |
| `VerifikasiPembayaranTest` | Append-only; `tolak` wajib `catatan`; tidak mengubah jumlah |
| `RekapPendapatanTest` | Basis kas, denda terpisah sebagai piutang |
| `TunggakanHttpTest` | Empat keranjang R-04 lewat HTTP |
| `DendaHttpTest` | Denda dihitung saat dibaca, `denda_ringkas` konsisten |
| `MigrasiHapusDepositTest` | Deposit benar-benar keluar: kolom hilang, tiga CHECK menyempit, trigger append-only utuh, `down()`/`up()` bisa dibalik |
| `PembalikPembayaranTest` | Baris pembalik negatif; saldo tagihan pulih; `total_final` dilepas; rekap memisahkan pembalik |

**Kebijakan dan tarif**

| Berkas | Menjaga |
|---|---|
| `KebijakanKosTest` | R-05 gerbang wajib; empat CHECK kolom |
| `RiwayatTarifTest` | Append-only; tarif berlaku = baris terbesar ≤ tanggal |
| `KomponenPerKamarTest` | `NULLS NOT DISTINCT`; komponen global vs per kamar |
| `MigrasiT17Test` | Bentuk data setelah migrasi T17 (denda bertipe) |

**Akses dan isolasi**

| Berkas | Menjaga |
|---|---|
| `AuthTest` | Login, token, 401 |
| `IsolasiDanAuditTest` | **R-07**: 404 bukan 403; `log_audit` append-only |
| `PengelolaTest` | Wewenang per tindakan; tidak bisa didelegasikan |
| `UsulanPersetujuanTest` | 201 vs 202; kedaluwarsa dihitung; dua baris audit |
| `KomponenUsulanTest` | **REV-SEC-07**: komponen biaya ikut jalur usulan; 409 saat bentrok tanggal |
| `AktivasiAkunTest` | Tanpa sandi awal; token hash; `wajib_ganti_sandi`; email aktif terkunci; periksa-token dibatasi per IP |
| `PdpTest` | Persetujuan per versi; anonimisasi; 409 untuk akun terikat |
| `VerifikasiPembayaranTest` | Pemeriksaan terakhir yang berlaku; pengelola tidak memeriksa catatan sendiri |

**Data induk**

| Berkas | Menjaga |
|---|---|
| `DataIndukTest` | Kamar, penghuni, kontrak; satu kontrak aktif per kamar |
| `SkemaRiwayatKamarTest` | **REV-DAT-01/04**: `RESTRICT` pada tiga FK riwayat menolak `DELETE` **lewat foreign key**, bukan lewat pesan trigger append-only; kamar tanpa riwayat tetap bisa dihapus; `kamar_fasilitas` tetap ikut terlepas; index `(kontrak_id, status)` ada dan index `(status, jatuh_tempo)` lama tidak hilang |
| `JejakDataIndukTest` | **REV-DAT-05**: kamar, penghuni, kontrak, fasilitas, dan aturan kos menulis `log_audit`; hanya kolom yang berubah yang dicatat; menyimpan tanpa perubahan tidak menghasilkan jejak; jejak menyebut pengelola yang bertindak |
| `FasilitasTest` | `PUT /fasilitas` mengganti seluruh daftar |
| `AturanKosTest` | `aktif` bukan hapus; kategori string bebas |
| `OperasionalTest` | `/health`, logging permintaan gagal |
| `NotifikasiTest` | Sembilan peristiwa; bawaan kode; tidak memberi tahu pelaku |

### 3.4 Jebakan menulis tes PHP

| Jebakan | Akibat | Obat |
|---|---|---|
| Satu statement gagal | **Seluruh transaksi dibatalkan** | Bungkus dalam `DB::transaction(...)` sebelum memeriksa `QueryException` |
| `DB::afterCommit()` | **Tidak pernah terpanggil** di `RefreshDatabase` | Kirim notifikasi di batas HTTP |
| `Schema::hasTable` nyata | Tes `/health` 503 tidak bisa memaksa kegagalan | Mock `Schema::shouldReceive('hasTable')` |
| `Kontrak::create` tanpa `tarif_awal` | NOT NULL violation | Selalu sertakan `tarif_awal` |
| `PengelolaKewenangan` ganda | Unique `(user_id, wewenang)` | `firstOrCreate` |

---

## 4. Skrip uji browser, balapan, dan jalur proxy - 30 skrip, 879 langkah

### 4.1 Menjalankan

```bash
# Seluruh guardrail (direkomendasikan)
bash periksa.sh

# Hanya uji browser (paling lama dari seluruh gate)
bash periksa.sh --hanya-e2e

# Satu skrip
node docs/uji/e2e-t3.mjs
```

Prasyarat: `docker compose up`, Chrome dengan `--remote-debugging-port=9222`,
`APP_DEMO_MODE=true`, database ter-seed.

### 4.2 Katalog

| Skrip | Langkah | Yang dibuktikan |
|---|---|---|
| `e2e-t1.mjs` | 15 | Walking skeleton: login dua peran, dashboard, keluar; **token dicabut di server selagi sesi terbuka** mengeluarkan pengguna ke `/masuk` dengan pesan dan `lanjut` (REV-UI-01) |
| `e2e-t2.mjs` | 18 | Kamar, penghuni, kontrak |
| `e2e-t3.mjs` | 19 | Terbit tagihan, rincian, snapshot |
| `e2e-t4.mjs` | 20 | Terbit massal: yang sudah ada dilewati; kendali halaman memotong 52 tagihan jadi 50 + 2 tanpa mengubah total di kepala halaman (K-08) |
| `e2e-t5.mjs` | 22 | Catat pembayaran, status berubah |
| `e2e-t6.mjs` | 24 | Denda dan simulasi |
| `e2e-t7.mjs` | 32 | Tunggakan berjenjang |
| `e2e-t9.mjs` | 26 | Isolasi penghuni, log audit |
| `e2e-t18.mjs` | 26 | Fasilitas vs komponen biaya |
| `e2e-t19.mjs` | 23 | Aturan kos, portal penghuni |
| `e2e-t20.mjs` | 36 | Metode pembayaran, verifikasi, pembalik, rekap |
| `e2e-t24.mjs` | 43 | Pengelola, wewenang, menu tersembunyi, rekap vs tunai-saya |
| `e2e-t25.mjs` | 30 | Notifikasi in-app + email |
| `e2e-t26.mjs` | 39 | Usulan, persetujuan, kedaluwarsa |
| `e2e-t27.mjs` | 28 | Aktivasi, lupa sandi, wajib ganti sandi |
| `e2e-t28.mjs` | 42 | PDP: persetujuan, akses, anonimisasi |
| `e2e-r3.mjs` | 50 | Pembatalan tagihan, pemaafan denda, bagikan WhatsApp, rekening |
| `uji-pembalik.mjs` | 13 | Tombol **Tarik kembali**: alasan wajib, baris negatif di riwayat, tombol hilang sesudah ditarik, pembatalan jadi mungkin |
| `uji-qris.mjs` | 16 | Unggah gambar QRIS, pratinjau, gambar termuat sebagai blob di portal penghuni, penolakan pada rekening transfer, rekening nonaktif 404 |
| `asap-semua-halaman.mjs` | 24 | Semua halaman terbuka tanpa galat konsol/5xx |
| `uji-tata-letak.mjs` | 104 | Tidak meluber di 360/390/768px; navigasi bawah muncul di layar sempit dan hilang di 1280px |
| `uji-kartu-tabel.mjs` | 65 | Tabel 4+ kolom jadi kartu; label sesuai `<th>`; desktop kembali normal |
| `uji-pwa.mjs` | 26 | Manifest, service worker, **pagar R-07** |
| `uji-galat-muat.mjs` | 30 | Pemuatan gagal tidak menyamar sebagai "Belum ada" (REV-UI-04) |
| `uji-tiga-peran.mjs` | 28 | Pemilik, pengelola, penghuni bergantian dalam **satu** sesi; batas peran ditegakkan server (403 vs 404 dibedakan), token lama tidak mewarisi peran baru |
| `uji-aksesibilitas.mjs` | 27 | axe-core pada 21 halaman **dan tiga keadaan yang baru muncul dari tindakan pengguna** (panel rincian, form catat pembayaran, galat validasi) dengan WCAG 2.0/2.1 A/AA; kontrol negatif menyisipkan empat cacat dan menuntut keempatnya terdeteksi |
| `uji-balapan-t4.mjs` | 10 | 10 permintaan terbit paralel → satu 201, sisanya 409 |
| `uji-balapan-t5.mjs` | 12 | Pembayaran paralel tidak melebihi sisa |
| `e2e-fase35.mjs` | 18 | Penanda penyiapan dirender; ekspor benar-benar terunduh (peristiwa unduhan CDP, bukan kode status); pengelola tidak melihat tombol ekspor rekap; halaman bantuan terbuka untuk kedua peran |
| `uji-proxy.mjs` | 5 | Lewat port proxy (`:3000`), IP klien yang tercatat adalah IP pemanggil, bukan IP proxy — dibuktikan dari pembatas laju dan `log_audit` |

### 4.3 Dua uji tata letak, dua cacat berbeda

Ini bukan duplikasi.

| | `uji-tata-letak.mjs` | `uji-kartu-tabel.mjs` |
|---|---|---|
| Mengukur | Luberan halaman | Bentuk tabel |
| Melewati `.tabel-bungkus` | **Ya** — bungkus itu memang menggulir | Tidak |
| Menangkap | Halaman bergulir ke samping | Tabel yang harus digeser |
| Asersi | `scrollWidth <= innerWidth` | `data-label` = `<th>`, `thead` tersembunyi, sel `flex` |

`uji-tata-letak` sengaja melewati elemen di dalam `.tabel-bungkus`, karena
bungkus itulah yang menggulir. Konsekuensinya: **tabel yang masih berupa tabel
di layar 360px selalu lolos** — ia tidak meluber, ia hanya harus digeser.
`uji-kartu-tabel` menutup celah itu.

### 4.4 Urutan langkah menentukan

`periksa.sh` menjalankan `migrate:fresh --seed` dan `cache:clear` **di antara**
skrip. Itu bukan kerapian:

- Uji isolasi sengaja gagal login berulang kali untuk memicu pembatas.
  Hitungannya tersimpan di cache, dan `migrate:fresh` **tidak**
  membersihkannya. Skrip berikutnya lalu menerima 429 pada login yang benar.
- Uji yang mengubah data (terbit tagihan, catat pembayaran) akan membuat skrip
  berikutnya mengukur keadaan yang bukan miliknya.

**Jangan jalankan skrip satu per satu secara manual lalu menyimpulkan hijau.**
Urutan adalah bagian dari uji.

### 4.5 Jebakan menulis tes browser

| Jebakan | Akibat | Obat |
|---|---|---|
| Backtick di dalam template literal (bahkan di komentar) | `node --check` gagal dengan baris yang salah | Selalu `node --check`; berkas `.mjs`/`.ps1` harus ASCII |
| Nama dari seeder ditulis di skrip | Gagal setiap data contoh berubah | Baca dari `/me` |
| Akun baru mendarat di `/persetujuan-pdp` | Skrip gagal di langkah pertama, terbaca seperti kebocoran | `lewatiPersetujuanPdp(ws)` setelah `masukSebagai()` |
| Dua `change` checkbox dalam satu tick | Keduanya membaca larik lama | Klik dengan jeda ~120 ms |
| `tarif_sewa`/`kebijakan_kos` append-only | `berlaku_dari` bentrok pada jalan kedua | Hitung tanggal bebas (`tanggalBebas()`) |
| Kedaluwarsa tidak bisa dibuat lewat API | Uji kedaluwarsa tidak mungkin | Mundurkan `kedaluwarsa_pada` lewat `psql` (`execFileSync`) |
| Chrome menyajikan bundel lama | Uji mengukur kode lama, melaporkan kegagalan fitur yang sudah ada | `_cdp.mjs` mematikan cache; jendela manual tidak |

---

## 5. Uji balapan

Dua skrip, dan keduanya menguji hal yang **tidak bisa** diuji secara berurutan.

| Skrip | Langkah | Yang dibuktikan |
|---|---|---|
| `uji-balapan-t4.mjs` | 10 | 10 permintaan terbit paralel untuk periode yang sama → tepat satu `201`, sisanya `409` |
| `uji-balapan-t5.mjs` | 12 | Pembayaran paralel tidak membuat total melebihi sisa |

**Kenapa harus paralel.** R-02 dijaga unique index, bukan pemeriksaan
sebelum-insert. Pemeriksaan berurutan akan lulus pada tes biasa:
periksa → tidak ada → sisipkan. Dengan 10 permintaan bersamaan, semuanya
memeriksa sebelum ada yang menyisipkan. Hanya index yang menahan. Tes
berurutan **tidak akan pernah** menangkap kelas kesalahan ini.

---

## 6. Evaluasi terhadap ukuran keberhasilan

Dari `1_PRD.md` §6, dan bagaimana masing-masing dibuktikan.

| # | Ukuran | Target | Dibuktikan oleh | Status |
|---|---|---|---|---|
| U-1 | Terbitkan tagihan 30 kamar | < 1 menit | Demo terkontrol | Terukur ~4 detik |
| U-2 | Kesalahan hitung prorata | 0 | `KalkulatorTest` | Lulus |
| U-3 | Tagihan ganda per periode | 0 | `uji-balapan-t4.mjs` | Lulus 10/10 |
| U-4 | Kebocoran data antar-penghuni | 0 | `IsolasiDanAuditTest`, `e2e-t9.mjs` | Lulus |
| U-5 | Respons `/api` di cache PWA | 0 | `uji-pwa.mjs` — **dibuktikan bisa gagal** | Lulus 22/22 |
| U-6 | Halaman meluber di 360px | 0 | `uji-tata-letak.mjs` | Lulus 104/104 |
| U-7 | Tabel tak terbaca di 360px | 0 | `uji-kartu-tabel.mjs` | Lulus 65/65 |
| U-8 | Kepala pemilik sebelum konten | < 220px | Terukur | 203px |
| U-9 | Cakupan tes PHP | 830 tes | CI | Lulus 3883 asersi |
| U-10 | Pemuatan gagal menyamar sebagai data kosong | 0 layar | `uji-galat-muat.mjs` — **dibuktikan bisa gagal** | Lulus 30/30 |

---

## 7. Yang **tidak** diuji, dan kenapa

Daftar ini lebih penting daripada daftar yang diuji, karena ia menentukan
seberapa besar kepercayaan pada hijau.

| Tidak diuji | Kenapa | Risiko yang diterima |
|---|---|---|
| Midtrans sungguhan | Belum ada akun sandbox | Alur webhook hanya diuji dengan payload tiruan |
| Uji beban | Sudah ada skripnya 30 Okt 2026: `docs/beban/uji-beban.mjs`, 50 bersamaan / 500 permintaan, ambang p95 1.000 ms (K-10). Terukur di laptop: 77 permintaan/detik, p95 840 ms, 0 galat. Bukan bagian gate | Angka di Render/Neon belum ada. Daftar tagihan bisa berhalaman lewat `?per_page=` (K-05, pilihan C), dan layar Tagihan memotong 50 baris per halaman di peramban (K-08) |
| ~~Uji aksesibilitas otomatis (axe)~~ | ~~Waktu~~ | **Sudah ada 30 Oktober 2026:** `docs/uji/uji-aksesibilitas.mjs` (axe-core, 21 halaman, WCAG 2.0/2.1 A/AA, 27 langkah) dijalankan gate. Sejak 30 Oktober ia juga mengukur **tiga keadaan yang baru muncul dari tindakan pengguna**: panel rincian terbuka, form catat pembayaran terbuka, dan pesan galat validasi - keadaan yang paling sering gagal kontras dan paling jarang dilihat pengembang. Dua cacat nyata ditemukannya: kontras 3,49:1 pada kode peristiwa di Pengaturan notifikasi (kini 5,3:1), dan seluruh pesan galat yang tidak diumumkan pembaca layar karena `role="alert"` tidak pernah dipasang (kini dipasang oleh `PesanBaris` sendiri). Yang tetap belum: pembaca layar sungguhan (NVDA/VoiceOver), karena itu butuh manusia di depan mesin |
| ~~Orientasi lanskap ponsel~~ | **Sudah diukur 29 Okt 2026** | `uji-tata-letak.mjs` menguji 640×360 pada Tagihan, Rekening, dan Pengelola; kontra-uji dengan div 2000px membuat tiga asersi merah |
| ~~Uji luring sungguhan~~ | **Sudah dibuktikan 29 Okt 2026** | `uji-pwa.mjs` mematikan jaringan pada target service worker (bukan hanya halaman) dan menghapus Cache Storage sebagai kontrol negatif. Batasnya: hanya di Chrome desktop, bukan ponsel sungguhan |
| Email sungguhan (Brevo) | Hanya Mailpit | Batas kirim Brevo dan reputasi domain belum diuji |
| E2E di CI | ~~Menggantung 17+ menit~~ | **Sudah hijau.** Dugaan "menggantung" keliru: yang gagal adalah paritas daftar skrip, 34-41 dtk. Kini 30 skrip / 871 langkah lulus |
| Tes komponen Vue | Tidak ada yang merender markup | Regresi tampilan hanya tertangkap E2E; fungsi murni sudah dijaga Vitest |

> **Sejak 29 Oktober 2026 deploy produksi dibatalkan pemilik.** Baris yang
> menyebut Midtrans sungguhan, email Brevo, beban di Render/Neon, dan cold start
> tidak lagi menjadi pekerjaan tertunda — tidak ada layanan yang dijalankan.
> Batasnya tetap dicatat apa adanya, karena batas yang dihapus dari dokumen akan
> ditemukan lagi sebagai kejutan.

**E2E di CI sudah dipercaya lagi.** `continue-on-error` sudah **dicabut**;
`timeout-minutes: 20` tetap sebagai jaring pengaman. Penyebab lamanya bukan
E2E: `periksa.sh` ketinggalan dua skrip yang hanya ada di `periksa.ps1`,
sehingga langkah paritas gagal dalam 34-41 detik - dan karena itu E2E tidak
pernah benar-benar berjalan. Sesudah disamakan, E2E berjalan penuh dan
membuka enam kegagalan yang selama ini tertutup: tiga balapan di skrip uji
dan satu cacat layout nyata (halaman Tagihan bergulir mendatar di 360px).
Semuanya sudah diperbaiki. Rincian: `docs/utang-teknis.md` 1 dan 2b.

---

## 8. Menambah tes

### 8.1 Tes PHP

```bash
# 1. Buat berkas
#    Feature: api/tests/Feature/NamaTest.php
#    Unit:    api/tests/Unit/NamaTest.php

# 2. Tulis
# 3. Jalankan
docker compose exec -T api php artisan test --filter=NamaTest

# 4. Seluruhnya
docker compose exec -T api php artisan test
```

**Pilih Feature bila** perilakunya melewati middleware, database, atau bentuk
JSON. **Pilih Unit bila** yang diuji murni perhitungan dan butuh banyak kasus
batas.

### 8.2 Tes browser

```bash
# 1. Buat docs/uji/e2e-tNN.mjs mengikuti pola _cdp.mjs
# 2. WAJIB: node --check docs/uji/e2e-tNN.mjs
# 3. Jalankan
node docs/uji/e2e-tNN.mjs

# 4. Daftarkan di KEDUA berkas guardrail
#    periksa.sh  -> array E2E
#    periksa.ps1 -> array $e2e
```

Langkah 4 tidak opsional: paritas daftar antara `periksa.sh` dan `periksa.ps1`
diperiksa CI. Skrip yang hanya didaftarkan di salah satunya akan lolos dari
guardrail selamanya tanpa ada yang memberi tahu.

### 8.3 Daftar periksa sebelum commit

1. `node --check` setiap `.mjs` baru.
2. Nama/nilai unik memakai cap waktu.
3. Asersi menunggu bukti data tiba, bukan penanda memuat.
4. Terdaftar di **kedua** berkas guardrail.
5. `bash periksa.sh --hanya-e2e` hijau.
6. Untuk pagar keamanan: **coba cabut pagarnya, pastikan uji merah.**
7. `docs/uji/README.md` diperbarui.

---

## 9. Membaca hasil

```
LULUS  360px Tagihan: setiap sel berlabel sesuai judul kolomnya
GAGAL  390px Kontrak: kartu tidak meluber mendatar - dokumen 412 > 390 (.kartu.panel)
64/65 langkah lulus
```

Format: `STATUS  konteks: asersi - detail`.

Detail sengaja memuat **angka dan nama elemen**, bukan hanya "gagal". Laporan
`794 lebih besar dari 390` tidak memberi tahu apa yang harus diperbaiki; laporan
`dokumen 412 > 390 (.kartu.panel)` memberi tahu di mana mencarinya.

Kode keluar `0` hanya bila **semua** langkah lulus. `periksa.sh` berhenti pada
skrip pertama yang gagal — kalau ia melanjutkan, kegagalan pertama akan
menyebabkan kegagalan berantai yang menyembunyikan penyebabnya.
