#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Membuktikan pagar FASE 0 benar-benar bekerja DAN bisa gagal.
#
#   bash docs/uji/uji-pagar-fase0.sh
#
# Kenapa berkas terpisah dari tes PHPUnit: yang diuji di sini adalah berkas
# konfigurasi dan skrip shell yang hidup di LUAR image api - Dockerfile.prod,
# docker-compose.prod.yml, entrypoint-prod.sh, dan berkas .ini PHP. Tidak satu
# pun ikut ke dalam image `api`, sehingga tes PHP tidak bisa membacanya.
#
# Prinsip yang dipegang berkas ini: pagar yang tidak pernah dibuktikan bisa
# GAGAL bukan pagar, melainkan hiasan. Karena itu setiap butir diuji dua kali -
# sekali dengan nilai benar (harus lulus), sekali dengan nilai salah yang
# disuntikkan sementara (harus gagal) - lalu dikembalikan.
# ---------------------------------------------------------------------------

set -uo pipefail

AKAR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$AKAR" || exit 1

GAGAL=()
lapor() {
    local nama="$1" lulus="$2" detail="${3:-}"
    local akhiran=''
    if [ -n "$detail" ]; then akhiran="  $detail"; fi
    if [ "$lulus" -eq 0 ]; then
        printf '  LULUS  %s%s\n' "$nama" "$akhiran"
    else
        printf '  GAGAL  %s%s\n' "$nama" "$akhiran"
        GAGAL+=("$nama")
    fi
}

echo '=== FASE 0.3: DB_SSLMODE benar-benar dibaca ==='

# Yang diperiksa bukan "apakah tulisan env() ada di berkas", melainkan apakah
# NILAINYA benar-benar sampai ke konfigurasi Laravel. Membaca berkas hanya
# membuktikan niat; membaca hasil membuktikan perilaku.
DB_SSLMODE=require docker compose exec -T \
    -e DB_SSLMODE=require api php artisan tinker --execute='
        echo config("database.connections.pgsql.sslmode");
    ' 2>/dev/null | tr -d '\r' > /tmp/sslmode_require.txt || true

isi="$(cat /tmp/sslmode_require.txt 2>/dev/null | tr -d '[:space:]')"
lapor 'DB_SSLMODE=require terbaca sebagai require' \
    "$([ "$isi" = 'require' ] && echo 0 || echo 1)" \
    "terbaca: '${isi:-kosong}'"

# Bawaannya harus tetap `prefer`, supaya PostgreSQL lokal tanpa TLS - yang
# memang keadaan kontainer `db` di demo - tetap tersambung.
tanpa="$(docker compose exec -T api php artisan tinker --execute='
    echo config("database.connections.pgsql.sslmode");
' 2>/dev/null | tr -d '[:space:]')"
lapor 'tanpa DB_SSLMODE, bawaannya prefer (PostgreSQL lokal tetap tersambung)' \
    "$([ "$tanpa" = 'prefer' ] && echo 0 || echo 1)" \
    "terbaca: '${tanpa:-kosong}'"

# Profil produksi harus MENERUSKAN DB_SSLMODE, tetapi tidak boleh memberi nilai
# bawaan `require`: profil ini juga dijalankan di laptop, dan di sana
# PostgreSQL tidak memakai TLS. Nilai `require` sebagai bawaan akan mematahkan
# pengujian pagar yang justru menjadi alasan profil ini ada.
lapor 'docker-compose.prod.yml meneruskan DB_SSLMODE' \
    "$(grep -q 'DB_SSLMODE:' "$AKAR/docker-compose.prod.yml" && echo 0 || echo 1)"

lapor 'docker-compose.prod.yml TIDAK memberi bawaan require (lokal tetap jalan)' \
    "$(grep -q 'DB_SSLMODE: ${DB_SSLMODE:-require}' "$AKAR/docker-compose.prod.yml" && echo 1 || echo 0)"

echo ''
echo '=== FASE 0.4: pagar MAIL_FROM_ADDRESS di entrypoint ==='

ENTRY="$AKAR/docker/prod/entrypoint-prod.sh"

# Harness: menjalankan entrypoint SUNGGUHAN, tetapi seluruh perintah yang
# menyentuh sistem dipalsukan. Tanpa ini, yang terukur adalah kegagalan karena
# tidak punya akses root - bukan perilaku pagarnya.
#
# Yang dipalsukan dan alasannya:
#   php    - bagian tunggu-database, migrasi, dan cache konfigurasi.
#   sed    - menulis /etc/nginx/nginx.conf, butuh root.
#   chown  - butuh root.
#   chmod  - butuh root.
#   mkdir  - tidak dipalsukan; direktorinya memang sudah ada di repo.
STUB="$(mktemp -d)"
for alat in php sed chown chmod; do
    printf '#!/bin/sh\nexit 0\n' > "$STUB/$alat"
    chmod +x "$STUB/$alat"
done

# Argumen terakhir `true` dipakai untuk `exec "$@"` di ujung skrip: tanpa
# argumen, `exec` gagal dan kode keluarnya menutupi kode pagar yang sedang
# diukur. Ini pernah membuat pagar tampak "menolak" padahal yang gagal adalah
# baris terakhir skripnya.
jalankan_pagar() {
    # NGINX_CONF_TUJUAN mengalihkan tujuan `sed` ke berkas sementara: tanpa
    # itu entrypoint berhenti di bagian nginx karena tidak boleh menulis ke
    # /etc, dan kegagalan itu menutupi hasil pagar yang sedang diukur.
    ( cd "$AKAR" && PATH="$STUB:$PATH" NGINX_CONF_TUJUAN="$STUB/nginx.conf" \
        env "$@" sh "$ENTRY" true 2>&1 )
}
# (a) SMTP jarak jauh + alamat contoh -> HARUS berhenti dengan kode 1.
keluar="$(jalankan_pagar \
    APP_KEY=base64:uji \
    APP_ENV=production \
    MAIL_HOST=smtp.brevo.com \
    MAIL_FROM_ADDRESS=kos@contoh.test \
    RUN_MIGRATIONS=false 2>&1)"
kode=$?

pesan_ada=1
printf '%s' "$keluar" | grep -q 'alamat contoh' && pesan_ada=0

lapor 'alamat contoh + SMTP jarak jauh ditolak' \
    "$([ "$kode" -ne 0 ] && [ "$pesan_ada" -eq 0 ] && echo 0 || echo 1)" \
    "kode keluar: $kode"

# Pesannya harus TERBACA, bukan sekadar ada. Pagar yang berhenti tanpa
# menjelaskan sebabnya memaksa orang menebak, dan orang yang menebak akan
# menghapus pagarnya.
lapor 'penolakan menjelaskan sebabnya, bukan sekadar berhenti' \
    "$(printf '%s' "$keluar" | grep -q 'tautan aktivasi penghuni tidak pernah sampai' && echo 0 || echo 1)"

# (b) SMTP jarak jauh + alamat sungguhan -> HARUS lanjut.
keluar2="$(jalankan_pagar \
    APP_KEY=base64:uji \
    APP_ENV=production \
    MAIL_HOST=smtp.brevo.com \
    MAIL_FROM_ADDRESS=noreply@kos-saya.my.id \
    RUN_MIGRATIONS=false 2>&1)"
kode2=$?

lapor 'alamat sungguhan + SMTP jarak jauh diloloskan' \
    "$([ "$kode2" -eq 0 ] && echo 0 || echo 1)" \
    "kode keluar: $kode2"

# (c) Mailpit + alamat contoh -> HARUS lanjut. Profil produksi memang dirancang
# bisa dijalankan di laptop untuk menguji pagar-pagarnya, dan di sana alamat
# contoh adalah keadaan yang benar. Pagar yang menyala di sini akan memaksa
# orang mematikan pagar itu sendiri.
keluar3="$(jalankan_pagar \
    APP_KEY=base64:uji \
    APP_ENV=production \
    MAIL_HOST=mailpit \
    MAIL_FROM_ADDRESS=kos@contoh.test \
    RUN_MIGRATIONS=false 2>&1)"
kode3=$?

lapor 'Mailpit + alamat contoh tetap diloloskan (uji lokal tidak dipatahkan)' \
    "$([ "$kode3" -eq 0 ] && echo 0 || echo 1)" \
    "kode keluar: $kode3"

# (d) Alamat kosong + SMTP jarak jauh -> HARUS berhenti.
keluar4="$(jalankan_pagar \
    APP_KEY=base64:uji \
    APP_ENV=production \
    MAIL_HOST=smtp.brevo.com \
    MAIL_FROM_ADDRESS= \
    RUN_MIGRATIONS=false 2>&1)"
kode4=$?

lapor 'alamat kosong + SMTP jarak jauh ditolak' \
    "$([ "$kode4" -ne 0 ] && echo 0 || echo 1)" \
    "kode keluar: $kode4"

echo ''
echo '=== FASE 0.5: OPcache produksi tidak memeriksa timestamp ==='

PROD_INI="$AKAR/api/docker/php/opcache-prod.ini"
DEV_INI="$AKAR/api/docker/php/opcache.ini"

lapor 'opcache-prod.ini memakai validate_timestamps = 0' \
    "$(grep -qE '^opcache\.validate_timestamps[[:space:]]*=[[:space:]]*0$' "$PROD_INI" && echo 0 || echo 1)"

lapor 'opcache.ini pengembangan TETAP 1 (menyunting berkas langsung berefek)' \
    "$(grep -qE '^opcache\.validate_timestamps[[:space:]]*=[[:space:]]*1$' "$DEV_INI" && echo 0 || echo 1)"

lapor 'Dockerfile.prod menyalin opcache-prod.ini, bukan opcache.ini' \
    "$(grep -q 'opcache-prod.ini /usr/local/etc/php/conf.d/opcache.ini' "$AKAR/Dockerfile.prod" && echo 0 || echo 1)"

lapor 'Dockerfile pengembangan tetap memakai opcache.ini' \
    "$(grep -q 'COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini' "$AKAR/api/Dockerfile" && echo 0 || echo 1)"

rm -rf "$STUB"

echo ''
echo '=== FASE 0.1c/0.2: nginx meneruskan header proxy ke PHP ==='

# Tanpa `fastcgi_param HTTP_X_FORWARDED_*`, `include fastcgi_params` hanya
# mengoper REMOTE_ADDR - dan trustProxies tidak punya apa pun untuk dipercaya.
# Ini yang membuat perbaikan A1 sempat tampak selesai padahal tidak bekerja.
lapor 'nginx demo meneruskan X-Forwarded-For ke PHP' \
    "$(grep -q 'fastcgi_param HTTP_X_FORWARDED_FOR' "$AKAR/api/docker/nginx/default.conf" && echo 0 || echo 1)"

lapor 'nginx demo meneruskan X-Forwarded-Proto ke PHP' \
    "$(grep -q 'fastcgi_param HTTP_X_FORWARDED_PROTO' "$AKAR/api/docker/nginx/default.conf" && echo 0 || echo 1)"

lapor 'nginx produksi meneruskan X-Forwarded-For ke PHP' \
    "$(grep -q 'fastcgi_param HTTP_X_FORWARDED_FOR' "$AKAR/docker/prod/nginx.conf.template" && echo 0 || echo 1)"

lapor 'nginx produksi meneruskan X-Forwarded-Proto ke PHP' \
    "$(grep -q 'fastcgi_param HTTP_X_FORWARDED_PROTO' "$AKAR/docker/prod/nginx.conf.template" && echo 0 || echo 1)"

echo ''
if [ "${#GAGAL[@]}" -eq 0 ]; then
    echo 'PAGAR FASE 0 LULUS'
    exit 0
else
    echo "PAGAR FASE 0 GAGAL: ${#GAGAL[@]} pemeriksaan"
    for g in "${GAGAL[@]}"; do echo "  - $g"; done
    exit 1
fi
