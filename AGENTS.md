## Agent skills

### Issue tracker

GitHub Issues, driven with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Five canonical roles, each label string equal to its role name. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.

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

Tiga workflow di `.github/workflows/`: `uji.yml` (kebenaran kode - lint lalu 599
tes PHP lalu 18 skrip browser di Chrome headless), `rilis.yml` (bangun image,
dorong ke GHCR, staging lalu produksi), dan `deploy.yml` (satu lingkungan,
dipanggil dua kali). Rinciannya di `docs/ci-cd.md`.

Uji browser **tidak** ditulis ulang dengan Playwright. Delapan belas skrip di
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
- **Pembatas percobaan masuk menyimpan hitungannya di CACHE, bukan di
  database.** `migrate:fresh --seed` tidak menyentuhnya, jadi kegagalan login
  yang sengaja dilakukan satu skrip E2E membuat skrip berikutnya menerima 429
  pada login yang BENAR - gejalanya berpindah-pindah seperti gangguan jaringan.
  `periksa.sh` dan `periksa.ps1` karena itu menjalankan `cache:clear` di samping
  `migrate:fresh` sebelum tiap skrip. Kalau sebuah uji tiba-tiba gagal di
  langkah login padahal kredensialnya benar, periksa penghitung ini lebih dulu:
  `masuk:<email>|<ip>` (5/menit) dan `masuk-email:<email>` (20/jam).

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
ketahuan setelah build, 594 tes PHP, pint, dan eslint selesai. `periksa.ps1`
sekarang menjalankan `node --check` pada semua `.mjs` lebih dulu, tetapi jalankan
sendiri saat menyunting satu berkas: `node --check docs/uji/<berkas>.mjs`.

### PowerShell

`git commit -m` memotong pesan panjang; tulis ke berkas, pakai `-F <berkas>`,
lalu hapus. `>` menulis UTF-16 (pakai `cmd /c "..." > berkas`). Menyalurkan
keluaran `gh` lewat `|` merusak UTF-8. Skrip `.ps1` di repo ini disimpan UTF-8
tanpa BOM dan dibaca Windows PowerShell 5.1 sebagai ANSI — jangan menaruh simbol
non-ASCII di dalamnya.

## Dokumen

- `SPEC.md` — kontrak implementasi: aturan bisnis, keputusan, model data, API.
- `docs/tiket.md` — daftar tiket dan urutan pengerjaan.
- `docs/uji/audit-ketertelusuran.md` — peta aturan → implementasi → tes.
- `docs/uji/README.md` — indeks skrip uji beserta prasyaratnya.
