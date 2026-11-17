# Dokumen Akademik — Final (17 November 2026)

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
- `02-L2-fisikal-b1.png` (modul 2: Kamar, tarif & kebijakan)
- `02-L2-fisikal-b2.png` (modul 2 lanjutan: Kerusakan & fotonya)
- `02-L2-fisikal-c.png` (modul 3: Uang)

Lembar LRS (`03-L3-lrs.png`) berukuran A3 tegak karena seluruh 229 kolom hanya terbaca di sana (**2,56 mm**). Di A4 terbaik hanya **1,81 mm**, di bawah ambang baca 2,52 mm.

---

## Diagram Kelas dan Diagram Urutan

Diminta dosen setelah laporan selesai; **tidak disalin ke folder ini** supaya
tidak ada dua salinan yang bisa berselisih. Berkasnya ada di `docs/akademik/`
dan siap ditempel (SVG + PNG 2x):

| Berkas | Isi | Huruf di A3 |
|---|---|---|
| `kelas-a.png` | Kelas: akun, akses & kepatuhan (6 kelas) | 2,61 mm |
| `kelas-b.png` | Kelas: kamar, sewa & kebijakan (7 kelas) | 2,61 mm |
| `kelas-c.png` | Kelas: tagihan, pembayaran & catatan (9 kelas) | 2,61 mm |
| `urutan-UC-01..14.png` | Satu diagram urutan per use case, 14 lembar | 2,58–4,46 mm |

Diagram kelas dipecah tiga lembar dengan alasan yang sama seperti ERD L2 a/b1/b2/c:
23 kotak di satu halaman hanya terbaca **1,1 mm**. Asosiasi yang menyeberang
lembar didaftar di kaki gambar, bukan digambar sebagai garis yang menggantung di
tepi kertas.

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
- **100 endpoint** (`api/routes/api.php`)
- **26 tabel**, **229 kolom**, **30 kunci asing**
- **14 use case**, **16 aliran batas**
- Tes: **918 PHP**, **19 Vitest**, **34 skrip uji** (942 langkah)
- Ukuran huruf LRS: **2,56 mm** (A3 tegak), **1,81 mm** (A4 terbaik)

Angka-angka ini dijaga oleh pemeriksa yang tertanam di generator. Lihat `docs/serah-terima.md` §5 untuk cara memeriksanya sendiri.

---

## Status Gate

CI terakhir hijau pada commit **f0f503c** (run `41276981323`). Angka gate penuh
terakhir, dijalankan lokal pada 17 November 2026 (**SEMUA LULUS**, 693 detik):
- Format/lint: pint, eslint, vitest bersih
- Tes: 918 PHP / 4142 asersi, 19 Vitest / 235 asersi
- Browser: 34 skrip / 942 langkah
- Pemeriksa dokumen: konsistensi ERD/LRS, DFD, cakupan kontrak, angka dokumen

---

## Catatan Terakhir

Dokumen ini dibangun ulang setelah Fase 8 (integritas jalur uang, 17 Nov 2026):
- Kolom 228 → 229: `transaksi_gateway.total_beku` (K-20); migrasi 47 → 48
- Tinggi huruf LRS 2,54 → 2,56 mm setelah celah antar kotak diketatkan
  (86 → 78 px); ambang baca 2,52 mm tidak berubah
- Tes 912 → 918 PHP / 4142 asersi (enam tes jalur uang Fase 8, merah dulu)
- Lembar kelas c kini memuat `total_beku`; lembar fisikal b1/b2 menggantikan b

Yang masih perlu Anda lakukan: latihan ucap 3× dari `docs/demo-sidang.md` sebelum sidang.
