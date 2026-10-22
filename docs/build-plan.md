# Rencana Pembangunan Aplikasi

Keputusan bentuk fase build, diambil 22 Oktober 2026 sebelum `/to-spec` dijalankan. Berkas ini
adalah **sumber primer** untuk spec: `/to-spec` membaca ini, bukan mengarang ulang.

## Keputusan yang sudah final

| Hal | Keputusan | Alasan |
|---|---|---|
| Cakupan | **T1–T7 + T9** (8 tiket) | Semua R-01..R-04 dan R-07 terdemokan. T8 (deposit) dan T10 (laporan) ditunda jadi tabel manual di laporan. |
| Backend | Laravel 11 API-only + PostgreSQL + Sanctum | Sesuai rencana awal, dokumentasi banyak, cocok untuk tugas kampus. |
| Frontend | Vue 3 + Vite SPA, **modern product feel** | Menggunakan skill desain di fase frontend. Anggaran ~2–3 sesi tambahan. |
| Notasi DFD | Gane-Sarson (utama), Yourdon tersedia | Lihat `docs/dfd/README.md` bagian 6. |
| Batas waktu | 22 Desember 2026 (sesuai charter) | ~9 minggu dari sekarang. |

## Tiket

Irisan vertikal — tiap tiket memotong skema, API, UI, dan tes, dan bisa didemokan sendiri.

| # | Tiket | Aturan | Blocker | Demo |
|---|---|---|---|---|
| T1 | Walking skeleton: login 2 peran, 1 endpoint, 1 layar, PG, Docker Compose | — | — | login → dashboard kosong |
| T2 | Kamar, Penghuni, Kontrak + tarif & komponen biaya | R-06 | T1 | pemilik mendaftarkan kamar & penghuni |
| T3 | Modul tagihan + prorata bulan pertama | R-01 | T2 | tagihan pertama dengan prorata benar |
| T4 | Terbit massal + tolak duplikat periode | R-02 | T3 | terbit 2× → yang kedua ditolak |
| T5 | Catat pembayaran + perubahan status tagihan | — | T3 | bayar → status lunas |
| T6 | Denda keterlambatan + plafon 30% | R-03 | T3 | hari ke-7 vs ke-8, plafon tercapai |
| T7 | Umur tunggakan 0–30 / 31–60 / >60 | R-04 | T6 | dashboard bucket tunggakan |
| T9 | Portal penghuni + log audit append-only | R-07 | T4 | penghuni hanya melihat tagihannya sendiri |

**Ditunda:** T8 (deposit & kerusakan, R-05) dan T10 (rekap & laporan, proses 5.0).

**Urutan kerja yang disarankan:** T1 → T2 → T3 → T6 lebih dulu. Di situ letak nilai proyek.
Kalau waktu habis setelah T6, demo masih kuat: prorata benar, denda benar, plafon benar.
Membangun CRUD kamar/penghuni sampai mengkilap lebih dulu berisiko kehabisan waktu sebelum
sampai ke bagian yang membedakan proyek ini dari CRUD biasa.

## Dua seam pengujian

Hanya dua. Bukan satu per use case.

**Seam 1 — kalkulator tagihan (murni, tanpa basis data).**

```
hitungSewaBulanPertama(tarif, hariTinggal, hariDalamBulan) -> uang
hitungDenda(pokok, hariTerlambat, tarifHarian, plafon)     -> uang
kelompokUmur(hariTerlambat)                               -> bucket
```

Menguji batas 7/8, 30/31, 60/61, dan plafon 30% di sini. Cepat, tanpa DB, tanpa HTTP.

**Seam 2 — HTTP sebagai konvensi.** Untuk yang tidak bisa diuji di fungsi murni: R-02 (tolak
duplikat) dan R-07 (penghuni tidak bisa mengakses tagihan orang lain). Auth, policy, status code.

## Pertanyaan terbuka — harus dijawab di spec

Enam hal ini menentukan benar-salahnya perhitungan, dan semuanya keputusan spec, bukan
keputusan coding.

1. **Arah pembulatan prorata.** `1.500.000 × 15 / 31 = 725.806,45`. Dibulatkan ke rupiah penuh
   ke bawah, ke atas, atau ke terdekat? Siapa yang membulatkan — kalkulator atau formatter?
2. **Dasar denda setelah pembayaran sebagian.** Plafon 30% dihitung dari pokok awal atau dari
   sisa tagihan? Satu kalimat, satu tes.
3. **Tipe uang.** Konfirmasi `numeric(14,2)` di PostgreSQL dan string desimal di PHP. Jangan
   pernah float.
4. **Zona waktu.** "Hari ke-8 setelah jatuh tempo" dihitung pada `Asia/Jakarta`, bukan UTC.
5. **Versi tarif.** Konfirmasi tarif punya `berlaku_dari` dan tidak pernah di-`UPDATE`, supaya
   R-06 tidak berlaku surut. Kalau tarif ditimpa, tagihan lama ikut berubah.
6. **Idempotensi R-02.** Konfirmasi pakai unique constraint `(kontrak_id, periode)` di basis
   data, bukan pengecekan `if exists` di aplikasi, supaya dua request bersamaan tidak lolos.

## Di luar cakupan

Sub-metering listrik/air, payment gateway, aplikasi mobile native, integrasi akuntansi,
migrasi data historis lebih dari 12 bulan, deposit (T8), modul laporan lengkap (T10).

## Yang tidak boleh dilupakan

Log audit R-07 harus **append-only**. Kalau log bisa diedit atau dihapus, audit tidak
membuktikan apa pun, dan itu meruntuhkan salah satu dari tujuh aturan bisnis.
