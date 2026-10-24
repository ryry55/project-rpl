# 4. AGENTS — Cara bekerja di repo ini

> Dokumen ini untuk **manusia atau agen yang akan mengubah kode**. Isinya bukan
> penjelasan produk, melainkan hal-hal yang akan membuat Anda merusak sesuatu
> bila tidak tahu.
>
> `AGENTS.md` di akar repo adalah pintu masuk singkat. Dokumen ini versi
> lengkapnya.

Versi 1.0 · 26 Oktober 2026

---

## 1. Tiga perintah yang harus Anda hafal

```powershell
# PowerShell (Windows) - seluruh guardrail
powershell -ExecutionPolicy Bypass -File periksa.ps1

# Git Bash (Linux/macOS/CI) - seluruh guardrail
bash periksa.sh

# Hanya uji browser (jauh lebih cepat saat mengubah frontend)
bash periksa.sh --hanya-e2e
```

**Pakai `periksa`, jangan menjalankan tes satu per satu.** Urutan langkahnya
menentukan hasil. Salah urutan menghasilkan hijau yang menyesatkan — contoh
nyata: pembatas login menyimpan hitungannya di cache, dan `migrate:fresh` tidak
membersihkannya, sehingga kegagalan login yang disengaja satu skrip membuat
skrip berikutnya menerima 429 pada login yang **benar**. `periksa` menjalankan
`cache:clear` di antara skrip; menjalankan manual tidak.

---

## 2. Siklus kerja

```bash
# 1. Ubah kode
# 2. Bangun ulang image - kode DIBAKE ke dalam image, tidak di-bind-mount
docker compose build api web && docker compose up -d api web

# 3. PENTING: setelah container api dibuat ulang, nginx memegang IP upstream lama
docker compose restart api-web

# 4. Periksa
bash periksa.sh --hanya-e2e

# 5. Commit
```

### 2.1 Jebakan yang paling sering memakan waktu

| Gejala | Sebab | Obat |
|---|---|---|
| Semua `/api` → 502 | Container `api` dibuat ulang, `api-web` memegang IP lama | `docker compose restart api-web` |
| Perubahan Vue tidak terlihat | Image tidak dibangun ulang | `docker compose build web && docker compose up -d web` |
| Uji lulus tapi aplikasi salah | Chrome menyajikan bundel lama dari cache | `_cdp.mjs` sudah mematikan cache; kalau menjalankan manual, matikan sendiri |
| Login 429 pada kredensial benar | Cache pembatas tidak dibersihkan | `docker compose exec -T api php artisan cache:clear` |
| `bash` tidak bisa menghubungi localhost | `bash` dari PowerShell = WSL, namespace jaringan berbeda | Pakai `C:\Program Files\Git\bin\bash.exe` |

---

## 3. Di mana menaruh kode

| Anda menulis… | Taruh di | Jangan taruh di |
|---|---|---|
| Aturan bisnis (hitung, putuskan, terbitkan) | `app/Domain/<Masalah>/` | Controller, Model |
| Terjemahan HTTP | `app/Http/Controllers/Api/` | Domain |
| Validasi | `app/Http/Requests/Api/` | Controller |
| Bentuk JSON | `app/Http/Resources/` | Controller |
| Bentuk data, relasi, scope | `app/Models/` | Domain |
| Gaya yang dipakai > 1 halaman | `web/src/style.css` | `<style scoped>` |
| Gaya khas satu halaman | `<style scoped>` di view-nya | `style.css` |
| Komponen yang dipakai > 1 halaman | `web/src/komponen/ui/` | `views/` |
| Uji browser | `docs/uji/` | `api/tests/` |

**Aturan yang tidak boleh dilanggar: tidak ada perhitungan di controller, tidak
ada aturan bisnis di model.** Kalau sebuah aturan dibutuhkan dua tempat, ia naik
ke `Domain/`.

---

## 4. Hal yang akan mematahkan uji

### 4.1 Nama kelas dan selektor

Selektor ini dipakai skrip E2E dan **tidak boleh diganti namanya**:

```
.tabel  .lencana-status  .aksi-kepala  .sel-kosong  .pesan-sukses
.galat-bidang  .kisi-fasilitas  .pilihan-fasilitas  section.aturan
.simulasi-panel  .panel-massal  .hasil-massal  .rincian-massal
.blok-bayar  .form-bayar  .tabel-riwayat  .denda-uraian  .snapshot
.kartu.panel  nav a  .lonceng  .panel-lonceng  .satu-lonceng
.tabel-notifikasi  .pilihan-kanal  .nama-peristiwa  .jumlah  li.belum
.satu-usulan  .aksi-usulan  .lencana-tipe  .lencana-akun  .lencana-versi
.tautan-data  .pesan-tautan  .daftar-data  .isi-pdp  .kartu-metode
.kisi-metode  .panel-piutang  .tabel-tunai  .tabel-pembayaran
```

Id yang dipakai: `#email`, `#password`, `#periode-massal`, `#kontrak`,
`#periode`, `#bayar-*`, `#rekap-dari`, `#rekap-sampai`, `#nilai-kebijakan`,
`#berlaku-kebijakan`, `#nama-fasilitas`, `#judul-aturan`, `#isi-aturan`,
`#daftar-kategori`, `#nama-pengelola`, `#email-pengelola`, `#nilai-tarif`,
`#berlaku-tarif`, `#saring-usulan`, `#alasan-{id}`, `#email-lupa`,
`#password-lama`, `#password-baru`, `#password-ulang`, `#konfirmasi-hapus`.

`PanelKartu` harus tetap `<section class="kartu panel">`.

### 4.2 `data-label` tidak ditulis tangan

Jangan menambahkan `data-label` ke `<td>` secara manual. Direktif
`v-kartu-tabel` menurunkannya dari `<th>`. Menulis manual membuat dua sumber
kebenaran yang akan melenceng.

**Setiap `<table class="tabel">` baru wajib diberi `v-kartu-tabel`.**
`web/skrip/pasang-kartu-tabel.mjs` bisa dipakai untuk memasangnya sekaligus.

### 4.3 Skrip `.mjs` dan `.ps1` harus ASCII

**Backtick di dalam komentar pun memutus template literal.** Seluruh pemeriksaan
DOM dikirim sebagai template literal; satu backtick di komentar membuat
`node --check` gagal dengan pesan yang menunjuk baris yang salah.

```bash
node --check docs/uji/skrip-baru.mjs   # selalu, sebelum menjalankan
```

`periksa.ps1` memeriksa semua `.mjs` lebih dulu, jadi pelanggaran ini tertangkap
di awal — tetapi lebih baik tidak membuatnya.

### 4.4 Paritas daftar uji

`periksa.ps1` dan `periksa.sh` punya daftar skrip yang **harus sama**. CI
memeriksanya dengan grep. Skrip yang hanya didaftarkan di salah satunya akan
lolos dari guardrail selamanya tanpa ada yang memberi tahu.

Saat menambah skrip uji: **tambahkan di kedua berkas.**

### 4.5 Uji harus bisa dijalankan berulang

Jangan memakai nilai tetap yang unik di database (`nomor: 'KT-1'`, email tetap).
Jalan kedua akan gagal pada unique index, dan kegagalan itu terbaca seolah-olah
fiturnya rusak. Pakai cap waktu:

```js
const cap = Date.now().toString(36).slice(-4).toUpperCase()
nomor: `KT-${cap}`
email: `uji-${cap}@kos.test`
```

---

## 5. Menulis tes

### 5.1 Tes PHP

```bash
docker compose exec -T api php artisan test
docker compose exec -T api php artisan test --filter=NamaTest
```

- Pakai `RefreshDatabase`.
- **PostgreSQL membatalkan seluruh transaksi setelah satu statement gagal.**
  Bungkus panggilan yang diharapkan gagal dalam `DB::transaction(...)` sebelum
  memeriksa `QueryException`.
- `DB::afterCommit()` **tidak pernah terpanggil** di bawah `RefreshDatabase`.
  Notifikasi karena itu dikirim di batas HTTP, bukan lewat `afterCommit`.
- Untuk tes `/health` yang mengembalikan 503, mock `Schema::hasTable`.

### 5.2 Tes browser

```bash
node docs/uji/e2e-t3.mjs
```

Pola wajib:

```js
import { APP, mulaiUji, masukSebagai, tungguSampai, kirim, jalankan, lewatiPersetujuanPdp } from './_cdp.mjs'

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji
  try {
    await masukSebagai(ws, 'pemilik@kos.test')
    await lewatiPersetujuanPdp(ws)   // akun baru mendarat di gerbang PDP sekali
    // ...
    lapor('sesuatu benar', hasil)
  } finally {
    await uji.selesai()
  }
})
```

**Jangan menuliskan nama dari seeder.** Baca dari `/me`. Nama pemilik adalah
"Yukie Wirapratama Agusti" hari ini, dan akan berubah.

### 5.3 Menunggu yang benar

```js
// SALAH - "Memuat tagihan…" juga cocok dengan `tbody tr`
await tungguSampai(ws, `document.querySelectorAll('.tabel tbody tr').length > 0`)

// BENAR - lencana status hanya ada pada baris sungguhan
await tungguSampai(ws, `document.querySelector('.tabel tbody tr .lencana-status')`)
```

**Aturan umum: tunggu elemen yang membuktikan data sudah tiba, bukan elemen
pertama yang muncul.** Penanda memuat selalu lebih dulu ada.

### 5.4 Membuktikan uji bisa gagal

Uji yang tidak pernah terbukti bisa gagal bukan uji. Untuk pagar keamanan
(R-07, isolasi, append-only), **cabut pagarnya sementara dan pastikan ujinya
merah.**

Contoh yang sudah dilakukan: blok `/api` di `sw.js` dicabut, `uji-pwa.mjs`
melaporkan `bocor: /api/me`, pagar dikembalikan. Tanpa langkah ini, asersi
"tidak ada /api di cache" juga lulus bila cache-nya kosong — dan tidak
membuktikan apa pun.

---

## 6. Gaya penulisan

### 6.1 Komentar menjelaskan **mengapa**, bukan **apa**

```php
// BURUK
// Mengambil tarif yang berlaku
$tarif = $this->pencari->cari($kamar, $tanggal);

// BAIK
// Tarif dicari pada tanggal tagihan, bukan tanggal hari ini. Tagihan yang
// diterbitkan mundur untuk bulan lalu harus memakai tarif yang berlaku saat
// itu - kalau tidak, laporan lama berubah setiap kali tarif naik.
$tarif = $this->pencari->cari($kamar, $tanggal);
```

Komentar yang baik menjawab pertanyaan yang akan muncul **enam bulan lagi saat
seseorang ingin mengubah baris ini**.

### 6.2 Bahasa Indonesia

Seluruh narasi, komentar, nama variabel domain, dan pesan galat memakai Bahasa
Indonesia. Nama teknis yang memang berbahasa Inggris (`Controller`, `Request`,
`Resource`, `scope`) tetap.

Istilah domain memakai istilah yang dipakai pemilik kos: `tagihan`, `tunggakan`,
`penghuni`, `kamar`, `kontrak`, `denda`, `kebijakan`.

### 6.3 Pesan galat harus bisa ditindaklanjuti

```php
// BURUK
'Data tidak valid'

// BAIK
'Plafon denda harus lebih dari 0. Denda 1% per hari dengan plafon 0 berarti
 tidak ada denda yang pernah berlaku.'
```

Pesan galat adalah satu-satunya tempat aplikasi berbicara langsung kepada
pengguna yang sedang bingung.

---

## 7. Dokumentasi

| Berkas | Kapan diperbarui |
|---|---|
| `docs/utang-teknis.md` | Setiap kali menunda sesuatu. **Daftar yang tidak dicatat akan terlupakan** |
| `docs/uji/README.md` | Setiap menambah skrip uji |
| `docs/ci-cd.md` | Setiap mengubah workflow |
| `SPEC.md` | Setiap mengubah aturan bisnis |
| `docs/produk/*.md` | Setiap mengubah arsitektur atau design system |

**Saat menunda sesuatu, catat tiga hal:** apa yang kurang, kenapa ditunda, dan
apa rencana penutupannya. Dua yang pertama saja tidak cukup — tanpa rencana,
"ditunda" berarti "tidak akan pernah".

---

## 8. Yang tidak boleh dilakukan

| Jangan | Kenapa |
|---|---|
| Menambah `overflow-x: hidden` pada `.tabel-bungkus` | Menyembunyikan kolom, bukan membuatnya muat |
| Mengganti `<table>` menjadi `<div>` | Mematahkan selektor uji **dan** pembaca layar sekaligus |
| Menghapus cincin fokus tanpa pengganti | Membuat aplikasi tidak bisa dipakai dengan papan ketik |
| Menyimpan respons `/api` di service worker | Kebocoran data antar-pengguna (R-07) |
| Memakai `float` untuk uang | Selisih satu rupiah yang tidak bisa dijelaskan |
| `UPDATE` pada tabel append-only | Trigger menolak; kalau lolos, riwayat hilang |
| Menambah kata sandi di form pembuatan akun | Akun dibuat tanpa sandi; aktivasi = menetapkan sendiri |
| Menjalankan `migrate:fresh` di produksi | Entrypoint menolaknya; seeder memperingatkan |
| Menulis nama seeder di skrip uji | Akan gagal setiap data contoh berubah |
| Menambah uji hanya di `periksa.sh` | Paritas dengan `periksa.ps1` diperiksa CI |

---

## 9. Kalau tersangkut

1. **Baca `docs/utang-teknis.md`.** Kemungkinan besar sudah pernah tersangkut di sini.
2. **Jalankan `bash periksa.sh --hanya-e2e`.** Kalau hijau, masalahnya di tempat lain.
3. **Periksa `docker compose ps`.** Container sehat?
4. **`docker compose restart api-web`.** Kalau `/api` 502, ini jawabannya.
5. **`docker compose exec -T api php artisan cache:clear`.** Kalau ada 429 aneh.
6. **Cek cache Chrome.** Kalau perubahan tidak terlihat, `_cdp.mjs` sudah
   mematikannya — tetapi jendela yang Anda buka manual tidak.

**Jangan menebak pada pipa CI.** E2E yang menggantung di runner sudah dicatat
sebagai utang teknis justru karena penyebabnya tidak bisa direproduksi di
laptop. Menebak pada CI lebih mahal daripada manfaatnya — dan pipeline yang
merah karena hal yang belum dipahami akan diabaikan orang.
