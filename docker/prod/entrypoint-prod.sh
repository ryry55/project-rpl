#!/bin/sh
# ---------------------------------------------------------------------------
# Entrypoint produksi satu layanan.
#
# Dipanggil sebelum supervisor menjalankan php-fpm dan nginx. Tugasnya tiga:
# menunggu database, merender konfigurasi yang butuh PORT, dan menutup pagar
# produksi. Semua yang di sini idempoten - container boleh restart berkali-kali.
# ---------------------------------------------------------------------------

set -e

echo "[entrypoint] mulai"

# --- 1. Tunggu database ----------------------------------------------------
# Render/Neon membuka koneksi lebih lambat daripada container yang bangun.
# Tanpa penantian, `migrate` gagal pada percobaan pertama dan container
# dianggap gagal start padahal databasenya hanya belum siap.
if [ -n "$DB_HOST" ]; then
    echo "[entrypoint] menunggu database di ${DB_HOST}:${DB_PORT:-5432} ..."
    i=0
    until php -r '
        $h = getenv("DB_HOST"); $p = getenv("DB_PORT") ?: "5432";
        $d = getenv("DB_DATABASE"); $u = getenv("DB_USERNAME"); $w = getenv("DB_PASSWORD");
        try {
            // Neon mewajibkan TLS. `sslmode=require` dipakai bila disetel
            // lewat DB_SSLMODE, karena tanpa itu koneksi ditolak.
            $ssl = getenv("DB_SSLMODE");
            $dsn = "pgsql:host=$h;port=$p;dbname=$d" . ($ssl ? ";sslmode=$ssl" : "");
            new PDO($dsn, $u, $w);
            exit(0);
        } catch (Throwable $e) { exit(1); }
    ' 2>/dev/null; do
        i=$((i + 1))
        if [ "$i" -ge 60 ]; then
            echo "[entrypoint] GAGAL: database tidak siap setelah 120 detik" >&2
            exit 1
        fi
        sleep 2
    done
    echo "[entrypoint] database siap."
fi

# --- 2. Pagar produksi -----------------------------------------------------
# Diperiksa SEBELUM migrasi, karena kesalahan konfigurasi lebih baik
# menggagalkan start daripada menghasilkan layanan yang tampak sehat.
if [ "${APP_ENV:-production}" != "production" ]; then
    echo "[entrypoint] PERINGATAN: APP_ENV bukan production (${APP_ENV:-})." >&2
fi

if [ "${APP_DEBUG:-false}" = "true" ]; then
    echo "[entrypoint] BERHENTI: APP_DEBUG=true di produksi membocorkan jejak tumpukan," >&2
    echo "[entrypoint]           nama berkas, dan isi variabel lingkungan lewat halaman galat." >&2
    exit 1
fi

if [ "${APP_DEMO_MODE:-false}" = "true" ]; then
    echo "[entrypoint] BERHENTI: APP_DEMO_MODE=true membuat header X-Simulasi-Tanggal" >&2
    echo "[entrypoint]           diterima, sehingga siapa pun bisa memundurkan tanggal" >&2
    echo "[entrypoint]           untuk menghapus denda." >&2
    exit 1
fi

# Seeder menulis sandi pemilik menjadi "password" setiap kali berjalan.
# Di produksi itu berarti seluruh data kos terbuka bagi siapa pun yang tahu
# alamatnya. Seeder sendiri sudah menolak (T29); ini menutup jalan lainnya.
if [ "${RUN_SEEDER:-false}" = "true" ]; then
    echo "[entrypoint] PERINGATAN: RUN_SEEDER=true diabaikan di produksi." >&2
    RUN_SEEDER=false
    export RUN_SEEDER
fi

# --- 3. APP_KEY ------------------------------------------------------------
# Gagal keras bila kosong. Sandi terenkripsi dan token sesi bergantung pada
# kunci ini; membuat kunci baru di produksi akan memutus semua sesi dan
# membuat data terenkripsi lama tidak terbaca.
if [ -z "${APP_KEY:-}" ]; then
    if [ -f .env ] && grep -q '^APP_KEY=base64:' .env; then
        echo "[entrypoint] APP_KEY dibaca dari .env"
    else
        echo "[entrypoint] BERHENTI: APP_KEY kosong. Setel lewat environment." >&2
        echo "[entrypoint]           Membuat kunci baru di sini akan memutus semua sesi" >&2
        echo "[entrypoint]           dan membuat data terenkripsi lama tidak terbaca." >&2
        exit 1
    fi
fi

# --- 4. Render konfigurasi yang butuh PORT --------------------------------
PORT="${PORT:-8080}"
export PORT
sed "s/\${PORT}/$PORT/g" /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf
echo "[entrypoint] nginx mendengarkan di port $PORT"

# Pastikan direktori tulis ada. Volume tidak dipakai di Render free, jadi
# direktori ini hidup selama container - cukup untuk log dan cache tampilan,
# sedangkan sesi disimpan di database (SESSION_DRIVER=database) justru karena
# isi direktori ini hilang setiap restart.
mkdir -p storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs bootstrap/cache

# --- 5. Migrasi ------------------------------------------------------------
# `--force` wajib karena APP_ENV=production. Yang TIDAK pernah dijalankan di
# sini: `migrate:fresh`. Satu perintah itu menghapus seluruh tagihan dan
# pembayaran, dan tidak ada keadaan produksi yang membutuhkannya.
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "[entrypoint] menjalankan migrasi"
    php artisan migrate --force --no-interaction
fi

# --- 6. Cache konfigurasi --------------------------------------------------
# Dilakukan SETELAH migrasi dan setelah seluruh environment tersedia, supaya
# nilai yang tersimpan adalah nilai produksi. `config:cache` membuat `env()`
# di luar berkas config tidak terbaca - dan repo ini tidak memakainya di luar
# config, jadi aman.
echo "[entrypoint] menyegarkan cache konfigurasi"
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

# --- 7. Kepemilikan berkas -------------------------------------------------
# Baru di sini, BUKAN sebelumnya: seluruh perintah artisan di atas berjalan
# sebagai root dan menulis ke storage/logs serta bootstrap/cache. Bila
# kepemilikan diberikan lebih dulu, berkas yang baru dibuat tetap milik root,
# dan php-fpm (www-data) gagal menulis log pada permintaan pertama.
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

echo "[entrypoint] siap."
exec "$@"
