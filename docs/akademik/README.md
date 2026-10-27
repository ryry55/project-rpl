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
| `bab4-data.js` | Sumber Bab 4: 4 tahap normalisasi, 4 gambar, 9 tabel spesifikasi, perangkat |
| `build-normalisasi.js` | Menggambar SVG empat tahap normalisasi |
| `build-bab4.js` | Menulis `Bab_4_Implementasi_dan_Pengujian.md`; gagal bila menyimpang dari skema |
| `build.js` | Menulis kedua SVG; gagal bila data tidak konsisten |
| `build-dokumen.js` | Menulis ketiga `.md`; tidak menulis apa pun bila ada masalah |
| `rute-api.json` | Potret `route:list --json` — 81 endpoint |
| `tabel-domain.json` | Potret 23 tabel domain dari `pg_attribute` |

## Membangun ulang

```powershell
node docs/akademik/build.js           # SVG
node docs/akademik/build-dokumen.js   # tiga berkas .md
powershell -ExecutionPolicy Bypass -File docs/akademik/export-png.ps1   # PNG 2x
powershell -ExecutionPolicy Bypass -File docs/akademik/export-pdf.ps1        # narasi -> PDF
powershell -ExecutionPolicy Bypass -File docs/akademik/export-diagram-pdf.ps1  # diagram -> PDF 2 hal
node docs/akademik/uji-validator.js   # buktikan pemeriksa benar-benar menolak
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

## Lampiran A3: mengapa LRS tidak ditempel di Bab 4

`Gambar 4.6` (LRS) **tidak** ditanam ke badan laporan A4, melainkan diserahkan
sebagai lampiran `docs/erd/03-L3-lrs.png` berukuran **A3 tegak**.

Sebabnya diukur, bukan dipilih. Dengan seluruh 201 kolom, tinggi huruf di kertas
pada ambang baca 2,52 mm:

| Kolom | A4 tegak | A4 miring | A3 tegak | A3 miring |
|---|---|---|---|---|
| 2 | 1,09 mm | 0,77 mm | 1,54 mm | 1,09 mm |
| 3 | 1,59 mm | 1,12 mm | 2,24 mm | 1,59 mm |
| **4** | 2,03 mm | 1,43 mm | **2,87 mm** | 2,03 mm |
| 5 | 1,72 mm | 1,71 mm | 2,43 mm | 2,42 mm |
| 6 | 1,42 mm | 2,01 mm | 2,01 mm | 2,84 mm |

Seluruh varian A4 gagal. Bila lembar A3 diskalakan ke lebar A4, hasilnya kembali
2,03 mm — jadi menempelkannya di Bab 4 hanya akan menghasilkan gambar yang tidak
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

Angka pada dokumen dibaca dari sumbernya, bukan diketik: 81 endpoint, 23 tabel,
41 migrasi, 16 aliran batas, versi Laravel dari `api/composer.lock`. Tidak ada
angka yang boleh dikarang — lihat `docs/serah-terima.md` §5 untuk cara
memeriksanya.
