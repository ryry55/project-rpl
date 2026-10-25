# Proses Bisnis & Spesifikasi Pemodelan Sistem Informasi Operasional Kos-Kosan

> **Dibangkitkan, jangan disunting tangan** — `node docs/akademik/build-dokumen.js`.
> Angka dibaca dari `rute-api.json` (80 endpoint), `tabel-domain.json` (23 tabel),
> `usecase-data.js` (14 use case), `api/composer.lock` (Laravel 12.70.1),
> dan `docs/dfd/dfd-data.js` (14 aliran batas).


## 1. Ringkasan Eksekutif Sistem

**Sistem Informasi Operasional Kos-Kosan** mengelola operasional kos — kamar,
penghuni, kontrak sewa, tarif dan kebijakan, tagihan bulanan, pembayaran,
sampai laporan pendapatan dan tunggakan — sebagai API Laravel 12.70.1 +
PostgreSQL dengan antarmuka Vue 3.

Pembedanya dari pencatatan manual bukan kelengkapan fitur, melainkan **siapa
yang berhak melakukan apa**: **pemilik** memutuskan, **pengelola** berwenang
terbatas mengusulkan, **penghuni** hanya membaca. Pengelola ber-wewenang
`ubah_tarif` pun tidak menetapkan tarif. Uang dicatat sebagai peristiwa yang
tidak bisa diubah. Cakupan: **80 endpoint** `/api`, **14 use case**, **3
aktor**, **23 tabel domain** dari **41 migrasi**.

## 2. Aktor dan Entitas Eksternal Sistem

**3 aktor**, dan ketiganya juga entitas eksternal pada DFD Konteks. Semua
masuk lewat `POST /api/login`; pembedanya gerbang per endpoint — `peran:`
memeriksa jenis akun, `wewenang:` memeriksa izin yang didelegasikan.

1. **Pemilik Kos:** Pemilik akun dan pemegang keputusan: menetapkan tarif, kebijakan, dan
   rekening tujuan setoran, serta memaafkan denda.
2. **Pengelola:** Akun buatan pemilik dengan wewenang terbatas: mencatat pembayaran tunai,
   menerbitkan tagihan, mengusulkan perubahan tarif atau kebijakan.
3. **Penghuni:** Penerima informasi dan pembayar, bukan pemasok data; portalnya baca-saja
   kecuali membuka tautan bayar daring.

## 3. Alur Transaksi Utama

Satu siklus bulanan; setiap langkah menunjuk endpoint nyata pada `api/routes/api.php`.

1. **Registrasi dan autentikasi.** Ketiga peran masuk lewat `POST /api/login`;
   akun dibuat pemilik atau pengelola lalu diaktifkan lewat surel, dan sebelum
   aktivasi login ditolak 403.
2. **Penyiapan data induk.** Pemilik atau pengelola ber-wewenang
   `kelola_kamar` dan `kelola_penghuni` mendaftarkan kamar, fasilitas, aturan
   kos, dan identitas penghuni.
3. **Penetapan angka uang.** Pemilik menetapkan tarif, komponen biaya, dan
   tiga dimensi kebijakan. Bila pengelola ber-wewenang `ubah_tarif` yang
   menekan tombol, sistem menyimpan **usulan** dan membalas 202.
4. **Pembuatan kontrak sewa.** Kontrak menunjuk satu kamar kosong dan satu
   penghuni; indeks parsial menolak kontrak aktif kedua pada kamar yang sama.
5. **Penerbitan tagihan bulanan.** Sistem menghitung sewa prorata (R-01),
   membekukan tarif dan kebijakan (R-06), lalu menolak bila periode itu sudah
   punya tagihan hidup (R-02) atau kebijakan belum lengkap (R-05).
6. **Pembayaran dan verifikasi.** Uang yang diterima dicatat beserta tanggal,
   metode, dan penerimanya; status tagihan menjadi `sebagian` atau `lunas`.
   Akun yang mencatat tidak boleh memverifikasi catatannya sendiri.
7. **Pemantauan dan pelaporan.** Pemilik membaca rekap pendapatan; pengelola
   membaca tunggakan menurut umur piutang (R-04) dan, bila punya `baca_audit`,
   jejak audit. Penghuni melihat tagihannya sendiri (R-07).
8. **Penutupan periode.** Tagihan salah terbit dibatalkan beralasan sehingga
   periodenya bebas diterbitkan ulang; denda yang dilepas pemilik dicatat
   sebagai pemaafan.

## 4. Pemodelan Berorientasi Objek (UML Use Case Diagram)

Batas sistem mencakup seluruh fungsionalitas aplikasi operasional kos: **3
aktor**, **14 use case** — jumlah final, diturunkan dari 80 endpoint nyata
yang terpetakan habis tanpa sisa dan tanpa satu pun dipakai dua use case.

### 4.1 Deskripsi 14 Use Cases Fungsional

1. **UC-01 Registrasi & Login Akun:** Autentikasi ketiga peran: login,
   pemulihan sandi lewat surel, dan penggantian sandi wajib.
2. **UC-02 Kelola Kamar, Fasilitas & Aturan Kos:** Mendaftarkan kamar,
   fasilitas tiap kamar, dan aturan kos yang dibaca penghuni.
3. **UC-03 Kelola Data Penghuni & Kontrak Sewa:** Mendaftarkan penghuni,
   membuat kontrak atas satu kamar kosong, dan mengakhiri kontrak.
4. **UC-04 Kelola Tarif Sewa & Komponen Biaya:** Menetapkan tarif per kamar
   dan komponen biaya; keduanya tambah-saja, riwayat tidak tertimpa.
5. **UC-05 Kelola Kebijakan Kos:** Menetapkan tiga dimensi kebijakan kos —
   satuan sewa, jatuh tempo, denda.
6. **UC-06 Persetujuan Usulan Perubahan Tarif & Kebijakan:** Memproses
   antrean usulan pengelola dan keputusan pemilik atas perubahan tarif dan
   kebijakan.
7. **UC-07 Terbitkan & Kelola Tagihan Bulanan:** Menghitung sewa prorata,
   menerbitkan tagihan satuan maupun massal, membatalkan yang salah, memaafkan
   denda.
8. **UC-08 Catat Pembayaran Sewa:** Mencatat uang diterima beserta tanggal,
   metode, penerima; status tagihan jadi sebagian atau lunas.
9. **UC-09 Verifikasi & Koreksi Pembayaran:** Memeriksa catatan pembayaran
   akun lain dan menarik yang salah lewat baris pembalik.
10. **UC-10 Laporan Rekap Pendapatan, Tunggakan & Jejak Audit:** Rekap
   pendapatan per metode, tunggakan menurut umur piutang, dan jejak audit.
11. **UC-11 Kelola Preferensi Akun, Notifikasi & Hak Data Pribadi:**
   Mengatur kanal notifikasi, membaca kotak masuk, persetujuan PDP, dan hak
   atas data pribadi sendiri.
12. **UC-12 Kelola Akun Pengelola & Wewenang:** Membuat akun pengelola,
   menetapkan wewenangnya, mengirim ulang aktivasi, dan menonaktifkan akun.
13. **UC-13 Portal Penghuni:** Menyajikan tagihan, denda, aturan, dan
   rekening tujuan transfer milik penghuni sendiri, serta membuka tautan bayar
   daring.
14. **UC-14 Kelola Rekening Tujuan Pembayaran & Diagnostik Sistem:**
   Mengelola rekening dan QRIS tujuan setoran, serta pemeriksaan kesehatan
   layanan dan peran.

### 4.2 Matriks Hak Akses Aktor terhadap Use Case

1. **Pemilik Kos:** Mengakses UC-01, UC-02, UC-03, UC-04, UC-05, UC-06, UC-07, UC-08, UC-09, UC-10, UC-11, UC-12, dan UC-14.
2. **Pengelola:** Mengakses UC-01, UC-02, UC-03, UC-04, UC-05, UC-06, UC-07, UC-08, UC-09, UC-10, UC-11, dan UC-14.
3. **Penghuni:** Mengakses UC-01, UC-11, dan UC-13.

Empat sel lebih sempit daripada perannya — (a) UC-07, (b) UC-10, (c) UC-14, dan (d) UC-13 — dan tidak dirinci di sini;
alasannya ada di `use-case-diagram.md` §4.

## 5. Pemodelan Terstruktur (Data Flow Diagram / DFD Konteks)

Proses sentral **0.0 Sistem Informasi Operasional Kos-Kosan** menghubungkan
**3 entitas eksternal** melalui **14 aliran data utama (7 masuk dan 7
keluar)**. Gambarnya di `dfd-konteks.md`, dibaca dari `docs/dfd/dfd-data.js` —
sumber tunggal yang sama dengan DFD teknis.

### 5.1 Matriks 14 Aliran Data Utama (Input & Output)

**A. Aliran Data Masuk (Input ke Proses Sentral 0.0):**

1. **Pemilik Kos -> Sistem:** data kamar, penghuni & kontrak
2. **Pemilik Kos -> Sistem:** tarif, kebijakan & komponen biaya
3. **Pemilik Kos -> Sistem:** keputusan persetujuan usulan
4. **Pemilik Kos -> Sistem:** periode penagihan
5. **Pemilik Kos -> Sistem:** konfirmasi setoran uang
6. **Pengelola -> Sistem:** usulan perubahan tarif & kebijakan
7. **Pengelola -> Sistem:** pencatatan pembayaran tunai

**B. Aliran Data Keluar (Output dari Proses Sentral 0.0):**

1. **Sistem -> Penghuni:** konfirmasi sewa & kontrak
2. **Sistem -> Pemilik Kos:** daftar usulan menunggu persetujuan
3. **Sistem -> Penghuni:** tagihan bulanan (pokok)
4. **Sistem -> Pengelola:** daftar tagihan terbit
5. **Sistem -> Penghuni:** kuitansi & status pelunasan
6. **Sistem -> Pemilik Kos:** rekap pendapatan & hunian
7. **Sistem -> Pemilik Kos:** daftar tunggakan & log audit

Penghuni sengaja tanpa aliran masuk data (portalnya hampir seluruhnya
baca-saja; bayar daring tidak membawa data, uangnya tercatat dari webhook), dan
pemilik dipisahkan
dari pengelola karena pengelola mengusulkan (I-6) sementara pemilik memutuskan
(I-3) — alasan lengkapnya di `dfd-konteks.md`.

## 6. Entitas Data Utama

23 tabel domain dikelompokkan menjadi 12 entitas logis: pembaca laporan
mengenal "kontrak", bukan tabel penghubung.

| Entitas | Atribut Data Utama & Ruang Lingkup |
|---|---|
| Pengguna & Peran | id, name, email, password, peran, wajib_ganti_sandi |
| Penghuni | id, nama, email, no_hp |
| Kamar | id, nomor, tipe, status_huni |
| Kontrak Sewa | id, kamar_id, penghuni_id, tgl_masuk, tarif_awal, status |
| Tarif, Komponen Biaya & Kebijakan | id, kamar_id, nilai, satuan, tipe, masa_tenggang, berlaku_dari |
| Tagihan & Rincian | id, kontrak_id, periode, pokok, jatuh_tempo, status, tarif_terpakai, total_final |
| Pembayaran & Verifikasi | id, tagihan_id, pembalik_id, jumlah, metode, dicatat_oleh |
| Usulan Perubahan | id, tipe, payload, status, diajukan_oleh, diputuskan_oleh |
| Log Audit | id, aktor_id, aktor_nama, aksi, entitas, nilai_lama, nilai_baru |
| Persetujuan PDP & Preferensi Notifikasi | user_id, versi, disetujui_pada, peristiwa, kanal, aktif |
| Rekening Tujuan Pembayaran | id, jenis, nama, nomor, atas_nama, aktif, urutan |
| Aturan Kos | id, judul, isi, kategori, sanksi, aktif |

## 7. Aturan Bisnis Pokok Sistem

Tujuh aturan berikut menahan sistem ini tetap benar ketika dipakai.
Masing-masing punya proses penjaga di DFD dan pengujian yang menyebut
nomornya.

1. **Sewa bulan pertama prorata (R-01).** Tagihan bulan pertama memakai `tarif
   × hari_ditagih ÷ hari_dalam_bulan`.
2. **Satu tagihan hidup per periode (R-02).** Penerbitan ulang ditolak,
   kecuali periode yang tagihannya sudah dibatalkan (REV-FIN-02).
3. **Denda setelah tenggang, berplafon (R-03).** Mulai hari ke-8 setelah jatuh
   tempo, 1% pokok per hari, berhenti pada 30% pokok. Bentuknya dapat diubah
   pemilik.
4. **Tunggakan menurut umur piutang (R-04).** Empat kelompok:
   `belum_jatuh_tempo`, `0_30`, `31_60`, dan `lebih_60` hari.
5. **Kebijakan lengkap sebelum tagihan (R-05).** Tanpa `satuan_sewa`,
   `jatuh_tempo`, dan `denda` terisi, penerbitan ditolak.
6. **Tarif tidak berlaku surut (R-06).** Perubahan tarif hanya untuk periode
   berikutnya; tagihan terbit memakai salinan yang dibekukan.
7. **Penghuni hanya lihat tagihannya (R-07).** Pembacaan tagihan disaring
   scope `Tagihan::terlihatOleh`; tagihan orang lain dijawab **404**, bukan
   403.

---

*Regenerasi: `usecase-data.js`, `dokumen-data.js`, `build.js`, `build-dokumen.js`.
Diagram: `01-use-case.svg`, `02-dfd-konteks.svg` (dan PNG 2x-nya).*
