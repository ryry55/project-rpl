# CI/CD

Tiga berkas di `.github/workflows/`. Masing-masing menjawab satu pertanyaan, dan
pemisahannya disengaja: pertanyaan yang berbeda tidak boleh berbagi satu
jawaban.

| Berkas | Pertanyaan | Kapan berjalan |
|---|---|---|
| `uji.yml` | Apakah kode ini benar? | Setiap push ke `main`, setiap pull request, dipanggil ulang oleh `rilis.yml` |
| `rilis.yml` | Apakah kode yang sudah benar bisa dikirim? | Tag `v*`, atau manual |
| `deploy.yml` | Pasang ke satu lingkungan | Dipanggil `rilis.yml`, dua kali (staging lalu produksi) |

## `uji.yml` - dua pekerjaan

**`lint`** (sekitar satu menit, tanpa Docker, tanpa database):

1. `pint --test` pada **seluruh** kode, bukan hanya berkas yang berubah.
2. `eslint`.
3. `vite build`.
4. Daftar skrip uji di `periksa.ps1` dan `periksa.sh` harus **sama**.
5. `docs/uji/periksa-profil-produksi.sh`.

Dua yang terakhir bukan pemeriksaan kode aplikasi, melainkan penjaga agar
perkakasnya sendiri tidak membusuk:

- Dua berkas memuat daftar skrip uji yang sama, satu untuk Windows dan satu
  untuk Linux. Skrip baru yang hanya didaftarkan di salah satunya akan lolos
  dari guardrail selamanya tanpa ada yang memberi tahu. Pemeriksaan ini membuat
  perbedaan itu gagal dalam hitungan detik.
- `docker-compose.prod.yml` berada di akar repo, **di luar** build context
  image api, sehingga tes PHPUnit tidak bisa membacanya. Profil produksi yang
  ada tetapi nilainya salah lebih berbahaya daripada yang tidak ada: ia membuat
  orang mengira pagarnya sudah terpasang.

**`uji`** (sekitar dua belas menit):

1. Bangun image dengan cache layer.
2. `docker compose up -d`, lalu tunggu `/api/health` menjawab **200**.
3. `php artisan test` di dalam container - 599 tes.
4. Jalankan Chrome headless dengan `--remote-debugging-port=9222`.
5. `./periksa.sh --hanya-e2e` - 18 skrip browser dan 2 uji balapan.

### Kenapa uji browser tidak ditulis ulang dengan Playwright

Rencana awal menyebut Playwright sebagai pengganti CDP. Yang dipilih adalah
kebalikannya, dan alasannya bukan kemalasan:

Delapan belas skrip di `docs/uji/` berbicara CDP mentah lewat WebSocket, dan
endpoint CDP-nya **dapat diatur lewat variabel lingkungan**
(`CDP_BASE`/`APP_URL`/`API_URL`/`MAILPIT_URL`). Artinya skrip yang sama berjalan
di Chrome berjendela di laptop dan di Chrome headless di runner tanpa satu baris
pun diubah.

Menulis ulang delapan belas skrip berarti membuang bukti yang sudah ada dan
membuat **dua** rangkaian uji yang akan berbeda pendapat. Yang di CI selalu
kalah dalam perbedaan itu, karena tidak ada yang membacanya.

### Kenapa tes PHP lewat `docker compose`, bukan `services: postgres`

`phpunit.xml` menulis `DB_HOST=db` sebagai `<server>`, dan
`docker/postgres/init-test-db.sql` membuat database `kos_test`. Memakai blok
`services:` di runner berarti menimpa keduanya dengan konfigurasi **kedua** yang
hanya hidup di CI.

Yang diuji di CI harus persis yang diuji di laptop. Berkas compose yang sama
dipakai untuk keduanya, sehingga tidak ada konfigurasi yang bisa menyimpang.

## `rilis.yml` - bangun, lalu deploy berurutan

```
uji (memanggil uji.yml)  ->  bangun (dorong ke GHCR)  ->  staging  ->  produksi
```

- **`uji`** memanggil `uji.yml` lewat `workflow_call`, bukan menyalin
  langkahnya. Salinan kedua akan menyimpang, dan yang menyimpang selalu yang di
  CI.
- **`bangun`** mendorong image api dan web ke GHCR dengan dua tag: `sha-<pendek>`
  (bisa dilacak ke commit) dan `latest`. Nama image dihitung sekali di langkah
  `Tentukan tag` dengan `${GITHUB_REPOSITORY,,}` (lowercase bash), lalu dipakai
  ulang lewat `steps.meta.outputs`. GHCR menolak nama image berhuruf besar,
  sedangkan nama repositori GitHub boleh berhuruf besar - tanpa penurunan ini
  rilis gagal pada `docker push` dengan pesan yang tidak menyebut sebabnya.
- **`staging`** dan **`produksi`** memakai `deploy.yml` yang sama, dipanggil dua
  kali dengan nama lingkungan berbeda. Nilai `APP_URL` dan
  `RENDER_DEPLOY_HOOK` dibaca dari GitHub Environments, jadi tidak ada satu
  baris pun yang bercabang berdasarkan lingkungan.
- **Produksi hanya berjalan bila staging hijau.** `if:` membedakan staging yang
  *gagal* (menghentikan produksi) dari staging yang *ter-skip* karena targetnya
  belum dikonfigurasi (tidak memblokir apa pun).

### Mengaktifkan deploy (LANGKAH 11)

Sampai endpoint produksi dibuat, job deploy **melaporkan bahwa ia belum
dikonfigurasi** alih-alih gagal. Workflow yang selalu merah karena rahasia yang
memang belum ada akan diabaikan orang - dan setelah diabaikan ia tidak lagi
menjaga apa pun.

Yang perlu dibuat di halaman repo saat LANGKAH 11:

| Tempat | Nama | Isi |
|---|---|---|
| Environment `staging` | variabel `APP_URL` | mis. `https://staging.kos-kosan.my.id` |
| Environment `staging` | rahasia `RENDER_DEPLOY_HOOK` | URL dari dashboard Render |
| Environment `production` | variabel `APP_URL` | mis. `https://kos-kosan.my.id` |
| Environment `production` | rahasia `RENDER_DEPLOY_HOOK` | URL dari dashboard Render |

Lingkungan `production` sebaiknya diberi **required reviewers**, sehingga rilis
sungguhan menunggu persetujuan manusia. Itu tidak mengubah berkas mana pun.

## Yang diperiksa smoke test, dan mengapa

`docs/uji/smoke-produksi.sh` menjalankan sembilan pemeriksaan dalam empat
kelompok. Semuanya adalah bahaya yang **hanya** muncul di produksi, dan
masing-masing akibatnya bisa disebut tanpa membayangkan:

| Pemeriksaan | Kalau gagal |
|---|---|
| `/api/health` menjawab 200 | Proses mati atau tidak siap; tidak ada yang bisa dipakai |
| `/api/health` menyebut database `ok` | Migrasi tidak berjalan; setiap layar gagal walau prosesnya hidup |
| `/api/health` menyebut zona waktu `Asia/Jakarta` | Denda dan jatuh tempo dihitung pada hari yang salah, tanpa satu pun galat |
| Ketiga akun demo (`pemilik`, `pengelola`, `penghuni`) ditolak | Seluruh data kos terbuka bagi siapa pun yang tahu alamatnya |
| `X-Simulasi-Dipakai` menjawab `false` | Siapa pun bisa memundurkan tanggal untuk menghapus denda, atau memajukannya untuk menagih denda yang belum jatuh tempo |
| Tidak ada `X-Tanggal-Sistem` | Penanda yang lebih sulit dipalsukan: header itu hanya muncul bila simulasi benar-benar berlaku |
| CORS tidak menjawab `*` | Situs mana pun boleh membaca jawaban API |

Zona waktu ikut diperiksa karena seluruh perhitungan denda bergantung padanya,
dan container yang salah waktu menghasilkan denda yang salah tanpa satu pun
galat. `X-Simulasi-Dipakai` diperiksa **nilainya**, bukan keberadaannya:
middleware itu selalu mengirim header tersebut, sehingga memeriksa keberadaan
akan lulus pada server yang simulasi tanggalnya justru hidup.

Berkas itu **dibuktikan bisa gagal**: dijalankan terhadap profil demo, ia gagal
pada enam pemeriksaan; dijalankan terhadap `docker-compose.prod.yml`, ia
lulus. Pemeriksaan yang tidak pernah terbukti bisa gagal bukan pemeriksaan.

## Menjalankan hal yang sama di laptop

```bash
./periksa.sh              # seluruh pemeriksaan
./periksa.sh --cepat      # lewati uji browser
./periksa.sh --hanya-e2e  # hanya uji browser (yang dipakai CI)
```

`periksa.sh` adalah kembaran Linux dari `periksa.ps1`. Keduanya menjalankan
urutan yang sama; perbedaannya hanya pada perkakas yang tersedia (lihat komentar
di kepala berkasnya).

Profil produksi bisa dicoba tanpa menyentuh layanan sungguhan:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
bash docs/uji/smoke-produksi.sh http://localhost:3000
```

Dengan profil itu, akun demo tidak ada (seeder mati), tanggal tidak bisa
digeser, dan CORS hanya menerima satu origin - semuanya bisa diperiksa di
laptop sebelum menyentuh server mana pun.
