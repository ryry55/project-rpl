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

### R-05 — Deposit hanya untuk kerusakan (ditunda)

Tidak diimplementasikan di rilis ini. Kolom `kontrak.deposit` tetap dibuat dan diisi saat
pendaftaran, supaya tidak perlu migrasi besar nanti. Fitur pencatatan kerusakan masuk T8 (di luar
cakupan).

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
| Parameter denda | `GET/PUT /parameter-denda` | `GET`, `GET /berlaku`, `POST` | PUT yang menambah baris menyesatkan; `POST` menyatakan "berlakukan parameter baru" dengan jujur |

**Append-only ditegakkan basis data, bukan hanya aplikasi.** `tarif_sewa`, `komponen_biaya`, dan
`parameter_denda` punya trigger yang menolak `UPDATE` dan `DELETE`. Endpoint yang tidak ada hanya
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
| Parameter denda | dibaca dari **kolom snapshot di baris `tagihan`**, bukan `parameter_denda` terkini | Itu inti R-06 lapis 2. Membaca parameter terkini membuat denda tagihan lama berubah saat parameter diubah |
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
                 tarif_awal (numeric 14,0), deposit (numeric), status (aktif|selesai)
                 -- satu kontrak aktif per kamar (partial unique index)
                 -- tarif_awal = salinan tarif saat kontrak dibuat, untuk keterangan.
                 -- Sumber kebenaran tarif tetap tarif_sewa (R-06).

tarif_sewa       id, kamar_id, nilai (numeric 14,0), berlaku_dari (date)
                 UNIQUE (kamar_id, berlaku_dari)
                 -- append-only: tidak pernah UPDATE

komponen_biaya   id, nama, nilai (numeric 14,0), satuan (rupiah/bulan), berlaku_dari (date)
                 UNIQUE (nama, berlaku_dari)
                 -- append-only. Kolom `aktif` TIDAK dibuat: menonaktifkan lewat
                 -- UPDATE akan merusak riwayat R-06. Menghentikan komponen =
                 -- baris baru bernilai 0 dengan berlaku_dari baru.

parameter_denda  id, tarif_persen (numeric 5,2), plafon_persen (numeric 5,2),
                 masa_tenggang (int), berlaku_dari (date)
                 UNIQUE (berlaku_dari)
                 -- append-only: mengubah parameter = baris baru

tagihan          id, kontrak_id, periode (date, tanggal 1), pokok (numeric 14,0),
                 jatuh_tempo (date), status (belum_bayar|sebagian|lunas|dibatalkan),
                 tarif_terpakai (json), tarif_denda_persen, plafon_persen, masa_tenggang,
                 terbit_pada (timestamp)
                 UNIQUE (kontrak_id, periode)

tagihan_rincian  id, tagihan_id, nama, nilai (numeric 14,0), urutan

pembayaran       id, tagihan_id, tanggal (date), jumlah (numeric 14,0), metode, bukti (path|null),
                 dicatat_oleh (user_id)

log_audit        id, aktor_id, aksi, entitas, entitas_id, waktu, nilai_lama (json), nilai_baru (json)
                 -- append-only
```

`tagihan.denda` dan `tagihan.total` **tidak ada sebagai kolom** — keduanya turunan, dihitung dari
`pokok`, `jatuh_tempo`, dan parameter yang dibekukan.

---

## 7. Permukaan API

Semua di bawah `/api`. Auth lewat `Authorization: Bearer <token>`.

| Method | Path | Peran | Tiket |
|---|---|---|---|
| POST | `/login` | publik | T1 |
| POST | `/logout` | semua | T1 |
| GET | `/me` | semua | T1 |
| GET/POST | `/kamar` | pemilik | T2 |
| GET | `/kamar/{id}` | pemilik | T2 |
| PUT | `/kamar/{id}` | pemilik | T2 |
| GET/POST | `/penghuni` | pemilik | T2 |
| GET | `/penghuni/{id}` | pemilik | T2 |
| PUT | `/penghuni/{id}` | pemilik | T2 |
| GET/POST | `/kontrak` | pemilik | T2 |
| GET | `/kontrak/{id}` | pemilik | T2 |
| POST | `/kontrak/{id}/akhiri` | pemilik | T2 |
| GET/POST | `/tarif` | pemilik | T2 |
| GET | `/kamar/{id}/tarif-berlaku?periode=` | pemilik | T2 |
| GET/POST | `/komponen-biaya` | pemilik | T2 |
| GET | `/parameter-denda` | pemilik | T2 |
| GET | `/parameter-denda/berlaku` | pemilik | T2 |
| POST | `/parameter-denda` | pemilik | T2 |
| GET/POST | `/tagihan` | pemilik | T3 |
| GET | `/tagihan/{id}` | pemilik (semua), penghuni (miliknya) | T3 |
| GET | `/tagihan/{id}/denda` | pemilik | T6 |
| GET | `/tunggakan` | pemilik | T7 |
| POST | `/tagihan/terbitkan` | pemilik | T4 |
| POST | `/tagihan/{id}/pembayaran` | pemilik | T5 |
| GET | `/tagihan/{id}/denda` | pemilik, penghuni (miliknya) | T6 |
| GET | `/tunggakan` | pemilik | T7 |
| GET | `/audit-log` | pemilik | T9 |
| GET | `/penghuni/tagihan` | penghuni | T9 |

**Konvensi status code.**

| Kode | Kapan |
|---|---|
| 401 | Belum login / token tidak valid |
| 403 | Peran tidak berhak (penghuni memanggil endpoint pemilik) |
| 404 | Resource tidak ada **atau** bukan miliknya (R-07) |
| 409 | Duplikat periode (R-02) |
| 422 | Validasi gagal, dengan `errors` per field |

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
- [ ] Kontrak baru menyimpan `tgl_masuk`, tarif, dan deposit
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

### US-5 — Mencatat pembayaran (T5)

Sebagai pemilik, saya ingin mencatat uang yang saya terima, supaya status tagihan akurat.

- [ ] Pembayaran sebagian → status `sebagian`, sisa benar
- [ ] Pembayaran pas → status `lunas`
- [ ] Pembayaran melebihi sisa → **422**, bukan disimpan sebagai saldo
- [ ] Jumlah 0 atau negatif → 422
- [ ] Setiap pembayaran tercatat di `log_audit`

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
| R-01 prorata | `Kalkulator::hitungSewaBulanPertama` | `test_prorata_*` | ⏳ |
| R-02 satu tagihan per periode | unique index + `TerbitkanTagihan` | `test_terbit_dua_kali_ditolak` | ⏳ |
| R-03 denda 1%/hari plafon 30% | `Kalkulator::hitungDenda` | `test_batas_7_8`, `test_plafon_37` | ⏳ |
| R-04 umur tunggakan | `Kalkulator::kelompokUmur` | `test_batas_30_31`, `test_batas_60_61` | ⏳ |
| R-05 deposit | — | — | ⏸ ditunda (T8) |
| R-06 tarif tidak surut | `tarif_sewa` append-only + snapshot | `test_tagihan_lama_tidak_berubah` | ⏳ |
| R-07 isolasi + audit | filter query + `log_audit` append-only | `test_akses_silang_404` | ⏳ |

Status: ⏳ belum, ✅ selesai, ⚠ sebagian, ⏸ ditunda.

---

## 11. Di luar cakupan

Ditunda dengan sadar, bukan terlupa.

| Hal | Alasan | Rencana |
|---|---|---|
| Deposit & kerusakan (R-05) | Butuh alur foto kerusakan dan negosiasi; kolom sudah disiapkan | T8, tabel manual di laporan |
| Laporan & rekap lengkap (proses 5.0) | Bisa dihitung manual dari data yang ada | T10, tabel manual di laporan |
| Pembatalan tagihan | Tidak dibutuhkan untuk demo | enum ada, UI tidak |
| Notifikasi WhatsApp/email | Di luar kendali anggaran | — |
| Multi-kos / multi-pemilik | Satu kos, satu pemilik | — |
| Impor data historis | Kos baru, tidak ada data lama | — |
| Sub-metering listrik/air | Sewa all-in | — |

---

## 12. Data demo (seed)

Supaya demo tidak perlu mengetik: 1 kos, **8 kamar** (6 terisi), **6 penghuni** dengan tanggal
masuk berbeda-beda — termasuk **satu yang masuk tanggal 31** untuk memamerkan jepit tanggal, dan
**satu yang masuk tanggal 1** untuk memamerkan tarif penuh.

Skenario tagihan yang harus ada setelah seed:

| Penghuni | Kondisi | Untuk memamerkan |
|---|---|---|
| A | Masuk tanggal 1, lunas | Tarif penuh tanpa selisih |
| B | Masuk tanggal 15, belum bayar, terlambat 8 hari | Denda mulai |
| C | Terlambat 30 hari | Kelompok 0–30 |
| D | Terlambat 45 hari | Kelompok 31–60 |
| E | Terlambat 90 hari | Kelompok > 60, denda sudah di plafon |
| F | Masuk tanggal 31 | Jepit jatuh tempo |

Seed memakai `APP_DEMO_MODE=true` supaya tanggalnya relatif terhadap hari demo, bukan tanggal
mati yang cepat basi.

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
