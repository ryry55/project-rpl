#!/bin/sh
# Bukti bahwa pagar kos:buat-pemilik bisa GAGAL.
#
# Pagar yang tidak pernah terbukti gagal bukan pagar. Skrip ini menyuntikkan
# kembali empat cacat ke salinan BERKAS DI DALAM CONTAINER, menjalankan tes,
# lalu mengembalikannya. Tidak ada yang berubah di disk host.
set -e
BERKAS=/var/www/html/app/Console/Commands/BuatPemilik.php
CADANGAN=/tmp/BuatPemilik.php.asli

cp "$BERKAS" "$CADANGAN"
trap 'cp "$CADANGAN" "$BERKAS"' EXIT INT TERM

jalankan() {
    echo "=== CACAT: $1 ==="
    php artisan test --filter="$2" 2>&1 | grep -E "Tests:|FAILED|✓|⨯" | tail -6 || true
    echo ""
    cp "$CADANGAN" "$BERKAS"
}

# 1. Pagar "pemilik kedua" dicabut.
sed -i 's/if (\$sudahAda->isNotEmpty() && ! \$this->option(.paksa.)) {/if (false) {/' "$BERKAS"
grep -q 'if (false) {' "$BERKAS" && jalankan "pagar pemilik kedua dicabut" "test_pemilik_kedua_ditolak_tanpa_paksa"

# 2. Aturan unique email dicabut.
sed -i "s/'email' => \['required', 'email', 'max:255', 'unique:users,email'\]/'email' => ['required', 'email', 'max:255']/" "$BERKAS"
jalankan "unique email dicabut" "test_email_yang_sudah_dipakai_ditolak"

# 3. Batas panjang sandi dicabut.
sed -i "s/'sandi' => \['required', 'string', 'min:8'\]/'sandi' => ['required', 'string']/" "$BERKAS"
jalankan "batas min:8 dicabut" "test_sandi_kurang_dari_delapan_huruf_ditolak"

# 4. Penanda wajib ganti sandi selalu dimatikan.
sed -i 's/\$wajibGanti = ! \$sandiDiketik;/$wajibGanti = false;/' "$BERKAS"
jalankan "penanda wajib ganti sandi dimatikan" "test_sandi_lewat_argumen_menandai_wajib_ganti"

echo "Berkas dikembalikan. Verifikasi:"
php artisan test --filter=BuatPemilikTest 2>&1 | grep -E "Tests:|FAILED" | tail -2
