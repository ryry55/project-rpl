<#
.SYNOPSIS
    Ekspor SELURUH SVG ERD berjenjang menjadi PNG resolusi cetak (2x).

.DESCRIPTION
    Jalankan setelah "node docs/erd/build-tingkat.js":
        powershell -ExecutionPolicy Bypass -File docs/erd/export-png.ps1

    Ukuran diambil dari atribut width/height pada SVG, bukan dipatok di sini.
    Sebelumnya angka kanvas dibaca dari erd-data.js, dan itu membuat PNG
    terpotong setiap kali tata letak berubah tanpa ada yang gagal. SVG adalah
    sumber kebenarannya sendiri.

    Skala 2x HANYA menambah piksel. Ia tidak memperbaiki huruf yang terlalu
    kecil terhadap lebar gambar - itu diurus oleh ambang NISBAH_MIN di
    chen.js/papan.js, yang menolak build bila hurufnya terlalu kecil.
#>

$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path

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

$scale = 2
$svgFiles = Get-ChildItem -Path $here -Filter '*.svg' | Sort-Object Name

if ($svgFiles.Count -eq 0) {
    throw "Tidak ada berkas SVG di $here. Jalankan 'node docs/erd/build-tingkat.js' lebih dulu."
}

foreach ($svg in $svgFiles) {
    $isi = Get-Content -Path $svg.FullName -Raw -Encoding UTF8

    if ($isi -notmatch '<svg[^>]*width="(\d+)"[^>]*height="(\d+)"') {
        Write-Warning "Lewati $($svg.Name) - atribut width/height tidak ditemukan."
        continue
    }
    $w = [int]$Matches[1]
    $h = [int]$Matches[2]

    $pngPath = Join-Path $here ($svg.BaseName + '.png')
    $uri = 'file:///' + ($svg.FullName -replace '\\', '/')

    $galatSebelumnya = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    & $browser --headless=new --disable-gpu --hide-scrollbars `
        --default-background-color=FFFFFFFF `
        "--force-device-scale-factor=$scale" `
        "--window-size=$w,$h" `
        "--screenshot=$pngPath" $uri 2>&1 | Out-Null
    $ErrorActionPreference = $galatSebelumnya

    $waited = 0
    while (-not (Test-Path $pngPath) -and $waited -lt 12000) {
        Start-Sleep -Milliseconds 250
        $waited += 250
    }

    if (Test-Path $pngPath) {
        $kb = [math]::Round((Get-Item $pngPath).Length / 1KB, 1)
        Write-Host ("  {0,-30} {1}x{2}px  {3} KB" -f $pngPath.Split('\')[-1], ($w * $scale), ($h * $scale), $kb)
    } else {
        Write-Warning "Gagal membuat $($svg.BaseName).png"
    }
}

Write-Host ''
Write-Host 'Selesai. PNG siap ditempel ke dokumen Word atau slide.'
