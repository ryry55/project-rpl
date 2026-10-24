<#
.SYNOPSIS
    Mengukur jumlah halaman sebenarnya dari dokumen akademik.

.DESCRIPTION
    Markdown -> HTML (pandoc) -> PDF (Chrome headless) -> hitung /Type /Page.
    Dipakai untuk memverifikasi klaim jumlah halaman; bukan bagian dari build.

    Gaya cetak meniru template dosen (Times New Roman 11pt, spasi tunggal,
    margin 2,5 cm) supaya jumlah halaman sebanding dengan PDF template.

    Butuh pandoc dan Chrome/Edge. Kalau tidak ada, lewati saja: angka halaman
    hanya dipakai saat memutuskan apakah isi masih pantas disebut "3 halaman".

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File docs/akademik/ukur-halaman.ps1
    powershell -ExecutionPolicy Bypass -File docs/akademik/ukur-halaman.ps1 `
        -Sumber 'docs/akademik/use-case-diagram.md' -Keluaran 'docs/akademik/_ukur-uc'
#>

param(
  [string]$Sumber = 'docs/akademik/Proses_Bisnis_Kos_Kosan.md',
  [string]$Keluaran = 'docs/akademik/_ukur'
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
if (-not (Get-Command pandoc -ErrorAction SilentlyContinue)) { throw 'pandoc tidak ditemukan.' }

New-Item -ItemType Directory -Force -Path $Keluaran | Out-Null

$html = Join-Path (Resolve-Path $Keluaran).Path 'ukur.html'
$pdf = Join-Path (Resolve-Path $Keluaran).Path 'ukur.pdf'

$css = @'
<style>
  @page { size: A4; margin: 2.5cm 2.2cm; }
  body { font-family: "Times New Roman", serif; font-size: 11pt; line-height: 1.15; margin: 0; }
  h1 { font-size: 13pt; margin: 0 0 8pt 0; }
  h2 { font-size: 11.5pt; margin: 10pt 0 4pt 0; }
  h3 { font-size: 11pt; margin: 8pt 0 3pt 0; }
  p { margin: 0 0 5pt 0; }
  table { border-collapse: collapse; width: 100%; font-size: 9pt; margin: 3pt 0 6pt 0; }
  td, th { border: 1px solid #666; padding: 1.5pt 3pt; vertical-align: top; }
  blockquote { margin: 0 0 6pt 0; padding-left: 8px; border-left: 2px solid #bbb; color: #333; font-size: 9pt; }
  ul, ol { margin: 2pt 0 5pt 0; padding-left: 18pt; }
  li { margin: 0 0 2pt 0; }
</style>
'@

pandoc $Sumber -f gfm -t html5 -s --metadata title='Ukur' -o $html
$isi = Get-Content $html -Raw -Encoding UTF8
$isi = $isi -replace '</head>', "$css</head>"
Set-Content -Path $html -Value $isi -Encoding UTF8

# Chrome menulis pesan kemajuan ke stderr; dengan 'Stop' PowerShell menganggapnya
# galat dan menghentikan skrip. Diredupkan sementara.
$galatLama = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
& $browser --headless=new --disable-gpu --no-sandbox --user-data-dir="$env:TEMP\chrome-ukur" --print-to-pdf="$pdf" "file:///$((Resolve-Path $html).Path -replace '\\','/')" 2>&1 | Out-Null
$ErrorActionPreference = $galatLama
if (-not (Test-Path $pdf)) { throw 'Gagal membuat PDF' }

# Setiap halaman PDF memuat "/Type /Page" (bukan /Pages).
# PS 5.1 tidak punya Encoding::Latin1, jadi pakai code page 28591.
$bytes = [System.IO.File]::ReadAllBytes($pdf)
$teks = [System.Text.Encoding]::GetEncoding(28591).GetString($bytes)
$jumlah = ([regex]::Matches($teks, '/Type\s*/Page[^s]')).Count

"$Sumber -> $jumlah halaman"
