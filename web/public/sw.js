/* Service worker Kos-Kosan.
 *
 * Tugasnya sempit dan disengaja: membuat aplikasi bisa dipasang ke layar utama
 * dan tetap terbuka saat jaringan putus. Ia TIDAK menyentuh data.
 *
 * === ATURAN YANG TIDAK BOLEH DILANGGAR ===
 *
 * Permintaan ke `/api/` tidak pernah disimpan, tidak pernah dibaca dari cache,
 * dan tidak pernah dijawab oleh berkas ini. Alasannya bukan soal kesegaran
 * data, tetapi soal kebocoran: cache peramban dipakai bersama oleh semua akun
 * di perangkat yang sama. Bila satu respons `/api/tagihan` tersimpan, lalu
 * pemilik keluar dan penghuni masuk di perangkat itu, penghuni bisa menerima
 * tagihan milik penghuni sebelumnya - pelanggaran langsung R-07 (isolasi data
 * antar-pengguna). Karena itu `/api/` dilewatkan sepenuhnya, bukan
 * "di-cache dengan hati-hati".
 *
 * Yang disimpan hanya: kerangka aplikasi (`index.html`), manifest, ikon, dan
 * berkas ber-hash di `/assets/`. Tidak ada satu pun yang berisi data pengguna.
 */

// Naikkan angka ini setiap kali strategi cache berubah. Nama cache memuat
// versinya, sehingga versi lama dihapus di `activate` dan tidak menumpuk.
const VERSI = 'v1'
const CACHE_KERANGKA = `kos-kerangka-${VERSI}`
const CACHE_ASET = `kos-aset-${VERSI}`

// Kerangka yang disimpan saat pemasangan. Sengaja pendek: setiap berkas di sini
// menunda pemasangan, dan yang benar-benar dibutuhkan saat offline hanyalah
// halaman kosong yang bisa diisi router.
const KERANGKA = [
  '/',
  '/manifest.webmanifest',
  '/ikon/ikon-192.png',
  '/ikon/ikon-512.png',
  '/ikon/apple-touch-icon.png',
]

self.addEventListener('install', (peristiwa) => {
  peristiwa.waitUntil(
    (async () => {
      const cache = await caches.open(CACHE_KERANGKA)
      // `addAll` gagal seluruhnya bila satu berkas gagal. Itu yang diinginkan:
      // kerangka separuh jadi lebih buruk daripada tidak ada, karena aplikasi
      // akan terbuka lalu berhenti di tengah tanpa penjelasan.
      await cache.addAll(KERANGKA)

      // Tanpa ini, versi baru menunggu sampai SEMUA tab lama ditutup. Pada
      // aplikasi yang dipasang ke layar utama, tab lama itu praktis tidak
      // pernah tertutup - jadi perbaikan pada service worker sendiri tidak
      // pernah sampai, termasuk perbaikan pada pagar R-07 di bawah. Itu bukan
      // kemungkinan teoretis: sudah terjadi sekali, dan versi tanpa pagar
      // sempat menyimpan respons `/api/` ke cache.
      await self.skipWaiting()
    })(),
  )
})

self.addEventListener('activate', (peristiwa) => {
  peristiwa.waitUntil(
    (async () => {
      const nama = await caches.keys()
      await Promise.all(
        nama
          .filter((n) => n.startsWith('kos-') && n !== CACHE_KERANGKA && n !== CACHE_ASET)
          .map((n) => caches.delete(n)),
      )
      // Ambil kendali atas tab yang sudah terbuka supaya versi baru langsung
      // berlaku, tanpa menunggu semua tab lama ditutup.
      await self.clients.claim()
    })(),
  )
})

self.addEventListener('fetch', (peristiwa) => {
  const permintaan = peristiwa.request
  const alamat = new URL(permintaan.url)

  // Hanya GET. POST/PUT/DELETE tidak punya arti untuk cache dan tidak boleh
  // diulang diam-diam.
  if (permintaan.method !== 'GET') return

  // Hanya origin sendiri. Permintaan ke host lain (mis. gateway pembayaran)
  // dibiarkan apa adanya.
  if (alamat.origin !== self.location.origin) return

  // --- Pagar R-07 -----------------------------------------------------------
  // Dilewatkan tanpa `respondWith`, jadi peramban menanganinya langsung ke
  // jaringan seperti tidak ada service worker sama sekali.
  if (alamat.pathname === '/api' || alamat.pathname.startsWith('/api/')) return

  // --- Navigasi: jaringan dulu ---------------------------------------------
  // `index.html` menyebut nama berkas ber-hash. Bila versi lama disajikan dari
  // cache, ia menunjuk berkas yang sudah tidak ada setelah deploy berikutnya -
  // dan aplikasi gagal dimuat dengan galat 404 yang membingungkan. Karena itu
  // navigasi selalu mencoba jaringan dulu, dan cache hanya dipakai saat offline.
  if (permintaan.mode === 'navigate') {
    peristiwa.respondWith(
      (async () => {
        try {
          const segar = await fetch(permintaan)
          const cache = await caches.open(CACHE_KERANGKA)
          // Simpan salinan terbaru kerangka untuk dipakai saat offline nanti.
          cache.put('/', segar.clone())
          return segar
        } catch {
          const cache = await caches.open(CACHE_KERANGKA)
          const tersimpan = await cache.match('/')
          if (tersimpan) return tersimpan
          // Belum pernah online sama sekali: tidak ada yang bisa disajikan.
          return new Response(
            '<!doctype html><meta charset="utf-8"><title>Luring</title>' +
              '<body style="font:16px system-ui;padding:2rem">' +
              '<h1>Tidak ada koneksi</h1>' +
              '<p>Aplikasi belum pernah dimuat di perangkat ini, jadi belum ada ' +
              'salinan yang bisa dibuka. Sambungkan internet lalu coba lagi.</p>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } },
          )
        }
      })(),
    )
    return
  }

  // --- Aset ber-hash: cache dulu -------------------------------------------
  // Nama berkas di `/assets/` memuat hash isinya (mis. `index-a1b2c3d4.js`),
  // jadi isi satu nama tidak pernah berubah. Menyajikannya dari cache selalu
  // benar, dan itulah yang membuat pembukaan kedua terasa seketika.
  if (alamat.pathname.startsWith('/assets/')) {
    peristiwa.respondWith(
      (async () => {
        const cache = await caches.open(CACHE_ASET)
        const tersimpan = await cache.match(permintaan)
        if (tersimpan) return tersimpan
        const segar = await fetch(permintaan)
        if (segar.ok) cache.put(permintaan, segar.clone())
        return segar
      })(),
    )
    return
  }

  // --- Sisanya (ikon, manifest): jaringan dulu, cache sebagai cadangan ------
  peristiwa.respondWith(
    (async () => {
      try {
        const segar = await fetch(permintaan)
        if (segar.ok) {
          const cache = await caches.open(CACHE_KERANGKA)
          cache.put(permintaan, segar.clone())
        }
        return segar
      } catch {
        const cache = await caches.open(CACHE_KERANGKA)
        const tersimpan = await cache.match(permintaan)
        if (tersimpan) return tersimpan
        return new Response('', { status: 504, statusText: 'Luring' })
      }
    })(),
  )
})
