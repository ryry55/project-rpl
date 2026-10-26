#!/bin/sh
# Bukti bahwa pagar StatusPenyiapan bisa GAGAL.
#
# Menyuntikkan kembali cacat ke salinan berkas DI DALAM CONTAINER, menjalankan
# tes yang seharusnya menangkapnya, lalu mengembalikannya.
set -e
BERKAS=/var/www/html/app/Domain/Penyiapan/StatusPenyiapan.php
CADANGAN=/tmp/StatusPenyiapan.php.asli

cp "$BERKAS" "$CADANGAN"
trap 'cp "$CADANGAN" "$BERKAS"' EXIT INT TERM

jalankan() {
    echo "=== CACAT: $1 ==="
    php artisan test --filter="$2" 2>&1 | grep -E "Tests:|⨯|✓" | tail -6 || true
    echo ""
    cp "$CADANGAN" "$BERKAS"
}

# 1. Rekening nonaktif ikut dihitung.
sed -i "s/->where('aktif', true)->count();/->count();/" "$BERKAS"
grep -q "Rekening::query()->count()" "$BERKAS" \
    && jalankan "rekening nonaktif ikut dihitung" "test_rekening_nonaktif_tidak_dihitung"

# 2. Kebijakan diselesaikan terhadap bulan depan, bukan bulan ini.
#    Cacat ini meniru kesalahan yang paling mudah terjadi: memakai tanggal yang
#    salah saat menyelesaikan kebijakan. Akibatnya kebijakan yang baru berlaku
#    bulan depan dilaporkan sudah menutup gerbang bulan ini.
sed -i "s/\$kurang = \$this->penyelesai->tipeYangKurang(\$hariIni);/\$kurang = \$this->penyelesai->tipeYangKurang(\$hariIni->addMonth());/" "$BERKAS"
jalankan "kebijakan diselesaikan terhadap bulan depan" "test_kebijakan_bertanggal_bulan_depan_belum_dihitung"

# 3. Kamar tanpa tarif dianggap selesai (langkah tarif tidak memeriksa tarif).
sed -i "s/\$kamar->isNotEmpty() && \$kamarTanpaTarif->isEmpty()/\$kamar->isNotEmpty()/" "$BERKAS"
jalankan "langkah tarif tidak memeriksa tarif" "test_kamar_tanpa_tarif_menahan_langkah_tarif"

# 4. Penghuni dijadikan wajib.
#
# Menyuntikkan dengan MENGGANTI `'wajib' => false` yang sudah ada, bukan
# menyisipkan kunci baru di dekatnya. Percobaan pertama menyisipkan
# `'wajib' => true` SEBELUM kunci lama, dan di array PHP kunci terakhir yang
# menang - sehingga cacatnya tidak pernah benar-benar terpasang dan tesnya
# lulus tanpa membuktikan apa pun. Kegagalan yang diam, dan justru itulah yang
# paling perlu dicatat.
sed -i "s/'wajib' => false,/'wajib' => true,/" "$BERKAS"
jalankan "penghuni dijadikan wajib" "test_penghuni_bukan_langkah_wajib"

echo "Berkas dikembalikan. Verifikasi:"
php artisan test --filter=PenyiapanTest 2>&1 | grep -E "Tests:|FAILED" | tail -2
