# Menyegarkan salinan di folder dokumen/ dari sumbernya di docs/.
# Sumber kebenaran ada di docs/ (generator + pemeriksa gate); folder ini
# hanya salinan serahan. Jalankan setelah build ulang selesai.
#
# Tiap subfolder tujuan DIKOSONGKAN dulu: salinan lama yang namanya sudah
# tidak ada di sumber (mis. lembar yang dipecah) tidak boleh tertinggal
# basi di samping salinan barunya.

$ErrorActionPreference = 'Stop'
$akar = Split-Path -Parent $PSScriptRoot

function Salin($berkas, $tujuan) {
    $t = Join-Path $akar $tujuan
    if (-not (Test-Path $t)) { New-Item -ItemType Directory -Force -Path $t | Out-Null }
    Remove-Item -Path (Join-Path $t '*') -Force -ErrorAction SilentlyContinue
    foreach ($b in $berkas) { Copy-Item (Join-Path $akar $b) $t -Force }
}

Salin @('docs\produk\KONTEKS_PROYEK.md') 'dokumen\01-charter'
Salin @('docs\akademik\Proses_Bisnis_Kos_Kosan.pdf') 'dokumen\02-proses-bisnis'
Salin @('docs\akademik\01-use-case.png', 'docs\akademik\Kos_Kosan_Diagrams_Pertemuan_4.pdf') 'dokumen\03-use-case'
Salin @(
    'docs\dfd\01-dfd-konteks.png',
    'docs\dfd\01-dfd-konteks-yourdon.png',
    'docs\dfd\02-dfd-level-1.png',
    'docs\dfd\02-dfd-level-1-yourdon.png',
    'docs\dfd\03-dfd-level-2.md',
    'docs\dfd\README.md'
) 'dokumen\04-dfd'
Salin @(
    'docs\erd\00-L0-konseptual.png',
    'docs\erd\01-L1-logikal.png',
    'docs\erd\02-L2-fisikal-a.png',
    'docs\erd\02-L2-fisikal-b1.png',
    'docs\erd\02-L2-fisikal-b2.png',
    'docs\erd\02-L2-fisikal-c.png'
) 'dokumen\05-erd'
Salin @('docs\erd\03-L3-lrs.png', 'docs\erd\03-lrs.md') 'dokumen\06-lrs'
Salin @('docs\akademik\Bab_4_Implementasi_dan_Pengujian.pdf') 'dokumen\07-bab-4'
Salin @('docs\akademik\kelas-a.png', 'docs\akademik\kelas-b.png', 'docs\akademik\kelas-c.png') 'dokumen\08-diagram-kelas'
Salin (Get-ChildItem (Join-Path $akar 'docs\akademik') -Filter 'urutan-UC-*.png' | ForEach-Object { 'docs\akademik\' + $_.Name }) 'dokumen\09-diagram-urutan'
Salin @('docs\uji\audit-ketertelusuran.md') 'dokumen\10-peta-ketertelusuran'

Write-Host 'Salinan dokumen/ segar.'
