# ERD dan LRS — Sistem Informasi Operasional Kos-Kosan

Dua dokumen, satu sumber: **basis data yang berjalan**.

| Berkas | Isi |
|---|---|
| `01-erd-inti.svg` / `.png` | ERD notasi Chen, 7 entitas alur uang — untuk dijelaskan saat sidang |
| `02-erd-lengkap.svg` / `.png` | ERD notasi Chen, seluruh 23 tabel domain — untuk lampiran |
| `03-lrs.md` | LRS lengkap: 23 tabel, 201 kolom, 26 kunci asing |
| `skema.json` | Potret skema dari basis data; sumber kedua dokumen di atas |

## Kenapa dibangun, bukan digambar tangan

ERD dan LRS yang digambar tangan akan menyimpang dari basis data, dan
penyimpangan itu tidak akan ketahuan sampai ada yang membandingkan keduanya —
biasanya dosen atau pemilik kos. Dua puluh tiga tabel dengan 201 kolom terlalu
banyak untuk diketik ulang dengan benar; kolom yang lupa diketik tidak
menimbulkan galat di mana pun, ia hanya muncul sebagai gambar yang salah.

Karena itu relasi, kardinalitas, dan partisipasi **diturunkan** dari kunci asing:

| Yang digambar | Ditentukan oleh |
|---|---|
| arah relasi | arah kunci asing |
| kardinalitas sisi anak `N` atau `1` | ada tidaknya indeks unik pada kolom kunci asing |
| partisipasi wajib (garis ganda) | kolom `NOT NULL` |
| daftar kolom di LRS | `information_schema.columns` |

Yang ditulis tangan hanya dua hal: **tata letak** (`erd-data.js`) dan **bahasa**
(`lrs-data.js` — kata kerja relasi, makna tabel).

## Cara membangun ulang

```bash
node docs/erd/ambil-skema.js     # ambil skema dari basis data (hanya bila skema berubah)
node docs/erd/build.js           # bangun 01-erd-inti.svg dan 02-erd-lengkap.svg
node docs/erd/build-lrs.js       # bangun 03-lrs.md
powershell -ExecutionPolicy Bypass -File docs/erd/export-png.ps1   # SVG -> PNG (2x)
```

`ambil-skema.js` memerlukan container `db` hidup. Dua perintah build tidak.

## Penjaga

`node docs/uji/cek-erd-lrs.js` dijalankan di kedua gerbang mutu
(`periksa.ps1` dan `periksa.sh`). Ia gagal bila:

- `skema.json` tidak lagi cocok dengan basis data (ada migrasi baru);
- ada tabel di basis data yang tidak punya bagian di LRS;
- ada kunci asing yang tidak muncul di tabel relasi LRS;
- jumlah tabel atau kunci asing yang ditulis di dokumen tidak sama dengan yang dihitung;
- berkas SVG hasil build tidak ada atau kosong.

`build.js` sendiri gagal bila ada kunci asing yang tidak digambar **dan** tidak
terdaftar di `tidak_digambar` beserta alasannya, atau bila ada simbol yang
saling menimpa. Kedua pemeriksa sudah dibuktikan bisa gagal — bukan hanya
diklaim bisa.

## Yang sengaja tidak digambar

### Tujuh kunci asing pelaku

`pembayaran.dicatat_oleh`, `pembayaran.diterima_oleh`, `tagihan.dibatalkan_oleh`,
`tagihan.denda_dimaafkan_oleh`, `verifikasi_pembayaran.diverifikasi_oleh`,
`usulan_perubahan.diputuskan_oleh`, dan `users.pemilik_id` semuanya merujuk ke
`users` sebagai **pelaku**, bukan sebagai bagian struktur data. Menggambarnya
akan menarik tujuh garis panjang ke satu kotak yang sama, dan hasilnya bukan
diagram yang lebih lengkap melainkan diagram yang tidak terbaca.

Kolomnya tetap ada, tetap tercatat di LRS §5, dan tetap punya keterangan di
LRS §4. Tidak ada yang disembunyikan — hanya dipindahkan ke tempat yang bisa
dibaca.

### Empat tabel tanpa relasi

`aturan_kos`, `log_audit`, `notifications`, dan `rekening` tidak punya kunci
asing keluar maupun masuk. Ini bukan kelalaian: isinya memang tidak terikat
pada baris tabel lain. Legenda menyebutkannya, dan daftarnya **dihitung** dari
skema — tabel baru yang berdiri sendiri otomatis ikut disebut.

### Atribut di ERD lengkap

ERD Chen yang ideal menggambar setiap kolom sebagai elips. Untuk 201 kolom itu
menghasilkan gambar yang mustahil dibaca. Karena itu:

- **ERD inti** menggambar atribut kunci sebagai elips;
- **ERD lengkap** menggambar relasi saja, dengan alasan yang sama;
- **LRS** memuat seluruh 201 kolom beserta tipe dan kuncinya.

Pembagian ini disengaja: ERD menjawab "bagaimana datanya berhubungan", LRS
menjawab "kolomnya apa saja". Menggambar keduanya di satu diagram akan membuat
keduanya tidak terbaca.

## Catatan notasi

Notasi Chen dipilih karena itu yang lazim diminta di mata kuliah Indonesia.
Materi kuliah P01–P15 proyek ini **tidak** mengajarkan ERD maupun LRS, sehingga
bentuknya mengikuti kebiasaan akademik, bukan slide.

Konvensi yang dipakai:

- entitas — persegi panjang;
- relasi — belah ketupat berisi kata kerja;
- atribut — elips, kunci primer digaris bawahi;
- `1` / `N` — kardinalitas, ditulis di dekat ujung garis;
- garis ganda — partisipasi wajib (kolom `NOT NULL`).

Untuk LRS, bentuknya mengikuti notasi relasional formal:

```
nama_tabel = (kolom_pk, kolom_biasa, kolom_fk*)
```

dengan kunci primer digaris bawahi dan kunci asing bertanda bintang.

## Berkas

| Berkas | Peran |
|---|---|
| `ambil-skema.js` | membaca basis data → `skema.json` |
| `erd-data.js` | tata letak entitas + kata kerja relasi |
| `erd-gambar.js` | penyaji Chen + enam pemeriksa tata letak |
| `build.js` | menulis kedua SVG |
| `lrs-data.js` | makna tabel dan kolom + urutan penyajian |
| `build-lrs.js` | menulis `03-lrs.md` |
| `export-png.ps1` | SVG → PNG 2x memakai Chrome/Edge headless |
| `../uji/cek-erd-lrs.js` | penjaga agar dokumen tidak basi |
