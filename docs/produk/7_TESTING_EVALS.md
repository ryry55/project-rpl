# 7. Testing & Evals

> Dokumen ini menjawab: **apa yang dibuktikan tes, bagaimana menjalankannya, dan
> bagaimana menambah tes yang benar-benar menahan.**
>
> Angka diambil dari aplikasi yang berjalan: **748 tes PHP (3522 asersi)** dalam
> 36 berkas, dan **26 skrip uji** (24 browser + 2 balapan HTTP) dengan **760 langkah**.

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
        │  26 skrip uji                        │  lambat (~300 dtk), tetapi
        │  (22 browser + 2 balapan)            │  satu-satunya yang melihat
        └──────────────────────────────────────┘
      ┌──────────────────────────────────────┐
      │  613 tes Feature                     │  ~25 dtk, jalur penuh
      │  (validasi, izin, isolasi, balapan)  │  lewat middleware nyata
      └──────────────────────────────────────┘
    ┌──────────────────────────────────────┐
    │  100 tes Unit                        │  cepat, murni logika
    │  (prorata, denda, umur tunggakan)    │  tanpa database
    └──────────────────────────────────────┘
```

Yang **tidak** ada: tes komponen Vue (Vitest). Tidak ada satu pun. Setiap
komponen yang berharga sudah diuji lewat browser, dan tes komponen akan
menguji implementasi, bukan perilaku.

---

## 3. Tes PHP — 748 tes, 3522 asersi

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

### 3.3 Dua puluh lima tes Feature

Dikelompokkan menurut apa yang dijaga:

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

## 4. Skrip uji browser dan balapan - 26 skrip, 760 langkah

### 4.1 Menjalankan

```bash
# Seluruh guardrail (direkomendasikan)
bash periksa.sh

# Hanya uji browser (~299 detik)
bash periksa.sh --hanya-e2e

# Satu skrip
node docs/uji/e2e-t3.mjs
```

Prasyarat: `docker compose up`, Chrome dengan `--remote-debugging-port=9222`,
`APP_DEMO_MODE=true`, database ter-seed.

### 4.2 Katalog

| Skrip | Langkah | Yang dibuktikan |
|---|---|---|
| `e2e-t1.mjs` | 9 | Walking skeleton: login dua peran, dashboard, keluar |
| `e2e-t2.mjs` | 14 | Kamar, penghuni, kontrak |
| `e2e-t3.mjs` | 19 | Terbit tagihan, rincian, snapshot |
| `e2e-t4.mjs` | 16 | Terbit massal: yang sudah ada dilewati |
| `e2e-t5.mjs` | 22 | Catat pembayaran, status berubah |
| `e2e-t6.mjs` | 24 | Denda dan simulasi |
| `e2e-t7.mjs` | 32 | Tunggakan berjenjang |
| `e2e-t9.mjs` | 26 | Isolasi penghuni, log audit |
| `e2e-t18.mjs` | 26 | Fasilitas vs komponen biaya |
| `e2e-t19.mjs` | 23 | Aturan kos, portal penghuni |
| `e2e-t20.mjs` | 35 | Metode pembayaran, verifikasi, pembalik, rekap |
| `e2e-t24.mjs` | 43 | Pengelola, wewenang, menu tersembunyi, rekap vs tunai-saya |
| `e2e-t25.mjs` | 30 | Notifikasi in-app + email |
| `e2e-t26.mjs` | 38 | Usulan, persetujuan, kedaluwarsa |
| `e2e-t27.mjs` | 28 | Aktivasi, lupa sandi, wajib ganti sandi |
| `e2e-t28.mjs` | 39 | PDP: persetujuan, akses, anonimisasi |
| `e2e-r3.mjs` | 50 | Pembatalan tagihan, pemaafan denda, bagikan WhatsApp, rekening |
| `uji-pembalik.mjs` | 13 | Tombol **Tarik kembali**: alasan wajib, baris negatif di riwayat, tombol hilang sesudah ditarik, pembatalan jadi mungkin |
| `uji-qris.mjs` | 15 | Unggah gambar QRIS, pratinjau, gambar termuat sebagai blob di portal penghuni, penolakan pada rekening transfer, rekening nonaktif 404 |
| `asap-semua-halaman.mjs` | 23 | Semua halaman terbuka tanpa galat konsol/5xx |
| `uji-tata-letak.mjs` | 94 | Tidak meluber di 360/390/768px |
| `uji-kartu-tabel.mjs` | 65 | Tabel 4+ kolom jadi kartu; label sesuai `<th>`; desktop kembali normal |
| `uji-pwa.mjs` | 22 | Manifest, service worker, **pagar R-07** |
| `uji-galat-muat.mjs` | 30 | Pemuatan gagal tidak menyamar sebagai "Belum ada" (REV-UI-04) |
| `uji-balapan-t4.mjs` | 10 | 10 permintaan terbit paralel → satu 201, sisanya 409 |
| `uji-balapan-t5.mjs` | 12 | Pembayaran paralel tidak melebihi sisa |

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
| U-6 | Halaman meluber di 360px | 0 | `uji-tata-letak.mjs` | Lulus 94/94 |
| U-7 | Tabel tak terbaca di 360px | 0 | `uji-kartu-tabel.mjs` | Lulus 65/65 |
| U-8 | Kepala pemilik sebelum konten | < 220px | Terukur | 203px |
| U-9 | Cakupan tes PHP | 748 tes | CI | Lulus 3522 asersi |
| U-10 | Pemuatan gagal menyamar sebagai data kosong | 0 layar | `uji-galat-muat.mjs` — **dibuktikan bisa gagal** | Lulus 30/30 |

---

## 7. Yang **tidak** diuji, dan kenapa

Daftar ini lebih penting daripada daftar yang diuji, karena ia menentukan
seberapa besar kepercayaan pada hijau.

| Tidak diuji | Kenapa | Risiko yang diterima |
|---|---|---|
| Midtrans sungguhan | Belum ada akun sandbox | Alur webhook hanya diuji dengan payload tiruan |
| Uji beban | Kos 5-30 kamar; beban tidak relevan | Tidak diketahui perilaku pada 1000 kamar |
| Uji aksesibilitas otomatis (axe) | Waktu | Pelanggaran kontras/ARIA tidak tertangkap mesin |
| Orientasi lanskap ponsel | Waktu | 640×360 belum pernah diukur |
| Uji luring sungguhan | `Network.emulateNetworkConditions` belum dipakai | Kerangka ter-cache, tetapi belum dibuktikan bisa dibuka tanpa jaringan |
| Email sungguhan (Brevo) | Hanya Mailpit | Batas kirim Brevo dan reputasi domain belum diuji |
| E2E di CI | Menggantung 17+ menit di runner, lulus 299 dtk lokal | Dicatat utang teknis; tidak ditebak |
| Tes komponen Vue | Tidak ada | Regresi di dalam komponen hanya tertangkap E2E |

**E2E di CI adalah yang paling perlu diperhatikan.** Ia sudah `continue-on-error`
dan `timeout-minutes: 20` supaya tidak memblokir. Penyebabnya tidak bisa
direproduksi di laptop, dan menebak pada CI lebih mahal daripada manfaatnya.

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
