# 6. Tools & Contracts

> Dokumen ini menjawab: **endpoint apa yang ada, siapa yang boleh memanggilnya,
> apa yang dikirim, dan apa yang kembali.**
>
> Sumber kebenaran tetap `api/routes/api.php` dan kelas `Resource`. Dokumen ini
> diambil dari `php artisan route:list --json` pada aplikasi yang berjalan.

Versi 1.0 · 26 Oktober 2026 · **80 endpoint**

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

## 3. Autentikasi, akun, dan kesehatan (12 endpoint)

### 3.1 Publik

| Metode | Path | Isi |
|---|---|---|
| `POST` | `/api/login` | `{ email, password }` → `{ data: { token, user } }` |
| `POST` | `/api/lupa-sandi` | `{ email }` → `200` selalu, **apa pun** hasilnya; `429` bila terlalu sering |
| `POST` | `/api/periksa-token` | `{ token, jenis? }` → `{ data: { berlaku, nama, email } }`; `429` bila terlalu sering |
| `POST` | `/api/atur-sandi` | `{ token, jenis, password, password_confirmation }` |
| `GET` | `/api/health` | `200 { data: { status: "ok", database, zona_waktu, waktu } }` atau `503` dengan `status: "down"` |
| `GET` | `/api/pemilik/saja` | Pemeriksa middleware `peran:pemilik`; `403` untuk peran lain |

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

**`/periksa-token` dibatasi 20 kali per menit per IP dan menyamarkan email
(REV-SEC-05).** Endpoint ini publik dan menjawab "token ini sah" untuk token acak
64 karakter; tanpa batas ia adalah mesin penebak. Kuncinya IP saja karena email
justru yang sedang dicari. `nama` tetap dikirim utuh — itulah satu-satunya guna
layar ini, dan orang yang memegang tokennya memang sudah membuka kotak surat akun
itu — sedangkan `email` menjadi `bu***@kos.test`. Ini pengerasan, bukan penutupan
lubang kritis: token 64 karakter acak sudah di luar jangkauan tebak-menebak, dan
pemegang token yang sah bisa langsung menetapkan sandi baru lewat `/atur-sandi`
tanpa perlu membaca alamat emailnya.

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

**Penghuni yang masih punya tagihan belum lunas juga ditahan (REV-SEC-04).**
Sebelum R2 pemeriksaan berhenti di status kontrak. Penghuni yang kontraknya sudah
selesai tetapi masih menunggak bisa menghapus akunnya — dan sesudah itu tidak ada
lagi yang bisa ditagih atas namanya, karena namanya sudah menjadi "pengguna
dihapus". Tagihan `belum_bayar` dan `sebagian` menghalangi; tagihan `dibatalkan`
tidak, karena pembatalan berarti memang tidak ditagihkan lagi dan memblokir
karenanya akan mengunci orang selamanya pada kesalahan yang sudah diperbaiki.

Jawabannya **409** dengan alasan yang bisa dibaca, bukan 403: pengguna berhak
meminta, hanya waktunya belum tepat.

---

## 4. Data kos (33 endpoint)

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

**`POST /komponen-biaya` memakai aturan yang sama (REV-SEC-07).** Sebelumnya
endpoint itu menulis baris untuk kedua peran, sehingga `ubah_tarif` yang
seharusnya berarti "boleh mengusulkan" menjadi "boleh menambah biaya bulanan ke
seluruh kamar tanpa persetujuan" — dan menambah komponen berarti menaikkan
tagihan **setiap** penghuni bulan berikutnya. Sekarang: pemilik `201`, pengelola
`202` + baris usulan bertipe `komponen`. Tidak ada wewenang baru; `ubah_tarif`
dipakai kembali supaya pengelola yang sudah memilikinya tidak diam-diam
kehilangan hak.

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

### 4.4.1 Rekening tujuan pembayaran (REV-UI-05)

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/rekening` | `peran:pemilik,pengelola` |
| `POST` `PUT` `DELETE` | `/api/rekening[/{rekening}]` | `peran:pemilik` |
| `GET` | `/api/rekening/{rekening}/gambar` | `auth:sanctum` (semua peran) |
| `GET` | `/api/penghuni/rekening` | `peran:penghuni` |

`POST` / `PUT` — `{ jenis, nama, nomor?, atas_nama?, catatan?, aktif?, urutan?,
gambar?, hapus_gambar? }`. `jenis` ∈ `transfer` \| `qris`; `nama` wajib; `nomor`
boleh kosong untuk QRIS statis yang hanya berupa gambar.

**Menulis hanya pemilik.** Nomor rekening adalah alamat uang masuk: pengelola
yang boleh menggantinya bisa mengarahkan seluruh setoran penghuni ke rekeningnya
sendiri, lalu menandai tagihannya lunas dengan wewenang `catat_pembayaran` yang
memang ia punya. Tidak ada pemeriksaan lain yang menangkapnya, jadi gerbangnya
`peran:pemilik` — bukan `wewenang:`, karena tidak ada wewenang yang boleh
membukanya.

Membaca dibuka untuk pengelola (ia yang menagih, jadi ia perlu tahu ke mana
penghuni mentransfer) dan untuk penghuni lewat endpoint sempit
`/penghuni/rekening` yang hanya mengirim baris **aktif** dan tanpa `meta`.
Penghuni yang mentransfer ke rekening lama yang sudah tidak dipakai kehilangan
uangnya, dan tidak ada kode yang bisa mengembalikannya — jadi penyaringnya di
server.

**Tidak append-only**, berbeda dari tarif dan kebijakan: tidak ada angka tagihan
yang dihitung dari baris ini. Setiap tambah/ubah/hapus menulis `log_audit`
dengan salinan baris lengkap, termasuk nomor lama saat nomor diganti. Isi
gambarnya **tidak** ikut disalin ke `log_audit` — yang dicatat hanya
`punya_gambar`, `gambar_mime`, dan `gambar_ukuran`.

#### Gambar QRIS

`gambar` adalah berkas (`multipart/form-data`) berisi PNG, JPG, atau WEBP,
maksimal 2 MB. `hapus_gambar: true` membuang gambar tanpa menghapus rekening.
Hanya berlaku untuk `jenis: qris`; pada `transfer` dijawab **422** dengan pesan
yang menyebut alasannya.

Isi diperiksa `getimagesize`, **bukan** nama berkas atau `Content-Type` kiriman:
berkas PDF yang dinamai `.png` ditolak. **SVG ditolak** karena ia dokumen yang
bisa memuat skrip, dan menyajikannya kembali dari origin aplikasi membuat satu
unggahan menjadi jalan menjalankan kode di peramban penghuni.

**Disimpan di kolom `bytea`, bukan berkas di disk.** `public/` dipasang
read-only ke nginx dan Render free tidak punya disk persisten; berkas yang
ditulis hari ini hilang saat restart. Isinya hanya bisa keluar lewat
`GET /api/rekening/{id}/gambar`, yang mengirim `Content-Type`,
`X-Content-Type-Options: nosniff`, dan CSP yang melarang skrip.

> **Jebakan yang wajib diketahui siapa pun yang menyentuh kolom ini.** Menulis
> `bytea` lewat parameter teks biasa (termasuk `mass assignment`) menyimpan
> **0 byte tanpa galat apa pun**: unggahan tampak berhasil, `gambar_ukuran`
> terisi, gambarnya kosong. Satu-satunya jalur tulis yang terbukti utuh adalah
> `decode(?, 'base64')` di `App\Domain\Rekening\PenyimpanGambarQris`. Karena itu
> `gambar` sengaja **tidak** ada di `$fillable` `Rekening`.

`RekeningResource` mengirim `punya_gambar`, `gambar_mime`, `gambar_ukuran`, dan
`gambar_path` (path relatif, mis. `/rekening/3/gambar`) — **bukan** isi gambarnya
dan bukan URL absolut. Path relatif dipakai karena rutenya butuh Bearer token,
sedangkan `<img src>` tidak bisa mengirim header: komponen `GambarQris.vue`
mengambilnya lewat `fetch` ber-token lalu menjadikannya blob. URL absolut dari
`route()` tidak dipakai karena di dev API dan SPA berbeda port.

Penghuni hanya bisa membuka gambar rekening **aktif**; rekening nonaktif
dijawab **404**, bukan 403 — 403 akan memberi tahu bahwa rekening lama itu masih
ada. Pengelola dan pemilik bisa membuka keduanya.

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

**Email akun yang sudah aktif tidak bisa diubah lewat `PUT` (REV-SEC-01).**
Sebelumnya endpoint ini mengganti kolom email lalu menghapus `email_verified_at`
dan seluruh sesi. Niatnya benar — tautan lama harus batal — tetapi akibatnya
jalan pengambilalihan yang rapi: pemilik mengubah email penghuni menjadi alamat
yang ia kuasai, akun itu kembali "belum aktif", pemilik menekan "kirim ulang
aktivasi" dan menetapkan sandinya sendiri sebagai pemegang kotak surat. Penghuni
aslinya tidak pernah menerima apa pun, dan R-07 runtuh dari dalam.

Penolakan dijawab **409** pada bidang `email`. Alamat yang salah ketik **hanya
bisa ada pada akun yang belum aktif** — email diverifikasi justru dengan membuka
tautan yang dikirim ke alamat itu — jadi akun aktif pasti punya alamat yang bisa
dihubungi, dan penghuninya sendiri yang memakai alur "lupa sandi" bila perlu.
Nama tetap boleh diubah; yang dikunci hanya alamatnya. Untuk akun yang belum
aktif, mengganti email tetap bekerja dan mengirim tautan baru ke alamat baru.

### 4.6 Kontrak

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/kontrak` | `peran:pemilik,pengelola` |
| `GET` | `/api/kontrak/{kontrak}` | `peran:pemilik,pengelola` |
| `POST` | `/api/kontrak` | `wewenang:kelola_penghuni` |
| `POST` | `/api/kontrak/{kontrak}/akhiri` | `wewenang:kelola_penghuni` |

`POST` — `{ kamar_id, penghuni_id, tgl_masuk, tarif? }`.

`tarif_awal` **wajib ada di database** tetapi klien hanya boleh mengirimnya bila
kamar **belum punya tarif** pada `tgl_masuk`. Bila kamar sudah punya, server
mengambilnya dari `PencariTarif` dan **menolak `tarif` yang berbeda** dengan
`422` pada bidang `tarif` — bukan menimpanya. Kalau klien boleh menang, akan ada
dua sumber kebenaran untuk tarif dan tidak ada yang bisa menjelaskan selisihnya.

**Penetapan tarif pertama lewat kontrak hanya untuk pemilik (REV-SEC-02).**
Sebelum R2 jalur ini menulis `tarif_sewa` dengan `create()` langsung, sehingga
pengelola yang hanya berhak *mengusulkan* tarif bisa menetapkannya sendiri cukup
dengan membuat kontrak untuk kamar yang belum bertarif — tanpa usulan, tanpa
persetujuan, dan tanpa jejak. Sekarang pengelola yang mencoba dijawab **422**
dengan `kode: 'tarif_perlu_pemilik'`; pemilik tetap boleh, dan barisnya ditulis
lewat `PenerapPerubahan` sehingga meninggalkan `log_audit` seperti perubahan
tarif lainnya.

Satu kamar hanya boleh punya satu kontrak aktif (indeks parsial). Melanggar →
`409`.

---

## 5. Tagihan dan pembayaran (16 endpoint)

### 5.1 Tagihan

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/tagihan` | `peran:pemilik,pengelola` |
| `GET` | `/api/tagihan/{tagihan}` | `auth` saja (difilter scope) |
| `POST` | `/api/tagihan` | `wewenang:terbitkan_tagihan` |
| `POST` | `/api/tagihan/terbitkan` | `wewenang:terbitkan_tagihan` |
| `POST` | `/api/tagihan/{tagihan}/batal` | `wewenang:terbitkan_tagihan` |
| `POST` | `/api/tagihan/{tagihan}/maaf-denda` | `peran:pemilik` |
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

### 5.1.1 Pembatalan tagihan (REV-FIN-02)

`POST /api/tagihan/{tagihan}/batal` — `{ alasan }` (wajib, 5–500 karakter).

Pembatalan **mengubah status, tidak menghapus baris**. Nomor tagihan yang pernah
disebut ke penghuni harus tetap bisa dijawab; yang dibebaskan hanya periodenya,
lewat index unik parsial `WHERE status <> 'dibatalkan'`. Respons **200** dengan
`TagihanResource` yang sudah berstatus `dibatalkan`.

| Keadaan | Jawaban |
|---|---|
| Tagihan sudah menerima uang | **422** — tarik pembayarannya dulu lewat baris pembalik |
| Tagihan sudah dibatalkan | **422** |
| Alasan kurang dari 5 karakter | **422** pada `alasan` |
| Periode yang dibatalkan diterbitkan ulang | **201** — ini gunanya pembatalan |
| Dua tagihan *hidup* untuk satu periode | **409** |

Gerbangnya `wewenang:terbitkan_tagihan` — kebalikan dari menerbitkan. Orang yang
boleh menerbitkan tagihan yang salah adalah orang yang harus bisa membatalkannya.

### 5.1.2 Pemaafan denda (REV-FIN-07)

`POST /api/tagihan/{tagihan}/maaf-denda` — `{ jumlah, alasan }`.

`jumlah` adalah rupiah bulat yang dimaafkan; `0` **menarik kembali** pemaafan yang
sudah ada. `alasan` wajib bila `jumlah > 0`, dan tidak diperlukan saat menarik
kembali (tidak ada uang yang dilepas).

**Gerbangnya `peran:pemilik`, bukan `wewenang:`.** Memaafkan denda berarti
melepaskan uang pemilik pada satu tagihan satu orang. Berbeda dari tarif dan
kebijakan yang perlu didelegasikan karena pemilik tidak selalu di lokasi, tidak
ada kebutuhan operasional yang menuntut pemaafan didelegasikan.

Dendanya **tidak dinolkan, tetapi dibekukan** pada hari pokoknya lunas — bukan
pada hari ini. Menolkan akan memberi hadiah kepada penghuni yang paling lama
menunda pokoknya: ia yang paling banyak berutang denda justru yang paling besar
pemaafannya. `denda` yang dibaca layar adalah nilai **neto**; `denda_ringkas`
mengirim `denda_sebelum_maaf` dan `dimaafkan` supaya layar bisa menulis "denda
Rp 450.000, dimaafkan" alih-alih angka nol tanpa penjelasan.

### 5.2 Penerbitan massal

`POST /api/tagihan/terbitkan` — `{ periode }`.

Menerbitkan untuk **semua kontrak aktif**. Kontrak yang sudah punya tagihan
periode itu dilewati, bukan digagalkan — kalau digagalkan, satu tagihan yang
sudah ada akan memblokir seluruh bulan.

```json
{ "data": [ … ], "meta": { "diterbitkan": 11, "dilewati": 1 } }
```

Setiap penghuni menerima satu pemberitahuan untuk **tagihannya sendiri** — nominal
dan jatuh temponya, bukan ringkasan batch (REV-DAT-02). Yang dilewati atau gagal
tidak dikirimi: tidak ada tagihan yang bisa disebut nominalnya. Dengan
`QUEUE_CONNECTION=sync`, 30 penghuni berarti 30 pengiriman berurutan di dalam
satu permintaan — dicatat sebagai utang teknis (DAT-03).

### 5.3 Pembayaran

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/pembayaran` | `peran:pemilik,pengelola` |
| `GET` | `/api/pembayaran/penerima` | `peran:pemilik,pengelola` |
| `GET` | `/api/pembayaran/tunai-saya` | `peran:pemilik,pengelola` |
| `POST` | `/api/pembayaran/{pembayaran}/verifikasi` | `wewenang:verifikasi_pembayaran` |
| `POST` | `/api/pembayaran/{pembayaran}/pembalik` | `wewenang:verifikasi_pembayaran` |

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

**Pengelola tidak boleh memeriksa catatannya sendiri** (REV-FIN-03). Pemilik
dikecualikan: pada kos kecil ia sering satu-satunya orang yang memegang uang, dan
melarangnya memeriksa catatannya sendiri berarti tidak ada yang bisa memeriksa
sama sekali. Penolakan dijawab **403** dengan `kode: 'verifikasi_catatan_sendiri'`.

### 5.3.1 Pembalik pembayaran (REV-FIN-01)

`POST /pembayaran/{pembayaran}/pembalik` — `{ alasan }`

Menulis **baris baru bernilai negatif** yang menunjuk baris asalnya lewat
`pembalik_id`, bukan menyunting atau menghapus baris lama. Respons:

```json
{
  "data": {
    "pembalik": { "id": 9, "jumlah": -600000, "adalah_pembalik": true, … },
    "pembayaran": { "id": 4, "sudah_dibalik": true, … },
    "status_tagihan": "belum_bayar"
  }
}
```

Kenapa baris negatif, bukan tabel pembalik tersendiri: seluruh laporan
menjumlahkan `SUM(pembayaran.jumlah)`. Dengan tanda negatif, `totalDibayar()`,
rekap, sisa piutang, dan uang tunai di tangan semuanya tetap benar **tanpa satu
pun agregat diubah** — dan agregat yang tidak perlu diubah adalah agregat yang
tidak bisa lupa diubah.

Batasan yang dijaga basis data, bukan hanya domain:

| Aturan | Penjaga | Jawaban HTTP |
|---|---|---|
| `alasan` wajib | CHECK `pembalik_ada_alasan` | 422 |
| Jumlah pembalik selalu negatif | CHECK `pembayaran_tanda_jumlah` | 422 |
| Satu pembayaran dibalik sekali | unique index `pembalik_id` | 422 |
| Pembalik tidak bisa dibalik | trigger `pembayaran_tolak_pembalik_bertingkat` | 422 |

**Pembalikan selalu penuh.** Koreksi nominal berarti balik lalu catat ulang —
dua baris yang bisa dibaca, bukan satu baris yang berubah tanpa jejak.

**Tanggal pembalikan = hari ini**, bukan tanggal pembayaran asalnya. Membalik
bukan mengubah buku bulan yang sudah ditutup. Karena itu pula demo yang memakai
`X-Simulasi-Tanggal` harus mengirim header itu pada panggilan pembalikan juga;
tanpa itu baris negatifnya jatuh di luar rentang rekap yang sedang ditampilkan.

### 5.3.2 Uang tunai milik sendiri (REV-SEC-06)

`GET /pembayaran/tunai-saya?dari=&sampai=` — keduanya opsional, bawaan bulan
berjalan.

Mengembalikan **angka saja** (`dari`, `sampai`, `jumlah`, `transaksi`,
`pembalik`, `catatan`) untuk baris tunai yang `diterima_oleh`-nya adalah
pemanggil. Tidak ada `per_metode`, tidak ada `piutang`, tidak ada nama penghuni.

Endpoint ini ada karena `/rekap` ditutup untuk pengelola: yang dibutuhkan
pekerjaannya adalah "berapa uang tunai yang masih saya pegang", bukan
"berapa pendapatan kos". Rincian transaksinya tetap tersedia di
`GET /pembayaran`, yang sengaja tetap terbuka.

### 5.4 Tunggakan, rekap, audit

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/tunggakan` | `peran:pemilik,pengelola` |
| `GET` | `/api/rekap` | `peran:pemilik` |
| `GET` | `/api/audit-log` | `wewenang:baca_audit` |

`GET /tunggakan` mengelompokkan ke `belum_jatuh_tempo` / `0_30` / `31_60` /
`lebih_60` (R-04).

**`/rekap` hanya untuk pemilik (REV-SEC-06).** Rekap adalah satu-satunya tempat
seluruh pendapatan kos terlihat sebagai satu angka; pengelola yang memegang angka
itu bisa menyimpulkan penghasilan pemiliknya tanpa pernah diminta. Batasnya
sengaja asimetris: yang ditutup agregatnya, yang dibuka rinciannya
(`GET /pembayaran`) dan uang tunai miliknya sendiri (`GET /pembayaran/tunai-saya`).

`GET /rekap?dari=&sampai=` — keduanya opsional, dan **bawaannya bulan
berjalan** (`dari` = awal bulan, `sampai` = hari ini), bukan "seluruhnya".
Rekap adalah layar yang dibuka untuk melihat "bagaimana bulan ini", dan
menuntut dua tanggal sebelum menampilkan apa pun membuat halaman kosong pada
kunjungan pertama.

Pendapatan = `SUM(pembayaran.jumlah)` pada `tanggal_pembayaran` (basis kas).
Denda yang belum dibayar dilaporkan **terpisah** sebagai
`piutang.denda_belum_dibayar` — ia bukan pendapatan sampai ada uang masuk.

**Pembalik dilaporkan terpisah dari transaksi.** `jumlah_transaksi` menghitung
baris masuk saja, dan `pembalik: { jumlah, transaksi }` menghitung baris negatif.
`total` tetap `SUM(jumlah)` sehingga angkanya bersih, tetapi keduanya bisa
dibaca sebagai dua peristiwa: menampilkan hanya angka nol akan menyembunyikan
bahwa ada uang yang pernah tercatat lalu dibatalkan.

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
| Pengelola mengajukan | `tarif.usul` / `kebijakan.usul` / `komponen.usul` | `usulan_perubahan` |
| Pemilik menyetujui | `tarif.setujui` / `kebijakan.setujui` / `komponen.setujui` | `tarif_sewa` / `kebijakan_kos` / `komponen_biaya` |
| Pemilik menolak | `tarif.tolak` / `kebijakan.tolak` / `komponen.tolak` | `usulan_perubahan` |

**Tiga tipe usulan**, daftar tertutup di `JenisUsulan`: `tarif`, `kebijakan`,
`komponen`. Ketiganya memakai `wewenang:ubah_tarif` (untuk `komponen` juga),
karena menambah wewenang baru akan diam-diam mencabut hak pengelola yang sudah
memilikinya. Menambah tipe berarti menambah cara baru menyentuh angka uang, dan
itu harus lewat kode yang memeriksanya — bukan lewat baris di tabel yang tampil
di layar tetapi tidak dipahami siapa pun.

Persetujuan yang gagal karena `23505` (baris untuk tanggal itu sudah ada)
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

## 8. Portal penghuni, pembayaran daring, dan notifikasi (10 endpoint)

| Metode | Path | Middleware |
|---|---|---|
| `GET` | `/api/penghuni/tagihan` | `peran:penghuni` |
| `GET` | `/api/penghuni/aturan` | `peran:penghuni` |
| `GET` | `/api/penghuni/rekening` | `peran:penghuni` |
| `POST` | `/api/tagihan/{tagihan}/bayar-daring` | `peran:penghuni` |

**Ketiganya harus didaftarkan sebelum `apiResource('penghuni')`.** Kalau tidak,
`GET /penghuni/tagihan` ditangkap `GET /penghuni/{penghuni}` dan "tagihan"
diperlakukan sebagai id — route model binding mencari `penghuni.id = 'tagihan'`
dan PostgreSQL menolaknya dengan `22P02`. Kelas kesalahan yang sama dengan
`/tagihan/terbitkan`.

### 8.0 Pembayaran daring (T22)

`POST /tagihan/{tagihan}/bayar-daring` — tanpa badan permintaan. Balasan:
`{ data: { redirect_url, order_id, jumlah, kedaluwarsa_pada } }`.

Endpoint ini **tidak mencatat uang**. Ia membuat tautan bayar di gateway dan
menyimpan satu baris `transaksi_gateway` berstatus `menunggu`. Uangnya baru
tercatat saat gateway memanggil webhook dan menyatakan `settlement`.

Digerbangi `peran:penghuni`, **bukan** `wewenang:catat_pembayaran`: penghuni
memang tidak berwenang mencatat pembayaran, dan memberi mereka wewenang itu
supaya tombol ini bisa dipakai akan membuka seluruh pencatatan uang. Rute ini
menjaga peran; kepemilikan baris diperiksa controller — tagihan milik orang lain
dijawab **404**, mengikuti R-07.

**Satu transaksi menunggu per tagihan.** Menekan tombol dua kali mengembalikan
tautan yang sama, bukan membuat percobaan kedua — dijaga indeks parsial
`transaksi_gateway_satu_menunggu_per_tagihan`.

### 8.0.1 Webhook gateway (T22)

`POST /api/webhook/midtrans` — `throttle:120,1`, **tanpa** `auth:sanctum` dan
tanpa `peran:`. Pemanggilnya Midtrans, bukan pengguna; tidak ada token yang bisa
dipakai.

```json
{
  "order_id": "KOS-12-1732012345",
  "status_code": "200",
  "gross_amount": "600000.00",
  "signature_key": "…",
  "transaction_status": "settlement",
  "fraud_status": "accept",
  "transaction_id": "…"
}
```

Pemeriksaan, berurutan:

| Pemeriksaan | Gagal → |
|---|---|
| `signature_key` = SHA512(`order_id` + `status_code` + `gross_amount` + server key), dibandingkan `hash_equals` | **401** tanpa penjelasan |
| `order_id` dikenal di `transaksi_gateway` | **200** (diabaikan) |
| `gross_amount` = `transaksi_gateway.jumlah` yang **dibekukan** | **200** (diabaikan) |
| status belum pernah diproses | **200** (idempoten) |

**Semua notifikasi bertanda tangan sah dijawab 200**, termasuk yang tidak
mengubah apa pun. Midtrans mengulang kiriman selama balasan bukan 2xx; menjawab
4xx untuk kasus yang memang tidak perlu ditindak akan membuatnya mengulang
selamanya. Hanya tanda tangan yang salah dijawab 401 — dan itu tanpa keterangan,
sebab keterangan akan memberi tahu penyerang bagian mana yang meleset.

**`gross_amount` dicocokkan dengan nominal yang DIMINTA**, bukan total tagihan
hari ini. Denda bisa bertambah setelah tautan dibuat; membandingkan dengan total
berjalan akan menolak pembayaran sah.

**`capture` dihitung lunas hanya bila `fraud_status = 'accept'`.** `capture`
dengan `challenge` berarti masih ditinjau — menandainya lunas berarti
memberikan barang sebelum uangnya pasti.

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
| Rekening | 4 | `/rekening*` |
| Penghuni & kontrak | 9 | `/penghuni*`, `/kontrak*` |
| Tagihan | 8 | `/tagihan*` |
| Pembayaran | 5 | `/pembayaran*` |
| Laporan | 3 | `/tunggakan`, `/rekap`, `/audit-log` |
| Usulan | 3 | `/usulan*` |
| Pengelola | 6 | `/pengelola*` |
| Portal penghuni | 4 | `/penghuni/tagihan`, `/penghuni/aturan`, `/penghuni/rekening`, `/tagihan/{id}/bayar-daring` |
| Notifikasi | 5 | `/notifikasi*`, `/pengaturan-notifikasi` |
| Infrastruktur | 2 | `/health` (§3.1), `/pemilik/saja` |
| Webhook gateway | 1 | `/webhook/midtrans` (§8.0.1) |
| **Total** | **80** | |

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
