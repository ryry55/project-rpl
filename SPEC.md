# SPEC — Sistem Informasi Operasional Kos-Kosan

Status: **final, siap dipecah jadi tiket**. Tanggal: 22 Oktober 2026.
Sumber: `docs/charter/Project_Charter_Kos-Kosan.docx`, `docs/dfd/README.md`,
`docs/dfd/03-dfd-level-2.md`, `docs/build-plan.md`.

Dokumen ini adalah **kontrak**. Kalau implementasi ingin menyimpang, ubah dokumen ini dulu —
jangan diam-diam berbeda. Yang tidak diatur di sini bukan berarti bebas; artinya belum diputuskan.

---

## 1. Masalah dan sasaran

Pemilik kos kecil (5–30 kamar) mengelola semuanya sendiri: menagih sewa, menghitung prorata
penghuni baru, menghitung denda keterlambatan, dan mencatat siapa yang menunggak. Pekerjaan itu
sekarang dilakukan dengan buku catatan dan ingatan, dan tiga kesalahan berulang terjadi:

1. **Prorata salah hitung** saat penghuni masuk di tengah bulan — sering dibulatkan ke atas
   (merugikan penghuni) atau ke bawah (merugikan pemilik), dan tidak konsisten antar bulan.
2. **Denda tidak konsisten** — kadang lupa ditagih, kadang ditagih dengan angka yang berbeda
   untuk kasus yang sama, dan tidak ada batas sehingga bisa menumpuk tanpa akhir.
3. **Tunggakan tidak terpantau** — pemilik tidak tahu siapa yang terlambat 10 hari versus
   70 hari, padahal penanganannya berbeda.

Aplikasi ini menggantikan buku catatan untuk tiga pekerjaan itu. Nilainya bukan "CRUD kamar dan
penghuni" — itu hanya prasyarat. Nilainya ada di **perhitungan yang benar dan dapat
dipertanggungjawabkan**, dan di **bukti bahwa perhitungan itu benar** (tes batas).

**Sasaran yang bisa diukur:**

| # | Sasaran | Ukuran |
|---|---|---|
| S1 | Prorata selalu benar | Nol selisih antara tagihan tersimpan dan hitungan manual pada 6 kasus batas |
| S2 | Denda konsisten dan terbatas | Denda berhenti di 30% pokok, terbukti pada hari ke-37 |
| S3 | Tunggakan terkelompok | Dashboard menampilkan 0–30 / 31–60 / >60 hari |
| S4 | Penghuni tidak bisa melihat tagihan orang lain | 404 pada percobaan akses silang |

**Bukan sasaran:** akuntansi, absensi, sub-metering listrik/air (sewa sudah all-in), payment
gateway, aplikasi mobile native, notifikasi WhatsApp.

---

## 2. Pengguna dan peran

Hanya **dua** peran. "Petugas" sudah dilebur ke Pemilik — kos sebesar ini tidak punya karyawan
administrasi.

| Peran | Siapa | Yang boleh dilakukan |
|---|---|---|
| **Pemilik** | Pemilik kos, satu orang | Semua: kamar, penghuni, kontrak, tarif, parameter denda, terbit tagihan, catat pembayaran, lihat semua tagihan dan tunggakan |
| **Penghuni** | Penyewa kamar | Hanya melihat tagihannya sendiri dan riwayat pembayarannya. Tidak bisa mengubah apa pun. |

Satu akun pemilik, satu akun per penghuni. Tidak ada pendaftaran mandiri — akun dibuat pemilik.

---

## 3. Kosakata

Aturan dari `AGENTS.md`: istilah domain **Indonesia** (dipakai di laporan, DFD, dan UI), mekanika
kode **Inggris** (nama kelas, nama tes). Tabel dan kolom basis data mengikuti DFD supaya laporan
dan skema bisa dibaca berdampingan.

| Indonesia (domain, UI, DB) | Inggris (kode) | Arti tepat |
|---|---|---|
| tagihan | `Invoice` | Satu kewajiban bayar untuk satu kontrak pada satu periode |
| pokok | `principal` | Sewa (setelah prorata bila bulan pertama) + biaya tambahan tetap. **Tidak termasuk denda.** |
| denda | `penalty` | Tambahan karena terlambat. Dihitung dari pokok, tidak berbunga |
| tunggakan | `arrears` | Tagihan yang belum lunas **dan** sudah lewat jatuh tempo |
| umur tunggakan | `aging` | Selisih hari antara hari ini dan jatuh tempo |
| penghuni | `Tenant` | Orang yang menyewa |
| kontrak | `Contract` | Ikatan penghuni ↔ kamar, punya tanggal masuk dan tarif |
| prorata | `prorate` | Sewa sebulan dihitung sebanding jumlah hari tinggal |
| masa tenggang | `grace period` | Hari setelah jatuh tempo sebelum denda mulai (7 hari) |
| plafon | `cap` | Batas maksimum denda (30% pokok) |

Jangan memakai sinonim: bukan "invoice" di UI, bukan "sewa" sebagai pengganti "pokok".

---

## 4. Aturan bisnis

Tujuh aturan ini sudah tertulis di charter dan DFD. Di sini ditambahkan **nilai batas** supaya
bisa diuji.

### R-01 — Prorata bulan pertama

> Sewa bulan pertama dihitung `tarif × hari_tinggal / hari_dalam_bulan`, dibulatkan ke rupiah penuh.
> Bulan penuh dibayar tarif penuh.

```
hari_tinggal = hari_dalam_bulan - tanggal_masuk + 1     (hari masuk ikut dihitung)
```

| Kasus | Nilai |
|---|---|
| Masuk tanggal 1 | `hari_tinggal = hari_dalam_bulan` → **persis** tarif penuh, bukan tarif − Rp 1 |
| Masuk 15 Oktober (31 hari) | `17/31` → 1.500.000 × 17 / 31 = 822.580,65 → **Rp 822.581** |
| Masuk 31 Oktober | `1/31` → **Rp 48.387** |
| Masuk 28 Februari (28 hari) | `1/28` → **Rp 53.571** |

Prorata **hanya untuk bulan berjalan saat masuk**. Bulan-bulan berikutnya selalu tarif penuh,
walaupun penghuni keluar di tengah bulan — tidak ada prorata keluar, karena sisa bulan tidak
dikembalikan (aturan ini dinyatakan eksplisit supaya tidak dianggap lupa).

**Pembulatan:** hitung eksak → bulatkan half-up → **simpan angka yang sudah bulat**. Jangan
pernah menyimpan angka berbeda dari yang ditampilkan. Total = jumlah baris yang sudah bulat.

### R-02 — Satu tagihan per kontrak per periode

> Tidak boleh ada dua tagihan untuk kontrak yang sama pada periode yang sama. Percobaan kedua ditolak.

Ditegakkan oleh **unique index**, bukan pengecekan di aplikasi:

```sql
CREATE UNIQUE INDEX tagihan_kontrak_periode_unique ON tagihan (kontrak_id, periode);
```

Aplikasi menangkap SQLSTATE `23505` dan membalas **HTTP 409**. Pengecekan `exists()` boleh ada
sebelumnya untuk pesan yang lebih ramah, **tapi bukan sebagai pengaman** — dua request bersamaan
bisa lolos keduanya. Tombol "Terbitkan" ditekan dua kali cepat harus menghasilkan 409, bukan dua
baris.

### R-03 — Denda keterlambatan

> Denda mulai hari ke-8 setelah jatuh tempo, sebesar **1% dari pokok per hari**, dibatasi
> **30% dari pokok**. Denda berhenti bertambah saat plafon tercapai.

```
hari_denda   = max(0, hari_terlambat - 7)
denda        = min( bulat(pokok × hari_denda × 1 / 100),
                   bulat(pokok × 30 / 100) )
```

Karena 1% × 30 hari = 30%, **plafon selalu tercapai tepat di hari denda ke-30 = hari terlambat
ke-37**. Contoh pokok Rp 1.500.000:

| Hari terlambat | Hari denda | Denda |
|---|---|---|
| 0–7 | 0 | Rp 0 |
| 8 | 1 | Rp 15.000 |
| 30 | 23 | Rp 345.000 |
| **37** | **30** | **Rp 450.000** ← plafon |
| 365 | 358 | Rp 450.000 (tetap) |

Batas atas yang bisa ditagih = **130% dari pokok**.

Dua hal yang wajib benar dan mudah salah:

- **Hitung sekali jalan, jangan diakumulasi per hari.** Menjumlahkan 30 kali hasil pembulatan
  membuat total meleset dari plafon.
- **Dasar denda adalah pokok yang dibekukan saat tagihan terbit.** Pembayaran sebagian tidak
  menurunkan dasar. Kalau dasarnya sisa tagihan, mencicil justru menurunkan plafon — dan menunda
  bayar jadi lebih menguntungkan daripada mencicil.

Denda **tetap berjalan** sampai tagihan lunas penuh, walau sudah dibayar sebagian.

**Parameter denda dibekukan saat tagihan terbit.** Tagihan menyimpan `tarif_denda_persen`,
`plafon_persen`, dan `masa_tenggang` yang berlaku saat itu. Pemilik mengubah parameter di kemudian
hari **tidak** mengubah tagihan lama.

### R-04 — Umur tunggakan

> Tunggakan dikelompokkan menurut selisih hari terhadap jatuh tempo.

| Kelompok | Kondisi | Arti |
|---|---|---|
| **Belum jatuh tempo** | `hari_terlambat < 0` | Belum waktunya bayar |
| **0–30 hari** | `0 ≤ hari_terlambat ≤ 30` | Baru terlambat, ingatkan |
| **31–60 hari** | `31 ≤ hari_terlambat ≤ 60` | Perlu tindakan |
| **> 60 hari** | `hari_terlambat ≥ 61` | Perlu keputusan: tegur atau keluarkan |

Tagihan berstatus `lunas` dan `dibatalkan` **tidak pernah** masuk tunggakan. Batas 30/31 dan
60/61 adalah titik uji wajib.

### R-05 — Kebijakan kos wajib lengkap sebelum tagihan pertama

> Penerbitan tagihan ditolak selama satuan sewa, jatuh tempo, dan denda belum diisi pemilik.

Tanpa ketiga dimensi itu, angka tagihan tidak punya arti: satuan sewa menentukan dasar hitung,
jatuh tempo menentukan kapan denda mulai berjalan, dan denda menentukan besar konsekuensinya.
Menebak salah satunya berarti menerbitkan tagihan yang tidak bisa dipertanggungjawabkan, dan
tagihan yang sudah terbit **tidak bisa** ditarik kembali angkanya (R-06).

Karena itu `POST /tagihan` dijawab **422** sambil menyebut tipe mana yang masih kurang, bukan
menerbitkan tagihan dengan angka tebakan. Penegaknya `KebijakanBelumLengkap`; layar Kebijakan
menandai kartu yang masih menghalangi penagihan supaya pemilik tahu apa yang harus diisi lebih
dulu.

Dimensi kebijakan ada **tiga** (`satuan_sewa`, `jatuh_tempo`, `denda`). Sempat direncanakan empat
dengan `deposit` sebagai dimensi terpisah; dimensi itu dihapus bersama seluruh jejak deposit —
lihat batas sistem di bawah.

#### Batas sistem: deposit

> Sistem ini **tidak** memungut, mencatat, maupun mengembalikan deposit.

Deposit **bukan** salah satu dari tujuh aturan bisnis. Ia ketiadaan fitur, bukan aturan yang
ditegakkan, dan karena itu sengaja tidak diberi nomor R-*.

Deposit pernah ada sebagai kolom `kontrak.deposit` yang diisi dari kebijakan kos. Kolom itu
dikeluarkan seluruhnya di fase terakhir, bersama tipe kebijakan `deposit` dan kelas
`PenentuDeposit`. Alasannya bukan kerapian, melainkan pertanggungjawaban uang: deposit
dikumpulkan saat kontrak dibuat, tetapi tidak pernah dicatat sebagai uang masuk, tidak pernah
mengurangi tagihan, dan tidak pernah dikembalikan saat penghuni keluar. Sistem menampung janji
uang yang tidak pernah ia pertanggungjawabkan.

Dua jalan keluarnya adalah (a) merekam deposit sebagai pembayaran sah lalu mengembalikannya lewat
baris negatif, atau (b) berhenti berpura-pura menanganinya. Pemilik memilih (b), dengan alasan:
**kolom yang menyimpan angka tanpa akibat lebih buruk daripada kolom yang tidak ada, karena ia
membuat orang mengira uangnya terjaga.**

Konsekuensi yang diterima: kos yang memang memungut deposit mencatatnya di luar sistem. Ini
konsisten dengan batas yang sudah dinyatakan di §1.2 — sistem ini menagih sewa, bukan
mengelola titipan uang.

### R-06 — Tarif tidak berlaku surut

> Mengubah tarif hanya berlaku untuk tagihan yang terbit **setelah** perubahan. Tagihan yang sudah
> terbit tidak pernah berubah angkanya.

Dua lapis pertahanan, keduanya wajib:

1. **Riwayat tarif.** `tarif_sewa` punya `berlaku_dari` dan baris lama **tidak pernah di-UPDATE**.
   Tarif yang dipakai untuk periode P = baris dengan `berlaku_dari` terbesar yang ≤ awal periode P.
2. **Snapshot di tagihan.** `tagihan.tarif_terpakai` menyimpan nilai tarif dan komponen yang
   dipakai, sebagai JSON beku.

Lapis 2 yang sebenarnya menegakkan aturan ini. Tanpa snapshot, mengubah data master tetap
mengubah tagihan lama lewat join.

Titik uji: buat tagihan Oktober → ubah tarif berlaku November → tagihan Oktober **tidak berubah**.

### R-07 — Isolasi penghuni dan log audit

> Penghuni hanya bisa melihat tagihan dan pembayarannya sendiri. Setiap perubahan status tagihan
> dicatat di log audit yang tidak bisa diubah atau dihapus.

**Isolasi.** Setiap query tagihan untuk peran penghuni difilter di sumbernya
(`where penghuni_id = auth()->penghuni_id`), sehingga tagihan milik orang lain **tidak pernah
dimuat** dan hasilnya **HTTP 404**, bukan 403. 404 dipilih supaya keberadaan tagihan orang lain
tidak bocor lewat perbedaan status code.

**Log audit append-only.** Tabel `log_audit` hanya menerima INSERT. Tidak ada endpoint ubah atau
hapus. Ditegakkan di basis data dengan `REVOKE UPDATE, DELETE` (atau trigger penolak), bukan hanya
dengan kesepakatan. Kalau log bisa diedit, log tidak membuktikan apa pun.

Yang dicatat: pembuatan tagihan, perubahan status tagihan, pencatatan pembayaran, dan perubahan
parameter/tarif. Isi: aktor, aksi, entitas, id entitas, waktu, nilai lama, nilai baru (JSON).

---

## 5. Keputusan teknis (mengikat)

| Hal | Keputusan | Konsekuensi kalau dilanggar |
|---|---|---|
| Uang | `numeric(14,0)` / rupiah bulat / `bcmath`. **Tidak ada float** | Selisih Rp 1 pada plafon 30% |
| Waktu | `app.timezone = 'Asia/Jakarta'`; `jatuh_tempo` bertipe `date` | `hari_terlambat` meleset sehari |
| Periode | kolom `periode` bertipe `date`, diisi **tanggal 1** bulan tersebut | Urutan dan unique index jadi rumit |
| Jatuh tempo | tanggal hari masuk penghuni, **dijepit ke akhir bulan** | Bug yang muncul 4× setahun |
| Denda | dihitung saat dibaca (derived), tidak disimpan | Nilai basi; harus ada job penyegar |
| Parameter denda | dibekukan (snapshot) di tagihan saat terbit | Tagihan lama berubah saat parameter diubah |
| R-02 | unique index + tangkap `23505` → 409 | Duplikat lolos saat request bersamaan |
| Auth | Laravel Sanctum, token bearer, dua peran | — |

**Jepit jatuh tempo.** `jatuh_tempo(tagihan) = clamp(hari(tgl_masuk), jumlah_hari_bulan(periode))`.

| Masuk | Periode | Jatuh tempo |
|---|---|---|
| 31 Jan 2026 | Feb 2026 | **28 Feb 2026** |
| 30 Jan 2026 | Feb 2026 | **28 Feb 2026** |
| 31 Mar 2026 | Apr 2026 | **30 Apr 2026** |
| 15 Okt 2026 | Okt 2026 | **15 Okt 2026** |
| 15 Okt 2026 | Nov 2026 | **15 Nov 2026** |

Tagihan pertama karena itu jatuh tempo **pada tanggal masuk** — sewa dibayar saat masuk. Bulan
berikutnya jatuh tempo pada tanggal yang sama di bulan itu.

**Jatuh tempo bulan masuk tidak boleh mendahului tanggal masuk.** Kebijakan *tanggal tetap 15*
berarti sewa jatuh tempo tiap tanggal 15. Penghuni yang masuk tanggal 16 September akan mendapat
tagihan September yang jatuh temponya 15 September — sehari **sebelum** ia menempati kamarnya.
Tagihan itu lahir dalam keadaan sudah terlambat, dan dendanya berjalan dari tanggal ia belum
berutang apa pun. Pokoknya pun sudah diprorata 15/30, jadi menagihnya lebih cepat daripada porsi
yang ia pakai tidak punya dasar.

Karena itu, **pada bulan masuk saja**, jatuh tempo dinaikkan sampai tanggal masuk. Bulan berikutnya
tetap persis tanggal 15. Cara `tanggal_masuk` dan `akhir_bulan` tidak terpengaruh — keduanya sudah
menghasilkan tanggal yang tidak pernah mendahului hari masuk.

| Masuk | Periode | Kebijakan | Jatuh tempo |
|---|---|---|---|
| 16 Sep 2026 | Sep 2026 | tanggal tetap 15 | **16 Sep 2026** (dinaikkan) |
| 16 Sep 2026 | Okt 2026 | tanggal tetap 15 | **15 Okt 2026** (apa adanya) |
| 16 Sep 2026 | Sep 2026 | tanggal masuk | 16 Sep 2026 |
| 16 Sep 2026 | Sep 2026 | akhir bulan | 30 Sep 2026 |

**Deviasi sadar dari DFD.** DFD menaruh `tarif_dasar` di D1 (Kamar). Implementasi memindahkannya
ke `tarif_sewa` di D3, karena riwayat tarif butuh tabel sendiri. DFD bersifat logis, bukan
fisik — `tarif_dasar` di D1 dibaca sebagai "tarif kamar", bukan sebagai kolom yang harus ada.

**Deviasi sadar dari SPEC awal, dicatat saat T2.** Empat hal di bawah ini berbeda dari rumusan
awal §6. Semuanya karena rumusan awal bertentangan dengan aturan bisnis yang sudah disepakati,
bukan karena lebih mudah dikerjakan:

| Hal | SPEC awal | Implementasi | Alasan |
|---|---|---|---|
| `komponen_biaya.aktif` | ada kolom `aktif (bool)` | **kolom tidak dibuat** | Kolom yang bisa di-UPDATE bertentangan dengan append-only. Menghentikan komponen = baris baru bernilai 0 |
| Email penghuni | tidak ada | `penghuni.email` unik | Setiap penghuni harus bisa login (R-07), dan login butuh email |
| Relasi penghuni–user | `penghuni.user_id` | `users.penghuni_id` | Satu arah saja; kolom di `users` sudah ada sejak T1 dan dipakai `auth` |
| Parameter denda | `GET/PUT /parameter-denda` | satu `tipe` di `kebijakan_kos` | Tabel `parameter_denda` dihapus: denda adalah salah satu dimensi kebijakan, dan memisahkannya berarti snapshot tagihan harus menyalin dua tabel |

**Append-only ditegakkan basis data, bukan hanya aplikasi.** `tarif_sewa`, `komponen_biaya`, dan
`kebijakan_kos` punya trigger yang menolak `UPDATE` dan `DELETE`. Endpoint yang tidak ada hanya
menahan lewat aplikasi; trigger menahan lewat `psql` juga — termasuk bagi superuser, yang tidak
tertahan oleh `REVOKE`.

**Tarif kontrak berasal dari satu sumber.** `kontrak.tarif_awal` hanya keterangan; tagihan selalu
membaca `tarif_sewa`. Bila pemilik mengisi tarif di formulir kontrak padahal kamar sudah punya
tarif yang berlaku, permintaan **ditolak 422** dengan pesan yang menyebut tarif mana yang berlaku
— bukan diterima lalu salah satu angka diabaikan diam-diam.

**Simulasi tanggal untuk demo.** Karena denda bergantung pada "hari ini", sediakan override
`X-Simulasi-Tanggal: 2026-12-01` yang **hanya aktif** bila `APP_DEMO_MODE=true`. Tanpa ini,
mendemokan "hari ke-37" berarti menunggu 37 hari. Endpoint dan header ini tidak ada di produksi.

**Keputusan T6, dicatat saat implementasi.**

| Hal | Keputusan | Alasan |
|---|---|---|
| Sumber "hari ini" | satu kelas `App\Support\WaktuSistem`, singleton, di-reset middleware `SimulasiTanggal` di awal setiap permintaan | Denda dan umur tunggakan harus memakai tanggal acuan yang sama. Tanpa satu sumber, dua layar bisa menampilkan umur tunggakan berbeda untuk tagihan yang sama. Tanpa reset, tanggal simulasi bocor ke permintaan berikutnya |
| Header tidak sah | **diabaikan**, bukan 400 | Demo tidak boleh gagal karena salah ketik tanggal. Respons tetap mengumumkan `X-Simulasi-Dipakai: false` supaya tidak ada tebakan |
| Pengumuman status simulasi | di **`meta` badan respons**, bukan hanya header | `exposed_headers` CORS default kosong, jadi `fetch()` tidak bisa membaca header lintas-origin. Header tetap dikirim dan didaftarkan di `exposed_headers` |
| Denda disimpan? | **tidak**, dihitung saat dibaca | Nilainya bergantung pada "hari ini"; kolom akan selalu basi dan butuh job penyegar |
| Dasar denda | `tagihan.pokok` yang dibekukan, bukan sisa tagihan | Bila dasarnya sisa, penghuni bisa menurunkan denda dengan membayar Rp 1 |
| Parameter denda | dibaca dari **kolom snapshot di baris `tagihan`**, bukan `kebijakan_kos` terkini | Itu inti R-06 lapis 2. Membaca parameter terkini membuat denda tagihan lama berubah saat parameter diubah |
| Tarif 0% | sah, berarti tanpa denda | Cara mematikan denda tanpa mengubah skema; tidak boleh menjadi pembagian dengan nol |
| Persentase | diurai dari **teks**, bukan di-cast ke float | 1,15% tidak bisa direpresentasikan eksak sebagai float; pembulatan denda bisa meleset Rp 1 |

**Keputusan T7, dicatat saat implementasi.**

| Hal | Keputusan | Alasan |
|---|---|---|
| Sumber hari terlambat | `kelompokUmur(hariTerlambat)` menerima angka, **tidak** memanggil `now()` | Fungsi yang membaca jam sendiri tidak bisa diuji pada titik batas tanpa menggeser jam global, dan tidak ikut aturan `X-Simulasi-Tanggal` |
| Rumus denda di endpoint tunggakan | **memanggil `PenghitungDenda` yang sama** dengan endpoint denda, tidak disalin | Dua salinan rumus akan menyimpang; kriteria penerimaan tiket menuntut angka yang identik |
| Penyaringan status | `status NOT IN ('lunas','dibatalkan')` **di SQL** | Menyaring di PHP membuat `count`/`sum` menghitung baris yang seharusnya tidak ada |
| `sebagian` | **tetap** dihitung tunggakan | Dibayar sebagian belum lunas; kalau tersaring, tunggakan paling berbahaya justru hilang dari layar |
| Kelompok kosong | tetap dikembalikan, selalu empat kelompok dalam urutan tetap | "0 di atas 60 hari" adalah kabar baik, bukan kelompok yang hilang |
| "Belum jatuh tempo" | kelompok **tersendiri**, bukan bagian 0-30 | Orang yang belum waktunya bayar bukan penunggak; memasukkannya membuat angka penunggak tidak bisa dipercaya |
| Kunci kelompok | `belum_jatuh_tempo`, `0_30`, `31_60`, `lebih_60` — konstan, bukan label | Dipakai sebagai kunci JSON sekaligus kelas CSS; label tampilan boleh berubah tanpa memecah apa pun |
| Tanggal acuan | hanya lewat `X-Simulasi-Tanggal`, tidak ada parameter query tanggal | Dua jalur penggeseran tanggal menghasilkan dua acuan berbeda untuk tagihan yang sama |
| Kendali simulasi di UI | satu komponen `KendaliSimulasi.vue` + satu state `simulasi.js`, dipakai Tagihan dan Tunggakan | Dua salinan yang menyimpang membuat salah satu halaman tidak bisa dipakai mendemokan yang lain |

**Keputusan T4, dicatat saat implementasi.**

| Hal | Keputusan | Alasan |
|---|---|---|
| Kode status terbit massal | **201** ada yang terbit · **409** tidak ada yang terbit karena duplikat · **200** tidak ada yang terbit karena belum mulai · **422** tidak ada kontrak aktif | Pemilik bertanya "apakah aksi saya mengubah sesuatu?"; 409 harus terlihat sebagai penolakan, bukan sukses kosong |
| `dilewati_duplikat` dipisah dari `dilewati` | dua penghitung terpisah di respons | "Sudah pernah diterbitkan" dan "memang belum waktunya" punya arti berbeda; hanya yang pertama berarti tombol ditekan dua kali |
| Transaksi per kontrak | tiap kontrak punya `DB::transaction` sendiri | Satu kamar tanpa tarif tidak boleh menggagalkan penerbitan sebulan penuh untuk semua penghuni |
| Pemeriksaan `exists()` di aplikasi | tetap ada, **bukan** pengaman utama | Memberi alasan yang terbaca; pengaman sebenarnya tetap unique index, karena `exists()` punya celah balapan |
| `aktor_id` tanpa foreign key | kolom biasa, tanpa constraint | `ON DELETE SET NULL` adalah UPDATE yang ditolak trigger; `RESTRICT` membuat user yang pernah tercatat tidak bisa dihapus selamanya |
| `aktor_nama` disalin | snapshot, bukan join | Kalau di-join, mengganti nama user menulis ulang sejarah log |
| Jejak audit ditulis di dalam transaksi perubahan | `PencatatAudit` dipanggil sebelum commit | Menulis setelah commit meninggalkan jendela waktu di mana data berubah tetapi jejaknya belum ada |
| Batch yang seluruhnya dilewati | **tidak** menulis jejak audit | Log mencatat perubahan, bukan percobaan |
| Perintah `tagihan:terbitkan` | ada, memanggil layanan yang sama dengan endpoint | Penerbitan bulanan adalah pekerjaan terjadwal, bukan sesuatu yang bergantung pada ada orang membuka halaman |
| Audit tarif & parameter denda | dikerjakan di T4, bukan menunggu T9 | Endpoint-nya sudah ada; menunda berarti perubahan tarif tanpa jejak selama beberapa tiket |

**Keputusan T5, dicatat saat implementasi.**

| Hal | Keputusan | Alasan |
|---|---|---|
| Sumber status tagihan | **`SUM(pembayaran.jumlah)`**, bukan penambahan status lama | Status yang ditambah sedikit-sedikit salah begitu ada satu jalur yang lupa memperbaruinya, dan tidak ada cara mendeteksinya. Kolom `status` hanya simpanan untuk query |
| Pemeriksaan sisa | di dalam transaksi, setelah `SELECT ... FOR UPDATE` pada baris `tagihan` | Pemeriksaan sisa adalah baca-lalu-tulis. Tanpa kunci, dua pembayaran bersamaan sama-sama membaca sisa Rp 1.500.000 dan sama-sama menulis — Rp 2.000.000 untuk tagihan Rp 1.500.000 |
| Aturan yang butuh data tagihan | di `PencatatPembayaran`, **bukan** di `FormRequest` | Kalau diperiksa di `FormRequest`, pemeriksaan berjalan di luar kunci baris dan hasilnya bisa basi sebelum sempat menulis |
| Denda saat memutuskan sisa | dihitung pada **tanggal acuan sistem**, bukan `tanggal_pembayaran` | Denda adalah fungsi "sudah berapa hari terlambat sekarang". Memakai tanggal pembayaran berarti denda bisa dihapus dengan menulis tanggal mundur |
| Kelebihan bayar | **ditolak 422**, tidak disimpan sebagai saldo negatif | Saldo negatif membuat `SUM(jumlah)` berhenti bermakna sebagai "uang yang diterima", dan status yang diturunkan dari situ bisa dibuat apa saja |
| `pembayaran.jumlah` | **positif** untuk uang masuk; **negatif hanya** untuk baris pembalik yang punya `pembalik_id` | Uang disimpan sebagai rupiah bulat. Tanda negatif dikunci CHECK `pembayaran_tanda_jumlah` supaya tidak ada baris positif yang bisa menyamar sebagai pembalik atau sebaliknya |
| `total_final` di `tagihan` | dibekukan **sekali** saat status menjadi `lunas`; dilepas kembali bila pembalikannya membatalkan pelunasan | Denda berhenti berjalan saat lunas. Tanpa pembekuan, tagihan lunas menampilkan "total Rp 1.500.000" dan "dibayar Rp 1.950.000" sekaligus — dua angka yang bertentangan di satu layar |
| `sisa` di respons | `max(0, total − dibayar)` | Kelebihan bayar sudah ditolak, jadi negatif hanya mungkin karena bug; menampilkannya sebagai nol lebih jujur daripada sebagai utang terbalik |
| Tabel `pembayaran` | **append-only** lewat trigger `BEFORE UPDATE OR DELETE` | Catatan uang yang bisa diedit diam-diam tidak berguna untuk mempertanggungjawabkan saldo. Koreksi salah input memakai **baris pembalik** (REV-FIN-01): baris baru bernilai negatif yang menunjuk baris asalnya, sehingga yang salah tetap terbaca sebagai salah |
| `tanggal_pembayaran` vs `created_at` | dua kolom terpisah | Yang pertama tanggal uang diterima menurut pemilik, yang kedua kapan baris dicatat. Selisihnya sendiri informasi yang berguna |
| Penjumlahan `dibayar` di daftar | `withSum('pembayaran as total_dibayar')`, satu query untuk satu halaman | Tanpa ini, daftar 30 tagihan menjalankan 30 query penjumlahan; halaman daftar jadi lambat justru karena fitur pembayaran |
| Rumus `pokok + denda` | satu tempat: `HasilDenda::total()` | Tiga salinan rumus yang sama pasti akan menyimpang |

**Keputusan T9, dicatat saat implementasi.**

| Hal | Keputusan | Alasan |
|---|---|---|
| Isolasi penghuni | **scope di model** (`Tagihan::terlihatOleh`), bukan `if` di controller | Aturan yang disalin ke tiap endpoint akan lupa disalin pada endpoint yang ditambahkan belakangan, dan tidak ada yang menyadarinya sampai ada kebocoran |
| Tagihan orang lain | **404**, bukan 403 | 403 berarti "ada, tapi bukan milikmu" — cukup untuk memetakan tagihan siapa saja yang ada dengan mencoba id berurutan |
| `show` dan `denda` | mengambil **ulang** barisnya lewat scope, meski binding sudah menyediakannya | Route model binding mengambil baris tanpa memandang pemiliknya; membiarkannya berarti isolasi hanya ada di daftar, tidak di detail |
| Akun penghuni tanpa `penghuni_id` | daftar **kosong**, bukan 403/500 | Akun seperti itu tetap bisa login; "belum ada tagihan" lebih jujur daripada galat yang tidak bisa ia perbaiki |
| Endpoint portal | `/penghuni/tagihan` **terpisah** dari `/tagihan` | `/tagihan` adalah halaman kerja pemilik (saring per kontrak, terbitkan, catat bayar). Menumpuk dua peran di satu endpoint membuat tiap perubahan harus dipikirkan dua kali |
| Urutan rute | `/penghuni/tagihan` didaftarkan **sebelum** `apiResource('penghuni')` | Kalau tidak, "tagihan" ditangkap sebagai id penghuni dan PostgreSQL menolaknya dengan `22P02`. Sama kelasnya dengan `/tagihan/terbitkan` di T4 |
| Log audit | hanya `index`; **tidak ada** endpoint tulis | Jejak ditulis `PencatatAudit` di dalam transaksi perubahan. Endpoint tulis akan memungkinkan jejak palsu, dan itu menghancurkan seluruh gunanya |
| Append-only | ditegakkan **trigger basis data**, bukan hanya kode aplikasi | Riwayat yang bisa diubah dari `psql` bukan riwayat. Tesnya memakai `DB::statement` mentah, bukan Eloquent |
| Urutan log | `dibuat_pada DESC, id DESC` | Dua baris bisa lahir pada detik yang sama; tanpa pemutus, urutannya berubah-ubah antar permintaan dan jejak yang seharusnya tetap terlihat bergerak |
| `batas` log | maksimum 500, dan pemotongan **diumumkan** di `meta.dipotong` | Tanpa pengumuman, layar menyiratkan bahwa 200 baris adalah seluruh isi log |
| Nilai lama di layar | ditampilkan berdampingan dengan nilai baru; baris baru tidak diberi panah | "— → 1.500.000" terbaca seperti perubahan dari kosong, padahal barisnya memang baru dibuat |
| Simulasi tanggal di portal | header ikut dikirim, status dibaca dari `meta.simulasi` | Kalau portal menghitung terhadap tanggal berbeda dari layar pemilik, dua layar menampilkan denda berbeda untuk tagihan yang sama |

---

## 6. Model data

Delapan tabel. Nama tabel dan kolom Indonesia, mengikuti DFD.

```
users            id, name, email, password, peran (pemilik|penghuni), penghuni_id (nullable)

kamar            id, nomor (unique), tipe, status_huni (kosong|terisi), catatan

penghuni         id, nama, email (unique), no_hp
                 -- email ditambahkan: akun login penghuni butuh email, dan SPEC
                 -- semula hanya menyebut user_id. Relasi 1:1 diwujudkan sebagai
                 -- users.penghuni_id (lihat catatan deviasi di bawah).

kontrak          id, kamar_id, penghuni_id, tgl_masuk (date), tgl_keluar (date|null),
                 tarif_awal (numeric 14,0), status (aktif|selesai)
                 -- satu kontrak aktif per kamar (partial unique index)
                 -- tarif_awal = salinan tarif saat kontrak dibuat, untuk keterangan.
                 -- Sumber kebenaran tarif tetap tarif_sewa (R-06).

tarif_sewa       id, kamar_id, nilai (numeric 14,0), berlaku_dari (date)
                 UNIQUE (kamar_id, berlaku_dari)
                 -- append-only: tidak pernah UPDATE
                 -- kamar_id ON DELETE RESTRICT sejak R4 (REV-DAT-01). Dulu CASCADE,
                 -- dan cascade itu tidak pernah bisa berjalan: trigger append-only
                 -- menolaknya di tengah transaksi dengan pesan yang menyebut
                 -- tarif_sewa, bukan kamar. RESTRICT menolak lebih awal dan jujur.

komponen_biaya   id, nama, nilai (numeric 14,0), satuan (rupiah/bulan), berlaku_dari (date),
                 kamar_id (bigint|null -- NULL = berlaku untuk seluruh kos)
                 UNIQUE (nama, berlaku_dari, kamar_id) NULLS NOT DISTINCT
                 -- append-only. Kolom `aktif` TIDAK dibuat: menonaktifkan lewat
                 -- UPDATE akan merusak riwayat R-06. Menghentikan komponen =
                 -- baris baru bernilai 0 dengan berlaku_dari baru.
                 -- NULLS NOT DISTINCT penting: tanpa itu dua baris "listrik untuk
                 -- seluruh kos" pada tanggal yang sama sama-sama lolos, karena
                 -- NULL tidak pernah sama dengan NULL di UNIQUE biasa.
                 -- kamar_id ON DELETE RESTRICT sejak R4 (REV-DAT-01), sama seperti
                 -- tarif_sewa. kamar_fasilitas sengaja tetap CASCADE: ia bukan
                 -- riwayat, dan pemasangan fasilitas wajar hilang bersama kamarnya.

kebijakan_kos    id, tipe, nilai (varchar 30), kamar_id (bigint|null),
                 persen, nominal, masa_tenggang, tanggal, berlaku_dari (date)
                 UNIQUE (tipe, kamar_id, berlaku_dari) NULLS NOT DISTINCT
                 -- append-only. Satu baris per dimensi; kolom angka yang dipakai
                 -- bergantung pada `tipe`, sisanya NULL. Denda tidak lagi punya
                 -- tabel parameter sendiri: ia salah satu `tipe` di sini, supaya
                 -- snapshot tagihan hanya perlu menyalin satu tabel.
                 -- kamar_id ON DELETE RESTRICT sejak R4 (REV-DAT-01).

tagihan          id, kontrak_id, periode (date, tanggal 1), pokok (numeric 14,0),
                 total_final (numeric 14,0|null), jatuh_tempo (date),
                 status (belum_bayar|sebagian|lunas|dibatalkan),
                 tarif_terpakai (json), kebijakan_terpakai (jsonb),
                 tarif_denda_persen, plafon_persen, masa_tenggang,
                 terbit_pada (timestamp),
                 alasan_batal (text|null), dibatalkan_oleh (bigint|null),
                 dibatalkan_pada (timestamp|null),
                 denda_dimaafkan (numeric 14,0, default 0),
                 alasan_maaf_denda (text|null), denda_dimaafkan_oleh (bigint|null),
                 denda_dimaafkan_pada (timestamp|null)
                 UNIQUE (kontrak_id, periode) WHERE status <> 'dibatalkan'
                 -- total_final: diisi sekali saat status menjadi lunas. Null selama
                 -- tagihan belum lunas karena angkanya masih bergerak.
                 -- UNIQUE-nya PARSIAL sejak R3 (REV-FIN-02): dua tagihan hidup untuk
                 -- satu periode tetap ditolak, tetapi periode yang tagihannya sudah
                 -- dibatalkan boleh diterbitkan ulang. Index penuh membuat satu
                 -- tagihan yang salah terbit mengunci periode itu selamanya.
                 -- Pembatalan mengubah status, TIDAK menghapus baris: nomor tagihan
                 -- yang pernah disebut ke penghuni harus tetap bisa dijawab.
                 -- denda_dimaafkan adalah kolom, bukan tabel: satu kolom pada baris
                 -- yang sama tidak bisa terlupa saat menjumlahkan (R3, REV-FIN-07).
                 -- Index tagihan_kontrak_status_index (kontrak_id, status) sejak R4
                 -- (REV-DAT-04): setiap penagihan satu kontrak dan setiap
                 -- pemeriksaan duplikat terbit-massal menyaring kontrak_id lebih
                 -- dulu, dan (status, jatuh_tempo) tidak menolongnya. Urutan
                 -- kolomnya sengaja kontrak_id dulu - satu kontrak puluhan baris,
                 -- satu status ratusan. Index lama TIDAK dihapus: ia melayani
                 -- daftar tunggakan yang menyaring status lalu mengurutkan tempo.

rekening         id, jenis (transfer|qris), nama, nomor (string|null),
                 atas_nama (string|null), catatan (string|null),
                 aktif (boolean, default true), urutan (smallint)
                 -- TIDAK append-only: tidak ada angka tagihan yang dihitung dari
                 -- baris ini. Setiap tambah/ubah/hapus menulis log_audit dengan
                 -- salinan baris lengkap, termasuk nomor lama saat nomor diganti.
                 -- nomor boleh null: QRIS statis bisa hanya berupa gambar.

tagihan_rincian  id, tagihan_id, nama, nilai (numeric 14,0), urutan

pembayaran       id, tagihan_id, jumlah (numeric 14,0), tanggal_pembayaran (date),
                 catatan (varchar 255|null), metode (varchar 20|null),
                 sumber (varchar 30, default 'dicatat_pemilik'),
                 referensi (varchar 100|null),
                 dicatat_oleh (bigint|null), diterima_oleh (bigint|null),
                 pembalik_id (bigint|null), created_at
                 -- append-only lewat trigger BEFORE UPDATE OR DELETE
                 -- jumlah > 0 untuk uang masuk; jumlah < 0 HANYA untuk baris
                 -- pembalik (pembalik_id terisi). CHECK pembayaran_tanda_jumlah.
                 -- metode ∈ tunai|transfer|qris|gateway, wajib diisi klien sejak T20.
                 -- sumber ∈ dicatat_pemilik|gateway_webhook.
                 -- diterima_oleh wajib untuk tunai, ditegakkan CHECK
                 -- pembayaran_tunai_ada_pemegang (NOT VALID supaya baris lama
                 -- tidak perlu diisi ulang).
                 -- pembalik_id UNIQUE: satu baris hanya boleh dibalik sekali.
                 -- Trigger pembalik_tolak_pembalik_bertingkat: pembalik tidak
                 -- boleh punya pembalik (CHECK tidak bisa subquery).

log_audit        id, aktor_id, aktor_nama, aksi, entitas, entitas_id, dibuat_pada,
                 nilai_lama (json), nilai_baru (json)
                 -- append-only lewat trigger BEFORE UPDATE OR DELETE
                 -- aktor_id sengaja tanpa foreign key; aktor_nama disalin, bukan join
```

`tagihan.denda` dan `tagihan.sisa` **tidak ada sebagai kolom** — keduanya turunan, dihitung dari
`pokok`, `jatuh_tempo`, parameter yang dibekukan, dan `SUM(pembayaran.jumlah)`.

**Deviasi sadar dari SPEC awal, dicatat saat T5 dan diperbarui di T20.** `pembayaran` dirumuskan
di §6 sebagai `tanggal, jumlah, metode, bukti, dicatat_oleh`:

| Hal | SPEC awal | Implementasi | Alasan |
|---|---|---|---|
| Tanggal | `tanggal` | `tanggal_pembayaran` | Nama kolom yang menyebut apa tanggalnya; `tanggal` sendirian ambigu di tabel yang punya `created_at` |
| Metode | `metode` | **ada** sejak T20 (`tunai`/`transfer`/`qris`/`gateway`) | Sempat tidak dibuat karena belum ada tiket yang memakainya. T20 membuktikan sebaliknya: rekap per metode tidak mungkin dihitung tanpa kolom ini, dan menyimpannya di `catatan` berarti membaca teks bebas |
| Bukti | `bukti` | **tidak dibuat** | Butuh penyimpanan berkas, dan Render free tidak punya disk persisten. Bukti transfer dikirim lewat WhatsApp dan diperiksa mata pemilik sebelum ia menekan "verifikasi" |
| Pencatat | `dicatat_oleh` (user_id) | **ada** sejak T20, FK RESTRICT | Sempat tidak dibuat. T20 menambahkannya karena pertanyaan "siapa yang mencatat uang ini" harus bisa dijawab tanpa membaca `log_audit`, dan `diterima_oleh` (pemegang uang tunai) adalah orang yang berbeda dari pencatat |
| Sumber | — | `sumber` ∈ `dicatat_pemilik` ∥ `gateway_webhook` | Uang yang masuk lewat webhook Midtrans tidak punya pencatat manusia. Tanpa kolom ini, `dicatat_oleh` harus dibiarkan kosong tanpa menjelaskan kenapa |

---

## 7. Permukaan API

Semua di bawah `/api`. Auth lewat `Authorization: Bearer <token>`.
Daftar lengkap **86 endpoint**; rincian bentuk request/response ada di
`docs/produk/6_TOOLS_CONTRACTS.md`.

### 7.1 Autentikasi, akun, dan PDP

| Method | Path | Middleware |
|---|---|---|
| POST | `/login` | publik |
| POST | `/logout` | `auth` |
| GET | `/me` | `auth` |
| POST | `/ganti-sandi` | `auth` |
| POST | `/lupa-sandi` | publik |
| POST | `/periksa-token` | publik |
| POST | `/atur-sandi` | publik |
| POST | `/persetujuan-pdp` | `auth` |
| GET | `/pdp/data-saya` | `auth` |
| DELETE | `/pdp/data-saya` | `auth` |

### 7.2 Data kos

| Method | Path | Middleware |
|---|---|---|
| GET/POST | `/kamar` | `peran:pemilik,pengelola` / `wewenang:kelola_kamar` |
| GET/PUT | `/kamar/{kamar}` | `peran:pemilik,pengelola` / `wewenang:kelola_kamar` |
| GET | `/kamar/{kamar}/tarif-berlaku` | `peran:pemilik,pengelola` |
| PUT | `/kamar/{kamar}/fasilitas` | `wewenang:kelola_kamar` |
| GET/POST | `/tarif` | `peran:pemilik,pengelola` / `wewenang:ubah_tarif` |
| GET/POST | `/komponen-biaya` | `peran:pemilik,pengelola` / `wewenang:ubah_tarif` |
| GET/POST | `/kebijakan` | `peran:pemilik,pengelola` / `wewenang:ubah_kebijakan` |
| GET | `/fasilitas` | `peran:pemilik,pengelola` |
| POST/PUT/DELETE | `/fasilitas[/{fasilitas}]` | `wewenang:kelola_kamar` |
| GET | `/aturan` | `peran:pemilik,pengelola` |
| POST/PUT/DELETE | `/aturan[/{aturan}]` | `wewenang:kelola_kamar` |
| GET | `/rekening` | `peran:pemilik,pengelola` |
| POST/PUT/DELETE | `/rekening[/{rekening}]` | `peran:pemilik` |
| GET | `/rekening/{rekening}/gambar` | `auth` (penghuni hanya rekening aktif) |
| GET/POST | `/penghuni` | `peran:pemilik,pengelola` / `wewenang:kelola_penghuni` |
| GET/PUT | `/penghuni/{penghuni}` | `peran:pemilik,pengelola` / `wewenang:kelola_penghuni` |
| POST | `/penghuni/{penghuni}/kirim-ulang-aktivasi` | `wewenang:kelola_penghuni` |
| GET/POST | `/kontrak` | `peran:pemilik,pengelola` / `wewenang:kelola_penghuni` |
| GET | `/kontrak/{kontrak}` | `peran:pemilik,pengelola` |
| POST | `/kontrak/{kontrak}/akhiri` | `wewenang:kelola_penghuni` |

### 7.3 Tagihan dan pembayaran

| Method | Path | Middleware |
|---|---|---|
| GET | `/tagihan` | `peran:pemilik,pengelola` |
| GET | `/tagihan/{tagihan}` | `auth` (difilter scope, R-07) |
| POST | `/tagihan` | `wewenang:terbitkan_tagihan` |
| POST | `/tagihan/terbitkan` | `wewenang:terbitkan_tagihan` |
| POST | `/tagihan/{tagihan}/batal` | `wewenang:terbitkan_tagihan` |
| POST | `/tagihan/{tagihan}/maaf-denda` | `peran:pemilik` |
| GET | `/tagihan/{tagihan}/denda` | `auth` (difilter scope) |
| POST | `/tagihan/{tagihan}/pembayaran` | `wewenang:catat_pembayaran` |
| GET | `/pembayaran` | `peran:pemilik,pengelola` |
| GET | `/pembayaran/penerima` | `wewenang:catat_pembayaran` |
| GET | `/pembayaran/tunai-saya` | `wewenang:catat_pembayaran` |
| POST | `/pembayaran/{pembayaran}/pembalik` | `wewenang:catat_pembayaran` |
| POST | `/pembayaran/{pembayaran}/verifikasi` | `wewenang:verifikasi_pembayaran` |
| GET | `/tunggakan` | `peran:pemilik,pengelola` |
| GET | `/rekap` | `peran:pemilik` |
| GET | `/ekspor/pembayaran` | `peran:pemilik,pengelola` |
| GET | `/ekspor/tunggakan` | `peran:pemilik,pengelola` |
| GET | `/ekspor/rekap` | `peran:pemilik` |
| GET | `/audit-log` | `wewenang:baca_audit` |

### 7.4 Usulan, pengelola, notifikasi, portal

| Method | Path | Middleware |
|---|---|---|
| GET | `/usulan` | `peran:pemilik` |
| POST | `/usulan/{usulan}/setujui` | `peran:pemilik` |
| POST | `/usulan/{usulan}/tolak` | `peran:pemilik` |
| GET/POST | `/pengelola` | `peran:pemilik` |
| GET/PUT/DELETE | `/pengelola/{pengelola}` | `peran:pemilik` |
| POST | `/pengelola/{pengelola}/kirim-ulang-aktivasi` | `peran:pemilik` |
| GET | `/notifikasi` | `auth` |
| POST | `/notifikasi/{notifikasi}/baca` | `auth` |
| POST | `/notifikasi/baca-semua` | `auth` |
| GET/PUT | `/pengaturan-notifikasi` | `auth` |
| GET | `/penghuni/tagihan` | `peran:penghuni` |
| GET | `/penghuni/aturan` | `peran:penghuni` |
| GET | `/penghuni/rekening` | `peran:penghuni` |
| GET | `/penghuni/kontrak` | `peran:penghuni` |
| POST | `/tagihan/{tagihan}/bayar-daring` | `peran:penghuni` |
| GET | `/penyiapan` | `peran:pemilik,pengelola` |
| POST | `/webhook/midtrans` | publik (tanda tangan SHA512) |
| GET | `/health` | publik |
| GET | `/pemilik/saja` | `peran:pemilik` |

**Dua jalur baca yang terbuka untuk ketiga peran.** `/tagihan/{id}` dan `/tagihan/{id}/denda`
disaring scope `Tagihan::terlihatOleh`: pemilik dan pengelola melihat semuanya, penghuni hanya
miliknya, dan id milik orang lain dijawab **404 yang tidak bisa dibedakan** dari id yang tidak ada.

**Urutan pendaftaran rute ikut menentukan perilaku.** Laravel mencocokkan rute sesuai urutan
deklarasi, jadi `/penghuni/tagihan`, `/penghuni/aturan`, `/penghuni/rekening`, dan
`/penghuni/kontrak` harus didaftarkan
**sebelum** `apiResource('penghuni')` — kalau tidak, "tagihan" ditangkap sebagai `{penghuni}` dan
PostgreSQL menolaknya dengan `22P02`. Kelas kesalahan yang sama sudah dua kali terjadi
(`/tagihan/terbitkan` di T4).

**Konvensi status code.**

| Kode | Kapan |
|---|---|
| 200 | Berhasil (baca, ubah, aksi yang tidak membuat baris) |
| 201 | Berhasil membuat baris baru |
| 202 | Usulan perubahan diterima tetapi belum berlaku (T26) |
| 401 | Belum login / token tidak valid |
| 403 | Peran tidak berhak (penghuni memanggil endpoint pemilik) |
| 404 | Resource tidak ada **atau** bukan miliknya (R-07) |
| 409 | Duplikat periode (R-02), atau bentrok yang tidak bisa diselesaikan pengirim |
| 422 | Validasi gagal, dengan `errors` per field; juga pembayaran yang melebihi sisa, berjumlah ≤ 0, atau pada tagihan yang dibatalkan |

Bentuk respons sukses: `{ "data": ... }`. Error: `{ "message": "...", "errors": {...} }`.

---

## 8. Cerita pengguna dan kriteria penerimaan

Ditulis sebagai "sebagai X, saya ingin Y, supaya Z", dengan kriteria yang bisa diuji.

### US-1 — Masuk dan memisahkan peran (T1)

Sebagai pemilik, saya ingin login dan diarahkan ke dashboard, supaya orang lain tidak bisa
mengakses data kos saya.

- [ ] Login dengan email+password benar → 200 + token
- [ ] Password salah → 401
- [ ] `GET /me` mengembalikan peran
- [ ] Token tidak valid → 401
- [ ] Penghuni memanggil endpoint pemilik → 403

### US-2 — Mendaftarkan kamar, penghuni, dan kontrak (T2)

Sebagai pemilik, saya ingin mencatat kamar dan penghuni beserta tarifnya, supaya sistem tahu
siapa menyewa apa dengan harga berapa.

- [ ] Nomor kamar duplikat → 422
- [ ] Kontrak baru menyimpan `tgl_masuk` dan tarif
- [ ] Dua kontrak aktif pada satu kamar → 422
- [ ] Mengubah tarif membuat baris **baru** di `tarif_sewa` dengan `berlaku_dari`, baris lama utuh
- [ ] Tarif efektif untuk periode P = baris terakhir dengan `berlaku_dari ≤ P`

### US-3 — Menerbitkan tagihan bulan pertama dengan prorata (T3)

Sebagai pemilik, saya ingin menekan satu tombol untuk menerbitkan tagihan, supaya saya tidak
menghitung prorata manual dan salah.

- [ ] Penghuni masuk tanggal 1 → pokok **persis** tarif penuh
- [ ] Masuk 15 Oktober, tarif 1.500.000 → sewa **Rp 822.581** (17/31)
- [ ] Masuk 31 Oktober → sewa **Rp 48.387**
- [ ] Biaya tambahan tetap muncul sebagai baris rincian terpisah
- [ ] `pokok` = jumlah rincian; `total` = `pokok` + denda (denda 0 saat terbit)
- [ ] `jatuh_tempo` = tanggal masuk
- [ ] `tarif_terpakai` menyimpan snapshot JSON
- [ ] Mengubah tarif setelah terbit → tagihan lama **tidak berubah** (R-06)

### US-4 — Menerbitkan massal dan menolak duplikat (T4)

Sebagai pemilik, saya ingin menerbitkan tagihan semua kontrak aktif sekaligus, supaya tidak
menekan satu per satu.

- [ ] `POST /tagihan/terbitkan` dengan `periode` membuat tagihan untuk semua kontrak aktif
- [ ] Menekan lagi untuk periode yang sama → **409** (R-02), bukan dua baris
- [ ] Dua request bersamaan → tetap satu yang menang, satunya 409
- [ ] Respons menyebut berapa terbit dan berapa dilewati

**Selesai di T4.** `PenerbitanMassal` + `POST /tagihan/terbitkan` + `tagihan:terbitkan`;
`PenerbitanMassalTest` (17 tes) dan `PenerbitanBalapanTest` (3 tes invarian); balapan sungguhan
diuji lewat dua permintaan HTTP paralel di `docs/uji/uji-balapan-t4.mjs` (10/10). Respons juga
memisahkan `dilewati_duplikat` dari `dilewati`, dan tiap tagihan yang terbit menulis `log_audit`.

### US-5 — Mencatat pembayaran (T5)

Sebagai pemilik, saya ingin mencatat uang yang saya terima, supaya status tagihan akurat.

- [ ] Pembayaran sebagian → status `sebagian`, sisa benar
- [ ] Pembayaran pas → status `lunas`
- [ ] Pembayaran melebihi sisa → **422**, bukan disimpan sebagai saldo
- [ ] Jumlah 0 atau negatif → 422
- [ ] Setiap pembayaran tercatat di `log_audit`

**Selesai di T5.** `PencatatPembayaran` + `POST /tagihan/{id}/pembayaran` + tabel `pembayaran`
append-only; `PembayaranTest` (26 tes, 113 asersi). Dua pembayaran serentak diuji lewat permintaan
HTTP paralel di `docs/uji/uji-balapan-t5.mjs` (12/12): yang kalah menerima 422 dengan pesan
*"sisa tagihan Rp 500.000"* — angka itu hanya bisa muncul kalau ia menunggu transaksi pertama
commit lalu membaca ulang, yang membuktikan `FOR UPDATE` benar-benar bekerja. Kolom `total_final`
ditambahkan supaya angka `total`, `dibayar`, dan `sisa` tidak saling bertentangan pada tagihan
yang sudah lunas.

**Batasan yang dicatat jujur.** `pembayaran` bersifat append-only. Koreksi salah input
memakai **baris pembalik** (REV-FIN-01): baris baru bernilai negatif yang menunjuk baris
asalnya lewat `pembalik_id`, bukan penyuntingan baris lama. Yang salah tetap terbaca sebagai
salah, dan karena tandanya ikut ke `SUM(jumlah)`, saldo tagihan, rekap, dan uang tunai di
tangan semuanya tetap benar tanpa satu pun agregat diubah. Sampai R2 fitur ini belum ada;
sejak R2 endpoint-nya (`POST /pembayaran/{id}/pembalik`) tersedia, tetapi belum punya tombol
di layar.

### US-6 — Melihat denda yang benar (T6)

Sebagai pemilik, saya ingin sistem menghitung denda, supaya saya tidak perlu memutuskan angka
setiap kali dan tidak bisa dituduh sewenang-wenang.

- [ ] Hari terlambat 7 → denda **0**
- [ ] Hari terlambat 8 → denda **1%** pokok
- [ ] Hari terlambat 37 → denda **30%** pokok (plafon)
- [ ] Hari terlambat 100 → tetap **30%**
- [ ] Pembayaran sebagian tidak menurunkan plafon
- [ ] Mengubah parameter denda setelah terbit → tagihan lama tidak berubah
- [ ] `X-Simulasi-Tanggal` menggeser hari ini (hanya saat `APP_DEMO_MODE=true`)

### US-7 — Melihat tunggakan berkelompok (T7)

Sebagai pemilik, saya ingin melihat tunggakan dikelompokkan umurnya, supaya saya tahu mana yang
baru dan mana yang sudah parah.

- [ ] Empat kelompok: belum jatuh tempo, 0–30, 31–60, > 60
- [ ] Batas 30/31 dan 60/61 benar
- [ ] Tagihan lunas dan dibatalkan tidak muncul
- [ ] Tiap kelompok menampilkan jumlah tagihan dan total rupiah

### US-8 — Portal penghuni dengan isolasi dan audit (T9)

Sebagai penghuni, saya ingin melihat tagihan saya sendiri, supaya saya tahu harus bayar berapa.

- [ ] Penghuni melihat hanya tagihannya sendiri
- [ ] Mengakses tagihan milik orang lain → **404**
- [ ] Penghuni tidak bisa mengubah apa pun → 403
- [ ] Perubahan status tagihan tercatat di `log_audit`
- [ ] Operasi UPDATE/DELETE langsung ke `log_audit` **ditolak** basis data

**Selesai di T9.** `Tagihan::scopeTerlihatOleh` + `PortalPenghuniController` + `AuditLogController`;
`IsolasiDanAuditTest` (18 tes, 65 asersi) dan `docs/uji/e2e-t9.mjs` (26/26). Isolasi ditulis sebagai
scope di model, bukan `if` di tiap controller: aturan yang disalin ke setiap endpoint akan lupa
disalin pada endpoint yang ditambahkan belakangan, dan tidak ada yang menyadarinya sampai ada yang
membuka URL milik orang lain. `show` dan `denda` mengambil ulang barisnya lewat scope itu meskipun
route model binding sudah menyediakannya — binding mengambil baris tanpa memandang pemiliknya.

**404, bukan 403.** Penghuni yang membuka tagihan orang lain mendapat jawaban yang **persis sama**
dengan id yang tidak ada. 403 akan mengatakan "ada, tapi bukan milikmu" — dan itu sudah cukup untuk
memetakan tagihan siapa saja yang ada di sistem hanya dengan mencoba id berurutan. Tesnya
membandingkan kedua jawaban itu, bukan hanya memeriksa satu.

**Akun yatim.** Penghuni yang tidak terhubung ke data penghuni mendapat daftar kosong, bukan 403
dan bukan 500. Akun seperti itu tetap bisa login; menampilkan "belum ada tagihan" lebih jujur
daripada galat yang tidak bisa ia perbaiki sendiri.

**Log audit hanya punya `index`.** Tidak ada `store`, `update`, maupun `destroy`, dan itu bukan
kelalaian: jejak ditulis `PencatatAudit` di dalam transaksi perubahan, sedangkan endpoint tulis akan
memungkinkan seseorang menambahkan jejak palsu. Penegakan append-only-nya ada di **basis data**
(trigger `BEFORE UPDATE OR DELETE`), bukan hanya di kode — tes memakai `DB::statement` mentah untuk
membuktikannya, karena riwayat yang bisa diubah dari `psql` bukan riwayat.

**Dua kesalahan yang ditemukan saat mengerjakan T9, keduanya di tes.** (a) `PUT /api/kamar/1`
semula diasersikan 403 dengan id tetap; `SubstituteBindings` berjalan **sebelum** `PastikanPeran`,
jadi id yang kebetulan tidak ada menghasilkan 404 lebih dulu — tes lulus sendiri-sendiri dan gagal
saat dijalankan bersama. (b) Satu asersi memakai `/15\\.000/`, yang di JavaScript biasa berarti
"backslash literal", bukan titik — pola yang sama sudah tercatat di T5 dan T6.

---

## 9. Dua seam pengujian

Hanya dua. Bukan satu per use case. Semua aturan angka diuji di seam pertama, jadi tidak perlu
basis data dan tidak perlu HTTP.

**Seam 1 — kalkulator murni** (`App\Domain\Billing\Kalkulator`):

```
hitungSewaBulanPertama(int $tarif, int $hariTinggal, int $hariDalamBulan): int
hitungDenda(int $pokok, int $hariTerlambat, array $parameter): int
kelompokUmur(int $hariTerlambat): string
tanggalJatuhTempo(CarbonInterface $tglMasuk, CarbonInterface $periode): CarbonInterface
```

Titik uji wajib: `hari_tinggal` 1 dan penuh; batas 7/8; plafon hari ke-37; batas 30/31 dan 60/61;
jepit tanggal 31 → 28/29/30.

**Seam 2 — konvensi HTTP**: R-02 (409) dan R-07 (404 + 403 + log append-only). Auth, policy,
status code. Ini yang tidak bisa dibuktikan di fungsi murni.

Selain dua seam itu, tes lain opsional. Yang tidak boleh: menguji prorata lewat HTTP.

---

## 10. Rencana ketertelusuran

Dirawat **berjalan**, diperbarui setiap tiket selesai, dan diaudit formal sekali di akhir.
Lokasi: `docs/uji/audit-ketertelusuran.md`.

| Aturan | Implementasi | Tes | Status |
|---|---|---|---|
| R-01 prorata | `Kalkulator::hitungSewaBulanPertama` | `test_prorata_*` | ✅ |
| R-02 satu tagihan per periode | unique index + `PenerbitanMassal` | `test_menekan_dua_kali_menghasilkan_409_bukan_tagihan_kedua`, `uji-balapan-t4.mjs` | ✅ |
| R-03 denda 1%/hari plafon 30% | `Kalkulator::hitungDenda` + `PenghitungDenda` | `test_batas_7_8`, `test_plafon_37`, `test_pembayaran_sebagian_tidak_menurunkan_dasar_denda` | ✅ |
| R-04 umur tunggakan | `Kalkulator::kelompokUmur` | `test_batas_30_31`, `test_batas_60_61` | ✅ |
| R-05 kebijakan wajib lengkap | `KebijakanBelumLengkap` menolak `POST /tagihan` dengan 422 | `test_terbit_sebelum_kebijakan_lengkap_ditolak`, `test_terbit_sesudah_kebijakan_lengkap_berhasil` | ✅ |
| Batas sistem: deposit | tidak ada di sistem (dihapus di fase terakhir) — **bukan** aturan R-* | `MigrasiHapusDepositTest` (5 tes), `KebijakanKosTest::test_tipe_deposit_ditolak_validasi_maupun_constraint` | ✅ |
| R-06 tarif tidak surut | `tarif_sewa` append-only + snapshot `tarif_terpakai` | `test_tagihan_lama_tidak_berubah` | ✅ |
| R-07 isolasi + audit | scope `Tagihan::terlihatOleh` + `log_audit` append-only (trigger basis data) | `IsolasiDanAuditTest` (18 tes): `test_penghuni_hanya_melihat_tagihannya_sendiri`, `test_penghuni_mengakses_tagihan_orang_lain_mendapat_404_bukan_403`, `test_penghuni_mengakses_denda_tagihan_orang_lain_mendapat_404`, `test_penghuni_memanggil_endpoint_pemilik_ditolak_403`, `test_update_langsung_ke_log_audit_ditolak_basis_data`, `test_delete_langsung_ke_log_audit_ditolak_basis_data`; `docs/uji/e2e-t9.mjs` (26/26) | ✅ |

Status: ⏳ belum, ✅ selesai, ⚠ sebagian, ⏸ ditunda.

---

## 11. Di luar cakupan

Ditunda dengan sadar, bukan terlupa.

| Hal | Alasan | Rencana |
|---|---|---|
| Deposit & kerusakan | **Dihapus seluruhnya di fase terakhir**, bukan ditunda: deposit tidak pernah tercatat sebagai uang masuk, tidak pernah mengurangi tagihan, dan tidak pernah dikembalikan. Batas sistem, bukan aturan R-* — lihat R-05 | Tidak ada rencana mengembalikannya |
| Laporan & rekap lengkap (proses 5.0) | Bisa dihitung manual dari data yang ada | T10, tabel manual di laporan |
| Pembatalan tagihan | **Sudah dikerjakan di R3** (REV-FIN-02): status berubah, baris tidak dihapus, periode bisa diterbitkan ulang | `POST /tagihan/{id}/batal`, tombol di panel rincian |
| Pemaafan denda | **Sudah dikerjakan di R3** (REV-FIN-07): denda dibekukan pada hari pokok lunas, bukan dinolkan | `POST /tagihan/{id}/maaf-denda`, `peran:pemilik` |
| Rekening tujuan pembayaran | **Sudah dikerjakan di R3** (REV-UI-05): transfer dan QRIS statis, tulisan hanya pemilik | Tabel `rekening`, halaman Rekening, kartu "Cara membayar" di portal |
| Koreksi pembayaran | **Sudah dikerjakan di R2** (REV-FIN-01): baris pembalik bertanda lewat `POST /pembayaran/{id}/pembalik` | Endpoint teruji; tombol layar menyusul |
| Notifikasi WhatsApp | Di luar kendali anggaran; R3 hanya menyediakan tautan `wa.me` yang dibuka pemilik sendiri | — |
| Multi-kos / multi-pemilik | Satu kos, satu pemilik | — |
| Impor data historis | Kos baru, tidak ada data lama | — |
| Sub-metering listrik/air | Sewa all-in | — |

---

## 12. Data demo (seed)

Seeder **sengaja tidak** mengisi kamar, kontrak, tagihan, maupun kebijakan kos. Yang disemai
hanya:

| Disemai | Isi | Kenapa |
|---|---|---|
| Akun | `pemilik@kos.test`, `pengelola@kos.test`, `penghuni@kos.test` — semuanya `password`, sudah terverifikasi, `wajib_ganti_sandi = false` | Demo harus bisa dimulai tanpa membaca dokumentasi |
| Penghuni | Satu baris `Budi Santoso` dengan `no_hp` terisi | Kontrak butuh penghuni, dan tombol WhatsApp butuh nomor |
| Wewenang pengelola | Empat wewenang harian: `catat_pembayaran`, `kelola_kamar`, `kelola_penghuni`, `terbitkan_tagihan` | Justru **tanpa** `ubah_tarif`/`ubah_kebijakan`, supaya pembatasannya bisa didemokan |
| Fasilitas | Enam baris katalog (AC, kamar mandi dalam, lemari, meja belajar, Wi-Fi, parkir motor) | Katalog, bukan pemasangan; pemasangan per kamar tetap manual |
| Aturan kos | Beberapa aturan aktif | Portal penghuni harus punya isi saat pertama dibuka |

**Kenapa kebijakan kos tidak disemai.** Kebijakan kosong adalah **gerbang** yang harus terlihat
di demo: tanpa satuan sewa, jatuh tempo, dan denda, penerbitan tagihan ditolak dengan pesan
"Wajib diisi dulu". Membuatkan kebijakan bawaan akan menyembunyikan gerbang itu — dan berarti
menebak kebijakan kos orang lain.

**Kenapa kamar, kontrak, dan tagihan tidak disemai.** Setiap kos berbeda; nomor kamar dan
tarifnya adalah data pemilik, bukan data contoh. Untuk mengisi data contoh tanpa mengetik:

```bash
node docs/uji/e2e-t2.mjs   # kamar, penghuni, kontrak lewat layar sungguhan
node docs/uji/e2e-r3.mjs   # tagihan, pembayaran, pembatalan, pemaafan denda
```

**Tanggal relatif.** `APP_DEMO_MODE=true` membuat tanggal sistem bisa digeser lewat header
`X-Simulasi-Tanggal`, sehingga denda bisa diperagakan tanpa menunggu berbulan-bulan. Di mode
normal header itu diabaikan sepenuhnya.

---

## 13. Definisi selesai

Sebuah tiket selesai kalau **semua** ini benar:

- Semua kriteria penerimaan tiket itu lulus lewat tes otomatis
- Baris ketertelusuran untuk aturan terkait diperbarui
- `docker compose up` dari keadaan bersih menjalankan aplikasi tanpa langkah manual
- Tidak ada `float` di jalur perhitungan uang
- Tidak ada query tagihan yang tidak difilter peran
- Commit menyebut nomor issue

Rilis dianggap selesai kalau **7 dari 7** aturan di §10 berstatus ✅ atau ⏸ dengan alasan
tertulis, **dan** audit ketertelusuran formal sudah dijalankan sekali.
