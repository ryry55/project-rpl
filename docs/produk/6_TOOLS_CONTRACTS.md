# 6. Tools & Contracts

> Dokumen ini menjawab: **endpoint apa yang ada, siapa yang boleh memanggilnya,
> apa yang dikirim, dan apa yang kembali.**
>
> Sumber kebenaran tetap `api/routes/api.php` dan kelas `Resource`. Dokumen ini
> diambil dari `php artisan route:list --json` pada aplikasi yang berjalan.

Versi 1.0 · 26 Oktober 2026 · **69 endpoint**

---

## 1. Konvensi amplop

Ditetapkan di T1 dan tidak pernah dilanggar sejak itu.

### 1.1 Sukses

```json
{ "data": … }
```

Daftar berhalaman:

```json
{
  "data": [ … ],
  "links": { "first": "…", "last": "…", "prev": null, "next": "…" },
  "meta": { "current_page": 1, "per_page": 25, "total": 12 }
}
```

Beberapa endpoint menambahkan `meta` di luar paginasi untuk menjelaskan
keputusan yang tidak terlihat dari `data`:

| Endpoint | `meta` | Arti |
|---|---|---|
| `POST /tarif`, `POST /kebijakan` | `diajukan` | `false` = langsung berlaku (pemilik), `true` = jadi usulan (pengelola) |
| `POST /tagihan/terbitkan` | `dilewati`, `diterbitkan` | Berapa kontrak dilewati karena sudah punya tagihan periode itu |
| `GET /notifikasi` | `belum_dibaca` | Dihitung dari seluruh baris, **bukan** dari `batas` yang dikirim |
| `GET /usulan` | `jumlah_menunggu` | Dihitung pada scope yang sama dengan daftar |

### 1.2 Galat

```json
{ "message": "…", "errors": { "field": ["…"] } }
```

`errors` hanya ada pada 422. Bentuk ini bawaan Laravel dan **tidak diubah**,
supaya `@error` di Vue dan `assertJsonValidationErrors` di tes bekerja tanpa
lapisan penerjemah.

Beberapa galat menambahkan `kode` yang bisa diperiksa mesin:

| `kode` | HTTP | Arti |
|---|---|---|
| `wajib_ganti_sandi` | 403 | Akun ditahan sampai sandi diganti |
| `kebijakan_belum_lengkap` | 409 | R-05 — setup kebijakan belum selesai |
| `penghapusan_belum_dapat_dilakukan` | 409 | Akun ini masih terikat kontrak/izin |

`kode` **tidak** menggantikan `message`. Klien boleh memakai `kode` untuk
bercabang, tetapi manusia membaca `message`.

---

## 2. Middleware

Empat lapis, dijalankan berurutan.

```
CatatPermintaanGagal   → catat setiap respons ≥400 sebagai satu baris JSON
Authenticate:sanctum   → token → user, atau 401
WajibGantiSandi        → wajib_ganti_sandi → 403
PastikanPeran / PastikanWewenang
```

### 2.1 `peran:` vs `wewenang:`

| | Memeriksa | Dipakai untuk |
|---|---|---|
| `peran:pemilik,pengelola` | **jenis akun** | **Membaca** data kos |
| `wewenang:kelola_kamar` | **izin mengubah** | Menulis |

Pemilik selalu lolos `wewenang:`. Penghuni selalu 403.

**Wewenang membatasi tindakan, bukan pengetahuan.** Pengelola yang mencatat
pembayaran harus melihat nama penghuni dan daftar tagihan; menutup bacaan
membuatnya tidak bisa bekerja, padahal ia tidak mengubah apa pun. Satu-satunya
bacaan yang dibatasi adalah jejak audit (`baca_audit`), karena isinya tindakan
semua orang.

**Urutan yang perlu diingat:** route model binding berjalan **sebelum**
`wewenang:`, jadi id yang tidak ada menghasilkan **404**, bukan 403. Disengaja:
403 akan memberi tahu bahwa id itu ada.

### 2.2 Delapan wewenang

```
catat_pembayaran   kelola_kamar        kelola_penghuni   terbitkan_tagihan
ubah_tarif         ubah_kebijakan     verifikasi_pembayaran   baca_audit
```

`ubah_tarif` dan `ubah_kebijakan` berarti **boleh mengusulkan**, bukan
menerapkan. Lihat §6.

---

## 3. Autentikasi, akun, dan kesehatan (11 endpoint)

### 3.1 Publik

| Metode | Path | Isi |
|---|---|---|
| `POST` | `/api/login` | `{ email, password }` → `{ data: { token, user } }` |
| `POST` | `/api/lupa-sandi` | `{ email }` → `200` selalu, **apa pun** hasilnya; `429` bila terlalu sering |
| `POST` | `/api/periksa-token` | `{ token, jenis? }` → `{ data: { berlaku, nama, email } }` |
| `POST` | `/api/atur-sandi` | `{ token, jenis, password, password_confirmation }` |
| `GET` | `/api/health` | `200 { data: { status: "ok", database, zona_waktu, waktu } }` atau `503` dengan `status: "down"` |

`/lupa-sandi` selalu `200` meski email tidak terdaftar. Membalas `404` untuk
email yang tidak ada berarti aplikasi ini bisa dipakai memeriksa siapa saja yang
menjadi penghuni kos. Waktu respons juga disamakan lewat
`PembatasMasuk::userTidakDikenal()` — tanpa itu, `200` yang sama tetap bisa
dibedakan dari lamanya, dan perbedaan waktu sudah cukup.

`/health` sengaja hanya berisi status — tanpa nama tabel, versi, atau pesan
galat. Pemantau uptime tidak butuh lebih, dan pesan galat di endpoint publik
adalah peta jalan bagi penyerang. Pemeriksaannya menyentuh tabel `users`, bukan
`SELECT 1`: koneksi bisa hidup sementara migrasi belum jalan, dan dalam keadaan
itu setiap layar gagal walau koneksinya sehat.

### 3.2 Butuh token

| Metode | Path | Catatan |
|---|---|---|
| `POST` | `/api/logout` | Hapus token yang dipakai |
| `GET` | `/api/me` | `{ data: { id, nama, email, peran, penghuni_id, pemilik_id, wewenang[] } }` + `meta: { wajib_ganti_sandi, perlu_persetujuan_pdp, versi_pdp }` |
| `POST` | `/api/ganti-sandi` | `{ password_lama?, password, password_confirmation }` |
| `POST` | `/api/persetujuan-pdp` | Tanpa badan. Versi diambil dari server (`PersetujuanPdp::VERSI_BERLAKU`) |

`password_lama` **wajib** kecuali `wajib_ganti_sandi = true`. Pengguna yang
ditahan memang belum tentu tahu sandinya (akun dibuat dengan sandi acak).

`/me` adalah satu-satunya endpoint yang dipakai setiap halaman untuk memutuskan
menu mana yang tampil. `wewenang` dikirim sebagai larik datar — frontend tidak
perlu menebak dari `peran`.

### 3.3 Hak subjek data

| Metode | Path | Catatan |
|---|---|---|
| `GET` | `/api/pdp/data-saya` | Akun, kamar, persetujuan, jejak audit yang menyebut dirinya |
| `DELETE` | `/api/pdp/data-saya` | `{ konfirmasi: "HAPUS" }` |

Tidak ada parameter id di kedua endpoint. Selalu tentang pemanggil sendiri,
sehingga tidak ada pemeriksaan wewenang yang bisa lupa.

`konfirmasi` divalidasi **sebelum** pemeriksaan 409. Urutannya disengaja: bodi
yang salah bentuk adalah 422, dan pengguna yang salah mengetik kata konfirmasi
tidak boleh diberi tahu bahwa akunnya sebenarnya tidak boleh dihapus.

Penghapusan **menganonimkan**, bukan menghapus baris. Akun yang pernah menangani
uang tidak bisa dihapus sama sekali (FK `RESTRICT`) — dan itu disengaja.

---

## 4. Data kos (29 endpoint)

### 4.1 Kamar

| Metode | Path | Middleware | Isi |
|---|---|---|---|
| `GET` | `/api/kamar` | `peran:pemilik,pengelola` | daftar + tarif berlaku |
| `GET` | `/api/kamar/{kamar}` | `peran:pemilik,pengelola` | satu kamar |
| `POST` | `/api/kamar` | `wewenang:kelola_kamar` | `{ nomor, tipe?, catatan? }` |
| `PUT` | `/api/kamar/{kamar}` | `wewenang:kelola_kamar` | sama |
| `PUT` | `/api/kamar/{kamar}/fasilitas` | `wewenang:kelola_kamar` | `{ fasilitas: [id, …] }` |
| `GET` | `/api/kamar/{kamar}/tarif-berlaku` | `peran:pemilik,pengelola` | `?tanggal=YYYY-MM-DD` |

`PUT /fasilitas` **mengganti seluruh daftar** lewat `sync()`, bukan menambah.
Kuncinya `present`: larik kosong berarti "hapus semua fasilitas", dan itu
permintaan yang sah.

### 4.2 Tarif dan komponen biaya

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/tarif` | `peran:pemilik,pengelola` |
| `POST` | `/api/tarif` | `wewenang:ubah_tarif` |
| `GET` | `/api/komponen-biaya` | `peran:pemilik,pengelola` |
| `POST` | `/api/komponen-biaya` | `wewenang:ubah_tarif` |

`POST /tarif` — `{ kamar_id, nilai, berlaku_dari }`.

| Pemanggil | Status | Efek |
|---|---|---|
| Pemilik | `201` | `meta.diajukan = false`, tarif langsung berlaku |
| Pengelola | `202` | `meta.diajukan = true`, baris `usulan_perubahan` dibuat |

`berlaku_dari` tidak boleh bertabrakan dengan baris yang sudah ada
(`UNIQUE (kamar_id, berlaku_dari)`). Dua lapis: `Rule::unique` menolak lebih
dulu dengan **422** dan pesan yang bisa dibaca pemilik; bila dua permintaan
bersamaan sama-sama lolos validasi, unique index menangkapnya dan `SQLSTATE
23505` juga diterjemahkan menjadi **422** dengan bentuk galat yang sama.
Validasi saja tidak cukup — dua permintaan bersamaan sama-sama lolos sebelum
ada yang menyisipkan.

Satu-satunya tempat `23505` menjadi **409** adalah persetujuan usulan
(`POST /usulan/{id}/setujui`), karena di sana usulan bisa gagal diterapkan
setelah pemilik memutuskan — dan usulan yang gagal diterapkan bukan usulan yang
ditolak (lihat §6).

### 4.3 Kebijakan

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/kebijakan` | `peran:pemilik,pengelola` |
| `POST` | `/api/kebijakan` | `wewenang:ubah_kebijakan` |

`POST` — `{ tipe, nilai, kamar_id?, berlaku_dari, persen?, plafon_persen?, masa_tenggang?, nominal?, tanggal? }`

Kolom yang wajib diisi bergantung `tipe`. Lihat `5_SCHEMAS.md` §2.6 untuk
matriksnya. Kolom yang tidak relevan **wajib tidak dikirim**; mengirimnya
menghasilkan `422`, bukan diabaikan — supaya salah paham ketahuan di awal.

Perilaku 201/202 sama seperti `/tarif`.

### 4.4 Fasilitas dan aturan

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/fasilitas` | `peran:pemilik,pengelola` |
| `POST` `PUT` `DELETE` | `/api/fasilitas[/{id}]` | `wewenang:kelola_kamar` |
| `GET` | `/api/aturan` | `peran:pemilik,pengelola` |
| `POST` `PUT` `DELETE` | `/api/aturan[/{id}]` | `wewenang:kelola_kamar` |

`aturan.kategori` adalah **string bebas**. Server mengirim saran lewat
`GET /aturan` → `meta.kategori_umum`, tetapi tidak menolak kategori baru.
Kategori aturan kos terlalu beragam untuk dijadikan enum.

Aturan memakai `aktif`, bukan dihapus. Portal penghuni menyaring `aktif` **di
server**.

### 4.5 Penghuni

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/penghuni` | `peran:pemilik,pengelola` |
| `GET` | `/api/penghuni/{penghuni}` | `peran:pemilik,pengelola` |
| `POST` | `/api/penghuni` | `wewenang:kelola_penghuni` |
| `PUT` | `/api/penghuni/{penghuni}` | `wewenang:kelola_penghuni` |
| `POST` | `/api/penghuni/{penghuni}/kirim-ulang-aktivasi` | `wewenang:kelola_penghuni` |

`POST` — `{ nama, email?, no_hp? }`.

**Tidak ada kolom kata sandi.** Akun dibuat dengan `Str::random(64)` yang
di-hash dan tidak pernah dikembalikan atau dicatat. Aktivasi = pengguna
menetapkan sandinya sendiri lewat tautan email.

`email` boleh kosong (penghuni tanpa akun), tetapi bila ada harus unik.

### 4.6 Kontrak

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/kontrak` | `peran:pemilik,pengelola` |
| `GET` | `/api/kontrak/{kontrak}` | `peran:pemilik,pengelola` |
| `POST` | `/api/kontrak` | `wewenang:kelola_penghuni` |
| `POST` | `/api/kontrak/{kontrak}/akhiri` | `wewenang:kelola_penghuni` |

`POST` — `{ kamar_id, penghuni_id, tgl_masuk, tarif?, deposit? }`.

`tarif_awal` **wajib ada di database** tetapi klien hanya boleh mengirimnya bila
kamar **belum punya tarif** pada `tgl_masuk`. Bila kamar sudah punya, server
mengambilnya dari `PencariTarif` dan **menolak `tarif` yang berbeda** dengan
`422` pada bidang `tarif` — bukan menimpanya. Kalau klien boleh menang, akan ada
dua sumber kebenaran untuk tarif dan tidak ada yang bisa menjelaskan selisihnya.

`deposit` memakai `filled()`, bukan `integer()`: `integer()` mengembalikan `0`
baik untuk "tidak diisi" maupun "diisi nol", sehingga deposit kosong akan
terbaca sebagai "tanpa deposit" dan mengabaikan kebijakan kos.

Satu kamar hanya boleh punya satu kontrak aktif (indeks parsial). Melanggar →
`409`.

---

## 5. Tagihan dan pembayaran (12 endpoint)

### 5.1 Tagihan

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/tagihan` | `peran:pemilik,pengelola` |
| `GET` | `/api/tagihan/{tagihan}` | `auth` saja (difilter scope) |
| `POST` | `/api/tagihan` | `wewenang:terbitkan_tagihan` |
| `POST` | `/api/tagihan/terbitkan` | `wewenang:terbitkan_tagihan` |
| `GET` | `/api/tagihan/{tagihan}/denda` | `auth` saja |
| `POST` | `/api/tagihan/{tagihan}/pembayaran` | `wewenang:catat_pembayaran` |

`POST /tagihan` — `{ kontrak_id, periode }`. `periode` = tanggal 1 bulan
bersangkutan.

`GET /tagihan/{tagihan}` sengaja **tidak** memakai `peran:` — penghuni harus
bisa membuka tagihannya sendiri. Isolasi ditegakkan `scopeMilikPenghuni` di
query. Tagihan milik orang lain → **404**.

Bentuk `TagihanResource` yang perlu diketahui:

```json
{
  "id": 1, "periode": "2026-02-01", "pokok": 1500000,
  "jatuh_tempo": "2026-02-10", "status": "belum_bayar",
  "denda": 45000, "total": 1545000, "dibayar": 0, "sisa": 1545000,
  "denda_ringkas": {
    "tipe": "persen_harian", "bentuk": "persen",
    "berjalan": true, "hari_terlambat": 3, "masa_tenggang": 7,
    "hari_denda": 3, "plafon": 450000, "sisa_hari_ke_plafon": 27,
    "berhenti": false, "alasan_berhenti": null
  },
  "tarif_terpakai": { … }, "kebijakan_terpakai": { … }
}
```

**Denda dihitung saat dibaca, bukan disimpan.** Nilainya bergantung pada "hari
ini", jadi kolom akan selalu basi dan butuh job penyegar. `denda_ringkas`
dikirim supaya layar tidak perlu memanggil `/denda` hanya untuk menampilkan
"terlambat 12 hari".

`GET /tagihan/{id}/denda` mengembalikan uraian lengkap untuk layar simulasi.

### 5.2 Penerbitan massal

`POST /api/tagihan/terbitkan` — `{ periode }`.

Menerbitkan untuk **semua kontrak aktif**. Kontrak yang sudah punya tagihan
periode itu dilewati, bukan digagalkan — kalau digagalkan, satu tagihan yang
sudah ada akan memblokir seluruh bulan.

```json
{ "data": [ … ], "meta": { "diterbitkan": 11, "dilewati": 1 } }
```

Setiap penghuni menerima satu email. Dengan `QUEUE_CONNECTION=sync`, 30
penghuni berarti 30 pengiriman berurutan di dalam satu permintaan — dicatat
sebagai utang teknis.

### 5.3 Pembayaran

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/pembayaran` | `peran:pemilik,pengelola` |
| `GET` | `/api/pembayaran/penerima` | `peran:pemilik,pengelola` |
| `POST` | `/api/pembayaran/{pembayaran}/verifikasi` | `wewenang:verifikasi_pembayaran` |

`POST /tagihan/{tagihan}/pembayaran` — `{ jumlah, tanggal_pembayaran, metode, catatan?, diterima_oleh? }`

`metode` **wajib** di API (`tunai`|`transfer`|`qris`|`gateway`). Di database
boleh NULL hanya untuk baris lama.

`diterima_oleh` **nullable di validasi**, tetapi database menegakkan
`pembayaran_tunai_ada_pemegang`: bila `metode = 'tunai'`, `diterima_oleh` tidak
boleh NULL. Constraint itu `NOT VALID` — ia berlaku untuk baris baru, bukan
memeriksa ulang baris lama yang sudah terlanjur ada. Praktisnya, layar selalu
mengirim penerima untuk tunai; yang menahan adalah database, bukan validator.

`jumlah` tidak boleh melebihi sisa tagihan. Kelebihan ditolak, bukan disimpan
sebagai saldo — saldo negatif adalah utang terbalik yang tidak ada artinya di
kos.

`tanggal_pembayaran` tidak boleh di masa depan, dibandingkan terhadap
`WaktuSistem::hariIni()` — **bukan** `now()`. Tanpa itu, mode demo dengan tanggal
simulasi 2026-11-21 akan menolak pembayaran bertanggal 2026-11-21 sebagai "masa
depan", karena jam dinding masih Oktober.

Verifikasi:

```json
{ "keputusan": "terima" | "tolak", "catatan": "…" }
```

`catatan` wajib bila `tolak` (CHECK `tolak_beralasan`).

**Verifikasi tidak mengubah `pembayaran.jumlah` maupun `tagihan.status`.** Ia
catatan pemeriksaan, bukan koreksi. Satu baris = satu pemeriksaan; yang terakhir
berlaku.

`verifikasi_terakhir: null` berarti **belum diperiksa**, bukan ditolak. Layar
yang tidak bisa membedakan keduanya akan menandai uang yang belum sempat
diperiksa sebagai uang bermasalah.

### 5.4 Tunggakan, rekap, audit

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/tunggakan` | `peran:pemilik,pengelola` |
| `GET` | `/api/rekap` | `peran:pemilik,pengelola` |
| `GET` | `/api/audit-log` | `wewenang:baca_audit` |

`GET /tunggakan` mengelompokkan ke `belum_jatuh_tempo` / `0_30` / `31_60` /
`lebih_60` (R-04).

`GET /rekap?dari=&sampai=` — keduanya opsional, dan **bawaannya bulan
berjalan** (`dari` = awal bulan, `sampai` = hari ini), bukan "seluruhnya".
Rekap adalah layar yang dibuka untuk melihat "bagaimana bulan ini", dan
menuntut dua tanggal sebelum menampilkan apa pun membuat halaman kosong pada
kunjungan pertama.

Pendapatan = `SUM(pembayaran.jumlah)` pada `tanggal_pembayaran` (basis kas).
Denda yang belum dibayar dilaporkan **terpisah** sebagai
`piutang.denda_belum_dibayar` — ia bukan pendapatan sampai ada uang masuk.

`GET /pembayaran` **mewajibkan** `dari` dan `sampai`; `/rekap` tidak. Daftar
pembayaran tanpa rentang bisa mengembalikan ribuan baris, sedangkan rekap tanpa
rentang punya jawaban bawaan yang masuk akal.

---

## 6. Usulan dan persetujuan (3 endpoint)

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/usulan` | `peran:pemilik,pengelola` |
| `POST` | `/api/usulan/{usulan}/setujui` | `peran:pemilik` |
| `POST` | `/api/usulan/{usulan}/tolak` | `peran:pemilik` |

**Endpoint keputusan memakai `peran:pemilik`, bukan `wewenang:ubah_tarif`.**
Pemilik selalu lolos wewenang apa pun, jadi memakai wewenang akan tampak benar —
tetapi ia menyiratkan bahwa wewenang itu bisa didelegasikan, dan itu bertentangan
dengan seluruh alasan usulan ini ada.

`GET /usulan?status=menunggu|disetujui|ditolak|kedaluwarsa`

Pengelola hanya melihat usulannya sendiri; pemilik melihat semua.

Dua penyaring memakai **dua syarat**, karena kedaluwarsa tidak punya barisnya
sendiri di database:

| `?status=` | Yang dijalankan |
|---|---|
| `kedaluwarsa` | `status = 'menunggu' AND kedaluwarsa_pada < now()` |
| `menunggu` | `status = 'menunggu' AND kedaluwarsa_pada >= now()` |
| lainnya | `status = ?` |

Menyaring `status = 'kedaluwarsa'` saja akan selalu menghasilkan daftar kosong,
dan daftar kosong terbaca sebagai "tidak ada yang kedaluwarsa". Sebaliknya,
`menunggu` tanpa syarat kedua akan menampilkan usulan yang sebenarnya sudah
mati sebagai "masih bisa diputuskan".

`POST .../tolak` — `{ alasan }`, wajib.

**Dua baris audit per alur:**

| Kejadian | `aksi` | `entitas` |
|---|---|---|
| Pengelola mengajukan | `tarif.usul` | `usulan_perubahan` |
| Pemilik menyetujui | `tarif.setujui` | `tarif_sewa` |
| Pemilik menolak | `tarif.tolak` | `usulan_perubahan` |

Persetujuan yang gagal karena `23505` (tarif untuk tanggal itu sudah ada)
mengembalikan `409` dan **membiarkan usulan tetap `menunggu`**. Usulan yang
gagal diterapkan bukan usulan yang ditolak.

---

## 7. Pengelola (6 endpoint)

Semuanya `peran:pemilik`. Memberi wewenang tidak bisa didelegasikan — siapa pun
yang boleh memberi wewenang pada dasarnya boleh memberi dirinya wewenang apa pun.

| Metode | Path | Isi |
|---|---|---|
| `GET` | `/api/pengelola` | daftar + `wewenang[]` |
| `GET` | `/api/pengelola/{id}` | satu |
| `POST` | `/api/pengelola` | `{ nama, email, wewenang: [] }` |
| `PUT` | `/api/pengelola/{id}` | sama |
| `DELETE` | `/api/pengelola/{id}` | cabut |
| `POST` | `/api/pengelola/{id}/kirim-ulang-aktivasi` | kirim ulang tautan |

`POST` tidak punya kolom kata sandi. Sama seperti penghuni: akun dibuat dengan
sandi acak yang tidak pernah diketahui siapa pun, aktivasi lewat email.

`POST` dan `PUT` membalas `meta: { tautan_aktivasi_terkirim, email }`. Layar
memakai `tautan_aktivasi_terkirim` untuk membedakan dua pesan yang sangat
berbeda: "tautan sudah dikirim" dan "akun dibuat, tetapi email gagal dikirim —
periksa alamatnya lalu tekan kirim ulang". Tanpa itu, email yang salah ketik
akan tampak seperti aktivasi yang tidak pernah sampai.

Mencabut pengelola = turunkan ke `penghuni` + kosongkan `pemilik_id` + hapus
seluruh wewenang + hapus seluruh token Sanctum. Token dicabut, bukan hanya
wewenangnya: mencabut wewenang membuat permintaan berikutnya ditolak, sedangkan
mencabut token membuat orangnya tidak bisa memakai akun itu sama sekali — dan
itu yang dimaksud "cabut akses". Tidak ada baris yang dihapus; jejak audit yang
menyebutnya harus tetap bisa dibaca.

Payload audit `pengelola.tambah`/`ubah`/`cabut` **tidak** memuat `nama` atau
`email` subjek, hanya `wewenang`. Alasannya UU PDP: pencabutan wewenang tidak
perlu meninggalkan nama orang itu di catatan sejarah selamanya.

---

## 8. Portal penghuni dan notifikasi (7 endpoint)

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/penghuni/tagihan` | `peran:penghuni` |
| `GET` | `/api/penghuni/aturan` | `peran:penghuni` |

**Keduanya harus didaftarkan sebelum `apiResource('penghuni')`.** Kalau tidak,
`GET /penghuni/tagihan` ditangkap `GET /penghuni/{penghuni}` dan "tagihan"
diperlakukan sebagai id — route model binding mencari `penghuni.id = 'tagihan'`
dan PostgreSQL menolaknya dengan `22P02`. Kelas kesalahan yang sama dengan
`/tagihan/terbitkan`.

### 8.1 Notifikasi (5 endpoint)

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/notifikasi` | `auth` |
| `POST` | `/api/notifikasi/{id}/baca` | `auth` |
| `POST` | `/api/notifikasi/baca-semua` | `auth` |
| `GET` | `/api/pengaturan-notifikasi` | `auth` |
| `PUT` | `/api/pengaturan-notifikasi` | `auth` |

Sengaja **di luar** `WajibGantiSandi`: pesan notifikasi bisa menjelaskan kenapa
akun ditahan, dan kotak masuk yang tidak bisa dibuka akan menyembunyikan
penjelasan itu.

`GET /notifikasi?saja_belum_dibaca=&batas=` — `batas` bawaan **30**, maksimum
**100**.

Bentuk tiap baris: `{ id, peristiwa, label_peristiwa, judul, pesan, tautan, dibaca_pada, dibuat_pada }`.

`meta.belum_dibaca` dihitung dari **seluruh** baris, bukan dari 30 yang dikirim.
Lencana menu yang menghitung dari 30 baris terakhir akan berhenti bertambah
setelah tiga puluh, dan pemilik mengira tidak ada lagi yang baru.

Notifikasi milik akun lain pada `POST /notifikasi/{id}/baca` menghasilkan
**404**, bukan 403 — aturan yang sama dengan R-07.

`PUT /pengaturan-notifikasi` — `{ matriks: { "tagihan.terbit": { "in_app": true, "email": true }, … } }`

Bentuknya **peta bersarang** `peristiwa → kanal → boolean`, bukan larik baris.
Layar mengirim seluruh matriks sekaligus, tetapi server hanya menghapus baris
untuk peristiwa yang disebut (`array_keys($matriks)`) — endpoint yang menghapus
semua lalu menulis sebagian akan membuang preferensi yang tidak pernah dikirim
pemanggil, tanpa satu pun galat.

**Tidak adanya baris = pakai bawaan kode**, bukan "mati". Pengguna baru tidak
perlu diberi 16 baris bawaan, dan peristiwa baru otomatis punya perilaku tanpa
migrasi data. Baris yang isinya sama dengan bawaan juga tidak ditulis, supaya
perubahan bawaan di kode tetap berlaku bagi akun lama.

`in_app` **tidak pernah disimpan sebagai `false`**. Permintaan "matikan in-app"
berakhir sebagai "tidak ada baris", bukan sebagai baris berisi keputusan yang
tidak pernah dijalankan.

---

## 9. Tabel rujukan cepat

| Kelompok | Jumlah | Prefix |
|---|---|---|
| Autentikasi & akun | 8 | `/login`, `/logout`, `/me`, `/ganti-sandi`, `/lupa-sandi`, `/periksa-token`, `/atur-sandi`, `/persetujuan-pdp` |
| PDP | 2 | `/pdp/*` |
| Kamar & fasilitas | 10 | `/kamar*`, `/fasilitas*` |
| Tarif, komponen, kebijakan | 6 | `/tarif`, `/komponen-biaya`, `/kebijakan` |
| Aturan | 4 | `/aturan*` |
| Penghuni & kontrak | 9 | `/penghuni*`, `/kontrak*` |
| Tagihan | 6 | `/tagihan*` |
| Pembayaran | 3 | `/pembayaran*` |
| Laporan | 3 | `/tunggakan`, `/rekap`, `/audit-log` |
| Usulan | 3 | `/usulan*` |
| Pengelola | 6 | `/pengelola*` |
| Portal penghuni | 2 | `/penghuni/tagihan`, `/penghuni/aturan` |
| Notifikasi | 5 | `/notifikasi*`, `/pengaturan-notifikasi` |
| Infrastruktur | 2 | `/health` (§3.1), `/pemilik/saja` |
| **Total** | **69** | |

---

## 10. Kontrak yang mudah dilanggar

Daftar ini ada karena masing-masing pernah dilanggar sekali.

| Kontrak | Kalau dilanggar |
|---|---|
| Sukses selalu `{ data: … }` | Frontend membaca `res.data` lalu `undefined`; halaman kosong tanpa galat |
| Galat selalu `{ message, errors? }` | `@error` tidak menemukan bidangnya; pengguna tidak tahu apa yang salah |
| 404 untuk data orang lain | 403 memberi tahu data itu ada; penyerang bisa memetakan jumlah tagihan |
| `periode` = tanggal 1 | Unique `(kontrak_id, periode)` tidak menahan apa pun; tagihan ganda lolos |
| `metode` wajib di API | Rekap punya keranjang `tanpa_metode` yang tidak bisa dijelaskan |
| `catatan` wajib saat `tolak` | Penolakan tanpa alasan tidak bisa dipertanggungjawabkan |
| `?status=kedaluwarsa` menyaring `menunggu` | Baris `kedaluwarsa` tidak pernah ada di database; daftar selalu kosong |
| `?status=menunggu` tanpa syarat kedaluwarsa | Usulan yang sudah mati tampil sebagai "masih bisa diputuskan" |
| `/notifikasi` di luar `WajibGantiSandi` | Pengguna tertahan tidak bisa membaca alasan penahanannya |
| Rute spesifik sebelum `apiResource` | `22P02` dari PostgreSQL, terbaca seperti kerusakan database |
| `wewenang` tidak untuk membaca | Pengelola yang mencatat pembayaran melihat daftar kosong |
