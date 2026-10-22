# DFD — Sistem Informasi Operasional Kos-Kosan

Notasi **Gane-Sarson** (versi utama) dan **Yourdon/DeMarco** (versi pembanding). Seluruh gambar
dihasilkan dari skrip, sehingga bisa diubah dan dibangun ulang kapan saja. Kedua versi memakai
data, proses, penyimpanan, dan aliran yang **identik** — hanya simbol dan konvensi penomoran
yang berbeda.

| Unsur | Gane-Sarson (`build.js`) | Yourdon / DeMarco (`build-yourdon.js`) |
|---|---|---|
| Entitas eksternal | kotak | kotak (sama) |
| Proses | persegi sudut tumpul | **lingkaran** |
| Penyimpanan data | kotak terbuka di kanan | **dua garis sejajar** |
| Nomor proses | `1.0`, `2.0`, … | `1`, `2`, … |
| Nomor konteks | `0.0` | `0` |

## Berkas

| Berkas | Isi |
|---|---|
| `build.js` | Sumber editable gambar notasi Gane-Sarson |
| `build-yourdon.js` | Sumber editable gambar notasi Yourdon/DeMarco |
| `01-dfd-konteks.svg` / `.png` | DFD Konteks / DFD Nol (Level 0) — Gane-Sarson |
| `02-dfd-level-1.svg` / `.png` | DFD Level 1 — Gane-Sarson |
| `01-dfd-konteks-yourdon.svg` / `.png` | DFD Konteks / DFD Nol (Level 0) — Yourdon/DeMarco |
| `02-dfd-level-1-yourdon.svg` / `.png` | DFD Level 1 — Yourdon/DeMarco |
| `03-dfd-level-2.md` | Dekomposisi proses 3.0 dan 4.0 |
| `export-png.ps1` | Ekspor semua SVG ke PNG resolusi cetak (2x) |
| `README.md` | Berkas ini — tabel aliran data & kamus data |

## Cara membangun ulang

```powershell
cd docs/dfd
node build.js
node build-yourdon.js
powershell -ExecutionPolicy Bypass -File export-png.ps1
```

Kedua skrip build sekaligus memeriksa bahwa tidak ada garis aliran yang menembus kotak
entitas/proses/penyimpanan data (pada versi Yourdon, pemeriksaan juga mencakup lingkaran
proses). Bila ada, proses build gagal dan menyebutkan aliran mana yang bermasalah.

---

## 1. DFD Konteks (DFD Nol / Level 0)

Proses tunggal **0.0 Sistem Informasi Operasional Kos-Kosan** menghubungkan
**2 entitas eksternal** melalui **10 aliran data utama** (5 masuk, 5 keluar).

### Aliran data level 0

| Kode | Arah | Aliran | Struktur data |
|---|---|---|---|
| I-1 | Pemilik → Sistem | data kamar & tarif sewa | nomor_kamar, tipe, tarif_dasar, status_huni |
| I-2 | Pemilik → Sistem | komponen biaya & parameter denda | jenis_biaya, nominal, tarif_denda_persen, plafon_persen, masa_tenggang |
| I-3 | Pemilik → Sistem | konfirmasi setoran uang | tagihan_id, tanggal, jumlah, metode |
| I-4 | Penghuni → Sistem | pendaftaran sewa | nama, no_hp, kamar_id, tgl_masuk, durasi |
| I-5 | Penghuni → Sistem | bukti pembayaran | tagihan_id, tanggal, jumlah, metode, bukti |
| O-1 | Sistem → Pemilik | rekap pendapatan & hunian | periode, total_pendapatan, occupancy_rate |
| O-2 | Sistem → Pemilik | daftar tunggakan & peringatan | daftar_tunggakan[], kategori_umur, peringatan[] |
| O-3 | Sistem → Penghuni | konfirmasi sewa & kontrak | kontrak_id, kamar, tgl_mulai, tarif |
| O-4 | Sistem → Penghuni | tagihan bulanan + denda | periode, rincian[], pokok, denda, total, jatuh_tempo |
| O-5 | Sistem → Penghuni | kuitansi & status pelunasan | pembayaran_id, tanggal, jumlah, sisa_tunggakan |

Pada level ini belum ada penyimpanan data — itu muncul di level 1.

---

## 2. DFD Level 1

**5 proses**, **6 penyimpanan data**, **2 entitas eksternal**, **23 aliran data**.

### 2.1 Proses

| Proses | Nama | Masukan | Keluaran | Store | Aturan bisnis |
|---|---|---|---|---|---|
| **1.0** | Kelola Kamar & Penghuni | data kamar, pendaftaran sewa | data kamar, kontrak sewa, konfirmasi sewa | D1, D2 | — |
| **2.0** | Kelola Tarif, Biaya Tambahan & Parameter Denda | komponen biaya, parameter denda | tarif & komponen | D3 | R-06 |
| **3.0** | Terbitkan Tagihan Bulanan | kontrak aktif, tarif berlaku | tagihan, tagihan bulanan + denda | D4 | **R-01, R-02** |
| **4.0** | Catat Pembayaran, Denda & Tunggakan | bukti pembayaran, konfirmasi setoran, tagihan belum lunas | kuitansi & status pelunasan, pembayaran, log audit | D4, D5, D6 | **R-03, R-04, R-05** |
| **5.0** | Laporan & Rekap | data kamar, kontrak, tagihan, pembayaran | rekap pendapatan, hunian & daftar tunggakan | baca D1, D2, D4, D5 | R-07 |

### 2.2 Penyimpanan data

| Store | Nama | Atribut kunci |
|---|---|---|
| **D1** | Kamar | id, nomor, tipe, tarif_dasar, status_huni |
| **D2** | Penghuni & Kontrak | id, nama, no_hp, kamar_id, tgl_masuk, tgl_keluar, deposit |
| **D3** | Tarif & Komponen | id, jenis, nilai, satuan, berlaku_mulai |
| **D4** | Tagihan | id, kontrak_id, periode, rincian, pokok, denda, total, jatuh_tempo, status |
| **D5** | Pembayaran | id, tagihan_id, tanggal, jumlah, metode, bukti |
| **D6** | Log Audit | id, aktor, aksi, entitas, waktu, nilai_lama, nilai_baru |

### 2.3 Aliran data level 1

**Entitas → proses**

| # | Asal | Tujuan | Aliran | Struktur |
|---|---|---|---|---|
| 1 | Pemilik | 1.0 | data kamar & tarif sewa | nomor_kamar, tipe, tarif_dasar |
| 2 | Pemilik | 2.0 | komponen biaya & parameter denda | jenis, nominal, tarif_denda_persen, plafon, tenggang |
| 3 | Pemilik | 4.0 | konfirmasi setoran uang | tagihan_id, tanggal, jumlah |
| 4 | Penghuni | 1.0 | pendaftaran sewa | nama, no_hp, kamar_id, tgl_masuk |
| 5 | Penghuni | 4.0 | bukti pembayaran | tagihan_id, tanggal, jumlah, bukti |

**Proses → entitas**

| # | Asal | Tujuan | Aliran | Struktur |
|---|---|---|---|---|
| 6 | 1.0 | Penghuni | konfirmasi sewa & kontrak | kontrak_id, kamar, tgl_mulai, tarif |
| 7 | 3.0 | Penghuni | tagihan bulanan + denda | periode, rincian[], total, jatuh_tempo |
| 8 | 4.0 | Penghuni | kuitansi & status pelunasan | pembayaran_id, jumlah, sisa_tunggakan |
| 9 | 5.0 | Pemilik | rekap pendapatan, hunian & daftar tunggakan | periode, pendapatan, occupancy, tunggakan[] |

**Proses → penyimpanan**

| # | Asal | Tujuan | Aliran | Struktur |
|---|---|---|---|---|
| 10 | 1.0 | D1 | data kamar | nomor, tipe, tarif_dasar, status |
| 11 | 1.0 | D2 | kontrak sewa | kontrak_id, penghuni, kamar, tgl_masuk |
| 12 | 2.0 | D3 | tarif & komponen | jenis, nilai, satuan, berlaku_mulai |
| 13 | 3.0 | D4 | tagihan | periode, rincian, pokok, total, jatuh_tempo |
| 14 | 4.0 | D5 | pembayaran | tagihan_id, tanggal, jumlah, metode |
| 15 | 4.0 | D6 | log audit | aktor, aksi, entitas, waktu, nilai |

**Penyimpanan → proses**

| # | Asal | Tujuan | Aliran | Struktur |
|---|---|---|---|---|
| 16 | D2 | 3.0 | kontrak aktif | kontrak_id, kamar, tgl_masuk, tarif |
| 17 | D3 | 3.0 | tarif berlaku | jenis, nilai, berlaku_mulai |
| 18 | D4 | 4.0 | tagihan belum lunas | tagihan_id, total, jatuh_tempo, status |

**Penyimpanan → proses 5.0**

| # | Asal | Tujuan | Aliran | Struktur |
|---|---|---|---|---|
| 19 | D1 | 5.0 | data kamar | nomor, status_huni |
| 20 | D2 | 5.0 | kontrak | kamar, penghuni, periode |
| 21 | D4 | 5.0 | tagihan | periode, pokok, denda, status |
| 22 | D5 | 5.0 | pembayaran | tanggal, jumlah |

> Jumlah pada catatan kaki gambar (23) menghitung aliran 9 sebagai satu berkas
> gabungan tiga store; tabel di atas memecahnya agar tiap struktur data terlihat.
> Total baris tabel = 22 aliran antar-komponen + 1 aliran gabungan ke Pemilik.

---

## 3. Kamus data

```
kamar           = nomor_kamar + tipe + tarif_dasar + status_huni
status_huni     = "kosong" | "terisi" | "perbaikan"

penghuni        = nama + no_hp + email
kontrak         = kontrak_id + penghuni + kamar + tgl_masuk + tgl_keluar + deposit
tgl_masuk       = tanggal
tgl_keluar      = tanggal | "aktif"

komponen_biaya  = jenis + nilai + satuan + berlaku_mulai
jenis           = "sewa" | "kebersihan" | "parkir" | "ac" | "laundry"
satuan          = "rupiah/bulan" | "rupiah/orang"

parameter_denda = tarif_denda_persen + plafon_persen + masa_tenggang
tarif_denda_persen = persen per hari (1..100), dihitung dari pokok
plafon_persen   = 1..100
masa_tenggang   = jumlah_hari

tagihan         = tagihan_id + kontrak_id + periode + rincian + pokok + denda
                + total + jatuh_tempo + status_tagihan
periode         = bulan + tahun
rincian         = { nama_komponen + nilai } +
pokok           = jumlah(rincian.nilai) + sewa_prorata
sewa_prorata    = bulat(tarif_dasar * hari_tinggal / hari_dalam_bulan)
hari_denda      = max(0, selisih - masa_tenggang)
denda           = 0                                   jika selisih <= masa_tenggang
                = min(bulat(pokok * hari_denda * tarif_denda_persen / 100),
                      bulat(pokok * plafon_persen / 100))  jika selisih > masa_tenggang
selisih         = hari_ini - jatuh_tempo (selisih tanggal kalender, zona Asia/Jakarta)
total           = pokok + denda
status_tagihan  = "belum_bayar" | "sebagian" | "lunas" | "dibatalkan"

pembayaran      = pembayaran_id + tagihan_id + tanggal + jumlah + metode + bukti
metode          = "tunai" | "transfer"

log_audit       = aktor + aksi + entitas + waktu + nilai_lama + nilai_baru

kategori_umur   = "0-30" | "31-60" | ">60"
```

---

## 4. Aturan bisnis di balik diagram

| Kode | Aturan | Proses | Titik batas untuk pengujian |
|---|---|---|---|
| **R-01** | Sewa bulan pertama dihitung prorata: `tarif × hari_tinggal / hari_dalam_bulan` | 3.0 | hari 1, 15, 28 (Feb), 29 (kabisat), 31 |
| **R-02** | Satu kontrak hanya boleh punya satu tagihan per periode; terbit ulang ditolak | 3.0 | periode sama / berbeda |
| **R-03** | Denda mulai hari ke-8 setelah jatuh tempo, **1% dari pokok per hari**, plafon 30% dari pokok | 4.0 | selisih hari 7 / 8, hari ke-37 (plafon tercapai), hari setelah plafon |
| **R-04** | Tunggakan dikelompokkan 0–30 / 31–60 / >60 hari | 4.0 | hari 30 / 31, dan 60 / 61 |
| **R-05** | Deposit dipotong hanya bila kerusakan tercatat; sisanya wajib dikembalikan | 4.0 | ada / tanpa catatan kerusakan |
| **R-06** | Tarif tidak berlaku surut — perubahan hanya untuk periode berikutnya | 2.0 | transaksi sebelum / sesudah perubahan |
| **R-07** | Penghuni hanya melihat tagihannya sendiri; setiap perubahan status tercatat | 4.0, 5.0 | lintas akun |

---

## 5. Catatan desain untuk presentasi

Tiga hal yang layak dijelaskan bila ditanya "kenapa begitu":

1. **Mengapa hanya 2 entitas?** Kos 5–30 kamar dikelola pemilik sendiri. Memisahkan
   "Petugas" sebagai entitas hanya akan menambah simbol tanpa menambah kenyataan.

2. **Mengapa tidak ada proses pencatatan meter?** Sistem menyasar kos dengan sewa
   all-in. Sub-metering utilitas sengaja diletakkan di daftar *tidak dikerjakan*,
   bukan karena keterbatasan waktu, tetapi karena segmen yang disasar tidak
   memisahkan biaya listrik dan air.

3. **Mengapa tiga aturan menumpuk di proses 4.0?** Denda, klasifikasi umur
   tunggakan, dan penjagaan status adalah satu medan yang sama: uang yang belum
   masuk. Memecahnya ke proses berbeda akan memaksa data tagihan berpindah
   bolak-balik tanpa manfaat.

---

## 6. Catatan pemilihan notasi

Dua versi disediakan supaya bisa menyesuaikan dengan kebiasaan dosen pengampu atau
contoh di modul praktikum. Isinya sama, jadi **pakai salah satu** — jangan keduanya
di satu laporan, karena akan terlihat seperti dua gambar yang saling bertentangan.

| Aspek | Gane-Sarson | Yourdon / DeMarco |
|---|---|---|
| Simbol proses | persegi sudut tumpul | lingkaran |
| Simbol penyimpanan data | kotak terbuka di sisi kanan | dua garis sejajar |
| Kelebihan | ruang teks lebih lega, cocok untuk nama proses panjang | bentuk proses paling mudah dibedakan dari entitas saat diproyeksikan |
| Kekurangan | proses dan penyimpanan sama-sama kotak, sekilas mirip | nama proses panjang harus disingkat agar muat di lingkaran |

Konsekuensi praktis: pada versi Yourdon, nama proses di dalam lingkaran dibuat
ringkas (`Terbitkan Tagihan Bulanan` menjadi `Terbitkan / Tagihan / Bulanan`).
Nama lengkap tetap ditulis pada baris keterangan di bawah gambar dan pada tabel
proses di bagian 2.1, sehingga tidak ada informasi yang hilang.

Nomor proses juga mengikuti konvensi masing-masing notasi: `1.0`–`5.0` pada
Gane-Sarson, `1`–`5` pada Yourdon. Dekomposisi level 2 di `03-dfd-level-2.md`
memakai penomoran Gane-Sarson (`3.1`, `3.2`, …); bila memakai versi Yourdon,
baca sebagai `3.1`, `3.2`, … dengan proses induk `3`.
