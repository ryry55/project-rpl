// Uji balapan R-02 dengan dua permintaan HTTP yang benar-benar bersamaan.
//
// Kenapa berkas terpisah dari tes PHPUnit: di dalam satu proses PHP, dua
// permintaan tidak bisa tiba bersamaan - yang kedua selalu menunggu yang
// pertama selesai. Percobaan memakai dua koneksi basis data di dalam satu tes
// juga tidak membuktikan apa pun: PostgreSQL memblokir penulis kedua dengan
// `55P03` (lock timeout), bukan menolaknya dengan `23505`, sehingga kegagalan
// yang terlihat bukan berasal dari constraint yang sedang diuji.
//
// Skrip ini menembak dua permintaan POST /tagihan/terbitkan pada saat yang sama
// lewat dua koneksi jaringan terpisah, lalu memeriksa bahwa tepat satu yang
// berhasil dan jumlah baris di basis data tetap satu.
//
// Pakai: node docs/uji/uji-balapan-t4.mjs
// Prasyarat: docker compose up, database sudah di-seed (migrate:fresh --seed).

const API = process.env.API_URL || 'http://localhost:8081/api'

const langkah = []
function lapor(nama, lulus, detail = '') {
  langkah.push({ nama, lulus })
  console.log(`${lulus ? 'LULUS' : 'GAGAL'}  ${nama}${detail ? ' - ' + detail : ''}`)
}

async function masuk() {
  const res = await fetch(`${API}/login`, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: 'pemilik@kos.test', password: 'password' }),
  })

  if (!res.ok) throw new Error(`login gagal: ${res.status}`)
  const isi = await res.json()
  return isi.data.token
}

function panggil(token, jalur, metode, isi) {
  const opsi = {
    method: metode,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    },
  }
  if (isi) opsi.body = JSON.stringify(isi)

  return fetch(`${API}${jalur}`, opsi).then(async (res) => ({
    status: res.status,
    isi: await res.json().catch(() => null),
  }))
}

/**
 * Menyatakan kebijakan kos default bila belum ada (T16b).
 *
 * Sejak T16, menerbitkan tagihan menolak berjalan sebelum satuan sewa dan cara
 * jatuh tempo ditetapkan. Skrip ini menerbitkan tagihan lewat API, jadi tanpa
 * langkah ini kegagalannya akan muncul sebagai 422 pada langkah balapan - bukan
 * pada gerbang yang sebenarnya bekerja.
 *
 * Idempoten: yang diperiksa `meta.lengkap` dari GET, bukan hasil POST. Skrip
 * bisa dijalankan berulang tanpa `migrate:fresh` lebih dulu, dan `kebijakan_kos`
 * append-only menolak POST kedua dengan tanggal yang sama.
 *
 * `berlaku_dari` 2026-01-01 karena periode yang diuji di sini 2026.
 */
async function pastikanKebijakan(token) {
  const awal = await panggil(token, '/kebijakan?berlaku_pada=2026-01-01', 'GET')
  if (awal.status !== 200) throw new Error(`GET /kebijakan: ${awal.status}`)
  if (awal.isi?.meta?.lengkap) return 'sudah lengkap'

  // Tiga tipe sejak T17; denda membawa angkanya sendiri (R-03).
  const bawaan = {
    satuan_sewa: { nilai: 'bulanan' },
    jatuh_tempo: { nilai: 'tanggal_masuk' },
    denda: {
      nilai: 'persen_harian',
      persen: 1,
      plafon_persen: 30,
      masa_tenggang: 7,
    },
  }
  const diisi = []

  for (const tipe of awal.isi?.meta?.kurang || []) {
    const isi = bawaan[tipe]
    if (!isi) throw new Error(`tidak ada nilai bawaan untuk tipe ${tipe}`)

    const kirim = await panggil(token, '/kebijakan', 'POST', {
      tipe,
      ...isi,
      berlaku_dari: '2026-01-01',
    })

    // 422 berarti baris untuk tanggal itu sudah ada - sama baiknya dengan 201.
    if (kirim.status !== 201 && kirim.status !== 422) {
      throw new Error(`POST /kebijakan ${tipe}: ${kirim.status}`)
    }
    diisi.push(tipe)
  }

  const akhir = await panggil(token, '/kebijakan?berlaku_pada=2026-01-01', 'GET')
  if (!akhir.isi?.meta?.lengkap) throw new Error('kebijakan masih belum lengkap')

  return `diisi: ${diisi.join(', ')}`
}

async function siapkanSatuKontrak(token) {
  const nomor = `BALAP-${Date.now()}`

  const kamar = await panggil(token, '/kamar', 'POST', { nomor, tipe: 'Uji Balapan' })
  if (kamar.status !== 201) throw new Error(`kamar: ${JSON.stringify(kamar.isi)}`)

  const tarif = await panggil(token, '/tarif', 'POST', {
    kamar_id: kamar.isi.data.id,
    nilai: 1500000,
    berlaku_dari: '2026-01-01',
  })
  if (tarif.status !== 201) throw new Error(`tarif: ${JSON.stringify(tarif.isi)}`)

  const penghuni = await panggil(token, '/penghuni', 'POST', {
    nama: `Penghuni ${nomor}`,
    email: `${nomor.toLowerCase()}@kos.test`,
    password: 'rahasia123',
  })
  if (penghuni.status !== 201) throw new Error(`penghuni: ${JSON.stringify(penghuni.isi)}`)

  const kontrak = await panggil(token, '/kontrak', 'POST', {
    kamar_id: kamar.isi.data.id,
    penghuni_id: penghuni.isi.data.id,
    tgl_masuk: '2026-01-15',
    deposit: 0,
  })
  if (kontrak.status !== 201) throw new Error(`kontrak: ${JSON.stringify(kontrak.isi)}`)

  return { kontrakId: kontrak.isi.data.id, nomor }
}

async function jalan() {
  const token = await masuk()
  lapor('login pemilik berhasil', Boolean(token))

  // T16b: gerbang kebijakan. Periode yang diuji 2026-11, jadi kebijakan harus
  // berlaku sebelum itu.
  const kebijakan = await pastikanKebijakan(token)
  lapor('kebijakan kos siap', true, kebijakan)

  // --- Satu kontrak, dua permintaan serentak ------------------------------
  const { kontrakId, nomor } = await siapkanSatuKontrak(token)
  lapor('kontrak uji siap', Boolean(kontrakId), nomor)

  const periode = '2026-11-01'

  // Inilah inti pengujian: keduanya dilepas tanpa saling menunggu.
  const [a, b] = await Promise.all([
    panggil(token, '/tagihan/terbitkan', 'POST', { periode }),
    panggil(token, '/tagihan/terbitkan', 'POST', { periode }),
  ])

  const status = [a.status, b.status].sort()
  lapor(
    'dua permintaan serentak menghasilkan tepat satu 201 dan satu 409',
    status[0] === 201 && status[1] === 409,
    `status: ${a.status} dan ${b.status}`,
  )

  // Tidak boleh ada yang berakhir 500: kalau constraint bocor sebagai 500,
  // pemilik melihat "server error" padahal sistemnya bekerja benar.
  lapor(
    'tidak ada permintaan yang berakhir 500',
    a.status < 500 && b.status < 500,
    `${a.status}, ${b.status}`,
  )

  // --- Jumlah baris tetap satu --------------------------------------------
  const daftar = await panggil(token, `/tagihan?kontrak_id=${kontrakId}`, 'GET')
  const jumlah = (daftar.isi?.data || []).length

  lapor(
    'R-02: hanya satu tagihan untuk kontrak ini',
    jumlah === 1,
    `${jumlah} tagihan`,
  )

  lapor(
    'periode tagihan yang tersimpan benar',
    daftar.isi?.data?.[0]?.periode === periode,
    String(daftar.isi?.data?.[0]?.periode),
  )

  // --- Penolakan yang menang menyebut alasan yang terbaca -----------------
  const kalah = a.status === 409 ? a : b
  const alasan = (kalah.isi?.data?.rincian || [])
    .map((r) => r.alasan)
    .filter(Boolean)
    .join(' ')

  lapor(
    'yang ditolak menjelaskan alasannya, bukan pesan constraint mentah',
    alasan.length > 0 && !/SQLSTATE|duplicate key/i.test(alasan),
    alasan.slice(0, 120),
  )

  // --- Tiga permintaan serentak: tetap satu -------------------------------
  const nomor2 = `BALAP3-${Date.now()}`
  const kamar2 = await panggil(token, '/kamar', 'POST', { nomor: nomor2, tipe: 'Uji Balapan' })
  await panggil(token, '/tarif', 'POST', {
    kamar_id: kamar2.isi.data.id,
    nilai: 1500000,
    berlaku_dari: '2026-01-01',
  })
  const penghuni2 = await panggil(token, '/penghuni', 'POST', {
    nama: `Penghuni ${nomor2}`,
    email: `${nomor2.toLowerCase()}@kos.test`,
    password: 'rahasia123',
  })
  const kontrak2 = await panggil(token, '/kontrak', 'POST', {
    kamar_id: kamar2.isi.data.id,
    penghuni_id: penghuni2.isi.data.id,
    tgl_masuk: '2026-01-15',
    deposit: 0,
  })

  const tiga = await Promise.all([
    panggil(token, '/tagihan/terbitkan', 'POST', { periode }),
    panggil(token, '/tagihan/terbitkan', 'POST', { periode }),
    panggil(token, '/tagihan/terbitkan', 'POST', { periode }),
  ])

  const sukses = tiga.filter((r) => r.status === 201).length
  const konflik = tiga.filter((r) => r.status === 409).length

  lapor(
    'tiga permintaan serentak: tepat satu yang menerbitkan',
    sukses === 1 && konflik === 2,
    `${sukses} sukses, ${konflik} konflik`,
  )

  const daftar2 = await panggil(token, `/tagihan?kontrak_id=${kontrak2.isi.data.id}`, 'GET')
  lapor(
    'R-02 tetap utuh pada tiga permintaan serentak',
    (daftar2.isi?.data || []).length === 1,
    `${(daftar2.isi?.data || []).length} tagihan`,
  )

  const gagal = langkah.filter((l) => !l.lulus)
  console.log(`\n${langkah.length - gagal.length}/${langkah.length} langkah lulus`)
  if (gagal.length) process.exitCode = 1
}

jalan().catch((e) => {
  console.error('uji balapan gagal:', e.message)
  process.exitCode = 1
})
