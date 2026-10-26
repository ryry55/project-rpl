# DFD — Sistem Informasi Operasional Kos-Kosan

Notasi **Gane-Sarson** (versi utama) dan **Yourdon/DeMarco** (versi pembanding). Seluruh gambar
dihasilkan dari skrip, sehingga bisa diubah dan dibangun ulang kapan saja. Kedua versi memakai
data, proses, penyimpanan, dan aliran yang **identik** — sekarang bukan karena disiplin penulis,
tetapi karena keduanya membaca satu berkas data yang sama.

| Unsur | Gane-Sarson (`build.js`) | Yourdon / DeMarco (`build-yourdon.js`) |
|---|---|---|
| Entitas eksternal | kotak | kotak (sama) |
| Proses | persegi sudut tumpul | **elips** (lihat catatan di bawah) |
| Penyimpanan data | kotak terbuka di kanan | **dua garis sejajar** |
| Nomor proses | `1.0`, `2.0`, … | `1`, `2`, … |
| Nomor konteks | `0.0` | `0` |

## Berkas

| Berkas | Isi |
|---|---|
| `dfd-data.js` | **Sumber tunggal**: entitas, proses, penyimpanan, aliran, aturan, kamus data, tata letak |
| `dfd-gambar.js` | Cara menggambar + pemeriksa tata letak, arah panah, dan kelengkapan semantik |
| `build.js` | Pembangun gambar Gane-Sarson |
| `build-yourdon.js` | Pembangun gambar Yourdon/DeMarco |
| `build-readme.js` | Pembangun tabel di README ini dari `dfd-data.js` |
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
node build-readme.js
powershell -ExecutionPolicy Bypass -File export-png.ps1
```

Urutannya penting: `build-readme.js` membaca `dfd-data.js`, jadi jalankan setelah data berubah.
Bila tabel README tidak cocok dengan data, skrip itu menolak menulis dan menyebut bagian mana yang
menyimpang.

Ketiga skrip build memeriksa sendiri empat hal, dan **gagal** bila ada yang tidak terpenuhi:

1. tidak ada garis aliran yang menembus kotak atau elips;
2. setiap aliran berawal di tepi kotak asal dan berakhir di tepi kotak tujuan (menangkap panah
   terbalik dan panah yang berhenti di ruang kosong);
3. setiap aliran punya titik label dan teks label (menangkap panah tanpa keterangan);
4. tidak ada dua label yang saling menimpa.

Selain itu `build.js` memeriksa kelengkapan semantik: setiap penyimpanan data ditulis **dan** dibaca,
setiap proses punya masukan **dan** keluaran, setiap entitas terhubung, dan jumlah aliran batas
sistem di level 0 sama dengan di level 1.

Pemeriksaan itulah yang sebelumnya tidak ada. Cacat yang dilaporkan pada audit bukan kesalahan
menggambar, melainkan tidak adanya penjaga: aliran bisa hilang, panah bisa terbalik, dan data store
bisa menjadi *black hole* tanpa satu pun peringatan.

---

## 1. DFD Konteks (DFD Nol / Level 0)

Proses tunggal **0.0 Sistem Informasi Operasional Kos-Kosan** menghubungkan
**4 entitas eksternal** melalui **16 aliran data utama** (8 masuk, 8 keluar).

<!-- AWAL:aliran-konteks -->

**Aliran masuk** — dari entitas ke sistem:

| Kode | Dari | Ke | Nama aliran | Struktur data |
| --- | --- | --- | --- | --- |
| **I-1** | PEMILIK KOS | Proses 1.0 | data kamar, penghuni & kontrak | <code>nomor_kamar, tipe, nama, no_hp, email, tgl_masuk</code> |
| **I-2** | PEMILIK KOS | Proses 2.0 | tarif, kebijakan & komponen biaya | <code>kamar_id, nilai, berlaku_dari (tarif) · tipe, nilai, angka_pendukung, kamar_id (kebijakan)</code> |
| **I-3** | PEMILIK KOS | Proses 2.0 | keputusan persetujuan usulan | <code>usulan_id, keputusan, alasan</code> |
| **I-4** | PEMILIK KOS | Proses 3.0 | periode penagihan | <code>periode (bulan + tahun)</code> |
| **I-5** | PEMILIK KOS | Proses 4.0 | konfirmasi setoran uang | <code>tagihan_id, tanggal, jumlah, metode, diterima_oleh</code> |
| **I-6** | PENGELOLA | Proses 2.0 | usulan perubahan tarif & kebijakan | <code>jenis, tipe, nilai_baru, alasan, diajukan_oleh</code> |
| **I-7** | PENGELOLA | Proses 4.0 | pencatatan pembayaran tunai | <code>tagihan_id, tanggal, jumlah, metode=tunai, diterima_oleh</code> |
| **I-8** | GERBANG PEMBAYARAN | Proses 4.0 | notifikasi settlement | <code>order_id, transaction_status, fraud_status, gross_amount, signature_key</code> |

**Aliran keluar** — dari sistem ke entitas:

| Kode | Dari | Ke | Nama aliran | Struktur data |
| --- | --- | --- | --- | --- |
| **O-1** | Proses 1.0 | PENGHUNI | konfirmasi sewa & kontrak | <code>kontrak_id, kamar, tgl_mulai, tarif_awal</code> |
| **O-2** | Proses 2.0 | PEMILIK KOS | daftar usulan menunggu persetujuan | <code>usulan_id, tipe, nilai_lama, nilai_baru, batas_waktu</code> |
| **O-3** | Proses 3.0 | PENGHUNI | tagihan bulanan (pokok) | <code>periode, rincian[], pokok, total, jatuh_tempo</code> |
| **O-4** | Proses 3.0 | PENGELOLA | daftar tagihan terbit | <code>periode, daftar_tagihan[], jumlah_terbit</code> |
| **O-5** | Proses 4.0 | PENGHUNI | kuitansi & status pelunasan | <code>pembayaran_id, tanggal, jumlah, sisa_tunggakan</code> |
| **O-6** | Proses 5.0 | PEMILIK KOS | rekap pendapatan & hunian | <code>periode, total_pendapatan, jumlah_transaksi, occupancy_rate</code> |
| **O-7** | Proses 5.0 | PEMILIK KOS | daftar tunggakan & log audit | <code>daftar_tunggakan[], kategori_umur, baris_audit[]</code> |
| **O-8** | Proses 4.0 | GERBANG PEMBAYARAN | permintaan transaksi bayar | <code>order_id, gross_amount, nama_penghuni, deskripsi_tagihan</code> |

Jumlahnya 8 masuk dan 8 keluar. Angka yang sama muncul di level 1,
karena setiap aliran batas sistem digambar persis sekali di masing-masing tingkat.

<!-- AKHIR:aliran-konteks -->

Pada level ini belum ada penyimpanan data — itu muncul di level 1.

### Mengapa tiga entitas manusia, bukan dua

Sistem ini memisahkan **pemilik** dan **pengelola** sebagai dua akun yang berbeda. Pemilik adalah
pemegang keputusan; pengelola adalah akun yang ia buat dan beri wewenang terbatas. Pemisahan itu
bukan hiasan konseptual — ia mengubah aliran data secara nyata:

- Pengelola **mengusulkan** perubahan tarif dan kebijakan (I-6), dan usulan itu baru berlaku setelah
  pemilik memutuskan (I-3). Tanpa entitas pengelola, alur *maker-checker* ini tidak punya pelaku.
- Pengelola **mencatat pembayaran tunai yang ia pegang** (I-7), sehingga uang tunai di tangan dapat
  dipertanggungjawabkan per akun.

Sebelumnya pengelola digabung ke dalam "pemilik" dengan alasan kesederhanaan. Itu keliru: yang
disederhanakan hanya gambar, sementara kenyataan sistemnya tetap punya dua peran dengan wewenang
berbeda. Diagram yang menghapus perbedaan itu justru tidak bisa dipakai untuk menjelaskan mengapa
pengelola tidak boleh menetapkan tarif sendiri.

### Mengapa penghuni tidak punya aliran masuk

Portal penghuni bersifat **baca saja**. Pendaftaran sewa dilakukan pemilik atau pengelola, dan
pencatatan uang juga dilakukan mereka. Penghuni hanya menerima konfirmasi kontrak (O-1), tagihan
(O-3), dan kuitansi (O-5).

Karena itu penghuni diposisikan sebagai **sink** — penerima informasi, bukan pemasok data. Ini
penting untuk kejujuran diagram: menggambar panah "pendaftaran sewa" dari penghuni akan
menjanjikan fitur yang tidak ada.

### Mengapa gerbang pembayaran menjadi entitas keempat

Semula diagram ini hanya punya tiga entitas, dan gerbang pembayaran tidak digambar sama sekali.
Itu cacat, bukan penyederhanaan. `POST /webhook/midtrans` adalah endpoint yang **menulis baris
`pembayaran`** ke D5 tanpa ada manusia yang menekan tombol; kalau aktornya tidak ada di diagram,
aliran I-8 muncul dari ruang kosong. Sebaliknya `POST /tagihan/{id}/bayar-daring` mengirim
permintaan keluar ke Midtrans dan menyimpan `token` serta `redirect_url` yang dikembalikan — itu
O-8.

Bedanya dengan penghuni: penghuni **menerima** data dan tidak pernah memasoknya, sedangkan gerbang
pembayaran justru satu-satunya pihak luar yang memasok data uang. Karena itu ia harus digambar,
dan karena itu pula tanda tangannya diverifikasi serta `gross_amount`-nya dicocokkan dengan nilai
yang dibekukan di `transaksi_gateway` sebelum baris pembayaran dibuat.

---

## 2. DFD Level 1

**5 proses**, **6 penyimpanan data**, **4 entitas eksternal**, **39 aliran data**
(16 melintasi batas sistem, 23 internal).

### 2.1 Proses

<!-- AWAL:proses -->

| No | Nama proses | Aturan yang dijalankan | Keterangan |
| --- | --- | --- | --- |
| **1.0** | Kelola Kamar, Penghuni & Kontrak | — | Mendaftarkan kamar, menautkan penghuni, dan membuat kontrak sewa. Membaca kamar dan penghuni sebelum menulis kontrak — tanpa itu kontrak bisa menunjuk kamar yang sudah terisi. |
| **2.0** | Kelola Tarif, Kebijakan & Komponen Biaya | R-06 | Menetapkan tarif, kebijakan per dimensi, dan komponen biaya. Pemilik berlaku langsung; pengelola hanya mengusulkan, dan usulan menunggu keputusan pemilik. |
| **3.0** | Terbitkan Tagihan Bulanan | R-01, R-02, R-05 | Menghitung sewa prorata dan biaya tetap, lalu menerbitkan tagihan. Memeriksa tagihan yang sudah ada pada periode yang sama sebelum menulis. |
| **4.0** | Catat Pembayaran, Denda & Tunggakan | R-03, R-04 | Mencatat uang masuk, menghitung denda dari kebijakan berlaku, dan memutakhirkan status tagihan. Setiap perubahan meninggalkan baris audit. |
| **5.0** | Laporan & Rekap | R-07 | Menyusun rekap pendapatan, hunian, tunggakan, dan jejak audit. Hanya membaca — tidak pernah menulis ke penyimpanan data mana pun. |

<!-- AKHIR:proses -->

### 2.2 Penyimpanan data

<!-- AWAL:store -->

| Kode | Penyimpanan | Atribut utama | Keterangan |
| --- | --- | --- | --- |
| **D1** | Kamar | <code>id, nomor, tipe, status_huni</code> | Kamar beserta status huninya. Status hanya kosong atau terisi — tidak ada status perbaikan, karena perbaikan tidak pernah menghalangi penagihan. |
| **D2** | Penghuni & Kontrak | <code>penghuni: id, nama, no_hp, email · kontrak: id, kamar_id, penghuni_id, tgl_masuk, tgl_keluar, tarif_awal, status</code> | Data penghuni dan kontrak sewanya. tarif_awal membekukan tarif yang berlaku ketika kontrak ditandatangani. |
| **D3** | Tarif, Kebijakan & Komponen | <code>tarif_sewa: kamar_id, nilai, berlaku_dari · komponen_biaya: nama, nilai, satuan, kamar_id, berlaku_dari · kebijakan_kos: tipe, nilai, persen, plafon_persen, masa_tenggang, nominal, tanggal, kamar_id, berlaku_dari</code> | Tarif sewa, kebijakan kos per dimensi, dan komponen biaya. Bersifat tambah-saja: perubahan membuat baris baru dengan berlaku_dari sendiri, baris lama tidak pernah ditimpa. |
| **D4** | Tagihan | <code>id, kontrak_id, periode, pokok, total_final, jatuh_tempo, status, tarif_terpakai, kebijakan_terpakai</code> | Tagihan bulanan beserta salinan tarif dan kebijakan yang dipakai saat terbit. Denda tidak disimpan sebagai kolom: ia dihitung ulang setiap kali dibaca, dan hanya `total_final` yang dibekukan saat tagihan lunas. |
| **D5** | Pembayaran | <code>id, tagihan_id, tanggal_pembayaran, jumlah, metode, sumber, diterima_oleh, dicatat_oleh</code> | Uang yang benar-benar diterima, dicatat per tanggal pembayaran. Metode mencakup tunai, transfer, QRIS, dan gateway; sumber memisahkan yang dicatat orang dari yang datang lewat webhook. |
| **D6** | Log Audit | <code>id, aktor_id, aktor_nama, aksi, entitas, entitas_id, nilai_lama, nilai_baru, dibuat_pada</code> | Jejak setiap perubahan, ditulis tiga proses dan dibaca proses laporan. Bersifat tambah-saja dan dijaga trigger basis data, bukan hanya kode. |

<!-- AKHIR:store -->

### 2.3 Aliran data

<!-- AWAL:aliran-masuk -->

| Kode | Dari | Ke | Nama aliran | Struktur data |
| --- | --- | --- | --- | --- |
| **I-1** | PEMILIK KOS | Proses 1.0 | data kamar, penghuni & kontrak | <code>nomor_kamar, tipe, nama, no_hp, email, tgl_masuk</code> |
| **I-2** | PEMILIK KOS | Proses 2.0 | tarif, kebijakan & komponen biaya | <code>kamar_id, nilai, berlaku_dari (tarif) · tipe, nilai, angka_pendukung, kamar_id (kebijakan)</code> |
| **I-3** | PEMILIK KOS | Proses 2.0 | keputusan persetujuan usulan | <code>usulan_id, keputusan, alasan</code> |
| **I-4** | PEMILIK KOS | Proses 3.0 | periode penagihan | <code>periode (bulan + tahun)</code> |
| **I-5** | PEMILIK KOS | Proses 4.0 | konfirmasi setoran uang | <code>tagihan_id, tanggal, jumlah, metode, diterima_oleh</code> |
| **I-6** | PENGELOLA | Proses 2.0 | usulan perubahan tarif & kebijakan | <code>jenis, tipe, nilai_baru, alasan, diajukan_oleh</code> |
| **I-7** | PENGELOLA | Proses 4.0 | pencatatan pembayaran tunai | <code>tagihan_id, tanggal, jumlah, metode=tunai, diterima_oleh</code> |
| **I-8** | GERBANG PEMBAYARAN | Proses 4.0 | notifikasi settlement | <code>order_id, transaction_status, fraud_status, gross_amount, signature_key</code> |

<!-- AKHIR:aliran-masuk -->

<!-- AWAL:aliran-keluar -->

| Kode | Dari | Ke | Nama aliran | Struktur data |
| --- | --- | --- | --- | --- |
| **O-1** | Proses 1.0 | PENGHUNI | konfirmasi sewa & kontrak | <code>kontrak_id, kamar, tgl_mulai, tarif_awal</code> |
| **O-2** | Proses 2.0 | PEMILIK KOS | daftar usulan menunggu persetujuan | <code>usulan_id, tipe, nilai_lama, nilai_baru, batas_waktu</code> |
| **O-3** | Proses 3.0 | PENGHUNI | tagihan bulanan (pokok) | <code>periode, rincian[], pokok, total, jatuh_tempo</code> |
| **O-4** | Proses 3.0 | PENGELOLA | daftar tagihan terbit | <code>periode, daftar_tagihan[], jumlah_terbit</code> |
| **O-5** | Proses 4.0 | PENGHUNI | kuitansi & status pelunasan | <code>pembayaran_id, tanggal, jumlah, sisa_tunggakan</code> |
| **O-6** | Proses 5.0 | PEMILIK KOS | rekap pendapatan & hunian | <code>periode, total_pendapatan, jumlah_transaksi, occupancy_rate</code> |
| **O-7** | Proses 5.0 | PEMILIK KOS | daftar tunggakan & log audit | <code>daftar_tunggakan[], kategori_umur, baris_audit[]</code> |
| **O-8** | Proses 4.0 | GERBANG PEMBAYARAN | permintaan transaksi bayar | <code>order_id, gross_amount, nama_penghuni, deskripsi_tagihan</code> |

<!-- AKHIR:aliran-keluar -->

<!-- AWAL:aliran-internal -->

| Kode | Dari | Ke | Nama aliran | Struktur data |
| --- | --- | --- | --- | --- |
| **10** | Proses 1.0 | Store D1 | data kamar | <code>nomor, tipe, status_huni</code> |
| **11** | Proses 1.0 | Store D2 | kontrak sewa | <code>kontrak_id, penghuni, kamar, tgl_masuk, tarif_awal</code> |
| **12** | Proses 2.0 | Store D3 | tarif, kebijakan & komponen | <code>tarif: kamar_id, nilai, berlaku_dari · kebijakan: tipe, nilai, angka, kamar_id, berlaku_dari</code> |
| **13** | Proses 2.0 | Store D6 | log audit perubahan | <code>aktor, aksi, nilai_lama, nilai_baru, waktu</code> |
| **14** | Proses 3.0 | Store D4 | tagihan | <code>periode, pokok, jatuh_tempo, status, tarif_terpakai, kebijakan_terpakai</code> |
| **15** | Proses 3.0 | Store D6 | log audit penerbitan | <code>aktor, aksi, tagihan_id, waktu</code> |
| **16** | Proses 4.0 | Store D4 | status tagihan & total final | <code>status, total_final, jatuh_tempo</code> |
| **17** | Proses 4.0 | Store D5 | pembayaran | <code>tagihan_id, tanggal_pembayaran, jumlah, metode, sumber</code> |
| **18** | Proses 4.0 | Store D6 | log audit pembayaran | <code>aktor, aksi, pembayaran_id, waktu</code> |
| **19** | Store D1 | Proses 1.0 | ketersediaan kamar | <code>kamar_id, status_huni</code> |
| **20** | Store D2 | Proses 1.0 | identitas penghuni | <code>penghuni_id, nama, no_hp, email</code> |
| **21** | Store D2 | Proses 3.0 | kontrak aktif | <code>kontrak_id, kamar, tgl_masuk, tarif_awal</code> |
| **22** | Store D3 | Proses 3.0 | tarif & kebijakan berlaku | <code>tipe, nilai, angka_pendukung, berlaku_dari, kamar_id</code> |
| **23** | Store D4 | Proses 3.0 | validasi duplikasi periode | <code>kontrak_id, periode</code> |
| **24** | Store D3 | Proses 4.0 | kebijakan denda | <code>bentuk_denda, tarif_denda_persen, plafon_persen, masa_tenggang</code> |
| **25** | Store D4 | Proses 4.0 | tagihan belum lunas | <code>tagihan_id, pokok, jatuh_tempo, status</code> |
| **26** | Store D5 | Proses 4.0 | riwayat pembayaran | <code>tagihan_id, jumlah, tanggal_pembayaran</code> |
| **32** | Store D3 | Proses 2.0 | nilai lama yang digantikan | <code>tarif: kamar_id, nilai, berlaku_dari · kebijakan: tipe, nilai, angka, kamar_id</code> |
| **27** | Store D1 | Proses 5.0 | data kamar | <code>nomor, status_huni</code> |
| **28** | Store D2 | Proses 5.0 | kontrak | <code>kamar, penghuni, periode</code> |
| **29** | Store D4 | Proses 5.0 | tagihan | <code>periode, pokok, denda, status</code> |
| **30** | Store D5 | Proses 5.0 | pembayaran | <code>tanggal_pembayaran, jumlah, metode</code> |
| **31** | Store D6 | Proses 5.0 | log audit | <code>aktor, aksi, entitas, waktu</code> |

<!-- AKHIR:aliran-internal -->

### 2.4 Keseimbangan level 0 dan level 1

Ini yang sebelumnya rusak. Pada level 0, sistem mengeluarkan **dua** aliran terpisah ke pemilik:
rekap pendapatan & hunian, dan daftar tunggakan & peringatan. Pada level 1, keduanya digabung
menjadi satu panah — kata "peringatan" hilang tanpa jejak, dan jumlah aliran yang melintasi batas
sistem menjadi tidak seimbang.

Sekarang keluaran proses 5.0 dipecah menjadi dua aliran (O-6 dan O-7), sehingga jumlahnya
seimbang. `build.js` memeriksa keseimbangan ini secara otomatis: setiap aliran batas wajib punya
rute di kedua tingkat, dan hitungannya harus sama — kini **8 masuk dan 8 keluar**.

---

## 3. Kamus data

<!-- AWAL:kamus -->

```
kamar = nomor_kamar + tipe + status_huni
status_huni = "kosong" | "terisi"
penghuni = nama + no_hp + email
kontrak = kontrak_id + penghuni + kamar + tgl_masuk + tgl_keluar + tarif_awal + status_kontrak
status_kontrak = "aktif" | "selesai"
tgl_masuk = tanggal
tgl_keluar = tanggal | kosong (berarti masih aktif)
tarif_sewa = kamar_id + nilai + berlaku_dari
komponen_biaya = nama + nilai + satuan + kamar_id + berlaku_dari
satuan_komponen = "rupiah/bulan" | "rupiah/orang"
kebijakan_kos = tipe + nilai + angka_pendukung + kamar_id + berlaku_dari
angka_pendukung = persen + plafon_persen + masa_tenggang + nominal + tanggal
tipe_kebijakan = "satuan_sewa" | "jatuh_tempo" | "denda"
bentuk_denda = "persen_harian" | "persen_bulanan" | "nominal_tetap" | "tanpa_denda"
persen_denda = persen per hari atau per bulan (0..100), dihitung dari pokok
plafon_persen = 0..100
masa_tenggang = jumlah_hari
tagihan = tagihan_id + kontrak_id + periode + pokok + total_final + jatuh_tempo
      + status_tagihan + tarif_terpakai + kebijakan_terpakai
periode = bulan + tahun
rincian = tagihan_rincian: { nama + nilai + urutan } +
pokok = jumlah(rincian.nilai)
sewa_prorata = bulat(tarif_dasar * hari_ditagih / hari_dalam_bulan)
total_final = pokok + denda, dibekukan tepat saat tagihan menjadi lunas
hari_denda = max(0, selisih - masa_tenggang)
denda = 0  jika selisih <= masa_tenggang
      = min(bulat(pokok * hari_denda * persen_denda / 100),
            bulat(pokok * plafon_persen / 100))  jika selisih > masa_tenggang
selisih = hari_ini - jatuh_tempo (selisih tanggal kalender, zona Asia/Jakarta)
status_tagihan = "belum_bayar" | "sebagian" | "lunas" | "dibatalkan"
pembayaran = pembayaran_id + tagihan_id + tanggal + jumlah + metode + sumber
      + diterima_oleh + dicatat_oleh + referensi
metode = "tunai" | "transfer" | "qris" | "gateway"
sumber = "dicatat_pemilik" | "gateway_webhook"
log_audit = aktor + aksi + entitas + entitas_id + waktu + nilai_lama + nilai_baru
kategori_umur = "belum_jatuh_tempo" | "0_30" | "31_60" | "lebih_60"
```

<!-- AKHIR:kamus -->

---

## 4. Aturan bisnis di balik diagram

<!-- AWAL:aturan -->

| Kode | Aturan | Proses | Yang diuji |
| --- | --- | --- | --- |
| **R-01** | Sewa bulan pertama dihitung prorata: tarif x hari_ditagih / hari_dalam_bulan | 3.0 | hari 1, 15, 28 (Feb), 29 (kabisat), 31 |
| **R-02** | Satu kontrak hanya boleh punya satu tagihan per periode; terbit ulang ditolak | 3.0 | periode sama / berbeda / setelah dibatalkan |
| **R-03** | Denda mulai hari ke-8 setelah jatuh tempo, 1% dari pokok per hari, plafon 30% dari pokok | 4.0 | selisih hari 7 / 8, hari ke-37 (plafon tercapai), hari setelah plafon |
| **R-04** | Tunggakan dikelompokkan belum_jatuh_tempo / 0-30 / 31-60 / lebih dari 60 hari | 4.0 | hari -1 (belum jatuh tempo), 0, 30 / 31, 60 / 61 |
| **R-05** | Kebijakan wajib lengkap sebelum tagihan pertama: tanpa satuan sewa, jatuh tempo, dan denda, penerbitan ditolak | 3.0 | terbit sebelum lengkap / sesudah lengkap / kamar berpenimpa |
| **R-06** | Tarif tidak berlaku surut - perubahan hanya untuk periode berikutnya, tagihan lama memakai salinan saat terbit | 2.0 | transaksi sebelum / sesudah perubahan |
| **R-07** | Penghuni hanya melihat tagihannya sendiri; setiap perubahan status tercatat | 4.0, 5.0 | lintas akun |

<!-- AKHIR:aturan -->

---

## 5. Catatan desain untuk presentasi

Lima hal yang layak dijelaskan bila ditanya "kenapa begitu":

1. **Mengapa proses 3.0 membaca D4?** Aturan R-02 melarang dua tagihan untuk satu kontrak pada
   periode yang sama. Proses 3.0 wajib **memeriksa** tagihan yang sudah ada sebelum menulis.
   Mengandalkan indeks unik di basis data saja akan membuat pelanggaran terdeteksi sebagai galat
   teknis, bukan sebagai penolakan yang bisa dijelaskan ke pengguna.

2. **Mengapa proses 4.0 menulis balik ke D4?** Ketika uang diterima, status tagihan berubah menjadi
   `sebagian` atau `lunas`. Tanpa panah tulis balik, diagram akan menampilkan tagihan yang sudah
   dibayar sebagai belum bayar selamanya.

3. **Mengapa D6 ditulis tiga proses dan dibaca satu?** Log audit bukan catatan satu transaksi.
   Perubahan tarif, penerbitan tagihan, dan pencatatan pembayaran semuanya harus meninggalkan jejak.
   Dan karena proses 5.0 membacanya, D6 berhenti menjadi *black hole* — data store yang menerima
   tulisan tapi tidak pernah dibaca adalah cacat formal DFD.

4. **Mengapa tidak ada proses pencatatan meter?** Sistem menyasar kos dengan sewa all-in.
   Sub-metering utilitas sengaja diletakkan di daftar *tidak dikerjakan*, bukan karena keterbatasan
   waktu, tetapi karena segmen yang disasar tidak memisahkan biaya listrik dan air.

5. **Mengapa deposit tidak ada sama sekali?** Deposit dulu **dicatat** saat kontrak
   dibuat, besarannya dibaca dari kebijakan kos lewat `PenentuDeposit`, tetapi ia tidak
   pernah dicatat sebagai uang masuk, tidak pernah mengurangi tagihan, dan tidak pernah
   dikembalikan saat penghuni keluar. Artinya diagram ini akan menggambar uang yang tidak
   pernah dipertanggungjawabkan sistem. Pemilik memutuskan menghapusnya seluruhnya di
   fase terakhir: kolom `kontrak.deposit`, tipe kebijakan `deposit`, dan kelas
   `PenentuDeposit` sudah tidak ada. Alasan lengkapnya di `docs/utang-teknis.md`; yang
   tersisa sebagai utang adalah **antrean email** saja. Gambar QRIS sudah tersimpan
   (`rekening.gambar`, kolom `bytea`) sejak fase terakhir.

---

## 6. Catatan pemilihan notasi

Dua versi disediakan supaya bisa menyesuaikan dengan kebiasaan dosen pengampu atau contoh di modul
praktikum. Isinya sama, jadi **pakai salah satu** — jangan keduanya di satu laporan, karena akan
terlihat seperti dua gambar yang saling bertentangan.

| Aspek | Gane-Sarson | Yourdon / DeMarco |
|---|---|---|
| Simbol proses | persegi sudut tumpul | elips |
| Simbol penyimpanan data | kotak terbuka di sisi kanan | dua garis sejajar |
| Kelebihan | ruang teks lebih lega, cocok untuk nama proses panjang | bentuk proses paling mudah dibedakan dari entitas saat diproyeksikan |
| Kekurangan | proses dan penyimpanan sama-sama kotak, sekilas mirip | bentuknya menyimpang dari lingkaran baku |

**Catatan tentang bentuk proses Yourdon.** Yourdon/DeMarco baku memakai **lingkaran**. Di sini
dipakai **elips**, dan itu disengaja: nama proses kos-kosan tidak muat di dalam lingkaran seukuran
apa pun yang masih menyisakan ruang untuk panah. Memaksakan lingkaran berarti mengecilkan huruf
sampai tidak terbaca, atau memendekkan nama sampai kehilangan arti. Elips tetap membedakan proses
dari entitas (kotak) dan penyimpanan (dua garis) — satu-satunya hal yang penting dari bentuknya.
Bila dosen menuntut lingkaran baku, ganti `prosesYourdon` di `dfd-gambar.js` dan perpendek
`namaYourdon` di `dfd-data.js`.

Nomor proses juga mengikuti konvensi masing-masing notasi: `1.0`–`5.0` pada Gane-Sarson, `1`–`5`
pada Yourdon. Dekomposisi level 2 di `03-dfd-level-2.md` memakai penomoran Gane-Sarson
(`3.1`, `3.2`, …); bila memakai versi Yourdon, baca sebagai `3.1`, `3.2`, … dengan proses induk `3`.
