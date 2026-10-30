# Satu perintah untuk memeriksa seluruh repo.
#
#   powershell -ExecutionPolicy Bypass -File periksa.ps1
#
# Kenapa berkas ini ada: kode dibakar ke image, bukan di-mount. Artinya
# `php artisan test` tanpa `docker compose build` lebih dulu akan menguji
# kode LAMA dan memberi hasil hijau padahal perubahan belum masuk container.
# Kegagalan seperti itu tidak berbunyi. Urutan build, seed, lalu uji karena itu
# dijadikan satu perintah, bukan dihafal.
#
# Pilihan:
#   -Cepat     lewati uji browser (E2E) dan uji balapan
#   -TanpaBuild lewati build image (hanya bila kode tidak berubah)
#
# Kode keluar 0 hanya bila semua langkah lulus.

param(
    [switch]$Cepat,
    [switch]$TanpaBuild
)

$ErrorActionPreference = 'Stop'
$gagal = @()

# Node menulis sebagian galatnya ke stderr, dan PowerShell 5.1 mengubah stderr
# menjadi error record. Dengan $ErrorActionPreference='Stop' itu MEMATIKAN
# gerbang di tengah jalan: uji yang belum berjalan tidak pernah dilaporkan, dan
# hasilnya terbaca seperti crash - bukan seperti "e2e-t25 gagal", padahal itulah
# yang perlu diperbaiki. Perintah luar karena itu dijalankan lewat fungsi ini,
# yang menurunkan preferensi ke Continue selama perintah berjalan. Keberhasilan
# tetap dinilai dari $LASTEXITCODE, bukan dari ada-tidaknya stderr.
function Jalankan([string]$perintah, [string[]]$argumen = @()) {
    if ($null -eq $argumen) { $argumen = @() }
    $lama = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $perintah @argumen 2>&1
    } finally {
        $ErrorActionPreference = $lama
    }
}
$mulai = Get-Date

# --- penjaga: satu gerbang pada satu waktu ---------------------------------
#
# Gerbang ini memakai SATU set container dan SATU basis data. Dua gerbang yang
# berjalan bersamaan akan saling menghapus basis data di tengah tes: yang satu
# menjalankan `migrate:fresh --seed` sementara yang lain sedang menguji. Hasilnya
# bukan gagal yang jelas, melainkan keluaran kosong dan pesan galat yang tidak
# berhubungan - pernah terjadi, dan menelan waktu untuk menelusurinya.
#
# Kunci ini dilepas otomatis oleh `finally` di akhir berkas, termasuk bila
# gerbang dihentikan.
$kunci = Join-Path $env:TEMP 'kos-periksa.lock'
if (Test-Path $kunci) {
    $pemilik = (Get-Content $kunci -Raw -ErrorAction SilentlyContinue)
    $masihHidup = $false
    if ($pemilik -match '^\s*(\d+)\s*$') {
        $masihHidup = [bool](Get-Process -Id ([int]$Matches[1]) -ErrorAction SilentlyContinue)
    }
    if ($masihHidup) {
        Write-Host "Gerbang lain sedang berjalan (PID $pemilik)." -ForegroundColor Red
        Write-Host "Tunggu sampai selesai - dua gerbang bersamaan akan saling merusak data uji." -ForegroundColor Red
        exit 1
    }
    Remove-Item $kunci -Force -ErrorAction SilentlyContinue
}
Set-Content -Path $kunci -Value $PID -Encoding ASCII

function Judul($teks) {
    Write-Host ''
    Write-Host "=== $teks ===" -ForegroundColor Cyan
}

function Catat($nama, $lulus, $detail = '') {
    $akhiran = if ($detail) { "  $detail" } else { '' }
    if ($lulus) {
        Write-Host "  LULUS  $nama$akhiran" -ForegroundColor Green
    } else {
        Write-Host "  GAGAL  $nama$akhiran" -ForegroundColor Red
        $script:gagal += $nama
    }
}

# --- 1. Docker hidup? -------------------------------------------------------
Judul 'Docker'
try {
    docker info 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'docker info gagal' }
    Catat 'Docker Desktop berjalan' $true
} catch {
    Catat 'Docker Desktop berjalan' $false 'jalankan Docker Desktop lebih dulu'
    Write-Host "`nBerhenti: tanpa Docker, semua langkah lain akan gagal." -ForegroundColor Red
    Remove-Item $kunci -Force -ErrorAction SilentlyContinue
    exit 1
}

# --- 2. Build ---------------------------------------------------------------
# Kode dibakar ke image. Melewati langkah ini adalah cara paling umum
# mendapatkan hasil hijau yang menyesatkan.
#
# docker menulis progres build ke stderr. Dengan $ErrorActionPreference='Stop',
# PowerShell mengubahnya menjadi galat fatal meski build BERHASIL — dilaporkan
# gagal padahal image-nya terbangun. Karena itu preferensi diturunkan sementara
# dan keberhasilan dinilai dari $LASTEXITCODE, bukan dari ada-tidaknya stderr.
function Jalankan-Docker {
    try {
        $hasil = Jalankan 'docker' $args
        $hasil | Out-Null
        return ($LASTEXITCODE -eq 0)
    } catch {
        return $false
    }
}

# Menyegarkan database demo sebelum tiap skrip uji browser.
#
# Kegagalannya dulu ditelan `| Out-Null`, dan akibatnya menyesatkan: bila
# `migrate:fresh` gagal (mis. ada container lain yang menahan koneksi), data
# skrip SEBELUMNYA masih ada, lalu skrip berikutnya gagal pada asersi yang tidak
# ada hubungannya - "opsi kontrak tidak ada" - dan yang dikejar adalah bug yang
# tidak ada. Sekarang kegagalan itu menghentikan gate di tempat kejadian.
function SegarkanDatabase {
    $hasil = Jalankan 'docker' @('compose', 'exec', '-T', 'api', 'php', 'artisan', 'migrate:fresh', '--seed')
    if ($LASTEXITCODE -ne 0) {
        Write-Host '  GAGAL  database demo gagal disegarkan - sisa data skrip sebelumnya' -ForegroundColor Red
        Write-Host '         akan membuat uji berikutnya gagal karena alasan yang salah' -ForegroundColor Red
        $hasil | Select-Object -Last 10 | ForEach-Object { Write-Host ('    ' + $_) }
        exit 1
    }
    # Cache ikut dibersihkan, bukan hanya database. Pembatas percobaan masuk
    # menyimpan hitungannya di cache, dan migrate:fresh tidak menyentuhnya.
    Jalankan 'docker' @('compose', 'exec', '-T', 'api', 'php', 'artisan', 'cache:clear') | Out-Null
}

if (-not $TanpaBuild) {
    Judul 'Build image'
    Catat 'image api dan web dibangun' (Jalankan-Docker compose build api web)
    Catat 'container berjalan' (Jalankan-Docker compose up -d)

    # Tunggu API benar-benar siap menerima permintaan.
    $siap = $false
    for ($i = 0; $i -lt 30; $i++) {
        try {
            $r = Invoke-WebRequest -Uri 'http://localhost:8081/api/me' -TimeoutSec 3 -UseBasicParsing -ErrorAction Stop
            $siap = $true
            break
        } catch {
            # 401 berarti API sudah menjawab, dan itu yang ditunggu.
            if ($_.Exception.Response.StatusCode.value__ -eq 401) { $siap = $true; break }
            Start-Sleep -Milliseconds 800
        }
    }
    Catat 'API menjawab' $siap
} else {
    Judul 'Build image'
    Write-Host '  dilewati (-TanpaBuild)' -ForegroundColor Yellow
}

# --- 3. Tes PHP -------------------------------------------------------------
Judul 'Tes PHP'
$keluaran = docker compose exec -T api php artisan test 2>&1
$tesOk = $LASTEXITCODE -eq 0

# Ringkasan "Tests:  N passed" diambil bila ADA. Versi lama langsung memakai
# `.Matches.Groups[1]` tanpa memeriksa kecocokan, sehingga keluaran kosong
# (container belum siap, atau dua gerbang berjalan bersamaan) menghasilkan
# "Cannot index into a null array" - pesan yang menyembunyikan sebab aslinya.
$cocokTes = $keluaran | Select-String -Pattern 'Tests:\s+(.+)' | Select-Object -Last 1
$ringkas = if ($cocokTes) { $cocokTes.Matches.Groups[1].Value } else { 'tidak menghasilkan ringkasan' }

if (-not $cocokTes) {
    Write-Host '  Tidak ada baris "Tests:" pada keluaran php artisan test.' -ForegroundColor Yellow
    Write-Host '  Kemungkinan container belum siap, atau ada perintah lain yang berebut container.' -ForegroundColor Yellow
    $keluaran | Select-Object -Last 12 | ForEach-Object { Write-Host ('    ' + $_) }
}

Catat 'php artisan test' $tesOk $ringkas
if (-not $tesOk) {
    # Baris yang menandakan kegagalan. Ditulis dengan pola teks biasa, bukan
    # simbol: berkas ini UTF-8 tanpa BOM, dan Windows PowerShell 5.1
    # membacanya sebagai ANSI sehingga simbol non-ASCII tampil rusak.
    $keluaran | Select-String -Pattern 'FAILED' | Select-Object -First 15 | ForEach-Object { Write-Host ('    ' + $_.Line) }
    $keluaran | Select-String -Pattern 'Tests:' | Select-Object -Last 1 | ForEach-Object { Write-Host ('    ' + $_.Line) }
}

# --- 4. Format kode ---------------------------------------------------------
# Pint dijalankan di HOST, bukan di container. Container berisi kode yang sudah
# dibakar ke image, jadi memeriksa berkas di sana berarti memeriksa versi lama:
# pint melaporkan "bersih" untuk berkas yang di host masih kotor, dan
# kegagalannya tidak berbunyi. Sudah terjadi sekali, dan tertangkap hanya
# karena hasilnya dicurigai.
#
# `pint --dirty` juga tidak bisa dipakai di dalam container: tidak ada `.git`
# di sana.
#
# Diperiksa SELURUH kode, bukan hanya berkas yang berubah. Versi sebelumnya
# hanya memeriksa berkas yang berubah, dan itu membuat repo yang tidak
# pint-clean lolos selamanya: tidak ada yang berubah, jadi tidak ada yang
# diperiksa. Saat itu terungkap ada 35 berkas yang tidak sesuai format dan
# guardrail tetap hijau.
Judul 'Format (pint pada seluruh kode)'
Push-Location api
try {
    $keluaranPint = & php vendor\bin\pint --test 2>&1
    $pintOk = $LASTEXITCODE -eq 0
} finally {
    Pop-Location
}
Catat 'pint bersih pada seluruh kode' $pintOk 'jalankan: cd api; php vendor\bin\pint'
if (-not $pintOk) {
    $keluaranPint | Select-Object -First 10 | ForEach-Object { Write-Host ('    ' + $_) }
}

# --- 5. Lint frontend -------------------------------------------------------
# `vite build` sudah menangkap galat kompilasi template, tetapi tidak
# menangkap variabel mati, `v-for` tanpa `:key`, atau komponen yang dipakai
# tanpa diimpor — ketiganya lolos build dan baru terlihat saat halamannya
# dibuka. Dijalankan di HOST: `node_modules` ada di sana setelah `npm install`.
Judul 'Lint frontend'
if (Test-Path web\node_modules) {
    Push-Location web
    try {
        $keluaranLint = Jalankan 'npm' 'run', 'lint'
        $lintOk = $LASTEXITCODE -eq 0
    } finally {
        Pop-Location
    }
    Catat 'eslint' $lintOk 'jalankan: cd web; npm run lint'
    if (-not $lintOk) {
        $keluaranLint | Select-String -Pattern 'error' | Select-Object -First 10 | ForEach-Object { Write-Host ('    ' + $_.Line.Trim()) }
    }
} else {
    Write-Host '  dilewati: web\node_modules belum ada (cd web; npm install)' -ForegroundColor Yellow
}

# --- 6. Kesesuaian dokumen --------------------------------------------------
# Angka di dokumen dibaca dari sumbernya, bukan diketik. Dua pemeriksa ini
# menutup celah yang sudah benar-benar terjadi: `KONTEKS_PROYEK.md` sempat
# menyebut 71 endpoint padahal aplikasinya 86, dan tiga judul bagian di
# `6_TOOLS_CONTRACTS.md` salah jumlah. Keduanya butuh hitungan detik, bukan
# menit, dan tidak butuh aplikasi hidup.
Judul 'Kesesuaian dokumen'

# Potret rute adalah SUMBER angka endpoint untuk pemeriksa di bawah. Tanpa
# langkah ini, gerbang memeriksa dokumen terhadap potret yang bisa basi -
# endpoint baru lolos tanpa ada yang tahu. Itu pernah terjadi: `GET
# /api/penghuni/kontrak` hidup di aplikasi tetapi tidak pernah masuk potret,
# dan ketiga pemeriksa tetap mencetak "COCOK".
$rute = @(Jalankan 'docker' 'compose', 'exec', '-T', 'api', 'php', 'artisan', 'route:list', '--json', '--path=api')
$ruteTeks = ($rute -join "`n").Trim()
$ruteOk = $LASTEXITCODE -eq 0 -and $ruteTeks.StartsWith('[')
if ($ruteOk) {
    # Ditulis lewat .NET, bukan `>` atau Out-File: PowerShell 5.1 menulis UTF-16
    # atau UTF-8 ber-BOM, dan `> docs\...` menghasilkan berkas yang tidak bisa
    # dibaca `JSON.parse` - kegagalan yang terlihat seperti potret rusak.
    [System.IO.File]::WriteAllText(
        (Join-Path (Resolve-Path 'docs\akademik').Path 'rute-api.json'),
        $ruteTeks + "`n",
        (New-Object System.Text.UTF8Encoding($false))
    )
}
Catat 'potret rute disegarkan dari aplikasi' $ruteOk

$keluaranAngka = Jalankan 'node' 'docs\uji\cek-angka-dokumen.js'
$angkaOk = $LASTEXITCODE -eq 0
Catat 'angka dokumen cocok dengan sumbernya' $angkaOk
if (-not $angkaOk) {
    $keluaranAngka | Select-String -Pattern 'BEDA|ADA ANGKA' | ForEach-Object { Write-Host ('    ' + $_.Line.Trim()) }
}

$keluaranKontrak = Jalankan 'node' 'docs\uji\cek-cakupan-kontrak.js'
$kontrakOk = $LASTEXITCODE -eq 0
Catat 'setiap endpoint tercatat di kontrak' $kontrakOk
if (-not $kontrakOk) {
    $keluaranKontrak | Select-String -Pattern 'SALAH|belum tercatat|tercatat ganda|BEDA' | ForEach-Object { Write-Host ('    ' + $_.Line.Trim()) }
}

# ERD dan LRS adalah dokumen yang paling mudah basi tanpa ketahuan: gambar
# yang salah terlihat persis seperti gambar yang benar. Pemeriksa ini
# membandingkan skema.json dengan basis data yang berjalan, lalu memastikan
# setiap tabel dan setiap kunci asing benar-benar muncul di dokumen.
$keluaranErd = Jalankan 'node' 'docs\uji\cek-erd-lrs.js'
$erdOk = $LASTEXITCODE -eq 0
Catat 'ERD dan LRS cocok dengan basis data' $erdOk
if (-not $erdOk) {
    $keluaranErd | Select-String -Pattern 'tidak|belum|sudah' | ForEach-Object { Write-Host ('    ' + $_.Line.Trim()) }
}

# DFD punya `build.js` yang menolak menulis bila ada black hole, miracle, grey
# hole, aliran tanpa rute, atau aliran batas yang tidak seimbang antar tingkat.
# Tetapi build.js TIDAK PERNAH dipanggil gerbang: sampai Oktober 2026 satu-
# satunya cara mengetahui DFD masih sehat adalah menjalankannya dengan tangan.
# Akibatnya delapan temuan audit DFD berstatus "selesai" hanya bersandar pada
# pembacaan manusia, dan regresi berikutnya tidak akan berbunyi.
#
# Pemeriksa ini menutup dua hal yang bisa dibuktikan tanpa mata: semantiknya
# bersih, dan keempat SVG yang di-commit SAMA PERSIS dengan hasil penggambaran
# data sekarang (menangkap "data diubah, gambar lupa dibangun").
$keluaranDfd = Jalankan 'node' 'docs\uji\cek-dfd.js'
$dfdOk = $LASTEXITCODE -eq 0
Catat 'DFD konsisten dan gambarnya tidak basi' $dfdOk
if (-not $dfdOk) {
    $keluaranDfd | Select-String -Pattern '^-|BEDA|tidak' | ForEach-Object { Write-Host ('    ' + $_.Line.Trim()) }
}

# --- 7. Profil produksi -----------------------------------------------------
# Berkas profil yang ada tetapi nilainya salah lebih berbahaya daripada yang
# tidak ada: ia membuat orang mengira pagarnya sudah terpasang. Diperiksa di
# sini karena `docker-compose.prod.yml` berada di akar repo - di luar build
# context image api, sehingga tes PHPUnit tidak bisa membacanya.
#
# Pemeriksanya ditulis dalam bash karena berkas yang sama dipakai CI (Linux).
# Bila bash tidak ada di mesin ini, langkahnya DILEWATI dengan peringatan -
# bukan dilaporkan lulus, karena melaporkan lulus berarti mengaku memeriksa
# sesuatu yang tidak diperiksa.
if (-not $Cepat) {
    Judul 'Profil produksi'
    $adaBash = $null -ne (Get-Command bash -ErrorAction SilentlyContinue)
    if (-not $adaBash) {
        Write-Host '  dilewati: bash tidak ada di PATH (dipakai CI, bukan langkah wajib di Windows)' -ForegroundColor Yellow
    } elseif (Test-Path docs/uji/periksa-profil-produksi.sh) {
        $keluaranProfil = Jalankan 'bash' 'docs/uji/periksa-profil-produksi.sh'
        $profilOk = $LASTEXITCODE -eq 0
        Catat 'profil produksi menutup tiga bahaya' $profilOk
        if (-not $profilOk) {
            $keluaranProfil | Select-String -Pattern '^  - ' | ForEach-Object { Write-Host ('    ' + $_.Line) }
        }
    } else {
        Catat 'profil produksi menutup tiga bahaya' $false 'docs/uji/periksa-profil-produksi.sh tidak ada'
    }

    # --- 6b. Pagar FASE 0 ---------------------------------------------------
    # Pagar yang tidak pernah dibuktikan bisa GAGAL bukan pagar. Skrip ini
    # memeriksa DB_SSLMODE, pagar MAIL_FROM_ADDRESS, OPcache produksi, dan
    # penerusan header proxy oleh nginx - seluruhnya berada di luar image api,
    # sehingga tidak satu pun bisa diperiksa tes PHPUnit.
    Judul 'Pagar FASE 0'
    if ($adaBash -and (Test-Path docs/uji/uji-pagar-fase0.sh)) {
        $keluaranPagar = Jalankan 'bash' 'docs/uji/uji-pagar-fase0.sh'
        $pagarOk = $LASTEXITCODE -eq 0
        Catat 'pagar FASE 0 (SSL, email, OPcache, header proxy)' $pagarOk
        if (-not $pagarOk) {
            $keluaranPagar | Select-String -Pattern '^  GAGAL' | ForEach-Object { Write-Host ('    ' + $_.Line) }
        }
    } else {
        Write-Host '  dilewati: bash tidak ada di PATH' -ForegroundColor Yellow
    }
}

# --- 8. Uji browser ---------------------------------------------------------
if (-not $Cepat) {
    Judul 'Uji browser (E2E)'

    # Sintaks seluruh skrip uji diperiksa LEBIH DULU, sebelum satu pun dijalankan.
    #
    # Alasannya bukan kecepatan, melainkan waktu tunggu. Tanpa langkah ini,
    # satu backtick yang salah tempat di dalam template literal baru ketahuan
    # setelah build image, seluruh tes PHP, pint, dan eslint selesai - sekitar
    # lima menit - dan pesannya muncul sebagai galat PowerShell yang menyebut baris
    # `Jalankan`, bukan baris yang rusak. `node --check` menjawabnya
    # dalam hitungan detik dan menyebut berkas serta barisnya.
    #
    # Backtick di dalam template literal memang menutup literalnya lebih awal.
    # Itu terjadi bahkan di dalam komentar, dan itulah bentuk kerusakan yang
    # paling mudah terjadi saat menyunting skrip ini.
    $rusak = @()
    foreach ($s in Get-ChildItem docs\uji\*.mjs) {
        Jalankan 'node' '--check', $s.FullName | Out-Null
        if ($LASTEXITCODE -ne 0) { $rusak += $s.Name }
    }
    Catat 'sintaks seluruh skrip uji' ($rusak.Count -eq 0) $(if ($rusak.Count -eq 0) { '' } else { 'rusak: ' + ($rusak -join ', ') })

    # Endpoint CDP dibaca dari lingkungan, sama seperti skrip Node di bawah.
    # Tanpa ini, mengeset CDP_BASE membuat pemeriksaan hidup dan pembersihan
    # tab menunjuk browser yang berbeda dari yang dipakai skrip uji.
    $cdpBase = if ($env:CDP_BASE) { $env:CDP_BASE } else { 'http://127.0.0.1:9222' }

    $chromeHidup = $false
    try {
        Invoke-RestMethod -Uri "$cdpBase/json/version" -TimeoutSec 3 | Out-Null
        $chromeHidup = $true
    } catch {
        Catat "Chrome di $cdpBase" $false 'uji browser dilewati'
    }

    if ($chromeHidup) {
        Catat "Chrome di $cdpBase" $true

        # Skrip berikut MENGUBAH database demo, jadi database di-seed ulang
        # sebelum masing-masing dijalankan.
        $e2e = @(
            'e2e-t1.mjs', 'e2e-t2.mjs', 'e2e-t3.mjs', 'e2e-t4.mjs',
            'e2e-t5.mjs', 'e2e-t6.mjs', 'e2e-t7.mjs', 'e2e-t9.mjs',
            'e2e-t18.mjs', 'e2e-t19.mjs', 'e2e-t20.mjs', 'e2e-t24.mjs',
            'e2e-t25.mjs', 'e2e-t26.mjs', 'e2e-t27.mjs', 'e2e-t28.mjs',
            # R3: pembatalan tagihan, pemaafan denda, bagikan WhatsApp,
            # rekening tujuan pembayaran.
            'e2e-r3.mjs',
            # Fase terakhir: tombol pembalik pembayaran (REV-FIN-01).
            # Endpoint-nya sudah ada sejak R2, tetapi tombolnya baru dipasang
            # sekarang - tanpa skrip ini, satu-satunya cara memakainya adalah
            # memanggil API dengan tangan.
            'uji-pembalik.mjs',
            # Fase terakhir: gambar QRIS pada rekening. Sampai sebelumnya
            # `jenis: qris` hanya menyimpan teks, padahal QRIS statis ADALAH
            # gambarnya - kode QR tidak bisa dibayar dengan mengetik nomornya.
            'uji-qris.mjs',
            'asap-semua-halaman.mjs', 'uji-tata-letak.mjs',
            # U2 (mobile/PWA) - lihat catatan di periksa.sh.
            'uji-kartu-tabel.mjs', 'uji-pwa.mjs',
            # R5: "Belum ada ..." tidak boleh muncul saat pemuatan gagal
            # (REV-UI-04). Blokade 500 dipasang lewat CDP, bukan database.
            'uji-galat-muat.mjs',
            # Fase 3.5: perintah pembuatan pemilik, penanda penyiapan, dan
            # ekspor CSV. Ketiganya menambah permukaan yang tidak disentuh
            # skrip mana pun di atas - perintah artisan yang tidak pernah
            # dibuka lewat HTTP, panel yang hanya tampil saat kos belum siap,
            # dan berkas yang diunduh alih-alih ditampilkan.
            'e2e-fase35.mjs',
            # Tiga peran BERGANTAIAN dalam satu sesi. Skrip lain memakai satu
            # peran masing-masing; tidak ada yang memeriksa apa yang terjadi
            # saat peran berganti di peramban yang sama - dan kebocoran yang
            # paling mungkin pada sistem multi-peran justru keadaan yang
            # tertinggal dari peran sebelumnya.
            'uji-tiga-peran.mjs'
        )

        # Batas waktu per skrip. `periksa.sh` sudah memakai `timeout 240`; di
        # sini tidak ada padanannya, sehingga satu skrip yang menggantung
        # menahan seluruh rangkaian tanpa mencetak namanya - dan hasilnya
        # terbaca seperti gerbang yang crash, bukan seperti "skrip X
        # menggantung". Operator `&` di PowerShell 5.1 tidak punya batas
        # waktu, jadi prosesnya dijalankan lewat Start-Process dan keluarannya
        # ditangkap lewat berkas sementara supaya tetap terbaca setelah
        # prosesnya dimatikan. 240 detik = ~3x skrip terlama (88 langkah).
        #
        # `KOS_BATAS_SKRIP` ada untuk membuktikan batas ini benar-benar
        # bekerja tanpa menunggu empat menit: setel 2, jalankan satu skrip
        # yang menggantung, dan ia harus dilaporkan GAGAL.
        $batasSkrip = if ($env:KOS_BATAS_SKRIP) { [int]$env:KOS_BATAS_SKRIP } else { 240 }

        function JalankanBerbatas([string]$berkas) {
            # `Start-Process -PassThru` di PowerShell 5.1 mengembalikan objek
            # yang `ExitCode`-nya KOSONG walau prosesnya sudah selesai (diuji:
            # node keluar 3, terbaca kosong), dan kosong terbaca sebagai
            # gagal - seluruh skrip akan dilaporkan GAGAL. ProcessStartInfo
            # dipakai karena mengisi ExitCode dengan benar.
            $psi = New-Object System.Diagnostics.ProcessStartInfo
            $psi.FileName = 'node'
            $psi.Arguments = '"' + $berkas + '"'
            $psi.UseShellExecute = $false
            $psi.RedirectStandardOutput = $true
            $psi.RedirectStandardError = $true
            $p = [System.Diagnostics.Process]::Start($psi)
            $keluar = $p.StandardOutput.ReadToEndAsync()
            $galat = $p.StandardError.ReadToEndAsync()
            $lewat = -not $p.WaitForExit($batasSkrip * 1000)
            if ($lewat) {
                # /T ikut mematikan proses anak; tanpa itu sisa proses bisa
                # menahan pipe dan skrip berikutnya menunggu selamanya.
                Jalankan 'taskkill' @('/PID', "$($p.Id)", '/T', '/F') | Out-Null
                $p.WaitForExit(5000) | Out-Null
            }
            # Aliran keluaran ditutup setelah prosesnya berhenti; menunggu di
            # sini mencegah hasil kosong karena dibaca terlalu cepat.
            $teks = @((($keluar.Result + "`n" + $galat.Result) -split "`r?`n") | Where-Object { $_ -ne '' })
            return [pscustomobject]@{
                Keluaran   = $teks
                Kode       = $(if ($lewat) { 1 } else { $p.ExitCode })
                LewatWaktu = $lewat
            }
        }

        foreach ($s in $e2e) {
            # Tab yatim dari percobaan sebelumnya membuat langkah login gagal
            # karena alasan yang tidak ada hubungannya dengan aplikasi.
            try {
                $tabs = Invoke-RestMethod -Uri "$cdpBase/json/list" -TimeoutSec 5
                $tabs | Where-Object { $_.type -eq 'page' } | ForEach-Object {
                    Invoke-RestMethod -Uri ("$cdpBase/json/close/" + $_.id) -TimeoutSec 5 | Out-Null
                }
            } catch { }

            SegarkanDatabase

            $hasil = JalankanBerbatas "docs/uji/$s"
            $akhir = ($hasil.Keluaran | Select-String -Pattern 'langkah lulus' | Select-Object -Last 1).Line
            $detail = if ($hasil.LewatWaktu) { "batas waktu $batasSkrip detik terlampaui" } else { $akhir }
            Catat $s ($hasil.Kode -eq 0) $(if ($detail) { $detail } else { '' })
            if ($hasil.Kode -ne 0) {
                $hasil.Keluaran | Select-String -Pattern '^GAGAL|E2E gagal' | ForEach-Object { Write-Host ('    ' + $_.Line) }
            }
        }

        Judul 'Uji balapan'
        foreach ($s in @('uji-balapan-t4.mjs', 'uji-balapan-t5.mjs')) {
            SegarkanDatabase
            $hasil = JalankanBerbatas "docs/uji/$s"
            $akhir = ($hasil.Keluaran | Select-String -Pattern 'langkah lulus' | Select-Object -Last 1).Line
            $detail = if ($hasil.LewatWaktu) { "batas waktu $batasSkrip detik terlampaui" } else { $akhir }
            Catat $s ($hasil.Kode -eq 0) $(if ($detail) { $detail } else { '' })
        }

        # Jalur proxy (FASE 0.1b). Inilah uji yang selama ini TIDAK ADA, dan
        # itulah sebabnya cacat A1 lolos 748 tes PHP plus 27 skrip E2E tanpa
        # pernah ketahuan: E2E menguji apa yang tampak di layar, sedangkan IP
        # tidak pernah tampil di layar.
        #
        # Dijalankan lewat port PROXY (:3000), bukan langsung ke API. Pada jalur
        # langsung tidak ada yang menambahkan X-Forwarded-For, sehingga ujinya
        # akan lulus meski perbaikannya tidak ada - dan uji yang lulus tanpa
        # perbaikan lebih buruk daripada tidak ada uji.
        #
        # Cache dibersihkan lebih dulu karena pembatas periksa-token menyimpan
        # hitungannya di sana; sisa hitungan dari skrip sebelumnya akan membuat
        # ujinya gagal karena sebab yang tidak ada hubungannya.
        Judul 'Jalur proxy'
        docker compose exec -T api php artisan cache:clear 2>&1 | Out-Null
        $hasilProxy = JalankanBerbatas 'docs/uji/uji-proxy.mjs'
        $akhirProxy = ($hasilProxy.Keluaran | Select-String -Pattern 'langkah lulus' | Select-Object -Last 1).Line
        $detailProxy = if ($hasilProxy.LewatWaktu) { "batas waktu $batasSkrip detik terlampaui" } else { $akhirProxy }
        Catat 'uji-proxy.mjs' ($hasilProxy.Kode -eq 0) $(if ($detailProxy) { $detailProxy } else { '' })
        if ($hasilProxy.Kode -ne 0) {
            $hasilProxy.Keluaran | Select-String -Pattern '^GAGAL' | ForEach-Object { Write-Host ('    ' + $_.Line) }
        }
    }
} else {
    Judul 'Uji browser'
    Write-Host '  dilewati (-Cepat)' -ForegroundColor Yellow
}

# --- Ringkasan --------------------------------------------------------------
# Kunci dilepas SEBELUM keluar, apa pun hasilnya. Tanpa ini, satu kegagalan
# akan membuat gerbang berikutnya menolak berjalan karena mengira masih ada
# gerbang lain - dan penolakan itu sendiri membingungkan.
Remove-Item $kunci -Force -ErrorAction SilentlyContinue

$durasi = [int]((Get-Date) - $mulai).TotalSeconds
Write-Host ''
if ($gagal.Count -eq 0) {
    Write-Host "SEMUA LULUS  ($durasi detik)" -ForegroundColor Green
    exit 0
} else {
    Write-Host ("GAGAL: " + $gagal.Count + " langkah  ($durasi detik)") -ForegroundColor Red
    $gagal | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }
    exit 1
}

