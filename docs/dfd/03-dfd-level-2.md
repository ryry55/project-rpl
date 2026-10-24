# DFD Level 2 — Dekomposisi Proses 3.0 dan 4.0

Level 2 hanya dibuat untuk dua proses yang memuat aturan bisnis tidak sepele.
Proses 1.0, 2.0, dan 5.0 tidak didekomposisi karena isinya tunggal dan sudah
jelas pada level 1 — mendekomposisinya hanya akan menghasilkan gambar yang
memecah satu langkah menjadi dua tanpa menambah pemahaman.

Notasi tetap **Gane-Sarson**. Penomoran anak proses mengikuti induknya.

Satu hal yang berubah dari versi sebelumnya, dan alasannya perlu dinyatakan di
depan: **proses 4.2 dan 4.4 tidak lagi digantungkan pada kedatangan uang.**
Versi lama memodelkan rantai lurus `bukti bayar → 4.1 → 4.2 → 4.3 → 4.4`, dan
akibatnya penghuni yang menunggak tiga bulan tanpa pernah membayar sepeser pun
tidak akan pernah dihitung dendanya maupun umur tunggakannya. Itu bukan cacat
gambar, itu cacat model: dua proses yang seharusnya berjalan menurut kalender
dijadikan anak dari peristiwa pembayaran.

---

## Proses 3.0 — Terbitkan Tagihan Bulanan

```
   periode penagihan (I-4)      kontrak aktif (D2)      tarif & kebijakan (D3)
              │                        │                          │
              │                        └────────────┬─────────────┘
              │                                     ▼
              │                          ╭────────────────────╮
              └─────────────────────────►│        3.1         │
                                         │      Periksa       │
                                         │    Kelengkapan     │
                                         │     Kebijakan      │
                                         ╰─────────┬──────────╯
                                                   │
                              tidak lengkap ◄──────┤
                                    │              │ lengkap
                                    ▼              ▼
                            tolak (R-05)   ╭────────────────────╮
                            "lengkapi      │        3.2         │
                             kebijakan     │   Hitung Sewa      │
                             dulu"         │     (prorata)      │
                                           ╰─────────┬──────────╯
                                                     │ sewa terhitung
                                                     ▼
                                           ╭────────────────────╮
                                           │        3.3         │
                                           │    Tambahkan       │
                                           │   Biaya Tetap      │
                                           ╰─────────┬──────────╯
                                                     │ biaya[]
                                                     ▼
                                           ╭────────────────────╮
                                           │        3.4         │
                                           │   Susun Rincian,   │
                                           │   Total & Jatuh    │
                                           │      Tempo         │
                                           ╰─────────┬──────────╯
                                                     │ tagihan sementara
                                                     ▼
                                           ╭────────────────────╮
                                    D4 ───►│        3.5         │
                                 validasi  │      Periksa       │
                                 duplikasi │     Duplikasi      │
                                           │      Periode       │
                                           ╰─────────┬──────────╯
                                                     │
                                    ┌────────────────┴────────────────┐
                              sudah ada                         belum ada
                                    │                                 │
                                    ▼                                 ▼
                            tolak (R-02)                     simpan ke D4
                            "periode ini                     (tagihan)
                             sudah terbit"
```

### 3.1 Periksa Kelengkapan Kebijakan

| Aspek | Isi |
|---|---|
| Masukan | kebijakan berlaku untuk kamar kontrak (D3), periode |
| Keluaran | lanjut, **atau** penolakan `kebijakan_belum_lengkap` |
| Aturan | **R-05** |
| Diperiksa | `satuan_sewa`, `jatuh_tempo`, `denda` — ketiganya wajib ada dan berlaku pada periode itu |
| Titik uji | terbit sebelum kebijakan diisi; sesudah diisi; kamar yang punya penimpa kebijakan sendiri |

Diperiksa **per kamar**, bukan sekali untuk seluruh kos: satu kamar boleh punya
kebijakan penimpa (mis. kamar harian), dan kamar itu tetap wajib lengkap walau
default kos sudah terisi.

Urutannya sengaja **sebelum** pencarian tarif. Pesan yang paling bisa
ditindaklanjuti adalah "kebijakan belum diisi" — satu langkah itu memperbaiki
semua kamar sekaligus. Sebaliknya "kamar A1 belum punya tarif" untuk kos 30
kamar baru akan menuntun pemilik ke 30 pesan berbeda.

### 3.2 Hitung Sewa (prorata)

| Aspek | Isi |
|---|---|
| Masukan | kontrak aktif (`tgl_masuk`, `tarif_awal`), tarif berlaku (D3), periode, satuan sewa |
| Keluaran | sewa terhitung |
| Aturan | **R-01** |
| Rumus | `hari_ditagih = hari_dalam_bulan` bila bulan bukan bulan masuk<br>`hari_ditagih = hari_dalam_bulan − tanggal_masuk + 1` bila bulan masuk<br>`sewa = tarif × hari_ditagih / hari_dalam_bulan` untuk satuan bulanan |
| Titik uji | masuk tanggal 1 (penuh), tanggal 15, tanggal terakhir; Februari 28 hari; Februari kabisat 29 hari; bulan 31 hari |

Contoh yang wajib benar: tarif Rp 1.500.000, masuk 17 Februari 2026 (28 hari).
`hari_ditagih = 28 − 17 + 1 = 12`. `sewa = 1.500.000 × 12 / 28 = Rp 642.857`.

Prorata hanya berlaku pada **bulan penghuni masuk**. Bulan berikutnya selalu
tarif penuh — termasuk bulan ketika penghuni keluar di tengah bulan, karena
sisa bulan tetap disewa. Tidak ada prorata keluar.

Satuan `mingguan` dan `harian` memakai `hari_ditagih` yang sama, sehingga kos
mingguan pun tidak menagih sebulan penuh untuk penghuni yang masuk tanggal 28.
Yang berubah hanya cara harga diterjemahkan: `tarif × hari / 7` untuk mingguan,
`tarif × hari` untuk harian.

### 3.3 Tambahkan Biaya Tetap

| Aspek | Isi |
|---|---|
| Masukan | komponen biaya berlaku untuk kamar ini (D3) |
| Keluaran | daftar biaya[] |
| Aturan | — |
| Catatan | Komponen **tidak** diprorata: kebersihan dan parkir tidak berkurang hanya karena penghuni masuk di tengah bulan |

Komponen dicari **untuk kamar kontrak ini**, sehingga biaya yang hanya berlaku
sebagian kamar tidak bocor ke penghuni lain. Baris bernilai 0 dilewati: nol
adalah cara komponen dihentikan, bukan komponen gratis.

### 3.4 Susun Rincian, Total & Jatuh Tempo

| Aspek | Isi |
|---|---|
| Masukan | sewa terhitung, biaya[] |
| Keluaran | tagihan sementara (`rincian[]`, `pokok`, `jatuh_tempo`) |
| Aturan | — |
| Rumus | `pokok = sewa + Σ biaya`<br>`denda = 0` saat terbit<br>`jatuh_tempo` menurut cara yang dipilih: tanggal masuk, tanggal tetap, atau akhir bulan |
| Titik uji | jatuh tempo "tanggal 31" di bulan Februari (dijepit ke 28/29, bukan menggelinding ke Maret) |

Denda **selalu nol** saat terbit. Ini yang membuat label aliran O-3 di level 1
berbunyi "tagihan bulanan (pokok)" — menyebut denda di sana akan menyesatkan,
karena denda baru muncul di proses 4.0 setelah masa tenggang terlampaui.

`pokok` dibekukan sebagai angka jadi, dan `tarif_terpakai` menyimpan salinan
tarif, komponen, dan kebijakan yang dipakai. Tanpa keduanya, menaikkan tarif
bulan depan akan mengubah tagihan bulan lalu lewat join.

### 3.5 Periksa Duplikasi Periode

| Aspek | Isi |
|---|---|
| Masukan | tagihan sementara, D4 |
| Keluaran | tagihan tersimpan **atau** penolakan |
| Aturan | **R-02** |
| Perilaku | Bila `(kontrak_id, periode)` sudah ada, sistem **menolak** dan tidak menimpa. Koreksi dilakukan lewat pembatalan lalu terbit ulang, agar jejak lama tetap ada |
| Titik uji | terbit periode sama dua kali; terbit periode berbeda; terbit setelah tagihan lama dibatalkan |

Diperiksa **dua lapis**: proses ini membaca D4 lebih dulu supaya pelanggaran
ditolak dengan pesan yang bisa dijelaskan, dan unique index parsial
`(kontrak_id, periode) WHERE status <> 'dibatalkan'` menjadi penjaga terakhir
bila dua permintaan tiba bersamaan. Lapis kedua penting karena pemeriksaan
baca-lalu-tulis selalu punya celah balapan; lapis pertama penting karena galat
basis data bukan pesan yang bisa dibaca pemilik kos.

**Sejak REV-FIN-02 index penjaganya parsial.** Syaratnya bukan lagi "tidak ada
baris dengan periode itu", melainkan "tidak ada baris **hidup** dengan periode
itu". Konsekuensinya: periode yang tagihannya sudah dibatalkan bisa diterbitkan
ulang, sementara dua tagihan hidup untuk satu periode tetap ditolak. Tanpa
kelonggaran ini, satu tagihan yang salah terbit mengunci periode itu selamanya —
dan pemilik tidak punya jalan lain selain menghapus baris, yang akan menghapus
nomor tagihan yang mungkin sudah disebut ke penghuni.

Karena itu **pembatalan tagihan (REV-FIN-02) adalah pasangan dari proses ini**,
bukan proses tersendiri. Ia tidak dihitung sebagai sub-proses di sini karena
tidak menerbitkan apa pun; ia hanya membebaskan periode yang dikunci 3.5.

| Aspek | Isi |
|---|---|
| Masukan | id tagihan, alasan pembatalan |
| Keluaran | D4 dengan `status = 'dibatalkan'` |
| Aturan | **REV-FIN-02** |
| Perilaku | Status berubah, **baris tidak dihapus**. `alasan_batal`, `dibatalkan_oleh`, dan `dibatalkan_pada` diisi. Tagihan yang sudah menerima uang **ditolak** — jalan yang benar adalah menarik pembayarannya lebih dulu lewat baris pembalik |
| Titik uji | batal tanpa alasan; batal tagihan berbayar; terbit ulang periode yang sudah dibatalkan; batal dua kali |

Pembatalan bukan penghapusan karena tiga hal yang harus tetap bisa dijawab:
nomor tagihan yang pernah disebut ke penghuni, alasan kenapa angkanya tidak
ditagih lagi, dan siapa yang memutuskan. Menghapus baris menjawab ketiganya
dengan "tidak tahu".

Tagihan yang sudah menerima uang ditolak **bukan karena teknis**, melainkan
karena membatalkannya akan membuat uang yang sudah tercatat menggantung pada
tagihan yang tidak lagi ditagih. Urutannya harus: balik pembayaran (uang keluar
dari tagihan itu, tetap tercatat sebagai baris negatif), lalu batalkan.

---

## Proses 4.0 — Catat Pembayaran, Denda & Tunggakan

Proses ini punya **dua jalur yang tidak saling menunggu**:

- **jalur peristiwa** — berjalan saat uang masuk (4.1 → 4.3);
- **jalur kalender** — berjalan kapan pun tagihan dibaca, tanpa menunggu uang (4.2 → 4.4).

```
   konfirmasi setoran (I-5)      pencatatan tunai (I-7)
              │                            │
              └─────────────┬──────────────┘
                            ▼
                    ╭───────────────╮
             D4 ───►│      4.1      │◄─── D5
       tagihan belum│     Catat     │     riwayat
            lunas   │   Pembayaran  │     pembayaran
                    ╰───────┬───────╯
                            │ pembayaran tersimpan
                            ▼
                    ╭───────────────╮
          4.2 ─────►│      4.3      │
      denda berjalan│   Perbarui    │
                    │ Status Tagihan│
                    ╰───────┬───────╯
                            │ status, total_final
                            ▼
                        D4, D6

   ═══════════ jalur kalender — tidak menunggu pembayaran ═══════════

      D4 tagihan          D3 kebijakan denda
           │                       │
           └───────────┬───────────┘
                       ▼
               ╭───────────────╮
               │      4.2      │
               │    Hitung     │
               │     Denda     │
               ╰───────┬───────╯
                       │ denda hari ini
                       ▼
               ╭───────────────╮
          D4 ─►│      4.4      │
       tagihan │  Hitung Umur  │
      belum lunas  Tunggakan   │
               ╰───────┬───────╯
                       │ kategori_umur
                       ▼
                   D6 / proses 5.0
```

### 4.1 Catat Pembayaran

| Aspek | Isi |
|---|---|
| Masukan | konfirmasi setoran (I-5), pencatatan tunai (I-7), tagihan belum lunas (D4), riwayat pembayaran (D5) |
| Keluaran | pembayaran tersimpan di D5 |
| Aturan | jumlah harus > 0; tidak boleh melebihi sisa tagihan (kelebihan **ditolak**, bukan disimpan sebagai saldo); metode wajib dikenal; tunai wajib menyebut pemegang uang |
| Titik uji | jumlah 0, jumlah negatif, jumlah = sisa, jumlah > sisa, tunai tanpa `diterima_oleh` |

Status tagihan diturunkan dari `SUM(pembayaran.jumlah)`, tidak pernah
ditambahkan sedikit-sedikit. Kolom `tagihan.status` hanya simpanan untuk
kemudahan query; sumber kebenarannya adalah jumlah uang yang benar-benar
tercatat. Menambah status secara inkremental akan salah begitu ada satu jalur
yang lupa memperbaruinya, dan tidak ada cara mendeteksinya.

Baris tagihan dikunci `FOR UPDATE` sebelum sisa diperiksa. Tanpa kunci, dua
pembayaran yang tiba bersamaan sama-sama membaca sisa Rp 1.000.000, sama-sama
menilai "tidak melebihi sisa", dan sama-sama menulis.

### 4.2 Hitung Denda

| Aspek | Isi |
|---|---|
| Masukan | tagihan (pokok, jatuh_tempo, status), bentuk denda dari dokumen beku tagihan, tanggal hari ini |
| Keluaran | denda hari ini |
| Aturan | **R-03** |
| Rumus | `denda = 0` bila `selisih ≤ masa_tenggang`<br>`hari_denda = selisih − masa_tenggang`<br>`denda = min(bulat(pokok × hari_denda × tarif_persen / 100), bulat(pokok × plafon_persen / 100))` |
| Titik uji | selisih hari 7 → denda 0; hari 8 → denda 1 hari; hari saat plafon tercapai; hari setelah plafon tetap di plafon |

Contoh dengan pokok Rp 1.500.000, tarif denda **1% per hari**, tenggang 7 hari, plafon 30%:

| Selisih (hari) | Hari kena denda | Denda |
|---|---|---|
| 5 | 0 | Rp 0 |
| 7 | 0 | Rp 0 |
| **8** | 1 | **Rp 15.000** |
| 15 | 8 | Rp 120.000 |
| 30 | 23 | Rp 345.000 |
| **37** | **30** | **Rp 450.000** (plafon tercapai) |
| 90 | 30 | Rp 450.000 (tetap) |

Karena tarif 1% per hari dan plafon 30%, plafon selalu tercapai tepat pada
**hari denda ke-30**, yaitu **hari terlambat ke-37**. Denda dihitung sekali
jalan, bukan diakumulasi per hari, supaya pembulatan tidak menumpuk.

Tiga hal yang menentukan kebenaran angka di sini:

1. **Bentuk denda dibaca dari dokumen beku tagihan, bukan dari kebijakan
   terkini.** Denda adalah satu-satunya nilai di sistem ini yang dihitung ulang
   setiap kali dibaca. Kalau bentuknya dibaca dari `kebijakan_kos` terkini,
   pemilik yang menaikkan denda bulan depan akan mengubah denda tagihan bulan
   lalu.
2. **Dasarnya `pokok` yang dibekukan, bukan sisa tagihan.** Penghuni yang sudah
   membayar sebagian tetap dikenai denda dari pokok penuh. Kalau dasarnya sisa,
   penghuni bisa menurunkan denda dengan membayar Rp 1.
3. **Tagihan lunas atau dibatalkan berhenti berjalan.** Tanpa pemeriksaan ini,
   tagihan yang dibayar pada hari ke-100 akan tetap menampilkan denda 30%.

**Sejak REV-FIN-07 denda juga berhenti pada hari pokoknya lunas, bukan hanya
pada hari tagihannya lunas.** Ini berbeda dari poin 3 dan perlu dijelaskan
karena keduanya mudah tertukar:

| Keadaan | Denda |
|---|---|
| Tagihan `lunas` (pokok + denda terbayar) | 0, dan `total_final` sudah dibekukan |
| Tagihan `sebagian`, tetapi **pokoknya** sudah tertutup penuh | **dibekukan** pada tanggal pokok lunas, bukan 0 |
| Tagihan `dibatalkan` | 0 |

Alasannya: penghuni yang sudah melunasi pokok tetapi belum membayar dendanya
tidak lagi menunda uang sewa. Membiarkan denda terus bertambah membuat satu
minggu keterlambatan menjadi utang yang tumbuh selamanya, dan tidak ada angka
yang bisa disepakati saat pemilik menagih. Karena itu `PenghitungDenda` mencari
tanggal pokok lunas (`Tagihan::tanggalPokokLunas()`) dan memakai
`min(hari_ini, tanggal_pokok_lunas)` sebagai acuan — bukan menghapus dendanya.

**Denda yang berhenti bukan denda yang dimaafkan.** Dua hal berbeda:

- **Berhenti** karena pokok lunas — keputusan sistem, tidak butuh persetujuan,
  dan `denda_sebelum_maaf` tidak berubah.
- **Dimaafkan** oleh pemilik (`POST /tagihan/{id}/maaf-denda`) — keputusan
  manusia, `peran:pemilik`, wajib beralasan, dan disimpan di kolom
  `denda_dimaafkan`. `denda` yang dibaca layar adalah nilai **neto**, sementara
  `denda_sebelum_maaf` tetap dikirim supaya penghuni bisa melihat dendanya
  pernah ada.

Memaafkan bukan menghapus jejak: `denda_dimaafkan` adalah kolom pada baris
tagihan yang sama, sehingga setiap penjumlahan denda (`total()`,
`totalDibebankan()`, rekap, piutang) otomatis ikut mengurangi tanpa ada tempat
yang bisa terlupa.

Bentuk denda tidak selalu persen harian. Yang dikenal sistem ada empat:
`persen_harian`, `persen_bulanan` (persen per bulan keterlambatan, sebulan
dihitung penuh), `nominal_tetap` (angka rupiah tetap per bulan), dan
`tanpa_denda` (nol, dan **bukan** kesalahan).

### 4.3 Perbarui Status Tagihan

| Aspek | Isi |
|---|---|
| Masukan | tagihan, total pembayaran terkumpul, denda berjalan dari 4.2 |
| Keluaran | `status_tagihan` baru, `total_final` bila lunas |
| Aturan | **R-07** |
| Transisi | `belum_bayar` → `sebagian` bila 0 < dibayar < total<br>`sebagian` → `lunas` bila dibayar ≥ total<br>`lunas` bersifat final — koreksi lewat pembatalan |
| Titik uji | bayar sebagian dua kali; bayar tepat sisa; coba bayar tagihan yang sudah lunas; coba bayar tagihan yang dibatalkan |

Saat tagihan menjadi lunas, `total_final` dibekukan **tepat sekali**. Sesudah
itu denda berhenti berjalan, jadi tanpa pembekuan angka "total" akan turun
menjadi sebesar pokok saja sementara "dibayar" tetap sebesar pokok + denda —
dua angka yang bertentangan di satu layar.

Setiap perubahan status menulis baris **baru** di D6, bukan menimpa baris lama.
`log_audit` bersifat tambah-saja dan dijaga trigger basis data, bukan hanya
kode.

### 4.4 Hitung Umur Tunggakan

| Aspek | Isi |
|---|---|
| Masukan | tagihan berstatus belum lunas dan belum dibatalkan (D4), denda berjalan dari 4.2, tanggal hari ini |
| Keluaran | `kategori_umur` per tagihan + total per kelompok |
| Aturan | **R-04** |
| Rumus | `hari_terlambat = hari_ini − jatuh_tempo` (selisih tanggal kalender, zona `Asia/Jakarta`) |
| Kelompok | `belum_jatuh_tempo` bila `hari_terlambat < 0`<br>`0_30` bila `0 ≤ hari_terlambat ≤ 30`<br>`31_60` bila `31 ≤ hari_terlambat ≤ 60`<br>`lebih_60` bila `hari_terlambat > 60` |
| Titik uji | hari −1 (belum jatuh tempo), 0, 30 / 31, 60 / 61 |

Batasnya inklusif di kedua ujung: hari ke-30 masih `0_30`, hari ke-31 sudah
`31_60`. Pergeseran satu hari di sini berarti pemilik menagih orang di kelompok
yang salah.

**Kelompok `belum_jatuh_tempo` wajib ada, dan versi lama salah di sini.**
Rumus lama berbunyi `0-30 bila umur ≤ 30`, yang memasukkan `umur < 0` ke
kelompok penunggak. Akibatnya daftar tunggakan berisi orang yang belum
waktunya bayar, dan angka "jumlah penunggak" tidak bisa dipercaya. Orang yang
belum jatuh tempo bukan penunggak.

Kelompok kosong tetap dikembalikan. Pemilik perlu melihat "0 tagihan di atas
60 hari" sebagai kabar baik, bukan sebagai kelompok yang hilang.

Denda dan umur **dihitung saat dibaca**, tidak disimpan. Karena itu proses ini
tidak menulis ke D4 dan tidak butuh penjadwal: setiap kali layar tunggakan
dibuka, angkanya adalah angka hari itu.

---

## Ringkasan jumlah

| Tingkat | Jumlah |
|---|---|
| Proses level 1 | 5 |
| Sub-proses level 2 | 9 (3.1–3.5, 4.1–4.4) |
| Penyimpanan data | 6 |
| Entitas eksternal | 3 |
| Aturan bisnis | 7 + 4 temuan audit R3 |

Sembilan sub-proses inilah yang menjadi dasar penyusunan tabel pengujian black
box: setiap sub-proses menyumbang minimal dua titik batas, sehingga totalnya
lebih dari cukup untuk membuktikan sistem diuji, bukan hanya didemokan.

**Pembatalan tagihan (REV-FIN-02) sengaja tidak dihitung sebagai sub-proses
tersendiri.** Ia tidak menerbitkan apa pun — ia hanya membebaskan periode yang
dikunci 3.5, dan menggambarnya sebagai kotak terpisah akan menyiratkan bahwa ia
punya aturan hitung sendiri. Uraiannya ada di dalam §3.5 di atas.

## Yang sengaja tidak didekomposisi

| Proses | Alasan |
|---|---|
| 1.0 Kelola Kamar, Penghuni & Kontrak | Isinya satu langkah simpan dengan pemeriksaan ketersediaan kamar; dekomposisinya akan berbunyi "periksa lalu simpan" — dua kotak untuk satu pikiran |
| 2.0 Kelola Tarif, Kebijakan & Komponen | Sama, ditambah alur usulan pengelola yang sudah dijelaskan aliran I-3/I-6/O-2 di level 1 |
| 5.0 Laporan & Rekap | Hanya membaca; tidak ada aturan hitung baru di dalamnya selain yang sudah ada di 3.0 dan 4.0 |

Yang **belum** punya proses sendiri: pemotongan deposit karena kerusakan
(R-05 versi lama, tiket T8). Deposit dicatat saat kontrak dibuat — besarannya
dibaca dari kebijakan kos — tetapi alur pemotongan dan pengembaliannya belum
dikerjakan. Menggambarnya di sini akan menjanjikan fitur yang tidak bisa
ditunjukkan saat demo.
