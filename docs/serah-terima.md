# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang harus dikerjakan berikutnya, dan apa yang
> tidak boleh diubah. Riwayatnya ada di `git log`, bukan di sini. Aturan:
> **maksimal 120 baris** — kalau lebih, ia menjadi `SPEC.md` kedua. Keputusan
> terkunci **dirujuk**, tidak disalin: salinan akan menyimpang dari sumbernya.

Terakhir diperbarui: fase R4 selesai · 6 November 2026

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir yang selesai | **R4** (`af75277`) |
| Fase yang sedang dikerjakan | **R5** — frontend, UI-01/02/04 |
| Suite PHP / Pint / ESLint | **713 lulus / 3389 asersi**, 36 berkas tes · bersih |
| Migrasi / endpoint / tabel | **36** / **78** (tidak berubah) / **22** |
| Skrip browser | 23 (di luar `_cdp.mjs`), 700 langkah |

**Working tree bersih** kecuali `revisi.md` dan `docs/revisi.md`, yang **sengaja
tidak di-commit**: keduanya masukan audit, bukan deliverable, dan akan selalu
muncul sebagai untracked. Itu benar, bukan sisa pekerjaan.

R4 selesai seluruhnya: DAT-01 (FK `RESTRICT` pada tiga tabel riwayat), DAT-04
(index `tagihan_kontrak_status_index`), DAT-02 (notifikasi per penghuni saat terbit
massal), DAT-05 (jejak audit data induk). **DAT-03 diterima sebagai utang** — §3.

---

## 2. Tiga aksi berikutnya

1. **Kerjakan UI-04 lebih dulu**, karena hanya ini cacat asli di antara ketiganya.
   `web/src/views/DashboardPenghuni.vue:373` menampilkan "Belum ada tagihan untuk
   Anda" dengan syarat `!memuat && daftar.length === 0` saja. Saat `galatMuat`
   terisi, layar menyatakan "tidak ada tagihan" padahal yang terjadi adalah gagal
   muat — dua keadaan yang sangat berbeda bagi penghuni. Tambahkan `&& !galatMuat`
   pada `BarisKosong` itu.
2. **Putuskan UI-01 dan UI-02 dengan membaca, bukan mengubah** (§4), lalu pastikan
   tidak ada yang rusak: `asap-semua-halaman.mjs` dan `e2e-t9.mjs`.
3. **Tutup fase**: `docker compose build web && docker compose up -d web`, lalu
   `periksa.ps1` penuh, commit dengan kode temuan di judul, lalu timpa berkas ini
   untuk R6.

---

## 3. Yang tidak boleh diubah

Jangan menyimpang dari ini tanpa keputusan pemilik:

| Hal | Di mana |
|---|---|
| Tujuh aturan bisnis R-01..R-07 | `SPEC.md` §4, `KONTEKS_PROYEK.md` §4 |
| Model kepemilikan A (pengelola + 8 wewenang) | `SPEC.md` §5, `3_ARCHITECTURE.md` §3.6 |
| Rumus prorata, pembulatan, zona waktu | `SPEC.md` §4 R-01 |
| Isolasi R-07 (404 bukan 403) | `SPEC.md` §4 R-07 |
| Append-only R-06 + snapshot saat terbit | `SPEC.md` §4 R-06 |
| Bentuk permukaan API 78 endpoint | `SPEC.md` §7 |
| Urutan kerja R1→R7 | `revisi.md` (untracked) |

**DAT-03 diterima sebagai utang, bukan cacat yang harus ditutup.** Satu layanan
Render tidak bisa menjalankan queue worker persisten. Kalau sesi berikutnya
menemukan `QUEUE_CONNECTION=sync` dan berniat "memperbaikinya", itu bukan
perbaikan — itu membatalkan keputusan arsitektur. Catatannya di
`docs/utang-teknis.md` §3, kodenya tidak diubah.

**Empat temuan `revisi.md` salah** — jangan diimplementasikan. Daftarnya di
`docs/utang-teknis.md` §7; yang paling menggoda: REV-FIN-06 (status `perbaikan`
tidak ada di kode), REV-ACA-03 (berkas lock Word memang tidak di-track), dan
REV-UI-02 (premisnya salah, §4). **R5 adalah fase perbaikan cacat, bukan fase
fitur** — tiga temuan, tidak lebih.

---

## 4. Jebakan untuk fase berikutnya

Umum, berlaku selamanya — sudah ada di `AGENTS.md` §"Jebakan yang sudah
menggigit". **Baca bagian itu, bukan bagian ini.** Yang khusus fase berikutnya:

**R5 (frontend, UI-01/02/04). Ketiganya sudah diperiksa ulang di akhir R4:**

- **REV-UI-01 SUDAH benar.** `web/src/sesi.js:97` menangani `e.status === 401`
  dengan `keluarLokal()`. Temuan itu overstated: tidak ada status login palsu yang
  menggantung. **Jangan memperbaiki yang tidak rusak** — mengubahnya berisiko
  merusak jalur keluar yang sudah teruji di `e2e-t1`.
- **REV-UI-02 premisnya salah.** Tidak ada satu pun rujukan `komponen-biaya` di
  `web/src`. Menambah UI komponen biaya berarti **fitur baru**, bukan perbaikan.
- **REV-UI-04 asli.** `DashboardPenghuni.vue:373` menampilkan "Belum ada tagihan"
  tanpa memeriksa `galatMuat`. `galatMuat` sudah ada (L29) dan sudah ditampilkan
  (L222) — yang hilang hanya syaratnya di baris kosong.
- **REV-UI-05 sudah dikerjakan di R3** (rekening/QRIS di portal penghuni).
- Setelah mengubah `.vue`: `docker compose build web && docker compose up -d web`.
  **Build saja tidak cukup** — image memuat hasil build, bukan berkas sumber.
  Kalau `api` ikut di-build ulang, **`docker compose restart api-web`** juga; tanpa
  itu semua panggilan `/api` jadi 502 karena nginx memegang IP upstream lama.
- Uji browser menulis ulang PNG di `docs/uji/`. Sebelum commit:
  `git checkout -- docs/uji/*.png`, kecuali ada tangkapan layar yang memang perlu
  berubah — sebutkan di pesan commit kalau begitu.

**R6 (artefak akademik).** Template dosen = **tepat 7 bab**. Use case berbentuk
`UC-01 Nama: penjelasan`, matriks akses `Aktor: Mengakses UC-xx, ...`. Dosen hanya
menuntut **DFD Konteks Level 0** — perbaiki cacat level 1/2, jangan menambah level.
14 use case sudah final. **R7 (sisa Laravel 11):** larik `utang-teknis.md` §5 sudah
menyatakan ini "Diperbaiki di R7", padahal R7 belum dijalankan. Verifikasi dulu.

## 5. Cara memverifikasi klaim di berkas ini

Jangan percaya angka di atas — periksa sendiri:

```powershell
git log --oneline -3        # fase terakhir yang benar-benar commit
git status --short          # hanya revisi.md + docs/revisi.md?
(Get-ChildItem api\database\migrations\*.php).Count          # 36
(Get-ChildItem api\tests -Recurse -Filter *Test.php).Count   # 36
docker compose exec -T api php artisan test                  # 713 lulus
Select-String -Path web\src\sesi.js -Pattern 401             # ada keluarLokal
```

Kalau salah satu tidak cocok, berkas ini basi — perbarui dulu, baru bekerja.