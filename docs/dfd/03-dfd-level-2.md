# DFD Level 2 — Dekomposisi Proses 3.0 dan 4.0

Level 2 hanya dibuat untuk dua proses yang memuat aturan bisnis tidak sepele.
Proses 1.0, 2.0, dan 5.0 tidak didekomposisi karena isinya tunggal dan sudah
jelas pada level 1 — mendekomposisinya hanya akan menghasilkan gambar yang
memecah satu langkah menjadi dua tanpa menambah pemahaman.

Notasi tetap **Gane-Sarson**. Penomoran anak proses mengikuti induknya.

---

## Proses 3.0 — Terbitkan Tagihan Bulanan

```
              kontrak aktif (dari D2)        tarif berlaku (dari D3)
                        │                              │
                        ▼                              ▼
                ╭───────────────╮            ╭──────────────────╮
                │      3.1      │            │       3.2        │
                │    Hitung     │            │   Tambahkan      │
                │    Sewa       │            │  Biaya Tambahan  │
                │  (prorata)    │            │      Tetap       │
                ╰───────┬───────╯            ╰────────┬─────────╯
                        │  sewa terhitung             │ biaya[]
                        └──────────────┬──────────────┘
                                       ▼
                              ╭─────────────────╮
                              │       3.3       │
                              │ Susun Rincian,  │
                              │  Total & Jatuh  │
                              │     Tempo       │
                              ╰────────┬────────╯
                                       │ tagihan sementara
                                       ▼
                              ╭─────────────────╮
                              │       3.4       │
                              │     Periksa     │
                              │    Duplikasi    │
                              │     Periode     │
                              ╰────────┬────────╯
                                       │
                          ┌────────────┴────────────┐
                          │                         │
                   sudah ada                  belum ada
                          │                         │
                          ▼                         ▼
                  tolak + pesan              simpan ke D4
                  "tagihan periode           (tagihan)
                   ini sudah terbit"
```

### 3.1 Hitung Sewa (prorata)

| Aspek | Isi |
|---|---|
| Masukan | kontrak aktif (tgl_masuk, tarif_dasar), periode |
| Keluaran | sewa terhitung |
| Aturan | **R-01** |
| Rumus | `sewa = tarif_dasar` bila tgl_masuk ≤ awal periode<br>`sewa = tarif_dasar × hari_tinggal / hari_dalam_bulan` bila tgl_masuk di tengah periode |
| Titik uji | masuk tanggal 1 (penuh), tanggal 15, tanggal terakhir; Februari 28 hari; Februari kabisat 29 hari; bulan 31 hari |

Contoh yang wajib benar: tarif Rp 1.500.000, masuk 17 Februari 2026 (28 hari).
`hari_tinggal = 28 − 17 + 1 = 12`. `sewa = 1.500.000 × 12 / 28 = Rp 642.857`.

### 3.2 Tambahkan Biaya Tambahan Tetap

| Aspek | Isi |
|---|---|
| Masukan | komponen biaya dari D3, kontrak |
| Keluaran | daftar biaya[] |
| Aturan | — |
| Catatan | Biaya ber-satuan `rupiah/orang` dikalikan jumlah penghuni kamar; `rupiah/bulan` dipakai apa adanya |

### 3.3 Susun Rincian, Total & Jatuh Tempo

| Aspek | Isi |
|---|---|
| Masukan | sewa terhitung, biaya[] |
| Keluaran | tagihan sementara (rincian[], pokok, total, jatuh_tempo) |
| Aturan | — |
| Rumus | `pokok = sewa + Σ biaya`<br>`total = pokok` (denda = 0 saat penerbitan)<br>`jatuh_tempo = tanggal_1 + 9 hari` (dapat diubah Pemilik) |

### 3.4 Periksa Duplikasi Periode

| Aspek | Isi |
|---|---|
| Masukan | tagihan sementara, D4 |
| Keluaran | tagihan tersimpan **atau** penolakan |
| Aturan | **R-02** |
| Perilaku | Bila `(kontrak_id, periode)` sudah ada, sistem **menolak** dan tidak menimpa. Koreksi dilakukan lewat pembatalan lalu terbit ulang, agar jejak lama tetap ada |
| Titik uji | terbit periode sama dua kali; terbit periode berbeda; terbit setelah tagihan lama dibatalkan |

---

## Proses 4.0 — Catat Pembayaran, Denda & Tunggakan

```
   bukti pembayaran (Penghuni)      konfirmasi setoran (Pemilik)
              │                                │
              └──────────────┬─────────────────┘
                             ▼
                    ╭─────────────────╮
                    │       4.1       │
                    │   Verifikasi    │
                    │  Bukti & Catat  │
                    │   Pembayaran    │
                    ╰────────┬────────╯
                             │ pembayaran tersimpan
                             ▼
                    ╭─────────────────╮
                    │       4.2       │
                    │     Hitung      │
                    │     Denda       │
                    ╰────────┬────────╯
                             │ denda
                             ▼
                    ╭─────────────────╮
                    │       4.3       │
                    │   Perbarui      │
                    │ Status Tagihan  │
                    ╰────────┬────────╯
                             │ status baru
                             ▼
                    ╭─────────────────╮
                    │       4.4       │
                    │  Hitung Umur    │
                    │    Tunggakan    │
                    ╰────────┬────────╯
                             │
                             ▼
                    kategori_umur + log audit → D6
```

### 4.1 Verifikasi Bukti & Catat Pembayaran

| Aspek | Isi |
|---|---|
| Masukan | bukti pembayaran, konfirmasi setoran, tagihan belum lunas |
| Keluaran | pembayaran tersimpan di D5 |
| Aturan | jumlah harus > 0; tidak boleh melebihi sisa tagihan (kelebihan ditolak, bukan disimpan sebagai saldo) |
| Titik uji | jumlah 0, jumlah negatif, jumlah = sisa, jumlah > sisa |

### 4.2 Hitung Denda

| Aspek | Isi |
|---|---|
| Masukan | tagihan (pokok, jatuh_tempo), parameter denda dari D3, tanggal hari ini |
| Keluaran | denda |
| Aturan | **R-03** |
| Rumus | lihat kamus data pada `README.md` |
| Titik uji | selisih hari 7 → denda 0; hari 8 → denda 1 hari; hari saat plafon tercapai; hari setelah plafon tetap di plafon |

Contoh dengan pokok Rp 1.500.000, tarif denda Rp 10.000/hari, tenggang 7 hari, plafon 30%:

| Selisih (hari) | Hari kena denda | Denda |
|---|---|---|
| 5 | 0 | Rp 0 |
| 7 | 0 | Rp 0 |
| **8** | 1 | **Rp 10.000** |
| 30 | 23 | Rp 230.000 |
| 45 | 38 | Rp 380.000 |
| **46** | 39 | **Rp 450.000** (plafon) |
| 90 | 83 | Rp 450.000 |

### 4.3 Perbarui Status Tagihan

| Aspek | Isi |
|---|---|
| Masukan | tagihan, total pembayaran terkumpul |
| Keluaran | status_tagihan baru |
| Aturan | **R-05**, **R-07** |
| Transisi | `belum_bayar` → `sebagian` bila 0 < dibayar < total<br>`sebagian` → `lunas` bila dibayar ≥ total<br>`lunas` bersifat final — koreksi lewat pembatalan |
| Catatan | Setiap perubahan status menulis baris baru di D6, bukan menimpa baris lama |

### 4.4 Hitung Umur Tunggakan

| Aspek | Isi |
|---|---|
| Masukan | tagihan berstatus belum lunas, tanggal hari ini |
| Keluaran | kategori_umur |
| Aturan | **R-04** |
| Rumus | `umur = hari_ini − jatuh_tempo`<br>`0-30` bila umur ≤ 30<br>`31-60` bila 31 ≤ umur ≤ 60<br>`>60` bila umur > 60 |
| Titik uji | umur 30 → `0-30`; umur 31 → `31-60`; umur 60 → `31-60`; umur 61 → `>60` |

---

## Ringkasan jumlah

| Tingkat | Jumlah |
|---|---|
| Proses level 1 | 5 |
| Sub-proses level 2 | 8 (3.1–3.4, 4.1–4.4) |
| Penyimpanan data | 6 |
| Entitas eksternal | 2 |
| Aturan bisnis | 7 |

Delapan sub-proses inilah yang menjadi dasar penyusunan tabel pengujian black box:
setiap sub-proses menyumbang minimal dua titik batas, sehingga totalnya lebih dari
cukup untuk membuktikan sistem diuji, bukan hanya didemokan.
