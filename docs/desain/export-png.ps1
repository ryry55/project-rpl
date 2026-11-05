<#
.SYNOPSIS
    Ekspor seluruh SVG mockup di docs/desain menjadi PNG 2x.

.DESCRIPTION
    Jalankan setelah "node docs/desain/build-mockup.js":
        powershell -ExecutionPolicy Bypass -File docs/desain/export-png.ps1

    Ukuran kanvas dibaca dari atribut width/height SVG, bukan dipatok di sini -
    pola yang sama dengan docs/erd/export-png.ps1, dan untuk alasan yang sama:
    angka yang ditulis dua kali akan berbeda pada suatu hari.

    Skrip .ps1 di repo ini disimpan tanpa BOM dan tidak boleh memuat simbol
    non-ASCII: Windows PowerShell 5.1 membacanya sebagai ANSI.
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
    throw "Tidak ada berkas SVG di $here. Jalankan 'node docs/desain/build-mockup.js' lebih dulu."
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
    while (-not (Test-Path $pngPath) -and $waited -lt 15000) {
        Start-Sleep -Milliseconds 250
        $waited += 250
    }

    if (Test-Path $pngPath) {
        $kb = [math]::Round((Get-Item $pngPath).Length / 1KB, 1)
        Write-Host ("  {0,-28} {1}x{2}px  {3} KB" -f $pngPath.Split('\')[-1], ($w * $scale), ($h * $scale), $kb)
    } else {
        Write-Warning "Gagal membuat $($svg.BaseName).png"
    }
}

Write-Host ''
Write-Host 'Selesai. PNG siap ditempel ke dokumen Word atau slide.'
