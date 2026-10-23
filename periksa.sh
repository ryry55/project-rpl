#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Satu perintah untuk memeriksa seluruh repo - versi Linux.
#
#   ./periksa.sh              seluruh pemeriksaan
#   ./periksa.sh --cepat      lewati uji browser dan uji balapan
#   ./periksa.sh --tanpa-build  lewati build image (hanya bila kode tidak berubah)
#   ./periksa.sh --hanya-e2e  hanya uji browser (dipakai CI setelah tes PHP)
#
# Kenapa berkas ini ada, padahal `periksa.ps1` sudah melakukan hal yang sama:
# `periksa.ps1` adalah PowerShell, dan runner GitHub Actions tidak bisa
# menjalankannya. Menyalin urutan langkahnya ke dalam YAML workflow berarti ada
# DUA tempat yang harus diubah setiap kali daftar skrip uji bertambah - dan
# yang kedua pasti terlupa. Berkas ini adalah kembaran yang menjalankan urutan
# yang SAMA, sehingga workflow cukup memanggilnya.
#
# Perbedaan yang disengaja dari `periksa.ps1`, dan alasannya:
#
#   - pint dijalankan di HOST bila `php` tersedia, sama seperti versi
#     PowerShell. Bila tidak tersedia, ia dijalankan DI DALAM container - dan
#     itu sah hanya karena image baru dibangun pada jalannya skrip ini, jadi
#     yang diperiksa adalah kode sekarang, bukan kode lama. Dengan
#     `--tanpa-build` jaminan itu hilang, sehingga langkahnya dilewati dengan
#     peringatan, bukan dilaporkan lulus.
#   - eslint butuh `node_modules` di host. Bila belum ada, langkahnya dilewati
#     dengan peringatan yang menyebutkan perintah untuk memasangnya. CI
#     memeriksanya di job terpisah yang memang menyiapkan Node.
#
# Kode keluar 0 hanya bila semua langkah lulus.
# ---------------------------------------------------------------------------

set -uo pipefail

CEPAT=0
TANPA_BUILD=0
HANYA_E2E=0

for arg in "$@"; do
    case "$arg" in
        --cepat|-c) CEPAT=1 ;;
        --tanpa-build|-b) TANPA_BUILD=1 ;;
        --hanya-e2e) HANYA_E2E=1; CEPAT=0; TANPA_BUILD=1 ;;
        -h|--help)
            sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "pilihan tidak dikenal: $arg (coba --help)" >&2
            exit 2
            ;;
    esac
done

GAGAL=()
MULAI=$SECONDS

judul() {
    echo ''
    echo "=== $1 ==="
}

# $1 nama, $2 lulus (0/1), $3 detail
catat() {
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

# Port dibaca dari .env supaya skrip ini memakai port yang sama dengan compose.
port_dari_env() {
    local kunci="$1" bawaan="$2" nilai=''
    if [ -f .env ]; then
        nilai="$(grep -E "^${kunci}=" .env 2>/dev/null | tail -n1 | cut -d= -f2 | tr -d '\r')"
    fi
    printf '%s' "${nilai:-$bawaan}"
}

# Nilai bawaannya SENGAJA sama dengan nilai bawaan docker-compose.yml, bukan
# dengan isi `.env` di laptop pengembang. Kalau keduanya berbeda dan `.env`
# tidak ada, skrip ini akan memeriksa port yang tidak didengarkan siapa pun
# dan melaporkan "API tidak menjawab" pada stack yang sebenarnya sehat.
API_PORT="$(port_dari_env API_PORT 8080)"
WEB_PORT="$(port_dari_env WEB_PORT 3000)"
MAILPIT_PORT="$(port_dari_env MAILPIT_UI_PORT 8025)"

export CDP_BASE="${CDP_BASE:-http://127.0.0.1:9222}"
export APP_URL="${APP_URL:-http://localhost:$WEB_PORT}"
export API_URL="${API_URL:-http://localhost:$API_PORT/api}"
export MAILPIT_URL="${MAILPIT_URL:-http://localhost:$MAILPIT_PORT}"

# Label yang dilaporkan diambil dari CDP_BASE, bukan ditulis tetap: saat
# dijalankan dengan instance Chrome lain (mis. headless pada port 9333),
# melaporkan "port=9222" akan menyesatkan orang yang membaca log.
CDP_LABEL="$CDP_BASE"

BUILD_DIJALANKAN=0

# --- 1. Docker hidup? -------------------------------------------------------
# Diperiksa SELALU, termasuk pada --hanya-e2e: langkah uji browser memanggil
# `docker compose exec` untuk menyegarkan database, jadi tanpa Docker ia akan
# gagal satu per satu dengan pesan yang tidak menyebut sebabnya.
judul 'Docker'
if docker info >/dev/null 2>&1; then
    catat 'Docker berjalan' 0
else
    catat 'Docker berjalan' 1 'jalankan Docker lebih dulu'
    echo ''
    echo 'Berhenti: tanpa Docker, semua langkah lain akan gagal.' >&2
    exit 1
fi

# --- 2. Build ---------------------------------------------------------------
# Kode dibakar ke image. Melewati langkah ini adalah cara paling umum
# mendapatkan hasil hijau yang menyesatkan.
if [ "$HANYA_E2E" -eq 0 ]; then
    judul 'Build image'
    if [ "$TANPA_BUILD" -eq 0 ]; then
        if docker compose build api web >/dev/null 2>&1; then
            catat 'image api dan web dibangun' 0
            BUILD_DIJALANKAN=1
        else
            catat 'image api dan web dibangun' 1 'lihat: docker compose build api web'
        fi

        if docker compose up -d >/dev/null 2>&1; then
            catat 'container berjalan' 0
        else
            catat 'container berjalan' 1 'lihat: docker compose up -d'
        fi

        # Tunggu API benar-benar menjawab, bukan sekadar container hidup.
        siap=1
        for _ in $(seq 1 40); do
            kode="$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "$API_URL/health" 2>/dev/null || true)"
            if [ "$kode" = '200' ]; then siap=0; break; fi
            sleep 1
        done
        catat 'API menjawab' "$siap" "GET $API_URL/health -> ${kode:-000}"
    else
        echo '  dilewati (--tanpa-build)'
    fi
fi

# --- 3. Tes PHP -------------------------------------------------------------
if [ "$HANYA_E2E" -eq 0 ]; then
    judul 'Tes PHP'
    keluaran="$(docker compose exec -T api php artisan test 2>&1)"
    kode=$?
    ringkas="$(printf '%s\n' "$keluaran" | grep -E 'Tests:' | tail -n1 | sed 's/^[[:space:]]*//')"
    catat 'php artisan test' "$([ "$kode" -eq 0 ] && echo 0 || echo 1)" "$ringkas"
    if [ "$kode" -ne 0 ]; then
        printf '%s\n' "$keluaran" | grep -E 'FAILED|Tests:' | head -n 15 | sed 's/^/    /'
    fi
fi

# --- 4. Format kode ---------------------------------------------------------
# Diperiksa SELURUH kode, bukan hanya berkas yang berubah: repo yang tidak
# pint-clean akan lolos selamanya kalau yang diperiksa hanya yang berubah.
if [ "$HANYA_E2E" -eq 0 ]; then
    judul 'Format (pint pada seluruh kode)'
    if command -v php >/dev/null 2>&1 && [ -f api/vendor/bin/pint ]; then
        keluaran_pint="$(cd api && php vendor/bin/pint --test 2>&1)"
        kode=$?
        catat 'pint bersih pada seluruh kode' "$([ "$kode" -eq 0 ] && echo 0 || echo 1)" 'jalankan: cd api; php vendor/bin/pint'
        if [ "$kode" -ne 0 ]; then printf '%s\n' "$keluaran_pint" | head -n 10 | sed 's/^/    /'; fi
    elif [ "$BUILD_DIJALANKAN" -eq 1 ]; then
        # Image baru dibangun dari working tree pada jalannya skrip ini, jadi
        # salinan di dalamnya adalah kode sekarang - bukan kode lama.
        keluaran_pint="$(docker compose exec -T api php vendor/bin/pint --test 2>&1)"
        kode=$?
        catat 'pint bersih pada seluruh kode' "$([ "$kode" -eq 0 ] && echo 0 || echo 1)" 'diperiksa di dalam image yang baru dibangun'
        if [ "$kode" -ne 0 ]; then printf '%s\n' "$keluaran_pint" | head -n 10 | sed 's/^/    /'; fi
    else
        # Jangan dilaporkan lulus. Tanpa build ulang dan tanpa php di host,
        # yang bisa diperiksa hanyalah salinan lama - dan lulus di situ berarti
        # tidak memeriksa apa pun.
        echo '  dilewati: tidak ada php di host dan image tidak dibangun ulang'
        echo '            jalankan: cd api && php vendor/bin/pint   (atau tanpa --tanpa-build)'
    fi
fi

# --- 5. Lint frontend -------------------------------------------------------
if [ "$HANYA_E2E" -eq 0 ]; then
    judul 'Lint frontend'
    if command -v npm >/dev/null 2>&1 && [ -d web/node_modules ]; then
        keluaran_lint="$(cd web && npm run lint 2>&1)"
        kode=$?
        catat 'eslint' "$([ "$kode" -eq 0 ] && echo 0 || echo 1)" 'jalankan: cd web; npm run lint'
        if [ "$kode" -ne 0 ]; then printf '%s\n' "$keluaran_lint" | grep -i 'error' | head -n 10 | sed 's/^/    /'; fi
    else
        echo '  dilewati: web/node_modules belum ada (cd web; npm install)'
    fi
fi

# --- 6. Profil produksi ------------------------------------------------------
# Berkas profil yang ada tetapi nilainya salah lebih berbahaya daripada yang
# tidak ada: ia membuat orang mengira pagarnya sudah terpasang. Diperiksa di
# sini karena `docker-compose.prod.yml` berada di akar repo - di luar build
# context image api, sehingga tes PHPUnit tidak bisa membacanya.
if [ "$HANYA_E2E" -eq 0 ]; then
    judul 'Profil produksi'
    if [ -f docs/uji/periksa-profil-produksi.sh ]; then
        keluaran_profil="$(bash docs/uji/periksa-profil-produksi.sh 2>&1)"
        kode=$?
        catat 'profil produksi menutup tiga bahaya' "$([ "$kode" -eq 0 ] && echo 0 || echo 1)"
        if [ "$kode" -ne 0 ]; then printf '%s\n' "$keluaran_profil" | grep -E '^  - ' | sed 's/^/    /'; fi
    else
        catat 'profil produksi menutup tiga bahaya' 1 'docs/uji/periksa-profil-produksi.sh tidak ada'
    fi
fi

# --- 7. Uji browser ---------------------------------------------------------
if [ "$CEPAT" -eq 0 ]; then
    judul 'Uji browser (E2E)'

    # Sintaks seluruh skrip uji diperiksa LEBIH DULU. Satu backtick yang salah
    # tempat di dalam template literal baru ketahuan setelah build image, 594
    # tes PHP, pint, dan eslint selesai - sekitar lima menit. `node --check`
    # menjawabnya dalam hitungan detik dan menyebut berkas serta barisnya.
    if command -v node >/dev/null 2>&1; then
        rusak=''
        for s in docs/uji/*.mjs; do
            if ! node --check "$s" >/dev/null 2>&1; then
                rusak="$rusak $(basename "$s")"
            fi
        done
        if [ -z "$rusak" ]; then
            catat 'sintaks seluruh skrip uji' 0
        else
            catat 'sintaks seluruh skrip uji' 1 "rusak:$rusak"
        fi
    else
        catat 'sintaks seluruh skrip uji' 1 'node tidak ada di PATH'
    fi

    if curl -s --max-time 3 "$CDP_BASE/json/version" >/dev/null 2>&1; then
        catat "Chrome di $CDP_LABEL" 0

        # Skrip berikut MENGUBAH database demo, jadi database di-seed ulang
        # sebelum masing-masing dijalankan. Daftar ini harus sama dengan
        # $e2e di periksa.ps1 - dua tempat, satu isi.
        E2E=(
            e2e-t1.mjs e2e-t2.mjs e2e-t3.mjs e2e-t4.mjs
            e2e-t5.mjs e2e-t6.mjs e2e-t7.mjs e2e-t9.mjs
            e2e-t18.mjs e2e-t19.mjs e2e-t20.mjs e2e-t24.mjs
            e2e-t25.mjs e2e-t26.mjs e2e-t27.mjs e2e-t28.mjs
            asap-semua-halaman.mjs uji-tata-letak.mjs
        )

        tutup_tab() {
            # Tab yatim dari percobaan sebelumnya membuat langkah login gagal
            # karena alasan yang tidak ada hubungannya dengan aplikasi.
            node -e '
              const b = process.env.CDP_BASE
              fetch(b + "/json/list").then(r => r.json())
                .then(ts => Promise.all(ts.filter(t => t.type === "page")
                  .map(t => fetch(b + "/json/close/" + t.id))))
                .catch(() => {})
            ' >/dev/null 2>&1 || true
        }

        for s in "${E2E[@]}"; do
            tutup_tab
            docker compose exec -T api php artisan migrate:fresh --seed >/dev/null 2>&1
            # Cache ikut dibersihkan, bukan hanya database. Pembatas percobaan
            # masuk menyimpan hitungannya di cache, dan `migrate:fresh` tidak
            # menyentuhnya - sehingga kegagalan login yang disengaja oleh satu
            # skrip membuat skrip berikutnya menerima 429 pada login yang BENAR.
            # Tanpa baris ini urutan skrip menentukan hasil, dan kegagalannya
            # berpindah-pindah seperti gangguan jaringan.
            docker compose exec -T api php artisan cache:clear >/dev/null 2>&1
            # Batas waktu per skrip. Tanpa ini, satu skrip yang menggantung
            # menahan seluruh rangkaian tanpa mencetak namanya - dan di CI itu
            # terlihat sebagai "job kehabisan waktu", bukan sebagai "skrip X
            # menggantung". `timeout` mengubahnya menjadi kegagalan yang
            # menyebut pelakunya. 240 detik = ~3x skrip terlama (88 langkah).
            #
            # `timeout` mengembalikan 124 bila batasnya tercapai, dan 127 bila
            # perintahnya tidak ada. Keduanya sampai ke `catat` sebagai gagal.
            if command -v timeout >/dev/null 2>&1; then
                hasil="$(timeout 240 node "docs/uji/$s" 2>&1)"
            else
                hasil="$(node "docs/uji/$s" 2>&1)"
            fi
            kode=$?
            akhir="$(printf '%s\n' "$hasil" | grep -E 'langkah lulus' | tail -n1)"
            catat "$s" "$([ "$kode" -eq 0 ] && echo 0 || echo 1)" "$akhir"
            if [ "$kode" -ne 0 ]; then
                printf '%s\n' "$hasil" | grep -E '^GAGAL|E2E gagal' | sed 's/^/    /'
            fi
        done

        judul 'Uji balapan'
        for s in uji-balapan-t4.mjs uji-balapan-t5.mjs; do
            docker compose exec -T api php artisan migrate:fresh --seed >/dev/null 2>&1
            docker compose exec -T api php artisan cache:clear >/dev/null 2>&1
            if command -v timeout >/dev/null 2>&1; then
                hasil="$(timeout 240 node "docs/uji/$s" 2>&1)"
            else
                hasil="$(node "docs/uji/$s" 2>&1)"
            fi
            kode=$?
            akhir="$(printf '%s\n' "$hasil" | grep -E 'langkah lulus' | tail -n1)"
            catat "$s" "$([ "$kode" -eq 0 ] && echo 0 || echo 1)" "$akhir"
        done
    else
        catat "Chrome di $CDP_LABEL" 1 'uji browser dilewati'
    fi
else
    judul 'Uji browser'
    echo '  dilewati (--cepat)'
fi

# --- Ringkasan --------------------------------------------------------------
DURASI=$((SECONDS - MULAI))
echo ''
if [ "${#GAGAL[@]}" -eq 0 ]; then
    echo "SEMUA LULUS  ($DURASI detik)"
    exit 0
else
    echo "GAGAL: ${#GAGAL[@]} langkah  ($DURASI detik)"
    for g in "${GAGAL[@]}"; do echo "  - $g"; done
    exit 1
fi
