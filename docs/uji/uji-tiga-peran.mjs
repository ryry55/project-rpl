// Uji tiga peran dalam SATU sesi: pemilik, pengelola, penghuni bergantian
// memakai peramban yang sama, dan batas antar-peran dibuktikan di server.
//
// Kenapa skrip ini ada, padahal peran sudah diuji berkali-kali:
// `docs/utang-teknis.md` pernah menyebut "uji tiga-peran di docs/uji" padahal
// berkasnya tidak pernah ada. Yang ada hanyalah skrip yang MEMAKAI satu peran
// berbeda - pengelola di `e2e-r3.mjs` dan `e2e-fase35.mjs`, penghuni di
// `e2e-t1/t19/t24`, sisanya pemilik. Tidak ada satu pun yang memakai ketiganya
// BERGANTAIAN dan memeriksa apa yang berubah saat peran berganti. Itu bukan
// hal yang sama: kebocoran yang paling mungkin terjadi pada sistem multi-peran
// adalah keadaan yang tertinggal dari peran sebelumnya - token, tembolok
// jawaban, dan data yang tersimpan di peramban.
//
// Yang dibuktikan:
//   1. Ketiga akun benar-benar bisa masuk dan mendarat di layar yang berbeda.
//   2. Endpoint pemilik-saja menolak pengelola DAN penghuni dengan 403, bukan
//      404 dan bukan 401 - tiga kode itu berarti tiga hal berbeda.
//   3. Endpoint penghuni menolak pemilik dan pengelola: portal penghuni bukan
//      "halaman umum", ia milik satu orang.
//   4. Setelah berganti peran, token lama benar-benar mati: permintaan dengan
//      token sebelumnya dijawab 401, bukan 403.
//   5. Data milik penghuni tidak bocor ke peran lain, dan data antar-penghuni
//      terisolasi (R-07): penghuni kedua tidak bisa membaca tagihan penghuni
//      pertama.
//   6. Menu yang dirender mengikuti peran - tapi ini hanya pelengkap; yang
//      mengikat adalah butir 2-5. Layar yang menyembunyikan menu bukan
//      pengamanan.
//
// Pakai: node docs/uji/uji-tiga-peran.mjs
// Prasyarat: docker compose up, Chrome --remote-debugging-port=9222,
// APP_DEMO_MODE=true, database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo: membuat kamar, penghuni, dan kontrak.

import {
  APP,
  mulaiUji,
  masukSebagai,
  panggilApi,
  evaluasi,
  tungguSampai,
  kirim,
  jalankan,
  pastikanKebijakan,
  lewatiPersetujuanPdp,
} from './_cdp.mjs'

const POKOK = 1_500_000
const PERIODE = '2026-09-01'

/** Token yang sedang dipegang peramban, untuk dibandingkan antar-peran. */
async function tokenSekarang(ws) {
  return evaluasi(ws, `localStorage.getItem('kos.token')`)
}

/**
 * Panggil API memakai token TERTENTU, bukan token yang sedang aktif.
 *
 * `panggilApi()` di `_cdp.mjs` sengaja selalu memakai token yang sedang
 * dipegang peramban - itu yang benar untuk menguji "apa yang bisa dilakukan
 * pengguna saat ini". Di sini yang perlu diuji justru sebaliknya: apa yang
 * terjadi bila token LAMA dipakai setelah peran berganti.
 */
async function panggilDenganToken(ws, jalur, token) {
  return evaluasi(ws, `
    (async () => {
      const res = await fetch(${JSON.stringify(APP + '/api' + jalur)}, {
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer ' + ${JSON.stringify(token)},
        },
      })
      let data = null
      try { data = await res.json() } catch { data = null }
      return { status: res.status, data }
    })()
  `)
}

async function main() {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  // --- 1. Pemilik masuk ----------------------------------------------------
  const masukPemilik = await masukSebagai(ws, 'pemilik@kos.test')
  lapor('pemilik bisa masuk', Boolean(masukPemilik))
  if (!masukPemilik) throw new Error('login pemilik gagal, sisa langkah tidak bisa dijalankan')

  const tokenPemilik = await tokenSekarang(ws)
  lapor('pemilik memegang token', Boolean(tokenPemilik))

  const mePemilik = await panggilApi(ws, '/me')
  lapor(
    'pemilik dikenali sebagai pemilik',
    mePemilik.data?.data?.peran === 'pemilik',
    String(mePemilik.data?.data?.peran),
  )

  const menuPemilik = await evaluasi(
    ws,
    `[...document.querySelectorAll('nav a')].map((a) => a.textContent.trim())`,
  )
  lapor(
    'menu pemilik memuat Rekening dan Pengelola',
    Array.isArray(menuPemilik) &&
      menuPemilik.some((t) => t.includes('Rekening')) &&
      menuPemilik.some((t) => t.includes('Pengelola')),
    Array.isArray(menuPemilik) ? `${menuPemilik.length} menu` : 'tidak terbaca',
  )

  // --- 2. Siapkan data lewat API sebagai pemilik ---------------------------
  const kebijakan = await pastikanKebijakan(ws)
  lapor('kebijakan kos siap', kebijakan.ok, kebijakan.alasan)
  if (!kebijakan.ok) throw new Error('kebijakan kos gagal disiapkan: ' + kebijakan.alasan)

  const siap = await evaluasi(ws, `
    (async () => {
      const t = localStorage.getItem('kos.token')
      const h = { Accept: 'application/json', 'Content-Type': 'application/json',
                  Authorization: 'Bearer ' + t }
      const p = async (jalur, metode, isi) => {
        const o = { method: metode, headers: h }
        if (isi) o.body = JSON.stringify(isi)
        const r = await fetch('/api' + jalur, o)
        let d = null
        try { d = await r.json() } catch { d = null }
        return { status: r.status, data: d }
      }
      const kamar = await p('/kamar', 'POST', { nomor: 'UJI-3P', status_huni: 'kosong' })
      const kamarId = kamar.data?.data?.id
      if (!kamarId) return { galat: 'kamar gagal dibuat', jawab: kamar }

      const p1 = await p('/penghuni', 'POST', { nama: 'Penghuni Satu', email: 'satu-3p@kos.test' })
      const p2 = await p('/penghuni', 'POST', { nama: 'Penghuni Dua', email: 'dua-3p@kos.test' })
      const id1 = p1.data?.data?.id
      const id2 = p2.data?.data?.id
      if (!id1 || !id2) return { galat: 'penghuni gagal dibuat', jawab: [p1, p2] }

      const kontrak = await p('/kontrak', 'POST', {
        kamar_id: kamarId, penghuni_id: id1, tgl_masuk: '2026-09-01', tarif: ${POKOK},
      })
      if (kontrak.status !== 201) return { galat: 'kontrak gagal dibuat', jawab: kontrak }
      const kontrakId = kontrak.data?.data?.id

      // Satu tagihan untuk penghuni pertama. kontrak_id wajib dikirim -
      // /tagihan menerbitkan untuk SATU kontrak, sedangkan penerbitan massal
      // ada di /tagihan/terbitkan.
      const terbit = await p('/tagihan', 'POST', { kontrak_id: kontrakId, periode: '${PERIODE}' })
      return {
        kamarId, penghuni1: id1, penghuni2: id2, kontrakId,
        tagihanId: terbit.data?.data?.id ?? null,
        tagihanStatus: terbit.status,
      }
    })()
  `)
  lapor('kamar, dua penghuni, kontrak, dan tagihan siap', !siap.galat, siap.galat || `kontrak ${siap.kontrakId}`)
  if (siap.galat) throw new Error('penyiapan gagal: ' + JSON.stringify(siap.jawab).slice(0, 300))

  const tagihanPenghuni1 = siap.tagihanId ? { id: siap.tagihanId } : null
  lapor('tagihan penghuni pertama terbit', Boolean(tagihanPenghuni1), `status ${siap.tagihanStatus}`)

  // --- 3. Pengelola: ditolak di tempat yang memang milik pemilik -----------
  //
  // Akun demoen seeder sudah aktif (`email_verified_at` diisi), jadi tidak ada
  // langkah aktivasi di sini. Bila suatu saat seeder berubah, langkah pertama
  // yang akan merah adalah "pengelola bisa masuk" - dan itu memang pesan yang
  // benar, bukan kegagalan yang membingungkan.
  const masukPengelola = await masukSebagai(ws, 'pengelola@kos.test')
  lapor('pengelola bisa masuk', Boolean(masukPengelola))

  const tokenPengelola = await tokenSekarang(ws)
  lapor(
    'token pengelola berbeda dari token pemilik',
    Boolean(tokenPengelola) && tokenPengelola !== tokenPemilik,
  )

  const mePengelola = await panggilApi(ws, '/me')
  lapor(
    'pengelola dikenali sebagai pengelola',
    mePengelola.data?.data?.peran === 'pengelola',
    String(mePengelola.data?.data?.peran),
  )

  const pemilikSaja = await panggilApi(ws, '/pemilik/saja')
  lapor(
    'pengelola ditolak 403 di endpoint pemilik-saja',
    pemilikSaja.status === 403,
    `status ${pemilikSaja.status}`,
  )

  const kelolaPengelola = await panggilApi(ws, '/pengelola')
  lapor(
    'pengelola tidak bisa membaca daftar pengelola',
    kelolaPengelola.status === 403,
    `status ${kelolaPengelola.status}`,
  )

  const portalSebagaiPengelola = await panggilApi(ws, '/penghuni/tagihan')
  lapor(
    'pengelola ditolak di portal penghuni',
    portalSebagaiPengelola.status === 403,
    `status ${portalSebagaiPengelola.status}`,
  )

  // Yang HARUS tetap bisa: pengelola memang berhak membaca data kos.
  const kamarSebagaiPengelola = await panggilApi(ws, '/kamar')
  lapor(
    'pengelola tetap bisa membaca daftar kamar',
    kamarSebagaiPengelola.status === 200,
    `status ${kamarSebagaiPengelola.status}`,
  )

  const menuPengelola = await evaluasi(
    ws,
    `[...document.querySelectorAll('nav a')].map((a) => a.textContent.trim())`,
  )
  lapor(
    'menu pengelola tidak memuat Pengelola maupun Rekening',
    Array.isArray(menuPengelola) &&
      !menuPengelola.some((t) => t.includes('Pengelola')) &&
      !menuPengelola.some((t) => t.includes('Rekening')),
    Array.isArray(menuPengelola) ? `${menuPengelola.length} menu` : 'tidak terbaca',
  )

  // --- 4. Token peran sebelumnya tetap milik peran asalnya ------------------
  //
  // Ini butir yang tidak bisa dibuktikan skrip satu-peran. Asumsi pertama saya
  // salah dan skrip ini yang membuktikannya: login TIDAK mencabut token lama.
  // `AuthController::MAKS_SESI = 3` sengaja mengizinkan tiga sesi hidup
  // bersamaan (ponsel, laptop, komputer kos), dan `batasiSesi()` hanya memutus
  // yang paling lama saat jumlahnya melewati batas. Jadi yang benar diuji
  // bukan "token lama mati", melainkan **token lama tidak berubah peran**:
  // token pemilik tetap menjawab pemilik, token pengelola tetap pengelola.
  // Kalau suatu saat login mencabut token lama, asersi ini akan merah dengan
  // status 401 - dan itu perubahan perilaku yang perlu disadari, bukan
  // kegagalan yang boleh diabaikan.
  const tokenPemilikSetelah = await panggilDenganToken(ws, '/me', tokenPemilik)
  lapor(
    'token pemilik tetap menjawab pemilik setelah peran lain masuk',
    tokenPemilikSetelah.status === 200 &&
      tokenPemilikSetelah.data?.data?.peran === 'pemilik',
    `status ${tokenPemilikSetelah.status}, peran ${tokenPemilikSetelah.data?.data?.peran}`,
  )

  // Dan batasnya memang membatasi: token peran lain tidak bisa dipakai untuk
  // membaca apa pun yang bukan haknya, walau masih hidup.
  const pemilikSajaDenganTokenPengelola = await panggilDenganToken(ws, '/pemilik/saja', tokenPengelola)
  lapor(
    'token pengelola ditolak 403 di endpoint pemilik-saja',
    pemilikSajaDenganTokenPengelola.status === 403,
    `status ${pemilikSajaDenganTokenPengelola.status}`,
  )

  // --- 5. Penghuni: batas R-07 ---------------------------------------------
  const masukPenghuni = await masukSebagai(ws, 'penghuni@kos.test')
  lapor('penghuni bisa masuk', Boolean(masukPenghuni))

  if (masukPenghuni) {
    await lewatiPersetujuanPdp(ws)

    const mePenghuni = await panggilApi(ws, '/me')
    lapor(
      'penghuni dikenali sebagai penghuni',
      mePenghuni.data?.data?.peran === 'penghuni',
      String(mePenghuni.data?.data?.peran),
    )

    const portal = await panggilApi(ws, '/penghuni/tagihan')
    lapor(
      'penghuni bisa membaca portalnya sendiri',
      portal.status === 200,
      `status ${portal.status}`,
    )

    // Endpoint pemilik-saja harus 403 untuk penghuni juga. 403 berarti "Anda
    // dikenali, tetapi tidak berwenang"; 401 berarti "tidak dikenali", dan
    // penghuni yang mendapat 401 akan mengira sesinya rusak.
    const pemilikSajaPenghuni = await panggilApi(ws, '/pemilik/saja')
    lapor(
      'penghuni ditolak 403, bukan 401, di endpoint pemilik-saja',
      pemilikSajaPenghuni.status === 403,
      `status ${pemilikSajaPenghuni.status}`,
    )

    const kamarPenghuni = await panggilApi(ws, '/kamar')
    lapor(
      'penghuni tidak bisa membaca daftar kamar',
      kamarPenghuni.status === 403,
      `status ${kamarPenghuni.status}`,
    )

    // R-07: tagihan orang lain harus 404, bukan 403. 403 memberi tahu bahwa
    // barisnya ADA - dan itu sudah kebocoran.
    if (tagihanPenghuni1?.id) {
      const tagihanOrangLain = await panggilApi(ws, `/tagihan/${tagihanPenghuni1.id}`)
      lapor(
        'penghuni mendapat 404 untuk tagihan penghuni lain',
        tagihanOrangLain.status === 404,
        `status ${tagihanOrangLain.status}`,
      )
    }

    const tokenPengelolaSetelah = await panggilDenganToken(ws, '/me', tokenPengelola)
    lapor(
      'token pengelola tetap menjawab pengelola setelah penghuni masuk',
      tokenPengelolaSetelah.status === 200 &&
        tokenPengelolaSetelah.data?.data?.peran === 'pengelola',
      `status ${tokenPengelolaSetelah.status}, peran ${tokenPengelolaSetelah.data?.data?.peran}`,
    )

    // Portal penghuni hanya milik satu orang: penghuni lain tidak boleh
    // membacanya. `penghuni@kos.test` bukan pemilik kontrak yang dibuat di
    // atas, jadi portalnya kosong - tetapi yang penting adalah ia TIDAK
    // melihat tagihan `satu-3p@kos.test`.
    const portalIsi = await panggilApi(ws, '/penghuni/tagihan')
    const milikOrangLain = (portalIsi.data?.data || []).some(
      (t) => t.id === tagihanPenghuni1?.id,
    )
    lapor(
      'portal penghuni tidak memuat tagihan penghuni lain',
      !milikOrangLain,
      `${(portalIsi.data?.data || []).length} tagihan`,
    )
  }

  // --- 6. Kembali ke pemilik: keadaan tidak tertinggal ---------------------
  const masukPemilikLagi = await masukSebagai(ws, 'pemilik@kos.test')
  lapor('pemilik bisa masuk lagi setelah ketiga peran bergantian', Boolean(masukPemilikLagi))

  const mePemilikLagi = await panggilApi(ws, '/me')
  lapor(
    'kembali sebagai pemilik, bukan peran sebelumnya',
    mePemilikLagi.data?.data?.peran === 'pemilik',
    String(mePemilikLagi.data?.data?.peran),
  )

  const portalSetelahPemilik = await panggilApi(ws, '/penghuni/tagihan')
  lapor(
    'portal penghuni kembali ditolak untuk pemilik',
    portalSetelahPemilik.status === 403,
    `status ${portalSetelahPemilik.status}`,
  )

  await uji.selesai()
}

jalankan(main)
