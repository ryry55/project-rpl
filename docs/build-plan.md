# Rencana Pembangunan Aplikasi

> **Status dokumen (fase terakhir).** Ini catatan perencanaan yang ditulis sebelum pekerjaan berjalan, dan isinya sengaja **tidak** ditulis ulang supaya jejak keputusan tetap jujur. Satu hal yang sudah tidak berlaku: **deposit** (T8, R-05) tidak lagi ditunda — ia **dihapus seluruhnya** di fase terakhir karena tidak pernah tercatat sebagai uang masuk, tidak pernah mengurangi tagihan, dan tidak pernah dikembalikan. Alasan lengkapnya di `SPEC.md` R-05 dan `docs/utang-teknis.md`. Keadaan akhir yang berlaku ada di `README.md` akar repo.

Keputusan bentuk fase build, diambil 22 Oktober 2026 sebelum `/to-spec` dijalankan. Berkas ini
adalah **sumber primer** untuk spec: `/to-spec` membaca ini, bukan mengarang ulang.

## Keputusan yang sudah final

| Hal | Keputusan | Alasan |
|---|---|---|
| Cakupan | **T1–T7 + T9** (8 tiket) | Semua R-01..R-04 dan R-07 terdemokan. T8 (deposit) dan T10 (laporan) ditunda jadi tabel manual di laporan. |
| Backend | Laravel 12.70.1 API-only + PostgreSQL + Sanctum | Rencana awal Laravel 11; Composer memasang 12.70.1 karena itu rilis stabil saat proyek dimulai. Selisihnya tidak menyentuh kode aplikasi. |
| Frontend | Vue 3 + Vite SPA, **modern product feel** | Menggunakan skill desain di fase frontend. Anggaran ~2–3 sesi tambahan. |
| Notasi DFD | Gane-Sarson (utama), Yourdon tersedia | Lihat `docs/dfd/README.md` bagian 6. |
| Batas waktu | 22 Desember 2026 (sesuai charter) | ~9 minggu dari sekarang. |
| Zona waktu | `Asia/Jakarta` | Tanggal kalender, bukan UTC. |

## Tiket

Irisan vertikal — tiap tiket memotong skema, API, UI, dan tes, dan bisa didemokan sendiri.

| # | Tiket | Aturan | Blocker | Demo |
|---|---|---|---|---|
| T1 | Walking skeleton: login 2 peran, 1 endpoint, 1 layar, PG, Docker Compose | — | — | login → dashboard kosong |
| T2 | Kamar, Penghuni, Kontrak + tarif & komponen biaya | R-06 | T1 | pemilik mendaftarkan kamar & penghuni |
| T3 | Modul tagihan + prorata bulan pertama | R-01 | T2 | tagihan pertama dengan prorata benar |
| T4 | Terbit massal + tolak duplikat periode | R-02 | T3 | terbit 2× → yang kedua ditolak |
| T5 | Catat pembayaran + perubahan status tagihan | — | T3 | bayar → status lunas |
| T6 | Denda keterlambatan + plafon 30% | R-03 | T3 | hari ke-7 vs ke-8, plafon hari ke-37 |
| T7 | Umur tunggakan 0–30 / 31–60 / >60 | R-04 | T6 | dashboard bucket tunggakan |
| T9 | Portal penghuni + log audit append-only | R-07 | T4 | penghuni hanya melihat tagihannya sendiri |

**Ditunda:** T8 (deposit & kerusakan, R-05) dan T10 (rekap & laporan, proses 5.0).

**Urutan kerja yang disarankan:** T1 → T2 → T3 → T6 lebih dulu. Di situ letak nilai proyek.
Kalau waktu habis setelah T6, demo masih kuat: prorata benar, denda benar, plafon benar.

## Dua seam pengujian

Hanya dua. Bukan satu per use case.

**Seam 1 — kalkulator tagihan (murni, tanpa basis data).**

```
hitungSewaBulanPertama(tarif, hariTinggal, hariDalamBulan) -> uang
hitungDenda(pokok, hariTerlambat, tarifPersen, tenggang, plafonPersen) -> uang
kelompokUmur(hariTerlambat)                               -> bucket
```

Menguji batas 7/8, 30/31, 60/61, dan plafon hari ke-37 di sini. Cepat, tanpa DB, tanpa HTTP.

**Seam 2 — HTTP sebagai konvensi.** Untuk yang tidak bisa diuji di fungsi murni: R-02 (tolak
duplikat) dan R-07 (penghuni tidak bisa mengakses tagihan orang lain). Auth, policy, status code.

## Jawaban atas pertanyaan terbuka

Semua sudah diputuskan. Spec tidak boleh menebak ulang.

### 1. Pembulatan prorata

**Bulat ke rupiah penuh, half-up, di dalam kalkulator, per baris tagihan.**

`1.500.000 × 15 / 31 = 725.806,45 → 725.806`

Yang penting bukan arahnya, tapi **di mana**: hitung eksak → bulatkan → simpan hasil bulat →
jumlahkan baris yang sudah bulat. Jangan simpan angka berbeda dari yang ditampilkan, karena
total di database, total di layar, dan jumlah baris bisa tidak cocok.

Titik uji wajib: penghuni masuk tanggal 1 → `hari_tinggal = hari_dalam_bulan` → hasil harus
**persis sama** dengan tarif penuh, bukan kurang Rp 1.

### 2. Denda: 1% per hari, plafon 30%

```
pokok    = sewa (setelah prorata bila bulan pertama) + biaya tambahan tetap
           pokok TIDAK termasuk denda periode sebelumnya
hari_denda = max(0, hari_terlambat - 7)
denda    = min(bulat(pokok × hari_denda × 1 / 100),
               bulat(pokok × 30 / 100))
```

Karena tarif 1%/hari dan plafon 30%, **plafon selalu tercapai tepat di hari denda ke-30**,
yaitu **hari terlambat ke-37**. Contoh pokok Rp 1.500.000:

| Hari terlambat | Hari denda | Denda |
|---|---|---|
| 7 | 0 | Rp 0 |
| 8 | 1 | Rp 15.000 |
| 30 | 23 | Rp 345.000 |
| **37** | **30** | **Rp 450.000** (plafon) |
| 365 | 358 | Rp 450.000 (tetap) |

**Hitung sekali jalan, jangan diakumulasi per hari** — akumulasi membuat pembulatan menumpuk
dan hasilnya meleset dari plafon.

Dua konsekuensi yang harus ditegaskan:

- **Denda tetap berjalan walau dibayar sebagian.** Denda berhenti hanya saat tagihan **lunas penuh**.
- **Plafon tidak berubah karena pembayaran sebagian.** Dasarnya pokok yang dibekukan, bukan sisa.
  Kalau dasarnya sisa, mencicil justru menurunkan plafon, dan menunda bayar jadi lebih
  menguntungkan daripada mencicil.

### 3. Tipe uang

**Rupiah bulat: `numeric(14,0)` di PostgreSQL.** Tidak ada sen.

Di Laravel: cast `decimal:0`, aritmetika pakai `bcmath`. **Jangan float** — `0.3` tidak bisa
direpresentasikan eksak dalam biner, dan itu penyebab klasik selisih Rp 1 pada plafon 30%.

### 4. Zona waktu

**`app.timezone = 'Asia/Jakarta'`; `jatuh_tempo` bertipe `date`, bukan `timestamp`.**

"Jatuh tempo tanggal 10" adalah tanggal, bukan momen. Menyimpannya sebagai `timestamp` membuat
tengah malam WIB menjadi 17:00 UTC hari sebelumnya, dan `hari_terlambat` bisa meleset sehari.

`hari_terlambat` = selisih tanggal kalender, keduanya `date`, tanpa komponen jam.

### 5. Tanggal jatuh tempo = tanggal masuk penghuni

Bukan tanggal tetap 1 atau 10. Konsekuensi: **setiap penghuni punya tanggal jatuh tempo
berbeda**, jadi penerbitan massal (T4) tidak memakai satu tanggal seragam.

**Aturan jepit:** kalau tanggal masuk tidak ada di bulan berjalan, pakai hari terakhir bulan itu.

- Masuk 31 Januari → jatuh tempo Februari = **28/29 Februari**
- Masuk 30 Januari → jatuh tempo Februari = **28/29 Februari**
- Masuk 31 Maret → jatuh tempo April = **30 April**

Tanpa aturan ini, denda dihitung dari tanggal yang tidak ada — bug yang hanya muncul beberapa
kali setahun sehingga sering lolos pengujian.

### 6. Versi tarif: dua lapis

**Lapis 1 — data master:** tarif punya `berlaku_dari`, dan baris lama **tidak pernah di-UPDATE**.
Mencari tarif untuk suatu periode = baris dengan `berlaku_dari` terbesar yang ≤ awal periode.

**Lapis 2 — snapshot di tagihan:** tagihan menyimpan `tarif_terpakai` sebagai nilai beku.

Lapis 2 yang sebenarnya menegakkan R-06. Tanpa snapshot, mengubah data master tetap mengubah
tagihan lama lewat join. Dengan snapshot, tagihan terbit tidak berubah angkanya oleh apa pun
kecuali pembayaran dan denda.

### 7. Idempotensi R-02

**Unique index di basis data**, bukan pengecekan di aplikasi.

```sql
CREATE UNIQUE INDEX tagihan_kontrak_periode_unique ON tagihan (kontrak_id, periode);
```

`if (!exists())` punya celah balapan: dua request bersamaan lolos pengecekan sebelum salah
satunya menulis. Di demo tombol "Terbitkan" ditekan dua kali cepat, ini benar-benar bisa terjadi.

Implementasi: coba `INSERT`, tangkap `QueryException` kode `23505` (unique violation
PostgreSQL), balas **HTTP 409 Conflict**. Cek di aplikasi tetap boleh ada, tapi hanya untuk
pesan error yang lebih ramah, bukan sebagai pengaman.

## Parameter awal (seed data)

| Parameter | Nilai |
|---|---|
| Tarif denda | 1% dari pokok per hari |
| Plafon denda | 30% dari pokok |
| Masa tenggang | 7 hari |
| Tanggal jatuh tempo | tanggal masuk, dijepit ke akhir bulan |
| Deposit | 1 bulan sewa (untuk T8 yang ditunda, tapi siapkan kolomnya di T2) |

## Audit ketertelusuran

Usulan pemilik proyek: periksa apakah aplikasi yang jadi masih sesuai DFD, charter, dan aturan
bisnis. Bentuknya satu tabel yang memetakan aturan → implementasi → tes → status.

**Rawat berjalan, jangan tunggu selesai.** Kalau audit baru dilakukan di akhir dan ternyata ada
aturan yang belum diimplementasikan, tidak ada waktu lagi memperbaikinya. Perbarui tabel ini
setiap tiket selesai, lalu jalankan audit formal sekali di akhir sebagai verifikasi.

Lokasi: `docs/uji/audit-ketertelusuran.md` (dibuat saat fase implementasi dimulai).

Format yang dituju:

```markdown
| Aturan | Implementasi | Tes | Status |
|---|---|---|---|
| R-01 prorata | TagihanCalculator::hitungSewaBulanPertama | test_prorata_15_dari_31 | ✅ |
| R-02 satu tagihan per periode | unique index + TagihanService | test_terbit_dua_kali_ditolak | ✅ |
| R-03 denda 1%/hari plafon 30% | TagihanCalculator::hitungDenda | test_batas_7_8, test_plafon_hari_37 | ✅ |
| R-04 aging | TagihanCalculator::kelompokUmur | test_batas_30_31, test_batas_60_61 | ✅ |
| R-05 deposit | — | — | ⏸ ditunda (T8) |
| R-06 tarif tidak surut | tarif.berlaku_dari + snapshot | test_tagihan_lama_tidak_berubah | ✅ |
| R-07 isolasi penghuni | Policy + audit_log append-only | test_penghuni_tidak_akses_tagihan_lain | ✅ |
```

Nilai tambahnya untuk sidang: dosen melihat aturan mana yang sudah dan belum terpenuhi, bukan
klaim "semua selesai".

## Di luar cakupan

Sub-metering listrik/air, payment gateway, aplikasi mobile native, integrasi akuntansi,
migrasi data historis lebih dari 12 bulan, deposit (T8), modul laporan lengkap (T10).

## Yang tidak boleh dilupakan

Log audit R-07 harus **append-only**. Kalau log bisa diedit atau dihapus, audit tidak
membuktikan apa pun, dan itu meruntuhkan salah satu dari tujuh aturan bisnis.

## Catatan alat: encoding saat mengirim teks ke GitHub

PowerShell 5.1 `Get-Content -Raw` membaca berkas UTF-8 **sebagai ANSI**, sehingga karakter
non-ASCII rusak saat dikirim ke `gh issue create --body-file`. Em dash (U+2014) selamat, tapi
panah (U+2192), centang (U+2705), dan simbol status (U+23F3) hancur menjadi byte sampah.

Pakai pembacaan eksplisit:

```powershell
$spec = [System.IO.File]::ReadAllText("SPEC.md", [System.Text.Encoding]::UTF8)
[System.IO.File]::WriteAllText("$env:TEMP\body.md", $spec, (New-Object System.Text.UTF8Encoding($false)))
gh issue edit 1 --body-file "$env:TEMP\body.md"
```

Verifikasi wajib setelah unggah: ambil ulang lewat `gh api ... > file.json` (redirect **cmd**, bukan
pipe PowerShell), lalu bandingkan jumlah kemunculan tiap karakter non-ASCII dengan berkas lokal.
Jangan percaya tampilan konsol — konsol yang menampilkan mojibake belum tentu servernya rusak,
dan sebaliknya.

## Perubahan yang menggantikan dokumen lama

R-03 semula tertulis "nominal tetap per hari". Pada 22 Oktober 2026 diubah menjadi
**1% dari pokok per hari dengan plafon 30%**. Yang ikut diperbarui:

- `docs/charter/Project_Charter_Kos-Kosan.docx` (bagian 2 dan KPI butir 5)
- `docs/dfd/README.md` (tabel aturan dan kamus data)
- `docs/dfd/03-dfd-level-2.md` (proses 4.2 dan contoh perhitungan)
- berkas ini
