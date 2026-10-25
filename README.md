# Sistem Informasi Operasional Kos-Kosan

Aplikasi untuk pemilik kos kecil (5–30 kamar) mengelola tagihan sewa: prorata penghuni baru,
denda keterlambatan, dan umur tunggakan. Lihat `SPEC.md` di akar repo untuk kontrak lengkap.

- **API**: Laravel 12, PHP 8.3, PostgreSQL 16, Sanctum
- **Web**: Vue 3 + Vite, dilayani nginx
- **Demo**: Docker Compose

## Menjalankan

```bash
cp .env.example .env      # Windows: Copy-Item .env.example .env
docker compose up --build
```

Tunggu sampai `db` berstatus sehat dan container `api` menulis `[entrypoint] siap.`

| Alamat | Isi |
|---|---|
| http://localhost:3000 | Aplikasi (SPA + proxy `/api`) |
| http://localhost:8081 | API langsung |

**Akun demo**

| Peran | Email | Kata sandi |
|---|---|---|
| Pemilik | `pemilik@kos.test` | `password` |
| Pengelola | `pengelola@kos.test` | `password` |
| Penghuni | `penghuni@kos.test` | `password` |

**Akun pengelola** diberi empat wewenang harian (mencatat pembayaran, mengelola kamar, mengelola
penghuni, menerbitkan tagihan) dan **sengaja tidak** diberi `ubah_tarif` maupun `ubah_kebijakan`.
Itu bukan kelalaian: akun inilah yang membuat pembatasan bisa didemokan — masuk sebagai pengelola,
lalu buka menu Kebijakan dan Jejak audit; keduanya tidak ada, dan formulir yang tidak boleh dipakai
memang tidak dirender.

Dari keadaan bersih (tanpa volume), migrasi dan seeder berjalan otomatis. Tidak ada langkah manual.

**Data demo.** Seeder hanya membuat akun dan satu penghuni. Kamar, kontrak, tagihan, dan **kebijakan
kos** belum diisi otomatis — dan itu disengaja: menu **Kebijakan** harus diisi pemilik lebih dulu
(satuan sewa, jatuh tempo, dan denda), karena tanpanya penerbitan tagihan ditolak. Setelah itu isi
lewat **Kamar → Tambah kamar**, **Penghuni → Tambah penghuni**, **Kontrak → Buat kontrak**, lalu
**Tagihan → Terbitkan tagihan**.

Untuk data contoh tanpa mengetik, jalankan skrip E2E yang melakukan persis alur itu:

```bash
node docs/uji/e2e-t2.mjs   # 8 kamar, 6 penghuni, 1 kontrak
node docs/uji/e2e-t3.mjs   # 2 kontrak + tagihan prorata & penuh
node docs/uji/e2e-t6.mjs   # tagihan + demo denda lewat simulasi tanggal
node docs/uji/e2e-t7.mjs   # empat kelompok umur tunggakan
node docs/uji/e2e-t4.mjs   # terbit massal + penolakan duplikat
node docs/uji/e2e-t5.mjs   # catat pembayaran sebagian lalu lunas
node docs/uji/e2e-t9.mjs   # isolasi portal penghuni + log audit pemilik
node docs/uji/e2e-t20.mjs  # metode bayar, verifikasi pembayaran, rekap pendapatan
node docs/uji/e2e-t24.mjs  # akun pengelola berwewenang terbatas + penolakan 403 di server
```

Uji balapan (bukan browser, jadi tidak butuh Chrome):

```bash
node docs/uji/uji-balapan-t4.mjs   # dua permintaan HTTP serentak → satu 201, satu 409
node docs/uji/uji-balapan-t5.mjs   # dua pembayaran serentak → satu 201, satu 422
```

Untuk kembali ke keadaan bersih:

```bash
docker compose exec api php artisan migrate:fresh --seed
```

## Menetapkan kebijakan kos (wajib sebelum menagih)

Setiap kos berbeda: ada yang menagih bulanan, ada yang harian; ada yang mendenda 1%/hari, ada yang
hanya menagih nominal tetap, ada yang tidak mendenda sama sekali. Karena itu semua aturan itu **bukan
konstanta di kode**, melainkan data yang dipilih pemilik di menu **Kebijakan**.

Tiga tipe wajib diisi sebelum tagihan pertama bisa terbit:

| Tipe | Pilihan | Angka yang menyertainya |
|---|---|---|
| Satuan sewa | bulanan / mingguan / harian | — |
| Jatuh tempo | tanggal masuk / tanggal tetap / akhir bulan | `tanggal` (untuk "tanggal tetap") |
| Denda | persen per hari / persen per bulan / nominal tetap / tanpa denda | `persen` + `plafon_persen` + `masa_tenggang`, atau `nominal` |

**Deposit tidak ada di sistem ini.** Dulu ada kolomnya, tetapi tidak pernah dicatat sebagai uang
masuk, tidak pernah mengurangi tagihan, dan tidak pernah dikembalikan — jadi ia dikeluarkan
seluruhnya di fase terakhir. Kos yang memungut deposit mencatatnya di luar sistem, dan itu batas
yang dinyatakan terbuka, bukan cacat tersembunyi. Lihat `SPEC.md` R-05.

Selama ketiganya belum lengkap, `POST /tagihan` dijawab **422** dengan menyebut tipe mana yang
kurang — bukan menerbitkan tagihan dengan angka tebakan. Layar Kebijakan menandai kartu yang masih
menghalangi penagihan.

Kebijakan bersifat **append-only**: mengubahnya berarti menambah baris baru dengan tanggal berlaku
baru. Tagihan yang sudah terbit tetap memakai salinan yang dibekukan saat terbit, jadi menaikkan
denda hari ini tidak mengubah tagihan bulan lalu.

## Mencatat fasilitas kamar

Halaman **Fasilitas** menyimpan katalog barang yang dimiliki kamar — AC, kamar mandi dalam, lemari,
Wi-Fi, parkir. Katalognya diisi sekali, lalu tiap kamar dicentang di halaman **Kamar** lewat tombol
**Fasilitas**; kolom Fasilitas di tabel kamar menunjukkan hasilnya sekilas.

Fasilitas **hanya dicatat, tidak menagih**. Ini pemisahan yang disengaja, bukan kelalaian:

| Pertanyaan | Dijawab oleh | Menagih? |
|---|---|---|
| Kamar ini **punya** apa? | `fasilitas` + `kamar_fasilitas` | Tidak |
| Kamar ini **ditagih** apa? | `komponen_biaya` | Ya |

Kamar ber-AC dicatat punya AC; kalau pemilik ingin menagih listrik AC Rp 100.000 per bulan, itu
komponen biaya, bukan fasilitas. Mencentang fasilitas **tidak pernah** menambah satu rupiah pun pada
tagihan — ada tes yang menjaganya (`FasilitasTest`, dan `e2e-t18.mjs` memeriksanya lewat layar).

Centang dikirim sebagai satu daftar penuh (`PUT /kamar/{id}/fasilitas`), jadi menambah dan melepas
dilakukan dengan cara yang sama.

## Aturan kos

Halaman **Aturan kos** menyimpan apa yang harus dipatuhi penghuni: jam malam, tamu, kebersihan,
pembayaran. Aturannya muncul di portal penghuni, di bawah tagihan.

Aturan **tidak** append-only dan **tidak** menyentuh uang sama sekali. Yang tidak berlaku lagi
disembunyikan lewat sakelar **Tampil di portal**, bukan dihapus: menghapusnya ikut menghilangkan
jawaban atas pertanyaan "memang ada aturan itu?" yang justru paling sering ditanyakan penghuni lama.

Penyaringan dilakukan **di server**, bukan di peramban. Aturan nonaktif tidak dikirim ke portal sama
sekali — bukan dikirim lalu disembunyikan dengan CSS. Bedanya bisa dibaca di jaringan, dan `e2e-t19.mjs`
memeriksa HTML portal agar tidak memuat judulnya sedikit pun.

Kategori adalah teks bebas dengan saran dari server (`umum`, `kebersihan`, `keamanan`, `tamu`,
`pembayaran`, `lainnya`). Daftar tertutup sengaja dihindari: setiap kos berbeda, dan kategori yang
tidak terpikirkan oleh pembuat aplikasi tetap harus bisa ditulis pemilik.

## Pengelola dan wewenang

Pemilik kos jarang mengerjakan semuanya sendiri. Halaman **Pengelola** membuat akun untuk orang yang
membantu — dan memberi mereka wewenang satu per satu, bukan peran "admin" yang bisa apa saja.

| Wewenang | Yang dibuka |
|---|---|
| `catat_pembayaran` | Mencatat pembayaran masuk |
| `verifikasi_pembayaran` | Memverifikasi bukti transfer |
| `terbitkan_tagihan` | Menerbitkan tagihan, satuan maupun massal |
| `kelola_kamar` | Menambah/mengubah kamar, fasilitas, aturan kos |
| `kelola_penghuni` | Menambah penghuni dan membuat kontrak |
| `baca_audit` | Membaca jejak audit |
| `ubah_tarif` | **Sensitif** — mengubah angka yang ditagih |
| `ubah_kebijakan` | **Sensitif** — mengubah cara kos menagih |

Tiga keputusan yang membentuk desain ini:

**Wewenang membatasi tindakan, bukan pengetahuan.** Pengelola tanpa `kelola_kamar` tetap bisa
membaca daftar kamar, dan pengelola tanpa `catat_pembayaran` tetap bisa membaca nama penghuni. Orang
yang mencatat pembayaran memang harus melihat nama dan nomor kamar; menyembunyikan data itu membuat
pekerjaannya mustahil, bukan membuatnya aman. Yang ditolak adalah **menulis**, bukan **melihat**.

**Memberi wewenang tidak bisa didelegasikan.** Hanya pemilik yang membuka `/pengelola`, dan endpoint
itu dijaga `peran:pemilik` di server. Alasannya satu baris: siapa pun yang boleh memberi wewenang pada
dasarnya boleh memberi **dirinya sendiri** wewenang apa pun, termasuk `ubah_tarif`.

**Mencabut akses bukan menghapus akun.** Penghapusan menurunkan perannya menjadi `penghuni`,
melepas `pemilik_id`, dan mencabut seluruh token Sanctum-nya. Barisnya tetap ada, karena jejak audit
harus tetap bisa menyebut nama orang yang dulu mencatat pembayaran tertentu. Akun yang hilang membuat
riwayatnya tidak bisa dipertanggungjawabkan.

Setiap tindakan pengelola dicatat di jejak audit **dengan namanya sendiri**, bukan atas nama pemilik.
Kolom `aktor_nama` di `log_audit` menyimpan nama saat kejadian, sehingga mengganti nama akun di
kemudian hari tidak menulis ulang masa lalu.

Di layar, tombol yang pasti ditolak tidak dirender, dan halaman yang isinya hanya formulir
disembunyikan dari menu. Tapi itu **hanya kesopanan**: seluruh pemeriksaan sebenarnya ada di server
lewat middleware `wewenang:`, dan `e2e-t24.mjs` membuktikannya dengan menembak API langsung memakai
token pengelola — bukan dengan memeriksa bahwa tombolnya hilang.

## Mendemokan denda tanpa menunggu 37 hari

Denda bergantung pada "hari ini", jadi menunggu plafon 30% berarti menunggu lebih dari sebulan.
Karena itu ada kendali **Simulasi tanggal** di halaman **Tagihan**: isi tanggal, klik Terapkan,
dan seluruh angka denda dihitung ulang terhadap tanggal itu.

Lewat API, sama halnya:

```bash
curl -H "X-Simulasi-Tanggal: 2026-11-21" \
     -H "Authorization: Bearer <token>" \
     http://localhost:8081/api/tagihan/1/denda
```

Header ini **hanya berlaku bila `APP_DEMO_MODE=true`** (default di `docker-compose.yml`).
Di mode normal header diabaikan sepenuhnya, dan respons mengumumkannya lewat
`X-Simulasi-Dipakai: false` serta `meta.simulasi` di badan respons.

Urutan yang cocok untuk sidang, untuk tagihan pokok Rp 1.500.000 yang jatuh tempo 15 Okt dengan
kebijakan denda **persen per hari 1%, plafon 30%, tenggang 7 hari**:

| Tanggal simulasi | Hari terlambat | Denda |
|---|---|---|
| 2026-10-22 | 7 | Rp 0 (masih tenggang) |
| 2026-10-23 | 8 | Rp 15.000 |
| 2026-11-14 | 30 | Rp 345.000 |
| 2026-11-21 | 37 | Rp 450.000 (plafon) |
| 2027-10-15 | 365 | Rp 450.000 (tetap plafon) |

## Membaca tunggakan

Halaman **Tunggakan** menjawab pertanyaan yang muncul tiap bulan: siapa yang menunggak, dan sudah
separah apa. Empat kartu di atas daftar menunjukkan jumlah dan total rupiah per kelompok umur;
menekan salah satu kartu menyaring daftar ke kelompok itu.

| Kelompok | Arti | Yang biasanya dilakukan |
|---|---|---|
| Belum jatuh tempo | Belum waktunya bayar | Tidak ada — bukan penunggak |
| 0–30 hari | Baru terlambat | Ingatkan |
| 31–60 hari | Perlu tindakan | Tagih langsung |
| Lebih dari 60 hari | Sudah lama | Putuskan: tegur atau akhiri kontrak |

Tagihan `lunas` dan `dibatalkan` tidak pernah muncul di sini. Tagihan `sebagian` **tetap** muncul —
dibayar sebagian belum berarti lunas.

Angka denda di halaman ini dihitung dengan kalkulator yang sama dengan endpoint
`GET /api/tagihan/{id}/denda`, bukan dengan rumus salinan; ada tes yang memastikan keduanya
identik untuk tagihan yang sama di beberapa tanggal acuan.

Lewat API:

```bash
curl -H "X-Simulasi-Tanggal: 2026-11-21" \
     -H "Authorization: Bearer <token>" \
     http://localhost:8081/api/tunggakan
```

## Menerbitkan tagihan sebulan sekaligus

Halaman **Tagihan** punya tombol **Terbitkan semua**. Satu tagihan per kontrak aktif untuk periode
yang dipilih; kontrak yang sudah punya tagihan periode itu dilewati, bukan diterbitkan dua kali.

Hasilnya memisahkan tiga nasib, karena artinya berbeda bagi pemilik:

| Hasil | Arti | Perlu tindakan? |
|---|---|---|
| **Terbit** | Tagihan baru dibuat | Tidak |
| **Dilewati** | Sudah punya tagihan periode ini, atau kontraknya belum mulai | Tidak |
| **Gagal** | Kamar belum punya tarif, atau kebijakan kos (satuan sewa / jatuh tempo / denda) belum lengkap | Ya |

Menekan tombol dua kali untuk periode yang sama menghasilkan **HTTP 409**, bukan tagihan kedua.
Aturan ini ditegakkan unique index `(kontrak_id, periode)` di basis data, bukan hanya pengecekan di
aplikasi — pengecekan di aplikasi punya celah balapan, dan dua permintaan yang tiba bersamaan bisa
lolos keduanya.

Lewat API:

```bash
curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
     -d '{"periode":"2026-11-01"}' \
     http://localhost:8081/api/tagihan/terbitkan
```

Atau lewat perintah, untuk penerbitan terjadwal:

```bash
docker compose exec api php artisan tagihan:terbitkan 2026-11-01
```

## Mencatat pembayaran

Buka **Rincian** pada salah satu tagihan, lalu **Catat pembayaran**. Status tagihan diturunkan dari
jumlah uang yang benar-benar tercatat: `belum bayar` → `sebagian` → `lunas`.

| Yang dilakukan | Hasil |
|---|---|
| Bayar kurang dari sisa | Status `sebagian`, sisa berkurang |
| Bayar pas sisa | Status `lunas`, sisa nol |
| Bayar melebihi sisa | **422** dengan pesan yang menyebut sisa — tidak disimpan sebagai saldo |
| Bayar tagihan yang dibatalkan | **422** |
| Tanggal pembayaran di masa depan | **422** |
| Tidak memilih metode bayar | **422** — formulir menahan tombol simpan |

Sisa tagihan = `pokok + denda − total pembayaran`. Dendanya tetap dihitung dari pokok penuh, jadi
membayar Rp 1 tidak menurunkan denda.

Dua pembayaran yang tiba bersamaan tidak bisa membuat total melebihi tagihan: baris `tagihan`
dikunci `FOR UPDATE` sebelum sisa diperiksa.

```bash
curl -X POST -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
     -d '{"jumlah":500000,"tanggal_pembayaran":"2026-11-10","metode":"transfer","referensi":"TRX-001"}' \
     http://localhost:8081/api/tagihan/1/pembayaran
```

Catatan penting: tabel `pembayaran` bersifat **append-only** (trigger menolak UPDATE dan DELETE),
dan baris pembalik belum ada. Salah input jumlah karena itu **tidak bisa diperbaiki dari
aplikasi** — lihat batasan yang dicatat di `SPEC.md` §5.

## Metode bayar, pemegang uang, dan pemeriksaan

Sejak T20 setiap baris pembayaran menjawab tiga hal yang dulu hanya bisa ditulis di kolom catatan
bebas — dan catatan teks tidak bisa dijumlahkan.

**Metode wajib dan tidak punya nilai bawaan.** Pilihannya `tunai`, `transfer`, `qris`, `gateway`.
Daftarnya sengaja tertutup: kalau pemilik menulis "tf", "transfer", "Transfer BCA" sebagai tiga
nilai berbeda, rekapnya pecah menjadi tiga baris yang masing-masing terlihat kecil. Nilai bawaan
`tunai` juga sengaja tidak ada — pemilik yang mengetik jumlah lalu langsung menyimpan akan mencatat
transfer sebagai uang tunai, dan rekap tunai per pemegang akan menghitung uang yang tidak pernah
lewat tangan siapa pun.

**Pemegang uang hanya ditanyakan untuk tunai.** Untuk transfer, QRIS, dan gateway medannya tidak
muncul, karena jawabannya memang tidak ada: uangnya sudah di rekening sejak awal. Untuk tunai,
kolomnya diisi otomatis dengan orang yang mencatat bila dikosongkan.

**Pemeriksaan pembayaran terpisah dari pencatatannya.** Tombol **Verifikasi** ada di riwayat
pembayaran. Yang berlaku adalah pemeriksaan **terakhir**, bukan "apakah pernah ada baris terima" —
pembayaran yang pernah diterima lalu ditolak pada pemeriksaan ulang harus berakhir ditolak.
Penolakan wajib menyebutkan alasannya, dan aturan itu ditegakkan dua lapis (validasi HTTP dan CHECK
di basis data).

Pemeriksaan **tidak mengubah** jumlah uang maupun status tagihan. Ia hanya menyatakan uang ini
sudah diperiksa. Pemeriksaan yang menemukan angka salah menandainya; koreksi tetap menuntut baris
pembalik yang belum ada.

`verifikasi_pembayaran` juga append-only lewat trigger: satu baris = satu pemeriksaan.

## Rekap pendapatan

Halaman **Rekap** menjawab pertanyaan yang berbeda dari Tagihan. Tagihan menjawab "berapa yang
seharusnya masuk"; rekap menjawab "berapa yang benar-benar masuk".

Satu aturan menentukan seluruh halaman itu benar atau tidak: **pendapatan = `SUM(pembayaran.jumlah)`,
dan denda yang masih tertera di tagihan BUKAN pendapatan — ia piutang.** Halaman itu menampilkan
keduanya sebagai dua angka terpisah, dan piutang tidak pernah ikut dijumlahkan ke total.

Kenapa dipisahkan sekeras itu. Tampilan yang wajar untuk pemilik kos adalah menjumlahkan kolom
`total` tiap tagihan lalu menyebutnya "pendapatan bulan ini". Angka itu terlalu besar: ia mencampur
uang yang sudah masuk dengan denda yang belum dibayar siapa pun. Pemilik yang mengira sudah menerima
Rp 4 juta padahal rekeningnya bertambah Rp 3,2 juta akan menyalahkan aplikasinya — dan ia benar.

| Yang ditampilkan | Artinya |
|---|---|
| Kartu per metode | Uang masuk per `tunai` / `transfer` / `qris` / `gateway` |
| Piutang | Denda yang belum dibayar + jumlah tagihan terbuka — **bukan** pendapatan |
| Asal baris | Dicatat manual atau dari webhook gateway |
| Uang tunai di tangan | Tunai per pemegang — setoran siapa yang belum disetor |
| Daftar pembayaran | Uang mana saja yang membentuk angka di atas |

**Periode memakai `tanggal_pembayaran`, bukan `tagihan.periode`.** Rekap ini menjawab "uang masuk
bulan ini" (basis kas). Penghuni yang membayar tunggakan Oktober pada November menambah kas
November — itu yang terlihat di rekening, dan itulah yang harus cocok dengan rekap.

Baris lama yang tidak punya metode (dibuat sebelum T20) dilaporkan sebagai kelompok **tanpa metode**,
bukan dilebur ke tunai. Menganggapnya tunai berarti mengarang fakta tentang uang; membiarkannya
terlihat membuat lubangnya kelihatan.

```bash
curl -H "Authorization: Bearer <token>" \
     "http://localhost:8081/api/rekap?dari=2026-11-01&sampai=2026-11-30"
```

`dari`/`sampai` opsional di `/rekap` (bawaannya awal bulan berjalan sampai hari ini), tetapi wajib
di `/pembayaran`. Rekap yang diam-diam memilih rentang sendiri adalah rekap yang menipu saat
dipertanyakan; layar tetap boleh menyebut rentang lain.


## Portal penghuni

Masuk sebagai penghuni (`penghuni@kos.test` / `password`) membuka **Tagihan saya**: daftar tagihan
miliknya sendiri, lengkap dengan denda berjalan, sisa, dan riwayat pembayaran.

Isolasinya bukan sekadar penyaringan di layar. Kalau penghuni membuka `/api/tagihan/<id>` milik
penghuni lain, jawabannya **404 yang tidak bisa dibedakan** dari id yang memang tidak ada. 403 akan
mengatakan "ada, tapi bukan milikmu" — cukup untuk memetakan tagihan siapa saja yang ada dengan
mencoba id berurutan.

| Yang dicoba penghuni | Hasil |
|---|---|
| Membuka tagihannya sendiri | 200 |
| Membuka tagihan penghuni lain | **404** |
| Membuka `/tagihan/{id}/denda` milik orang lain | **404** |
| Memanggil `/tagihan`, `/kamar`, `/audit-log` | **403** |
| Mencatat pembayaran | **403** |

## Jejak audit

Setiap tagihan yang terbit, setiap pembayaran yang tercatat, setiap tarif baru, dan setiap parameter
denda baru dicatat di tabel `log_audit` — aktor, aksi, entitas, id, waktu, nilai lama, dan nilai
baru. Sejak R4 hal yang sama berlaku untuk **data induk**: kamar, penghuni, kontrak, fasilitas, dan
aturan kos (`kamar.tambah`, `kontrak.akhir`, `fasilitas.hapus`, dan seterusnya). Tabel itu
**append-only**: trigger basis data menolak UPDATE dan DELETE, jadi riwayatnya tidak bisa diubah
lewat SQL langsung sekalipun.

Halaman **Jejak audit** di sisi pemilik menampilkan log itu, bisa disaring per entitas, id entitas,
dan jenis aksi. Tidak ada tombol ubah maupun hapus — bukan karena belum dibuat, tetapi karena
endpoint-nya memang tidak ada: jejak ditulis di dalam transaksi perubahannya, dan endpoint tulis akan
memungkinkan jejak palsu.

Nilai lama ditampilkan berdampingan dengan nilai baru (`belum_bayar → sebagian`), karena tanpa nilai
lama sebuah baris tidak bisa menjelaskan dari mana asalnya. Baris yang **dibuat** tidak diberi panah:
"— → 1.500.000" terbaca seperti perubahan dari kosong, padahal barisnya memang baru.

Jejak ditulis di dalam transaksi yang sama dengan perubahannya. Kalau ditulis setelah commit, ada
jendela waktu di mana data sudah berubah tetapi jejaknya belum ada.

Lewat API:

```bash
curl -H "Authorization: Bearer <token>" \
     "http://localhost:8081/api/audit-log?entitas=tagihan&entitas_id=1"
```

Log tumbuh terus, jadi `GET /audit-log` dibatasi 200 baris secara bawaan (maksimum `batas=500`).
Kalau hasilnya terpotong, `meta.dipotong` bernilai `true` — layar mengatakannya, bukan berpura-pura
sudah menampilkan seluruh isi log.

Coba ubah riwayatnya langsung lewat `psql`, dan basis data sendiri yang menolak:

```bash
docker compose exec db psql -U kos -d kos -c "UPDATE log_audit SET aksi = 'diubah' WHERE id = 1"
# ERROR: Tabel log_audit bersifat append-only (R-07): UPDATE tidak diizinkan.
```

## Menghentikan

```bash
docker compose down        # hentikan, data tetap
docker compose down -v     # hentikan dan hapus data (kembali ke keadaan bersih)
```

## Menjalankan tes

Tes memakai PostgreSQL, bukan SQLite — tiket berikutnya bergantung pada unique index parsial,
SQLSTATE `23505`, dan trigger append-only, yang perilakunya berbeda di SQLite.

```bash
docker compose exec api php artisan test
```

**Kode dibakar ke image, bukan di-mount.** `docker-compose.yml` sengaja tidak memasang `./api`
sebagai volume — container harus menjalankan apa yang benar-benar dibangun, bukan apa yang ada di
host. Konsekuensinya: setelah mengubah kode PHP, **jalankan `docker compose build api && docker
compose up -d api`** sebelum menguji. Tanpa itu, `php artisan test` akan menguji kode lama dan
hasilnya hijau padahal perubahan belum masuk — pernah terjadi, dan gejalanya menyesatkan (tes baru
tidak muncul di daftar sama sekali). Hal yang sama berlaku untuk `web`.

Database pengujian `kos_test` dibuat otomatis oleh `docker/postgres/init-test-db.sql`, terpisah
dari database demo supaya data sidang tidak pernah terhapus oleh tes.

## Struktur

```
.
├── api/                  Laravel 12 (API-only)
│   ├── app/Domain/Tarif/ PencariTarif — tarif efektif per periode (R-06 lapis 1)
│   ├── app/Domain/Billing/ Kalkulator (murni), PenerbitTagihan, PenerbitanMassal,
│   │                       PenghitungDenda, PencatatPembayaran
│   ├── app/Domain/Pembayaran/ MetodePembayaran (daftar tertutup), RekapPendapatan,
│   │                       PencatatVerifikasi — pemeriksaan append-only
│   ├── app/Domain/Akses/ Wewenang — daftar tertutup wewenang pengelola + mana yang sensitif
│   ├── app/Domain/Audit/ PencatatAudit — penulis log_audit, NilaiLama — pembanding sebelum/sesudah
│   ├── app/Http/         Controller, Request, Resource, Middleware (termasuk PastikanWewenang)
│   ├── app/Models/       User, Kamar, Penghuni, Kontrak, TarifSewa, KomponenBiaya,
│   │                     Fasilitas, AturanKos, Tagihan, TagihanRincian, Pembayaran,
│   │                     VerifikasiPembayaran, LogAudit, PengelolaKewenangan
│   ├── database/migrations/  Termasuk trigger append-only untuk tabel riwayat
│   ├── docker/php/       php.ini, opcache.ini, entrypoint.sh
│   └── docker/nginx/     Konfigurasi nginx untuk API
├── web/                  Vue 3 + Vite
│   ├── src/views/pemilik/    Kamar, Penghuni, Kontrak, Tagihan, Rekap, Tunggakan, Kebijakan,
│   │                         Fasilitas, Aturan, AuditLog, Pengelola
│   ├── src/views/DashboardPenghuni.vue  Portal penghuni (T9)
│   ├── src/komponen/         TataLetakPemilik (sidebar + navigasi sadar wewenang)
│   ├── src/sesi.js           Sesi, panggilan API, dan `boleh(wewenang)`
│   ├── src/format.js         Rupiah, tanggal, dan ringkasan galat validasi
│   └── docker/nginx/     Konfigurasi nginx untuk SPA
├── docker/postgres/      Init database pengujian
├── docs/uji/             Skrip E2E browser + screenshot bukti
├── docker-compose.yml
└── SPEC.md
```

## Konvensi API

Semua respons sukses: `{ "data": ... }`. Semua error: `{ "message": "...", "errors": {...} }`.

| Kode | Kapan |
|---|---|
| 401 | Belum login / token tidak valid |
| 403 | Peran atau wewenang tidak berhak |
| 404 | Tidak ada, **atau** bukan miliknya (R-07) |
| 409 | Duplikat periode tagihan (R-02) |
| 422 | Validasi gagal; juga pembayaran melebihi sisa atau pada tagihan yang dibatalkan |

Semua `/api/*` selalu dijawab JSON, termasuk untuk klien yang tidak mengirim
`Accept: application/json`. Dijamin oleh middleware `PaksaJson`.

## Catatan versi

**Laravel 12, bukan Laravel 11.** Rencana awal memakai Laravel 11, tetapi Composer memblokir
seluruh rilis 11.x karena 7 *security advisory* (`PKSA-d5tc-s1qs-h781` dan lain-lain). Laravel 12
adalah rilis stabil terdekat yang bersih dari advisory dan masih mendukung PHP 8.3. Perbedaan
API-nya kecil untuk kebutuhan proyek ini, dan charter tidak menyebut versi framework.

## Zona waktu

`APP_TIMEZONE=Asia/Jakarta` di `config/app.php`, `date.timezone` di `php.ini` container, dan `TZ`
pada container PostgreSQL. Ketiganya harus sama: kalau berbeda, `hari_terlambat` pada R-03 bisa
meleset satu hari tepat di sekitar tengah malam.
