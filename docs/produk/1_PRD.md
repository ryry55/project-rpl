# 1. PRD — Sistem Informasi Operasional Kos-Kosan

> Dokumen ini menjawab: **untuk siapa produk ini, masalah apa yang dipecahkan,
> dan bagaimana kami tahu masalahnya benar-benar terpecahkan.**
>
> Yang **tidak** ada di sini: skema database (lihat `5_SCHEMAS.md`), kontrak
> endpoint (`6_TOOLS_CONTRACTS.md`), dan keputusan teknis (lihat
> `3_ARCHITECTURE.md`).

Versi 1.0 · 26 Oktober 2026 · Status: berlaku

---

## 1. Masalah

### 1.1 Tesis

> **"Pain point-nya tetap beda-beda per kos. Ini masalah utamanya."**

Kalimat itu dari pemilik kos yang diwawancarai, dan ia membentuk seluruh
produk ini. Aplikasi kos yang ada di pasaran mengambil satu cara kerja —
umumnya: sewa bulanan, jatuh tempo tanggal 1, denda persentase tetap — lalu
memaksa setiap kos mengikutinya. Kos yang menagih mingguan, yang jatuh temponya
tanggal masuk, yang dendanya nominal tetap, atau yang tidak memakai denda sama
sekali, tidak punya tempat.

Akibatnya pemilik kembali ke buku tulis dan WhatsApp. Bukan karena aplikasinya
sulit, tetapi karena aplikasi itu **menentukan** cara kerja yang bukan cara
kerjanya.

### 1.2 Masalah turunan yang benar-benar menyita waktu

Diurutkan menurut frekuensi keluhan saat wawancara, bukan menurut kemudahan
implementasinya.

| # | Masalah | Yang terjadi sekarang | Frekuensi |
|---|---|---|---|
| M-1 | **Menghitung tagihan bulan pertama** | Penyewa masuk tanggal 17; pemilik menghitung prorata dengan kalkulator, kadang salah, kadang memilih membulatkan ke atas dan menyilangkan jari | setiap penyewa baru |
| M-2 | **Menghitung denda** | Denda 1%/hari dengan plafon 30% dihitung manual per penghuni. Untuk 12 penghuni telat, itu 12 perhitungan di buku | setiap bulan, bagi yang telat |
| M-3 | **Mencatat pembayaran** | Uang masuk lewat transfer, tunai, dan QRIS; dicatat di tiga tempat berbeda (buku, mutasi bank, obrolan) | hampir setiap hari |
| M-4 | **Mengetahui siapa yang menunggak** | Pemilik harus membuka buku dan membandingkan tanggal. Yang telat 40 hari terlihat sama dengan yang telat 3 hari | setiap awal bulan |
| M-5 | **Aturan yang berbeda tiap kamar** | Kamar pojok lebih mahal; kamar lantai 1 kena biaya kebersihan tambahan; kamar dengan AC kena biaya listrik terpisah. Semua ditulis di catatan pribadi | sekali saat kos dibuka, lalu terus diingat |
| M-6 | **Menjawab "sudah saya bayar belum?"** | Penghuni mengirim bukti transfer; pemilik harus mencari baris yang cocok di buku | beberapa kali seminggu |

### 1.3 Yang **bukan** masalah (dan karenanya tidak dikerjakan)

Daftar ini sama pentingnya dengan daftar di atas, karena ia mencegah
penambahan fitur yang tidak menyelesaikan apa pun.

| Bukan masalah | Kenapa tidak dikerjakan |
|---|---|
| Multi-kos untuk satu pemilik | Pemilik yang diwawancarai punya satu kos. Menambah dukungan multi-kos menggandakan setiap layar dan setiap izin, dan tidak ada satu pun keluhan yang meminta itu. Lihat `3_ARCHITECTURE.md` §3.6 untuk alasan penolakan Model C. |
| Pembukuan akuntansi (jurnal, neraca, laba-rugi) | Pemilik bukan akuntan dan tidak memintanya. Yang diminta adalah "berapa yang masuk bulan ini", bukan neraca. |
| Manajemen aset dan inventaris kamar | Di luar cakupan operasional sewa. |
| Chat di dalam aplikasi | Sudah ada WhatsApp, dan pemilik menolak memindahkannya. |
| Prediksi atau analitik | Dengan 5-30 kamar, pemilik sudah tahu angka-angkanya. Yang tidak diketahui adalah angka yang **tepat**. |

---

## 2. Pengguna

Dua peran. Bukan tiga, bukan empat — dan ini keputusan yang pernah berubah.

### 2.1 Pemilik Kos

- **Siapa:** satu orang, pemilik dan pengelola kos, 5-30 kamar.
- **Konteks pemakaian:** ponsel, sambil berdiri, sering di sela pekerjaan lain. Kadang di laptop saat rekap bulanan.
- **Yang ia butuhkan cepat:** *"Siapa yang belum bayar, dan sudah berapa lama?"*
- **Yang ia takutkan:** salah menagih, dan kehilangan catatan pembayaran.
- **Kemampuan teknis:** bisa memakai WhatsApp dan aplikasi bank. Tidak menulis rumus, tidak mengedit JSON, tidak membaca dokumentasi.

### 2.2 Penghuni

- **Siapa:** penyewa kamar, usia 18-30, akrab dengan ponsel.
- **Konteks pemakaian:** sekali saat menerima tagihan, lalu sekali saat membayar.
- **Yang ia butuhkan cepat:** *"Berapa yang harus saya bayar, dan kapan batasnya?"*
- **Yang ia takutkan:** denda yang muncul tanpa ia tahu hitungannya.
- **Akses:** hanya tagihannya sendiri. Ini **batas keras** (R-07), bukan preferensi.

### 2.3 Pengelola — peran ketiga yang **bukan** pengguna baru

Sempat diputuskan bahwa ada peran "Petugas". Peran itu dibatalkan: pada kos
5-30 kamar, "petugas" adalah pemilik juga, atau anggota keluarga yang membantu
sesekali.

Yang tetap dibutuhkan adalah **mendelegasikan sebagian pekerjaan tanpa
menyerahkan seluruh kos**. Karena itu peran `pengelola` adalah akun **milik
pemilik**, dengan wewenang per pekerjaan:

| Wewenang | Artinya |
|---|---|
| `catat_pembayaran` | Mencatat uang masuk |
| `kelola_kamar` | Menambah/mengubah kamar, fasilitas, aturan |
| `kelola_penghuni` | Menambah/mengubah penghuni dan kontrak |
| `terbitkan_tagihan` | Menerbitkan tagihan |
| `verifikasi_pembayaran` | Memeriksa kebenaran pembayaran |
| `baca_audit` | Membaca jejak audit |
| `ubah_tarif` | **Mengusulkan** perubahan tarif (bukan menerapkan) |
| `ubah_kebijakan` | **Mengusulkan** perubahan kebijakan (bukan menerapkan) |

Dua yang terakhir sengaja berbunyi "mengusulkan". Perubahan tarif dan kebijakan
menyentuh uang dan berlaku untuk semua penghuni; pengelola boleh mengajukan,
pemilik yang memutuskan. Usulan kedaluwarsa dalam **7 hari**.

### 2.4 Peta pengguna → masalah

| Masalah | Pemilik | Pengelola | Penghuni |
|---|---|---|---|
| M-1 prorata | ✓ (menerbitkan) | ✓ (jika `terbitkan_tagihan`) | melihat hasilnya |
| M-2 denda | ✓ | — | melihat rinciannya |
| M-3 catat bayar | ✓ | ✓ (jika `catat_pembayaran`) | — |
| M-4 tunggakan | ✓ | — | — |
| M-5 aturan per kamar | ✓ | ✓ (jika `kelola_kamar`) | membaca aturan |
| M-6 "sudah bayar?" | ✓ | ✓ | ✓ (melihat riwayat sendiri) |

---

## 3. Ruang lingkup versi 1

### 3.1 Masuk

| Fitur | Menjawab |
|---|---|
| Setup kebijakan kos (satuan sewa, jatuh tempo, denda) | M-5 — dan **wajib sebelum tagihan pertama** |
| Kamar, tarif per kamar, komponen biaya per kamar | M-5 |
| Fasilitas kamar | M-5 (dicatat, tidak ditagih) |
| Aturan kos | M-5 |
| Penghuni, kontrak, aktivasi akun lewat email | M-1, M-6 |
| Terbit tagihan (satuan + massal) dengan prorata | M-1 |
| Denda otomatis dengan plafon | M-2 |
| Catat pembayaran: tunai, transfer, QRIS, gateway | M-3 |
| Verifikasi pembayaran | M-3 |
| Tunggakan berjenjang 0-30/31-60/>60 | M-4 |
| Rekap pendapatan per metode | M-3 |
| Portal penghuni | M-6 |
| Jejak audit | kepercayaan |
| Notifikasi in-app + email | M-6 |
| Usulan perubahan + persetujuan pemilik | pembagian tugas |
| UU PDP: persetujuan, akses, penghapusan | kewajiban hukum |

### 3.2 Tidak masuk versi 1

| Tidak ada | Alasan | Rencana |
|---|---|---|
| Penerbitan tagihan otomatis (terjadwal) | Butuh cron yang tidak tersedia di Render gratis | Dicatat sebagai utang teknis |
| Deposit | Dulu ada kolomnya, tetapi tidak pernah dicatat sebagai uang masuk, tidak pernah mengurangi tagihan, dan tidak pernah dikembalikan. Pemilik memilih menghapusnya daripada mempertahankan angka yang tidak berakibat | **Dihapus seluruhnya di fase terakhir** |
| ~~Pembayaran pembalik~~ | ~~Belum ada kebutuhan nyata~~ | **Sudah ada:** baris negatif sejak R2, tombol **Tarik kembali** di panel rincian tagihan sejak fase terakhir |
| Antrean email | Render gratis tanpa worker persisten | Utang teknis |
| Multi-kos | Lihat §1.3 | Ditolak, alasannya di SPEC |
| Aplikasi native | PWA sudah bisa dipasang | Dicatat sebagai opsi masa depan (Capacitor) |

---

## 4. Aturan bisnis

Tujuh aturan yang membentuk produk. Masing-masing punya ID yang dipakai di
seluruh dokumen lain, di kode, dan di tes.

| ID | Aturan | Kenapa |
|---|---|---|
| **R-01** | Prorata = `tarif × hari_tinggal / hari_dalam_bulan`, pembulatan setengah ke atas, `hari_tinggal = hari_dalam_bulan − tanggal_masuk + 1` | Menyewa 17-31 di bulan 31 hari = 15 hari, bukan 14. Batas hari ikut dihitung karena penyewa menempati hari itu |
| **R-02** | Satu kontrak hanya boleh punya satu tagihan per periode. Pelanggaran → HTTP 409 | Tagihan ganda adalah kesalahan yang paling sulit diperbaiki setelah uang masuk |
| **R-03** | Denda bawaan 1% dari pokok per hari, plafon 30%, mulai hari ke-8. Plafon tercapai di hari telat ke-37 | 1%/hari adalah kebiasaan pasar; plafon melindungi penghuni dari denda yang melampaui pokok |
| **R-04** | Tunggakan berjenjang `belum_jatuh_tempo` / `0_30` / `31_60` / `lebih_60` | Pemilik perlu tahu bedanya "telat 3 hari" dan "telat 3 bulan" |
| **R-05** | Setup kebijakan wajib sebelum tagihan pertama | Tanpa satuan sewa dan jatuh tempo, tagihan tidak punya arti. Lebih baik menolak daripada menebak |
| **R-06** | Tarif, komponen biaya, dan kebijakan bersifat *append-only*; tagihan menyimpan **salinan** nilai saat terbit | Menaikkan tarif hari ini tidak boleh mengubah tagihan bulan lalu |
| **R-07** | Penghuni hanya bisa melihat datanya sendiri. Tagihan milik orang lain → 404, bukan 403 | 403 memberi tahu bahwa data itu ada. 404 tidak |

**Aturan R-05 dan R-06 saling melengkapi dan keduanya mahal bila dilanggar.**
R-05 mencegah tagihan tanpa dasar; R-06 menjaga tagihan yang sudah terbit tetap
benar selamanya.

---

## 5. Alur utama

### 5.1 Pertama kali membuka aplikasi

```
Masuk sebagai pemilik
  → Dashboard KOSONG, dengan gerbang: "Lengkapi kebijakan kos"
  → Isi 3 hal: satuan sewa, jatuh tempo, denda
  → Gerbang hilang
  → Tambah kamar + tarif
  → Tambah penghuni → akun dibuat, email aktivasi terkirim
  → Buat kontrak
  → Terbitkan tagihan
```

Seeder **sengaja tidak** membuat kebijakan, kamar, atau kontrak. Kos baru
memang kosong, dan gerbangnya harus terlihat di demo — kalau seeder mengisinya,
gerbang yang merupakan fitur justru tidak pernah terbukti ada.

### 5.2 Bulanan

```
Buka Tagihan → "Terbitkan semua"
  → Setiap kontrak aktif diperiksa: sudah ada tagihan periode ini?
  → Yang belum: hitung prorata (bila bulan pertama), denda (bila telat), terbitkan
  → Yang sudah: dilewati, dilaporkan sebagai "sudah ada"
  → Penghuni menerima notifikasi in-app + email
```

### 5.3 Pembayaran

```
Pemilik/pengelola mencatat pembayaran
  → Status tagihan dihitung ulang: belum_bayar → sebagian → lunas
  → Bila tunai, wajib menyebut siapa yang menerima uang
  → Verifikasi dicatat terpisah (append-only); pemeriksaan terakhir yang berlaku
```

### 5.4 Perubahan tarif oleh pengelola

```
Pengelola mengajukan tarif baru
  → 202: usulan dibuat, TIDAK langsung berlaku
  → Pemilik menerima notifikasi
  → Pemilik menyetujui → tarif baru berlaku, DUA baris audit tercatat
     atau menolak dengan alasan
  → Lewat 7 hari tanpa keputusan → usulan kedaluwarsa (dihitung, tidak disimpan)
```

---

## 6. Ukuran keberhasilan

Bukan metrik yang dikarang untuk laporan. Semuanya bisa diperiksa dari aplikasi
atau dari tes.

| # | Ukuran | Target | Cara memeriksa |
|---|---|---|---|
| U-1 | Waktu menerbitkan tagihan sebulan (30 kamar) | < 1 menit | Demo terkontrol |
| U-2 | Kesalahan hitung prorata | 0 | Tes unit `KalkulatorTest`, 22 tes / 160 asersi |
| U-3 | Tagihan ganda per periode | 0 (ditolak 409) | `uji-balapan-t4.mjs`, 10 langkah |
| U-4 | Kebocoran data antar-penghuni | 0 | `IsolasiDanAuditTest`, `e2e-t9.mjs` |
| U-5 | Respons `/api` yang masuk cache PWA | 0 | `uji-pwa.mjs` — dibuktikan bisa gagal |
| U-6 | Halaman yang meluber di 360px | 0 | `uji-tata-letak.mjs` 94 langkah |
| U-7 | Tabel yang tak terbaca di 360px | 0 | `uji-kartu-tabel.mjs` 65 langkah |
| U-8 | Bagian kepala pemilik sebelum konten | < 220px | Terukur 203px |
| U-9 | Cakupan tes PHP | 748 tes, 3522 asersi | CI |

---

## 7. Risiko

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Pemilik tidak mengisi kebijakan | Aplikasi tidak bisa dipakai | Gerbang R-05 + panduan di layar |
| Tarif berubah saat tagihan lama dipertanyakan | Sengketa | R-06 snapshot |
| Penghuni melihat data orang lain | Kepercayaan hancur, pelanggaran UU PDP | R-07, 404 bukan 403, cache PWA tidak menyentuh `/api` |
| Midtrans gagal di tengah pembayaran | Uang tidak tercatat | Webhook idempoten; verifikasi manual tetap ada |
| Render gratis tidur 15 menit | Permintaan pertama lambat ~50 detik | Loading state; dicatat di dokumen operasi |
| Dosen meminta format laporan berbeda | Laporan dirakit ulang | Isi dokumen terpisah dari format |

---

## 8. Yang berubah dari versi sebelumnya, dan kenapa

Dicatat karena keputusan yang berubah tanpa catatan akan diulang.

| Semula | Menjadi | Kenapa |
|---|---|---|
| Peran "Petugas" | Peran "Pengelola" milik pemilik, dengan wewenang | Petugas pada kos 5-30 kamar adalah pemilik juga |
| `parameter_denda` sebagai tabel | Kolom bertipe di `kebijakan_kos` | Dua sumber kebenaran denda = satu pasti salah |
| Kebijakan bebas JSON/formula | Menu tipe + input angka | Pemilik tidak menulis JSON; formulir bebas berarti hasil tak terduga |
| Tabel target tidak pernah dihapus | Append-only + trigger | Riwayat tarif adalah bukti, bukan data kerja |
| Dua layanan (API + SPA) | Satu layanan, SPA di `public/` | Render gratis: dua layanan berarti dua cold start |
| Denda persen saja | Empat bentuk: harian, bulanan, nominal tetap, tanpa denda | Tesis §1.1 — setiap kos berbeda |
