# Tiket — peta issue GitHub

Spec: **#1**. Delapan tiket terbit sebagai sub-issue #1, berlabel `ready-for-agent`.
Relasi blocking memakai **native issue dependencies** GitHub, jadi tiket yang belum bisa
dikerjakan akan tampil terkunci di UI.

| Tiket | Issue | Aturan | Diblokir oleh | Status |
|---|---|---|---|---|
| T1 Walking skeleton | **#2** | — | — | ✅ selesai |
| T2 Data induk + tarif append-only | **#3** | R-06 (lapis 1) | #2 | ✅ selesai |
| T3 Tagihan + prorata | **#4** | R-01, R-06 | #3 | ✅ selesai |
| T4 Terbit massal + tolak duplikat | **#5** | R-02 | #4 | ✅ selesai |
| T5 Catat pembayaran | **#6** | — | #4 | ✅ selesai |
| T6 Denda + simulasi tanggal | **#7** | R-03 | #4 | ✅ selesai |
| T7 Umur tunggakan | **#8** | R-04 | #7 | ✅ selesai |
| T9 Portal penghuni + log audit | **#9** | R-07 | #5, #6 | ✅ selesai |

```
#2 T1 ✅
 └─ #3 T2 ✅
     └─ #4 T3 ─┬─ #5 T4 ──┐
               ├─ #6 T5 ──┴─ #9 T9 ✅
               └─ #7 T6 ── #8 T7 ✅
```

## Urutan kerja

**Jalur kritis:** T1 → T2 → T3. Setelah T3, tiga tiket membuka bersamaan: T4, T5, T6.

Rekomendasi urutan pengerjaan: **T1 → T2 → T3 → T6 → T7 → T4 → T5 → T9**.

Alasannya: setelah T3, T6 dan T7 berisi aturan yang paling bernilai dan paling mungkin ditanya
saat sidang (denda dan umur tunggakan). Kalau waktu habis di tengah, demo sudah kuat tanpa T4,
T5, dan T9. Kalau mengerjakan T4/T5 dulu, yang tersisa justru bagian yang paling menonjol.

**T9 dikerjakan terakhir** karena bergantung pada dua tiket sekaligus dan tidak menambah aturan
baru — hanya menegakkan isolasi dan audit atas apa yang sudah ada.

## Catatan penyelesaian

**T2 (#3)** — commit `269edec`. 46 tes hijau (144 asersi), 13/13 langkah E2E browser,
3 screenshot. R-06 lapis 1 (riwayat tarif + trigger append-only di basis data) selesai.
Empat deviasi dari SPEC §6 dicatat di SPEC §5: `komponen_biaya.aktif` tidak dibuat, email
penghuni ditambahkan, relasi lewat `users.penghuni_id`, `parameter_denda` memakai `POST`.

**T3 (#4)** — commit `1406b45`. 91 tes hijau (387 asersi), termasuk 22 tes kalkulator murni tanpa
DB; 18/18 langkah E2E browser, 2 screenshot. R-01 dan R-06 lapis 2 selesai. R-02 ikut aktif lebih
awal: unique index `tagihan(kontrak_id, periode)` dan `23505` → 409 sudah dibuat di T3, jadi T4
tinggal menambahkan penerbitan massal.

**T6 (#7)** — 136 tes hijau (992 asersi), 46 di antaranya tes murni tanpa DB; 23/23 langkah E2E
browser, 2 screenshot. R-03 selesai. Keputusan yang dicatat di SPEC §5: satu sumber "hari ini"
(`WaktuSistem`), header simulasi diabaikan bila tidak sah, status simulasi diumumkan di `meta`
badan respons, denda tidak disimpan, dasar denda adalah pokok beku, parameter dibaca dari snapshot
baris `tagihan`, tarif 0% sah, persentase diurai dari teks.

**T7 (#8)** — 164 tes hijau (1421 asersi), 60 di antaranya tes murni tanpa DB; 31/31 langkah E2E
browser, 1 screenshot. R-04 selesai. Keputusan yang dicatat di SPEC §5: `kelompokUmur` menerima hari
terlambat sebagai argumen (tidak memanggil jam), rumus denda dipanggil dari kalkulator yang sama
bukan disalin, penyaringan status di SQL, `sebagian` tetap dihitung tunggakan, kelompok kosong tetap
dikirim, "belum jatuh tempo" kelompok tersendiri, dan kendali simulasi diekstrak jadi satu komponen
yang dipakai Tagihan dan Tunggakan.

**T4 (#5)** — 186 tes hijau (1517 asersi), 60 di antaranya tes murni tanpa DB; 15/15 langkah E2E
browser, 9/9 langkah uji balapan HTTP, 1 screenshot. R-02 selesai. Keputusan yang dicatat di SPEC §5:
kode status 201/409/200/422 membedakan tiga nasib kontrak, `dilewati_duplikat` dipisah dari
`dilewati`, transaksi per kontrak, pemeriksaan `exists()` bukan pengaman utama, `aktor_id` tanpa
foreign key, `aktor_nama` disalin, jejak audit di dalam transaksi perubahan, batch yang seluruhnya
dilewati tidak menulis jejak. Fondasi `log_audit` (tabel + trigger + `PencatatAudit`) dibangun di
sini karena kriteria T4 menuntutnya, sementara T9 justru diblokir T4; T9 tinggal portal penghuni dan
endpoint baca.

**T5 (#6)** — 214 tes hijau (1638 asersi); 21/21 langkah E2E browser, 11/11 langkah uji balapan HTTP,
1 screenshot. Keputusan yang dicatat di SPEC §5: status diturunkan dari `SUM(pembayaran.jumlah)`
bukan ditambah inkremental, pemeriksaan sisa di dalam transaksi setelah `SELECT ... FOR UPDATE`,
aturan yang butuh data tagihan di layanan bukan di `FormRequest`, denda dihitung pada tanggal acuan
sistem bukan tanggal pembayaran, kelebihan bayar ditolak 422, `jumlah` selalu positif, `total_final`
dibekukan sekali saat lunas, `sisa` dijepit `max(0, ...)`, `pembayaran` append-only, `dibayar`
dijumlahkan dengan `withSum` untuk menghindari N+1, dan rumus `pokok + denda` hanya ada di
`HasilDenda::total()`. Batasan dicatat jujur: belum ada baris pembalik, jadi salah input jumlah
tidak bisa diperbaiki dari aplikasi.

**T9 (#9)** — 232 tes hijau (1703 asersi); 23/23 langkah E2E browser, 2 screenshot. R-07 selesai.
Keputusan yang dicatat di SPEC §5: isolasi ditulis sebagai scope `Tagihan::terlihatOleh` bukan `if`
di tiap controller, tagihan orang lain dijawab **404** yang tidak bisa dibedakan dari id yang tidak
ada, `show` dan `denda` mengambil ulang barisnya lewat scope meski route model binding sudah
menyediakannya, akun penghuni tanpa `penghuni_id` mendapat daftar kosong, endpoint portal terpisah
dari `/tagihan`, `/penghuni/tagihan` didaftarkan sebelum `apiResource('penghuni')`, log audit hanya
punya `index`, append-only ditegakkan trigger basis data, urutan `dibuat_pada DESC, id DESC`, `batas`
diumumkan lewat `meta.dipotong`, dan baris baru di log tidak diberi panah "— → nilai".

Dengan ini seluruh tiket yang dikerjakan selesai: T1, T2, T3, T6, T7, T4, T5, T9. R-01 sampai R-07
tertutup kecuali R-05 (deposit) yang memang ditunda ke T8.

## Ditunda (tidak punya issue)

| Tiket | Isi | Alasan |
|---|---|---|
| T8 | Deposit & kerusakan (R-05) | Butuh alur foto dan negosiasi; kolom `deposit` sudah disiapkan di T2 dan sudah terisi lewat formulir kontrak |
| T10 | Laporan & rekap (proses 5.0) | Bisa dihitung manual; jadi tabel manual di laporan |

Keduanya disebut di SPEC §11 dan tetap muncul di tabel ketertelusuran dengan status ⏸.

## Label

Semua tiket berlabel `ready-for-agent`. Label lain yang tersedia: `needs-triage`, `needs-info`,
`ready-for-human`, `wontfix`.

## Cara memeriksa graf

```powershell
gh issue list --state open --json number,title,parent
gh api repos/ryry55/project-rpl/issues/7/dependencies/blocked_by --jq '.[].number'
```

Catatan: `gh issue list --json blockedBy` **tidak** mengembalikan isi relasi walaupun
`issue_dependencies_summary.blocked_by` menunjukkan angka bukan nol. Pakai endpoint
`dependencies/blocked_by` untuk membaca daftarnya.
