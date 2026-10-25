// Uji balapan pembayaran: dua pembayaran serentak yang totalnya melebihi sisa.
//
// Kenapa ini perlu skrip terpisah, bukan tes PHPUnit: di dalam satu proses PHP
// dua permintaan tidak bisa tiba bersamaan, sehingga yang diuji hanya urutan.
// Padahal justru balapan yang berbahaya di sini - pemeriksaan sisa adalah
// baca-lalu-tulis:
//
//   baca sisa 1.500.000 -> nilai "tidak melebihi sisa" -> tulis
//
// Kalau dua permintaan melakukannya bersamaan tanpa kunci baris, keduanya
// membaca sisa yang sama, keduanya menilai aman, dan keduanya menulis. Hasilnya
// Rp 2.000.000 tercatat untuk tagihan Rp 1.500.000 - uang yang tidak pernah
// diterima, tersimpan sebagai angka yang tampak sah.
//
// `PencatatPembayaran` mengunci baris `tagihan` dengan `FOR UPDATE` sebelum
// memeriksa. Skrip ini membuktikan kunci itu benar-benar bekerja: yang kalah
// harus menerima 422, bukan ikut tersimpan.
//
// Pakai: node docs/uji/uji-balapan-t5.mjs
// Prasyarat: docker compose up, database sudah di-seed (migrate:fresh --seed).

const API = process.env.API_URL || 'http://localhost:8081/api'

// Tanggal acuan jauh sebelum jatuh tempo supaya denda nol dan angkanya bulat.
// Kalau denda ikut bermain, tes ini bisa gagal karena alasan yang tidak sedang
// diuji.
const SIMULASI = '2026-11-01'

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
  return (await res.json()).data.token
}

function panggil(token, jalur, metode, isi) {
  const opsi = {
    method: metode,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
      'X-Simulasi-Tanggal': SIMULASI,
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
 * Skrip ini menerbitkan tagihan lewat API, dan sejak T16 penerbitan menolak
 * berjalan sebelum satuan sewa dan cara jatuh tempo ditetapkan. Tanpa langkah
 * ini kegagalannya muncul sebagai 422 pada langkah balapan - bukan pada gerbang
 * yang sebenarnya bekerja.
 *
 * Idempoten: yang diperiksa `meta.lengkap` dari GET, bukan hasil POST, karena
 * `kebijakan_kos` append-only menolak POST kedua dengan tanggal yang sama.
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

    if (kirim.status !== 201 && kirim.status !== 422) {
      throw new Error(`POST /kebijakan ${tipe}: ${kirim.status}`)
    }
    diisi.push(tipe)
  }

  const akhir = await panggil(token, '/kebijakan?berlaku_pada=2026-01-01', 'GET')
  if (!akhir.isi?.meta?.lengkap) throw new Error('kebijakan masih belum lengkap')

  return `diisi: ${diisi.join(', ')}`
}

/** Satu kontrak + satu tagihan terbit, dibuat lewat API seperti pemilik sungguhan. */
async function siapkanTagihan(token) {
  const nomor = `BAYAR-${Date.now()}`

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
  })
  if (penghuni.status !== 201) throw new Error(`penghuni: ${JSON.stringify(penghuni.isi)}`)

  const kontrak = await panggil(token, '/kontrak', 'POST', {
    kamar_id: kamar.isi.data.id,
    penghuni_id: penghuni.isi.data.id,
    tgl_masuk: '2026-01-15',
  })
  if (kontrak.status !== 201) throw new Error(`kontrak: ${JSON.stringify(kontrak.isi)}`)

  const tagihan = await panggil(token, '/tagihan', 'POST', {
    kontrak_id: kontrak.isi.data.id,
    periode: '2026-11-01',
  })
  if (tagihan.status !== 201) throw new Error(`tagihan: ${JSON.stringify(tagihan.isi)}`)

  return { tagihanId: tagihan.isi.data.id, pokok: tagihan.isi.data.pokok }
}

async function jalan() {
  const token = await masuk()
  lapor('login pemilik berhasil', Boolean(token))

  // T16b: gerbang kebijakan. siapkanTagihan() menerbitkan tagihan lewat API.
  const kebijakan = await pastikanKebijakan(token)
  lapor('kebijakan kos siap', true, kebijakan)

  // --- Dua pembayaran serentak, masing-masing 1.000.000 dari pokok 1.500.000 --
  const { tagihanId, pokok } = await siapkanTagihan(token)
  lapor('tagihan uji siap', Boolean(tagihanId), `pokok ${pokok}`)

  const bayar = (jumlah) =>
    panggil(token, `/tagihan/${tagihanId}/pembayaran`, 'POST', {
      jumlah,
      tanggal_pembayaran: SIMULASI,
      // Metode wajib sejak T20 dan sengaja tidak punya nilai bawaan di server.
      // Tanpa baris ini setiap permintaan di bawah akan ditolak 422 karena
      // alasan yang tidak ada hubungannya dengan balapan yang sedang diuji.
      metode: 'tunai',
    })

  // Inilah intinya: keduanya dilepas tanpa saling menunggu.
  const [a, b] = await Promise.all([bayar(1_000_000), bayar(1_000_000)])

  const diterima = [a, b].filter((r) => r.status === 201).length
  const ditolak = [a, b].filter((r) => r.status === 422).length

  lapor(
    'dua pembayaran serentak: tepat satu diterima',
    diterima === 1 && ditolak === 1,
    `status ${a.status} dan ${b.status}`,
  )

  lapor(
    'tidak ada yang berakhir 500',
    a.status < 500 && b.status < 500,
    `${a.status}, ${b.status}`,
  )

  // --- Yang penting: uang tercatat tidak melebihi pokok --------------------
  const detail = await panggil(token, `/tagihan/${tagihanId}`, 'GET')
  const dibayar = detail.isi?.data?.dibayar
  const sisa = detail.isi?.data?.sisa
  const jumlahBaris = (detail.isi?.data?.pembayaran || []).length

  lapor(
    'invarian T5: total tercatat tidak melebihi pokok',
    dibayar <= pokok,
    `dibayar ${dibayar} dari pokok ${pokok}`,
  )

  lapor(
    'hanya satu baris pembayaran tersimpan',
    jumlahBaris === 1,
    `${jumlahBaris} baris`,
  )

  lapor(
    'sisa benar setelah satu pembayaran diterima',
    sisa === pokok - 1_000_000,
    `sisa ${sisa}`,
  )

  const kalah = a.status === 422 ? a : b
  const pesan = kalah.isi?.message || ''
  lapor(
    'yang ditolak menjelaskan sisa dengan angka, bukan pesan mentah',
    /sisa tagihan/i.test(pesan) && !/SQLSTATE|violates/i.test(pesan),
    pesan.slice(0, 130),
  )

  // --- Empat pembayaran serentak: tetap tidak melebihi --------------------
  const kedua = await siapkanTagihan(token)
  const bayar2 = (jumlah) =>
    panggil(token, `/tagihan/${kedua.tagihanId}/pembayaran`, 'POST', {
      jumlah,
      tanggal_pembayaran: SIMULASI,
      metode: 'tunai',
    })

  const empat = await Promise.all([
    bayar2(500_000),
    bayar2(500_000),
    bayar2(500_000),
    bayar2(500_000),
  ])

  const sukses = empat.filter((r) => r.status === 201).length
  const detail2 = await panggil(token, `/tagihan/${kedua.tagihanId}`, 'GET')

  lapor(
    'empat pembayaran serentak: jumlah yang diterima wajar',
    sukses >= 1 && sukses <= 3,
    `${sukses} dari 4 diterima`,
  )

  lapor(
    'invarian T5 tetap utuh pada empat pembayaran serentak',
    (detail2.isi?.data?.dibayar || 0) <= kedua.pokok,
    `dibayar ${detail2.isi?.data?.dibayar} dari pokok ${kedua.pokok}`,
  )

  lapor(
    'status konsisten dengan jumlah yang dibayar',
    ['sebagian', 'lunas'].includes(detail2.isi?.data?.status),
    String(detail2.isi?.data?.status),
  )

  const gagal = langkah.filter((l) => !l.lulus)
  console.log(`\n${langkah.length - gagal.length}/${langkah.length} langkah lulus`)
  if (gagal.length) process.exitCode = 1
}

jalan().catch((e) => {
  console.error('uji balapan gagal:', e.message)
  process.exitCode = 1
})
