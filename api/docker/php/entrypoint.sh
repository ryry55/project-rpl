#!/bin/sh
set -e

# Tunggu PostgreSQL siap. `depends_on` hanya menunggu container start, bukan
# database benar-benar menerima koneksi — dua hal berbeda.
if [ -n "$DB_HOST" ]; then
    echo "[entrypoint] menunggu PostgreSQL di ${DB_HOST}:${DB_PORT:-5432} ..."
    i=0
    until php -r '
        $h = getenv("DB_HOST"); $p = getenv("DB_PORT") ?: "5432";
        $d = getenv("DB_DATABASE"); $u = getenv("DB_USERNAME"); $w = getenv("DB_PASSWORD");
        try { new PDO("pgsql:host=$h;port=$p;dbname=$d", $u, $w); exit(0); }
        catch (Throwable $e) { exit(1); }
    ' 2>/dev/null; do
        i=$((i + 1))
        if [ "$i" -ge 60 ]; then
            echo "[entrypoint] GAGAL: PostgreSQL tidak siap setelah 120 detik" >&2
            exit 1
        fi
        sleep 2
    done
    echo "[entrypoint] PostgreSQL siap."
fi

if [ ! -f .env ]; then
    echo "[entrypoint] .env tidak ada, menyalin dari .env.example"
    cp .env.example .env
fi

# APP_KEY harus ada sebelum migrasi; kalau kosong, hasilkan sekali.
if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    echo "[entrypoint] APP_KEY kosong, membuat kunci baru"
    php artisan key:generate --force --no-interaction
fi

php artisan config:clear >/dev/null 2>&1 || true

# Migrasi + seed otomatis. Idempoten: aman dijalankan setiap container start,
# dan itulah syarat "docker compose up dari keadaan bersih tanpa langkah manual".
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "[entrypoint] menjalankan migrasi"
    php artisan migrate --force --no-interaction

    if [ "${RUN_SEEDER:-true}" = "true" ]; then
        echo "[entrypoint] menjalankan seeder"
        php artisan db:seed --force --no-interaction || true
    fi
fi

echo "[entrypoint] siap."
exec "$@"
