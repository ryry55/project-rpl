#!/bin/sh
# Bukti bahwa pagar "tabel bersih" bisa GAGAL.
#
# Dua ekspor - tunggakan dan pembayaran - sengaja TIDAK memakai blok ringkasan
# di atas judul kolom. Alasannya bukan kerapian: berkas itu dibuat untuk
# digabung ke pembukuan yang sudah dipakai pemilik, dan setiap alat CSV
# menganggap baris PERTAMA sebagai nama kolom. Blok ringkasan di atasnya membuat
# seluruh berkas terbaca sebagai satu tabel dengan kolom bergeser.
#
# Cacat di sini halus: berkasnya tetap terunduh, tetap terbuka di Excel, dan
# tetap terlihat rapi. Yang berubah hanya arti setiap kolomnya - dan itu baru
# ketahuan saat angka pemilik sudah salah.
set -e
CTRL=/var/www/html/app/Http/Controllers/Api/EksporController.php
CADANGAN=/tmp/EksporController.php.asli

cp "$CTRL" "$CADANGAN"
trap 'cp "$CADANGAN" "$CTRL"' EXIT INT TERM

echo "=== CACAT: blok ringkasan dikembalikan ke tunggakan ==="

php -r '
$f = "/var/www/html/app/Http/Controllers/Api/EksporController.php";
$s = file_get_contents($f);

$cari = "        \$baris[] = [\n            \x27Kelompok\x27,";
$ganti = "        \$baris[] = [\x27Daftar tunggakan\x27];\n"
       . "        \$baris[] = [\x27Tanggal acuan\x27, \$data[\x27tanggal_acuan\x27]];\n"
       . "        \$baris[] = [\n            \x27Kelompok\x27,";

$s = str_replace($cari, $ganti, $s, $jumlah);
file_put_contents($f, $s);
echo "suntikan terpasang: $jumlah\n";
'

if grep -q "Daftar tunggakan" "$CTRL"; then
    php artisan test --filter="test_ekspor_tunggakan_memuat_kelompok_dan_nama_penghuni" 2>&1 | tail -6
    echo ""
    php artisan test --filter="test_ekspor_pembayaran_dan_tunggakan_tidak_memakai_blok_ringkasan" 2>&1 | tail -6
else
    echo "!! SUNTIKAN GAGAL TERPASANG - tes tidak membuktikan apa pun"
fi

cp "$CADANGAN" "$CTRL"
echo ""
echo "Berkas dikembalikan. Verifikasi:"
php artisan test --filter=EksporTest 2>&1 | grep -E "Tests:|FAILED" | tail -2
