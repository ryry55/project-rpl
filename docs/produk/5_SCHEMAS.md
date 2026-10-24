# 5. Schemas

> Dokumen ini menjawab: **bentuk data yang sebenarnya, dan penjaga apa yang
> menegakkannya.**
>
> Seluruh isi di bawah diambil dari database yang berjalan
> (`pg_attribute`, `pg_constraint`, `pg_indexes`, `information_schema.triggers`),
> bukan dari niat. Kalau dokumen ini berbeda dengan migrasi, migrasi yang benar
> — dan dokumen ini yang harus diperbaiki.

Versi 1.0 · 26 Oktober 2026 · PostgreSQL 16

---

## 1. Gambaran

**22 tabel** (di luar tabel infrastruktur Laravel: `cache`, `cache_locks`,
`jobs`, `job_batches`, `failed_jobs`, `migrations`, `password_reset_tokens`,
`personal_access_tokens`, `sessions`). Dua puluh satu di antaranya adalah tabel
domain yang diuraikan di §2; yang kedua puluh dua adalah `notifications` milik
kanal in-app bawaan Laravel.

Total di skema `public`: **31 tabel**.

```
                    ┌──────────┐
                    │  users   │  peran: pemilik | pengelola | penghuni
                    └────┬─────┘
          ┌──────────────┼──────────────┬────────────────┐
          │              │              │                │
   ┌──────▼──────┐ ┌─────▼──────┐ ┌─────▼──────┐ ┌───────▼────────┐
   │ pengelola_  │ │ token_akun │ │persetujuan_│ │pengaturan_     │
   │ kewenangan  │ │            │ │   pdp      │ │ notifikasi     │
   └─────────────┘ └────────────┘ └────────────┘ └────────────────┘
          │
   ┌──────▼──────┐        ┌────────────┐
   │  penghuni   │───────▶│  kontrak   │
   └─────────────┘        └─────┬──────┘
                                │
                          ┌─────▼──────┐        ┌──────────────┐
                          │  tagihan   │───────▶│  pembayaran  │
                          └─────┬──────┘        └──────┬───────┘
                                │                      │
                    ┌───────────▼────┐      ┌──────────▼─────────┐
                    │tagihan_rincian │      │verifikasi_pembayaran│
                    └────────────────┘      └────────────────────┘

   ┌──────┐        ┌───────────┐      ┌──────────────┐
   │kamar │───────▶│tarif_sewa │      │kebijakan_kos │ (kamar_id NULL =
   └──┬───┘        └───────────┘      └──────────────┘  seluruh kos)
      │
      ├──▶ komponen_biaya    (yang DITAGIH, append-only)
      ├──▶ kamar_fasilitas ──▶ fasilitas   (yang DIMILIKI, bukan tagihan)
      └──▶ aturan_kos        (dibaca penghuni di portal)

   ┌───────────────┐        ┌──────────┐
   │usulan_perubahan│        │log_audit │ (append-only, seluruh entitas)
   └───────────────┘        └──────────┘

   ┌──────────┐
   │ rekening │  tujuan pembayaran (transfer / QRIS), bukan angka tagihan
   └──────────┘
```

### 1.1 Pemisahan yang paling mudah salah

| Konsep | Tabel | Ditagihkan? |
|---|---|---|
| **Fasilitas** — kamar ini *punya* apa | `fasilitas` + `kamar_fasilitas` | **Tidak.** Hanya dicatat dan ditampilkan |
| **Komponen biaya** — kamar ini *ditagih* apa | `komponen_biaya` | **Ya.** Satu-satunya sumber nominal tambahan di tagihan |

Fasilitas bisa diubah dan dihapus (bukan append-only). Komponen biaya
append-only, karena ia menyentuh uang.

---

## 2. Tabel

### 2.1 `users`

Akun untuk ketiga peran.

| Kolom | Tipe | Null | Bawaan |
|---|---|---|---|
| `id` | bigint | tidak | serial |
| `name` | varchar(255) | tidak | |
| `email` | varchar(255) | tidak | unique |
| `email_verified_at` | timestamp(0) | ya | **penanda "sudah diaktifkan"** |
| `password` | varchar(255) | tidak | |
| `peran` | varchar(20) | tidak | `'penghuni'` |
| `penghuni_id` | bigint | ya | FK → `penghuni`, SET NULL |
| `pemilik_id` | bigint | ya | FK → `users`, SET NULL |
| `wajib_ganti_sandi` | boolean | tidak | `false` |

**CHECK:**

```sql
users_pengelola_wajib_pemilik:
  peran <> 'pengelola' OR pemilik_id IS NOT NULL
```

Pengelola **wajib** terikat ke pemiliknya. Tanpa ini, pengelola yatim bisa
melihat data kos mana pun.

**Indeks:** `email` (unique), `peran`, `pemilik_id`.

**Catatan penting.** `email_verified_at` bukan sekadar verifikasi surel — ia
**penanda aktivasi akun**. Akun dibuat dengan sandi acak yang tidak pernah
diketahui siapa pun; aktivasi berarti pengguna menetapkan sandi sendiri. Login
sebelum `email_verified_at` terisi → 403 "Akun ini belum diaktifkan".

---

### 2.2 `penghuni`

| Kolom | Tipe | Null | Catatan |
|---|---|---|---|
| `id` | bigint | tidak | |
| `nama` | varchar(120) | tidak | |
| `email` | varchar(120) | ya | unique |
| `no_hp` | varchar(30) | ya | |

`email` boleh NULL (penghuni tanpa akun), tetapi bila ada harus unik. Saat
anonimisasi PDP, `nama` → `'pengguna dihapus'`, `email`/`no_hp` → NULL.

---

### 2.3 `kamar`

| Kolom | Tipe | Null | Bawaan |
|---|---|---|---|
| `id` | bigint | tidak | |
| `nomor` | varchar(20) | tidak | unique |
| `tipe` | varchar(40) | ya | |
| `status_huni` | varchar(255) | tidak | `'kosong'` |
| `catatan` | text | ya | |

**CHECK:** `status_huni IN ('kosong', 'terisi')`

`status_huni` **diturunkan** dari ada tidaknya kontrak aktif, bukan diisi
manual. Diisi manual berarti bisa berbeda dari kenyataan.

---

### 2.4 `tarif_sewa` — append-only

| Kolom | Tipe | Null |
|---|---|---|
| `id` | bigint | tidak |
| `kamar_id` | bigint | tidak |
| `nilai` | numeric(14,0) | tidak |
| `berlaku_dari` | date | tidak |

**UNIQUE:** `(kamar_id, berlaku_dari)`
**FK:** `kamar_id` → `kamar` **RESTRICT** (sejak R4, REV-DAT-01)
**TRIGGER:** `tarif_sewa_append_only` — menolak UPDATE dan DELETE

`RESTRICT`, bukan `CASCADE`. Cascade-nya dulu **tidak pernah bisa berjalan**:
trigger append-only menolak `DELETE` di tengah transaksi, sehingga
`DELETE FROM kamar` gagal dengan pesan yang menyebut `tarif_sewa` alih-alih
kamar. `RESTRICT` menolak lebih awal dan menyebut tabel yang benar. Yang lebih
penting, cascade pada tabel riwayat adalah janji yang bohong — ia menyatakan
"menghapus kamar membersihkan riwayatnya", padahal yang terjadi adalah
transaksi gagal. Kamar yang sudah punya riwayat tarif karena itu **tidak bisa
dihapus**; nomor yang salah ketik diperbaiki lewat `PUT /kamar/{kamar}`.

Tarif berlaku adalah baris dengan `berlaku_dari` terbesar yang ≤ tanggal yang
diminta. Tidak ada kolom "berlaku sampai" — ia **diturunkan** dari baris
berikutnya.

---

### 2.5 `komponen_biaya` — append-only

| Kolom | Tipe | Null | Bawaan |
|---|---|---|---|
| `id` | bigint | tidak | |
| `kamar_id` | bigint | ya | NULL = semua kamar |
| `nama` | varchar(80) | tidak | |
| `nilai` | numeric(14,0) | tidak | |
| `satuan` | varchar(30) | tidak | `'rupiah/bulan'` |
| `berlaku_dari` | date | tidak | |

**UNIQUE NULLS NOT DISTINCT:** `(nama, berlaku_dari, kamar_id)`

`NULLS NOT DISTINCT` (PostgreSQL 15+) membuat dua baris dengan `kamar_id` NULL
dianggap sama. Tanpa ini, unique index tidak menahan duplikat untuk komponen
global — dan duplikat berarti tagihan ganda.

**TRIGGER:** `komponen_biaya_append_only`

**FK:** `kamar_id` → `kamar` **RESTRICT** (sejak R4, REV-DAT-01). Sama seperti
`tarif_sewa`. `kamar_fasilitas` sengaja tetap `CASCADE`: ia bukan tabel riwayat,
tidak punya trigger append-only, dan pemasangan fasilitas memang wajar hilang
bersama kamarnya.

---

### 2.6 `kebijakan_kos` — append-only

Tabel terpenting di aplikasi ini. Satu baris per dimensi kebijakan.

| Kolom | Tipe | Null | Catatan |
|---|---|---|---|
| `id` | bigint | tidak | |
| `kamar_id` | bigint | ya | NULL = seluruh kos |
| `tipe` | varchar(30) | tidak | `satuan_sewa` \| `jatuh_tempo` \| `denda` \| `deposit` |
| `nilai` | varchar(30) | tidak | lihat tabel di bawah |
| `persen` | numeric(5,2) | ya | |
| `plafon_persen` | numeric(5,2) | ya | |
| `masa_tenggang` | smallint | ya | |
| `nominal` | numeric(14,0) | ya | |
| `tanggal` | smallint | ya | |
| `berlaku_dari` | date | tidak | |
| `dibuat_pada` | timestamptz(0) | tidak | `CURRENT_TIMESTAMP` |

**Nilai yang sah per tipe:**

| `tipe` | `nilai` | Kolom yang **wajib** terisi | Wajib NULL |
|---|---|---|---|
| `satuan_sewa` | `bulanan`, `mingguan`, `harian` | — | semua |
| `jatuh_tempo` | `tanggal_masuk`, `tanggal_tetap`, `akhir_bulan` | `tanggal` (1-31, hanya bila `tanggal_tetap`) | sisanya |
| `denda` | `persen_harian`, `persen_bulanan` | `persen` > 0, `plafon_persen` > 0, `masa_tenggang` ≥ 0 | `nominal`, `tanggal` |
| `denda` | `nominal_tetap` | `nominal` ≥ 0 | `persen`, `plafon_persen`, `masa_tenggang` |
| `denda` | `tanpa_denda` | — | semua |
| `deposit` | `nominal` | `nominal` ≥ 0 | sisanya |
| `deposit` | `tanpa_deposit`, `satu_bulan` | — | semua |

**Empat CHECK, dan semuanya penting:**

1. `kebijakan_kos_tipe_valid` — tipe dari daftar tertutup.
2. `kebijakan_kos_nilai_valid` — kombinasi `tipe`+`nilai` dari daftar tertutup.
3. `kebijakan_kos_kolom_sesuai_tipe` — kolom yang terisi harus **tepat** sesuai
   tipe. Ini yang mencegah baris setengah jadi seperti denda dengan `persen`
   terisi tetapi `plafon_persen` NULL.
4. `kebijakan_kos_tipe_kamar_tanggal_unique` — UNIQUE NULLS NOT DISTINCT pada
   `(tipe, kamar_id, berlaku_dari)`.

**TRIGGER:** `kebijakan_kos_append_only`

**FK:** `kamar_id` → `kamar` **RESTRICT** (sejak R4, REV-DAT-01). Kebijakan yang
berlaku untuk seluruh kos (`kamar_id` NULL) tidak terpengaruh — hanya baris yang
menunjuk satu kamar yang menahan penghapusan kamar itu.

**Kenapa satu baris per dimensi, bukan satu baris lebar.** Dengan satu baris
lebar, setiap perubahan satu dimensi (mis. hanya plafon denda) berarti menulis
ulang seluruh kebijakan — dan snapshot tagihan lama menjadi ambigu: dimensi mana
yang berubah?

---

### 2.7 `kontrak`

| Kolom | Tipe | Null | Bawaan |
|---|---|---|---|
| `id` | bigint | tidak | |
| `kamar_id` | bigint | tidak | FK RESTRICT |
| `penghuni_id` | bigint | tidak | FK RESTRICT |
| `tgl_masuk` | date | tidak | |
| `tgl_keluar` | date | ya | |
| `tarif_awal` | numeric(14,0) | tidak | **wajib saat create** |
| `deposit` | numeric(14,0) | tidak | `0` |
| `status` | varchar(255) | tidak | `'aktif'` |

**CHECK:** `status IN ('aktif', 'selesai')`

**Indeks parsial — penjaga utama:**

```sql
kontrak_satu_aktif_per_kamar:
  UNIQUE (kamar_id) WHERE status = 'aktif'
```

Satu kamar hanya boleh punya **satu** kontrak aktif. Ditegakkan database, bukan
kode — karena dua permintaan bersamaan akan lolos pemeriksaan kode.

`tarif_awal` disalin dari `tarif_sewa` saat kontrak dibuat. Ia **tidak** dipakai
untuk menghitung tagihan (yang memakai `PencariTarif`), tetapi menjadi catatan
kesepakatan awal.

---

### 2.8 `tagihan`

| Kolom | Tipe | Null | Catatan |
|---|---|---|---|
| `id` | bigint | tidak | |
| `kontrak_id` | bigint | tidak | FK RESTRICT |
| `periode` | date | tidak | tanggal 1 bulan bersangkutan |
| `pokok` | numeric(14,0) | tidak | CHECK ≥ 0 |
| `jatuh_tempo` | date | tidak | |
| `status` | varchar(255) | tidak | `belum_bayar` \| `sebagian` \| `lunas` \| `dibatalkan` |
| `tarif_terpakai` | json | tidak | **snapshot** |
| `kebijakan_terpakai` | jsonb | ya | **snapshot** |
| `tarif_denda_persen` | numeric(5,2) | ya | **snapshot** |
| `plafon_persen` | numeric(5,2) | ya | **snapshot** |
| `masa_tenggang` | smallint | ya | **snapshot** |
| `total_final` | numeric(14,0) | ya | |
| `terbit_pada` | timestamp(0) | tidak | |
| `denda_dimaafkan` | numeric(14,0) | tidak | bawaan 0; CHECK ≥ 0 |
| `alasan_maaf_denda` | varchar(500) | ya | wajib bila `denda_dimaafkan > 0` |
| `denda_dimaafkan_oleh` | bigint | ya | FK → `users` RESTRICT |
| `denda_dimaafkan_pada` | timestamp(0) | ya | |
| `alasan_batal` | varchar(500) | ya | wajib bila `status = 'dibatalkan'` |
| `dibatalkan_oleh` | bigint | ya | FK → `users` RESTRICT |
| `dibatalkan_pada` | timestamp(0) | ya | |

**UNIQUE parsial:** `(kontrak_id, periode) WHERE status <> 'dibatalkan'` —
**R-02**, penjaga tagihan ganda. Sejak REV-FIN-02 index-nya **parsial**: dua
tagihan *hidup* untuk satu periode tetap ditolak, tetapi periode yang tagihannya
sudah dibatalkan bisa diterbitkan ulang. Sebelumnya index penuh membuat satu
tagihan yang salah terbit mengunci periode itu selamanya.

**CHECK:** `tagihan_batal_ada_alasan` (`status <> 'dibatalkan' OR alasan_batal
IS NOT NULL`, `NOT VALID`), `tagihan_denda_dimaafkan_tidak_negatif`,
`tagihan_maaf_denda_ada_alasan`, `tagihan_batal_tanpa_pemaafan`.

**Indeks:** `(status, jatuh_tempo)` untuk daftar tunggakan; `(kontrak_id, status)`
— `tagihan_kontrak_status_index` — sejak R4 (REV-DAT-04).

**Kenapa `(kontrak_id, status)` ditambahkan, dan kenapa urutannya begitu.** Dua
query terpanas menyaring `kontrak_id` lebih dulu: penagihan satu kontrak
(`where kontrak_id = ? and status <> 'dibatalkan'`) dan pemeriksaan duplikat
terbit-massal. Indeks `(status, jatuh_tempo)` tidak menolong keduanya — ia
menyaring ke seluruh baris berstatus sama lalu memindai. Kolom pertama harus yang
paling selektif: satu kontrak punya puluhan baris, satu status ratusan. Indeks
lama **tidak dihapus** karena ia melayani query yang berbeda — daftar tunggakan
menyaring status lalu mengurutkan jatuh tempo.

**Kenapa pemaafan denda berupa kolom, bukan tabel tersendiri.** Satu kolom pada
baris yang sama tidak bisa terlupa saat menjumlahkan. Tabel `pemaafan_denda`
terpisah akan menuntut setiap penjumlahan denda — `total()`, `totalDibebankan()`,
rekap, piutang — untuk ikut menjumlahkannya, dan satu tempat yang terlupa
menghasilkan angka yang salah tanpa galat apa pun. Kolomnya negatif-by-design:
`denda` yang dibaca layar adalah nilai **neto**, sementara `denda_sebelum_maaf`
tetap dikirim supaya penghuni bisa melihat dendanya pernah ada.

**Kenapa pembatalan tidak menghapus baris.** Nomor tagihan yang pernah disebut
ke penghuni harus tetap bisa dijawab. Yang dibebaskan hanya periodenya, lewat
index parsial di atas.

**Kolom snapshot (R-06).** Menaikkan tarif hari ini tidak boleh mengubah tagihan
bulan lalu. Karena itu `tarif_terpakai` dan `kebijakan_terpakai` adalah salinan
nilai pada saat terbit, bukan rujukan ke baris `tarif_sewa`.

**Kenapa empat kolom denda terpisah, bukan hanya `kebijakan_terpakai`.** Tiga
kolom (`tarif_denda_persen`, `plafon_persen`, `masa_tenggang`) dipakai langsung
oleh `PenghitungDenda` di setiap perhitungan. Membacanya dari JSON berarti
parsing di jalur yang menghitung uang — dan JSON tidak punya tipe.

---

### 2.9 `tagihan_rincian`

| Kolom | Tipe | Null | Catatan |
|---|---|---|---|
| `id` | bigint | tidak | |
| `tagihan_id` | bigint | tidak | FK CASCADE |
| `nama` | varchar(80) | tidak | |
| `nilai` | numeric(14,0) | tidak | CHECK ≥ 0 |
| `urutan` | smallint | tidak | `0` |

Rincian **disalin** dari `komponen_biaya` saat terbit. Mengubah komponen biaya
tidak mengubah rincian tagihan lama.

---

### 2.10 `pembayaran` — append-only

| Kolom | Tipe | Null | Bawaan |
|---|---|---|---|
| `id` | bigint | tidak | |
| `tagihan_id` | bigint | tidak | FK |
| `pembalik_id` | bigint | ya | FK `pembayaran` RESTRICT, **unik** |
| `jumlah` | numeric(14,0) | tidak | |
| `tanggal_pembayaran` | date | tidak | |
| `catatan` | varchar(255) | ya | |
| `metode` | varchar(20) | **ya** | `tunai` \| `transfer` \| `qris` \| `gateway` |
| `sumber` | varchar(30) | tidak | `dicatat_pemilik` \| `gateway_webhook` |
| `referensi` | varchar(100) | ya | nomor transaksi gateway |
| `dicatat_oleh` | bigint | ya | FK users RESTRICT |
| `diterima_oleh` | bigint | ya | FK users RESTRICT |

**CHECK:**
- `pembayaran_metode_dikenal` — metode NULL atau dari daftar
- `pembayaran_sumber_dikenal`
- `pembayaran_tunai_ada_pemegang` — **NOT VALID**: bila metode `tunai`,
  `diterima_oleh` wajib terisi
- `pembayaran_tanda_jumlah` — `(pembalik_id IS NULL AND jumlah > 0) OR
  (pembalik_id IS NOT NULL AND jumlah < 0)`
- `pembalik_ada_alasan` — baris pembalik wajib punya `catatan` tidak kosong

**Kenapa `metode` boleh NULL di database.** Baris lama (sebelum T20) tidak punya
metode. `NOT VALID` pada CHECK tunai melakukan hal yang sama: baris lama tidak
diperiksa, baris baru diperiksa. Di API, `metode` **wajib**.

**Kenapa `dicatat_oleh`/`diterima_oleh` memakai RESTRICT, bukan SET NULL.**
Jejak uang tidak boleh menjadi yatim. Konsekuensinya: akun yang pernah menangani
uang tidak bisa dihapus — dan itu disengaja.

**Baris pembalik (REV-FIN-01).** Sejak R2 `jumlah` boleh negatif, tetapi hanya
untuk baris yang punya `pembalik_id`. Baris seperti itu adalah **pembalik**:
pernyataan bahwa uang yang pernah tercatat ditarik kembali, lengkap dengan
alasannya di `catatan`. Yang salah tetap terbaca sebagai salah; yang
ditambahkan adalah koreksinya. Karena tandanya ikut ke `SUM(jumlah)`,
`totalDibayar()`, rekap, sisa piutang, dan uang tunai di tangan semuanya tetap
benar tanpa satu pun agregat diubah.

Aturan yang dijaga basis data, bukan hanya domain:
- **satu pembayaran hanya bisa dibalik sekali** — unique index `pembalik_id`
- **pembalik tidak bisa dibalik** — trigger `pembayaran_tolak_pembalik_bertingkat`
  (CHECK tidak boleh berisi subquery)
- **pembalikan selalu penuh** — tidak ada kolom "sebagian"; koreksi nominal
  berarti balik lalu catat ulang

**TRIGGER:** `pembayaran_append_only`, `pembayaran_tolak_pembalik_bertingkat`

---

### 2.11 `verifikasi_pembayaran` — append-only

| Kolom | Tipe | Null | Bawaan |
|---|---|---|---|
| `id` | bigint | tidak | |
| `pembayaran_id` | bigint | tidak | FK |
| `diverifikasi_oleh` | bigint | ya | FK SET NULL |
| `keputusan` | varchar(10) | tidak | `terima` \| `tolak` |
| `catatan` | varchar(255) | ya | |

**CHECK:** `keputusan IN ('terima','tolak')`, dan
`tolak_beralasan` — `keputusan <> 'tolak' OR catatan IS NOT NULL`

**Satu baris = satu pemeriksaan. Yang terakhir berlaku.** Verifikasi **tidak**
mengubah `pembayaran.jumlah` maupun `tagihan.status` — ia catatan pemeriksaan,
bukan koreksi.

**TRIGGER:** `verifikasi_pembayaran_append_only`

---

### 2.12 `pengelola_kewenangan`

| Kolom | Tipe | Null |
|---|---|---|
| `id` | bigint | tidak |
| `user_id` | bigint | tidak |
| `wewenang` | varchar(40) | tidak |

**UNIQUE:** `(user_id, wewenang)`
**CHECK:** `pengelola_kewenangan_dikenal` — delapan nilai:

```
catat_pembayaran  kelola_kamar       kelola_penghuni  terbitkan_tagihan
ubah_tarif        ubah_kebijakan     verifikasi_pembayaran  baca_audit
```

Daftar ini **juga** ada di `App\Domain\Akses\Wewenang::SEMUA`. Dua tempat —
tetapi CHECK adalah penjaganya; konstanta PHP adalah sumbernya. Bila keduanya
berbeda, insert gagal dan itu ketahuan segera.

---

### 2.13 `usulan_perubahan`

Antrean kerja, **bukan** append-only.

| Kolom | Tipe | Null | Catatan |
|---|---|---|---|
| `id` | bigint | tidak | |
| `tipe` | varchar(20) | tidak | `tarif` \| `kebijakan` |
| `payload` | jsonb | tidak | |
| `ringkasan` | varchar(255) | tidak | dibekukan saat diajukan |
| `status` | varchar(20) | tidak | `menunggu` \| `disetujui` \| `ditolak` |
| `diajukan_oleh` | bigint | tidak | FK RESTRICT |
| `diputuskan_oleh` | bigint | ya | FK RESTRICT |
| `alasan` | varchar(255) | ya | |
| `dibuat_pada` | timestamp(0) | tidak | |
| `diputuskan_pada` | timestamp(0) | ya | |
| `kedaluwarsa_pada` | timestamp(0) | tidak | |

**Enam CHECK:**

| Nama | Isi |
|---|---|
| `tipe_dikenal` | `tarif` atau `kebijakan` |
| `status_dikenal` | `menunggu`/`disetujui`/`ditolak` |
| `tolak_beralasan` | `status <> 'ditolak' OR alasan IS NOT NULL` |
| `diputuskan_ada_pemutus` | `status = 'menunggu' OR diputuskan_oleh IS NOT NULL` |
| `diputuskan_ada_waktu` | `status = 'menunggu' OR diputuskan_pada IS NOT NULL` |
| `menunggu_belum_diputuskan` | `status <> 'menunggu' OR (diputuskan_oleh IS NULL AND diputuskan_pada IS NULL)` |

Enam CHECK ini menjaga satu invarian: **baris yang menunggu tidak punya
keputusan, dan baris yang sudah diputuskan punya pemutus serta waktunya.**
Tidak ada keadaan setengah jadi yang mungkin.

**`kedaluwarsa` BUKAN nilai `status` yang sah.** Kedaluwarsa **dihitung** dari
`kedaluwarsa_pada < now()`. Tidak ada cron yang menulisnya, tidak ada baris yang
berubah sendiri. `statusEfektif()` di model mengembalikan
`menunggu`/`disetujui`/`ditolak`/`kedaluwarsa`.

---

### 2.14 `log_audit` — append-only

| Kolom | Tipe | Null |
|---|---|---|
| `id` | bigint | tidak |
| `aktor_id` | bigint | ya |
| `aktor_nama` | varchar(255) | ya |
| `aksi` | varchar(255) | tidak |
| `entitas` | varchar(255) | tidak |
| `entitas_id` | bigint | ya |
| `nilai_lama` | jsonb | ya |
| `nilai_baru` | jsonb | ya |
| `dibuat_pada` | timestamptz(0) | tidak |

**Indeks:** `dibuat_pada`, `(entitas, entitas_id)`

**`aktor_nama` dibekukan.** Nama disimpan saat aksi terjadi, bukan diambil lewat
join. Kalau akun dihapus atau berganti nama, catatan sejarah tetap menyebut nama
yang berbuat saat itu.

**Sejak R4 (REV-DAT-05) data induk ikut berjejak.** Sebelumnya hanya uang dan
tarif yang tercatat, sehingga "siapa yang mengubah nomor kamar ini" tidak bisa
dijawab padahal perubahan itu menentukan tagihan bulan berikutnya. Kamar,
penghuni, kontrak, fasilitas, dan aturan kos kini menulis `log_audit` dengan pola
`<entitas>.tambah` / `.ubah` / `.hapus`. Entitasnya sudah dibedakan kolom
`entitas`, jadi yang perlu dibaca manusia adalah `nilai_lama`/`nilai_baru`.
Perpindahan `kamar.status_huni` **tidak** dicatat terpisah: ia berubah otomatis
saat kontrak dibuat atau diakhiri, dan jejaknya sudah ada di `kontrak.tambah` /
`kontrak.akhir` — mencatatnya lagi menghasilkan dua baris untuk satu kejadian.

**Pengecualian UPDATE (UU PDP).** Trigger `log_audit_append_only` mengizinkan
UPDATE **hanya** bila **semua** syarat ini terpenuhi:

1. `current_setting('kos.anonimisasi', true) = 'on'` (di-set dengan `SET LOCAL`,
   berlaku satu transaksi)
2. `aktor_id` menjadi NULL
3. `aktor_nama` menjadi `'pengguna dihapus'`
4. `aksi`, `entitas`, `entitas_id`, `nilai_lama`, `nilai_baru`, `dibuat_pada`
   **tidak berubah**

UPDATE lain tetap ditolak. Ini satu-satunya celah di seluruh skema, dan ia
selebar mungkin tidak ada.

---

### 2.15 `token_akun`

| Kolom | Tipe | Null | Catatan |
|---|---|---|---|
| `id` | bigint | tidak | |
| `user_id` | bigint | tidak | FK CASCADE |
| `jenis` | varchar(10) | tidak | `aktivasi` \| `reset` |
| `token_hash` | char(64) | tidak | SHA-256, unique |
| `kedaluwarsa_pada` | timestamptz(0) | tidak | |
| `dipakai_pada` | timestamptz(0) | ya | |
| `dibuat_pada` | timestamptz(0) | tidak | |

**CHECK:** `jenis IN ('aktivasi','reset')`
**Indeks:** `token_hash` (unique), `(user_id, jenis)`

**Token asli tidak pernah disimpan.** Hanya hash SHA-256. Kalau database bocor,
token di dalamnya tidak bisa dipakai.

Laravel punya `password_reset_tokens` bawaan — **sengaja tidak dipakai**, karena
tabel itu tidak mendukung dua jenis token dengan masa berlaku berbeda (aktivasi
7 hari, reset 60 menit) dan tidak punya kolom `dipakai_pada`.

---

### 2.16 `persetujuan_pdp` — append-only

| Kolom | Tipe | Null |
|---|---|---|
| `id` | bigint | tidak |
| `user_id` | bigint | tidak |
| `versi` | varchar(20) | tidak |
| `disetujui_pada` | timestamptz(0) | tidak |
| `ip` | varchar(45) | ya |

**UNIQUE:** `(user_id, versi)`
**TRIGGER:** `persetujuan_pdp_append_only`

Persetujuan dicatat per versi. `PersetujuanPdp::VERSI_BERLAKU = '1.0'`. Ketika
kebijakan privasi berubah, versi naik dan pengguna diminta menyetujui lagi —
tanpa menghapus catatan persetujuan versi lama.

---

### 2.17 `pengaturan_notifikasi`

| Kolom | Tipe | Null |
|---|---|---|
| `id` | bigint | tidak |
| `user_id` | bigint | tidak |
| `peristiwa` | varchar(40) | tidak |
| `kanal` | varchar(10) | tidak |
| `aktif` | boolean | tidak |

**UNIQUE:** `(user_id, peristiwa, kanal)`
**CHECK:** sembilan `peristiwa`, dua `kanal`

Sembilan peristiwa: `usulan.diajukan`, `usulan.diputuskan`, `tarif.diubah`,
`kebijakan.diubah`, `komponen.diubah`, `tagihan.massal`, `tagihan.terbit`,
`pembayaran.dicatat`, `pembayaran.diverifikasi`.

`komponen.diubah` menyusul di R2 (REV-SEC-07). Sebelumnya komponen biaya adalah
satu-satunya angka uang yang bisa berubah tanpa meninggalkan pemberitahuan -
padahal menambah komponen berarti menaikkan tagihan SETIAP penghuni bulan
berikutnya.

**Tidak adanya baris = pakai bawaan kode** (`Peristiwa::kanalBawaan()`), bukan
"mati". Ini disengaja: pengguna baru tidak perlu diberi 16 baris bawaan, dan
peristiwa baru otomatis punya perilaku tanpa migrasi data.

---

### 2.18 `fasilitas` dan `kamar_fasilitas`

`fasilitas`: `id`, `nama` (unique), `ikon`, `urutan`.
`kamar_fasilitas`: `kamar_id`, `fasilitas_id`, UNIQUE `(kamar_id, fasilitas_id)`.

**Bukan append-only.** Fasilitas adalah keterangan, bukan uang. `PUT
/kamar/{kamar}/fasilitas` mengganti seluruh daftar lewat `sync()`.

---

### 2.19 `aturan_kos`

| Kolom | Tipe | Null | Bawaan |
|---|---|---|---|
| `id` | bigint | tidak | |
| `judul` | varchar(120) | tidak | |
| `isi` | text | tidak | |
| `kategori` | varchar(40) | tidak | `'umum'` |
| `sanksi` | varchar(200) | ya | |
| `aktif` | boolean | tidak | `true` |
| `urutan` | smallint | tidak | `0` |

`kategori` adalah **string bebas** dengan saran dari server
(`SimpanAturanRequest::KATEGORI_UMUM`), bukan enum database. Alasannya: kategori
aturan kos sangat beragam dan pemilik perlu menambah yang tidak terpikirkan
pembuat aplikasi.

Aturan memakai `aktif` sebagai penanda, bukan dihapus. Portal penghuni menyaring
`aktif` **di server**, bukan di frontend.

---

### 2.20 `rekening` — tujuan pembayaran (REV-UI-05)

| Kolom | Tipe | Null | Bawaan |
|---|---|---|---|
| `id` | bigint | tidak | |
| `jenis` | varchar(20) | tidak | `'transfer'` — `transfer` \| `qris` |
| `nama` | varchar(60) | tidak | nama bank atau penyedia |
| `nomor` | varchar(60) | ya | nomor rekening / identitas merchant |
| `atas_nama` | varchar(80) | ya | |
| `catatan` | varchar(200) | ya | |
| `aktif` | boolean | tidak | `true` |
| `urutan` | smallint | tidak | `0` |

**Indeks:** `(aktif, urutan)`.

**Kenapa tabel tersendiri, bukan jenis `kebijakan_kos` baru.** Bentuk
`kebijakan_kos` adalah satu nilai pilihan ditambah kolom angka bertipe
(`persen`, `nominal`, `masa_tenggang`, `tanggal`). Nomor rekening adalah teks
bebas; menaruhnya di sana berarti menambah satu kolom `teks` yang selalu kosong
di seluruh baris jenis lain. `kebijakan_kos` juga dirancang satu baris per
dimensi, sedangkan satu kos wajar punya beberapa tujuan bayar sekaligus.

**Kenapa tidak append-only**, berbeda dari `tarif_sewa`/`kebijakan_kos`/
`komponen_biaya`. Tidak ada angka uang yang dihitung dari baris ini: mengganti
nomor rekening hari ini tidak mengubah tagihan bulan lalu, karena tagihan tidak
pernah membacanya. Yang dijaga adalah jejaknya — setiap tambah, ubah, dan hapus
menulis `log_audit` dengan salinan baris lengkap, dan itulah yang menjawab "dulu
saya transfer ke mana".

**`nomor` boleh kosong** karena QRIS statis bisa hanya berupa gambar tanpa nomor
yang perlu dibaca manusia. Memaksa kolom ini terisi membuat pemilik mengarang
angka supaya formulirnya mau tersimpan.

**Menulis hanya pemilik**, membaca juga pengelola. Nomor rekening adalah alamat
uang masuk: pengelola yang boleh menggantinya bisa mengarahkan seluruh setoran
penghuni ke rekeningnya sendiri, lalu menandai tagihannya lunas dengan wewenang
`catat_pembayaran` yang memang ia punya.

---

## 3. Trigger append-only

Delapan trigger: tujuh pasang `append_only` (UPDATE dan DELETE) ditambah satu
trigger penjaga pembalik.

| Tabel | Trigger |
|---|---|
| `tarif_sewa` | `tarif_sewa_append_only` |
| `komponen_biaya` | `komponen_biaya_append_only` |
| `kebijakan_kos` | `kebijakan_kos_append_only` |
| `log_audit` | `log_audit_append_only` |
| `pembayaran` | `pembayaran_append_only` |
| `verifikasi_pembayaran` | `verifikasi_pembayaran_append_only` |
| `persetujuan_pdp` | `persetujuan_pdp_append_only` |
| `pembayaran` (INSERT) | `pembalik_tolak_pembalik_bertingkat` — pembalik tidak boleh punya pembalik |

**Kenapa trigger, bukan hanya konvensi kode.** Konvensi dilanggar oleh kode baru
yang tidak tahu konvensinya, oleh `UPDATE` manual di `psql`, dan oleh migrasi
yang lupa. Trigger tidak.

**Kenapa bukan soft delete.** Soft delete tetap mengizinkan UPDATE pada baris
yang "dihapus" — nilai bisa diubah tanpa jejak. Trigger menutup jalan itu
sepenuhnya.

---

## 4. Indeks yang menegakkan aturan

Indeks ini bukan untuk kecepatan — ia menegakkan aturan bisnis.

| Indeks | Aturan |
|---|---|
| `kontrak_satu_aktif_per_kamar` (partial unique) | Satu kamar = satu kontrak aktif |
| `tagihan_kontrak_periode_aktif_unique` (partial unique, `WHERE status <> 'dibatalkan'`) | **R-02** — tidak ada dua tagihan *hidup* untuk satu periode; periode yang dibatalkan boleh diterbitkan ulang |
| `tarif_sewa_kamar_id_berlaku_dari_unique` | Satu tarif per kamar per tanggal |
| `kebijakan_kos_tipe_kamar_tanggal_unique` (NULLS NOT DISTINCT) | Satu kebijakan per dimensi per tanggal |
| `komponen_biaya_nama_tanggal_kamar_unique` (NULLS NOT DISTINCT) | Tidak ada komponen ganda |
| `token_akun_token_hash_unique` | Satu token, satu pemilik |
| `persetujuan_pdp_user_id_versi_unique` | Satu persetujuan per versi |

---

## 5. Pola yang dipakai berulang

### 5.1 `NULLS NOT DISTINCT`

Dipakai di `kebijakan_kos` dan `komponen_biaya`, keduanya punya `kamar_id`
nullable yang berarti "berlaku untuk semua kamar".

Pada unique index biasa, dua baris dengan `kamar_id = NULL` dianggap **berbeda**
— karena `NULL ≠ NULL`. Akibatnya duplikat komponen global lolos, dan tagihan
menjadi ganda. `NULLS NOT DISTINCT` (PostgreSQL 15+) memperbaikinya.

### 5.2 Indeks parsial untuk invarian

`UNIQUE (kamar_id) WHERE status = 'aktif'` hanya membatasi baris aktif. Kontrak
yang sudah selesai boleh berapa pun untuk kamar yang sama — dan itu memang yang
diinginkan: kamar yang sama bisa disewa bergantian sepanjang tahun.

### 5.3 Snapshot dalam JSON + kolom bertipe

`tagihan` menyimpan `tarif_terpakai` (json lengkap, untuk ditampilkan) **dan**
kolom bertipe untuk nilai yang dipakai menghitung (`tarif_denda_persen`,
`plafon_persen`, `masa_tenggang`).

JSON untuk kelengkapan bukti; kolom bertipe untuk perhitungan. Membaca angka
uang dari JSON berarti parsing di jalur yang menghitung uang.

### 5.4 Kolom yang **diturunkan**, bukan disimpan

| Nilai | Diturunkan dari | Kenapa tidak disimpan |
|---|---|---|
| `kamar.status_huni` | ada/tidaknya kontrak aktif | Kalau disimpan, bisa berbeda dari kenyataan |
| Usulan `kedaluwarsa` | `kedaluwarsa_pada < now()` | Tidak perlu cron; tidak ada baris yang berubah sendiri |
| Tarif "berlaku sampai" | baris `tarif_sewa` berikutnya | Dua kolom yang harus konsisten = satu pasti salah |
| `sudah_aktif` akun | `email_verified_at IS NOT NULL` | Satu sumber kebenaran |

---

## 6. Migrasi

**36 migrasi**, dijalankan berurutan. Tiga yang paling perlu dipahami:

| Migrasi | Isi |
|---|---|
| `..._create_kebijakan_kos_table` | Empat CHECK yang menegakkan bentuk kebijakan |
| `..._add_snapshot_to_tagihan` | Kolom snapshot R-06 |
| `..._create_append_only_triggers` | Tujuh trigger, plus pengecualian anonimisasi |

Dua migrasi R4 mengubah **kunci dan indeks**, bukan kolom:

| Migrasi | Isi |
|---|---|
| `2026_11_06_090300_restrict_delete_on_riwayat_kamar` | `tarif_sewa`, `komponen_biaya`, dan `kebijakan_kos` dilepas lalu dipasang ulang dengan `ON DELETE RESTRICT` pada `kamar_id` (REV-DAT-01). PostgreSQL tidak punya `ALTER CONSTRAINT ... ON DELETE`, jadi constraint-nya di-drop lalu dibuat ulang; namanya mengikuti konvensi Laravel. `down()` mengembalikan `CASCADE` |
| `2026_11_06_090400_add_kontrak_status_index_to_tagihan` | `tagihan_kontrak_status_index` pada `(kontrak_id, status)` (REV-DAT-04). Tidak ada backfill dan tidak ada kolom baru — aman dijalankan pada database berisi data |

**Aturan migrasi di repo ini:**

1. Migrasi **tidak pernah** diubah setelah dijalankan. Perbaikan = migrasi baru.
2. Migrasi harus aman dijalankan pada database berisi data (kecuali
   `migrate:fresh` yang hanya boleh di demo).
3. Backfill data ditulis di migrasi yang sama dengan kolomnya, bukan di seeder —
   seeder hanya untuk data demo.
4. `RUN_SEEDER=true` ditolak bila `APP_ENV=production`.

---

## 7. Data contoh (seeder)

Seeder **sengaja minimal**:

| Dibuat | Tidak dibuat |
|---|---|
| 3 akun: `pemilik@kos.test`, `pengelola@kos.test`, `penghuni@kos.test` (sandi `password`) | Kebijakan kos |
| Persetujuan PDP versi 1.0 untuk ketiganya | Kamar |
| Wewenang pengelola: 4 wewenang harian | Kontrak |
| | Tagihan |

**Kenapa kebijakan, kamar, dan kontrak tidak dibuat.** Gerbang R-05 ("lengkapi
kebijakan dulu") adalah **fitur**, dan fitur yang tidak pernah terlihat di demo
tidak terbukti ada. Kos baru memang kosong.

Pengelola sengaja **tidak** diberi `ubah_tarif` dan `ubah_kebijakan`, supaya
alur pengajuan usulan bisa didemokan tanpa mengubah data apa pun.

Ketiga akun demo punya `wajib_ganti_sandi = false` dan `email_verified_at =
now()`, supaya demo tidak terhalang gerbang aktivasi.
