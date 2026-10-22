# Generate Project Charter Kos-Kosan dari template Smart Campus Asset.
# Memakai Word COM agar seluruh format template (font, margin, bullet, tabel) tetap utuh.
#
# Catatan penting: template menaruh bullet sebagai KARAKTER LITERAL "• " di awal
# paragraf (bukan Word list), jadi setiap penggantian teks harus mempertahankan
# prefiks itu. Fungsi Set-ParaText di bawah menanganinya.

$ErrorActionPreference = 'Stop'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$template = Join-Path (Split-Path (Split-Path $here -Parent) -Parent) "Template Laporan\Project_Charter_Smart_Campus_Asset .docx"
$target = Join-Path $here "Project_Charter_Kos-Kosan.docx"

if (-not (Test-Path $template)) { throw "template tidak ditemukan: $template" }
Copy-Item -LiteralPath $template -Destination $target -Force

$BULLET = [char]0x2022

$word = New-Object -ComObject Word.Application
$word.Visible = $false
$word.DisplayAlerts = 0
$doc = $word.Documents.Open($target)

# Ganti teks sebuah paragraf, pertahankan paragraph mark dan prefiks bullet.
function Set-ParaText {
    param($doc, [string]$needle, [string]$newText)
    for ($i = 1; $i -le $doc.Paragraphs.Count; $i++) {
        $p = $doc.Paragraphs.Item($i)
        $old = $p.Range.Text
        if ($old -like "*$needle*") {
            $hadBullet = $old.Length -gt 0 -and $old[0] -eq $BULLET
            $body = $newText
            if ($hadBullet) { $body = "$BULLET $newText" }
            $r = $p.Range
            $r.MoveEnd(1, -1) | Out-Null   # 1 = wdCharacter, buang paragraph mark
            $r.Text = $body
            return $i
        }
    }
    throw "paragraf tidak ditemukan: $needle"
}

# ---------- kop dokumen ----------
Set-ParaText $doc "Project Charter Pembuatan Sistem Informasi Reservasi" `
    "Project Charter Pembuatan Sistem Informasi Operasional Kos-Kosan Berbasis Web" | Out-Null
Set-ParaText $doc "Judul Proyek" `
    "Judul Proyek   : Sistem Informasi Operasional Kos-Kosan Berbasis Web" | Out-Null
Set-ParaText $doc "Nomor Proyek" `
    "Nomor Proyek   : 0024 / 0019-0061 / I / 2026" | Out-Null
Set-ParaText $doc "Tanggal" `
    "Tanggal        : 8 September 2026" | Out-Null

# ---------- 1. Tujuan ----------
Set-ParaText $doc "Tujuan proyek ini adalah untuk membangun" `
    ("Tujuan proyek ini adalah membangun Sistem Informasi Operasional Kos-Kosan berbasis Web yang membantu pemilik kos mengelola kamar, penghuni, kontrak sewa, tagihan bulanan, pembayaran, denda, dan tunggakan pada satu tempat. Sistem ini menggantikan pencatatan manual yang mudah hilang dan sulit direkap, sehingga penagihan menjadi konsisten, riwayat pembayaran tersimpan rapi, dan penghuni dapat memeriksa tagihannya sendiri. Sasaran yang disasar adalah kos dengan 5 sampai 30 kamar yang dikelola langsung oleh pemiliknya, dengan sewa sudah mencakup listrik dan air.") | Out-Null

# ---------- 2. Ruang lingkup ----------
Set-ParaText $doc "Analisis kebutuhan dan perancangan sistem informasi reservasi" `
    "Analisis kebutuhan dan perancangan sistem informasi operasional kos-kosan berbasis Web" | Out-Null
Set-ParaText $doc "Pengembangan aplikasi Web yang terdiri dari modul-modul" `
    ("Pengembangan aplikasi Web yang terdiri dari modul pengelolaan kamar dan penghuni, pengelolaan tarif dan komponen biaya, penerbitan tagihan bulanan, pencatatan pembayaran, serta pelaporan tunggakan") | Out-Null
Set-ParaText $doc "Pengembangan web service yang menghubungkan aplikasi Web dengan basis data sarana" `
    ("Pengembangan web service yang menghubungkan aplikasi Web dengan basis data operasional kos-kosan") | Out-Null
Set-ParaText $doc "Pengujian dan evaluasi sistem informasi reservasi" `
    "Pengujian dan evaluasi sistem informasi operasional kos-kosan berbasis Web" | Out-Null
Set-ParaText $doc "Pelaksanaan workshop serta penyusunan dokumentasi" `
    "Pelaksanaan workshop serta penyusunan dokumentasi sistem informasi operasional kos-kosan berbasis Web" | Out-Null

# ---------- 3. Anggota tim (tabel 1) ----------
$team = @(
    @('Project Manager',       'Yuni Siswantoro, M.Kom.',      'bertanggung jawab mengelola dan mengawasi seluruh proses proyek, termasuk perencanaan, pengendalian, komunikasi, dan pelaporan'),
    @('System Analyst',        'Muhammad Ryvan Gibran',        'bertanggung jawab menganalisis kebutuhan dan merancang sistem informasi operasional kos-kosan, termasuk DFD dan aturan bisnis'),
    @('Web Developer',         'Juniar Rifqi Nur Rachman',     'bertanggung jawab mengembangkan aplikasi Web sesuai dengan desain sistem'),
    @('Web Service Developer', 'Muhamad Akbar Rapli',          'bertanggung jawab mengembangkan web service yang menghubungkan aplikasi Web dengan basis data operasional kos-kosan'),
    @('Tester',                'Yukie Wirapratama Agusti',     'bertanggung jawab menguji dan mengevaluasi sistem informasi operasional kos-kosan, termasuk pengujian batas nilai pada aturan bisnis'),
    @('Trainer',               'Muhammad Ryvan Gibran',        'bertanggung jawab memberikan pelatihan dan bimbingan kepada pemilik kos tentang penggunaan sistem'),
    @('Documenter',            'Juniar Rifqi Nur Rachman & Muhamad Akbar Rapli', 'bertanggung jawab membuat dan menyediakan dokumentasi sistem informasi operasional kos-kosan')
)
$t = $doc.Tables.Item(1)
for ($r = 1; $r -le $team.Count; $r++) {
    for ($c = 1; $c -le 3; $c++) { $t.Cell($r, $c).Range.Text = $team[$r - 1][$c - 1] }
}

# ---------- 4. Jadwal (tabel 2) ----------
$sched = @(
    @('1', 'Analisis kebutuhan dan perancangan sistem',            '5 minggu', '8 Sep 2026',  '6 Okt 2026'),
    @('2', 'Pemodelan sistem (DFD konteks, level 1, dan level 2)', '1 minggu', '13 Okt 2026', '13 Okt 2026'),
    @('3', 'Perancangan arsitektur dan antarmuka aplikasi',        '3 minggu', '20 Okt 2026', '3 Nov 2026'),
    @('4', 'Pengembangan web service dan aplikasi Web',            '4 minggu', '10 Nov 2026', '1 Des 2026'),
    @('5', 'Pengujian dan evaluasi sistem',                        '2 minggu', '8 Des 2026',  '15 Des 2026'),
    @('6', 'Dokumentasi sistem dan pelatihan pengguna',            '1 minggu', '22 Des 2026', '22 Des 2026')
)
$t = $doc.Tables.Item(2)
while ($t.Rows.Count -lt ($sched.Count + 1)) { $t.Rows.Add() | Out-Null }
while ($t.Rows.Count -gt ($sched.Count + 1)) { $t.Rows.Item($t.Rows.Count).Delete() }
for ($r = 1; $r -le $sched.Count; $r++) {
    for ($c = 1; $c -le 5; $c++) { $t.Cell($r + 1, $c).Range.Text = $sched[$r - 1][$c - 1] }
}

# ---------- 5. Biaya (tabel 3) ----------
$cost = @(
    @('1', 'Analisis kebutuhan dan perancangan sistem',  'Rp 10.000.000'),
    @('2', 'Pemodelan sistem dan perancangan antarmuka', 'Rp 10.000.000'),
    @('3', 'Pengembangan web service dan aplikasi Web',  'Rp 20.000.000'),
    @('4', 'Pengujian dan evaluasi sistem',              'Rp 5.000.000'),
    @('5', 'Dokumentasi dan pelatihan pengguna',         'Rp 5.000.000')
)
$t = $doc.Tables.Item(3)
for ($r = 1; $r -le $cost.Count; $r++) {
    for ($c = 1; $c -le 3; $c++) { $t.Cell($r + 1, $c).Range.Text = $cost[$r - 1][$c - 1] }
}
$t.Cell($cost.Count + 2, 2).Range.Text = 'Total'
$t.Cell($cost.Count + 2, 3).Range.Text = 'Rp 50.000.000'

# ---------- 6. Risiko ----------
Set-ParaText $doc "Reschedule Proyek" `
    'Perubahan kebutuhan di tengah proyek (scope creep) yang menyebabkan penjadwalan ulang' | Out-Null
Set-ParaText $doc "Kegagalan dalam memenuhi" `
    'Kegagalan memenuhi spesifikasi dan kualitas sistem yang diharapkan pemilik kos' | Out-Null
Set-ParaText $doc "Kerusakan atau kehilangan data" `
    'Kerusakan atau kehilangan data tagihan dan pembayaran akibat kesalahan sistem atau perangkat lunak berbahaya' | Out-Null
Set-ParaText $doc "Ketidakpuasan atau keluhan" `
    'Ketidaksesuaian perhitungan prorata dan denda dengan kebiasaan yang berlaku di kos, sehingga angka tagihan diperselisihkan penghuni' | Out-Null
Set-ParaText $doc "Kurangnya dukungan dan komunikasi" `
    'Kurangnya dukungan dan komunikasi antara pihak pemberi kerja dan penerima kerja' | Out-Null

# ---------- 7. Manfaat ----------
Set-ParaText $doc "Meningkatkan akses dan kenyamanan pengguna" `
    'Memusatkan data kamar, penghuni, tagihan, dan pembayaran pada satu sistem sehingga tidak lagi tersebar di buku catatan' | Out-Null
Set-ParaText $doc "Meningkatkan efisiensi dan efektivitas dalam pengelolaan sarana" `
    'Membuat penagihan bulanan konsisten karena prorata, biaya tambahan, dan denda dihitung otomatis dengan aturan yang sama setiap bulan' | Out-Null
Set-ParaText $doc "Meningkatkan kualitas dan kuantitas inventaris" `
    'Memberi pemilik kos gambaran tunggakan terkini beserta umurnya, sehingga penagihan dapat diprioritaskan' | Out-Null
Set-ParaText $doc "Meningkatkan citra dan reputasi sarana prasarana" `
    'Membuka akses bagi penghuni untuk memeriksa tagihan dan riwayat pembayarannya sendiri tanpa harus bertanya kepada pemilik' | Out-Null

# ---------- 8. KPI ----------
$kpi = @(
    'Jumlah kamar dan penghuni yang terdaftar aktif dalam basis data',
    'Jumlah tagihan bulanan yang terbit tepat waktu setiap periode',
    'Persentase tagihan yang lunas sebelum jatuh tempo',
    'Ketepatan perhitungan prorata sewa bulan pertama terhadap rumus yang ditetapkan',
    'Ketepatan perhitungan denda 1% per hari dengan plafon 30% dari pokok, mencakup batas hari ke-7 dan ke-8, titik plafon pada hari ke-37, serta pengelompokan umur tunggakan pada batas hari ke-30 dan ke-31 dan hari ke-60 dan ke-61',
    'Jumlah pembayaran yang tercatat lengkap dengan bukti dan kuitansi',
    'Tingkat kesesuaian sistem dengan aturan bisnis R-01 sampai R-07 pada pengujian black-box'
)
Set-ParaText $doc "Jumlah barang yang terdaftar"       $kpi[0] | Out-Null
Set-ParaText $doc "Jumlah pengguna yang terdaftar"     $kpi[1] | Out-Null
Set-ParaText $doc "Jumlah peminjaman dan pengembalian" $kpi[2] | Out-Null
Set-ParaText $doc "Jumlah akses dan kunjungan"         $kpi[3] | Out-Null
Set-ParaText $doc "Tingkat kepuasan dan loyalitas"     $kpi[4] | Out-Null
Set-ParaText $doc "Tingkat ketersediaan dan keandalan" $kpi[5] | Out-Null
Set-ParaText $doc "Tingkat kesesuaian dan kualitas"    $kpi[6] | Out-Null

# ---------- sisipkan aturan bisnis R-01..R-07 ke bagian 2 ----------
$anchorIdx = 0
for ($i = 1; $i -le $doc.Paragraphs.Count; $i++) {
    if ($doc.Paragraphs.Item($i).Range.Text -like "*Pelaksanaan workshop serta penyusunan dokumentasi*") { $anchorIdx = $i; break }
}
if ($anchorIdx -eq 0) { throw "anchor aturan bisnis tidak ditemukan" }

$inserts = @(
    'Aturan bisnis yang wajib dipenuhi sistem adalah sebagai berikut:',
    'R-01  Sewa bulan pertama dihitung prorata, yaitu tarif dikali jumlah hari tinggal dibagi jumlah hari dalam bulan tersebut',
    'R-02  Satu kontrak hanya boleh memiliki satu tagihan per periode, dan penerbitan ulang untuk periode yang sama ditolak',
    'R-03  Denda keterlambatan mulai berlaku pada hari ke-8 setelah jatuh tempo, sebesar 1% dari pokok tagihan per hari, dan dibatasi maksimal 30% dari pokok sehingga denda berhenti bertambah pada hari terlambat ke-37',
    'R-04  Tunggakan dikelompokkan menurut umur, yaitu 0 sampai 30 hari, 31 sampai 60 hari, dan lebih dari 60 hari',
    'R-05  Deposit hanya dipotong bila ada kerusakan yang tercatat, dan sisanya wajib dikembalikan kepada penghuni',
    'R-06  Perubahan tarif tidak berlaku surut, hanya untuk periode berikutnya',
    'R-07  Penghuni hanya dapat melihat tagihannya sendiri, dan setiap perubahan status tagihan tercatat dalam log audit'
)

for ($k = 0; $k -lt $inserts.Count; $k++) {
    $anchor = $doc.Paragraphs.Item($anchorIdx + $k)
    $anchor.Range.InsertParagraphAfter()
    $newP = $doc.Paragraphs.Item($anchorIdx + $k + 1)
    $r = $newP.Range
    $r.MoveEnd(1, -1) | Out-Null
    $r.Text = $inserts[$k]
}

# ---------- ukuran halaman ----------
#
# Template memakai kertas US Letter (21,59 x 27,94 cm). Laporan kuliah di
# Indonesia memakai A4, jadi ukuran dan margin disetel ulang di sini, bukan
# dibiarkan mengikuti template. Tanpa langkah ini setiap regenerasi akan
# mengembalikan Letter.
#
# Margin standar laporan: kiri 4 cm, kanan 3 cm, atas 3 cm, bawah 3 cm.
$doc.PageSetup.PaperSize = 7          # 7 = wdPaperA4
$doc.PageSetup.TopMargin = 85.05      # poin; 1 cm = 28,35 poin
$doc.PageSetup.BottomMargin = 85.05
$doc.PageSetup.LeftMargin = 113.4
$doc.PageSetup.RightMargin = 85.05

$doc.Save()

$doc.Repaginate()
$end = $doc.Content.End
$pages = $doc.Range($end - 1, $end).Information(3)   # 3 = wdActiveEndPageNumber
$words = $doc.ComputeStatistics(0)

# Ekspor PDF dijalankan di sini karena ExportAsFixedFormat terbukti bekerja pada
# dokumen Word sungguhan; yang dulu menggantung adalah cara pemanggilannya.
$pdf = [System.IO.Path]::ChangeExtension($target, '.pdf')
$doc.ExportAsFixedFormat($pdf, 17)    # 17 = wdExportFormatPDF

$doc.Close($false)
$word.Quit()
[System.Runtime.InteropServices.Marshal]::ReleaseComObject($doc) | Out-Null
[System.Runtime.InteropServices.Marshal]::ReleaseComObject($word) | Out-Null
[GC]::Collect()
[GC]::WaitForPendingFinalizers()

Write-Host "charter selesai ditulis: $pages halaman, $words kata"
Write-Host "PDF: $pdf"
