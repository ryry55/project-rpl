# Dokumen serahan — Sistem Informasi Operasional Kos-Kosan

Satu subfolder per dokumen, supaya tidak perlu mencari. **Semua berkas di
sini adalah salinan**: sumber kebenarannya ada di `docs/` (dibangun
generator, dijaga pemeriksa gate). Setelah sumber dibangun ulang, jalankan:

```powershell
powershell -ExecutionPolicy Bypass -File dokumen\segarkan.ps1
```

## Isi dan status (17 November 2026)

| Subfolder | Dokumen | Berkas | Status |
|---|---|---|---|
| `01-charter` | Charter proyek | `KONTEKS_PROYEK.md` | menunggu review pemilik (angka dikoreksi audit 17 Nov) |
| `02-proses-bisnis` | Proses bisnis tujuh bab | PDF 4 halaman | segar |
| `03-use-case` | Diagram use case + serahan Pertemuan 4 (use case + DFD konteks) | PNG + PDF 2 halaman | segar |
| `04-dfd` | DFD konteks + level 1 (dua notasi) + level 2 + kamus data | 4 PNG + 2 md | level-1 tak perlu berubah (audit 17 Nov); spec REV-FIN-02 menunggu satu kalimat K-19 |
| `05-erd` | ERD berjenjang L0–L2 | 6 PNG | segar |
| `06-lrs` | LRS lembar A3 (lampiran terpisah) + versi teks | PNG A3 + md | segar |
| `07-bab-4` | Bab 4: normalisasi, ERD, LRS, spesifikasi data | PDF 8 halaman | segar |
| `08-diagram-kelas` | Diagram kelas tiga lembar | 3 PNG | segar — atribut & metode diturunkan dari skema + model, dijaga pemeriksa gate (audit 17 Nov) |
| `09-diagram-urutan` | Diagram urutan 14 use case | 14 PNG | UC-09 menunggu sinkron K-19/K-20 |
| `10-peta-ketertelusuran` | Peta aturan → kode → tes | md | menunggu baris K-19/K-20 |

Diagram normalisasi 1NF–4NF ada di dalam PDF Bab 4; sumber SVG-nya
`docs/akademik/04-*.svg`.

## Angka yang dijaga pemeriksa gate

100 endpoint · 26 tabel · 229 kolom · 30 kunci asing · 48 migrasi ·
918 tes PHP / 4142 asersi · 34 skrip uji / 942 langkah browser · huruf LRS
2,56 mm pada A3 tegak (ambang baca 2,52 mm).

Memeriksa semuanya sendiri: `powershell -ExecutionPolicy Bypass -File periksa.ps1`.

## Bangun ulang sumber (urutannya menentukan)

```powershell
# gambar SVG dulu, baru markdown, baru PDF, baru PNG 2x
node docs/akademik/build.js                    # use case + DFD konteks akademik
node docs/akademik/build-normalisasi.js       # normalisasi NF0-3NF
node docs/dfd/build.js                         # DFD konteks + level 1
node docs/erd/build-tingkat.js                 # ERD berjenjang + LRS
node docs/erd/build-lrs.js                     # 03-lrs.md
node docs/akademik/build-dokumen.js            # tiga berkas .md
node docs/akademik/build-bab4.js              # Bab 4
node docs/akademik/build-diagram.js            # kelas + urutan

powershell -ExecutionPolicy Bypass -File docs\erd\export-png.ps1
powershell -ExecutionPolicy Bypass -File docs\dfd\export-png.ps1
powershell -ExecutionPolicy Bypass -File docs\akademik\export-png.ps1
powershell -ExecutionPolicy Bypass -File docs\akademik\export-pdf.ps1
powershell -ExecutionPolicy Bypass -File docs\akademik\export-diagram-pdf.ps1
powershell -ExecutionPolicy Bypass -File docs\akademik\export-bab4-pdf.ps1

# terakhir: segarkan salinan di folder ini
powershell -ExecutionPolicy Bypass -File dokumen\segarkan.ps1
```
