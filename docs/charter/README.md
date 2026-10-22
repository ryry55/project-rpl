# Project Charter — Sistem Informasi Operasional Kos-Kosan

Dokumen ini adalah deliverable akademik untuk mata kuliah RPL. Isinya menjadi **sumber
primer** bagi `/to-spec` di fase berikutnya: ruang lingkup, aktor, dan aturan bisnis di sini
adalah bahan yang sama, bukan dua dokumen yang harus diselaraskan belakangan.

## Berkas

| Berkas | Isi |
|---|---|
| `Project_Charter_Kos-Kosan.docx` | Charter final, format mengikuti template jurusan |
| `build-charter.ps1` | Sumber editable — mengisi template lewat Word COM |

## Cara membangun ulang

```powershell
cd docs/charter
powershell -ExecutionPolicy Bypass -File build-charter.ps1
```

Skrip menyalin template dari `Template Laporan/` setiap kali dijalankan, jadi hasilnya selalu
bersih dan idempoten. Semua teks ada di dalam skrip; tidak ada yang perlu diedit manual di Word.

## PDF

Ekspor PDF **tidak** dilakukan oleh skrip. `ExportAsFixedFormat`, `SaveAs2` ke PDF, dan
`PrintOut` ke "Microsoft Print to PDF" semuanya menggantung di mesin ini — termasuk untuk
dokumen kosong, jadi penyebabnya bukan dokumen ini. Kalau perlu PDF:

1. Buka `Project_Charter_Kos-Kosan.docx` di Word.
2. **File → Save As → PDF**, atau **File → Print → Microsoft Print to PDF**.

Alternatif lain: unggah ke Google Docs lalu **File → Download → PDF**.

## Format

Template asli memakai **Courier New 9,5 pt**, margin 72 pt (kiri/kanan) dan 61,2 pt
(atas/bawah), ukuran Letter. Ini terlihat tidak lazim untuk laporan, tapi dipertahankan
apa adanya karena dosen membandingkan dengan contoh. Kalau Anda ingin mengganti ke Times New
Roman 12 pt, ubah `Styles("Normal")` di skrip — jangan mengubah per paragraf, karena akan
memakan waktu dan mudah terlewat.

## Catatan isi

- **Aturan bisnis R-01..R-07 disisipkan ke bagian 2 (Ruang Lingkup)**, bukan bagian terpisah.
  Alasannya: charter tidak punya slot untuk aturan bisnis, dan aturan inilah yang membedakan
  proyek ini dari CRUD biasa. Kalau dosen meminta bagian tersendiri, pindahkan blok itu.
- **Jadwal disusun per pertemuan kuliah**, dimulai 8 September 2026, dengan UTS setelah P07
  dan UAS setelah P15. Bagian 4 menampilkan rentang minggu, bukan nomor pertemuan, supaya
  tetap terbaca sebagai jadwal proyek.
- **Biaya Rp 50.000.000** mengikuti template, dengan nama kegiatan disesuaikan. Angka ini
  tidak mencerminkan biaya nyata dan tidak perlu dipertahankan kalau dosen meminta realistis.
- **Nama tim dan peran** mengikuti dokumen template. Tidak ada NIM di charter karena template
  juga tidak memuatnya; NIM biasanya masuk di lembar pengesahan laporan besar.
