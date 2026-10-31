# Format Bab 4 menurut laporan referensi

Catatan ini mencatat **susunan** Bab 4 dari laporan contoh mata kuliah Sistem
Basis Data yang diberikan dosen. Yang disimpan adalah strukturnya, bukan isi
laporannya: laporan itu milik kelompok lain dan tidak perlu ikut masuk repo.

Berkas hasilnya: `docs/akademik/Bab_4_Implementasi_dan_Pengujian.md` dan `.pdf`.

## Susunan yang diikuti

```
BAB IV  IMPLEMENTASI DAN PENGUJIAN
  4.1. Desain
       4.1.1. Basis Data
              1. Unnormalized Form (NF0)         -> Gambar 4.1
              2. First Normal Form (1NF)         -> Gambar 4.2
              3. Second Normal Form (2NF)        -> Gambar 4.3
              4. Third Normal Form (3NF)         -> Gambar 4.4
              Entity Relationship Diagram (ERD)  -> Gambar 4.5
              Logical Record Structure (LRS)     -> Gambar 4.6 (lembar A3, lampiran terpisah)
       4.1.2. Spesifikasi Data
              (satu tabel contoh per entitas)    -> Tabel 4.1 ... 4.9
  4.2. Pendukung
       4.2.1. Spesifikasi Hardware dan Software
```

## Ciri format yang perlu ditiru

1. **Normalisasi ditampilkan sebagai gambar**, bukan tabel markdown. Empat
   tahap: NF0 (data apa adanya, sel berisi banyak nilai), 1NF (satu nilai per
   sel), 2NF (ketergantungan sebagian dihilangkan), 3NF (ketergantungan
   transitif dihilangkan).
2. **ERD dan LRS melanjutkan penomoran gambar** normalisasi — 4.5 dan 4.6,
   bukan mulai dari 1 lagi.
3. **Spesifikasi Data berupa tabel berisi contoh baris**, satu tabel per
   entitas, dengan keterangan singkat di atasnya.
4. **Bagian Pendukung** memuat spesifikasi perangkat keras dan lunak, dipisah
   A dan B.

## Perbedaan yang disengaja dari laporan contoh

| Laporan contoh | Proyek ini | Alasan |
|---|---|---|
| LRS satu gambar | **Sama - LRS satu gambar**, tetapi **A3 dan diserahkan terpisah** | Seluruh 209 kolom muat dalam satu bagan pada **A3 tegak, huruf 2,74 mm** - di atas ambang 2,52 mm. Seluruh varian A4 gagal (terbaik 2,03 mm), jadi lembar A3 tidak ditempel ke badan laporan A4: bila diskalakan ke lebar A4 hasilnya kembali 2,03 mm |
| Spesifikasi Data tanpa kunci | Menyebut kunci primer dan kunci asing | Kunci asing adalah inti LRS; menghilangkannya membuat LRS dan spesifikasi tidak saling terhubung |
| ERD semua entitas | ERD 12 entitas inti | Tabel teknis (token, jejak audit, notifikasi) tidak menambah pemahaman konsep; daftarnya tetap ada di dokumen ERD |
| Data contoh dikarang | Data contoh konsisten dengan skema nyata | Nama kolom dan tipenya diambil dari `docs/erd/skema.json` |

## Cara membangun ulang

```powershell
node docs/akademik/build-normalisasi.js                              # SVG tahap normalisasi
node docs/akademik/build-bab4.js                                     # markdown
powershell -ExecutionPolicy Bypass -File docs/akademik/export-bab4-pdf.ps1   # PDF
```

`build-bab4.js` **gagal** bila ada tabel yang disebut tetapi tidak ada di
`skema.json`, ada tabel skema yang tidak masuk kelompok mana pun, ada kolom
spesifikasi yang tidak nyata, baris contoh tidak sepanjang kepala kolomnya,
atau gambar yang dirujuk belum dibuat.
