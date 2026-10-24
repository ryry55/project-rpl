# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang harus dikerjakan berikutnya, dan apa yang
> tidak boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> Aturan: **maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md`
> kedua dan gunanya hilang. Keputusan yang terkunci **dirujuk**, tidak disalin —
> salinan akan menyimpang dari sumbernya.

Terakhir diperbarui: fase R7 selesai · 25 Oktober 2026

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir yang selesai | **R7** (`4dca0b1`) |
| Fase yang sedang dikerjakan | — (tidak ada; R1–R7 tuntas) |
| Suite PHP | **713 lulus / 3389 asersi**, 36 berkas tes |
| Pint / ESLint | bersih |
| Migrasi | **36** |
| Endpoint `/api` | **78** |
| Tabel domain | **22** |
| Skrip browser | **24** (di luar `_cdp.mjs`), **730 langkah** |
| `periksa.ps1` | **SEMUA LULUS** (480 detik, 25 skrip) |

**Working tree bersih** kecuali `revisi.md` dan `docs/revisi.md`, yang **sengaja
tidak di-commit**: keduanya masukan audit, bukan deliverable.

R6 selesai di `4dca0b1`: `docs/akademik/` berisi diagram use case (3 aktor,
14 use case), matriks hak akses, laporan narasi 7 bab, dan DFD Konteks Level 0 —
semuanya dibangkitkan dari sumber. R7 mengaudit angka: Laravel 12.70.1
dikonfirmasi dari tiga sumber, angka tes usang diperbaiki. Tiga sebutan
"Laravel 11" **sengaja dipertahankan** sebagai catatan keputusan.

---

## 2. Tiga aksi berikutnya

1. **Periksa apakah pemilik masih menuntut 3 halaman.** Narasi sekarang
   **4 halaman** terukur: template memuat 10 aliran dan 5 entitas, sedangkan
   sistem ini wajib 14 aliran, 12 entitas, dan 7 aturan — lantai jujurnya ~3,6
   halaman. Memotong ke 3 berarti membuang isi yang diminta template.
2. **Ekspor `.docx` / `.pdf` final bila diminta.** Jangan menyunting `.md` hasil
   bangkitan — ubah `usecase-data.js` / `dokumen-data.js` lalu bangun ulang.
3. **Tinjau dua kolom mati** (`gerbang`, `konsekuensi`): keduanya tidak pernah
   dicetak. Bukan bug — calon pembersihan, bukan perbaikan mendesak.

---

## 3. Yang tidak boleh diubah

Jangan menyimpang dari ini tanpa keputusan pemilik. Rujukannya:

| Hal | Di mana |
|---|---|
| Tujuh aturan bisnis R-01..R-07 | `SPEC.md` §4, `KONTEKS_PROYEK.md` §4 |
| Model kepemilikan A (pengelola + 8 wewenang) | `SPEC.md` §5, `3_ARCHITECTURE.md` §3.6 |
| Bentuk permukaan API 78 endpoint | `SPEC.md` §7 |
| Urutan kerja R1→R7 | `revisi.md` (untracked) |
| 14 use case, sudah final | `docs/produk/1_PRD.md`, `revisi.md` REV-ACA-01 |

**Empat temuan `revisi.md` salah** — jangan diimplementasikan. Daftarnya di
`docs/utang-teknis.md` §7. Termasuk **REV-ACA-03** (berkas lock
`~$oject_Charter_Kos-Kosan.docx`): berkas itu **tidak di-track**, `.gitignore`
sudah memuat `~$*`. Tidak ada yang perlu dihapus.

**Jangan menambah atau mengurangi use case.** 14 sudah final, termasuk UC-11 dan
UC-14 yang sengaja grab-bag. Pemetaannya tertutup: 78 endpoint terpakai habis,
tidak ada yang dipakai dua use case.

**Jangan menyunting berkas hasil bangkitan di `docs/akademik/`.** Tiga `.md` dan
kedua SVG ditulis ulang setiap build; ubah sumbernya (`usecase-data.js`,
`dokumen-data.js`), lalu jalankan
`node docs/akademik/build.js && node docs/akademik/build-dokumen.js`.

**Jangan menulis ulang DFD.** R1 sudah menjadikannya bersumber tunggal
(`dfd-data.js` + `dfd-gambar.js`). `dfd-konteks-gambar.js` memanggilnya, tidak
menyalinnya — pertahankan itu.

---

## 4. Jebakan untuk fase berikutnya

Umum, berlaku selamanya — sudah ada di `AGENTS.md` §"Jebakan yang sudah
menggigit". **Baca bagian itu, bukan bagian ini.** Yang khusus R6/R7:

- **Keanggotaan aktor diturunkan dari middleware**, bukan diketik. `build.js`
  membaca `peran:` / `wewenang:` di `api/routes/api.php`; mengubah matriks tanpa
  mengubah rute akan menggagalkan build — itu memang tujuannya.
- **`uji-validator.js` harus tetap 4/4.** Kalau gagal, pemeriksanya yang rusak,
  bukan datanya.
- **Halaman diukur, jangan dikira-kira.** `ukur-halaman.ps1` memakai Times New
  Roman 11pt dan margin 2,5 cm supaya sebanding dengan template; teks template
  menghasilkan 3 halaman dengan alat yang sama.
- **`rute-api.json` peka encoding.** Pengalihan `>` milik PowerShell menulis
  UTF-16LE ber-BOM dan `JSON.parse` menolaknya. Pakai `cmd /c` (perintahnya di
  `docs/akademik/README.md`).

---

## 5. Cara memverifikasi klaim di berkas ini

Jangan percaya angka di atas — periksa sendiri:

```powershell
git log --oneline -3        # fase terakhir yang benar-benar commit
git status --short          # hanya revisi.md + docs/revisi.md?
(Get-ChildItem api\database\migrations\*.php).Count          # 36
(Get-ChildItem api\tests -Recurse -Filter *Test.php).Count   # 36
(Get-ChildItem docs\uji\*.mjs).Count                         # 25 (24 + _cdp)
docker compose exec -T api php artisan test                  # 713 lulus
node docs/akademik/build.js && node docs/akademik/build-dokumen.js   # 0 masalah
node docs/akademik/uji-validator.js                          # 4/4
```

Kalau salah satu tidak cocok, berkas ini basi — perbarui dulu, baru bekerja.
