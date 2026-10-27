# ERD berjenjang dan LRS — Sistem Informasi Operasional Kos-Kosan

Delapan gambar, satu dokumen teks, satu sumber: **basis data yang berjalan**.

| Berkas | Tingkat | Isi |
|---|---|---|
| `00-L0-konseptual.svg` / `.png` | 0 — konseptual | 11 entitas inti, relasi, **tanpa atribut** |
| `01-L1-logikal.svg` / `.png` | 1 — logikal | 12 entitas, relasi + atribut kunci |
| `02-L2-fisikal-{a,b,c}.svg` / `.png` | 2 — fisikal | 23 tabel, **seluruh kolom dan tipenya** |
| `03-L3-lrs-{a,b,c}.svg` / `.png` | 3 — LRS | 23 tabel, notasi relasional |
| `03-lrs.md` | 3 — LRS | LRS lengkap sebagai teks: 201 kolom, 26 kunci asing |
| `skema.json` | — | Potret skema dari basis data; sumber semua berkas di atas |

## Kenapa berjenjang, bukan satu gambar

ERD yang menggambar 23 tabel dan 201 kolom sekaligus **tidak bisa dibaca**, dan
itu bukan soal resolusi. Sudah diukur: pada satu lembar berisi seluruh tabel,
huruf efektif setelah gambar dipaskan ke satu halaman A4 hanya **0,52% lebar
halaman** — sekitar 1 mm. Mustahil. Menambah skala ekspor tidak menolong sama
sekali, karena yang menentukan adalah nisbah huruf terhadap ukuran lembar, dan
nisbah itu tidak berubah oleh jumlah piksel.

Karena itu satu tingkat tidak sama dengan satu gambar. Yang dipecah bukan
tingkatnya, melainkan **lembar** pada tingkat 2 dan 3 — dan jumlah lembarnya
ditentukan oleh pengukuran, bukan ditebak. Lihat `pecahTerbaca` di
`build-tingkat.js`.

Empat tingkat itu sendiri menjawab empat pertanyaan berbeda:

| Tingkat | Menjawab | Yang digambar |
|---|---|---|
| 0 konseptual | benda apa saja yang terlibat? | kotak + belah ketupat, tanpa atribut |
| 1 logikal | bagaimana hubungannya setelah kunci asing diselesaikan? | + elips atribut kunci |
| 2 fisikal | kolom dan tipe apa saja yang benar-benar ada? | kotak tabel berisi seluruh kolom |
| 3 LRS | bagaimana bentuk relasional formalnya? | PK digaris bawahi, FK bertanda bintang |

## Kenapa dibangun, bukan digambar tangan

ERD dan LRS yang digambar tangan akan menyimpang dari basis data, dan
penyimpangan itu tidak ketahuan sampai ada yang membandingkannya — biasanya
dosen atau pemilik kos. Kolom yang lupa diketik tidak menimbulkan galat di mana
pun; ia hanya muncul sebagai gambar yang salah, dan gambar yang salah terlihat
persis seperti gambar yang benar.

Karena itu relasi, kardinalitas, partisipasi, dan daftar kolom **diturunkan**
dari kunci asing:

| Yang digambar | Ditentukan oleh |
|---|---|
| arah relasi | arah kunci asing |
| kardinalitas sisi anak `N` atau `1` | ada tidaknya indeks unik tunggal pada kolom kunci asing |
| partisipasi wajib (garis ganda) | kolom `NOT NULL` |
| daftar kolom dan tipe | `information_schema.columns` |
| kunci asing lintas lembar | dihitung dari skema, bukan didaftarkan tangan |

Yang ditulis tangan hanya tiga hal: **tata letak** dan **kata kerja relasi**
(`tingkat-data.js`), serta **makna tabel dan kolom** (`lrs-data.js`).

## Cara membangun ulang

```bash
node docs/erd/ambil-skema.js       # ambil skema dari basis data (hanya bila skema berubah)
node docs/erd/build-tingkat.js     # bangun L0, L1, L2 (3 lembar), L3 (3 lembar)
node docs/erd/build-lrs.js         # bangun 03-lrs.md
powershell -ExecutionPolicy Bypass -File docs/erd/export-png.ps1   # SVG -> PNG (2x)
```

`ambil-skema.js` memerlukan container `db` hidup. Tiga perintah lainnya tidak.

## Penjaga

### Di dalam build

`build-tingkat.js` **keluar dengan kode 1** bila ada satu saja dari ini:

1. kunci asing yang tidak digambar dan tidak tercatat di `tidak_digambar`
   beserta alasannya;
2. relasi yang digambar tetapi tidak ada di basis data;
3. simbol yang saling menimpa (kotak, elips, belah ketupat, **label
   kardinalitas**);
4. garis relasi yang menembus kotak entitas atau memotong elips atribut;
5. dua relasi yang tergambar sebagai **satu garis** karena jalurnya berimpit;
6. label kardinalitas yang bertumpuk, menempel garis, atau melayang jauh dari
   garisnya;
7. **huruf efektif** di bawah ambang keterbacaan;
8. baris legenda yang keluar dari kotaknya atau terpotong di tepi kanan;
9. tabel basis data yang tidak muncul di tingkat mana pun, atau tidak tercatat
   sebagai dikecualikan beserta alasannya;
10. tabel yang muncul dua kali di lembar yang sama.

Butir 7 yang paling sering menyelamatkan gambar. Ukurannya bukan "huruf dibagi
lebar kanvas" — cara itu keliru, karena menganggap gambar selalu dipaskan
selebar halaman, sehingga lembar tinggi-sempit dinilai jauh lebih baik daripada
kenyataannya. Yang dihitung:

```
hurufEfektif = ukuran_huruf × min(1/lebar, 1,4142/tinggi)   ≥ 0,012
```

yaitu ukuran huruf setelah gambar dipaskan ke satu halaman A4 potret — mana yang
lebih dulu menyentuh tepi, lebar atau tinggi. Ambang 1,2% lebar halaman setara
±6 pt.

Butir 5 dan 6 lahir dari cacat yang benar-benar lolos ke gambar. Dua kunci asing
antara pasangan tabel yang sama (`usulan_perubahan.diajukan_oleh` dan
`diputuskan_oleh`, keduanya ke `users`) dulu menghasilkan jalur identik dan
tergambar sebagai satu garis; label kardinalitasnya menumpuk jadi satu glyph.
Perutean sekarang menghukum jalur yang berimpit, dan label dipilih dari calon
di sepanjang garisnya sendiri. Bila benar-benar tidak ada tempat, build
**gagal** — bukan menaruh label asal-asalan.

### Di gerbang mutu

`node docs/uji/cek-erd-lrs.js` dijalankan di `periksa.ps1`, `periksa.sh`, dan CI.
Ia memeriksa hal yang tidak bisa diperiksa oleh build: bahwa `skema.json` masih
cocok dengan basis data, bahwa setiap tabel dan kunci asing muncul di LRS, bahwa
angka di dokumen sama dengan hasil hitung, bahwa **setiap SVG berjenjang ada
dan tidak kosong**, dan bahwa **PNG pasangannya berukuran tepat 2x SVG-nya**.

Pemeriksaan PNG itu bukan kehati-hatian berlebihan. SVG dan PNG bisa berbeda
tanpa ada yang gagal: SVG disunting lalu dibangun ulang, tetapi PNG lupa
diekspor ulang — gambar yang benar di repo, gambar lama di laporan. PNG lama
terlihat persis seperti PNG yang benar. Tangkapan layar Chrome yang terpotong
juga lolos tanpa pemeriksaan ini, karena berkasnya tetap terbentuk.

## Pembagian lembar tingkat 2 dan 3

Tiga lembar, dipotong menurut alur data supaya setiap lembarnya satu rantai yang
saling terhubung — bukan satu topik yang kebetulan mirip:

| Lembar | Nama | Tabel |
|---|---|---|
| a | Akun & Tata Kelola | 9 |
| b | Kamar, Tarif & Kebijakan | 8 |
| c | Kontrak, Tagihan & Pembayaran | 6 |

`users` sengaja ditaruh bersama tabel-tabel yang menunjuknya, supaya garisnya
bisa ditarik. `rekening` tidak punya kunci asing ke mana pun, jadi ia bebas
ditempatkan dan dipakai untuk menyeimbangkan lembar.

Kunci asing yang tetap menyeberang lembar **tidak bisa ditarik garisnya** —
tabel tujuannya ada di lembar lain. Karena itu ia dihitung dari skema dan
ditulis di legenda tiap lembar. Daftar yang diketik tangan akan basi, dan daftar
yang basi tidak memberi tanda apa pun.

## Yang sengaja tidak digambar

### Tingkat 0 dan 1 tidak memuat seluruh 23 tabel

ERD konseptual menjawab "benda apa saja yang terlibat", bukan "tabel apa saja
yang ada". Tabel teknis seperti `token_akun`, `notifications`, dan `log_audit`
tidak punya makna bagi pemilik kos — ia lahir dari cara sistem dibuat, bukan
dari cara kos dijalankan. Menjejalkannya ke tingkat konseptual membuat diagram
yang seharusnya bisa dijelaskan dalam satu menit menjadi tidak terbaca.

L0 memuat 11 entitas dan mengecualikan 12 tabel; L1 memuat 12 entitas (bertambah
`kamar_fasilitas`) dan mengecualikan 11. Semuanya tercatat di `dikecualikan`
beserta `alasan_dikecualikan`, dan build gagal bila ada tabel yang tidak
digambar **dan** tidak tercatat.

### Perbedaan L0 dan L1 yang disengaja

`kamar_fasilitas` adalah tabel penghubung. Di basis data ia ada, tetapi secara
konseptual ia bukan entitas — ia hanya cara menyimpan hubungan banyak-ke-banyak
antara kamar dan fasilitas. Karena itu:

- **L0** menggambar `kamar —memiliki— fasilitas` sebagai satu relasi `N:N`;
- **L1** menggambar `kamar_fasilitas` sebagai entitas, karena pada tingkat
  logikal hubungan `N:N` memang harus sudah diselesaikan.

Itu bukan ketidakkonsistenan, melainkan proses konseptual → logikal yang memang
diminta. Build menolak bila tabel penghubung itu ikut digambar di tingkat yang
sama — sebab hubungannya akan tergambar dua kali.

### Tujuh kunci asing pelaku

`pembayaran.dicatat_oleh`, `pembayaran.diterima_oleh`, `tagihan.dibatalkan_oleh`,
`tagihan.denda_dimaafkan_oleh`, `verifikasi_pembayaran.diverifikasi_oleh`,
`usulan_perubahan.diputuskan_oleh`, dan `users.pemilik_id` semuanya merujuk ke
`users` sebagai **pelaku**, bukan sebagai bagian struktur data. Menggambarnya
akan menarik tujuh garis panjang ke satu kotak yang sama, dan hasilnya bukan
diagram yang lebih lengkap melainkan diagram yang tidak terbaca.

Kolomnya tetap ada, tetap tergambar di tingkat 2 sebagai `FK`, dan tetap punya
keterangan di `03-lrs.md` §4.

### Atribut di tingkat 0

Tingkat 0 sengaja tanpa atribut sama sekali: `kunci: []` membuat `chen.js` tidak
menggambar elips. Tingkat 1 menggambar kunci primer dan satu atribut pembeda.
Seluruh 201 kolom ada di tingkat 2 dan di `03-lrs.md`.

Menggambar 201 kolom sebagai elips mustahil dibaca, dan itu bukan penghematan —
ERD Chen yang baik memang tidak menampilkan seluruh kolom.

## Catatan notasi

Notasi Chen dipilih karena itu yang lazim diminta di mata kuliah Indonesia.
Materi kuliah P01–P15 proyek ini **tidak** mengajarkan ERD maupun LRS, sehingga
bentuknya mengikuti kebiasaan akademik, bukan slide.

Konvensi tingkat 0–1:

- entitas — persegi panjang;
- relasi — belah ketupat berisi kata kerja;
- atribut — elips, kunci primer digaris bawahi;
- `1` / `N` — kardinalitas, ditulis di lorong dekat ujung garis;
- garis ganda — partisipasi wajib (kolom `NOT NULL`).

Konvensi tingkat 2–3 (kotak tabel):

- kepala gelap — nama tabel;
- `PK` / `FK` / `UQ` — kunci primer, kunci asing, unik;
- tipe kolom di kanan (hanya tingkat 2);
- garis ortogonal antar kotak — hubungan kunci asing.

Untuk LRS, bentuk teks formalnya:

```
nama_tabel = (kolom_pk, kolom_biasa, kolom_fk*)
```

dengan kunci primer digaris bawahi dan kunci asing bertanda bintang.

## Berkas

| Berkas | Peran |
|---|---|
| `ambil-skema.js` | membaca basis data → `skema.json` |
| `tingkat-data.js` | L0, L1, tiga modul, plus relasi konseptual |
| `chen.js` | penyaji notasi Chen untuk L0/L1 + pemeriksa tata letak |
| `papan.js` | penyaji kotak tabel untuk L2/L3 + perutean ortogonal |
| `build-tingkat.js` | menulis seluruh SVG berjenjang; mengukur dan memecah lembar |
| `lrs-data.js` | makna tabel dan kolom + urutan penyajian |
| `build-lrs.js` | menulis `03-lrs.md` |
| `export-png.ps1` | SVG → PNG 2x memakai Chrome/Edge headless |
| `../uji/cek-erd-lrs.js` | penjaga agar dokumen tidak basi |
