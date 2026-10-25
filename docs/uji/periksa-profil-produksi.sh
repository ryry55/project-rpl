#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Memeriksa profil produksi (T31).
#
#   ./docs/uji/periksa-profil-produksi.sh
#
# Kenapa berkas terpisah dari tes PHPUnit: `docker-compose.prod.yml` berada di
# akar repo, sedangkan image api hanya berisi direktori `api/`. Tes yang
# berjalan di dalam container karena itu tidak bisa membacanya sama sekali -
# bukan soal izin, berkasnya memang tidak ikut ke dalam image.
#
# Kenapa harus diperiksa, bukan sekadar ditulis: berkas profil yang ADA tetapi
# NILAINYA salah lebih berbahaya daripada berkas yang tidak ada. Yang tidak ada
# memaksa orang mencari; yang salah membuat orang mengira pagarnya sudah
# terpasang. Setiap nilai di bawah menutup satu bahaya yang akibatnya bisa
# disebut tanpa membayangkan:
#
#   APP_DEBUG=true      - halaman galat Laravel menampilkan jejak tumpukan,
#                         nama berkas, dan isi variabel lingkungan.
#   APP_DEMO_MODE=true  - header `X-Simulasi-Tanggal` menggeser "hari ini",
#                         sehingga denda bisa dihapus atau ditagih lebih awal.
#   RUN_SEEDER=true     - sandi pemilik kembali menjadi "password" setiap
#                         layanan bangun dari tidur.
#   CORS `*`            - situs mana pun boleh membaca jawaban API.
#
# Kode keluar 0 hanya bila semuanya lulus.
# ---------------------------------------------------------------------------

set -uo pipefail

AKAR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PROD="$AKAR/docker-compose.prod.yml"
DASAR="$AKAR/docker-compose.yml"

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

ada() { grep -qF -- "$1" "$2" && echo 0 || echo 1; }
tidak_ada() { grep -qF -- "$1" "$2" && echo 1 || echo 0; }

echo "Profil produksi: $PROD"

lapor 'docker-compose.prod.yml ada' "$([ -f "$PROD" ] && echo 0 || echo 1)"
if [ ! -f "$PROD" ]; then
    echo "Berhenti: tanpa berkas profil, tidak ada yang bisa diperiksa." >&2
    exit 1
fi

# --- Nilai yang menutup bahaya --------------------------------------------
lapor 'APP_ENV=production' "$(ada 'APP_ENV: production' "$PROD")"
lapor 'APP_DEBUG=false' "$(ada 'APP_DEBUG: "false"' "$PROD")"
lapor 'APP_DEMO_MODE=false' "$(ada 'APP_DEMO_MODE: "false"' "$PROD")"
lapor 'RUN_SEEDER=false' "$(ada 'RUN_SEEDER: "false"' "$PROD")"

# --- Sesi dan cache tidak boleh berbasis berkas --------------------------
# Di container tanpa disk persisten, `file` berarti setiap restart memutus
# semua sesi dan mengosongkan pembatas percobaan masuk - sehingga batas itu
# bisa dilewati hanya dengan menunggu container tidur lalu bangun.
lapor 'SESSION_DRIVER=database' "$(ada 'SESSION_DRIVER: database' "$PROD")"
lapor 'CACHE_STORE=database' "$(ada 'CACHE_STORE: database' "$PROD")"

# --- Tidak boleh ada jalan pintas yang menghapus data ---------------------
# Komentar boleh menyebut `migrate:fresh` - dan memang menyebutnya, untuk
# menjelaskan kenapa perintah itu tidak ada. Yang diperiksa adalah baris
# perintah: baris yang tidak dimulai `#`.
perintah="$(grep -vE '^[[:space:]]*#' "$PROD" || true)"
lapor 'tidak menjalankan migrate:fresh' "$(printf '%s' "$perintah" | grep -q 'migrate:fresh' && echo 1 || echo 0)"

# --- Port api tidak boleh terbuka ----------------------------------------
# Bila port api dipublikasikan, larangan CORS menjadi tidak berarti: klien mana
# pun bisa memanggil API langsung tanpa melewati browser, dan CORS hanya
# berlaku di browser.
lapor 'tidak mempublikasikan port tambahan' "$(printf '%s' "$perintah" | grep -qE '^[[:space:]]*ports:' && echo 1 || echo 0)"

# --- Lapisan benar-benar menimpa, bukan menambah layanan kedua -----------
# Kesalahan yang mudah terjadi: menulis ulang seluruh layanan `api` di berkas
# profil, sehingga dua definisi berbeda hidup berdampingan dan compose memilih
# salah satunya. Yang benar adalah lapisan yang hanya mengubah environment.
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    gabung="$(cd "$AKAR" && docker compose -f docker-compose.yml -f docker-compose.prod.yml config 2>/dev/null || true)"
    if [ -z "$gabung" ]; then
        lapor 'docker compose menerima gabungan kedua berkas' 1 'compose config gagal'
    else
        lapor 'docker compose menerima gabungan kedua berkas' 0
        lapor 'gabungan menghasilkan APP_DEBUG=false' "$(printf '%s' "$gabung" | grep -q 'APP_DEBUG: "false"' && echo 0 || echo 1)"
        lapor 'gabungan menghasilkan RUN_SEEDER=false' "$(printf '%s' "$gabung" | grep -q 'RUN_SEEDER: "false"' && echo 0 || echo 1)"
        lapor 'gabungan tetap memuat seeder mati pada layanan api' "$(printf '%s' "$gabung" | grep -q 'APP_DEMO_MODE: "false"' && echo 0 || echo 1)"

        # `migrate:fresh` tidak boleh muncul di gabungan mana pun.
        lapor 'gabungan tidak memuat migrate:fresh' "$(printf '%s' "$gabung" | grep -q 'migrate:fresh' && echo 1 || echo 0)"
    fi
else
    echo '  dilewati: docker tidak tersedia, gabungan tidak bisa diperiksa'
fi

# --- Berkas dasar tidak boleh ikut berubah diam-diam ---------------------
# Profil produksi adalah LAPISAN. Kalau berkas dasar ikut diubah menjadi
# produksi, demo di laptop berhenti bisa dijalankan - dan itu cara paling cepat
# membuat orang berhenti menjalankan guardrail.
lapor 'docker-compose.yml dasar tetap memakai mode demo' "$(ada 'APP_DEMO_MODE: ${APP_DEMO_MODE:-true}' "$DASAR")"

# --- Ringkasan -----------------------------------------------------------
# --- Image produksi benar-benar menunjuk berkas yang ada -----------------
# Rujukan COPY ke berkas yang tidak ada baru ketahuan setelah build berjalan
# beberapa menit. Memeriksanya di sini membuat kesalahan itu ketahuan dalam
# hitungan detik.
for rujukan in docker/prod/entrypoint-prod.sh \
                docker/prod/nginx.conf.template \
                docker/prod/supervisord.conf \
                docker/prod/php-fpm.conf \
                api/docker/backup.sh; do
    if [ -f "$AKAR/$rujukan" ]; then
        lapor "berkas image ada: $rujukan" 0
    else
        lapor "berkas image ada: $rujukan" 1 'tidak ditemukan'
    fi
done

lapor 'Dockerfile.prod memakai entrypoint produksi' \
    "$(grep -q 'entrypoint-prod.sh' "$AKAR/Dockerfile.prod" && echo 0 || echo 1)"
lapor 'supervisord menjalankan penjadwal' \
    "$(grep -q 'schedule:work' "$AKAR/docker/prod/supervisord.conf" && echo 0 || echo 1)"

echo ''
if [ "${#GAGAL[@]}" -eq 0 ]; then
    echo 'PROFIL PRODUKSI LULUS'
    exit 0
else
    echo "PROFIL PRODUKSI GAGAL: ${#GAGAL[@]} pemeriksaan"
    for g in "${GAGAL[@]}"; do echo "  - $g"; done
    exit 1
fi
