#!/bin/sh
# Bukti bahwa pagar ekspor CSV bisa GAGAL.
#
# Lima cacat disuntikkan kembali ke salinan berkas DI DALAM CONTAINER, satu per
# satu, lalu tes yang seharusnya menangkapnya dijalankan. Berkas dikembalikan
# setiap kali.
#
# Dua cacat di sini menghasilkan berkas yang TERUNDUH DENGAN BAIK dan tampak
# wajar saat dibuka sekilas - pemisah yang salah dan angka negatif yang
# berubah menjadi teks. Hanya tes yang mengurai isi berkasnya yang bisa
# membedakan keduanya dari berkas yang benar.
set -e
CSV=/var/www/html/app/Domain/Laporan/EksporCsv.php
RUTE=/var/www/html/routes/api.php
CADANGAN_CSV=/tmp/EksporCsv.php.asli
CADANGAN_RUTE=/tmp/api.php.asli

cp "$CSV" "$CADANGAN_CSV"
cp "$RUTE" "$CADANGAN_RUTE"

pulihkan() {
    cp "$CADANGAN_CSV" "$CSV"
    cp "$CADANGAN_RUTE" "$RUTE"
}
trap pulihkan EXIT INT TERM

jalankan() {
    echo "=== CACAT: $1 ==="
    php artisan test --filter="$2" 2>&1 | grep -E "Tests:|⨯|✓" | tail -5 || true
    echo ""
    pulihkan
}

gagal_pasang() {
    echo "!! SUNTIKAN GAGAL TERPASANG: $1"
    echo "   Tes akan lulus tanpa membuktikan apa pun. Perbaiki suntikannya lebih dulu."
    echo ""
    pulihkan
}

# 1. Pemisah dikembalikan ke koma.
#    Excel wilayah Indonesia memakai koma sebagai pemisah DESIMAL, sehingga
#    berkas berpemisah koma terbaca sebagai satu kolom raksasa. Berkasnya tetap
#    terunduh dan tetap "CSV yang sah" - hanya tidak bisa dipakai.
sed -i "s/';', '\"', '\\\\\\\\'/','', '\"', '\\\\\\\\'/" "$CSV"
if grep -q "fputcsv(\$keluaran, \$sel, ','" "$CSV"; then
    jalankan "pemisah kembali ke koma" "test_ekspor_rekap_mengembalikan_csv_dengan_bom_dan_pemisah_titik_koma"
else
    gagal_pasang "pemisah koma"
fi

# 2. BOM UTF-8 dihapus.
#    Tanpa BOM, Excel di Windows membaca berkas sebagai ANSI dan nama penghuni
#    yang beraksen menjadi rusak.
sed -i 's|fwrite($keluaran, "\\xEF\\xBB\\xBF");||' "$CSV"
if ! grep -q 'xEF' "$CSV"; then
    jalankan "BOM UTF-8 dihapus" "test_ekspor_rekap_mengembalikan_csv_dengan_bom_dan_pemisah_titik_koma"
else
    gagal_pasang "BOM dihapus"
fi

# 3. Netralisasi rumus dicabut.
#    Nama yang dimulai "=" akan dieksekusi Excel sebagai rumus saat berkas
#    dibuka. Ini CSV injection, dan namanya berasal dari formulir.
sed -i 's|foreach (self::AWALAN_BERBAHAYA as $awalan) {|foreach ([] as $awalan) {|' "$CSV"
if grep -q 'foreach (\[\] as \$awalan)' "$CSV"; then
    jalankan "netralisasi rumus dicabut" "test_teks_berawalan_sama_dengan_tanda_sama_dinetralkan"
else
    gagal_pasang "netralisasi rumus"
fi

# 4. Pemeriksaan "ini angka" dicabut, sehingga angka negatif ikut diberi kutip.
#    '-' adalah salah satu awalan berbahaya, dan memberi kutip padanya
#    mengubah angka menjadi TEKS - SUM di Excel menghasilkan 0, dan total di
#    berkas tidak lagi cocok dengan total di layar.
#
#    Disuntikkan lewat PHP, bukan `sed`: polanya berisi backslash ganda yang
#    harus melewati escaping shell DAN escaping regex `sed` sekaligus, dan
#    percobaan pertama menghasilkan "bad regex" - suntikan tidak terpasang,
#    lalu `set -e` menghentikan skrip sebelum cacat 5 dan 6 diuji. Kegagalan
#    yang diam, dan justru itu yang paling perlu dihindari di skrip bukti.
php -r '
$f = "/var/www/html/app/Domain/Laporan/EksporCsv.php";
$s = file_get_contents($f);
$s = preg_replace("~if \\(preg_match\\([^\\n]*?\\) === 1\\) \\{~", "if (false) {", $s, 1);
file_put_contents($f, $s);
'
if grep -q 'if (false) {' "$CSV"; then
    # Tes yang dijalankan adalah tes PENULIS, bukan tes HTTP. Tes HTTP mengirim
    # `(int)` dari controller, jadi cabang "string yang isinya angka" tidak
    # pernah dilewati di sana - dan cacat ini lulus pada tes HTTP. Itu bukan
    # alasan untuk menghapus cabangnya (Eloquent mengembalikan `decimal:0`
    # sebagai string untuk ekspor lain), melainkan alasan tes penulis ada.
    jalankan "angka negatif ikut dikutip" "test_penulis_csv_membedakan_angka_negatif_dari_teks_berbahaya"
else
    gagal_pasang "pemeriksaan angka"
fi

# 5. Rekap dibuka untuk pengelola.
#    Berkas rekap adalah gambaran utuh pendapatan kos dan bisa dibawa pulang.
#    Kebocoran ini tidak terlihat di layar mana pun - hanya dari daftar rute.
#    Disuntikkan dengan mendaftarkan ulang rute yang sama TANPA middleware
#    peran, tepat sebelum rute tunggakan di grup pemilik+pengelola.
php -r '
$f = "/var/www/html/routes/api.php";
$s = file_get_contents($f);
$s = str_replace(
    "Route::get(\x27/ekspor/tunggakan\x27",
    "Route::get(\x27/ekspor/rekap\x27, [EksporController::class, \x27rekap\x27])->name(\x27ekspor.rekap.bocor\x27);\n            Route::get(\x27/ekspor/tunggakan\x27",
    $s
);
file_put_contents($f, $s);
'
if grep -q 'ekspor.rekap.bocor' "$RUTE"; then
    jalankan "rekap dibuka untuk pengelola" "test_pengelola_tidak_boleh_mengekspor_rekap"
else
    gagal_pasang "rute rekap bocor"
fi

# 6. Cache-Control no-store dihapus.
#    Berkas keuangan dan nama penghuni akan tertinggal di cache komputer
#    bersama yang dipakai bergantian.
sed -i "s|'Cache-Control' => 'no-store, max-age=0',||" "$CSV"
if ! grep -q 'no-store' "$CSV"; then
    jalankan "Cache-Control no-store dihapus" "test_ekspor_dilarang_di_cache"
else
    gagal_pasang "Cache-Control"
fi

echo "Berkas dikembalikan. Verifikasi:"
php artisan test --filter=EksporTest 2>&1 | grep -E "Tests:|FAILED" | tail -2
