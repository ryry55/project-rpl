# Rencana: dari satu model kos menjadi kos yang bisa dikonfigurasi

> **Status dokumen (fase terakhir).** Ini catatan perencanaan yang ditulis sebelum pekerjaan berjalan, dan isinya sengaja **tidak** ditulis ulang supaya jejak keputusan tetap jujur. Satu hal yang sudah tidak berlaku: **deposit** (T8, R-05) tidak lagi ditunda — ia **dihapus seluruhnya** di fase terakhir karena tidak pernah tercatat sebagai uang masuk, tidak pernah mengurangi tagihan, dan tidak pernah dikembalikan. Alasan lengkapnya di `SPEC.md` R-05 dan `docs/utang-teknis.md`. Keadaan akhir yang berlaku ada di `README.md` akar repo.

Dokumen ini mencatat keputusan yang diambil 24 Oktober 2026, setelah audit menemukan bahwa
sistem mengunci **satu model bisnis kos** lalu memperlakukannya seolah universal.

## Diagnosis

Akar masalahnya bukan daftar fitur yang hilang. Akarnya: **tidak ada satu pun tempat untuk
menyimpan "kebijakan kos ini bagaimana"**. Tidak ada tabel `kos`, tidak ada `pengaturan`.
Semua keputusan bisnis menjadi konstanta global:

| Yang seharusnya berbeda per kos | Kenyataan sebelum perubahan |
|---|---|
| Satuan sewa | Tidak ada kolom. `Kalkulator.php` memakai `daysInMonth` — bulanan satu-satunya model |
| Jatuh tempo | Selalu tanggal masuk (`Kalkulator.php:78`) |
| Denda aktif atau tidak | Selalu aktif |
| Deposit | Kolom ada, perilaku tidak ada |
| Biaya tambahan per kamar | `komponen_biaya` tanpa `kamar_id` — global |
| status kamar | Hanya `kosong` dan `terisi` |
| Laporan kerusakan | Tidak ada |

Akibatnya aplikasi hanya **benar** untuk kos bersewa bulanan, all-in, satu lokasi, tanpa klaim
kerusakan. Begitu satu ciri berbeda, aplikasi jadi salah — bukan kurang.

## Keputusan

### D-01 — Satuan sewa disimpan **per kamar**, bukan per kos

Kos nyata mencampur: kamar bulanan untuk penghuni tetap, kamar harian untuk tamu. Menaruh
satuan di `pengaturan_kos` akan salah. Kolom: `kamar.satuan_sewa` (`bulanan` | `mingguan` |
`harian`).

### D-02 — Mode harian/mingguan tidak memakai denda dan tidak memakai prorata

Bayar di muka per malam. Tidak ada tagihan bulanan, tidak ada masa tenggang, tidak ada denda
berjalan. Karena itu dua mode ini **hampir tidak bersinggungan** — bukan "denda harus jalan di
dua model". `Kalkulator::hitungSewaBulanPertama` tetap utuh sebagai fungsi murni; yang
ditambahkan adalah fungsi murni baru di sebelahnya.

### D-03 — R-01 tidak ditulis ulang

`hitungSewaBulanPertama(int, int, int): int` tetap jadi satu-satunya jalur prorata bulanan.
Mode baru masuk lewat `Kalkulator::hitungSewaPeriode()` yang memilih berdasarkan satuan.
Alasan: R-01 adalah nilai jual utama proyek dan punya 22 tes batas. Menulis ulang berisiko
tinggi, menambah di sebelahnya berisiko nol.

### D-04 — Tetap satu tagihan per periode, bukan satu tagihan per malam

Sewa harian 30 malam tetap **satu** baris `tagihan` dengan `pokok = 30 x tarif`. Alasan:
unique index `(kontrak_id, periode)` tetap berlaku, `sisa` dan `pembayaran` tidak perlu diubah,
dan pemilik tidak menerima 30 baris per bulan. Konsekuensi: `periode` untuk mode harian =
tanggal mulai menginap, bukan selalu tanggal 1.

### D-05 — `komponen_biaya.kamar_id` nullable + `NULLS NOT DISTINCT`

`NULL` berarti berlaku untuk semua kamar. Tanpa `NULLS NOT DISTINCT` (PostgreSQL 15+), unique
index `(nama, berlaku_dari, kamar_id)` akan mengizinkan duplikat baris global, karena SQL
memperlakukan `NULL` sebagai selalu berbeda.

### D-06 — Laporan kerusakan dan deposit adalah **satu** alur, bukan dua tiket

Deposit tanpa kerusakan tidak punya alasan untuk dipotong. Kerusakan tanpa deposit tidak punya
sumber dana. Karena itu T14 dan T15 dikerjakan berurutan sebagai satu alur:

```
kerusakan dilaporkan -> ditentukan siapa yang menanggung
                     -> penghuni: potong deposit, atau tambah ke tagihan
                     -> pemilik : catat sebagai tanggungan pemilik, tidak menagih siapa pun
```

### D-07 — Status kamar bertambah `perbaikan`

`kamar.status_huni` dari `('kosong','terisi')` menjadi `('kosong','terisi','perbaikan')`.
Kamar yang rusak bukan "kosong" — kalau disamakan, pemilik akan menawarkannya ke calon penghuni.

### D-08 — Denda bisa dimatikan lewat pengaturan, dan pemilik bisa membebaskan denda

Dua hal berbeda:

- **Saklar global** (`pengaturan_kos.denda_aktif`): kos yang tidak mendenda sama sekali.
- **Pembebasan per tagihan** (`tagihan.denda_dibebaskan`): pemilik memaafkan denda satu tagihan.

Pembebasan disimpan sebagai fakta di baris tagihan (bukan mengubah parameter), karena denda
diturunkan saat dibaca dan tidak punya baris yang bisa dinolkan.

## Urutan pengerjaan dan alasan

| Tiket | Isi | Kenapa di sini |
|---|---|---|
| **T11** | `komponen_biaya` per kamar | Paling kecil, tidak menyentuh kalkulator, membuka jalan snapshot komponen |
| **T12** | Satuan sewa + kalkulator harian/mingguan | Inti D-01..D-04, paling berisiko, butuh T11 sudah stabil |
| **T13** | `pengaturan_kos` (jatuh tempo, denda aktif) | Bergantung pada T12 karena mode jatuh tempo hanya masuk akal setelah satuan ada |
| **T14** | Laporan kerusakan + status kamar `perbaikan` | Butuh T13 (siapa penanggung jawab) |
| **T15** | Penyelesaian deposit (potong / tagih / bebaskan) | Butuh T14 dan T13 |

## Yang sengaja TIDAK dikerjakan

| Hal | Alasan |
|---|---|
| Multi-kos / multi-pemilik | Mengubah seluruh isolasi R-07 dan setiap query. Bukan pekerjaan satu fase |
| Sub-metering listrik/air | Butuh pembacaan meteran berkala dan tarif per kWh yang berubah. Tetap di luar cakupan |
| Akuntansi / laba bersih | Bukan sasaran sistem operasional |
| Booking / uang muka daring | Modul terpisah dengan alur pembayaran sendiri |
| Tiket komplain umum | Bukan kerusakan properti; beda alur dan beda penanggung jawab |

Batas ini **tetap harus ditulis di laporan**, supaya tidak diklaim lebih dari yang ada.

## Risiko

| Risiko | Mitigasi |
|---|---|
| R-01 rusak karena perubahan kalkulator | R-01 tidak disentuh (D-03); 22 tes batasnya harus tetap hijau tanpa diubah |
| Migrasi merusak data demo | Semua migrasi baru menambah kolom nullable atau bernilai default |
| Suite hijau jadi merah dalam waktu lama | Tiap tiket ditutup hanya kalau `periksa.ps1` penuh hijau |
| Laporan makin tertunda | Tiap tiket menyumbang satu bagian laporan; tidak ada pekerjaan yang terbuang |
