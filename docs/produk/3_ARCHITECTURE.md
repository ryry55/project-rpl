# 3. Architecture

> Dokumen ini menjawab: **bagaimana sistem ini dibentuk, dan mengapa begitu.**
>
> Keputusan yang mahal dibalik dicatat beserta alternatif yang ditolak. Keputusan
> yang jelas tidak dicatat — dokumentasi yang menjelaskan hal remeh akan
> berhenti dibaca.

Versi 1.0 · 26 Oktober 2026

---

## 1. Bentuk sistem

```
┌──────────────────────────────────────────────────────────────┐
│  Peramban (ponsel / laptop)                                  │
│  Vue 3 SPA · service worker (aset statis saja)               │
└───────────────────────────┬──────────────────────────────────┘
                            │ HTTPS, satu origin
┌───────────────────────────▼──────────────────────────────────┐
│  nginx  (satu-satunya port yang terbuka)                     │
│  /          → SPA statis                                     │
│  /assets/   → cache 1 tahun, immutable                       │
│  /sw.js     → no-store                                       │
│  /api/      → proxy ke PHP                                   │
└───────────────────────────┬──────────────────────────────────┘
                            │ HTTP internal
┌───────────────────────────▼──────────────────────────────────┐
│  Laravel 12.70.1 (API-only) · PHP 8.3.36                     │
│  Controller → Domain → Eloquent → PostgreSQL                 │
└───────────────────────────┬──────────────────────────────────┘
                            │
┌───────────────────────────▼──────────────────────────────────┐
│  PostgreSQL 16                                               │
│  22 tabel · 8 trigger (7 append-only) · CHECK sebagai pagar  │
└──────────────────────────────────────────────────────────────┘

  di luar jalur utama:
  SMTP (Brevo / Mailpit)     → email notifikasi & tautan akun
  Midtrans                   → webhook pembayaran
```

**Satu origin, bukan dua.** SPA dilayani nginx yang sama dengan proxy `/api/`.
Akibatnya CORS tidak dibutuhkan pada demo, dan cookie/token tidak menyeberang
origin. Di produksi, SPA diletakkan di dalam `public/` Laravel sehingga tetap
satu layanan — karena Render gratis menghitung per layanan, dan dua layanan
berarti dua cold start ~50 detik.

---

## 2. Lapisan

Tiga lapisan, dan aturan ketergantungannya searah.

```
Controller  →  Domain  →  Model (Eloquent)
    ↓            ↓
 Request      Exception
 Resource
```

### 2.1 Controller — hanya menerjemahkan

Tugas controller: memvalidasi lewat FormRequest, memanggil satu atau dua kelas
domain, mengubah hasil menjadi Resource. **Tidak ada perhitungan di controller.**

```php
// TagihanController::store — bentuk yang benar
$hasil = $this->penerbit->terbitkan($kontrak, $periode);
return (new TagihanResource($hasil->tagihan))->response()->setStatusCode(201);
```

### 2.2 Domain — tempat semua aturan tinggal

43 kelas di `app/Domain/`, dikelompokkan per masalah, bukan per tabel:

| Folder | Isi | Contoh |
|---|---|---|
| `Billing/` | Hitung dan terbitkan tagihan, denda, tunggakan, pembatalan, pemaafan | `Kalkulator`, `PenghitungDenda`, `PenerbitTagihan`, `DaftarTunggakan`, `PembatalTagihan`, `PemaafDenda` |
| `Kebijakan/` | Kebijakan kos, deposit | `PenyelesaiKebijakan`, `PenentuDeposit`, `PilihanKebijakan` |
| `Persetujuan/` | Usulan perubahan dan pemutusnya | `PengajuUsulan`, `PemutusUsulan`, `PenerapPerubahan` |
| `Pembayaran/` | Catat, balik, dan verifikasi pembayaran; rekap | `PencatatPembayaran`, `PembalikPembayaran`, `PencatatVerifikasi`, `RekapPendapatan` |
| `Akun/` | Aktivasi, tautan, pembatas masuk | `PembuatAkun`, `TautanAkun`, `PembatasMasuk` |
| `Notifikasi/` | Peristiwa, surat, penerima | `Pemberitahu`, `Penerima`, `PengaturanKanal` |
| `Audit/` | Jejak audit | `PencatatAudit`, `NilaiLama` |
| `Akses/` | Daftar wewenang | `Wewenang` |
| `Pdp/` | Hak subjek data | `LayananPdp` |
| `Tarif/` | Pencarian tarif berlaku | `PencariTarif` |

**Kenapa kelas domain, bukan service besar atau model gemuk.**

Aturan seperti R-01 (prorata) dan R-03 (denda) dipakai dari beberapa tempat:
penerbitan satuan, penerbitan massal, simulasi denda di UI, dan tes. Kalau
aturannya tinggal di `Tagihan::hitung()`, setiap pemanggil baru akan menyalin
sedikit logika — dan salinan itu melenceng. `Kalkulator` adalah satu-satunya
tempat rumus prorata hidup, dan `PenghitungDenda` satu-satunya tempat denda
hidup.

### 2.3 Model — hanya bentuk data

Model Eloquent memuat: `$fillable`, cast, relasi, scope. **Tidak ada
perhitungan bisnis.** Satu-satunya pengecualian yang disengaja: scope isolasi
(`scopeMilikPenghuni`, `scopeTerlihatOleh`) ada di model karena ia menyangkut
query, dan menaruhnya di luar berarti setiap pemanggil harus mengingatnya.

---

## 3. Keputusan besar, dan alternatif yang ditolak

### 3.1 Kebijakan sebagai data bertipe, bukan JSON bebas

**Keputusan.** `kebijakan_kos` menyimpan satu baris per dimensi:

| `tipe` | `nilai` yang sah | Kolom terisi |
|---|---|---|
| `satuan_sewa` | `bulanan`, `mingguan`, `harian` | — |
| `jatuh_tempo` | `tanggal_masuk`, `tanggal_tetap`, `akhir_bulan` | `tanggal` (bila `tanggal_tetap`) |
| `denda` | `persen_harian`, `persen_bulanan`, `nominal_tetap`, `tanpa_denda` | `persen`, `plafon_persen`, `masa_tenggang`, `nominal` |
| `deposit` | `tanpa_deposit`, `nominal`, `satu_bulan` | `nominal` |

Kolom yang tidak relevan **wajib NULL**, ditegakkan `CHECK kebijakan_kos_kolom_sesuai_tipe`.

**Alternatif yang ditolak: JSON bebas.** Terlihat lebih fleksibel, tetapi:
- Tidak bisa di-`CHECK`. Bentuk yang salah baru ketahuan saat tagihan dihitung.
- Tidak bisa di-query. "Kos mana yang dendanya nominal tetap" jadi mustahil.
- Membuka pintu ke formula buatan pengguna, dan itu ditolak keras (lihat 3.2).

**Alternatif yang ditolak: tabel `parameter_denda` terpisah.** Sempat ada, lalu
dihapus seluruhnya. Dua tempat menyimpan bentuk denda berarti satu di antaranya
pasti tertinggal saat yang lain diubah — dan yang tertinggal akan dipakai
menghitung uang.

### 3.2 Kustomisasi = menu + angka, bukan penulisan bebas

**Keputusan.** Pemilik memilih dari daftar tipe, lalu mengisi angka. Tidak ada
kolom teks yang diartikan sebagai rumus, tidak ada JSON yang ditulis tangan.

**Kenapa.** Pemilik kos bukan pemrogram. Formulir bebas berarti hasil yang tidak
bisa diprediksi, dan ketika tagihan salah, tidak ada cara menjelaskan kenapa.
Dengan menu bertipe, setiap kombinasi bisa diuji, dan pesan galat bisa spesifik
("plafon denda harus lebih dari 0").

Ini bukan pengurangan fleksibilitas. Empat bentuk denda × tiga jatuh tempo ×
tiga satuan sewa × tiga deposit = 108 kombinasi, dan semuanya tertangani.

### 3.3 Append-only untuk data yang menyentuh uang

**Keputusan.** Tujuh tabel punya trigger yang menolak `UPDATE` dan `DELETE`:

| Tabel | Kenapa |
|---|---|
| `tarif_sewa` | Riwayat tarif adalah bukti kesepakatan |
| `komponen_biaya` | Sama |
| `kebijakan_kos` | Sama |
| `log_audit` | Bukti tidak boleh diubah |
| `pembayaran` | Uang yang sudah tercatat tidak boleh hilang diam-diam |
| `verifikasi_pembayaran` | Satu baris = satu pemeriksaan |
| `persetujuan_pdp` | Persetujuan tidak boleh dicabut diam-diam |

**Alternatif yang ditolak: `deleted_at` (soft delete).** Soft delete tetap
mengizinkan `UPDATE` pada baris yang "dihapus", sehingga nilai bisa diubah tanpa
jejak. Trigger lebih tegas: tidak ada jalan masuk.

**Konsekuensi yang diterima.** Salah ketik tarif tidak bisa diperbaiki — harus
diterbitkan tarif baru dengan `berlaku_dari` yang benar. Ini disengaja: riwayat
yang bisa diedit bukan riwayat.

**Pengecualian tunggal.** `log_audit` boleh di-`UPDATE` **hanya** saat
anonimisasi UU PDP, dan hanya bila `current_setting('kos.anonimisasi') = 'on'`,
hanya `aktor_id` → NULL dan `aktor_nama` → `'pengguna dihapus'`, dan kolom lain
tidak berubah. `SET LOCAL` membuatnya berlaku satu transaksi saja.

### 3.4 Snapshot saat terbit, bukan rujukan

**Keputusan.** `tagihan` menyimpan `tarif_terpakai` (json), `kebijakan_terpakai`
(jsonb), `tarif_denda_persen`, `plafon_persen`, `masa_tenggang` — semuanya
**salinan** nilai pada saat terbit.

**Kenapa.** Menaikkan tarif hari ini tidak boleh mengubah tagihan bulan lalu.
Tanpa snapshot, laporan bulan lalu berubah setiap kali tarif berubah, dan
pemilik tidak bisa menjawab "kenapa tagihan Mei saya segini".

**Alternatif yang ditolak: rujukan ke `tarif_sewa.id`.** Lebih "normal", tetapi
membuat laporan historis bergantung pada baris yang masih ada, dan membuka
kemungkinan tagihan lama menampilkan angka yang tidak pernah ditagihkan.

### 3.5 Isolasi di query, bukan di tampilan

**Keputusan.** Penghuni hanya bisa melihat datanya sendiri, ditegakkan di query
(`scopeMilikPenghuni`) — bukan dengan menyembunyikan di frontend.

Akses ke milik orang lain → **404**, bukan 403. 403 memberi tahu bahwa data itu
ada; 404 tidak membocorkan apa pun.

**Kenapa bukan `403`.** Perbedaan ini bukan kehalusan. 403 pada `/tagihan/999`
memberi tahu penyerang bahwa tagihan 999 ada — dan dengan mencoba 1..1000, ia
belajar berapa banyak tagihan di kos itu. 404 tidak memberi tahu apa pun.

**Pagar tambahan.** Service worker PWA **tidak menyentuh `/api/`** sama sekali.
Cache peramban dipakai bersama semua akun di perangkat yang sama; satu respons
tersimpan berarti kebocoran antar-pengguna. Dijaga `uji-pwa.mjs`.

### 3.6 Model kepemilikan: satu kos, banyak akun

**Keputusan (Model A).** Satu instalasi = satu kos. Akun bertipe:

| `peran` | Punya `pemilik_id`? | Bisa apa |
|---|---|---|
| `pemilik` | — | Semua |
| `pengelola` | **wajib** (CHECK `users_pengelola_wajib_pemilik`) | Sesuai `pengelola_kewenangan` |
| `penghuni` | — | Datanya sendiri |

**Model B (ditolak):** tabel `kos` terpisah dengan `pemilik_id` di setiap tabel
data. Menambah satu join ke setiap query dan satu dimensi ke setiap indeks,
untuk kemampuan yang tidak diminta siapa pun.

**Model C (ditolak, dengan alasan tertulis di SPEC):** multi-kos, banyak
pemilik, satu instalasi. Ini mengubah setiap layar menjadi punya pemilih kos,
setiap izin menjadi dua dimensi (kos × wewenang), dan setiap uji menjadi dua
kali lebih banyak. Pemilik yang diwawancarai punya **satu** kos.

### 3.7 Wewenang membatasi tindakan, bukan pengetahuan

**Keputusan.** `wewenang:` menjaga **tindakan**. Membaca data kos cukup dengan
`peran:pemilik,pengelola`.

| Rute | Middleware |
|---|---|
| `GET /kamar` | `peran:pemilik,pengelola` |
| `POST /kamar` | `wewenang:kelola_kamar` |
| `GET /audit-log` | `wewenang:baca_audit` ← satu-satunya yang membaca |

**Kenapa.** Pengelola yang mencatat pembayaran **harus** melihat nama penghuni
dan daftar tagihan. Menutup bacaan berarti ia tidak bisa bekerja. Yang berbahaya
adalah mengubah, bukan melihat.

**Pengecualian:** `log_audit` dibatasi bacaan, karena isinya jejak semua
tindakan semua orang — termasuk yang bukan urusannya.

**Wewenang tidak bisa didelegasikan.** Tidak ada wewenang `ubah_kewenangan`.
Memberi wewenang adalah tindakan pemilik, titik.

### 3.8 Persetujuan pemilik untuk tindakan sensitif

**Keputusan.** `wewenang:ubah_tarif` dan `wewenang:ubah_kebijakan` berarti
**"boleh mengusulkan"**. Endpoint-nya **sama**:

| Pemanggil | Respons | Efek |
|---|---|---|
| Pemilik | `201` + `meta.diajukan = false` | Langsung berlaku |
| Pengelola | `202` + `meta.diajukan = true` | Baris `usulan_perubahan` dibuat |

Satu endpoint, dua perilaku, ditentukan oleh peran pemanggil — bukan dua
endpoint yang harus dijaga tetap sinkron.

**Kedaluwarsa dihitung, tidak disimpan.** `kedaluwarsa_pada` disimpan; status
`kedaluwarsa` **tidak pernah ditulis**. `sudahKedaluwarsa()` membandingkan
dengan `now()`. Tidak ada cron, tidak ada baris yang berubah sendiri, dan tidak
ada kemungkinan baris berstatus `menunggu` yang sebenarnya sudah mati.

### 3.9 Notifikasi dikirim setelah transaksi, dari batas HTTP

**Keputusan.** `Pemberitahu::dalamTransaksi()` menulis baris in-app **di dalam**
transaksi; `setelahTransaksi(Surat)` mengirim email **setelah** respons siap.

**Kenapa bukan `DB::afterCommit()`.** Callback itu **tidak pernah terpanggil**
di bawah `RefreshDatabase` — tes tidak pernah benar-benar commit. Notifikasi
tidak akan terkirim di tes, dan yang lebih buruk: tes akan lulus karena tidak
memeriksa apa pun.

**Kegagalan email tidak pernah dilempar ulang.** Ditangkap dan `Log::warning`.
Tagihan yang sudah terbit tidak boleh dibatalkan karena SMTP sedang mati.

---

## 4. Alur permintaan

Contoh: `POST /api/tagihan`.

```
1. CatatPermintaanGagal      mencatat setiap respons ≥ 400 sebagai satu baris JSON
2. Authenticate:sanctum      token → user, atau 401
3. WajibGantiSandi           bila wajib_ganti_sandi → 403 kode wajib_ganti_sandi
4. PastikanWewenang:terbitkan_tagihan
                             pemilik selalu lolos; pengelola diperiksa
5. TerbitkanTagihanRequest   validasi; gagal → 422
6. TagihanController::store  memanggil PenerbitTagihan
7. PenerbitTagihan           hitung prorata + denda, tulis tagihan + rincian,
                             catat audit, siapkan Surat
8. (commit)
9. Pemberitahu::setelahTransaksi  kirim email; gagal → Log::warning
10. TagihanResource          201 + JSON
```

**Urutan middleware ini penting.** Route model binding berjalan **sebelum**
`wewenang:`, jadi id yang tidak ada menghasilkan **404**, bukan 403. Itu
disengaja: 403 akan memberi tahu bahwa id itu ada.

---

## 5. Penanganan kesalahan

| Kejadian | HTTP | `kode` |
|---|---|---|
| Validasi gagal | 422 | — ( Laravel standar) |
| Belum masuk | 401 | — |
| Wajib ganti sandi | 403 | `wajib_ganti_sandi` |
| Bukan wewenangnya | 403 | — |
| Tagihan periode itu sudah ada | 409 | — (dari SQLSTATE 23505) |
| Usulan sudah diputuskan | 409 | — |
| Hapus akun yang punya kontrak aktif | 409 | `penghapusan_belum_dapat_dilakukan` |
| Data milik orang lain | **404** | — |
| Kebijakan belum lengkap | 409 | `kebijakan_belum_lengkap` |
| Database mati | 503 | — (`/health`) |

**R-02 ditegakkan dua lapis.** Unique index `(kontrak_id, periode)` adalah
penjaganya; kode menangkap SQLSTATE `23505` dan mengubahnya menjadi 409. Lapisan
kedua (pemeriksaan sebelum insert) sengaja **tidak** diandalkan sendirian —
antara memeriksa dan menyisipkan ada celah, dan dua permintaan bersamaan akan
lolos keduanya. `uji-balapan-t4.mjs` membuktikannya dengan 10 permintaan paralel.

---

## 6. Uang

| Hal | Keputusan |
|---|---|
| Tipe kolom | `numeric(14,0)` |
| Satuan | Rupiah utuh, tanpa sen |
| Aritmetika | Bilangan bulat: `intdiv` + pembanding sisa, bukan `round()` pada float |
| Pembulatan prorata | Setengah ke atas, eksplisit |

**Kenapa bukan `float`.** `0.1 + 0.2 ≠ 0.3` pada float. Untuk uang, itu bukan
keanehan yang bisa diabaikan — itu tagihan yang selisih satu rupiah dan tidak
bisa dijelaskan. Karena semua nilai sudah bilangan bulat rupiah,
`numeric(14,0)` + aritmetika bulat menghilangkan seluruh kelas kesalahan ini.

**Kenapa `numeric`, bukan `bigint`.** `bigint` juga bulat, tetapi `numeric`
membuat maksudnya terbaca ("ini uang, bukan id") dan menghindari
`bigint` yang dipakai untuk dua hal berbeda.

---

## 7. Waktu

| Hal | Keputusan |
|---|---|
| Zona waktu aplikasi | `Asia/Jakarta` |
| Kolom tanggal | `date` (tanpa waktu) |
| Kolom waktu | `timestamp(0) with time zone` |
| "Hari ini" | `WaktuSistem::hariIni()`, bukan `now()` langsung |

`WaktuSistem` bisa digeser di mode demo (`APP_DEMO_MODE=true`) untuk
mendemokan denda tanpa menunggu 37 hari. Di produksi ia selalu `now()`.

Tanggal kalender disimpan sebagai `date` karena "jatuh tempo 10 November" tidak
punya jam. Menyimpannya sebagai `timestamp` berarti setiap perbandingan harus
memutuskan jam berapa, dan jawabannya akan berbeda di setiap tempat.

---

## 8. Keamanan

| Ancaman | Pertahanan |
|---|---|
| Tebakan kata sandi | `PembatasMasuk`: 5/menit per (email+IP), 20/jam per email. **Hanya kegagalan yang dihitung** |
| Enumerasi akun lewat waktu respons | `userTidakDikenal()` melakukan `Hash::check` tiruan pada biaya bcrypt produksi |
| Enumerasi lewat pesan | Login gagal selalu "Email atau kata sandi salah" |
| Sesi menumpuk | Maks 3 sesi per akun; token terlama dihapus |
| Token bocor | Kedaluwarsa 14 hari; ganti sandi menghapus semua token lain |
| Kata sandi awal diketahui | Tidak ada kata sandi awal. Akun dibuat dengan `Str::random(64)` yang tidak pernah dikembalikan atau dicatat; aktivasi = menetapkan sandi sendiri |
| Email palsu | `email_verified_at` adalah penanda "sudah diaktifkan"; login sebelum itu → 403 |
| Link aktivasi dipakai ulang | `token_akun.dipakai_pada`; token baru menghapus token lama yang belum dipakai |
| Pencatatan token di log | `TautanAkun` tidak pernah mencatat token; hanya hash SHA-256 yang disimpan |

**Kenapa hanya kegagalan yang dihitung.** Menghitung semua percobaan berarti
pengguna yang berhasil masuk 6 kali dalam semenit akan terkunci. Yang perlu
dibatasi adalah tebakan, bukan pemakaian.

---

## 9. Yang belum ada, dan disengaja

| Tidak ada | Alasan | Rencana |
|---|---|---|
| Penerbitan tagihan terjadwal | Render gratis tanpa cron yang andal | Utang teknis |
| Antrean email | Tanpa worker persisten | Utang teknis |
| Pembayaran pembalik | Belum dibutuhkan | Utang teknis |
| Uji aksesibilitas otomatis | Waktu | Utang teknis |
| Multi-kos | Ditolak (§3.6) | — |
| Aplikasi native | PWA cukup | Capacitor dicatat sebagai opsi |

Semuanya tercatat di `docs/utang-teknis.md`, dengan sebab dan rencana
penutupan. Daftar yang tidak dicatat akan terlupakan.

---

## 10. Peta berkas

```
api/
  app/Domain/            43 kelas, 10 folder - semua aturan
  app/Http/Controllers/  22 pengendali — hanya menerjemahkan
  app/Http/Middleware/   6 middleware
  app/Http/Requests/     validasi
  app/Http/Resources/    14 resource — bentuk JSON
  app/Models/            20 model — hanya bentuk data
  database/migrations/   36 migrasi
  tests/                 36 berkas tes, 713 tes

web/
  src/views/             26 view (7 publik + 14 pemilik + 5 panel pemilik)
  src/komponen/          6 komponen ui + 3 komponen bersama
  src/direktif/          kartu-tabel.js
  src/style.css          630 baris — seluruh design system
  src/bagikan.js         pesan WhatsApp & salin papan klip (REV-UI-03)
  public/                manifest, sw.js, ikon
  skrip/                 buat-ikon.mjs, pasang-kartu-tabel.mjs

docs/
  produk/                9 dokumen ini
  uji/                   23 skrip E2E + perkakas
  dfd/                   4 diagram (Gane-Sarson, Yourdon)
  charter/               Project Charter
```

Batas yang dijaga: **tidak ada perhitungan di controller, tidak ada aturan di
model.** Kalau sebuah aturan dibutuhkan di dua tempat, ia naik ke `Domain/`.
