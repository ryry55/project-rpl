<#
.SYNOPSIS
    Mengekspor dokumen diagram menjadi PDF 2 halaman sesuai template dosen.

.DESCRIPTION
    Template `Smart_Campus_Asset_Diagrams_Pertemuan_4.pdf` berisi TEPAT dua
    halaman, satu diagram per halaman: halaman 1 Use Case Diagram, halaman 2
    Diagram Konteks DFD Level 0. Tidak ada tabel penjelas di dalamnya - tabel
    matriks tinggal di laporan narasi.

    Karena itu skrip ini TIDAK mengekspor `.md` kerja (`use-case-diagram.md`
    berisi enam bab, bukan satu halaman gambar). Ia menyusun HTML sendiri dari
    dua berkas SVG, satu per halaman, lalu mencetaknya.

    SVG dipakai, bukan PNG: vektor tetap tajam saat dicetak dan saat dosen
    memperbesar. PNG 2x disediakan terpisah untuk ditempel ke Word.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File docs/akademik/export-diagram-pdf.ps1
#>

param(
  [string]$Keluaran = 'docs/akademik/Kos_Kosan_Diagrams_Pertemuan_4.pdf'
)

$ErrorActionPreference = 'Stop'

$calon = @(
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
    "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe",
    "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
)
$browser = $calon | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $browser) { throw 'Chrome atau Edge tidak ditemukan.' }

# $PSScriptRoot = docs/akademik, jadi akar repo dua tingkat di atasnya.
$akar = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$svg1 = Join-Path $akar 'docs/akademik/01-use-case.svg'
$svg2 = Join-Path $akar 'docs/akademik/02-dfd-konteks.svg'
foreach ($s in @($svg1, $svg2)) {
    if (-not (Test-Path $s)) { throw "Diagram tidak ditemukan: $s. Jalankan node docs/akademik/build.js dulu." }
}

# Judul tiap halaman diambil dari template dosen supaya bentuknya sebanding.
$judul1 = 'Use Case Diagram - Sistem Informasi Operasional Kos-Kosan (3 Aktor)'
$judul2 = 'Diagram Konteks DFD (Level 0) - Sistem Informasi Operasional Kos-Kosan (3 Entitas)'

function Baca-Svg([string]$jalur) {
    $isi = Get-Content $jalur -Raw -Encoding UTF8
    # Buang deklarasi XML: ia tidak boleh muncul di tengah dokumen HTML.
    $isi = $isi -replace '^\s*<\?xml[^>]*\?>\s*', ''
    # Buang komentar pembangkit agar PDF tidak memuat catatan internal.
    $isi = $isi -replace '<!--.*?-->', ''
    return $isi
}

$s1 = Baca-Svg $svg1
$s2 = Baca-Svg $svg2

$html = @"
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><title>Diagram Kos-Kosan</title>
<style>
  @page { size: A4 landscape; margin: 1.2cm; }
  body { font-family: "Times New Roman", serif; margin: 0; }
  .halaman { page-break-after: always; text-align: center; }
  .halaman:last-child { page-break-after: auto; }
  h1 { font-size: 12pt; margin: 0 0 6pt 0; font-weight: bold; }
  svg { max-width: 100%; max-height: 17cm; height: auto; }
</style></head><body>
<div class="halaman"><h1>$judul1</h1>$s1</div>
<div class="halaman"><h1>$judul2</h1>$s2</div>
</body></html>
"@

$kerja = Join-Path $env:TEMP 'kos-diagram-pdf'
New-Item -ItemType Directory -Force -Path $kerja | Out-Null
$berkasHtml = Join-Path $kerja 'diagram.html'
Set-Content -Path $berkasHtml -Value $html -Encoding UTF8

$pdf = if ([System.IO.Path]::IsPathRooted($Keluaran)) { $Keluaran } else { Join-Path (Get-Location).Path $Keluaran }

$galatLama = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
& $browser --headless=new --disable-gpu --no-sandbox --user-data-dir="$env:TEMP\chrome-diagram" --no-pdf-header-footer --print-to-pdf="$pdf" "file:///$($berkasHtml -replace '\\','/')" 2>&1 | Out-Null
$ErrorActionPreference = $galatLama

if (-not (Test-Path $pdf)) { throw 'Gagal membuat PDF' }

$bytes = [System.IO.File]::ReadAllBytes($pdf)
$teks = [System.Text.Encoding]::GetEncoding(28591).GetString($bytes)
$jumlah = ([regex]::Matches($teks, '/Type\s*/Page[^s]')).Count

"$Keluaran ($jumlah halaman)"
