# Panduan Tim — Sistem Informasi Operasional Kos-Kosan

> **Untuk siapa dokumen ini.** Untuk lima orang yang mengerjakan proyek ini:
> pemilik produk, analis sistem, dua pengembang, dan penguji. Ditulis dengan
> anggapan Anda **belum pernah membuka repo ini**. Tidak ada yang perlu Anda
> hafal; yang perlu Anda tahu adalah ke mana harus melihat.
>
> **Bukan pengganti dokumen lain.** `KONTEKS_PROYEK.md` menjelaskan produknya,
> `SPEC.md` menjelaskan aturannya, `4_AGENTS.md` menjelaskan cara mengubah kode.
> Dokumen ini menjelaskan **bagaimana ketiganya menjadi satu gambaran** — dan
> mengapa beberapa keputusan di dalamnya diambil dengan sengaja, karena itulah
> bagian yang paling sering ditanyakan dan paling sulit dijawab ulang.

Versi 1.0 · 26 Oktober 2026

---

## 1. Satu menit: apa yang sedang kita bangun

Aplikasi web untuk **satu** pemilik kos kecil (5–30 kamar) untuk mengurus kamar,
penghuni, kontrak, tagihan bulanan, dan pembayaran. Bukan aplikasi untuk banyak
kos, bukan marketplace, bukan aplikasi pencari kos.

Yang membedakannya dari aplikasi sejenis bukan jumlah fitur, melainkan satu
kalimat yang menjadi dasar seluruh produk:

> **"Pain point-nya tetap beda-beda per kos. Ini masalah utamanya."**

Artinya: kos A menagih bulanan di muka, kos B menagih per tanggal masuk, kos C
memakai denda harian, kos D tidak memakai denda sama sekali. Aplikasi yang
memaksa satu pola akan ditolak oleh empat dari lima pemilik kos. Karena itu
**satuan sewa, cara jatuh tempo, dan bentuk denda adalah pilihan**, bukan
tetapan yang diprogram.

Batasnya penting dan disengaja: pemilik memilih dari **menu**, dan mengisi
**angka**. Ia tidak menulis rumus, tidak menempel JSON, tidak mengubah kode.
Kebebasan yang diberikan adalah kebebasan memilih, bukan kebebasan memprogram.
Alasannya ada di §7.

---

## 2. Tiga aktor, dan mengapa hanya tiga

| Aktor | Kode peran | Yang boleh |
|---|---|---|
| **Pemilik Kos** | `pemilik` | Semuanya. Satu-satunya yang menetapkan tarif, kebijakan, rekening, dan memaafkan denda |
| **Pengelola** | `pengelola` | Hanya yang diberikan pemilik, dari 8 wewenang. Perubahan tarif/kebijakan hanya **diusulkan** |
| **Penghuni** | `penghuni` | Membaca tagihannya sendiri, membayar, mengurus akunnya sendiri |

Tiga, bukan lima. Dan `pengelola` **tidak** dipecah menjadi "admin" dan "kasir"
dan "bendahara". Alasannya bukan kesederhanaan teknis, melainkan kejujuran
model: di kos sungguhan, orang yang sama mencatat pembayaran hari ini dan
mengurus kamar besok. Tiga peran yang tumpang tindih akan memaksa pemilik
memilihkan peran untuk orang yang pekerjaannya berubah setiap minggu. Satu
peran dengan **daftar wewenang** mengatakan hal yang sebenarnya.

Delapan wewenang itu:

`catat_pembayaran` · `kelola_kamar` · `kelola_penghuni` · `terbitkan_tagihan` ·
`ubah_tarif` · `ubah_kebijakan` · `verifikasi_pembayaran` · `baca_audit`

Dua di antaranya **sensitif** — `ubah_tarif` dan `ubah_kebijakan`. Pemegangnya
tidak langsung mengubah apa pun; ia mengirim **usulan**, dan pemilik yang
memutuskan. Usulan kedaluwarsa dalam **7 hari**.

**Kenapa begitu.** Pengelola yang bisa menaikkan tarifnya sendiri lalu menagih
penghuni, dan bisa menandai tagihannya lunas, adalah orang yang bisa menaikkan
harga dan mengantongi selisihnya tanpa satu pun aturan yang dilanggar. Pagar
yang memisahkan keduanya bukan ketidakpercayaan kepada orang, melainkan
penghapusan satu jalur penyalahgunaan yang tidak bisa dideteksi setelah kejadian.

**Yang tidak ada, dan ini keputusan:** tidak ada peran **admin**. Model datanya
satu kos dengan satu pemilik (lihat §7), jadi admin tidak punya pekerjaan yang
tidak sudah dikerjakan pemilik — dan menambahkannya akan membuat model "banyak
kos, banyak pemilik" masuk lewat pintu belakang, padahal model itu sudah
ditolak dengan alasan tertulis.

---

## 3. Perjalanan satu rupiah

Ini bagian yang perlu dipahami semua orang, termasuk yang tidak menulis kode.
Uang masuk lewat lima langkah, dan setiap langkah meninggalkan jejak yang tidak
bisa dihapus.

```
Pemilik menyiapkan          Penghuni menempati        Tagihan terbit
kebijakan + tarif     →     kamar (kontrak)     →     (prorata bulan pertama)
                                                                  ↓
        Laporan ←      Pembayaran dicatat     ←     Penghuni membayar
                          (tunai / transfer /
                           QRIS / Midtrans)
```

**1. Penyiapan dulu, tagihan kemudian.** Tagihan tidak bisa terbit sebelum
kebijakan lengkap — satuan sewa, cara jatuh tempo, aturan denda. Sistem tidak
menebak. Kalau kebijakan belum ada, penerbitan **ditolak**, bukan memakai nilai
bawaan. Ini aturan **R-05**, dan konsekuensinya disengaja: pemilik yang baru
mendaftar melihat daftar "apa yang belum selesai" di `/penyiapan`, bukan pesan
galat saat menekan tombol terbit.

**2. Bulan pertama dihitung prorata (R-01).**
`tarif × hari_tinggal ÷ hari_dalam_bulan`, dibulatkan setengah ke atas. Masuk
tanggal 15 di bulan 30 hari = separuh. Masuk tanggal 28 di bulan Februari =
sisa tiga hari, bukan sebulan penuh.

**3. Satu kontrak, satu tagihan per periode (R-02).** Dijaga **indeks unik
parsial di basis data**, bukan hanya pemeriksaan di kode. Menekan "terbitkan"
dua kali karena jaringan lambat tidak menghasilkan dua tagihan; yang kedua
ditolak. Tagihan yang dibatalkan tidak menghalangi penerbitan ulang.

**4. Denda berjalan sendiri (R-03).** Mulai hari ke-8 setelah jatuh tempo, 1%
dari pokok per hari, **berhenti di 30%**. Bukan denda yang diketik, melainkan
dihitung dari tanggal — sehingga bisa didemokan hari ini tanpa menunggu
sebulan.

**5. Uang yang salah catat ditarik, bukan dihapus.** Tidak ada `DELETE` pada
pembayaran. Koreksi dilakukan dengan **baris pembalik** bernilai negatif yang
menunjuk baris aslinya. Keduanya tetap ada di laporan, dan selisihnya nol.
Menghapus baris akan menghilangkan bukti bahwa kekeliruan pernah terjadi —
padahal justru itu yang dicari orang saat memeriksa.

**Kenapa append-only.** Tujuh tabel dijaga **trigger basis data** yang menolak
`UPDATE` dan `DELETE`: `tarif_sewa`, `kebijakan_kos`, `komponen_biaya`,
`pembayaran`, `verifikasi_pembayaran`, `persetujuan_pdp`, dan `log_audit` —
ditambah satu trigger yang menolak pembalik bertingkat. Bukan karena kode kita
tidak disiplin, tetapi karena disiplin kode tidak bisa dibuktikan kepada orang
yang memeriksa. Trigger bisa.

**`tagihan` sengaja TIDAK append-only**, dan bedanya penting: tagihan
**berubah status** (terbit → lunas → dibatalkan) sepanjang hidupnya, jadi
`UPDATE` harus diizinkan. Yang menjaga tagihan adalah aturan lain — indeks unik
parsial untuk R-02, `CHECK` yang mewajibkan alasan saat membatalkan, dan
salinan tarif yang dibekukan saat terbit (R-06). Membekukan seluruh baris
tagihan akan membuat tagihan tidak pernah bisa menjadi lunas.

---

## 4. Peta dokumen: mana yang dibaca untuk apa

Tidak perlu dibaca berurutan, dan tidak perlu dibaca semua. Baca yang sesuai
pertanyaan Anda.

| Kalau Anda bertanya… | Baca |
|---|---|
| "Produk ini apa, sudah sampai mana?" | `docs/produk/KONTEKS_PROYEK.md` |
| "Aturan bisnisnya apa saja?" | `SPEC.md` — R-01 sampai R-07 |
| "Endpoint ini bentuknya bagaimana?" | `docs/produk/6_TOOLS_CONTRACTS.md` |
| "Tabel dan kolomnya apa?" | `docs/produk/5_SCHEMAS.md` |
| "Kenapa arsitekturnya begini?" | `docs/produk/3_ARCHITECTURE.md` |
| "Apa yang sudah diuji, dan apa yang belum?" | `docs/produk/7_TESTING_EVALS.md` |
| "Bagaimana men-deploy dan memulihkan data?" | `docs/produk/8_OPERATIONS_SOP.md` |
| "Saya mau mengubah kode, apa yang bisa merusak?" | `docs/produk/4_AGENTS.md` |
| "Utang teknisnya apa saja?" | `docs/utang-teknis.md` |
| "Diagram untuk dosen?" | `docs/akademik/` dan `docs/dfd/` |
| "Charter-nya?" | `docs/charter/` |
| "Apa yang harus dikerjakan sesi berikutnya?" | `docs/serah-terima.md` |

---

## 5. Dokumen akademik: apa yang ada, dan apa yang **tidak** ada

Bagian ini ditulis karena paling sering menimbulkan kebingungan.

### 5.1 Yang ada

| Berkas | Isi | Halaman |
|---|---|---|
| `docs/charter/Project_Charter_Kos-Kosan.docx` / `.pdf` | Project Charter, format template jurusan | 4 |
| `docs/akademik/Proses_Bisnis_Kos_Kosan.pdf` | Narasi tujuh bab | **4 (tepat)** |
| `docs/akademik/Kos_Kosan_Diagrams_Pertemuan_4.pdf` | Use case + DFD konteks | **2 (tepat)** |
| `docs/dfd/01-dfd-konteks.svg` / `.png` | DFD konteks, notasi Gane-Sarson | 1 gambar |
| `docs/dfd/01-dfd-konteks-yourdon.svg` | DFD konteks, notasi Yourdon/DeMarco | 1 gambar |
| `docs/dfd/02-dfd-level-1.svg` / `.png` | **DFD Level 1** — lima proses, enam penyimpanan | 1 gambar |
| `docs/dfd/03-dfd-level-2.md` | Dekomposisi Level 2 + kamus data | teks |
| `docs/akademik/use-case-diagram.md` | 14 use case, 3 aktor, matriks hak akses | teks |

Jumlah halaman 4 dan 2 bukan kebetulan — keduanya **diperiksa setiap kali
dibangun**, dan build gagal kalau berubah. Template dosen menentukan bentuknya.

### 5.2 Soal "DSD" — sudah terjawab: DFD Level 1

Istilah **"DSD" tidak ditemukan** di repo ini, dan juga **tidak ada di materi
kuliah** (P01–P15). Yang sudah saya periksa dengan mengekstrak isi PDF-nya,
bukan dengan mencarinya di teks mentah — pencarian teks mentah pada PDF selalu
menjawab "tidak ada", bahkan untuk kata yang jelas tercetak.

**Pemilik proyek sudah mengonfirmasi: "DSD 1" berarti DFD Level 1.**
Dokumennya sudah ada — `docs/dfd/02-dfd-level-1.svg`, lengkap dengan lima
proses dan enam penyimpanan data. Tidak perlu dibuat apa pun.

DFD Level 1 di proyek ini memakai notasi Gane-Sarson; versi Yourdon/DeMarco
juga tersedia (`02-dfd-level-1-yourdon.svg`) bila dosen meminta notasi itu.
Keduanya dibangun dari `dfd-data.js` yang sama, sehingga mustahil menampilkan
aliran yang berbeda.

### 5.3 Penting: berkas akademik **dibangkitkan**

Jangan menyunting `Proses_Bisnis_Kos_Kosan.md`, `use-case-diagram.md`,
`dfd-konteks.md`, atau berkas `.svg` mana pun. Semuanya **ditulis ulang** oleh:

```powershell
node docs/akademik/build.js          # SVG use case + DFD konteks
node docs/akademik/build-dokumen.js  # tiga berkas .md
```

Suntingan tangan akan hilang pada build berikutnya. Yang disunting adalah
**sumbernya**: `usecase-data.js`, `dokumen-data.js`, `dfd-data.js`.

Ini bukan kerapian belaka. Dulu angka dan prosa bercampur di satu berkas, dan
keduanya menyimpang tanpa ketahuan — dokumen menyebut 81 endpoint padahal
aplikasinya sudah 85. Sekarang penyimpangan itu **mustahil secara struktur**:
build membaca potret rute yang nyata, dan **menolak** bila ada endpoint yang
tidak menjadi dasar use case mana pun. Pagar itu sudah membuktikan dirinya:
saat empat endpoint baru ditambahkan, build menolak sampai keempatnya dipetakan.

---

## 6. Tujuh aturan yang membentuk sistem

Ketujuh aturan ini ada di `SPEC.md` dan di `docs/dfd/README.md`. Dibaca
berurutan, ketujuhnya menjelaskan hampir seluruh perilaku aplikasi.

| Kode | Aturan | Dijaga oleh |
|---|---|---|
| **R-01** | Bulan pertama prorata: `tarif × hari ÷ hari_dalam_bulan` | Perhitungan, 5 kasus uji batas |
| **R-02** | Satu kontrak satu tagihan per periode | Indeks unik parsial di basis data |
| **R-03** | Denda mulai hari 8, 1%/hari, plafon 30% | Perhitungan dari tanggal |
| **R-04** | Tunggakan berkelompok: belum jatuh tempo / 0–30 / 31–60 / >60 | Query laporan |
| **R-05** | Kebijakan wajib lengkap sebelum tagihan pertama | Penerbitan ditolak |
| **R-06** | Tarif tidak berlaku surut; tagihan menyimpan salinan saat terbit | Snapshot + append-only |
| **R-07** | Penghuni hanya melihat tagihannya sendiri | Scope query; milik orang lain → **404**, bukan 403 |

**R-07 sengaja 404.** Menjawab 403 untuk tagihan milik orang lain berarti
mengakui tagihan itu ada. Penyerang yang menebak ID bisa memetakan berapa
banyak tagihan yang ada dan kapan saja terbitnya, tanpa pernah melihat satu
angkanya. 404 menutup kebocoran itu tanpa mengurangi apa pun bagi pengguna sah.

---

## 7. Keputusan yang sudah final (dan jangan dibuka lagi tanpa alasan baru)

Bagian ini yang paling sering ditanyakan ulang. Masing-masing sudah diputuskan
dengan alasan tertulis, dan membukanya kembali berarti mengulang perdebatan yang
sudah selesai.

**Model data: satu kos, satu pemilik.** Bukan banyak kos dengan banyak pemilik.
Alasannya bukan kesulitan teknis — model banyak kos berarti setiap query harus
menyaring `kos_id`, setiap peran harus terikat ke satu kos, dan setiap uji harus
membuktikan isolasi antarkos. Untuk satu kos 5–30 kamar, biaya itu tidak
dibayar oleh manfaat apa pun. **Konsekuensinya diterima:** aplikasi ini tidak
bisa dipakai satu orang untuk mengelola dua kos. Alasan lengkap ada di
`3_ARCHITECTURE.md` §3.6 dan `SPEC.md`.

**Tanpa deposit.** Kolom `kontrak.deposit`, tipe kebijakan `deposit`, dan
seluruh alurnya **dihapus**. Alasannya: kolom yang menyimpan angka tanpa akibat
membuat orang mengira uangnya terjaga. Kos yang memungut deposit mencatatnya di
luar sistem, dan charter menyatakan itu terang-terangan.

**Tiga dimensi kebijakan, bukan empat.** `satuan_sewa` (3 pilihan: bulanan,
mingguan, harian), `jatuh_tempo` (3: tanggal masuk, tanggal tetap, akhir
bulan), dan `denda` (4: persen harian, persen bulanan, nominal tetap, tanpa
denda). Angka "108 kombinasi" yang pernah tertulis adalah salah dan sudah
dikoreksi menjadi **36** (3 × 3 × 4). Kalau Anda menemukan "108" di dokumen
mana pun, itu sisa yang belum diperbaiki — laporkan.

**Jumlah use case 14, final.** Empat endpoint yang ditambahkan belakangan
(ekspor CSV dan status penyiapan) **dipetakan ke use case yang sudah ada**,
bukan dijadikan use case baru. Alasannya: use case menggambarkan pekerjaan
pengguna, dan "mengekspor" bukan pekerjaan yang berbeda dari "melihat laporan" —
hanya bentuk keluarannya yang berbeda.

**Empat belas, bukan jumlah endpoint.** Diagram yang memetakan satu kotak per
endpoint berhenti menjelaskan pekerjaan pengguna dan mulai menjelaskan daftar
rute. Itu bukan use case.

---

## 8. Cara menjalankan, dan cara memeriksa

```powershell
# Nyalakan semuanya (API, basis data, web)
docker compose up -d

# Aplikasi: http://localhost:3000
# Akun contoh: pemilik@kos.test / pengelola@kos.test / penghuni@kos.test
# Sandi semuanya: password
```

**Perintah yang harus dihafal hanya satu:**

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1   # Windows
bash periksa.sh                                        # Linux/macOS/CI
```

Itu menjalankan **seluruh** pagar: pint, eslint, vitest, 830 tes PHP, empat pemeriksa
kesesuaian dokumen, dan **30 skrip uji** (27 browser, 2 balapan, 1 jalur proxy).
Ada 31 berkas `.mjs` di `docs/uji/`; satu di antaranya, `_cdp.mjs`, adalah
pembantu yang tidak dijalankan sendiri. Butuh sekitar **sepuluh menit** - 568
detik pada mesin pengembangan proyek ini, untuk 879 langkah.

Jangan menjalankan tes satu per satu — urutannya menentukan hasil, dan salah
urutan menghasilkan hijau yang menyesatkan.

**Tiga jebakan yang paling banyak memakan waktu:**

1. **Kode dibakar ke dalam image.** Mengubah `api/` tidak berpengaruh sampai
   `docker compose build api && docker compose up -d api`. Setelah itu
   `docker compose restart api-web`, karena nginx memegang alamat IP lama dan
   akan menjawab **502**.
2. **`docker compose exec api` menjalankan kode dari image, bukan dari disk.**
   Berkas yang baru Anda sunting belum ada di dalam container sampai
   di-`cp`.
3. **`periksa.ps1` menulis ulang semua `docs/uji/*.png`.** Jangan
   `git checkout -- docs/uji` secara borongan; kembalikan satu per satu.

---

## 9. Pembagian kerja: siapa memegang apa

Pembagian ini mengikuti dokumen, bukan bagan organisasi. Yang penting bukan
siapa "memiliki" berkas, melainkan **siapa yang harus membaca apa sebelum
memutuskan**.

| Peran | Memegang | Wajib membaca sebelum memutuskan |
|---|---|---|
| **Manajer produk / dosen** | `docs/charter/`, `docs/akademik/`, lingkup | `SPEC.md` §3 (batas), `docs/utang-teknis.md` |
| **Analis sistem** | `SPEC.md`, `docs/dfd/`, `docs/akademik/usecase-data.js` | `docs/dfd/README.md`, `3_ARCHITECTURE.md` |
| **Pengembang backend** | `api/`, `docs/produk/6_TOOLS_CONTRACTS.md` | `4_AGENTS.md`, `5_SCHEMAS.md` |
| **Pengembang frontend** | `web/`, `2_DESIGN_SYSTEM.md` | `4_AGENTS.md` §4 (hal yang mematahkan uji) |
| **Penguji** | `docs/uji/`, `7_TESTING_EVALS.md` | `docs/uji/README.md` — katalog jebakan |

**Aturan yang berlaku untuk semua:** kalau Anda mengubah perilaku, ubah
dokumennya di commit yang sama. Angka di dokumen dibaca dari sumbernya, bukan
diketik — dan angka yang diketik akan tertinggal. Itu sudah terjadi sekali:
tabel di `KONTEKS_PROYEK.md` tertinggal jauh (71 endpoint padahal 85) karena
angkanya diketik tangan.

---

## 10. Yang belum selesai, dan mengapa

Jujur soal ini lebih berguna daripada daftar fitur.

| Belum | Mengapa belum | Butuh apa |
|---|---|---|
| **Deploy ke hosting sungguhan** | **Dibatalkan pemilik 29 Okt 2026** | Tidak ada; demo dari laptop |
| **Midtrans sandbox sungguhan** | Ikut batal: webhook butuh HTTPS publik | Tidak ada |
| ~~**Tagihan terjadwal otomatis**~~ | ~~Tanpa cron/worker persisten~~ | **Sudah ada** (`schedule:work` di supervisord). Batasnya container gratis yang tidur - A3 |
| ~~**Antrean email**~~ | ~~30 penghuni = 30 pengiriman berurutan~~ | **Sudah ada (Fase 5.5):** program `antrean` di supervisord |
| **Format laporan akhir** | Menunggu dosen | Konfirmasi format dari dosen |

**Soal hosting, khususnya.** Hostinger **shared hosting** tidak bisa menjalankan
aplikasi ini: PostgreSQL hanya tersedia di VPS, ekstensi `pdo_pgsql` tidak ada,
dan `shell_exec`/`exec` dimatikan sehingga perintah backup gagal. Domainnya
tetap bisa dipakai dengan mengarahkan CNAME ke Render. Rinciannya di
`docs/produk/12_OPSI-HOSTING.md`.

---

## 11. Kalau Anda hanya punya lima menit

Baca tiga hal ini, dalam urutan ini:

1. **§1 dan §2** dokumen ini — apa produknya, siapa penggunanya.
2. **§6** — tujuh aturan yang membentuk seluruh perilaku.
3. **`docs/demo-sidang.md`** — lima menit demo yang memperlihatkan kelima hal
   paling penting, lengkap dengan perintah untuk membuktikannya sendiri.

Sisanya bisa Anda cari saat pertanyaannya muncul. Dokumen ini tidak perlu
dihafal; ia perlu **diketahui keberadaannya**.
