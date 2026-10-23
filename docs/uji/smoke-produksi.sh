#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Smoke test sesudah deploy.
#
#   ./docs/uji/smoke-produksi.sh https://kos-kosan.my.id
#
# Kenapa berkas ini terpisah dari workflow, bukan ditulis langsung sebagai
# langkah YAML: yang diperiksa di sini adalah EMPAT bahaya nyata yang hanya
# muncul di produksi, dan YAML tidak bisa dijalankan di laptop untuk menguji
# apakah pemeriksanya sendiri bekerja. Sebagai berkas shell ia bisa dijalankan
# terhadap stack lokal - dan memang begitu ia dibuktikan: dengan profil demo,
# pemeriksaan akun demo HARUS gagal; dengan profil produksi, semuanya harus
# lulus. Pemeriksaan yang tidak pernah terbukti bisa gagal bukan pemeriksaan.
#
# Yang diperiksa:
#
#   1. `/api/health` menjawab 200 dengan database "ok". Ini membuktikan
#      migrasi benar-benar berjalan - bukan sekadar prosesnya hidup.
#   2. **Akun demo tidak bisa masuk.** Kalau `RUN_SEEDER=true` bocor ke
#      produksi, `pemilik@kos.test / password` membuka seluruh data kos siapa
#      pun yang tahu alamatnya. Itu bukan risiko teoretis: nilai bawaan
#      compose adalah `true`.
#   3. **Header `X-Simulasi-Tanggal` diabaikan.** Kalau `APP_DEMO_MODE=true`
#      bocor, siapa pun bisa memundurkan tanggal dan menghapus denda, atau
#      memajukannya dan menagih denda yang belum jatuh tempo.
#   4. **CORS tidak menerima origin sembarang.** Nilai bawaan repo ini adalah
#      `['*']`, yang berarti situs mana pun bisa memanggil API memakai sesi
#      pengguna yang sedang masuk.
#
# Kode keluar 0 hanya bila keempatnya lulus.
# ---------------------------------------------------------------------------

set -uo pipefail

DASAR="${1:-}"
if [ -z "$DASAR" ]; then
    echo "pakai: $0 <url-dasar>   mis. https://kos-kosan.my.id" >&2
    exit 2
fi
DASAR="${DASAR%/}"

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

echo "Smoke test: $DASAR"

# --- 1. Hidup dan tersambung ke database -----------------------------------
badan="$(curl -s --max-time 30 "$DASAR/api/health" 2>/dev/null || true)"
kode="$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$DASAR/api/health" 2>/dev/null || true)"
echo "  health: HTTP ${kode:-000}  ${badan:0:200}"

lapor '/api/health menjawab 200' "$([ "$kode" = '200' ] && echo 0 || echo 1)" "HTTP ${kode:-000}"
lapor '/api/health menyebut database ok' "$(printf '%s' "$badan" | grep -q '"database":"ok"' && echo 0 || echo 1)"

# Zona waktu ikut diperiksa: seluruh perhitungan denda bergantung padanya, dan
# container yang salah waktu menghasilkan denda yang salah tanpa satu pun galat.
#
# Dicocokkan dengan pola yang menerima `Asia/Jakarta` maupun `Asia\/Jakarta`:
# json_encode milik PHP meng-escape garis miring secara bawaan, sehingga
# pencocokan teks biasa akan gagal pada jawaban yang sebenarnya benar.
lapor '/api/health menyebut zona waktu Asia/Jakarta' "$(printf '%s' "$badan" | grep -qE 'Asia\\\\?/Jakarta' && echo 0 || echo 1)"

# --- 2. Akun demo harus mati ----------------------------------------------
kode_login="$(curl -s -o /tmp/smoke-login.json -w '%{http_code}' --max-time 30 \
    -X POST "$DASAR/api/login" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d '{"email":"pemilik@kos.test","password":"password"}' 2>/dev/null || true)"
echo "  login demo: HTTP ${kode_login:-000}  $(head -c 160 /tmp/smoke-login.json 2>/dev/null || true)"
lapor 'akun demo pemilik tidak bisa masuk' "$([ "$kode_login" != '200' ] && echo 0 || echo 1)" "HTTP ${kode_login:-000}"

kode_pengelola="$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 \
    -X POST "$DASAR/api/login" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d '{"email":"pengelola@kos.test","password":"password"}' 2>/dev/null || true)"
lapor 'akun demo pengelola tidak bisa masuk' "$([ "$kode_pengelola" != '200' ] && echo 0 || echo 1)" "HTTP ${kode_pengelola:-000}"

kode_penghuni="$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 \
    -X POST "$DASAR/api/login" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d '{"email":"penghuni@kos.test","password":"password"}' 2>/dev/null || true)"
lapor 'akun demo penghuni tidak bisa masuk' "$([ "$kode_penghuni" != '200' ] && echo 0 || echo 1)" "HTTP ${kode_penghuni:-000}"

# --- 3. Simulasi tanggal harus diabaikan ----------------------------------
# `SimulasiTanggal` SELALU mengirim header `X-Simulasi-Dipakai` - nilai `true`
# atau `false`. Jadi yang menentukan bukan ada-tidaknya header itu, melainkan
# nilainya. Memeriksa keberadaannya akan lulus pada server yang simulasi
# tanggalnya justru hidup.
#
# `X-Tanggal-Sistem` hanya dikirim bila simulasi DIPAKAI, dan itulah penanda
# yang paling sulit dipalsukan: kalau header itu muncul, tanggal yang diminta
# benar-benar berlaku.
kepala="$(curl -s -D - -o /dev/null --max-time 30 \
    -H 'X-Simulasi-Tanggal: 2030-01-01' \
    -H 'Accept: application/json' \
    "$DASAR/api/me" 2>/dev/null || true)"
dipakai="$(printf '%s' "$kepala" | grep -iE '^x-simulasi-dipakai:' | tr -d '\r' | awk '{print tolower($2)}')"
tanggal_sistem="$(printf '%s' "$kepala" | grep -iE '^x-tanggal-sistem:' | tr -d '\r' || true)"
echo "  simulasi: X-Simulasi-Dipakai=${dipakai:-<tidak ada>}  ${tanggal_sistem:-}"

lapor 'header X-Simulasi-Dipakai menjawab false' "$([ "$dipakai" = 'false' ] && echo 0 || echo 1)" "nilai: ${dipakai:-<tidak ada>}"
lapor 'tanggal sistem tidak bisa digeser lewat header' "$([ -z "$tanggal_sistem" ] && echo 0 || echo 1)" "${tanggal_sistem:-tidak ada X-Tanggal-Sistem}"

# --- 4. CORS ---------------------------------------------------------------
acao="$(curl -s -D - -o /dev/null --max-time 30 \
    -H 'Origin: https://situs-penipu.example' "$DASAR/api/health" 2>/dev/null \
    | grep -iE '^access-control-allow-origin:' || true)"
echo "  CORS untuk origin asing: ${acao:-<tidak ada>}"
lapor 'CORS tidak menerima origin sembarang' "$(printf '%s' "$acao" | grep -q '\*' && echo 1 || echo 0)" "${acao:-tidak ada header}"

# --- Ringkasan -------------------------------------------------------------
echo ''
if [ "${#GAGAL[@]}" -eq 0 ]; then
    echo "SMOKE LULUS"
    exit 0
else
    echo "SMOKE GAGAL: ${#GAGAL[@]} pemeriksaan"
    for g in "${GAGAL[@]}"; do echo "  - $g"; done
    exit 1
fi
