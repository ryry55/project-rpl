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
$mulai = Get-Date

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
    $lama = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & docker @args 2>&1 | Out-Null
        return ($LASTEXITCODE -eq 0)
    } catch {
        return $false
    } finally {
        $ErrorActionPreference = $lama
    }
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
$ringkas = ($keluaran | Select-String -Pattern 'Tests:\s+(.+)' | Select-Object -Last 1).Matches.Groups[1].Value
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
        $keluaranLint = & npm run lint 2>&1
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

# --- 6. Uji browser ---------------------------------------------------------
if (-not $Cepat) {
    Judul 'Uji browser (E2E)'

    # Sintaks seluruh skrip uji diperiksa LEBIH DULU, sebelum satu pun dijalankan.
    #
    # Alasannya bukan kecepatan, melainkan waktu tunggu. Tanpa langkah ini,
    # satu backtick yang salah tempat di dalam template literal baru ketahuan
    # setelah build image, 594 tes PHP, pint, dan eslint selesai - sekitar lima
    # menit - dan pesannya muncul sebagai galat PowerShell yang menyebut baris
    # `$hasil = node ...`, bukan baris yang rusak. `node --check` menjawabnya
    # dalam hitungan detik dan menyebut berkas serta barisnya.
    #
    # Backtick di dalam template literal memang menutup literalnya lebih awal.
    # Itu terjadi bahkan di dalam komentar, dan itulah bentuk kerusakan yang
    # paling mudah terjadi saat menyunting skrip ini.
    $rusak = @()
    foreach ($s in Get-ChildItem docs\uji\*.mjs) {
        & node --check $s.FullName 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { $rusak += $s.Name }
    }
    Catat 'sintaks seluruh skrip uji' ($rusak.Count -eq 0) $(if ($rusak.Count -eq 0) { '' } else { 'rusak: ' + ($rusak -join ', ') })

    $chromeHidup = $false
    try {
        Invoke-RestMethod -Uri 'http://127.0.0.1:9222/json/version' -TimeoutSec 3 | Out-Null
        $chromeHidup = $true
    } catch {
        Catat 'Chrome dengan --remote-debugging-port=9222' $false 'uji browser dilewati'
    }

    if ($chromeHidup) {
        Catat 'Chrome dengan --remote-debugging-port=9222' $true

        # Skrip berikut MENGUBAH database demo, jadi database di-seed ulang
        # sebelum masing-masing dijalankan.
        $e2e = @(
            'e2e-t1.mjs', 'e2e-t2.mjs', 'e2e-t3.mjs', 'e2e-t4.mjs',
            'e2e-t5.mjs', 'e2e-t6.mjs', 'e2e-t7.mjs', 'e2e-t9.mjs',
            'e2e-t18.mjs', 'e2e-t19.mjs', 'e2e-t20.mjs', 'e2e-t24.mjs',
            'e2e-t25.mjs', 'e2e-t26.mjs', 'e2e-t27.mjs', 'e2e-t28.mjs',
            'asap-semua-halaman.mjs', 'uji-tata-letak.mjs'
        )

        foreach ($s in $e2e) {
            # Tab yatim dari percobaan sebelumnya membuat langkah login gagal
            # karena alasan yang tidak ada hubungannya dengan aplikasi.
            try {
                $tabs = Invoke-RestMethod -Uri 'http://127.0.0.1:9222/json/list' -TimeoutSec 5
                $tabs | Where-Object { $_.type -eq 'page' } | ForEach-Object {
                    Invoke-RestMethod -Uri ("http://127.0.0.1:9222/json/close/" + $_.id) -TimeoutSec 5 | Out-Null
                }
            } catch { }

            docker compose exec -T api php artisan migrate:fresh --seed 2>&1 | Out-Null

            $hasil = node "docs/uji/$s" 2>&1
            $akhir = ($hasil | Select-String -Pattern 'langkah lulus' | Select-Object -Last 1).Line
            Catat $s ($LASTEXITCODE -eq 0) $(if ($akhir) { $akhir } else { '' })
            if ($LASTEXITCODE -ne 0) {
                $hasil | Select-String -Pattern '^GAGAL|E2E gagal' | ForEach-Object { Write-Host ('    ' + $_.Line) }
            }
        }

        Judul 'Uji balapan'
        foreach ($s in @('uji-balapan-t4.mjs', 'uji-balapan-t5.mjs')) {
            docker compose exec -T api php artisan migrate:fresh --seed 2>&1 | Out-Null
            $hasil = node "docs/uji/$s" 2>&1
            $akhir = ($hasil | Select-String -Pattern 'langkah lulus' | Select-Object -Last 1).Line
            Catat $s ($LASTEXITCODE -eq 0) $(if ($akhir) { $akhir } else { '' })
        }
    }
} else {
    Judul 'Uji browser'
    Write-Host '  dilewati (-Cepat)' -ForegroundColor Yellow
}

# --- Ringkasan --------------------------------------------------------------
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

