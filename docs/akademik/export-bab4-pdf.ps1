<#
.SYNOPSIS
    Mengekspor Bab 4 (Implementasi dan Pengujian) ke PDF siap cetak.

.DESCRIPTION
    Markdown -> HTML (pandoc + CSS cetak) -> PDF (Chrome headless).

    Gaya cetak mengikuti laporan referensi mata kuliah Sistem Basis Data:
    Times New Roman 11pt, A4, margin 2,5 cm.

    DUA HAL YANG TIDAK BOLEH DIUBAH TANPA ALASAN:

    1. SVG normalisasi DITANAM langsung ke HTML, bukan dirujuk lewat <img src>.
       HTML perantara ditulis di folder sementara, sedangkan SVG ada di
       docs/akademik; rujukan relatif `04-1nf.svg` akan dicari di folder
       sementara dan gagal tanpa pesan galat - PDF tetap terbentuk, tetapi
       gambarnya hilang. Kejadian ini sudah pernah menimpa dokumen ini.

    2. `--metadata title` sengaja TIDAK dipakai. Pandoc menambahkan judul itu
       sebagai <h1> di puncak dokumen, padahal dokumen ini sudah punya
       "BAB IV / IMPLEMENTASI DAN PENGUJIAN" sendiri - hasilnya judul dobel.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File docs/akademik/export-bab4-pdf.ps1
#>

param(
  [string]$Sumber = 'docs/akademik/Bab_4_Implementasi_dan_Pengujian.md',
  [string]$Keluaran = 'docs/akademik/Bab_4_Implementasi_dan_Pengujian.pdf'
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

$akar = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$dirSumber = Split-Path -Parent $Sumber

$kerja = Join-Path $env:TEMP 'kos-bab4-pdf'
New-Item -ItemType Directory -Force -Path $kerja | Out-Null
$html = Join-Path $kerja 'bab4.html'

$css = @'
<style>
  @page { size: A4; margin: 2.5cm 2.2cm; }
  body { font-family: "Times New Roman", serif; font-size: 11pt; line-height: 1.25; margin: 0; }
  h1 { font-size: 14pt; margin: 0 0 4pt 0; text-align: center; }
  h2 { font-size: 13pt; margin: 0 0 10pt 0; text-align: center; }
  h3 { font-size: 12pt; margin: 14pt 0 6pt 0; }
  h4 { font-size: 11.5pt; margin: 12pt 0 5pt 0; }
  p { margin: 0 0 6pt 0; text-align: justify; }
  table { border-collapse: collapse; width: 100%; font-size: 9pt; margin: 4pt 0 8pt 0; }
  td, th { border: 1px solid #666; padding: 2pt 3pt; vertical-align: top; }
  th { background: #eef2f8; }
  figure { margin: 6pt 0 10pt 0; text-align: center; page-break-inside: avoid; }
  img { display: block; max-width: 100%; max-height: 20cm; margin: 0 auto; }
  svg { display: block; max-width: 100%; height: auto; margin: 0 auto; }
  figcaption { font-style: italic; font-size: 9.5pt; margin: 4pt 0 0 0; text-align: center; }
  blockquote { margin: 0 0 8pt 0; padding-left: 8px; border-left: 2px solid #bbb; color: #333; font-size: 9pt; }
  ul, ol { margin: 2pt 0 6pt 0; padding-left: 18pt; }
  li { margin: 0 0 3pt 0; text-align: justify; }
</style>
'@

pandoc $Sumber -f gfm -t html5 -s --resource-path="$dirSumber;$akar" -o $html
$isi = Get-Content $html -Raw -Encoding UTF8
$isi = $isi -replace '</head>', "$css</head>"

# Tanam SVG: ganti setiap <img ... src="04-Nnf.svg"> dengan isi SVG-nya.
# Path-nya diambil dari atribut src, lalu dicari di folder sumber .md.
$isi = [regex]::Replace($isi, '<img\s+src="(04-\dnf\.svg)"[^>]*/?>', {
    param($m)
    $jalur = Join-Path $dirSumber $m.Groups[1].Value
    if (-not (Test-Path $jalur)) { throw "SVG tidak ditemukan: $jalur" }
    $svg = Get-Content $jalur -Raw -Encoding UTF8
    $svg = $svg -replace '^\s*<\?xml[^>]*\?>\s*', ''
    return $svg
})

Set-Content -Path $html -Value $isi -Encoding UTF8

# Periksa: kalau masih ada rujukan gambar relatif, PDF-nya akan bolong.
$sisa = [regex]::Matches($isi, '<img\s+src="(?<s>[^"]+)"')
foreach ($m in $sisa) {
    $s = $m.Groups['s'].Value
    if ($s -notmatch '^(https?:|data:)') {
        $calonJalur = if ([System.IO.Path]::IsPathRooted($s)) { $s } else { Join-Path $dirSumber $s }
        if (-not (Test-Path $calonJalur)) { throw "Gambar dirujuk tetapi tidak ditemukan: $s" }
        # Gambar relatif di HTML sementara TIDAK akan termuat; tanam sebagai data URI.
        $bytes = [System.IO.File]::ReadAllBytes((Resolve-Path $calonJalur))
        $b64 = [System.Convert]::ToBase64String($bytes)
        $mime = if ($s -match '\.png$') { 'image/png' } else { 'image/jpeg' }
        $isi = $isi.Replace($m.Value, $m.Value.Replace($s, "data:$mime;base64,$b64"))
    }
}
Set-Content -Path $html -Value $isi -Encoding UTF8

$pdf = if ([System.IO.Path]::IsPathRooted($Keluaran)) { $Keluaran } else { Join-Path (Get-Location).Path $Keluaran }

$galatLama = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
& $browser --headless=new --disable-gpu --no-sandbox --user-data-dir="$env:TEMP\chrome-bab4" --no-pdf-header-footer --print-to-pdf="$pdf" "file:///$($html -replace '\\','/')" 2>&1 | Out-Null
$ErrorActionPreference = $galatLama

if (-not (Test-Path $pdf)) { throw 'Gagal membuat PDF' }

$bytes = [System.IO.File]::ReadAllBytes($pdf)
$teks = [System.Text.Encoding]::GetEncoding(28591).GetString($bytes)
$jumlah = ([regex]::Matches($teks, '/Type\s*/Page[^s]')).Count

"$Sumber -> $Keluaran ($jumlah halaman)"
