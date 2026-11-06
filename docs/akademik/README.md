# Dokumen akademik

Artefak untuk template dosen: satu laporan narasi tujuh bab dan dua dokumen
gambar. Semua berkas di sini **dibangkitkan** — jangan disunting tangan, karena
suntingan akan hilang pada build berikutnya.

| Berkas | Isi |
|---|---|
| `Proses_Bisnis_Kos_Kosan.md` | Laporan narasi 7 bab (4 halaman) |
| `Proses_Bisnis_Kos_Kosan.pdf` | Versi cetak narasi, 4 halaman (Times 11pt, A4) |
| `Kos_Kosan_Diagrams_Pertemuan_4.pdf` | Dua halaman diagram, bentuk sama dengan template dosen |
| `Bab_4_Implementasi_dan_Pengujian.md` | Bab 4 format laporan Sistem Basis Data: normalisasi NF0–3NF, ERD, LRS, spesifikasi data |
| `Bab_4_Implementasi_dan_Pengujian.pdf` | Versi cetak Bab 4, 8 halaman — LRS sebagai lampiran A3 terpisah |
| `04-{1,2,3,4}nf.svg` | Gambar empat tahap normalisasi |
| `use-case-diagram.md` | Halaman 1: diagram use case, aktor, matriks, catatan kaki |
| `dfd-konteks.md` | Halaman 2: DFD Konteks Level 0 dan matriks 16 aliran |
| `01-use-case.svg` / `.png` | Diagram use case (vektor + PNG 2x) |
| `02-dfd-konteks.svg` / `.png` | DFD konteks (vektor + PNG 2x) |
| `kelas-{a,b,c}.svg` / `.png` | Diagram kelas, tiga lembar (vektor + PNG 2x) |
| `urutan-UC-01..14.svg` / `.png` | Diagram urutan, satu per use case (vektor + PNG 2x) |

**Dua format laporan, keduanya diminta.** `Proses_Bisnis_Kos_Kosan.md`
mengikuti template tujuh bab (narasi 4 halaman + dua halaman diagram).
`Bab_4_Implementasi_dan_Pengujian.md` mengikuti format laporan mata kuliah
Sistem Basis Data: normalisasi, ERD, LRS, spesifikasi data. Yang kedua
ditambahkan setelah contoh laporan dosen ditemukan; susunannya dicatat di
`docs/referensi/README.md`.

## Sumber dan penggambar

Pola yang sama dengan `docs/dfd/`: **data di satu berkas, penggambar di berkas
lain.** Diagram tangan tidak bisa diperbaiki saat angkanya berubah.

| Berkas | Peran |
|---|---|
| `usecase-data.js` | Sumber tunggal: 3 aktor, 14 use case, matriks, catatan kaki, tata letak |
| `usecase-gambar.js` | Menggambar SVG + **pemeriksa** keanggotaan aktor dari middleware |
| `dokumen-data.js` | 12 entitas data dan 7 aturan bisnis untuk bab 6–7 |
| `dfd-konteks-gambar.js` | Memanggil `docs/dfd/dfd-gambar.js`; tidak menggambar ulang DFD |
| `bab4-data.js` | Sumber Bab 4: 4 tahap normalisasi, 2 gambar (ERD + LRS), 9 tabel spesifikasi, perangkat |
| `build-normalisasi.js` | Menggambar SVG empat tahap normalisasi |
| `build-bab4.js` | Menulis `Bab_4_Implementasi_dan_Pengujian.md`; gagal bila menyimpang dari skema |
| `build.js` | Menulis kedua SVG; gagal bila data tidak konsisten |
| `build-dokumen.js` | Menulis ketiga `.md`; tidak menulis apa pun bila ada masalah |
| `kelas-data.js` | Menurunkan 22 kelas + 27 asosiasi dari `docs/erd/skema.json` dan model Eloquent |
| `kelas-gambar.js` | Menggambar tiga lembar kelas + **pemeriksa** ujung garis berimpit dan garis menembus kotak |
| `urutan-data.js` | 14 skenario: peserta, langkah, panah balik, catatan kaki |
| `urutan-gambar.js` | Menggambar SVG urutan + **pemeriksa** rute dan kelas yang benar-benar ada di `api/app` |
| `build-diagram.js` | Menulis 17 SVG; menolak menulis bila data ditolak penggambar |
| `rute-api.json` | Potret `route:list --json` - 89 endpoint |
| `tabel-domain.json` | Potret 24 tabel domain dari `pg_attribute` |

## Membangun ulang

```powershell
node docs/akademik/build.js           # SVG
node docs/akademik/build-diagram.js   # 17 SVG kelas + urutan
node docs/akademik/build-dokumen.js   # tiga berkas .md
powershell -ExecutionPolicy Bypass -File docs/akademik/export-png.ps1   # PNG 2x
powershell -ExecutionPolicy Bypass -File docs/akademik/export-pdf.ps1        # narasi -> PDF
powershell -ExecutionPolicy Bypass -File docs/akademik/export-diagram-pdf.ps1  # diagram -> PDF 2 hal
node docs/akademik/uji-validator.js   # buktikan pemeriksa benar-benar menolak
node docs/uji/cek-diagram.js          # diagram tidak basi + pagarnya bisa menolak
```

Bab 4 dibangun terpisah, dan **urutannya penting**: gambar dulu, baru markdown,
baru PDF.

```powershell
node docs/akademik/build-normalisasi.js                              # 4 SVG tahap normalisasi
node docs/akademik/build-bab4.js                                     # markdown (gagal bila SVG belum ada)
powershell -ExecutionPolicy Bypass -File docs/akademik/export-bab4-pdf.ps1   # PDF 8 halaman
```

`rute-api.json` dibuat ulang dari akar repo saat layanan `api` hidup:

```powershell
cmd /c "docker compose exec -T api php artisan route:list --json --path=api > docs\akademik\rute-api.json 2>nul"
```

Perintah itu memakai `cmd /c` dengan sengaja: pengalihan `>` milik PowerShell
menulis UTF-16LE ber-BOM, dan `JSON.parse` menolaknya.

## Diagram kelas dan diagram urutan

Dua belas gambar ini diminta dosen setelah laporan selesai, jadi bentuknya
mengikuti notasi UML seperlunya: kelas dengan atribut, tipe, dan operasi;
asosiasi dengan multiplisitas; urutan dengan garis hidup, panah panggilan, dan
panah balik bergaris putus.

**Diagram kelas dipecah tiga lembar**, pola yang sama dengan ERD L2 a/b/c: 22
kotak pada satu halaman menghasilkan huruf 1,1 mm. Tiga lembar memberi **2,61 mm**
di A3, di atas ambang 2,52 mm. Isinya tidak diketik: `kelas-data.js` membaca
`docs/erd/skema.json` (atribut, kunci, kunci asing) dan `api/app/Models/*.php`
(operasi), lalu berhenti bila ada model yang belum masuk kelompok mana pun.

**Asosiasi lintas lembar tidak digambar sebagai garis**, melainkan didaftar di
kaki gambar sebagai `Tagihan → Kontrak (kontrak_id, * : 1)`. Garis yang keluar
dari tepi kertas terbaca sebagai gambar yang terpotong, bukan sebagai hubungan.

**Satu diagram urutan per use case**, seluruhnya 14. Setiap peserta `kendali`,
`layanan`, dan `model` harus kelas yang benar-benar dideklarasikan di
`api/app`, dan setiap `endpoint` harus ada di `rute-api.json` serta memang
milik use case itu menurut `usecase-data.js`; kalau tidak, build berhenti.
Panah balik ditandai eksplisit lewat daftar `balik`, bukan ditebak dari arah —
`Pemberitahu → Basis Data` adalah panggilan INSERT yang memang mengarah ke kiri.

Lebar kanvas dijaga maksimum 1400 px supaya label 12 px tetap 2,52 mm di A3;
di atas itu hurufnya mengecil di bawah ambang dan build menolak.

## Lampiran A3: mengapa LRS tidak ditempel di Bab 4

`Gambar 4.6` (LRS) **tidak** ditanam ke badan laporan A4, melainkan diserahkan
sebagai lampiran `docs/erd/03-L3-lrs.png` berukuran **A3 tegak**.

Sebabnya diukur, bukan dipilih. Dengan seluruh 209 kolom, lembar A3 tegak
terukur **2,74 mm** — masih di atas ambang baca 2,52 mm. Tabel di bawah adalah
pengukuran bentuk (berapa kolom per lembar), bukan isi:

| Kolom | A4 tegak | A4 miring | A3 tegak | A3 miring |
|---|---|---|---|---|
| 2 | 1,06 mm | 0,75 mm | 1,50 mm | 1,06 mm |
| 3 | 1,53 mm | 1,08 mm | 2,16 mm | 1,53 mm |
| **4** | 1,94 mm | 1,37 mm | **2,74 mm** | 1,94 mm |
| 5 | 1,72 mm | 1,69 mm | 2,43 mm | 2,39 mm |
| 6 | 1,41 mm | 1,87 mm | 1,99 mm | 2,65 mm |

Seluruh varian A4 gagal. Bila lembar A3 diskalakan ke lebar A4, hasilnya kembali
1,94 mm — jadi menempelkannya di Bab 4 hanya akan menghasilkan gambar yang tidak
terbaca, padahal lampirannya terbaca. Karena itu `build-bab4.js` memakai
`tulisLampiran()`, bukan `tulisGambar()`: yang ditulis adalah keterangan gambar
beserta ukuran lembarnya, bukan `<img>` yang diperkecil.

Konsekuensinya: Bab 4 turun dari 10 menjadi 8 halaman. Penomoran gambar tidak
berubah — `Gambar 4.5` ERD dan `Gambar 4.6` LRS tetap berurutan.

## Yang dijaga build

Build gagal bila jumlah use case bukan 14, ada endpoint yang tidak ada di
`rute-api.json` atau dipakai dua use case, keanggotaan aktor tidak cocok dengan
gerbang `peran:`/`wewenang:` di `api/routes/api.php`, label melampaui elipsnya,
garis asosiasi menembus elips lain, entitas menunjuk tabel hantu, ada tabel yang
tidak dijelaskan entitas mana pun, atau aturan bisnis tidak sama dengan
`docs/dfd/dfd-data.js`.

Untuk kelas dan urutan, build juga gagal bila ada kelas yang menunjuk tabel
hantu, model yang belum masuk kelompok, dua ujung garis menempel di titik yang
sama, garis asosiasi menembus kotak, kelas atau rute karangan di diagram urutan,
langkah yang tidak kembali ke aktornya, atau huruf di bawah 2,52 mm di A3.
`node docs/uji/cek-diagram.js` membuktikan pagar-pagar itu benar-benar menolak
(masing-masing diuji dengan data yang sengaja dirusak), lalu membandingkan
ketujuh belas SVG dan PNG yang di-commit dengan hasil penggambaran data
sekarang — termasuk memastikan setiap PNG tepat 2x SVG-nya.

Angka pada dokumen dibaca dari sumbernya, bukan diketik: 89 endpoint, 24 tabel,
42 migrasi, 16 aliran batas, versi Laravel dari `api/composer.lock`. Tidak ada
angka yang boleh dikarang — lihat `docs/serah-terima.md` §5 untuk cara
memeriksanya.
