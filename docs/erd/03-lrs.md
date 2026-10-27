# 3. LRS — Logical Record Structure

> Dokumen ini menjawab: **bentuk logis data yang sebenarnya** — tabel apa
> saja yang ada, kolom apa saja isinya, mana kunci primer, dan ke mana kunci
> asing menunjuk.
>
> Seluruh isi di bawah dihasilkan dari basis data yang berjalan
> (`information_schema`, `pg_constraint`, `pg_indexes`), bukan diketik. Kalau
> dokumen ini berbeda dengan migrasi, migrasi yang benar — dan dokumen ini
> yang harus dibangun ulang dengan `node docs/erd/build-lrs.js`.

Versi 1.0 · 23 tabel · 201 kolom · PostgreSQL 16

## 1. Hubungan dengan ERD

ERD dan LRS bukan dua dokumen yang saling menggantikan, melainkan dua
potret dari basis data yang sama:

| | ERD (notasi Chen) | LRS (dokumen ini) |
|---|---|---|
| Menjawab | entitas apa saja, dan bagaimana hubungannya | kolom apa saja, dan mana kuncinya |
| Menggambar | kotak, belah ketupat, elips | daftar kolom beserta tipe dan kunci |
| Kunci asing | diwakili belah ketupat, kolomnya tidak digambar | ditulis lengkap dengan tanda bintang |
| Dipakai untuk | menjelaskan alur data saat sidang | membuktikan bahwa data bisa disimpan tanpa duplikasi |

Keduanya dibangun dari satu berkas yang sama, `skema.json`, sehingga mustahil
menyebut tabel atau relasi yang berbeda satu sama lain.

## 2. Notasi

Pada daftar di §3, setiap tabel ditulis dalam bentuk relasional formal:

```
nama_tabel = (kolom_pk, kolom_biasa, kolom_fk*)
```

- **<u>digaris bawahi</u>** — kunci primer (primary key): penanda unik satu baris;
- **\*** — kunci asing (foreign key): nilainya harus ada di tabel lain;
- tanpa tanda — kolom biasa.

Pada rincian di §4, setiap kolom memuat tipe, status null, dan kuncinya.

## 3. Daftar LRS

Notasi formal seluruh tabel, dalam urutan yang mengikuti alur pemakaian:
dari akun, ke kamar, ke uang, lalu ke jejak dan pengaturan.

```
users = (<u>id</u>, name, email, email_verified_at, password, remember_token, created_at, updated_at, peran, penghuni_id*, pemilik_id*, wajib_ganti_sandi)
penghuni = (<u>id</u>, nama, no_hp, created_at, updated_at, email)
kamar = (<u>id</u>, nomor, tipe, status_huni, catatan, created_at, updated_at)
fasilitas = (<u>id</u>, nama, ikon, urutan, created_at, updated_at)
kamar_fasilitas = (<u>id</u>, kamar_id*, fasilitas_id*, created_at, updated_at)
tarif_sewa = (<u>id</u>, kamar_id*, nilai, berlaku_dari, created_at, updated_at)
komponen_biaya = (<u>id</u>, nama, nilai, satuan, berlaku_dari, created_at, updated_at, kamar_id*)
kebijakan_kos = (<u>id</u>, kamar_id*, tipe, nilai, persen, plafon_persen, masa_tenggang, nominal, tanggal, berlaku_dari, dibuat_pada)
kontrak = (<u>id</u>, kamar_id*, penghuni_id*, tgl_masuk, tgl_keluar, tarif_awal, status, created_at, updated_at)
tagihan = (<u>id</u>, kontrak_id*, periode, pokok, jatuh_tempo, status, tarif_terpakai, tarif_denda_persen, plafon_persen, masa_tenggang, terbit_pada, created_at, updated_at, total_final, kebijakan_terpakai, alasan_batal, dibatalkan_oleh*, dibatalkan_pada, denda_dimaafkan, alasan_maaf_denda, denda_dimaafkan_oleh*, denda_dimaafkan_pada)
tagihan_rincian = (<u>id</u>, tagihan_id*, nama, nilai, urutan, created_at, updated_at)
pembayaran = (<u>id</u>, tagihan_id*, jumlah, tanggal_pembayaran, catatan, created_at, metode, sumber, referensi, dicatat_oleh*, diterima_oleh*, pembalik_id*)
verifikasi_pembayaran = (<u>id</u>, pembayaran_id*, diverifikasi_oleh*, keputusan, catatan, created_at)
transaksi_gateway = (<u>id</u>, tagihan_id*, order_id, jumlah, status, redirect_url, token, transaction_id, transaction_status, fraud_status, kedaluwarsa_pada, dibayar_pada, created_at, updated_at)
rekening = (<u>id</u>, jenis, nama, nomor, atas_nama, catatan, aktif, urutan, created_at, updated_at, gambar, gambar_mime, gambar_ukuran)
aturan_kos = (<u>id</u>, judul, isi, kategori, sanksi, aktif, urutan, created_at, updated_at)
pengelola_kewenangan = (<u>id</u>, user_id*, wewenang)
usulan_perubahan = (<u>id</u>, tipe, payload, ringkasan, status, diajukan_oleh*, diputuskan_oleh*, alasan, dibuat_pada, diputuskan_pada, kedaluwarsa_pada)
log_audit = (<u>id</u>, aktor_id, aktor_nama, aksi, entitas, entitas_id, nilai_lama, nilai_baru, dibuat_pada)
pengaturan_notifikasi = (<u>id</u>, user_id*, peristiwa, kanal, aktif)
persetujuan_pdp = (<u>id</u>, user_id*, versi, disetujui_pada, ip)
token_akun = (<u>id</u>, user_id*, jenis, token_hash, kedaluwarsa_pada, dipakai_pada, dibuat_pada)
notifications = (<u>id</u>, type, notifiable_type, notifiable_id, data, read_at, created_at, updated_at)
```

## 4. Rincian Tabel

Setiap tabel diuraikan lengkap: tipe kolom, boleh kosong atau tidak, dan
kuncinya. Keterangan kolom hanya ditulis bila namanya tidak menjelaskan
dirinya sendiri — kolom yang jelas tidak diberi keterangan berulang.

### 4.1 users

Akun untuk masuk aplikasi. Kolom `peran` menentukan hak akses: pemilik, pengelola, atau penghuni.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `name` | varchar(255) | wajib | — |  |
| `email` | varchar(255) | wajib | UQ |  |
| `email_verified_at` | timestamp | boleh | — |  |
| `password` | varchar(255) | wajib | — |  |
| `remember_token` | varchar(100) | boleh | — |  |
| `created_at` | timestamp | boleh | — |  |
| `updated_at` | timestamp | boleh | — |  |
| `peran` | varchar(20) | wajib | — | pemilik \| pengelola \| penghuni |
| `penghuni_id` | bigint | boleh | FK | diisi bila akun ini milik seorang penghuni · → `penghuni.id` |
| `pemilik_id` | bigint | boleh | FK | diisi bila akun ini pengelola yang terikat pada seorang pemilik · → `users.id` |
| `wajib_ganti_sandi` | boolean | wajib | — | true bila sandi dibuatkan pihak lain dan harus diganti saat pertama masuk |

### 4.2 penghuni

Data orang yang menyewa kamar. Sengaja dipisah dari `users` supaya penghuni bisa dicatat sebelum punya akun.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `nama` | varchar(120) | wajib | — |  |
| `no_hp` | varchar(30) | boleh | — |  |
| `created_at` | timestamp | boleh | — |  |
| `updated_at` | timestamp | boleh | — |  |
| `email` | varchar(120) | boleh | UQ | dipakai mengirim tautan aktivasi akun |

### 4.3 kamar

Unit kamar yang disewakan, beserta status huninya.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `nomor` | varchar(20) | wajib | UQ | unik; dipakai penghuni mengenali kamarnya |
| `tipe` | varchar(40) | boleh | — |  |
| `status_huni` | varchar(255) | wajib | — | kosong \| terisi \| dipesan |
| `catatan` | text | boleh | — |  |
| `created_at` | timestamp | boleh | — |  |
| `updated_at` | timestamp | boleh | — |  |

### 4.4 fasilitas

Daftar fasilitas yang bisa dimiliki kamar, mis. kasur, AC, kamar mandi dalam.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `nama` | varchar(60) | wajib | UQ |  |
| `ikon` | varchar(40) | boleh | — |  |
| `urutan` | smallint | wajib | — |  |
| `created_at` | timestamp | boleh | — |  |
| `updated_at` | timestamp | boleh | — |  |

### 4.5 kamar_fasilitas

Tabel penghubung kamar dan fasilitas. Satu kamar punya banyak fasilitas, satu fasilitas dipakai banyak kamar.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `kamar_id` | bigint | wajib | FK | → `kamar.id` |
| `fasilitas_id` | bigint | wajib | FK | → `fasilitas.id` |
| `created_at` | timestamp | boleh | — |  |
| `updated_at` | timestamp | boleh | — |  |

Kunci unik gabungan:

- (`kamar_id`, `fasilitas_id`) — kombinasi ini tidak boleh berulang

### 4.6 tarif_sewa

Tarif sewa per kamar yang berlaku mulai tanggal tertentu. Riwayat, bukan satu nilai yang ditimpa.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `kamar_id` | bigint | wajib | FK | → `kamar.id` |
| `nilai` | numeric(14,0) | wajib | — | rupiah bulat per satuan sewa yang berlaku |
| `berlaku_dari` | date | wajib | — | tanggal mulai berlakunya tarif; tarif lama tidak dihapus |
| `created_at` | timestamp | boleh | — |  |
| `updated_at` | timestamp | boleh | — |  |

Kunci unik gabungan:

- (`kamar_id`, `berlaku_dari`) — kombinasi ini tidak boleh berulang

### 4.7 komponen_biaya

Biaya tambahan per kamar (listrik, air, kebersihan) yang ikut ditagihkan setiap bulan.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `nama` | varchar(80) | wajib | — | mis. Listrik, Air, Kebersihan |
| `nilai` | numeric(14,0) | wajib | — | rupiah bulat yang ditambahkan ke tagihan setiap bulan |
| `satuan` | varchar(30) | wajib | — |  |
| `berlaku_dari` | date | wajib | — |  |
| `created_at` | timestamp | boleh | — |  |
| `updated_at` | timestamp | boleh | — |  |
| `kamar_id` | bigint | boleh | FK | kosong berarti berlaku untuk seluruh kamar · → `kamar.id` |

Kunci unik gabungan:

- (`nama`, `berlaku_dari`, `kamar_id`) — kombinasi ini tidak boleh berulang

### 4.8 kebijakan_kos

Kebijakan kos: satuan sewa, jatuh tempo, dan aturan denda. Berlaku untuk seluruh kos bila `kamar_id` kosong, atau khusus satu kamar bila diisi.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `kamar_id` | bigint | boleh | FK | → `kamar.id` |
| `tipe` | varchar(30) | wajib | — | satuan_sewa \| jatuh_tempo \| denda |
| `nilai` | varchar(30) | wajib | — | nilai utama; artinya bergantung pada `tipe` |
| `persen` | numeric(5,2) | boleh | — | dipakai bila tipe denda dan nilainya persen_harian atau persen_bulanan |
| `plafon_persen` | numeric(5,2) | boleh | — | batas tertinggi denda, mis. 30 berarti 30% dari pokok |
| `masa_tenggang` | smallint | boleh | — | jumlah hari sebelum denda mulai dihitung |
| `nominal` | numeric(14,0) | boleh | — | dipakai bila tipe denda dan nilainya nominal_tetap |
| `tanggal` | smallint | boleh | — | dipakai bila tipe jatuh_tempo dan nilainya tanggal_tetap |
| `berlaku_dari` | date | wajib | — |  |
| `dibuat_pada` | timestamptz | wajib | — |  |

Kunci unik gabungan:

- (`tipe`, `kamar_id`, `berlaku_dari`) — kombinasi ini tidak boleh berulang

### 4.9 kontrak

Perjanjian sewa seorang penghuni pada satu kamar, lengkap dengan tanggal masuk dan statusnya.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `kamar_id` | bigint | wajib | FK | → `kamar.id` |
| `penghuni_id` | bigint | wajib | FK | → `penghuni.id` |
| `tgl_masuk` | date | wajib | — | dasar perhitungan prorata bulan pertama |
| `tgl_keluar` | date | boleh | — |  |
| `tarif_awal` | numeric(14,0) | wajib | — |  |
| `status` | varchar(255) | wajib | — | dipesan \| aktif \| selesai \| dibatalkan |
| `created_at` | timestamp | boleh | — |  |
| `updated_at` | timestamp | boleh | — |  |

Indeks unik parsial — aturan bisnis yang ditegakkan basis data:

- `kontrak_satu_aktif_per_kamar`: CREATE UNIQUE INDEX kontrak_satu_aktif_per_kamar ON public.kontrak USING btree (kamar_id) WHERE ((status)::text = ANY ((ARRAY['aktif'::character varying, 'dipesan'::character varying])::text[]))

### 4.10 tagihan

Tagihan bulanan per kontrak: pokok, denda, total akhir, dan status pembayaran.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `kontrak_id` | bigint | wajib | FK | → `kontrak.id` |
| `periode` | date | wajib | — | bulan yang ditagih; satu periode hanya boleh punya satu tagihan hidup per kontrak |
| `pokok` | numeric(14,0) | wajib | — | jumlah sebelum denda, sesudah prorata |
| `jatuh_tempo` | date | wajib | — |  |
| `status` | varchar(255) | wajib | — | belum_bayar \| sebagian \| lunas \| dibatalkan |
| `tarif_terpakai` | json | wajib | — | salinan tarif saat tagihan diterbitkan, supaya riwayat tidak berubah bila tarif diperbarui |
| `tarif_denda_persen` | numeric(5,2) | boleh | — | salinan tarif denda saat terbit; denda dihitung dari sini, tidak disimpan sebagai kolom |
| `plafon_persen` | numeric(5,2) | boleh | — | salinan batas denda saat terbit, mis. 30 berarti 30% dari pokok |
| `masa_tenggang` | smallint | boleh | — | salinan jumlah hari tenggang saat terbit |
| `terbit_pada` | timestamp | wajib | — | waktu tagihan diterbitkan; tidak berubah walau tagihan diperbarui |
| `created_at` | timestamp | boleh | — |  |
| `updated_at` | timestamp | boleh | — |  |
| `total_final` | numeric(14,0) | boleh | — | pokok + denda berjalan - pembayaran yang sudah tercatat |
| `kebijakan_terpakai` | jsonb | boleh | — | salinan kebijakan saat terbit, agar tagihan lama tetap bisa dipertanggungjawabkan |
| `alasan_batal` | text | boleh | — |  |
| `dibatalkan_oleh` | bigint | boleh | FK | hanya status yang berubah; baris tidak pernah dihapus · → `users.id` |
| `dibatalkan_pada` | timestamptz | boleh | — |  |
| `denda_dimaafkan` | numeric(14,0) | wajib | — | true bila pemilik menghapus denda pada tagihan ini; pokok tetap ditagih |
| `alasan_maaf_denda` | text | boleh | — |  |
| `denda_dimaafkan_oleh` | bigint | boleh | FK | → `users.id` |
| `denda_dimaafkan_pada` | timestamptz | boleh | — |  |

Indeks unik parsial — aturan bisnis yang ditegakkan basis data:

- `tagihan_kontrak_periode_aktif_unique`: CREATE UNIQUE INDEX tagihan_kontrak_periode_aktif_unique ON public.tagihan USING btree (kontrak_id, periode) WHERE ((status)::text <> 'dibatalkan'::text)

### 4.11 tagihan_rincian

Rincian baris penyusun tagihan, supaya angka pokok bisa dipertanggungjawabkan.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `tagihan_id` | bigint | wajib | FK | → `tagihan.id` |
| `nama` | varchar(80) | wajib | — |  |
| `nilai` | numeric(14,0) | wajib | — |  |
| `urutan` | smallint | wajib | — |  |
| `created_at` | timestamp | boleh | — |  |
| `updated_at` | timestamp | boleh | — |  |

### 4.12 pembayaran

Pembayaran yang dicatat atas sebuah tagihan. Pembalik disimpan sebagai baris bernilai negatif yang menunjuk baris asalnya.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `tagihan_id` | bigint | wajib | FK | → `tagihan.id` |
| `jumlah` | numeric(14,0) | wajib | — | bernilai negatif bila baris ini adalah pembalik |
| `tanggal_pembayaran` | date | wajib | — |  |
| `catatan` | varchar(255) | boleh | — |  |
| `created_at` | timestamp | boleh | — |  |
| `metode` | varchar(20) | boleh | — |  |
| `sumber` | varchar(30) | wajib | — | manual \| tunai \| gateway_webhook |
| `referensi` | varchar(100) | boleh | — | nomor referensi dari gateway, bila sumbernya gateway_webhook |
| `dicatat_oleh` | bigint | boleh | FK | → `users.id` |
| `diterima_oleh` | bigint | boleh | FK | → `users.id` |
| `pembalik_id` | bigint | boleh | FK UQ | menunjuk baris yang dibalik; unik, sehingga satu pembayaran hanya bisa dibalik sekali · → `pembayaran.id` |

Indeks unik parsial — aturan bisnis yang ditegakkan basis data:

- `pembayaran_gateway_satu_per_order`: CREATE UNIQUE INDEX pembayaran_gateway_satu_per_order ON public.pembayaran USING btree (referensi) WHERE (((sumber)::text = 'gateway_webhook'::text) AND (referensi IS NOT NULL))

### 4.13 verifikasi_pembayaran

Pemeriksaan bukti transfer oleh pengelola sebelum pembayaran diakui sah.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `pembayaran_id` | bigint | wajib | FK | → `pembayaran.id` |
| `diverifikasi_oleh` | bigint | boleh | FK | → `users.id` |
| `keputusan` | varchar(10) | wajib | — | disetujui \| ditolak |
| `catatan` | varchar(255) | boleh | — |  |
| `created_at` | timestamp | boleh | — |  |

### 4.14 transaksi_gateway

Transaksi Midtrans untuk pembayaran daring, termasuk `order_id` dan statusnya.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `tagihan_id` | bigint | wajib | FK | → `tagihan.id` |
| `order_id` | varchar(64) | wajib | UQ | unik; dipakai mencocokkan webhook Midtrans dengan transaksi |
| `jumlah` | numeric(14,0) | wajib | — |  |
| `status` | varchar(20) | wajib | — | menunggu \| berhasil \| gagal \| kedaluwarsa |
| `redirect_url` | text | boleh | — |  |
| `token` | varchar(128) | boleh | — |  |
| `transaction_id` | varchar(64) | boleh | — |  |
| `transaction_status` | varchar(32) | boleh | — |  |
| `fraud_status` | varchar(32) | boleh | — |  |
| `kedaluwarsa_pada` | timestamp | boleh | — |  |
| `dibayar_pada` | timestamp | boleh | — |  |
| `created_at` | timestamp | boleh | — |  |
| `updated_at` | timestamp | boleh | — |  |

Indeks unik parsial — aturan bisnis yang ditegakkan basis data:

- `transaksi_gateway_satu_menunggu_per_tagihan`: CREATE UNIQUE INDEX transaksi_gateway_satu_menunggu_per_tagihan ON public.transaksi_gateway USING btree (tagihan_id) WHERE ((status)::text = 'menunggu'::text)

### 4.15 rekening

Rekening tujuan pembayaran milik kos, termasuk gambar QRIS yang disimpan sebagai biner.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `jenis` | varchar(20) | wajib | — |  |
| `nama` | varchar(60) | wajib | — |  |
| `nomor` | varchar(60) | boleh | — |  |
| `atas_nama` | varchar(80) | boleh | — |  |
| `catatan` | varchar(200) | boleh | — |  |
| `aktif` | boolean | wajib | — |  |
| `urutan` | smallint | wajib | — |  |
| `created_at` | timestamp | boleh | — |  |
| `updated_at` | timestamp | boleh | — |  |
| `gambar` | bytea | boleh | — | biner gambar QRIS; hanya boleh ditulis lewat decode(?, base64) |
| `gambar_mime` | varchar(60) | boleh | — |  |
| `gambar_ukuran` | integer | boleh | — |  |

### 4.16 aturan_kos

Teks aturan dan sanksi yang ditampilkan kepada penghuni.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `judul` | varchar(120) | wajib | — |  |
| `isi` | text | wajib | — |  |
| `kategori` | varchar(40) | wajib | — |  |
| `sanksi` | varchar(200) | boleh | — |  |
| `aktif` | boolean | wajib | — |  |
| `urutan` | smallint | wajib | — |  |
| `created_at` | timestamp | boleh | — |  |
| `updated_at` | timestamp | boleh | — |  |

### 4.17 pengelola_kewenangan

Wewenang terperinci yang diberikan pemilik kepada seorang pengelola.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `user_id` | bigint | wajib | FK | → `users.id` |
| `wewenang` | varchar(40) | wajib | — | satu baris per wewenang; 8 jenis, 2 di antaranya sensitif |

Kunci unik gabungan:

- (`user_id`, `wewenang`) — kombinasi ini tidak boleh berulang

### 4.18 usulan_perubahan

Usulan perubahan tarif atau kebijakan oleh pengelola yang menunggu keputusan pemilik. Kedaluwarsa setelah 7 hari.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `tipe` | varchar(20) | wajib | — |  |
| `payload` | jsonb | wajib | — |  |
| `ringkasan` | varchar(255) | wajib | — |  |
| `status` | varchar(20) | wajib | — | menunggu \| disetujui \| ditolak \| kedaluwarsa |
| `diajukan_oleh` | bigint | wajib | FK | → `users.id` |
| `diputuskan_oleh` | bigint | boleh | FK | → `users.id` |
| `alasan` | varchar(255) | boleh | — |  |
| `dibuat_pada` | timestamp | wajib | — |  |
| `diputuskan_pada` | timestamp | boleh | — |  |
| `kedaluwarsa_pada` | timestamp | wajib | — | usulan yang tidak diputuskan dalam 7 hari menjadi kedaluwarsa |

### 4.19 log_audit

Jejak setiap tindakan yang mengubah data, beserta pelaku dan waktunya.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `aktor_id` | bigint | boleh | — | sengaja tanpa kunci asing agar jejak audit tetap utuh bila akun dihapus |
| `aktor_nama` | varchar(255) | boleh | — |  |
| `aksi` | varchar(255) | wajib | — | mis. pemilik.tambah, tagihan.terbit, tarif.ubah |
| `entitas` | varchar(255) | wajib | — |  |
| `entitas_id` | bigint | boleh | — |  |
| `nilai_lama` | jsonb | boleh | — |  |
| `nilai_baru` | jsonb | boleh | — |  |
| `dibuat_pada` | timestamptz | wajib | — |  |

### 4.20 pengaturan_notifikasi

Preferensi kanal notifikasi per pengguna per peristiwa.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `user_id` | bigint | wajib | FK | → `users.id` |
| `peristiwa` | varchar(40) | wajib | — |  |
| `kanal` | varchar(10) | wajib | — |  |
| `aktif` | boolean | wajib | — |  |

Kunci unik gabungan:

- (`user_id`, `peristiwa`, `kanal`) — kombinasi ini tidak boleh berulang

### 4.21 persetujuan_pdp

Rekaman persetujuan pemrosesan data pribadi, beserta versi pernyataan yang disetujui.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `user_id` | bigint | wajib | FK | → `users.id` |
| `versi` | varchar(20) | wajib | — | versi pernyataan yang disetujui |
| `disetujui_pada` | timestamptz | wajib | — |  |
| `ip` | varchar(45) | boleh | — |  |

Kunci unik gabungan:

- (`user_id`, `versi`) — kombinasi ini tidak boleh berulang

### 4.22 token_akun

Token sekali pakai untuk aktivasi akun dan atur ulang sandi. Disimpan sebagai hash, bukan teks asli.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | bigint | wajib | PK |  |
| `user_id` | bigint | wajib | FK | → `users.id` |
| `jenis` | varchar(10) | wajib | — | aktivasi \| reset |
| `token_hash` | character | wajib | UQ | hash token; token asli hanya ada di tautan yang dikirim lewat email |
| `kedaluwarsa_pada` | timestamptz | wajib | — |  |
| `dipakai_pada` | timestamptz | boleh | — |  |
| `dibuat_pada` | timestamptz | wajib | — |  |

### 4.23 notifications

Notifikasi dalam aplikasi bawaan Laravel. Tidak punya kunci asing karena memakai relasi polimorfik.

| Kolom | Tipe | Null | Kunci | Keterangan |
|---|---|---|---|---|
| `id` | uuid | wajib | PK |  |
| `type` | varchar(255) | wajib | — |  |
| `notifiable_type` | varchar(255) | wajib | — | kelas pemilik notifikasi; relasi polimorfik, bukan kunci asing |
| `notifiable_id` | bigint | wajib | — | id baris pemilik notifikasi |
| `data` | text | wajib | — |  |
| `read_at` | timestamp | boleh | — |  |
| `created_at` | timestamp | boleh | — |  |
| `updated_at` | timestamp | boleh | — |  |

## 5. Relasi Antar Tabel

Seluruh 26 kunci asing di basis data, dikelompokkan menurut tabel asalnya.
Arah panah menunjukkan arah rujukan: kolom di kiri harus ada di tabel kanan.

| # | Dari | Kolom | Ke | Wajib | Arti kardinalitas |
|---|---|---|---|---|---|
| 1 | `users` | `pemilik_id` | `users.id` | boleh kosong | relasi diri: satu users boleh dirujuk banyak users lain |
| 2 | `users` | `penghuni_id` | `penghuni.id` | boleh kosong | satu penghuni boleh punya banyak users |
| 3 | `kamar_fasilitas` | `fasilitas_id` | `fasilitas.id` | wajib | satu fasilitas boleh punya banyak kamar_fasilitas |
| 4 | `kamar_fasilitas` | `kamar_id` | `kamar.id` | wajib | satu kamar boleh punya banyak kamar_fasilitas |
| 5 | `tarif_sewa` | `kamar_id` | `kamar.id` | wajib | satu kamar boleh punya banyak tarif_sewa |
| 6 | `komponen_biaya` | `kamar_id` | `kamar.id` | boleh kosong | satu kamar boleh punya banyak komponen_biaya |
| 7 | `kebijakan_kos` | `kamar_id` | `kamar.id` | boleh kosong | satu kamar boleh punya banyak kebijakan_kos |
| 8 | `kontrak` | `kamar_id` | `kamar.id` | wajib | satu kamar boleh punya banyak kontrak |
| 9 | `kontrak` | `penghuni_id` | `penghuni.id` | wajib | satu penghuni boleh punya banyak kontrak |
| 10 | `tagihan` | `denda_dimaafkan_oleh` | `users.id` | boleh kosong | satu users boleh punya banyak tagihan |
| 11 | `tagihan` | `dibatalkan_oleh` | `users.id` | boleh kosong | satu users boleh punya banyak tagihan |
| 12 | `tagihan` | `kontrak_id` | `kontrak.id` | wajib | satu kontrak boleh punya banyak tagihan |
| 13 | `tagihan_rincian` | `tagihan_id` | `tagihan.id` | wajib | satu tagihan boleh punya banyak tagihan_rincian |
| 14 | `pembayaran` | `dicatat_oleh` | `users.id` | boleh kosong | satu users boleh punya banyak pembayaran |
| 15 | `pembayaran` | `diterima_oleh` | `users.id` | boleh kosong | satu users boleh punya banyak pembayaran |
| 16 | `pembayaran` | `pembalik_id` | `pembayaran.id` | boleh kosong | relasi diri: satu pembayaran menunjuk tepat satu pembayaran lain |
| 17 | `pembayaran` | `tagihan_id` | `tagihan.id` | wajib | satu tagihan boleh punya banyak pembayaran |
| 18 | `verifikasi_pembayaran` | `diverifikasi_oleh` | `users.id` | boleh kosong | satu users boleh punya banyak verifikasi_pembayaran |
| 19 | `verifikasi_pembayaran` | `pembayaran_id` | `pembayaran.id` | wajib | satu pembayaran boleh punya banyak verifikasi_pembayaran |
| 20 | `transaksi_gateway` | `tagihan_id` | `tagihan.id` | wajib | satu tagihan boleh punya banyak transaksi_gateway |
| 21 | `pengelola_kewenangan` | `user_id` | `users.id` | wajib | satu users boleh punya banyak pengelola_kewenangan |
| 22 | `usulan_perubahan` | `diajukan_oleh` | `users.id` | wajib | satu users boleh punya banyak usulan_perubahan |
| 23 | `usulan_perubahan` | `diputuskan_oleh` | `users.id` | boleh kosong | satu users boleh punya banyak usulan_perubahan |
| 24 | `pengaturan_notifikasi` | `user_id` | `users.id` | wajib | satu users boleh punya banyak pengaturan_notifikasi |
| 25 | `persetujuan_pdp` | `user_id` | `users.id` | wajib | satu users boleh punya banyak persetujuan_pdp |
| 26 | `token_akun` | `user_id` | `users.id` | wajib | satu users boleh punya banyak token_akun |

Jumlah kunci asing: **26**.

## 6. Tabel Tanpa Relasi

Tabel berikut tidak punya kunci asing keluar maupun masuk. Ini bukan
kelalaian: isinya memang tidak terikat pada baris tabel lain.

- `aturan_kos` — Teks aturan dan sanksi yang ditampilkan kepada penghuni.
- `log_audit` — Jejak setiap tindakan yang mengubah data, beserta pelaku dan waktunya.
- `notifications` — Notifikasi dalam aplikasi bawaan Laravel. Tidak punya kunci asing karena memakai relasi polimorfik.
- `rekening` — Rekening tujuan pembayaran milik kos, termasuk gambar QRIS yang disimpan sebagai biner.

## 7. Aturan yang Tidak Terlihat dari Daftar Kolom

Sebagian aturan penting tidak berbentuk kolom, sehingga tidak akan terlihat
di §3 dan §4. Aturan-aturan itu ditegakkan basis data, bukan aplikasi:

### 7.1 Indeks unik parsial

Keunikan yang hanya berlaku untuk sebagian baris — inilah yang mencegah
satu kontrak punya dua tagihan hidup pada periode yang sama (R-02):

| Nama indeks | Letak |
|---|---|
| `kontrak_satu_aktif_per_kamar` | indeks unik parsial pada `kontrak` |
| `pembayaran_gateway_satu_per_order` | indeks unik parsial pada `pembayaran` |
| `tagihan_kontrak_periode_aktif_unique` | indeks unik parsial pada `tagihan` |
| `transaksi_gateway_satu_menunggu_per_tagihan` | indeks unik parsial pada `transaksi_gateway` |

Definisi lengkapnya ada pada rincian tiap tabel di §4.

### 7.2 Tabel yang hanya boleh ditambah (append-only)

Terdapat **7 tabel** yang menolak `UPDATE` dan `DELETE` lewat trigger basis data: `kebijakan_kos`, `komponen_biaya`, `log_audit`, `pembayaran`, `persetujuan_pdp`, `tarif_sewa`, `verifikasi_pembayaran`.

Jumlah trigger **8**, lebih banyak daripada jumlah tabelnya, karena satu tabel bisa punya lebih dari satu penjaga.

Baris pada tabel-tabel itu hanya bisa ditambah, sehingga riwayat tarif dan
pembayaran tidak bisa ditimpa diam-diam. Koreksi dilakukan dengan menambah
baris baru, bukan mengubah yang lama.

`tagihan` **tidak** termasuk daftar itu: statusnya memang harus bisa berubah dari `belum_bayar` menjadi `lunas`. Pembatalan pun hanya mengubah status, tidak pernah menghapus baris.

## 8. Cara Membuktikan Dokumen Ini Benar

```bash
node docs/erd/ambil-skema.js      # ambil ulang skema dari basis data
node docs/erd/build-lrs.js        # bangun ulang dokumen ini
node docs/erd/build-tingkat.js    # bangun ulang ERD berjenjang dari skema yang sama
```

Ketiga perintah gagal dengan kode keluar bukan nol bila ada yang tidak
konsisten, sehingga dokumen tidak bisa diam-diam menyimpang dari basis data.
