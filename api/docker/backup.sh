#!/bin/sh
# Backup PostgreSQL terjadwal, dan pemulihannya (T29).
#
# Kenapa skrip ini ada padahal Postgres punya `pg_dump` sendiri: karena
# perintah satu baris yang ditulis di README akan terlupa, dan backup yang
# terlupa bukan backup. Yang dijalankan di sini adalah hal yang sama setiap
# kali, dengan nama berkas yang bisa diurutkan, retensi, dan pemeriksaan bahwa
# hasilnya benar-benar bisa dibaca.
#
# **Yang tidak dilakukan skrip ini, dan harus dilakukan manusia:**
# `./backup.sh --uji-pulih` memulihkan ke database sementara `kos_pulih` dan
# menghitung jumlah baris di tabel-tabel penting. Tanpa langkah itu, semua yang
# ada di sini hanya menghasilkan berkas yang belum pernah dibuka siapa pun.
set -eu

DIR_TUJUAN="${BACKUP_DIR:-/var/backups/kos}"
SIMPAN_HARI="${BACKUP_SIMPAN_HARI:-14}"

PGHOST="${DB_HOST:-db}"
PGPORT="${DB_PORT:-5432}"
PGUSER="${DB_USERNAME:-kos}"
PGDATABASE="${DB_DATABASE:-kos}"
export PGPASSWORD="${DB_PASSWORD:-kos}"

uji_pulih() {
    berkas="$1"

    if [ ! -f "$berkas" ]; then
        echo "[backup] berkas tidak ada: $berkas" >&2
        exit 1
    fi

    echo "[backup] memulihkan $berkas ke database sementara kos_pulih ..."

    # Database sementara selalu dibuat ulang. Memulihkan ke atas sisa
    # pemulihan sebelumnya akan gagal pada CREATE TABLE pertama, dan
    # kegagalan itu tidak ada hubungannya dengan kesehatan berkasnya.
    psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d postgres -q \
        -c "DROP DATABASE IF EXISTS kos_pulih" \
        -c "CREATE DATABASE kos_pulih"

    # `ON_ERROR_STOP=1` wajib: tanpa itu, psql melanjutkan setelah galat dan
    # skrip melaporkan "berhasil" untuk pemulihan yang kehilangan separuh tabel.
    psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d kos_pulih -q \
        -v ON_ERROR_STOP=1 -f "$berkas" >/dev/null

    echo "[backup] memeriksa isi database hasil pemulihan ..."

    for tabel in users penghuni kamar kontrak tagihan pembayaran log_audit; do
        jumlah=$(psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d kos_pulih -tAc \
            "SELECT count(*) FROM $tabel")
        echo "[backup]   $tabel: $jumlah baris"
    done

    psql -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d postgres -q \
        -c "DROP DATABASE kos_pulih"

    echo "[backup] pemulihan teruji: berkas bisa dibaca dan berisi tabel yang diharapkan."
}

if [ "${1:-}" = "--uji-pulih" ]; then
    terbaru=$(ls -1t "$DIR_TUJUAN"/kos-*.sql 2>/dev/null | head -n 1 || true)
    if [ -z "$terbaru" ]; then
        echo "[backup] belum ada berkas backup di $DIR_TUJUAN" >&2
        exit 1
    fi
    uji_pulih "$terbaru"
    exit 0
fi

mkdir -p "$DIR_TUJUAN"

cap=$(date -u +%Y%m%dT%H%M%SZ)
tujuan="$DIR_TUJUAN/kos-$cap.sql"

echo "[backup] menulis $tujuan ..."

# `--clean --if-exists` menuliskan DROP sebelum CREATE, sehingga hasilnya bisa
# dipulihkan ke database yang sudah berisi. Tanpa itu, pemulihan ke database
# yang tidak kosong gagal pada tabel pertama.
#
# `--no-owner --no-privileges` membuat berkasnya bisa dipulihkan oleh user
# database mana pun - penting karena lingkungan pemulihan sering memakai user
# yang berbeda dari produksi.
pg_dump -h "$PGHOST" -p "$PGPORT" -U "$PGUSER" -d "$PGDATABASE" \
    --clean --if-exists --no-owner --no-privileges \
    -f "$tujuan"

# Berkas nol byte adalah kegagalan yang paling berbahaya: ia terlihat seperti
# backup, berumur benar, dan baru ketahuan rusak saat dibutuhkan.
if [ ! -s "$tujuan" ]; then
    echo "[backup] GAGAL: berkas kosong" >&2
    rm -f "$tujuan"
    exit 1
fi

echo "[backup] selesai: $(wc -c < "$tujuan") byte"

echo "[backup] menghapus backup lebih tua dari $SIMPAN_HARI hari ..."
find "$DIR_TUJUAN" -name 'kos-*.sql' -type f -mtime "+$SIMPAN_HARI" -print -delete

echo "[backup] backup yang tersedia:"
ls -1t "$DIR_TUJUAN"/kos-*.sql 2>/dev/null || echo "  (tidak ada)"
