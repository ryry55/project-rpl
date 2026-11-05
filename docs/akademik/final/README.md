# Dokumen Akademik — Final (1 November 2026)

Aplikasi **Kos-Kosan** sudah jadi; berkas ini berisi dokumen akademik yang diserahkan untuk tugas akhir. Semua file adalah hasil build dari generator — jangan disunting tangan.

---

## PDF Serahan

| Berkas | Isi | Halaman |
|---|---|---|
| `Proses_Bisnis_Kos_Kosan.pdf` | Laporan narasi 7 bab, format template dosen | 4 |
| `Kos_Kosan_Diagrams_Pertemuan_4.pdf` | Use case + DFD Konteks | 2 |
| `Bab_4_Implementasi_dan_Pengujian.pdf` | Normalisasi, ERD, LRS, spesifikasi data | 8 |
| `03-L3-lrs.png` | LRS A3 tegak **terpisah** sebagai lampiran | 1 lembar A3 |

Diagram tingkat lainnya juga terpisah (bukan digabung ke PDF):
- `00-L0-konseptual.png`
- `01-L1-logikal.png`
- `02-L2-fisikal-a.png` (modul 1: Akun & tata kelola)
- `02-L2-fisikal-b.png` (modul 2: Kamar & kebijakan)
- `02-L2-fisikal-c.png` (modul 3: Uang)

Lembar LRS (`03-L3-lrs.png`) berukuran A3 tegak karena seluruh 209 kolom hanya terbaca di sana (**2,74 mm**). Di A4 terbaik hanya **1,94 mm**, di bawah ambang baca 2,52 mm.

---

## Cara Membangun Ulang

**Salinan di folder ini adalah potret, bukan sumber.** Berkas aslinya ada di
`docs/akademik/` dan `docs/erd/`. Bila dokumen dibangun ulang, salin lagi:

```powershell
Copy-Item "docs\akademik\*.pdf" "docs\akademik\final\" -Force
Copy-Item "docs\erd\*.png" "docs\akademik\final\" -Force
```

```powershell
# Gambar SVG
node docs/akademik/build.js           # use case + DFD
node docs/akademik/build-normalisasi.js                              # normalisasi NF0–3NF

# Markdown
node docs/akademik/build-dokumen.js   # tiga berkas .md
node docs/akademik/build-bab4.js      # Bab 4 dengan LRS

# Konversi ke PDF
powershell -ExecutionPolicy Bypass -File docs/akademik/export-pdf.ps1                 # narasi -> PDF
powershell -ExecutionPolicy Bypass -File docs/akademik/export-diagram-pdf.ps1         # diagram -> PDF 2 hal
powershell -ExecutionPolicy Bypass -File docs/akademik/export-bab4-pdf.ps1            # Bab 4 -> PDF 8 halaman

# Gambar PNG 2x dari SVG
powershell -ExecutionPolicy Bypass -File docs/akademik/export-png.ps1
```

Urutan penting: gambar SVG dulu, baru markdown, baru PDF.

---

## Sumber dan Penggambar

Data sumber di satu tempat, penggambar di tempat lain:

| Data | Berasal dari |
|---|---|
| Use case | `docs/akademik/usecase-data.js` |
| DFD konteks | `docs/dfd/dfd-data.js` |
| Tabel domain & aturan | `docs/akademik/dokumen-data.js`, `docs/tiket.md` |
| Skema basis data | `pg_attribute` → `docs/erd/skema.json` |
| Rute API | `php artisan route:list --json` |
| ERD/LRS | `docs/erd/build-tingkat.js` |

Generator membaca dari sumber-sumber itu; bila angka berubah (kolom bertambah, endpoint pindah), build akan gagal hingga dikoreksi.

---

## Angka yang Dijaga

Build memastikan tidak ada angka yang diketik tangan:
- **89 endpoint** (`api/routes/api.php`)
- **24 tabel**, **209 kolom**, **29 kunci asing**
- **14 use case**, **16 aliran batas**
- Tes: **830 PHP**, **19 Vitest**, **30 skrip uji** (568 detik untuk 879 langkah)
- Ukuran huruf LRS: **2,74 mm** (A3 tegak), **1,94 mm** (A4 terbaik)

Angka-angka ini dijaga oleh pemeriksa yang tertanam di generator. Lihat `docs/serah-terima.md` §5 untuk cara memeriksanya sendiri.

---

## Status Gate

CI hijau pada commit **f0f503c** (run `41276981323`). Hasil gate penuh:
- Format/lint: pint, eslint, vitest bersih
- Tes: 830 PHP / 3883 asersi, 19 Vitest / 235 asersi
- Browser: 30 skrip / 879 langkah
- Pemeriksa dokumen: konsistensi ERD/LRS, DFD, cakupan kontrak, angka dokumenn

---

## Catatan Terakhir

Dokumen ini dibangun ulang setelah audit dokumen terakhir (1 Nov 2026):
- Narasi LRS diperbaiki: "terbesar di antara yang lolos ambang", bukan "satu-satunya"
- Piramida tes disegarkan: 730 Feature, 100 Unit
- Peta ketertelusuran memuat T14 (laporan kerusakan kamar)
- Istilah "skrip browser" diganti "skrip uji": 27 browser + 2 balapan + 1 jalur proxy

Yang masih perlu Anda lakukan: latihan ucap 3× dari `docs/demo-sidang.md` sebelum sidang.
