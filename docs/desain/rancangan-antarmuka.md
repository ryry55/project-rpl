# Rancangan antarmuka — spesifikasi gambar

> **Ini lembar kerja, bukan dokumen yang dibangkitkan.** Berkas ini dipakai untuk
> menggambar keenam layar di alat desain (FigCraft atau Figma), lalu hasilnya
> diekspor ke `docs/desain/`. Tidak ada generator yang membacanya.
>
> **Sebab ia ada:** Charter proyek menjanjikan *"Perancangan arsitektur dan
> antarmuka aplikasi"* (`docs/charter/build-charter.ps1`, milestone 3 dan baris
> biaya "Pemodelan sistem dan perancangan antarmuka"), tetapi repo tidak punya
> artefaknya. Layar yang digambar di sini menutup janji itu.
>
> **Aturan satu-satunya:** tulisan di layar rancangan harus **sama persis** dengan
> tulisan di aplikasi. Label yang dikarang akan terlihat sebagai kebohongan saat
> dosen membuka aplikasinya berdampingan.

## 1. Kanvas

| Hal | Nilai | Sumber |
|---|---|---|
| Ukuran frame | **1440 × 900** (desktop) | dipakai mengukur `max-width` di `style.css` |
| Lebar sidebar | **244 px** | `web/src/style.css` |
| Padding isi halaman | **28 px** kiri-kanan | 56 px total, disebut di `style.css` |
| Lebar isi biasa | **1100 px** | `.halaman` |
| Lebar isi halaman lebar | **1240 px** | `.halaman-lebar` (halaman Tagihan) |
| Jarak antar blok | **16 px** | `gap` pada `.halaman` |
| Tinggi baris tabel | **44 px** | `DESIGN.md` |

Pakai **auto layout** vertikal berjarak 16 px untuk isi halaman. Jangan menaruh
koordinat manual: gambar yang menempel di posisi absolut akan berantakan begitu
satu label berubah panjang.

## 2. Token

Salin ke Styles/Variables di alat desain, **jangan** diketik ulang per kotak.

| Nama | Nilai | Dipakai untuk |
|---|---|---|
| Bidang | `#f8f8f8` | latar halaman |
| Permukaan | `#ffffff` | panel, kartu, sidebar, bilah atas |
| Permukaan turun | `#f2f2f2` | hover tombol sekunder |
| Aktif | `#ececec` | menu aktif, pil netral |
| Hover | `#fafafa` | baris tabel disorot |
| Garis | `#e6e6e6` | tepi kartu, garis bawah kepala tabel |
| Garis rambut | `#f0f0f0` | pemisah antar baris |
| Kendali | `#d4d4d4` | bingkai masukan dan tombol sekunder |
| Tinta | `#0a0a0a` | seluruh teks utama |
| Tinta redup | `#6b6b6b` | label kolom, keterangan |
| Aksen | `#141414` | satu tombol gelap per layar |
| Lunas | `#2e7d32` di atas `#dcfae5` | pil status lunas |
| Perlu tindak | `#92400e` di atas `#fffbeb` | belum bayar / sebagian |
| Terlambat | `#c4242a` di atas `#ffedea` | pil terlambat |
| Data | `#1d5fbf` | bilah kemajuan ringkasan |

Tipografi: **Geist** untuk semua teks, **Geist Mono** untuk nominal rupiah.

| Pangkat | Ukuran | Berat | Dipakai untuk |
|---|---|---|---|
| Display | 24 px / 1.2 / -0.015em | 600 | judul halaman |
| Headline | 16 px | 600 | judul panel |
| Title | 13 px | 600 | sub-bagian |
| Body | 14 px / 20 px | 400 | isi dan sel tabel |
| Label | 12 px | 500 | label medan, kepala tabel |
| Money | 13 px | 400 | semua nominal rupiah |

Sudut: **8 px** panel, **6 px** kendali, **999 px** pil.

## 3. Resep komponen

| Komponen | Ukuran dan gaya |
|---|---|
| Tombol utama | tinggi 36 px, padding 7 × 13 px, isian `#141414`, teks putih, sudut 6 px |
| Tombol sekunder | tinggi 36 px, latar putih, bingkai `#d4d4d4`, teks `#0a0a0a` |
| Masukan | tinggi 36 px, padding 8 × 11 px, latar putih, bingkai `#d4d4d4` |
| Panel | latar putih, bingkai `#e6e6e6`, sudut 8 px, **tanpa bayangan** |
| Pil status | tinggi 22 px, padding 0 × 9 px, sudut 999 px, latar sesuai keadaan |
| Kepala tabel | Label 12 px `#6b6b6b`, bukan huruf besar, garis bawah `#e6e6e6` |
| Menu sidebar | tinggi 34 px, pil penuh saat aktif (`#ececec`) |

Satu layar hanya punya **satu** tombol gelap penuh.

## 4. Enam layar

Isi di bawah ini diambil dari kode, bukan dikarang. Contoh angka boleh diganti;
**tulisan tidak**.

### 4.1 Masuk — `masuk.png`

Kartu di tengah bidang `#f8f8f8`, lebar **380 px**.

- Lencana kecil "KOS", lalu judul **Masuk**
- Keterangan: *Sistem Informasi Operasional Kos-Kosan*
- Medan **Email**, placeholder `nama@kos.test`
- Medan **Kata sandi**
- Tombol utama selebar kartu: **Masuk**
- Tautan teks kecil: **Lupa kata sandi?**

### 4.2 Kamar — `kamar.png`

Sidebar + bilah atas + isi 1100 px.

- Judul **Kamar**, keterangan: `12 kamar · 9 terisi · 2 kosong · 1 diperbaiki`
- Aksi kanan: tombol utama **Tambah kamar**
- Panel panduan penyiapan di atas tabel (tiga langkah, satu tombol tautan)
- Tabel: **Nomor · Tipe · Fasilitas · Status · Penghuni · Tarif terakhir · Aksi**
  - `Status` memakai pil: **terisi**, **kosong**, **dipesan**, **perbaikan**
  - `Tarif terakhir` rata kanan, huruf mono
  - `Aksi` berisi tautan teks kecil: **Atur tarif**, **Fasilitas**, **Laporkan**

### 4.3 Kebijakan kos — `kebijakan.png`

- Judul **Kebijakan kos**, keterangan: *Cara kos ini menagih: satuan sewa dan
  jatuh tempo. Wajib ditetapkan sebelum tagihan bisa terbit.*
- Panel pemilih: label **Berlaku untuk** + dropdown *Semua kamar (default kos)*
- Tiga kartu, satu per dimensi: **Satuan sewa**, **Jatuh tempo**, **Denda**
  - Tiap kartu: label nilai yang berlaku, menu pilihan, tanggal **Berlaku dari**,
    tombol **Simpan**
- Bawah: panel **Riwayat kebijakan**, tabel **Berlaku dari · Jenis · Nilai · Untuk**

### 4.4 Tagihan — `tagihan.png`

Halaman lebar 1240 px.

- Judul **Tagihan**, keterangan:
  `14 tagihan · pokok Rp 21.000.000 · denda Rp 350.000 · belum masuk Rp 4.500.000`
- Aksi kanan: tombol sekunder **Terbitkan semua**, tombol utama **Terbitkan tagihan**
- Tabel sebelas kolom: **Periode · Penghuni · Kamar · Pokok · Denda · Total ·
  Dibayar · Sisa · Jatuh tempo · Status · (aksi)**
  - empat kolom uang rata kanan, huruf mono
  - `Status`: pil **lunas**, **sebagian**, **belum bayar**, **terlambat**
  - aksi: tautan teks **Rincian**

### 4.5 Portal penghuni — `portal-penghuni.png`

Tanpa sidebar pemilik; bilah atas biasa.

- Judul **Tagihan saya**
- Kartu ringkasan (total belum dibayar) di atas tabel
- Tabel: **Periode · Pokok · Denda · Total · Dibayar · Sisa · Jatuh tempo ·
  Status · (aksi)**
- Dua panel di bawah: **Cara membayar** dan **Aturan kos**

### 4.6 Laporan kerusakan — `laporan-kerusakan.png`

Panel di halaman Kamar, bukan halaman sendiri.

- Judul panel: **Laporkan kerusakan kamar A3**
- Keterangan: *Kamar akan ditandai **diperbaiki** sampai laporannya ditutup. Sewa
  tetap berjalan; biaya perbaikan tidak masuk tagihan penghuni.*
- Medan teks: label **Apa yang rusak?**, placeholder
  `Plafon kamar mandi bocor dan merembes ke lemari`
- Tombol utama **Laporkan kerusakan**, tombol sekunder **Batal**

## 5. Ekspor

| Hal | Nilai |
|---|---|
| Berkas gambar | `docs/desain/<nama>.png`, skala **2×** (2880 × 1800) |
| Berkas sumber | `docs/desain/<nama>.figcraft` (atau `.fig`) |
| Satu frame satu berkas | ya — jangan satu kanvas berisi enam frame |

Sebutkan di keterangan gambar bahwa ini **rancangan**, bukan tangkapan layar
aplikasi. Tangkapan layar asli ada di `docs/uji/*.png`.
