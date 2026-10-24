# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang harus dikerjakan berikutnya, dan apa yang
> tidak boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> Aturan: **maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md`
> kedua dan gunanya hilang. Keputusan yang terkunci **dirujuk**, tidak disalin —
> salinan akan menyimpang dari sumbernya.

Terakhir diperbarui: fase R4 · 6 November 2026

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir yang selesai | **R3** (`b7a4d77`) |
| Fase yang sedang dikerjakan | **R4** — kode dan tes selesai, **belum di-commit** |
| Suite PHP | **713 lulus / 3389 asersi**, 36 berkas tes |
| Pint / ESLint | bersih |
| Migrasi | **36** (2 baru di R4) |
| Endpoint `/api` | **78** (tidak berubah sejak R3) |
| Skrip browser | 23 (di luar `_cdp.mjs`), 700 langkah |

**R4 belum di-commit.** Yang ada di working tree dan harus masuk commit:

| Temuan | Berkas |
|---|---|
| DAT-01 `RESTRICT` pada 3 FK riwayat | `2026_11_06_090300_restrict_delete_on_riwayat_kamar.php` + `SkemaRiwayatKamarTest.php` |
| DAT-04 index `(kontrak_id, status)` | `2026_11_06_090400_add_kontrak_status_index_to_tagihan.php` + tes yang sama |
| DAT-02 notifikasi per-penghuni | `PenerbitanMassal.php`, `Pemberitahu.php`, `TagihanController.php`, `PenerbitanMassalTest.php` |
| DAT-05 jejak data induk | `PencatatAudit.php`, 5 controller, `JejakDataIndukTest.php` |
| DAT-03 | **tidak dikerjakan** — dicatat sebagai utang teknis (lihat §3) |

`revisi.md` dan `docs/revisi.md` **sengaja tidak di-commit**. Keduanya masukan
audit, bukan deliverable. Working tree yang bersih setelah commit tetap memuat
dua berkas itu sebagai untracked — itu benar, bukan sisa pekerjaan.

---

## 2. Tiga aksi berikutnya

1. **Jalankan ulang E2E yang terdampak R4**, satu per satu, dengan
   `migrate:fresh --seed --force` + `cache:clear` sebelum tiap skrip:
   `e2e-t2`, `e2e-t4`, `e2e-t18`, `e2e-t19`, `e2e-t25`, `e2e-t28`.
   Lalu `git checkout -- docs/uji/*.png` untuk mengembalikan tangkapan layar yang
   ikut ditulis ulang.
2. **Perbarui dokumen** dengan angka dan perilaku baru:
   `docs/utang-teknis.md` (§3 baris DAT-03), `SPEC.md` §6 (FK + index),
   `docs/produk/5_SCHEMAS.md` (36 migrasi, index baru),
   `docs/produk/3_ARCHITECTURE.md` + `KONTEKS_PROYEK.md` (34→36 migrasi),
   `docs/produk/7_TESTING_EVALS.md` (713/3389, 36 berkas),
   `docs/produk/1_PRD.md` U-9, `docs/produk/README.md` angka 687.
3. **Commit R4** dengan nama temuan di pesan commit: DAT-01/02/04/05.
   Lalu tulis ulang berkas ini untuk fase R5.

---

## 3. Yang tidak boleh diubah

Jangan menyimpang dari ini tanpa keputusan pemilik. Rujukannya:

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
perbaikan — itu membatalkan keputusan arsitektur. Catat di `docs/utang-teknis.md`
§3, jangan ubah kodenya.

**Empat temuan `revisi.md` salah** — jangan diimplementasikan. Daftarnya di
`docs/utang-teknis.md` §7. Yang paling menggoda: REV-FIN-06 (status `perbaikan`
tidak ada di kode) dan REV-ACA-03 (berkas lock Word memang tidak di-track).

---

## 4. Jebakan untuk fase berikutnya

Umum, berlaku selamanya — sudah ada di `AGENTS.md` §"Jebakan yang sudah
menggigit". **Baca bagian itu, bukan bagian ini.** Yang khusus fase berikutnya:

**R5 (frontend, UI-01/02/04).**
- REV-UI-01 **sudah benar**: `sesi.js:97` menangani 401 → `keluarLokal()`.
  Jangan "memperbaiki" yang tidak rusak. Temuan itu overstated.
- REV-UI-02 premisnya salah: tidak ada rujukan `komponen-biaya` di `web/src`.
  Kalau menambah UI komponen biaya, itu **fitur baru**, bukan perbaikan.
- REV-UI-04 **asli**: `DashboardPenghuni.vue:373` menampilkan "Belum ada
  tagihan" tanpa memeriksa `galatMuat`. Perbaikannya satu kondisi, bukan
  restrukturisasi.
- Setelah mengubah `.vue`: `docker compose build web && docker compose up -d web`.
  Build saja tidak cukup — image memuat hasil build, bukan berkas sumber.

**R6 (artefak akademik).**
- Template dosen = **tepat 7 bab**. Deskripsi use case berbentuk
  `UC-01 Nama: penjelasan`; matriks akses berbentuk `Aktor: Mengakses UC-xx, ...`.
- Dosen hanya menuntut **DFD Konteks Level 0**. Level 1/2 sudah melebihi
  tuntutan — perbaiki cacatnya, jangan menambah level.
- 14 use case sudah final dan diterima. Jangan menambah atau mengurangi.

**R7 (sisa Laravel 11).**
- Larik `utang-teknis.md` §5 sudah menyatakan ini "Diperbaiki di R7", padahal
  R7 belum dijalankan. Verifikasi dulu, baru percaya catatan itu.

---

## 5. Cara memverifikasi klaim di berkas ini

Jangan percaya angka di atas. Periksa sendiri:

```powershell
git log --oneline -3                    # fase terakhir yang benar-benar commit
git status --short                      # tree bersih? hanya revisi.md yang untracked?
(Get-ChildItem api\database\migrations\*.php).Count    # harus 36
(Get-ChildItem api\tests -Recurse -Filter *Test.php).Count  # harus 36
docker compose exec -T api php artisan test            # 713 lulus
```

Kalau salah satu tidak cocok, berkas ini basi — perbarui dulu, baru bekerja.
