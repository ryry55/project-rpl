<#
.SYNOPSIS
    Ekspor SVG akademik menjadi PNG resolusi cetak (2x) memakai Chrome/Edge headless.

.DESCRIPTION
    Jalankan setelah "node build.js" dan "node build-diagram.js":
        powershell -ExecutionPolicy Bypass -File docs/akademik/export-png.ps1

    Hasil: PNG skala 2x dengan latar putih untuk setiap SVG di daftar $berkas.
    Berkas ini sengaja ASCII murni agar terbaca oleh PowerShell 5.1.

    TIGA HAL YANG TIDAK BOLEH DIUBAH TANPA ALASAN

    1. Ukuran DIBACA dari atribut width/height pada akar SVG, bukan dipatok di
       sini. Angka yang dipatok pernah tertinggal ketika kanvas bertambah
       tinggi: SVG-nya benar, PNG-nya terpotong, dan tidak ada yang gagal.
       Daftarnya eksplisit, bukan pola *.svg - berkas 04-1nf..4nf.svg memang
       tidak punya PNG.

    2. SVG-nya DISISIPKAN ke halaman HTML berukuran 2x, tidak dibuka langsung
       sebagai berkas .svg. `--force-device-scale-factor` tidak dapat dipercaya
       di sini: dengan jendela 1250x1270 hasilnya 2500x2540 pada satu percobaan
       dan 5000x5080 pada percobaan lain, tergantung proses Chrome yang sudah
       berjalan. Pada 4x gambarnya hanya menempati seperempat kiri atas - dan
       tidak ada satu pun pesan galat. Ukuran halaman yang eksplisit membuat
       hasilnya tetap.

    3. SVG dibaca sebagai UTF8. `Get-Content -Raw` tanpa -Encoding memakai
       codepage ANSI di PowerShell 5.1, sehingga em dash, titik tengah, dan
       panah berubah menjadi "ae" dan "A." di dalam PNG.
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

# --- daftar gambar ---
# Eksplisit, bukan pola *.svg: berkas 04-1nf..4nf.svg memang tidak punya PNG.
$berkas = @(
    '01-use-case.svg',
    '02-dfd-konteks.svg',
    'kelas-a.svg',
    'kelas-b.svg',
    'kelas-c.svg'
)
foreach ($n in 1..14) { $berkas += ('urutan-UC-{0:D2}.svg' -f $n) }

$scale = 2

# Profil sendiri, dibuang di akhir. Tanpa ini Chrome bisa menempel ke proses
# yang sudah berjalan dan memakai ukuran jendela lama.
$profil = Join-Path $env:TEMP 'ekspor-svg-akademik'
$halaman = Join-Path $env:TEMP 'ekspor-svg-akademik.html'
if (Test-Path $profil) { Remove-Item $profil -Recurse -Force -ErrorAction SilentlyContinue }

$gagalUkuran = 0

foreach ($svg in $berkas) {
    $svgPath = Join-Path $here $svg
    $pngPath = Join-Path $here ($svg -replace '\.svg$', '.png')

    if (-not (Test-Path $svgPath)) {
        Write-Warning "Lewati $svg - berkas tidak ada. Jalankan 'node build.js' dan 'node build-diagram.js' lebih dulu."
        continue
    }

    $isi = Get-Content $svgPath -Raw -Encoding UTF8

    # Ukuran diambil dari akar SVG.
    $mw = [regex]::Match($isi, '<svg[^>]*?width="([0-9.]+)"')
    $mh = [regex]::Match($isi, '<svg[^>]*?height="([0-9.]+)"')
    if (-not $mw.Success -or -not $mh.Success) {
        Write-Warning "Lewati $svg - width/height tidak terbaca dari akar SVG."
        $gagalUkuran += 1
        continue
    }
    $W = [int][math]::Ceiling([double]$mw.Groups[1].Value) * $scale
    $H = [int][math]::Ceiling([double]$mh.Groups[1].Value) * $scale

    # Akar SVG diperbesar ke ukuran 2x, lalu disisipkan ke halaman kosong.
    $isi = [regex]::Replace($isi, '(<svg[^>]*?)width="[0-9.]+"', ('${1}width="' + $W + '"'))
    $isi = [regex]::Replace($isi, '(<svg[^>]*?)height="[0-9.]+"', ('${1}height="' + $H + '"'))
    $html = '<!doctype html><html><head><meta charset="utf-8">' +
        '<style>html,body{margin:0;padding:0;background:#fff}svg{display:block}</style>' +
        '</head><body>' + $isi + '</body></html>'
    Set-Content -Path $halaman -Value $html -Encoding UTF8

    $uri = 'file:///' + ($halaman -replace '\\', '/')
    if (Test-Path $pngPath) { Remove-Item $pngPath -Force }

    # Chrome menulis pesan kemajuan ke stderr; dengan $ErrorActionPreference = 'Stop'
    # PowerShell menganggapnya galat dan menghentikan skrip. Diredupkan sementara.
    $galatSebelumnya = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    & $browser --headless=new --disable-gpu --hide-scrollbars --default-background-color=FFFFFFFF "--window-size=$W,$H" "--user-data-dir=$profil" "--screenshot=$pngPath" $uri 2>&1 | Out-Null
    $ErrorActionPreference = $galatSebelumnya

    # Chrome headless kadang menulis berkas setelah proses utama selesai.
    $waited = 0
    while (-not (Test-Path $pngPath) -and $waited -lt 10000) {
        Start-Sleep -Milliseconds 250
        $waited += 250
    }

    if (Test-Path $pngPath) {
        # Ukuran berkas diperiksa, bukan diasumsikan. Jendela yang salah tetap
        # menghasilkan PNG yang terlihat "berhasil" tetapi gambarnya mengecil.
        Add-Type -AssemblyName System.Drawing
        $gambar = [System.Drawing.Image]::FromFile($pngPath)
        $aw = $gambar.Width; $ah = $gambar.Height
        $gambar.Dispose()
        if ($aw -ne $W -or $ah -ne $H) {
            Write-Warning "  $($svg -replace '\.svg$', '.png') berukuran $($aw)x$($ah), seharusnya $($W)x$($H)"
            $gagalUkuran += 1
        } else {
            $kb = [math]::Round((Get-Item $pngPath).Length / 1KB, 1)
            Write-Host ("  {0}  {1}x{2}px  {3} KB" -f ($svg -replace '\.svg$', '.png'), $aw, $ah, $kb)
        }
    } else {
        Write-Warning "Gagal membuat $pngPath"
        $gagalUkuran += 1
    }
}

if (Test-Path $profil) { Remove-Item $profil -Recurse -Force -ErrorAction SilentlyContinue }
if (Test-Path $halaman) { Remove-Item $halaman -Force -ErrorAction SilentlyContinue }

Write-Host ''
if ($gagalUkuran -gt 0) {
    Write-Host "$gagalUkuran gambar tidak sesuai. Jangan pakai PNG yang salah ukuran."
    exit 1
}
Write-Host 'Selesai. PNG siap ditempel ke dokumen Word atau slide.'
