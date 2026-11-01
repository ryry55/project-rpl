# ERD berjenjang dan LRS — Sistem Informasi Operasional Kos-Kosan

Delapan gambar, satu dokumen teks, satu sumber: **basis data yang berjalan**.

| Berkas | Tingkat | Isi |
|---|---|---|
| `00-L0-konseptual.svg` / `.png` | 0 — konseptual | 11 entitas inti, relasi, **tanpa atribut** |
| `01-L1-logikal.svg` / `.png` | 1 — logikal | 12 entitas, relasi + atribut kunci |
| `02-L2-fisikal-{a,b,c}.svg` / `.png` | 2 — fisikal | 24 tabel, **seluruh kolom dan tipenya** |
| `03-L3-lrs.svg` / `.png` | 3 — LRS | 24 tabel, **seluruh 209 kolom**, satu lembar **A3** |
| `03-lrs.md` | 3 — LRS | LRS lengkap sebagai teks: 209 kolom, 29 kunci asing |
| `skema.json` | — | Potret skema dari basis data; sumber semua berkas di atas |

## Kenapa berjenjang, bukan satu gambar

ERD yang menggambar 24 tabel dan 209 kolom sekaligus **tidak bisa dibaca di
A4**, dan itu bukan soal resolusi. Sudah diukur: pada satu lembar A4 berisi
seluruh tabel, tinggi hurufnya hanya **1,94 mm** — di bawah ambang 2,52 mm.
Menambah skala ekspor tidak menolong sama sekali, karena yang menentukan adalah
nisbah huruf terhadap ukuran lembar, dan nisbah itu tidak berubah oleh jumlah
piksel.

Yang perlu dicatat: **kegagalan itu bukan karena jumlah tabelnya, melainkan
karena jumlah kolomnya.** Karena itu pemecahan lembar pada tingkat 2 ditentukan
oleh pengukuran, bukan ditebak. Lihat `pecahTerbaca` di `build-tingkat.js`.

## LRS: satu lembar A3, seluruh 209 kolom

LRS digambar **satu lembar berisi seluruh 209 kolom**, dan itu hanya terbaca
bila dicetak di **A3 tegak**. Ini hasil pengukuran, bukan pilihan selera. Pada
ambang baca 2,52 mm (setara 1,2% lebar A4), dengan seluruh kolom:

| Kolom | A4 tegak | A4 miring | A3 tegak | A3 miring |
|---|---|---|---|---|
| 2 | 1,06 mm | 0,75 mm | 1,50 mm | 1,06 mm |
| 3 | 1,53 mm | 1,08 mm | 2,16 mm | 1,53 mm |
| **4** | 1,94 mm | 1,37 mm | **2,74 mm** | 1,94 mm |
| 5 | 1,72 mm | 1,69 mm | 2,43 mm | 2,39 mm |
| 6 | 1,41 mm | 1,87 mm | 1,99 mm | 2,65 mm |

**Seluruh varian A4 gagal**; yang terbaik 1,94 mm. Pada A3 tegak dengan 4 kolom
hasilnya **2,74 mm** — satu-satunya bentuk yang memuat semua atribut tanpa
mengorbankan keterbacaan.

Karena itu lembar LRS **diserahkan terpisah sebagai lampiran A3**, tidak ditempel
ke badan laporan A4. Bila A3 yang sama diskalakan ke lebar A4, hasilnya kembali
persis 1,94 mm — sama dengan menggambarnya di A4 sejak awal.

Satu konsekuensi: LRS **tidak memakai label kardinalitas `1`/`N`**. Angka itu
milik notasi Chen (tingkat 0 dan 1); pada LRS hubungan dinyatakan oleh kunci
asing bertanda bintang, dan arahnya oleh kepala panah. Pada lembar ini `users`
menerima sembilan kunci asing — delapan belas label di sekelilingnya tidak
menambah informasi dan sebagian tidak mendapat tempat sama sekali.

Pengukuran keterbacaan memakai **milimeter di kertas**, bukan persen lebar A4.
Pemeriksanya menerima parameter `kertas` (`KERTAS`, `AMBANG_MM`, `hurufMm` di
`papan.js`). Sebelumnya `RASIO_A4` dipatok mati, sehingga setiap lembar diukur
seolah-olah akan dicetak A4 — dan lembar yang dirancang untuk A3 selalu ditolak.

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
node docs/erd/build-tingkat.js     # bangun L0, L1, L2 (3 lembar), L3 (1 lembar A3)
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
7. **tinggi huruf di kertas** di bawah ambang keterbacaan;
8. baris legenda yang keluar dari kotaknya atau terpotong di tepi kanan;
9. tabel basis data yang tidak muncul di tingkat mana pun, atau tidak tercatat
   sebagai dikecualikan beserta alasannya;
10. tabel yang muncul dua kali di lembar yang sama;
11. kepala panah yang tercetak di dalam kotak tabel;
12. dua kepala panah yang bertumpuk di sisi kotak yang sama.

Butir 7 yang paling sering menyelamatkan gambar. Ukurannya bukan "huruf dibagi
lebar kanvas" — cara itu keliru, karena menganggap gambar selalu dipaskan
selebar halaman, sehingga lembar tinggi-sempit dinilai jauh lebih baik daripada
kenyataannya. Yang dihitung, dalam **milimeter tinggi huruf di kertas**:

```
hurufMm = ukuran_huruf × min(lebar_kertas/lebar_kanvas, tinggi_kertas/tinggi_kanvas)
hurufMm ≥ 2,52 mm
```

`KERTAS` (A4 210×297, A3 297×420) dan `AMBANG_MM` ada di `papan.js`, dan
`gambarPapan` menerima `definisi.kertas`. Ambang 2,52 mm sama persis dengan 1,2%
lebar A4 yang dipakai sebelumnya — jadi ambangnya tidak dilonggarkan, hanya
satuannya diganti menjadi satuan yang bisa dibayangkan.

`NISBAH_MIN` kini **diturunkan** dari `AMBANG_MM / 210`, bukan ditulis ulang;
kalau ambang milimeter berubah, angka persennya ikut berubah.

Butir 11 dan 12 lahir dari cacat yang lolos meski build menyatakan LULUS, dan
hanya ketahuan dari melihat PNG-nya: tiga kepala panah tercetak di atas judul
`users`, dan dua kepala panah di tepi kanannya saling menimpa. Penyebabnya satu:
perutean melewati kotak anak dan induk sebagai penghalang. Pemeriksa lama
memakai kekecualian yang sama, jadi ia tidak mungkin melihatnya.

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

### Tingkat 0 dan 1 tidak memuat seluruh 24 tabel

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
keterangan di `03-lrs.md` §4. Di lembar LRS, ketujuh kunci asing itu **memang
digambar** — di sana `users` bukan kotak yang harus dijelaskan konsepnya,
melainkan tabel yang dituju panah, dan sembilan panah yang berlabuh di titik
berbeda sepanjang sisinya masih terbaca.

### Atribut di tingkat 0

Tingkat 0 sengaja tanpa atribut sama sekali: `kunci: []` membuat `chen.js` tidak
menggambar elips. Tingkat 1 menggambar kunci primer dan satu atribut pembeda.
Seluruh 209 kolom ada di tingkat 2, di lembar LRS A3, dan di `03-lrs.md`.

Menggambar 209 kolom sebagai elips mustahil dibaca, dan itu bukan penghematan —
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
- garis ortogonal antar kotak — hubungan kunci asing;
- panah di ujung garis menunjuk tabel induk (hanya tingkat 3);
- label `1` / `N` hanya di tingkat 2, tidak di LRS.

Kenapa begitu: LRS adalah notasi **relasional** — hubungan dinyatakan oleh kunci
asing yang menunjuk tabel induk, bukan oleh angka kardinalitas. Angka `1`/`N`
milik notasi Chen. Pada satu lembar berisi 24 tabel, `users` menerima sembilan
panah; menambahkan delapan belas label di sekelilingnya memenuhi gambar dengan
angka yang tidak menambah informasi, dan sebagian tidak mendapat tempat sama
sekali. Arah hubungan karena itu dibawa oleh **panah**, bukan angka.

Sembilan panah yang menuju `users` berlabuh di titik yang berbeda-beda supaya
tidak menumpuk menjadi satu kepala panah. Titik labuhnya dipilih oleh perutean
sendiri: setiap sisi kotak menawarkan beberapa titik, dan titik yang sudah
terpakai dihukum — jadi penyebarannya muncul dari pengukuran, bukan dari angka
yang ditebak.

Jarak minimum antar titik labuh **diturunkan dari ukuran kepala panah**
(`lebarKepalaPanah() * 2 * 1,25`), bukan diketik terpisah. Sebelumnya kedua
angka itu ditulis sendiri-sendiri, dan hasilnya jarak minimum 16,1 px untuk
panah setinggi 20,9 px — dua panah yang "cukup jauh" menurut perutean tetap
saling menimpa saat digambar.

Dua pagar menjaga lembar LRS, karena keduanya pernah lolos:

1. **Perutean menghormati kotak induk dan anak.** Dulu keduanya dilewati, jadi
   Dijkstra bisa berjalan menembus badan kotak induk lalu keluar lewat tepi
   atasnya; garisnya tampak seperti panah yang menusuk judul tabel. Sekarang
   kedua kotak tetap penghalang, dengan satu "mulut" kecil di titik labuh.
2. **`docs/uji/cek-erd-lrs.js` memeriksa SVG hasil jadi**: kepala panah tidak
   boleh berada di dalam kotak mana pun, dua kepala panah pada sisi yang sama
   tidak boleh bertumpuk, dan tidak boleh ada dua garis sejajar yang berimpit.
   Pemeriksa lama memakai kekecualian yang sama dengan perutean — sehingga ia
   buta terhadap cacat yang justru paling merusak.

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
