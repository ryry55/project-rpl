# Tiket — peta issue GitHub

Spec: **#1**. Delapan tiket terbit sebagai sub-issue #1, berlabel `ready-for-agent`.
Relasi blocking memakai **native issue dependencies** GitHub, jadi tiket yang belum bisa
dikerjakan akan tampil terkunci di UI.

| Tiket | Issue | Aturan | Diblokir oleh | Status |
|---|---|---|---|---|
| T1 Walking skeleton | **#2** | — | — | ✅ selesai |
| T2 Data induk + tarif append-only | **#3** | R-06 (lapis 1) | #2 | ✅ selesai |
| T3 Tagihan + prorata | **#4** | R-01, R-06 | #3 | terbuka, siap |
| T4 Terbit massal + tolak duplikat | **#5** | R-02 | #4 | terkunci |
| T5 Catat pembayaran | **#6** | — | #4 | terkunci |
| T6 Denda + simulasi tanggal | **#7** | R-03 | #4 | terkunci |
| T7 Umur tunggakan | **#8** | R-04 | #7 | terkunci |
| T9 Portal penghuni + log audit | **#9** | R-07 | #5, #6 | terkunci |

```
#2 T1 ✅
 └─ #3 T2 ✅
     └─ #4 T3 ─┬─ #5 T4 ──┐
               ├─ #6 T5 ──┴─ #9 T9
               └─ #7 T6 ── #8 T7
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
