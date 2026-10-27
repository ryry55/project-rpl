# Konteks Proyek — Sistem Informasi Operasional Kos-Kosan

> **Baca ini dulu.** Dokumen ini adalah pintu masuk: apa proyek ini, untuk siapa,
> sudah sampai mana, dan apa yang harus dilakukan berikutnya.
>
> Delapan dokumen lain menjelaskan satu bidang masing-masing secara mendalam.
> Dokumen ini menjelaskan bagaimana kedelapannya menjadi satu.

Versi 1.0 · 26 Oktober 2026 · Status: diserahkan

---

## 1. Satu paragraf

Aplikasi web untuk pemilik kos kecil (5-30 kamar) mengelola kamar, penghuni,
tagihan, dan pembayaran. Dibangun sebagai tugas besar mata kuliah Rekayasa
Perangkat Lunak: Laravel 12.70.1 API-only + Vue 3 SPA + PostgreSQL 16, satu perintah
Docker Compose untuk menjalankan, 792 tes PHP dan 29 skrip uji untuk
menjaganya. Yang membedakannya dari aplikasi sejenis: **setiap kos boleh punya
cara kerja sendiri** — satuan sewa, jatuh tempo, dan bentuk dendanya dipilih dari
menu, bukan dipaksa mengikuti satu pola.

---

## 2. Kalimat yang membentuk seluruh produk

> **"Pain point-nya tetap beda-beda per kos. Ini masalah utamanya."**

Dari wawancara pemilik kos, dan setiap keputusan besar bisa dilacak ke kalimat
ini:

| Keputusan | Karena kalimat itu |
|---|---|
| Empat bentuk denda, bukan satu | Kos A memakai 1%/hari, kos B nominal Rp 50.000/bulan, kos C tidak memakai denda |
| Tiga jatuh tempo | Ada yang menagih tanggal masuk, ada yang tanggal 1, ada yang akhir bulan |
| Kebijakan per kamar, bukan hanya per kos | Kamar pojok lebih mahal; kamar lantai 1 kena biaya kebersihan |
| Kustomisasi = menu + angka | Pemilik bukan pemrogram; formulir bebas berarti hasil tak terduga |
| Setup wajib sebelum tagihan (R-05) | Tanpa itu aplikasi menebak, dan tebakan pada uang tidak bisa dipertanggungjawabkan |

**Yang tidak diambil dari kalimat itu:** kebebasan menulis rumus. Fleksibilitas
yang diminta adalah *pilihan*, bukan *bahasa pemrograman*. Empat bentuk × tiga
jatuh tempo × tiga satuan sewa = 36 kombinasi, dan semuanya tertangani tanpa
satu pun kolom yang berisi formula. (Angka ini pernah 108 karena `deposit` dulu
dihitung sebagai dimensi keempat; deposit sudah dihapus — lihat
`docs/utang-teknis.md`.)

---

## 3. Pengguna

| Peran | Siapa | Akses |
|---|---|---|
| **Pemilik** | Satu orang, pemilik sekaligus pengelola | Semua |
| **Pengelola** | Akun milik pemilik dengan wewenang terbatas | Sesuai 8 wewenang |
| **Penghuni** | Penyewa kamar | Hanya datanya sendiri |

**Peran "Petugas" dibatalkan.** Pada kos 5-30 kamar, petugas adalah pemilik juga
atau anggota keluarga yang membantu sesekali. Yang dibutuhkan bukan peran baru,
melainkan **mendelegasikan sebagian pekerjaan tanpa menyerahkan seluruh kos** —
dan itu yang dilakukan `pengelola`.

Dua wewenang sengaja berbunyi **"mengusulkan"**: `ubah_tarif` dan
`ubah_kebijakan`. Perubahan tarif dan kebijakan menyentuh uang semua penghuni;
pengelola boleh mengajukan, pemilik memutuskan. Usulan kedaluwarsa dalam 7 hari.

---

## 4. Tujuh aturan bisnis

Dipakai sebagai ID di seluruh dokumen, kode, dan tes.

| ID | Aturan | Dijaga oleh |
|---|---|---|
| **R-01** | Prorata = `tarif × hari_tinggal / hari_dalam_bulan`, setengah ke atas, `hari_tinggal = hari_dalam_bulan − tanggal_masuk + 1` | `Kalkulator`, `KalkulatorTest` |
| **R-02** | Satu kontrak = satu tagihan per periode. Melanggar → 409 | Unique index + `uji-balapan-t4.mjs` |
| **R-03** | Denda bawaan 1%/hari, plafon 30%, mulai hari ke-8; plafon tercapai hari ke-37 | `PenghitungDenda`, `DendaTest` |
| **R-04** | Tunggakan berjenjang `belum_jatuh_tempo`/`0_30`/`31_60`/`lebih_60` | `UmurTunggakan`, `UmurTunggakanTest`, `TunggakanHttpTest` |
| **R-05** | Setup kebijakan wajib sebelum tagihan pertama | `KebijakanBelumLengkap`, gerbang di UI |
| **R-06** | Tarif/komponen/kebijakan append-only; tagihan menyimpan **salinan** saat terbit | 7 trigger + kolom snapshot |
| **R-07** | Penghuni hanya melihat miliknya. Milik orang lain → **404**, bukan 403 | `scopeMilikPenghuni`, `IsolasiDanAuditTest`, pagar `/api` di `sw.js` |

**R-05 dan R-06 saling melengkapi.** R-05 mencegah tagihan tanpa dasar; R-06
menjaga tagihan yang sudah terbit tetap benar selamanya.

---

## 5. Angka yang harus akurat

| Hal | Angka |
|---|---|
| Endpoint API | **85** |
| Tabel (di luar infrastruktur Laravel) | **23** (32 di skema `public`) |
| Migrasi | **41** |
| Model Eloquent | **21** |
| Kelas domain | **43** (10 folder) |
| Pengendali | **26** |
| Middleware | **6** |
| Trigger basis data | **8** (7 append-only + 1 penjaga pembalik) |
| Tes PHP | **799** (3674 asersi) di **45** berkas |
| Skrip browser | **29** `.mjs` (28 terdaftar di gate; `uji-proxy.mjs` terpisah) |
| Halaman SPA | **27** komponen view · **31** rute bernama |
| Wewenang pengelola | **8** |
| Peristiwa notifikasi | **9** |

Seluruh rangkaian browser: **SEMUA LULUS (576 detik)** di laptop.

> **Angka-angka ini punya cara diperiksa, dan cara itu ada di repo.** Sebelum
> fase terakhir, tabel ini tertinggal jauh (71 endpoint, 22 tabel, 36 migrasi,
> 748 tes) karena diketik tangan. Yang benar sekarang dibaca dari sumbernya:
> `node docs/akademik/build-dokumen.js` mencetak `85 endpoint · 23 tabel`, dan
> `php artisan test` mencetak jumlah tesnya. Jangan mengetik ulang angkanya -
> angka yang diketik akan tertinggal lagi.

---

## 6. Yang sudah selesai

### 6.1 Dokumen

| Berkas | Isi |
|---|---|
| `docs/charter/Project_Charter_Kos-Kosan.docx` / `.pdf` | 4 halaman, A4, 875 kata |
| `SPEC.md` | 42 KB, issue #1; 8 tiket sebagai sub-issue #2-#9 |
| `docs/dfd/` | 4 SVG + 4 PNG (Gane-Sarson dan Yourdon), kamus data, tabel aliran |
| `docs/produk/*.md` | 9 dokumen ini |

### 6.2 Kode — 11 LANGKAH

| LANGKAH | Isi | Commit |
|---|---|---|
| 1 | Walking skeleton: login 2 peran, 1 endpoint, 1 layar | `e0248ee` |
| 2 | Kamar, penghuni, kontrak, tarif | `c8c493f` |
| 3 | Tagihan, prorata, snapshot | `9facf3d` |
| 4 | Penerbitan massal | `05aea51` |
| 5 | Pembayaran, verifikasi | `ee0a084` `44fe111` |
| 6 | Denda, tunggakan | `42af58c` |
| 7 | Isolasi, audit, portal penghuni | `1410058` |
| 8 | Akun: aktivasi, lupa sandi, PDP | `3657985` |
| 9 | CI/CD, health, backup, operasi | `b107ba7` `db0ff5a` `e6eeb4e` `f2c73f0` `173134d` |
| 10 | Mobile + PWA | `eef7ff9` |
| 11 | **Belum** — produksi, Neon, Render, domain | — |

### 6.3 Yang benar-benar berfungsi hari ini

- Pemilik mengisi kebijakan → tambah kamar → tambah penghuni → buat kontrak →
  terbitkan tagihan → catat pembayaran → lihat tunggakan → baca rekap.
- Penghuni menerima email aktivasi, menetapkan sandi, melihat tagihannya,
  membaca aturan kos, menerima notifikasi.
- Pengelola mencatat pembayaran dan mengajukan perubahan tarif; pemilik
  menyetujui atau menolak.
- Setiap tindakan tercatat di `log_audit`; penghuni bisa meminta salinan datanya
  dan menghapus akunnya (dianonimkan).
- Aplikasi bisa dipasang ke layar utama ponsel dan dibuka tanpa jaringan
  (kerangka saja, bukan data).

---

## 7. Yang belum selesai, dan mengapa

Diurutkan menurut **risiko**, bukan kemudahan.

| Belum | Risiko | Kenapa ditunda |
|---|---|---|
| **Produksi belum ada** (LANGKAH 11) | Tertinggi — seluruh klaim "siap produksi" belum terbukti | Butuh akun Render/Neon/domain |
| **Midtrans belum diuji sungguhan** | Tinggi — alur pembayaran gateway hanya diuji dengan payload tiruan | Belum ada akun sandbox |
| **Backup tidak terjadwal** | Tinggi — backup hanya terjadi kalau ada yang mengingat | Render gratis tanpa proses latar |
| **Backup di disk tidak persisten** | Tinggi — backup hilang saat redeploy | Butuh storage luar |
| **Penerbitan tagihan tidak terjadwal** | Sedang — pemilik harus menekan tombol tiap bulan | Tanpa cron |
| **Antrean email** | Sedang — 30 penghuni = 30 pengiriman berurutan | Tanpa worker persisten |
| ~~**Deposit tidak dipakai**~~ | ~~Sedang — dibaca saat kontrak, lalu tidak pernah dipakai~~ | **Sudah ditutup.** Deposit dihapus seluruhnya di fase terakhir: kolom `kontrak.deposit`, tipe kebijakan `deposit`, dan seluruh alurnya. Kolom yang menyimpan angka tanpa akibat membuat orang mengira uangnya terjaga |
| ~~**E2E menggantung di CI**~~ | ~~Sedang - CI tidak membuktikan frontend~~ | **Sudah ditutup.** Dugaan "menggantung" keliru: yang gagal adalah paritas daftar skrip (34-41 dtk), sehingga E2E tidak pernah berjalan. Kini seluruh 28 skrip terdaftar di gate lulus di runner; `continue-on-error` dicabut |
| **Uji aksesibilitas otomatis** | Rendah | Waktu |
| **Lanskap ponsel belum diuji** | Rendah | Waktu |
| **Uji luring sungguhan** | Rendah | Waktu |
| ~~**Pembayaran pembalik**~~ | ~~Rendah~~ | **Sudah ditutup (fase terakhir):** endpoint dan tombolnya sudah ada, dijaga `uji-pembalik.mjs` dan `PembalikPembayaranTest` |
| **Multi-kos** | — | **Ditolak** dengan alasan tertulis |

Semuanya tercatat di `docs/utang-teknis.md` dengan tiga hal: apa yang kurang,
kenapa ditunda, dan apa rencana penutupannya. Tanpa yang ketiga, "ditunda"
berarti "tidak akan pernah".

---

## 8. Keputusan yang tidak boleh dibalik tanpa membaca alasannya

Delapan keputusan ini mahal untuk diubah dan mudah diusulkan ulang oleh orang
baru. Alasannya ada di `3_ARCHITECTURE.md`.

| Keputusan | Kalau dibalik |
|---|---|
| **Append-only + trigger** untuk data yang menyentuh uang | Riwayat tarif bisa diedit → laporan lama berubah → tidak ada bukti |
| **Snapshot saat terbit**, bukan rujukan | Tagihan Mei berubah setiap tarif naik |
| **Kebijakan sebagai data bertipe**, bukan JSON | Tidak bisa di-CHECK, tidak bisa di-query, membuka pintu formula |
| **Isolasi di query**, 404 bukan 403 | 403 memetakan jumlah tagihan lewat percobaan id |
| **`wewenang` untuk tindakan, bukan bacaan** | Pengelola yang mencatat pembayaran melihat daftar kosong |
| **Satu layanan, SPA di `public/`** | Dua layanan Render = dua cold start 50 detik |
| **Notifikasi di batas HTTP**, bukan `afterCommit` | `afterCommit` tidak pernah terpanggil di `RefreshDatabase`; tes lulus tanpa mengirim apa pun |
| **Service worker tidak menyentuh `/api`** | Cache dibagi antar-akun di perangkat yang sama → kebocoran R-07 |

---

## 9. Cara memulai (15 menit)

```bash
# 1. Nyalakan
docker compose up -d

# 2. Buka aplikasi
#    http://localhost:3000
#    pemilik@kos.test / password

# 3. Lihat gerbang kebijakan - itu fitur, bukan cacat
#    Kos baru memang kosong; seeder sengaja tidak mengisinya

# 4. Periksa seluruh guardrail
bash periksa.sh                # ~5 menit
bash periksa.sh --hanya-e2e    # ~5 menit, hanya browser
```

### 9.1 Tiga akun demo

| Email | Sandi | Peran |
|---|---|---|
| `pemilik@kos.test` | `password` | Pemilik |
| `pengelola@kos.test` | `password` | Pengelola (4 wewenang harian, **tanpa** ubah tarif/kebijakan) |
| `penghuni@kos.test` | `password` | Penghuni |

Pengelola sengaja tidak diberi `ubah_tarif`/`ubah_kebijakan`, supaya alur
pengajuan usulan bisa didemokan tanpa mengubah data apa pun.

### 9.2 Tiga jebakan pertama

1. **`/api` 502 setelah rebuild** → `docker compose restart api-web`.
2. **Perubahan Vue tidak terlihat** → kode dibake ke image;
   `docker compose build web && docker compose up -d web`.
3. **Login 429 pada sandi benar** → `docker compose exec -T api php artisan cache:clear`.

---

## 10. Peta dokumen

```
KONTEKS_PROYEK.md  ← Anda di sini: apa, untuk siapa, sampai mana
│
├── 1_PRD.md              Masalah, pengguna, ruang lingkup, R-01..R-07, U-1..U-9
├── 2_DESIGN_SYSTEM.md    Token, komponen, tabel→kartu, PWA, a11y
├── 3_ARCHITECTURE.md     Lapisan, 9 keputusan besar + alternatif yang ditolak
├── 4_AGENTS.md           Cara mengubah kode tanpa merusak apa pun
├── 5_SCHEMAS.md          23 tabel, CHECK, indeks, trigger (dari DB nyata)
├── 6_TOOLS_CONTRACTS.md  85 endpoint, middleware, bentuk request/response
├── 7_TESTING_EVALS.md    792 tes, 29 skrip, prinsip "buktikan bisa gagal"
└── 8_OPERATIONS_SOP.md   Deploy, backup, pemulihan, insiden, batas gratis
```

### 10.1 Dokumen lain di repo

| Berkas | Isi |
|---|---|
| `AGENTS.md` | Pintu masuk singkat untuk agen; menunjuk ke `4_AGENTS.md` |
| `SPEC.md` | Spesifikasi 42 KB; sumber kebenaran aturan bisnis |
| `docs/utang-teknis.md` | Register utang teknis — **baca ini sebelum mengeluh** |
| `docs/uji/README.md` | Katalog 29 skrip uji dan jebakannya |
| `docs/ci-cd.md` | Tiga workflow, rinci |
| `docs/dfd/` | Diagram alir data dan kamus data |
| `docs/charter/` | Project Charter (docx + pdf) |
| `docs/akademik/` | Narasi 4 halaman + dua halaman diagram (dibangkitkan) |
| `docs/panduan-tim.md` | **Penjelasan untuk anggota tim** — mulai dari sini kalau baru bergabung |

---

## 11. Tim

| Nama | Peran |
|---|---|
| Yukie Wirapratama Agusti | Pemilik produk / Tester |
| Yuni Siswantoro, M.Kom. | Manajer Proyek |
| Muhammad Ryvan Gibran | Analis Sistem + Trainer |
| Juniar Rifqi Nur Rachman | Frontend + Documenter |
| Muhamad Akbar Raplis | Backend + Documenter |

---

## 12. Kalau Anda hanya punya waktu membaca satu bagian

Baca **§4 (tujuh aturan)** dan **§8 (keputusan yang tidak boleh dibalik)**.

Aturan menjelaskan apa yang dijaga. Keputusan menjelaskan mengapa menjaganya
mahal. Sisanya adalah rincian yang bisa dicari saat dibutuhkan.

Dan satu hal yang tidak tertulis di aturan mana pun: **setiap pagar di proyek ini
pernah dilihat gagal.** Uji R-07 dijalankan dengan pagar `/api` dicabut dan
melaporkan `bocor: /api/me`. Uji balapan dijalankan dengan 10 permintaan
bersamaan dan menangkap 9 tagihan ganda. Uji tata letak menangkap 753px pada
viewport 390px.

Uji yang tidak pernah terbukti bisa gagal bukan uji — ia hanya kebiasaan yang
membuat orang merasa aman.
