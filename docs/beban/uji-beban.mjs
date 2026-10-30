// Uji beban `GET /api/tagihan`.
//
// Bukan bagian dari `periksa.ps1` dan bukan bagian dari CI. Ambang waktu di
// mesin bersama mengukur tetangga, bukan aplikasi: runner yang sedang sibuk
// akan membuat gerbang merah acak, dan gerbang yang merah acak berhenti dibaca.
// Jalankan manual di mesin yang memang akan dipakai untuk demo.
//
// Pakai:
//   node docs/beban/uji-beban.mjs
//
// Variabel lingkungan (semuanya opsional):
//   API_URL      bawaan http://localhost:8081/api
//   BEBAN_PERMINTAAN   bawaan 500
//   BEBAN_BERSAMAAN    bawaan 50
//   AMBANG_P95         bawaan 1000 (milidetik)
//
// Kode keluar 1 bila ada jawaban non-2xx atau p95 melewati ambang.
//
// Kontrol negatif yang sudah dijalankan: `AMBANG_P95=1 node
// docs/beban/uji-beban.mjs` gagal dengan "p95 ... melewati ambang 1 ms".
// Tanpa itu, ambang yang tidak pernah merah hanya upacara.

const API = process.env.API_URL || 'http://localhost:8081/api'
const JUMLAH = Number(process.env.BEBAN_PERMINTAAN || 500)
const BERSAMAAN = Number(process.env.BEBAN_BERSAMAAN || 50)
const AMBANG_P95 = Number(process.env.AMBANG_P95 || 1000)

// Akun demo dari seeder. Uji beban tidak boleh membuat akun baru: yang diukur
// adalah endpoint yang sudah ada, dan akun tambahan mengubah data demo.
const EMAIL = process.env.BEBAN_EMAIL || 'pemilik@kos.test'
const SANDI = process.env.BEBAN_SANDI || 'password'

async function token() {
  const res = await fetch(`${API}/login`, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: EMAIL, password: SANDI }),
  })

  if (!res.ok) {
    throw new Error(`login gagal (${res.status}) - apakah database sudah di-seed?`)
  }

  const isi = await res.json()
  return isi.data.token
}

function persentil(daftar, p) {
  // `daftar` sudah terurut menaik. Indeks digeser ke bawah supaya p95 dari 500
  // angka tetap menunjuk angka yang benar-benar ada.
  const i = Math.min(daftar.length - 1, Math.floor((p / 100) * daftar.length))
  return daftar[i]
}

async function main() {
  const kunci = await token()

  const headers = { Accept: 'application/json', Authorization: `Bearer ${kunci}` }
  const alamat = `${API}/tagihan?per_page=50&page=1`

  // Pemanasan: satu permintaan supaya koneksi, opcache, dan rencana kueri
  // sudah terbentuk. Tanpa ini, permintaan pertama selalu jadi outlier dan
  // menggeser p95 untuk alasan yang tidak ada hubungannya dengan beban.
  const hangat = await fetch(alamat, { headers })
  if (!hangat.ok) {
    throw new Error(`permintaan pemanasan gagal (${hangat.status})`)
  }
  await hangat.text()

  const waktu = []
  const galat = []
  let berikut = 0

  const mulai = Date.now()

  async function pekerja() {
    for (;;) {
      const nomor = berikut
      berikut += 1
      if (nomor >= JUMLAH) return

      const t0 = performance.now()
      try {
        const res = await fetch(alamat, { headers })
        await res.text()
        if (!res.ok) galat.push(res.status)
      } catch (e) {
        galat.push(e.message)
      }
      waktu.push(performance.now() - t0)
    }
  }

  await Promise.all(Array.from({ length: BERSAMAAN }, pekerja))

  const detik = (Date.now() - mulai) / 1000
  waktu.sort((a, b) => a - b)

  const p50 = persentil(waktu, 50)
  const p95 = persentil(waktu, 95)
  const p99 = persentil(waktu, 99)

  console.log(`\nGET /tagihan - ${JUMLAH} permintaan, ${BERSAMAAN} bersamaan`)
  console.log(`  selesai   : ${waktu.length} dalam ${detik.toFixed(1)} detik`)
  console.log(`  laju      : ${(waktu.length / detik).toFixed(0)} permintaan/detik`)
  console.log(`  p50 / p95 / p99 : ${p50.toFixed(0)} / ${p95.toFixed(0)} / ${p99.toFixed(0)} ms`)
  console.log(`  galat     : ${galat.length}`)
  console.log(`  ambang p95: ${AMBANG_P95} ms`)

  let lulus = true

  if (galat.length > 0) {
    const ringkas = [...new Set(galat.map(String))].slice(0, 5).join(', ')
    console.log(`\nGAGAL  ${galat.length} permintaan tidak dijawab 2xx: ${ringkas}`)
    lulus = false
  }

  if (p95 > AMBANG_P95) {
    console.log(`\nGAGAL  p95 ${p95.toFixed(0)} ms melewati ambang ${AMBANG_P95} ms`)
    lulus = false
  }

  if (lulus) {
    console.log('\nLULUS  tidak ada galat, p95 di bawah ambang')
  }

  process.exitCode = lulus ? 0 : 1
}

main().catch((e) => {
  console.error('Uji beban gagal:', e.message)
  process.exitCode = 1
})
