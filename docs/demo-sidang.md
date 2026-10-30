# Panduan demo sidang — 5 menit

Halaman ini untuk **dibaca sambil mendemokan**. Isinya urutan klik yang paling menjelaskan sistem
dalam waktu sesingkat mungkin, beserta **apa yang harus diucapkan** di tiap langkah.

Satu aturan yang berlaku sepanjang demo: **setiap batas yang ditunjukkan harus dibuktikan di server,
bukan di layar.** Tombol yang hilang tidak membuktikan apa pun — peramban bisa diakali. Karena itu
di dua tempat di bawah ada langkah yang sengaja menembak API langsung memakai token pengelola.

## Sebelum mulai (sekali saja)

```powershell
docker compose up --build        # tunggu "[entrypoint] siap."
docker compose exec api php artisan migrate:fresh --seed
```

Buka **http://localhost:3000**. Akun demo:

| Peran | Email | Sandi |
|---|---|---|
| Pemilik | `pemilik@kos.test` | `password` |
| Pengelola | `pengelola@kos.test` | `password` |
| Penghuni | `penghuni@kos.test` | `password` |

Data awal hanya berisi akun dan satu penghuni — **kebijakan kos sengaja kosong**. Itu bukan data
yang lupa di-seed; itu justru hal pertama yang layak ditunjukkan.

### Kalau demo dilakukan bertiga

Token login disimpan di `localStorage`, dan `localStorage` dipakai bersama oleh semua tab di satu
profil peramban. **Tiga tab di satu profil akan saling menendang** — login sebagai penghuni membuat
pemilik keluar. Pakai tiga profil terpisah, misalnya Chrome, Edge, dan Firefox, atau tiga profil
Chrome (`chrome://settings/manageProfile`).

Untuk tiga perangkat dalam satu Wi-Fi: cari IPv4 laptop (`ipconfig`), lalu buka
`http://<ip-laptop>:3000`. Port 3000 sudah terbuka ke jaringan, dan `/api` diteruskan oleh nginx
sehingga tetap satu origin. Bila tidak bisa dibuka, izinkan Docker Desktop di jaringan Private pada
Windows Firewall.

Sebelum demo, segarkan data **dan** cache — penghitung percobaan masuk disimpan di cache, sehingga
login gagal saat latihan bisa membuat login yang benar ditolak (429) di tengah demo:

```powershell
docker compose exec api php artisan migrate:fresh --seed
docker compose exec api php artisan cache:clear
```

### PWA: hanya bekerja di `localhost` atau HTTPS

Service worker menolak mendaftar di `http://192.168.x.x` — itu aturan peramban, bukan bug aplikasi.
Artinya PWA **tidak bisa dipasang dari HP lewat Wi-Fi biasa**. Dua jalan yang benar:

- **Di laptop:** buka `http://localhost:3000`, ikon install muncul di address bar.
- **Di HP:** jalankan `cloudflared tunnel --url http://localhost:3000`, buka URL `https://` yang
  dicetak, lalu "Add to Home screen" (iPhone: Share → Add to Home Screen, tidak ada prompt otomatis).

Demo luring yang meyakinkan: pasang aplikasi, buka sekali, tutup, matikan Wi-Fi, buka lagi — kerangka
tetap muncul dengan pesan galat yang rapi, bukan layar putih. Lalu buka DevTools → Application →
Cache Storage: hanya `kos-kerangka-*` dan `kos-aset-*`, **tidak ada URL `/api/`**.

> "Yang tidak disimpan justru datanya. Cache peramban dipakai bersama semua akun di perangkat itu;
> satu respons tagihan yang tersimpan bisa terbaca penghuni berikutnya."

---

## Menit 1 — "Kenapa kos ini tidak bisa disamakan"

Masuk sebagai **pemilik**. Buka menu **Kebijakan**.

> "Setiap kos beda: ada yang bulanan, ada yang harian; ada yang mendenda 1% per hari, ada yang
> nominal tetap, ada yang tidak mendenda. Jadi ketiga aturan ini bukan ditulis di kode — pemilik
> yang memilih."

Tunjukkan tiga kartu yang **masih kosong**, lalu buka **Tagihan** dan tekan **Terbitkan tagihan**.

> "Dan selama ketiganya belum diisi, sistem **menolak menerbitkan tagihan** — 422 yang menyebut
> tipe mana yang kurang. Bukan menerbitkan dengan angka tebakan."

Kembali ke **Kebijakan**, isi ketiganya:

| Tipe | Pilihan untuk demo |
|---|---|
| Satuan sewa | Bulanan |
| Jatuh tempo | Tanggal tetap → `15` |
| Denda | Persen per hari → `1`, plafon `30`, tenggang `7` |

> "Batas kustomisasinya juga disengaja: **menu jenis dan angka**, bukan pengguna menulis rumus
> sendiri. Rumus buatan pengguna adalah kode yang tidak diuji, dan tidak bisa dijelaskan saat angka
> tagihannya dipertanyakan."

## Menit 2 — "Tagihan dan prorata"

Buka **Kamar → Tambah kamar** (satu kamar). Tarif **tidak** diisi di formulir itu: pada baris kamar
yang baru muncul, tekan **Atur tarif**, isi **Tarif baru per bulan** = `1500000`, lalu **Berlakukan
tarif**. Lalu **Penghuni → Tambah penghuni**, dan **Kontrak → Buat kontrak** dengan **tanggal masuk di
tengah bulan** — untuk demo ini pakai **16 September 2026** (September 30 hari).

> Tanggalnya harus **hari ini atau sudah lewat**. Kontrak bertanggal masa depan tersimpan berstatus
> `dipesan` dan belum bisa ditagih — itu memang perilaku yang benar (S1.9: pemilik boleh mencatat
> penghuni yang baru menyanggupi kamar bulan depan), tetapi ia bukan bahan demo prorata.

Buka **Tagihan → Terbitkan tagihan**, pilih **Kontrak** yang barusan dibuat, lalu isi **Periode**
(`2026-09-01` — medan tanggal, bukan pemilih bulan).

> "Penghuni yang masuk tanggal 16 tidak ditagih sebulan penuh. Proratanya
> `tarif × hari tinggal ÷ hari dalam bulan`, dan hari masuknya ikut dihitung: 30 − 16 + 1 = **15
> hari** dari 30, jadi Rp 1.500.000 × 15 ÷ 30 = **Rp 750.000**. Angkanya dihitung server, dan
> **dibekukan** saat tagihan terbit: menaikkan tarif besok tidak mengubah tagihan ini."

Kalau ingin menunjukkan bahwa tanggal 1 **tidak** diprorata (jebakan `tarif − Rp 1`):

| Masuk | Hari tinggal | Tagihan sewa |
|---|---|---|
| 1 September (30 hari) | 30/30 | Rp 1.500.000 — persis tarif penuh |
| 16 September (30 hari) | 15/30 | Rp 750.000 |
| 15 Oktober (31 hari) | 17/31 | Rp 822.581 |

Satu hal lagi yang layak disebut sambil menunjuk tanggal jatuh temponya:

> "Jatuh temponya **16 September** — tanggal ia masuk, bukan tanggal 15. Kebijakan kos ini berbunyi
> 'jatuh tempo tanggal 15', dan kalau aturan itu diterapkan apa adanya di bulan pertama, tagihan
> September akan jatuh tempo 15 September: **sehari sebelum ia menempati kamarnya**. Tagihan itu
> lahir dalam keadaan sudah terlambat, dan dendanya berjalan dari tanggal ia belum berutang apa pun.
> Jadi pada bulan masuk saja jatuh temponya dinaikkan sampai tanggal masuk; bulan berikutnya tetap
> tanggal 15."

## Menit 3 — "Denda yang bisa didemokan hari ini"

Di **Tagihan**, buka **Rincian** tagihan itu, lalu pakai kendali **Simulasi tanggal**.

> "Denda bergantung pada 'hari ini', jadi menunggu plafon 30% berarti menunggu lebih dari sebulan.
> Karena itu ada simulasi tanggal — dan ia **hanya hidup di mode demo**."

Angka di bawah adalah pokok Rp 750.000 dengan jatuh tempo 16 September 2026, denda 1%/hari, plafon
30%, tenggang 7 hari — **sudah dijalankan pada sistem**, bukan dihitung di atas kertas:

| Tanggal simulasi | Hari terlambat | Denda |
|---|---|---|
| 2026-09-23 | 7 | Rp 0 (masih tenggang) |
| 2026-09-24 | 8 | Rp 7.500 |
| 2026-10-16 | 30 | Rp 172.500 |
| 2026-10-23 | 37 | Rp 225.000 (plafon) |

> "Mulai hari ke-8, 1% per hari dari pokok, berhenti di 30%. Di hari ke-37 plafonnya tercapai, dan
> sesudah itu tidak naik lagi — 365 hari tetap Rp 225.000."

Kalau penguji ingin angka yang lebih bulat, pakai tagihan **sebulan penuh** (masuk tanggal 1):
pokok Rp 1.500.000, dan dendanya Rp 0 → Rp 15.000 → Rp 345.000 → **Rp 450.000** di hari ke-37.

## Menit 4 — "Uang yang salah catat harus bisa ditarik"

Masih di rincian tagihan, **Catat pembayaran** sebagian (misal Rp 500.000). Lalu tunjukkan tombol
**Tarik kembali** pada baris pembayaran itu.

> "Tabel pembayaran append-only: trigger basis data menolak UPDATE dan DELETE. Salah input tidak
> diperbaiki dengan mengubah barisnya, tetapi dengan **menariknya**: sistem menulis baris **negatif**
> yang menunjuk baris aslinya. Riwayatnya jadi dua baris, jadi pertanyaan 'dulu pernah dicatat
> Rp 500.000, lalu kenapa hilang' tetap bisa dijawab."

Coba simpan dengan **alasan kosong** — ditolak 422 dan formulirnya **tetap terbuka**.

> "Formulir yang menutup diri saat gagal akan membuat pemilik mengira penarikannya berhasil."

Sesudah ditarik, tunjukkan **status tagihan kembali menunggak**, dan sebutkan akibat sampingannya:

> "Ini juga membuka kebuntuan: tagihan yang tadinya tidak bisa dibatalkan karena masih ada uang
> menempel kini bisa dibatalkan."

## Menit 5 — "Batas wewenang, dibuktikan di server"

Buka **Pengelola**, tunjukkan daftar wewenang yang diberikan satu per satu.

> "Bukan peran 'admin' yang bisa apa saja. Akun pengelola diberi empat wewenang harian dan
> **sengaja tidak** diberi `ubah_tarif` maupun `ubah_kebijakan`."

Keluar, masuk sebagai **pengelola**. Tunjukkan bahwa menu **Kebijakan** dan **Jejak audit** tidak ada.

> "Tapi hilangnya menu hanya kesopanan. Yang sebenarnya menjaga adalah server."

Sekarang buktikan — jalankan di terminal, dengan token pengelola:

```powershell
# Ambil token pengelola
$body = @{ email='pengelola@kos.test'; password='password' } | ConvertTo-Json
$token = (Invoke-RestMethod -Uri "http://localhost:8081/api/login" -Method Post `
  -Body $body -ContentType 'application/json').data.token

# Coba ubah kebijakan sebagai pengelola.
# Badan permintaannya SENGAJA sah - kalau salah, 403-nya tidak membuktikan
# apa pun tentang wewenang.
try {
  Invoke-RestMethod -Uri "http://localhost:8081/api/kebijakan" -Method Post `
    -Headers @{ Authorization = "Bearer $token" } -ContentType 'application/json' `
    -Body '{"tipe":"satuan_sewa","nilai":"bulanan","berlaku_dari":"2026-01-01"}'
} catch {
  "STATUS: " + $_.Exception.Response.StatusCode.value__
}
```

Hasilnya:

```
STATUS: 403
```

> "**403 dari server**, walaupun tombolnya memang tidak pernah ada. Inilah yang membedakan
> menyembunyikan tombol dengan menutup jalan."

Badan responsnya di mode demo memuat jejak tumpukan karena `APP_DEBUG=true` — itu memang perilaku
Laravel di mode pengembangan, dan **profil produksi mematikannya** (`docker-compose.prod.yml`,
`APP_DEBUG=false`). Sebutkan itu sebelum penguji menemukannya sendiri; jauh lebih baik daripada
terlihat menyembunyikan sesuatu. Yang perlu diperhatikan justru pesannya:

```json
{ "message": "Akun Anda tidak memiliki wewenang untuk tindakan ini." }
```

> "Pesannya menyebut **wewenang**, bukan membocorkan struktur internal — dan di produksi hanya itu
> yang dikirim."

Tunjukkan juga bahwa pengelola **tetap bisa membaca** daftar kamar dan nama penghuni.

> "Wewenang membatasi **tindakan**, bukan **pengetahuan**. Orang yang mencatat pembayaran memang
> harus melihat nama dan nomor kamar; menyembunyikan data itu membuat pekerjaannya mustahil, bukan
> membuatnya aman."

---

## Kalau masih ada waktu — tiga demo tambahan

**Persetujuan pemilik (7 hari).** Sebagai pemilik, beri pengelola wewenang `ubah_tarif` di halaman
**Pengelola** (tanpa itu tombol pengajuan tidak muncul). Masuk sebagai pengelola itu, ajukan perubahan
tarif. Keluar, masuk sebagai pemilik, buka **Persetujuan** — usulan itu menunggu, dengan angka lama
dan angka baru berdampingan.

> "Tindakan yang bisa merugikan pemilik secara diam-diam tidak langsung berlaku. Pengelola
> **mengusulkan**, pemilik yang memutuskan, dan usulannya kedaluwarsa dalam 7 hari."

**Gambar QRIS.** Masuk sebagai pemilik, buka **Rekening**, tambah rekening jenis **QRIS**, unggah
gambar kode QR. Lalu masuk sebagai penghuni dan tunjukkan gambarnya di **Cara membayar**.

> "Medan unggah hanya muncul untuk QRIS — rekening transfer ditampilkan sebagai nomor, dan server
> menolak gambar untuknya. Yang diperiksa **isinya**, bukan nama berkasnya: PDF yang dinamai `.png`
> ditolak, dan SVG ditolak karena bisa memuat skrip."

**Portal penghuni dan isolasi.** Masuk sebagai penghuni. Buka tagihannya sendiri, lalu coba buka
tagihan penghuni lain lewat URL.

> "Jawabannya **404 yang tidak bisa dibedakan** dari id yang memang tidak ada. 403 akan mengatakan
> 'ada, tapi bukan milikmu' — cukup untuk memetakan tagihan siapa saja yang ada dengan mencoba id
> berurutan."

---

## Pertanyaan yang paling sering muncul

**"Kenapa tidak ada deposit?"**
Dulu ada kolomnya, tetapi tidak pernah dicatat sebagai uang masuk, tidak pernah mengurangi tagihan,
dan tidak pernah dikembalikan. Kolom yang menyimpan angka tanpa akibat **lebih buruk** daripada tidak
ada kolom: ia membuat orang mengira uangnya sudah tercatat. Jadi ia dikeluarkan seluruhnya, dan kos
yang memungut deposit mencatatnya di luar sistem — batas yang dinyatakan terbuka, bukan cacat
tersembunyi. Lihat `SPEC.md` R-05.

**"Kenapa satu layanan, bukan API dan web terpisah?"**
Target hosting-nya gratis: satu layanan Render. SPA dibangun ke `public/` dan dilayani bersama API,
jadi tidak ada CORS, tidak ada dua URL yang harus dijaga sinkron, dan tidak ada biaya layanan kedua.

**"Kenapa gambar QRIS di basis data, bukan di disk?"**
Render free tidak punya disk persisten. Berkas yang ditulis hari ini hilang saat restart, dan gambar
QRIS yang hilang lebih buruk daripada tidak ada — pemilik mengira sudah mengunggah. Basis data sudah
persisten dan sudah dicadangkan.

**"Apa yang belum selesai?"**
Satu hal, dan itu perintah Anda sendiri: **deploy dibatalkan 29 Oktober 2026**, jadi
Render/Neon, Midtrans sandbox, pinger uptime, dan verifikasi Brevo tidak akan dikerjakan.
Aplikasi dinilai dan didemokan dari laptop. Antrean email sudah ditutup
(Fase 5.5: program `antrean` + `KirimPemberitahuan`). Uji E2E di CI **sudah hijau** —
30 skrip / 871 langkah. Tidak ada yang disembunyikan.

**"Bagaimana aksesibilitasnya dibuktikan?"**
`docs/uji/uji-aksesibilitas.mjs` menjalankan axe-core pada 21 halaman (publik, pemilik,
penghuni, dan halaman 404) dengan aturan WCAG 2.0/2.1 level A/AA, dan ikut dijalankan
gate. Tiga keadaan yang baru muncul dari tindakan pengguna ikut diukur — panel rincian,
form catat pembayaran, dan pesan galat validasi — karena justru di sanalah cacat akses
paling sering bersembunyi. Dua cacat yang ditemukannya nyata: kode peristiwa di layar
Pengaturan notifikasi berkontras 3,49:1 karena transparansi 0,8 di atas abu sekunder;
transparansi itu dibuang sehingga menjadi 5,3:1. Dan seluruh pesan galat tidak
diumumkan pembaca layar, karena `role="alert"` menunggu pemanggil memintanya dan tidak
satu pun dari 39 pemanggil memintanya — kini `PesanBaris` memasangnya sendiri. Yang
tetap belum: pembaca layar sungguhan (NVDA/VoiceOver), karena itu butuh manusia di
depan mesin.

**"Bagaimana kalau angkanya dipertanyakan?"**
Setiap perubahan angka punya jejak: `log_audit` mencatat aktor, waktu, nilai lama, dan nilai baru,
di dalam transaksi yang sama dengan perubahannya. Tabel itu append-only — trigger basis data menolak
UPDATE dan DELETE, lewat SQL langsung sekalipun.
