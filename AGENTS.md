## Agent skills

### Issue tracker

GitHub Issues, driven with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Five canonical roles, each label string equal to its role name. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.

## Serah-terima antar sesi

Pekerjaan dibagi per fase (R1..R7) dan **tiap fase dikerjakan di sesi baru**,
supaya konteks tidak menumpuk. Karena sesi baru mulai tanpa ingatan, perpindahan
hanya aman bila tiga hal ini benar:

1. **Fase belum selesai kalau belum di-commit.** Working tree kotor adalah biaya
   terbesar bagi sesi berikutnya: ia harus merekonstruksi "apa yang belum masuk
   dan kenapa", dan `git diff` tidak bisa menjawabnya.
2. **`docs/serah-terima.md` ditimpa di akhir setiap fase**, lalu ikut di-commit
   bersama pekerjaannya. Isinya: fase terakhir, keadaan tree, tiga aksi
   berikutnya, keputusan terkunci, jebakan fase berikutnya. Maksimal 120 baris —
   lebih dari itu ia menjadi `SPEC.md` kedua.
3. **Jebakan baru langsung naik ke berkas ini**, tidak boleh hanya tinggal di
   percakapan. Jebakan yang hanya diketahui satu sesi akan ditemukan ulang lewat
   kegagalan yang sama.

Sesi baru wajib membaca, berurut: `docs/serah-terima.md` → bagian "Jebakan yang
sudah menggigit" di bawah → `SPEC.md` bagian yang disentuh fase itu. **Jangan
membaca seluruh `SPEC.md`** (828 baris) kalau hanya satu bagian yang relevan.

Berkas serah-terima memuat perintah untuk memverifikasi klaimnya sendiri. Kalau
angkanya tidak cocok dengan kenyataan, berkas itu basi — perbarui dulu, baru
bekerja.

### Kalau sesi butuh keputusan pemilik

Sesi tanpa ingatan punya satu cara gagal yang lebih berbahaya daripada lupa:
**menebak**. Tebakan yang masuk ke kode terlihat seperti pekerjaan selesai, dan
pemilik baru tahu saat sidang. Karena itu bertanya bukan kegagalan, menebak
adalah.

**Wajib bertanya** bila jawabannya menyentuh salah satu dari ini:

| Menyentuh | Contoh |
|---|---|
| Uang | Rumus, pembulatan, kapan denda berhenti, apa yang boleh diubah setelah terbit |
| Perilaku yang dilihat pengguna | Tombol baru, status baru, pesan galat, siapa boleh melihat apa |
| Cakupan | Menambah/mengurangi fitur, endpoint, tabel, atau use case |
| Keputusan terkunci | Apa pun di `SPEC.md` §4-§7 dan `docs/serah-terima.md` §3 |

**Boleh memutuskan sendiri** bila menyangkut cara, bukan apa: nama variabel,
urutan kueri, bentuk internal fungsi, cara menulis tes. Putuskan, lalu sebutkan
di pesan commit.

**Tidak boleh** mengubah `SPEC.md`, `revisi.md`, atau keputusan terkunci atas
inisiatif sendiri. Menemukan alasan kuat bahwa keputusan itu salah = bahan
pertanyaan, bukan izin.

**Salurannya tergantung moda:**

- **Moda berantai** (sesi induk melepas sub-sesi): sub-sesi **berhenti** dan
  melaporkan pertanyaannya di jawaban akhir. Sesi induk meneruskannya ke pemilik
  dan melanjutkan sub-sesi itu lewat `sessionID`. Tidak perlu menulis berkas.
- **Moda sesi baru** (opencode baru tanpa sesi induk): tidak ada yang
  meneruskan, jadi tulis pertanyaannya ke **`docs/keputusan-terbuka.md`**, commit
  berkas itu sendirian (`docs: keputusan terbuka K-xx`), lalu berhenti. Jangan
  menunggu di dalam sesi — tidak ada yang akan menjawab.

**Jawaban pemilik juga harus menetap.** Setelah dijawab, tulis jawabannya ke
entri yang sama di `docs/keputusan-terbuka.md` dengan status `dijawab` dan
tanggalnya, lalu commit. Tanpa itu pertanyaan yang sama akan ditanyakan ulang
oleh sesi berikutnya, dan pemilik akan menjawab dua kali.

## Menjalankan dan memeriksa

Satu perintah memeriksa seluruh repo:

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1
```

Ia menjalankan build image, tes PHP, pint, lalu seluruh uji browser dan uji balapan.
`-Cepat` melewati uji browser. **Pakai ini, jangan menjalankan tes satu per satu** —
urutan langkahnya menentukan, dan salah urutan menghasilkan hijau yang menyesatkan
(lihat di bawah).

### CI/CD

Tiga workflow di `.github/workflows/`: `uji.yml` (kebenaran kode - lint lalu 812
tes PHP lalu 29 skrip uji di Chrome headless), `rilis.yml` (bangun image,
dorong ke GHCR, staging lalu produksi), dan `deploy.yml` (satu lingkungan,
dipanggil dua kali). Rinciannya di `docs/ci-cd.md`.

Uji browser **tidak** ditulis ulang dengan Playwright. Dua puluh sembilan skrip browser di
`docs/uji/` berbicara CDP mentah, dan endpoint CDP-nya dapat diatur lewat
`CDP_BASE`/`APP_URL`/`API_URL`/`MAILPIT_URL` - jadi skrip yang sama berjalan di
Chrome berjendela di laptop dan di Chrome headless di runner tanpa satu baris pun
diubah. Menulis ulang berarti membuang bukti yang sudah ada dan membuat dua
rangkaian uji yang akan berbeda pendapat.

Di Linux dan di CI, kembaran `periksa.ps1` adalah `./periksa.sh` (pilihan
`--cepat`, `--tanpa-build`, `--hanya-e2e`). `uji.yml` memeriksa bahwa daftar
skrip uji di keduanya identik: skrip yang hanya didaftarkan di salah satunya
akan lolos dari guardrail selamanya tanpa ada yang memberi tahu.

### Jebakan yang sudah menggigit

- **Kode dibakar ke image, bukan di-mount.** `php artisan test` tanpa
  `docker compose build api` menguji kode LAMA dan memberi hasil hijau padahal
  perubahan belum masuk container. Tidak ada galat yang muncul. Hal yang sama
  berlaku untuk `web`. `periksa.ps1` melakukan build lebih dulu karena itu.
- **Tes harus lewat container.** PHP lokal (8.3.30) tidak punya `pdo_pgsql`:
  `docker compose exec api php artisan test`. Pengecualiannya pint, yang justru
  harus di HOST — di dalam container ia memeriksa kode lama.
- **`api-web` menyimpan IP lama `api`.** nginx menyelesaikan nama `api` sekali
  saat start; begitu container `api` dibangun ulang dan IP-nya berubah, setiap
  permintaan jadi 502 "Connection refused" sampai `api-web` di-restart manual.
  Gejalanya menyesatkan: 799 tes hijau, lalu gate merah di satu baris
  `API menjawab`. Sejak 29 Oktober 2026 `default.conf` memakai
  `resolver 127.0.0.11` + `set $api_upstream api:9000`, jadi nama itu
  diselesaikan ulang. Jangan kembalikan `fastcgi_pass api:9000` langsung.
- **`add_header` nginx tidak diwarisi oleh lokasi yang punya `add_header` sendiri.** Halaman SPA
  dilayani lewat internal redirect `try_files` ke `location = /index.html`, dan lokasi itu memasang
  `add_header Cache-Control` sendiri. Akibatnya setiap header di level `server` (`nosniff`,
  `X-Frame-Options`, `Referrer-Policy`, lalu HSTS dan CSP) **hilang tepat di halaman utama** —
  satu-satunya respons yang paling membutuhkannya. Gejalanya senyap: `curl` ke `/api/health`
  memperlihatkan semua header, sedangkan `curl` ke `/` tidak. Header keamanan karena itu diulang
  di lokasi itu, dan komentarnya jangan dihapus.
- **Kolom yang `nullable` di validasi belum tentu `NOT NULL` di basis data.** `komponen_biaya.satuan`
  punya `default('rupiah/bulan')`, tetapi formulir yang membiarkan kolomnya kosong mengirim `null`
  eksplisit (karena `ConvertEmptyStringsToNull`), dan `null` eksplisit **mengalahkan bawaan kolom**:
  hasilnya `SQLSTATE[23502]`, 500, bukan 422. Perbaikannya membuang kuncinya sebelum `create()`
  (`PenerapPerubahan::komponen()`), lalu `refresh()` supaya respons dan jejak audit sama dengan isi
  tabel. Sebelum menambah kolom berdefault, periksa apakah request-nya mengizinkan `null` untuk kolom
  yang sama.
- **Tab Chrome yatim merusak E2E berikutnya.** Bersihkan sebelum menjalankan:
  `Invoke-RestMethod http://127.0.0.1:9222/json/list`, lalu `/json/close/<id>`
  untuk tiap entri `page`. `periksa.ps1` melakukannya otomatis.
- **Database demo diubah oleh uji browser.** Jalankan
  `docker compose exec api php artisan migrate:fresh --seed` sebelum tiap skrip.
  Skrip asap adalah pengecualian: ia idempoten, jadi boleh dijalankan berulang
  tanpa menyegarkan database.
- **Perekam galat yang dipasang lewat `evaluasi` mati saat navigasi.** `Page.navigate`
  memuat ulang dokumen, sehingga `window.__rekam` hilang dan setiap halaman
  lulus dengan "tidak ada galat konsol" padahal tidak ada yang diperiksa.
  Pasang lewat `Page.addScriptToEvaluateOnNewDocument` (lihat `pasangPerekam` di
  `asap-semua-halaman.mjs`), dan sertakan kontrol negatif yang sengaja melempar
  galat untuk membuktikan perekam masih hidup.
- **`Network.emulateNetworkConditions` berlaku per target, dan service worker
  adalah target tersendiri.** Mematikan jaringan di target halaman **tidak**
  mematikan `fetch()` di dalam `sw.js`: navigasi "luring" tetap dilayani
  jaringan, dan uji luring hijau tanpa membuktikan cache. Gejalanya senyap -
  nginx mencatat `GET /` dari `sw.js` pada navigasi yang seharusnya luring.
  Terbukti dari kontrol negatif: seluruh isi Cache Storage dihapus dan halaman
  **tetap terbuka**. Yang benar: sambung ke target peramban
  (`GET /json/version` → `webSocketDebuggerUrl`), `Target.attachToTarget
  { flatten: true }` pada target `service_worker`, lalu kirim
  `Network.emulateNetworkConditions` dengan `sessionId` itu (`kirim()` di
  `_cdp.mjs` menerima parameter sesi). Melepas sesinya mengembalikan jaringan.
  `Network.clearBrowserCache` wajib didahulukan, kalau tidak salinan HTML di
  memori peramban menutupi peran cache service worker. Perintah ke target yang
  sudah mati tidak dijawab dan tidak gagal - beri batas waktu.
- **Asersi yang bergantung pada tanggal sistem akan gagal sendiri.** Dua skrip E2E
  memeriksa tagihan yang jatuh tempo 15 Okt. Asersi "denda masih 0 saat terbit"
  benar selama hari ini <= 22 Okt (hari terlambat 7, masih tenggang) dan gagal
  mulai 23 Okt tanpa satu baris kode pun berubah. Perbaikannya bukan menunggu,
  tetapi menyematkan tanggal lewat header `X-Simulasi-Tanggal`, atau
  membandingkan dengan nilai dari API alih-alih angka hardcoded. Kalau sebuah
  asersi menyebut "hari ini", "Denda Rp 0", atau tanggal tertentu, curigai ia.
- **Jangan menyunting skrip shell saat ia sedang berjalan.** Bash membaca
  berkas secara bertahap berdasarkan offset byte, bukan memuatnya utuh di awal.
  Menyisipkan beberapa baris di dekat atas `periksa.sh` saat ia masih
  mengeksekusi uji membuat bash melanjutkan dari offset lama, yaitu di tengah
  baris - hasilnya `syntax error near unexpected token ')'` pada baris yang
  isinya tidak salah, dan `SEMUA LULUS` tidak pernah tercetak walau seluruh uji
  lulus. Sunting dulu, jalankan kemudian.
- **Kegagalan `migrate:fresh` dulu ditelan gate.** `periksa.ps1` dan `periksa.sh`
  menjalankan `migrate:fresh --seed` dengan keluaran dibuang, sehingga bila
  penyegaran itu gagal - misalnya ada container lain yang masih menahan koneksi
  ke database `kos` - data skrip SEBELUMNYA tetap ada dan skrip berikutnya gagal
  pada asersi yang tidak berhubungan. Gejalanya `opsi kontrak tidak ada` di
  `e2e-t3` plus sepuluh kegagalan turunan, padahal aplikasinya benar. Sejak
  29 Oktober keduanya memanggil `SegarkanDatabase`/`segarkan`, yang berhenti
  dengan pesan jelas. Jangan mengembalikan `| Out-Null`, dan jangan meninggalkan
  container lain (`docker run` image produksi) yang terkoneksi ke database yang
  sama selama gate berjalan.
- **Pembatas percobaan masuk menyimpan hitungannya di CACHE, bukan di
  database.** `migrate:fresh --seed` tidak menyentuhnya, jadi kegagalan login
  yang sengaja dilakukan satu skrip E2E membuat skrip berikutnya menerima 429
  pada login yang BENAR - gejalanya berpindah-pindah seperti gangguan jaringan.
  `periksa.sh` dan `periksa.ps1` karena itu menjalankan `cache:clear` di samping
  `migrate:fresh` sebelum tiap skrip. Kalau sebuah uji tiba-tiba gagal di
  langkah login padahal kredensialnya benar, periksa penghitung ini lebih dulu:
  `masuk:<email>|<ip>` (5/menit) dan `masuk-email:<email>` (20/jam).
- **Satu komponen yang dirender dua kali punya dua state.** `LoncengNotifikasi`
  sempat dipasang di bilah atas DAN di sidebar dengan maksud "yang satu untuk
  layar sempit". Keduanya hidup bersamaan dan menghitung belum-dibaca sendiri:
  menandai terbaca di panel yang satu tidak mengubah lencana yang lain, jadi
  `e2e-t25` gagal pada asersi yang benar. Yang benar: satu instance, dipilih
  lewat `v-if` dari `matchMedia` (`layarSempit` di `TataLetakPemilik.vue`).
  Gejalanya menyesatkan - tampak seperti bug API karena angka di layar berbeda
  dari angka di basis data.
- **`node ... 2>&1` langsung mematikan gate, bukan menggagalkannya.** Di
  PowerShell 5.1 dengan `$ErrorActionPreference='Stop'`, satu baris ke stderr
  menjadi `NativeCommandError` dan `periksa.ps1` berhenti di situ: uji
  sesudahnya tidak pernah dilaporkan sebagai GAGAL, dan hasilnya terbaca seperti
  crash. Semua perintah luar kini lewat fungsi `Jalankan`, yang menurunkan
  preferensi hanya selama perintah berjalan. Jangan menambah panggilan
  `& node`, `& npm`, atau `bash` langsung di skrip itu.

### Menulis skrip uji

Skrip browser menulis ulang tangkapan layar di `docs/uji/`. Berkas itu ikut
di-track, jadi setiap kali E2E dijalankan, working tree jadi kotor walau tidak
ada kode yang berubah. Sebelum commit, kembalikan yang tidak relevan:
`git checkout -- docs/uji/*.png`. Kalau sebuah screenshot memang perlu
diperbarui, sebutkan di pesan commit bahwa perubahannya disengaja.

Skrip browser memakai `docs/uji/_cdp.mjs` — jangan menyalin helper CDP ke berkas
baru. Untuk memeriksa teks di layar pakai `teksMemuat(teks, '15.000')`, bukan
regex: `/15\\.000/` di dalam template literal berarti "backslash literal" dan
asersinya tidak pernah cocok. Kesalahan itu sudah terjadi tiga kali (T5, T6, T9).

**Akun baru selalu mendarat di gerbang PDP lebih dulu** (T28). Skrip yang
menguji hal lain wajib memanggil `lewatiPersetujuanPdp(ws)` tepat sesudah
`masukSebagai()` untuk akun yang baru dibuat; tanpa itu langkah pertamanya
gagal dan pesannya terbaca seperti kebocoran data atau tombol yang hilang.
Fungsinya tidak melakukan apa pun bila gerbangnya tidak muncul.

**Backtick di dalam komentar pun memutus template literal.** Seluruh pemeriksaan
DOM dikirim sebagai template literal, jadi satu backtick di komentar membuat
`node --check` gagal dengan pesan yang menunjuk baris yang salah - dan baru
ketahuan setelah build, 738 tes PHP, pint, dan eslint selesai. `periksa.ps1`
sekarang menjalankan `node --check` pada semua `.mjs` lebih dulu, tetapi jalankan
sendiri saat menyunting satu berkas: `node --check docs/uji/<berkas>.mjs`.

### PowerShell

`git commit -m` memotong pesan panjang; tulis ke berkas, pakai `-F <berkas>`,
lalu hapus. `>` menulis UTF-16 (pakai `cmd /c "..." > berkas`). Menyalurkan
keluaran `gh` lewat `|` merusak UTF-8. Skrip `.ps1` di repo ini disimpan UTF-8
tanpa BOM dan dibaca Windows PowerShell 5.1 sebagai ANSI — jangan menaruh simbol
non-ASCII di dalamnya.

**`Set-Content -Encoding UTF8` di Windows PowerShell 5.1 menulis BOM**, dan BOM
itu mematikan berkas PHP: `Namespace declaration statement has to be the very
first statement`. Gejalanya menyesatkan ke arah yang mahal - `docker compose
build api` gagal di `composer dump-autoload` (exit 255), lalu `php artisan test`
berjalan terhadap image **LAMA** dan memberi hijau yang tidak menguji apa pun.
Sunting berkas PHP dengan tool `edit`, bukan dengan `Set-Content`/`-replace`.
Kalau sudah terlanjur: `[IO.File]::ReadAllBytes(...)[0..2]` yang bernilai
`239,187,191` adalah BOM.

**Jangan memakai PowerShell untuk mengganti teks di banyak berkas.** Ini sudah
menghasilkan kerusakan senyap: satu loop atas `@(@('berkas','cari','ganti'), ...)`
dengan `$pair[0]` di dalam `-replace` tidak mengganti string, melainkan
**karakter**: `'b'` menjadi `'a'`, `'c'` menjadi `'o'`, dan seterusnya. Tidak ada
galat, tidak ada pesan - hasilnya 1065 baris berubah di satu berkas dan huruf
hilang dari tujuh berkas lain. Gejalanya baru terlihat di tangkapan layar.
Penanda bahwa itu terjadi: jumlah karakter sebuah berkas berubah tanpa
`git diff` yang masuk akal. Penggantian teks lintas berkas dilakukan dengan
tool `edit` (satu pola, terverifikasi unik) atau skrip Node yang menghitung
jumlah kemunculan lebih dulu dan berhenti bila angkanya tidak sesuai. Jerat
serupa: `node -e "..."` lewat PowerShell juga merusak tanda kutip - tulis
berkas `.js` dulu.

## Dokumen

- `SPEC.md` — kontrak implementasi: aturan bisnis, keputusan, model data, API.
- `docs/tiket.md` — daftar tiket dan urutan pengerjaan.
- `docs/uji/audit-ketertelusuran.md` — peta aturan → implementasi → tes.
- `docs/uji/README.md` — indeks skrip uji beserta prasyaratnya.
