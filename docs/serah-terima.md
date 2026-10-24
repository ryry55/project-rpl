# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang harus dikerjakan berikutnya, dan apa yang
> tidak boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> Aturan: **maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md`
> kedua dan gunanya hilang. Keputusan yang terkunci **dirujuk**, tidak disalin —
> salinan akan menyimpang dari sumbernya.

Terakhir diperbarui: fase R5 selesai · 6 November 2026

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir yang selesai | **R5** (`ba708fe`) |
| Fase yang sedang dikerjakan | **R6** — artefak akademik |
| Suite PHP | **713 lulus / 3389 asersi**, 36 berkas tes |
| Pint / ESLint | bersih |
| Migrasi | **36** |
| Endpoint `/api` | **78** |
| Tabel domain | **22** |
| Skrip browser | **24** (di luar `_cdp.mjs`), **730 langkah** |
| `periksa.ps1` | **SEMUA LULUS** (480 detik, 25 skrip) |

**Working tree bersih** kecuali `revisi.md` dan `docs/revisi.md`, yang **sengaja
tidak di-commit**: keduanya masukan audit, bukan deliverable.

R5 selesai: REV-UI-04 diperbaiki di **10 layar** (1 temuan asli + 9 turunan,
dicatat di `docs/utang-teknis.md` §3b). REV-UI-01 dan REV-UI-02 **tidak
dikerjakan karena temuannya salah** — lihat §3. Tiga E2E yang flaky
(`e2e-t25`, `e2e-r3`, `e2e-t18`) diperbaiki: ketiganya menunggu elemen yang
muncul sebelum data tiba. Dibuktikan bukan regresi R5 dengan menjalankan kode
lama lebih dulu.

---

## 2. Tiga aksi berikutnya

1. **Baca `revisi.md` bagian REV-ACA-01 dan REV-ACA-02**, lalu periksa apa yang
   sudah ada di `docs/charter/`, `docs/dfd/`, dan `Template Laporan/`. Jangan
   menulis ulang yang sudah ada — R1 sudah membereskan DFD, dan sembilan
   dokumen produk sudah ditulis di `docs/produk/`.
2. **Buat artefak yang memang belum ada:** diagram use case UML (3 aktor,
   14 use case, final dan tidak boleh diubah), matriks hak akses, dan dua
   dokumen laporan sesuai template dosen (7 bab).
3. **Tutup fase**: perbarui `docs/serah-terima.md` untuk R7, lalu commit dengan
   kode temuan di judul (REV-ACA-01/02).

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
`docs/utang-teknis.md` §7. Untuk R6 yang paling menggoda: **REV-ACA-03**
(berkas lock `~$oject_Charter_Kos-Kosan.docx`) — berkas itu **tidak di-track**,
`.gitignore` sudah memuat `~$*`. Tidak ada yang perlu dihapus.

**Jangan menambah atau mengurangi use case.** 14 sudah final dan diterima
pemilik, termasuk UC-11 dan UC-14 yang sengaja berupa grab-bag.

**Jangan menulis ulang DFD.** R1 sudah menjadikannya bersumber tunggal
(`dfd-data.js` + `dfd-gambar.js`) dengan 20+ penjaga. Menambah level bukan
perbaikan: dosen hanya menuntut Konteks Level 0.

---

## 4. Jebakan untuk fase berikutnya

Umum, berlaku selamanya — sudah ada di `AGENTS.md` §"Jebakan yang sudah
menggigit". **Baca bagian itu, bukan bagian ini.** Yang khusus R6:

- **Template dosen = tepat 7 bab.** Deskripsi use case berbentuk
  `UC-01 Nama: penjelasan`; matriks akses berbentuk
  `Aktor: Mengakses UC-xx, ...`. Menyimpang dari bentuk ini membuat dokumen
  dinilai tidak sesuai template walau isinya benar.
- **Bahasa Indonesia untuk seluruh narasi.** Istilah teknis boleh Inggris
  (endpoint, migration, webhook), narasi tidak.
- **Jangan mengarang angka.** Semua angka ada di §1 dan bisa diverifikasi
  dengan perintah di §5. Laporan yang menyebut angka berbeda dari sistem akan
  langsung terlihat saat sidang.
- **Diagram harus bisa diregenerasi.** Kalau membuat diagram baru, ikuti pola
  R1: data di satu berkas, penggambar di berkas lain, jangan gambar tangan.
  Diagram tangan tidak bisa diperbaiki saat angkanya berubah.
- **Dokumen `.docx`/`.pdf` tidak bisa diverifikasi dengan `git diff`.** Kalau
  menghasilkan berkas biner, simpan sumbernya (`.md` atau skrip) di repo
  supaya perubahannya bisa ditelusuri.

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
```

Kalau salah satu tidak cocok, berkas ini basi — perbarui dulu, baru bekerja.
