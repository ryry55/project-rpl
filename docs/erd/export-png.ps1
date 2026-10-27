<#
.SYNOPSIS
    Ekspor SVG ERD menjadi PNG resolusi cetak (2x) memakai Chrome/Edge headless.

.DESCRIPTION
    Jalankan setelah "node docs/erd/build.js":
        powershell -ExecutionPolicy Bypass -File docs/erd/export-png.ps1

    Hasil: 01-erd-inti.png dan 02-erd-lengkap.png (skala 2x, latar putih).
    Berkas ini sengaja ASCII murni agar terbaca oleh PowerShell 5.1.

    Ukuran diambil dari erd-data.js, bukan dipatok: kalau kanvas diperbesar
    untuk memberi ruang entitas baru, PNG ikut membesar. Sebaliknya, angka yang
    disalin tangan akan menghasilkan PNG terpotong tanpa ada yang gagal.
#>

$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$akar = Resolve-Path (Join-Path $here '..\..')

# --- cari Chrome atau Edge ---
$candidates = @(
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
    "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
)

$browser = $candidates | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $browser) {
    throw 'Chrome atau Edge tidak ditemukan. Pasang salah satunya, atau ekspor SVG secara manual.'
}

Write-Host "Memakai: $browser"

$ukur = node -e "const d=require('./docs/erd/erd-data.js');console.log(d.INTI.kanvas.w,d.INTI.kanvas.h,d.LENGKAP.kanvas.w,d.LENGKAP.kanvas.h)"
$angka = $ukur -split '\s+'
if ($angka.Count -lt 4) { throw "gagal membaca kanvas dari erd-data.js: $ukur" }
$iW = [int]$angka[0]; $iH = [int]$angka[1]; $lW = [int]$angka[2]; $lH = [int]$angka[3]

$targets = @(
    @{ Svg = '01-erd-inti.svg'; Png = '01-erd-inti.png'; W = $iW; H = $iH },
    @{ Svg = '02-erd-lengkap.svg'; Png = '02-erd-lengkap.png'; W = $lW; H = $lH }
)

$scale = 2

foreach ($t in $targets) {
    $svgPath = Join-Path $here $t.Svg
    $pngPath = Join-Path $here $t.Png

    if (-not (Test-Path $svgPath)) {
        Write-Warning "Lewati $($t.Svg) - berkas tidak ada. Jalankan 'node docs/erd/build.js' lebih dulu."
        continue
    }

    $uri = 'file:///' + ($svgPath -replace '\\', '/')
    $w = $t.W * $scale
    $h = $t.H * $scale

    # Chrome menulis pesan kemajuan ke stderr; dengan $ErrorActionPreference = 'Stop'
    # PowerShell menganggapnya galat dan menghentikan skrip. Diredupkan sementara.
    $galatSebelumnya = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    & $browser --headless=new --disable-gpu --hide-scrollbars --default-background-color=FFFFFFFF "--force-device-scale-factor=$scale" "--window-size=$($t.W),$($t.H)" "--screenshot=$pngPath" $uri 2>&1 | Out-Null
    $ErrorActionPreference = $galatSebelumnya

    # Chrome headless kadang menulis berkas setelah proses utama selesai.
    $waited = 0
    while (-not (Test-Path $pngPath) -and $waited -lt 10000) {
        Start-Sleep -Milliseconds 250
        $waited += 250
    }

    if (Test-Path $pngPath) {
        $kb = [math]::Round((Get-Item $pngPath).Length / 1KB, 1)
        Write-Host ("  {0}  {1}x{2}px  {3} KB" -f $t.Png, $w, $h, $kb)
    } else {
        Write-Warning "Gagal membuat $($t.Png)"
    }
}

Write-Host ''
Write-Host 'Selesai. PNG siap ditempel ke dokumen Word atau slide.'
