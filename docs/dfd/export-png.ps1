<#
.SYNOPSIS
    Ekspor SVG DFD menjadi PNG resolusi cetak (2x) memakai Chrome/Edge headless.

.DESCRIPTION
    Jalankan setelah "node build.js":
        powershell -ExecutionPolicy Bypass -File export-png.ps1

    Hasil: 01-dfd-konteks.png dan 02-dfd-level-1.png (skala 2x, latar putih).
    Berkas ini sengaja ASCII murni agar terbaca oleh PowerShell 5.1.
#>

$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path

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

# --- daftar gambar: berkas svg, tinggi ---
# Lebar dan tinggi DIBACA dari dfd-data.js, bukan dipatok. Sebelumnya angka di
# sini disalin tangan, dan ketika kanvas konteks bertambah tinggi untuk
# menampung entitas keempat, PNG tetap diekspor 1900x1000 sehingga gambar
# terpotong tanpa ada yang gagal.
$ukur = node -e "const d=require('./docs/dfd/dfd-data.js');const g=require('fs').readFileSync('./docs/dfd/dfd-gambar.js','utf8').match(/GESER_LEVEL1\s*=\s*(\d+)/);console.log(d.KANVAS.konteks.w,d.KANVAS.konteks.h,d.KANVAS.level1.w,d.KANVAS.level1.h+Number(g[1]))"
$angka = $ukur -split '\s+'
if ($angka.Count -lt 4) { throw "gagal membaca KANVAS dari dfd-data.js: $ukur" }
$kW = [int]$angka[0]; $kH = [int]$angka[1]; $lW = [int]$angka[2]; $lH = [int]$angka[3]

$targets = @(
    @{ Svg = '01-dfd-konteks.svg'; Png = '01-dfd-konteks.png'; W = $kW; H = $kH },
    @{ Svg = '02-dfd-level-1.svg'; Png = '02-dfd-level-1.png'; W = $lW; H = $lH },
    @{ Svg = '01-dfd-konteks-yourdon.svg'; Png = '01-dfd-konteks-yourdon.png'; W = $kW; H = $kH },
    @{ Svg = '02-dfd-level-1-yourdon.svg'; Png = '02-dfd-level-1-yourdon.png'; W = $lW; H = $lH }
)

$scale = 2

foreach ($t in $targets) {
    $svgPath = Join-Path $here $t.Svg
    $pngPath = Join-Path $here $t.Png

    if (-not (Test-Path $svgPath)) {
        Write-Warning "Lewati $($t.Svg) - berkas tidak ada. Jalankan 'node build.js' lebih dulu."
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
