// Uji jalur proxy (FASE 0.1b).
//
// Inilah uji yang selama ini TIDAK ADA, dan itulah sebabnya cacat A1 lolos
// 748 tes PHP plus 27 skrip E2E tanpa pernah ketahuan.
//
// Kenapa tidak cukup diuji lewat layar: cacat A1 tidak punya gejala yang
// terlihat. `$request->ip()` tetap mengembalikan sebuah alamat - hanya alamat
// yang salah. Tidak ada tombol yang berubah, tidak ada halaman yang rusak.
// Yang berubah hanya DUA hal, dan keduanya tidak tampak:
//
//   1. Pembatas laju menghitung seluruh pengguna sebagai satu IP, sehingga
//      percobaan ke-21 dari orang yang berbeda ikut ditolak (429).
//   2. Log audit mencatat IP proxy, bukan IP pelaku.
//
// Uji ini menembak lewat port PROXY (bawaan :3000), bukan langsung ke API.
// Perbedaannya menentukan: pada jalur langsung tidak ada yang menambahkan
// X-Forwarded-For, sehingga uji akan lulus meski perbaikannya tidak ada.
//
// Pakai: node docs/uji/uji-proxy.mjs
// Prasyarat: docker compose up (web :3000, api :8081).

const PROXY = process.env.PROXY_URL || 'http://localhost:3000'

const langkah = []
function lapor(nama, lulus, detail = '') {
  langkah.push({ nama, lulus })
  console.log(`${lulus ? 'LULUS' : 'GAGAL'}  ${nama}${detail ? ' - ' + detail : ''}`)
}

/**
 * Memanggil /api/periksa-token lewat proxy dengan X-Forwarded-For tertentu.
 *
 * Tokennya sengaja tidak sah. Yang diuji bukan hasil pemeriksaan token,
 * melainkan IP mana yang dipakai pembatas laju - dan token tidak sah tetap
 * menghasilkan 200 dengan `berlaku: false`.
 */
async function periksaToken(ip) {
  const res = await fetch(`${PROXY}/api/periksa-token`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Forwarded-For': ip,
    },
    body: JSON.stringify({ token: 'token-uji-proxy', jenis: 'aktivasi' }),
  })
  return res.status
}

/**
 * Memanggil endpoint yang butuh token, tanpa token.
 *
 * Menghasilkan 401, dan middleware CatatPermintaanGagal menuliskan IP-nya ke
 * log. Dipakai untuk membuktikan IP yang DICATAT, bukan hanya yang dipakai
 * pembatas.
 */
async function panggilTanpaToken(ip) {
  const headers = { Accept: 'application/json' }
  if (ip) headers['X-Forwarded-For'] = ip

  const res = await fetch(`${PROXY}/api/tagihan`, { method: 'GET', headers })
  return res.status
}

async function jalan() {
  // --- Bagian 1: pembatas laju memakai IP klien, bukan IP proxy -----------
  //
  // 25 percobaan dari 25 IP yang BERBEDA-BEDA. Batasnya 20 per menit per IP,
  // jadi bila IP klien benar-benar terbaca, tidak satu pun boleh ditolak.
  //
  // Sebelum perbaikan, uji ini gagal tepat pada percobaan ke-21: ke-25
  // permintaan berbagi satu IP (IP container web), dan 5 sisanya dijawab 429.
  const jumlah = 25
  const hasil = []
  for (let i = 1; i <= jumlah; i += 1) {
    hasil.push(await periksaToken(`203.0.113.${i}`))
  }

  const ditolak = hasil.filter((s) => s === 429).length
  lapor(
    `${jumlah} permintaan dari ${jumlah} IP berbeda tidak ada yang kena 429`,
    ditolak === 0,
    ditolak === 0 ? `${jumlah} x 200` : `${ditolak} ditolak (mulai percobaan ke-${hasil.indexOf(429) + 1})`,
  )

  // --- Bagian 2: pembatas tetap BEKERJA untuk satu IP ---------------------
  //
  // Ini penting dan mudah terlewat. Uji di atas membuktikan pembatas TIDAK
  // salah menghitung; uji ini membuktikan pembatas tidak justru dimatikan.
  // Perbaikan yang membuat semuanya 200 selamanya sama saja dengan menghapus
  // pembatasnya, dan itu regresi yang lebih buruk daripada cacat aslinya.
  const ipSama = '198.51.100.200'
  const beruntun = []
  for (let i = 0; i < 22; i += 1) {
    beruntun.push(await periksaToken(ipSama))
  }
  const ditolakSama = beruntun.filter((s) => s === 429).length
  const pertama429 = beruntun.indexOf(429) + 1

  lapor(
    'satu IP yang sama TETAP dibatasi pada percobaan ke-21',
    ditolakSama > 0 && pertama429 === 21,
    `429 pertama pada percobaan ke-${pertama429}, total ${ditolakSama} ditolak`,
  )

  // --- Bagian 3: IP yang tercatat di log adalah IP klien -------------------
  //
  // Dua IP klien berbeda harus tercatat sebagai dua nilai berbeda. Sebelum
  // perbaikan, keduanya tercatat sama: IP container `web` (172.20.0.4).
  //
  // Log dibaca dari `docker compose logs`, sama seperti e2e-t26/e2e-t27
  // memakai perintah docker. Yang dicari adalah entri `permintaan.gagal` yang
  // ditulis CatatPermintaanGagal untuk 401 yang baru saja kita buat.
  const ipA = '203.0.113.45'
  const ipB = '198.51.100.77'

  await panggilTanpaToken(ipA)
  await panggilTanpaToken(ipB)
  await new Promise((r) => setTimeout(r, 1200))

  let log = ''
  try {
    const { execFileSync } = await import('node:child_process')
    // `--since` dipakai, bukan `--tail`. Dengan `--tail`, baris lama dari
    // SEBELUM perbaikan ikut terbaca - dan uji akan gagal karena sejarah,
    // bukan karena keadaan sekarang. Jendela 60 detik cukup untuk memuat
    // kedua permintaan di atas dan tidak lebih.
    log = execFileSync('docker', ['compose', 'logs', '--since=60s', 'api'], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    })
  } catch (e) {
    log = String(e.stdout || '')
  }

  const tercatatA = log.includes(`"ip":"${ipA}"`)
  const tercatatB = log.includes(`"ip":"${ipB}"`)

  lapor(
    'IP klien pertama tercatat apa adanya di log',
    tercatatA,
    tercatatA ? ipA : 'tidak ditemukan di log',
  )
  lapor(
    'IP klien kedua tercatat apa adanya di log',
    tercatatB,
    tercatatB ? ipB : 'tidak ditemukan di log',
  )

  // --- Bagian 4: permintaan TANPA header proxy tetap tercatat -------------
  //
  // Ini bukan sekadar pelengkap, dan versi pertama uji ini SALAH di sini.
  //
  // Versi pertama memeriksa "log tidak boleh memuat IP 172.x sama sekali".
  // Asumsi itu keliru: permintaan yang TIDAK membawa X-Forwarded-For - dan
  // itu mayoritas lalu lintas sungguhan, termasuk seluruh 20 skrip E2E yang
  // menembak dari host - memang tercatat dengan IP sebenarnya dari sudut
  // pandang kontainer, yaitu gateway Docker (172.20.0.1). Itu perilaku yang
  // BENAR, bukan cacat. Yang salah adalah ujinya.
  //
  // Yang perlu dibuktikan justru sebaliknya: setelah proxy dipercaya,
  // permintaan tanpa header tidak boleh kehilangan IP-nya (kosong atau null).
  // Kalau itu terjadi, pembatas laju kehilangan kuncinya dan semua permintaan
  // tanpa proxy berbagi satu kunci kosong.
  await panggilTanpaToken(null)
  await new Promise((r) => setTimeout(r, 1200))

  let log2 = ''
  try {
    const { execFileSync } = await import('node:child_process')
    log2 = execFileSync('docker', ['compose', 'logs', '--since=20s', 'api'], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    })
  } catch (e) {
    log2 = String(e.stdout || '')
  }

  const adaIpKosong = /"ip":""/.test(log2) || /"ip":null/.test(log2)

  lapor(
    'permintaan tanpa header proxy tetap punya IP, tidak kosong',
    !adaIpKosong,
    adaIpKosong ? 'ada entri dengan ip kosong/null' : 'semua entri ber-IP',
  )

  const gagal = langkah.filter((l) => !l.lulus)
  console.log(`\n${langkah.length - gagal.length}/${langkah.length} langkah lulus`)
  if (gagal.length) process.exitCode = 1
}

jalan().catch((e) => {
  console.error('uji proxy gagal:', e.message)
  process.exitCode = 1
})
