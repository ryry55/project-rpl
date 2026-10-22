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

Skrip **mengekspor PDF sendiri** lewat `ExportAsFixedFormat`, menghasilkan
`Project_Charter_Kos-Kosan.pdf` di samping DOCX.

Catatan lama di berkas ini menyebut ekspor selalu menggantung. Itu keliru: yang
menggantung adalah pemanggilan dari skrip PowerShell satu baris. Setelah diuji
ulang, `ExportAsFixedFormat` berhasil untuk dokumen kosong maupun untuk charter
ini (4 halaman, 875 kata). Kegagalan sebelumnya kemungkinan besar karena proses
`WINWORD` yatim dari percobaan terdahulu, bukan karena API-nya.

## Format

Template asli memakai **Courier New 9,5 pt**, margin 72 pt (kiri/kanan) dan 61,2 pt
(atas/bawah), ukuran Letter. Font dan ukuran hurufnya dipertahankan apa adanya
karena dosen membandingkan dengan contoh. Kalau Anda ingin mengganti ke Times New
Roman 12 pt, ubah `Styles("Normal")` di skrip — jangan mengubah per paragraf,
karena akan memakan waktu dan mudah terlewat.

Yang diubah hanya kertas dan margin: **A4** dengan margin **kiri 4 cm,
kanan/atas/bawah 3 cm** — ukuran laporan kuliah di Indonesia. Tanpa langkah ini
setiap regenerasi mengembalikan Letter.

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
