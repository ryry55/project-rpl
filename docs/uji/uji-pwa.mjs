// Uji PWA: manifest terbaca, service worker terpasang, dan - yang paling
// penting - TIDAK ADA satu pun respons `/api/` yang masuk cache.
//
// Kenapa asersi cache itu ada.
//
// Cache peramban dipakai bersama oleh semua akun di perangkat yang sama. Bila
// satu respons `/api/tagihan` tersimpan, lalu pemilik keluar dan penghuni masuk
// di perangkat itu, penghuni bisa menerima tagihan milik penghuni sebelumnya.
// Itu pelanggaran langsung R-07 (isolasi data antar-pengguna), dan tidak akan
// terdeteksi uji fungsional mana pun - karena uji fungsional selalu masuk
// sebagai satu peran lalu selesai.
//
// Karena itu skrip ini memeriksa isi `caches.keys()` secara langsung.
//
// Prasyarat: build produksi (service worker tidak didaftarkan saat `npm run
// dev`), aplikasi disajikan di APP, dan Chrome dengan CDP.
//
// Pakai: node docs/uji/uji-pwa.mjs

import { APP, mulaiUji, masukSebagai, evaluasi, tungguSampai, kirim, jalankan } from './_cdp.mjs'

const tidur = (ms) => new Promise((r) => setTimeout(r, ms))

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  try {
    // --- 1. Manifest ------------------------------------------------------
    await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
    await tungguSampai(ws, `document.querySelector('#email')`)

    const tautanManifest = await evaluasi(
      ws,
      `document.querySelector('link[rel="manifest"]')?.getAttribute('href') || null`,
    )
    lapor('index.html menautkan manifest', tautanManifest === '/manifest.webmanifest', String(tautanManifest))

    const isiManifest = await evaluasi(
      ws,
      `fetch('/manifest.webmanifest').then(r => r.ok ? r.json() : null).catch(() => null)`,
    )
    lapor('manifest terbaca sebagai JSON', Boolean(isiManifest), isiManifest ? '' : 'gagal diambil')

    const tema = await evaluasi(
      ws,
      `document.querySelector('meta[name="theme-color"]')?.getAttribute('content') || null`,
    )
    // Dibandingkan dengan manifest, bukan dengan angka yang diketik di sini.
    // Warna tema pernah tertinggal di biru lama setelah palet berubah, dan
    // tidak ada yang gagal: halaman tetap terbuka, hanya bilahnya salah warna.
    lapor(
      'meta theme-color sama dengan theme_color manifest',
      Boolean(tema) && tema === isiManifest?.theme_color,
      `${tema} vs ${isiManifest?.theme_color}`,
    )

    if (isiManifest) {
      lapor('manifest punya name dan short_name', Boolean(isiManifest.name && isiManifest.short_name))
      lapor(
        'manifest memakai display standalone',
        isiManifest.display === 'standalone',
        String(isiManifest.display),
      )
      lapor('manifest memulai dari /', isiManifest.start_url === '/', String(isiManifest.start_url))

      const ikon = isiManifest.icons || []
      const ada192 = ikon.some((i) => (i.sizes || '').includes('192'))
      const ada512 = ikon.some((i) => (i.sizes || '').includes('512'))
      const adaMaskable = ikon.some((i) => (i.purpose || '').includes('maskable'))
      lapor('manifest menyertakan ikon 192 dan 512', ada192 && ada512)
      lapor('manifest menyertakan ikon maskable', adaMaskable)

      // Setiap ikon yang dijanjikan harus benar-benar bisa diambil. Ikon yang
      // 404 membuat PWA ditolak dipasang tanpa pesan yang jelas.
      const semuaAda = await evaluasi(
        ws,
        `Promise.all(${JSON.stringify(ikon.map((i) => i.src))}.map(
           (src) => fetch(src).then((r) => r.ok ? r.headers.get('content-type') : null).catch(() => null)
         ))`,
      )
      const ikonSah = Array.isArray(semuaAda) && semuaAda.every((t) => t && t.startsWith('image/'))
      lapor('semua ikon manifest benar-benar tersaji', ikonSah, JSON.stringify(semuaAda))
    }

    // --- 2. Service worker ------------------------------------------------
    //
    // Keadaan awal dibersihkan lebih dulu, dan bukan karena rapi-rapian:
    // pendaftaran service worker bertahan di profil peramban, begitu pula
    // cache-nya. Tanpa pembersihan, jalan kedua uji ini membaca cache sisa
    // jalan pertama - dan asersi "tidak ada /api di cache" bisa GAGAL karena
    // kebocoran yang sudah lama terjadi, atau LULUS karena cache-nya sudah
    // dibersihkan orang lain. Keduanya bukan jawaban atas pertanyaan yang
    // diajukan.
    await evaluasi(
      ws,
      `(async () => {
         const reg = await navigator.serviceWorker.getRegistrations()
         await Promise.all(reg.map((r) => r.unregister()))
         await caches.keys().then((n) => Promise.all(n.map((k) => caches.delete(k))))
         return true
       })()`,
    )
    await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
    await tungguSampai(ws, `document.querySelector('#email')`)

    // Pendaftaran terjadi pada `load`, jadi diberi waktu.
    const terdaftar = await tungguSampai(
      ws,
      `navigator.serviceWorker.getRegistrations().then((r) => r.length > 0)`,
      15000,
    )
    lapor('service worker terdaftar', terdaftar)

    // Tunggu sampai worker benar-benar aktif DAN mengendalikan halaman ini.
    // `state === 'activated'` saja tidak cukup: worker bisa aktif tetapi belum
    // mengendalikan halaman, dan pemeriksaan cache di bawah lalu tidak menguji
    // jalur `fetch` sama sekali.
    const aktif = await tungguSampai(
      ws,
      `navigator.serviceWorker.getRegistrations().then((r) =>
         r.some((reg) => reg.active && reg.active.state === 'activated'))`,
      20000,
    )
    lapor('service worker aktif', aktif)

    // Muat ulang supaya worker yang baru langsung mengendalikan halaman
    // (klaim hanya berlaku setelah ada navigasi).
    await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
    await tungguSampai(ws, `document.querySelector('#email')`)
    await tidur(800)

    const pengendali = await evaluasi(
      ws,
      `navigator.serviceWorker.controller ? navigator.serviceWorker.controller.scriptURL : null`,
    )
    lapor(
      'service worker mengendalikan halaman',
      Boolean(pengendali) && pengendali.endsWith('/sw.js'),
      String(pengendali),
    )

    const cakupan = await evaluasi(
      ws,
      `navigator.serviceWorker.getRegistrations().then((r) => r[0]?.scope || null)`,
    )
    lapor('cakupan service worker seluruh situs', cakupan === `${APP}/`, String(cakupan))

    // Tidak boleh ada worker yang menunggu: itu berarti versi baru tertahan,
    // dan versi yang sedang berjalan bisa saja yang lama.
    const menunggu = await evaluasi(
      ws,
      `navigator.serviceWorker.getRegistrations().then((r) => r.some((x) => !!x.waiting))`,
    )
    lapor('tidak ada service worker versi lama yang tertahan', menunggu === false)

    // --- 3. Pagar R-07: /api tidak boleh masuk cache ----------------------
    // Masuk dulu supaya ada permintaan /api yang benar-benar terjadi.
    const masuk = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login berhasil, ada permintaan /api', masuk)
    await tidur(1200)

    // Buka halaman yang memuat data, supaya service worker punya banyak
    // kesempatan menangkap respons API - kalau pagarnya bocor, di sinilah
    // bocornya.
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tagihan` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Tagihan')`)
    await tidur(1500)

    // Cache dikosongkan TEPAT SEBELUM pengukuran, lalu satu permintaan `/api`
    // dibuat secara sengaja. Ini yang membuat asersinya bermakna: kalau hanya
    // memeriksa "tidak ada /api di cache", hasilnya bisa lulus karena cache
    // memang belum pernah terisi - bukan karena pagarnya bekerja.
    //
    // Pagar yang tidak bisa dibuktikan menahan bukan pagar.
    const sebelum = await evaluasi(
      ws,
      `(async () => {
         await caches.keys().then((n) => Promise.all(n.map((k) => caches.delete(k))))
         // Muat ulang agar kerangka ter-cache lagi oleh service worker.
         await fetch('/manifest.webmanifest')
         return true
       })()`,
    )
    lapor('cache dikosongkan sebelum pengukuran', sebelum === true)

    // Permintaan API sungguhan, lewat jalur yang sama dengan aplikasi.
    const statusApi = await evaluasi(
      ws,
      `fetch('/api/me', { headers: { Authorization: 'Bearer ' + localStorage.getItem('kos.token') } })
         .then((r) => r.status).catch(() => -1)`,
    )
    lapor('permintaan /api berhasil (ada yang bisa bocor)', statusApi === 200, `status ${statusApi}`)
    await tidur(1200)

    const isiCache = await evaluasi(
      ws,
      `(async () => {
         const nama = await caches.keys()
         const rincian = []
         for (const n of nama) {
           const c = await caches.open(n)
           const kunci = await c.keys()
           rincian.push({ nama: n, jumlah: kunci.length, url: kunci.map((r) => new URL(r.url).pathname) })
         }
         return rincian
       })()`,
    )

    const semuaUrl = (isiCache || []).flatMap((c) => c.url || [])
    const urlApi = semuaUrl.filter((u) => u === '/api' || u.startsWith('/api/'))

    lapor(
      'TIDAK ADA respons /api yang masuk cache (R-07)',
      urlApi.length === 0,
      urlApi.length ? `bocor: ${urlApi.slice(0, 5).join(', ')}` : `${semuaUrl.length} berkas statis di-cache`,
    )

    // --- 4. Kerangka benar-benar tersimpan --------------------------------
    // Diperiksa SETELAH halaman dimuat ulang, bukan setelah cache dikosongkan:
    // pengosongan di atas sengaja menghapus kerangka, jadi memeriksanya di
    // sana hanya akan menguji urutan langkah uji ini sendiri.
    //
    // Asersi ini menjaga asersi R-07 di atas tetap bermakna: kalau tidak ada
    // yang tersimpan sama sekali, "tidak ada /api di cache" lulus dengan mudah
    // - dan tidak membuktikan apa pun tentang pagarnya.
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tagihan` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Tagihan')`)
    await tidur(1500)

    const setelahMuat = await evaluasi(
      ws,
      `(async () => {
         const nama = await caches.keys()
         const url = []
         for (const n of nama) {
           const c = await caches.open(n)
           url.push(...(await c.keys()).map((r) => new URL(r.url).pathname))
         }
         return url
       })()`,
    )
    lapor(
      'kerangka aplikasi tersimpan untuk mode luring',
      Array.isArray(setelahMuat) && setelahMuat.includes('/'),
      `${(setelahMuat || []).length} entri`,
    )
    lapor(
      'aset ber-hash tersimpan',
      Array.isArray(setelahMuat) && setelahMuat.some((u) => u.startsWith('/assets/')),
      `${(setelahMuat || []).filter((u) => u.startsWith('/assets/')).length} berkas`,
    )
    lapor(
      'tetap tidak ada /api di cache setelah pemuatan penuh',
      !(setelahMuat || []).some((u) => u === '/api' || u.startsWith('/api/')),
      (setelahMuat || []).filter((u) => u.startsWith('/api/')).join(', '),
    )

    // --- 4. Token tidak ikut tersimpan di cache ---------------------------
    // Token disimpan di localStorage (bukan cookie), jadi ia tidak pernah
    // melewati `fetch` service worker. Diperiksa tetap, karena bila kelak
    // dipindah ke cookie, cache yang menyimpan respons ber-Authorization akan
    // menjadi kebocoran.
    const tokenDiCache = await evaluasi(
      ws,
      `(async () => {
         const nama = await caches.keys()
         for (const n of nama) {
           const c = await caches.open(n)
           for (const r of await c.keys()) {
             if (/token|auth|sanctum/i.test(r.url)) return r.url
           }
         }
         return null
       })()`,
    )
    lapor('tidak ada URL bertanda token di cache', tokenDiCache === null, String(tokenDiCache))
  } finally {
    await uji.selesai()
  }
})
