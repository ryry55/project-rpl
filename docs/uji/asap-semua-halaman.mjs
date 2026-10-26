// Asap menyeluruh: buka SETIAP halaman sebagai pemilik dan sebagai penghuni,
// lalu laporkan galat konsol, permintaan yang gagal, dan halaman yang kosong.
//
// Ini bukan pengganti E2E per tiket. E2E membuktikan satu alur bisnis benar;
// skrip ini membuktikan tidak ada halaman yang diam-diam rusak - kelas
// kesalahan yang tidak tertangkap tes karena tes tidak pernah membukanya.
//
// Pakai: node docs/uji/asap-semua-halaman.mjs
// Prasyarat: docker compose up, Chrome --remote-debugging-port=9222,
// database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo: ia membuat satu kamar, satu penghuni, satu
// kontrak, dan satu tagihan supaya halaman Tagihan, Tunggakan, dan audit tidak
// kosong.

import {
  APP,
  API,
  kirim,
  evaluasi,
  tungguSampai,
  masukSebagai,
  mulaiUji,
  jalankan,
  aktifkanAkun,
  lewatiPersetujuanPdp,
} from './_cdp.mjs'

/**
 * Sandi yang ditetapkan sendiri oleh penghuni uji lewat tautan aktivasi.
 *
 * Sejak T27 pemilik tidak mengetikkan sandi siapa pun, jadi skrip ini harus
 * membuka tautan yang dikirim ke email penghuni - persis seperti penghuni
 * sungguhan. Tanpa itu langkah "login penghuni" di bawah gagal 403.
 */
const SANDI_PENGHUNI = 'sandi-asap-penghuni'

/** Batas bawah waktu surat; lihat alasannya di e2e-t24.mjs. */
const SEJAK = new Date()

// --- Perkakas khusus berkas ini --------------------------------------------
//
// Perekam galat dan pengukur halaman hanya dipakai di sini, jadi tinggal di
// berkas ini. Yang dipakai bersama (koneksi CDP, login, pelapor, pembersihan
// tab) datang dari `_cdp.mjs`.

/**
 * Memasang perekam galat yang BERTAHAN melewati navigasi.
 *
 * Perekam tidak boleh dipasang lewat `evaluasi`: `Page.navigate` memuat ulang
 * dokumen, sehingga `window.__rekam` yang ditanam sesudah halaman terbuka ikut
 * hilang, dan pembacaan berikutnya selalu melihat objek kosong. Akibatnya
 * "tidak ada galat konsol" lulus tanpa pernah memeriksa apa pun.
 *
 * `Page.addScriptToEvaluateOnNewDocument` menyuntikkan skrip sebelum skrip
 * aplikasi berjalan di setiap dokumen baru, jadi galat saat Vue memuat ikut
 * tercatat. Ini sudah diuji: perekam bertahan pada navigasi kedua dan
 * menangkap galat yang dilempar dari `setTimeout`.
 */
async function pasangPerekam(ws) {
  await kirim(ws, 'Page.enable')
  await kirim(ws, 'Page.addScriptToEvaluateOnNewDocument', {
    source: `
      window.__rekam = { galat: [], gagal: [] }
      window.addEventListener('error', (e) => {
        window.__rekam.galat.push(String(e.message || e.error || 'error'))
      })
      window.addEventListener('unhandledrejection', (e) => {
        window.__rekam.galat.push('promise: ' + String(e.reason))
      })
      const asli = window.fetch
      window.fetch = async (...a) => {
        const res = await asli(...a)
        if (res.status >= 400) {
          window.__rekam.gagal.push(res.status + ' ' + String(a[0]).replace(location.origin, ''))
        }
        return res
      }
    `,
  })
}

async function bersihkanRekaman(ws) {
  await evaluasi(ws, `(() => { if (window.__rekam) window.__rekam = { galat: [], gagal: [] }; return true })()`)
}

async function bacaRekaman(ws) {
  return evaluasi(ws, `(() => window.__rekam || { galat: [], gagal: [] })()`)
}

/** Apakah halaman benar-benar menggambar sesuatu, bukan kerangka kosong. */
async function ukurHalaman(ws) {
  return evaluasi(ws, `
    (() => {
      const teks = (document.body?.innerText || '').replace(/\\s+/g, ' ').trim()
      return {
        judul: document.querySelector('h1')?.textContent?.trim() || '',
        panjangTeks: teks.length,
        jumlahBarisTabel: document.querySelectorAll('tbody tr').length,
        jumlahTombol: document.querySelectorAll('button').length,
      }
    })()
  `)
}

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  await pasangPerekam(ws)

  // Kontrol negatif: buktikan perekam memang menangkap galat. Tanpa langkah
  // ini, perekam yang mati diam-diam akan membuat setiap halaman lulus dengan
  // "tidak ada galat konsol" padahal tidak ada yang diperiksa.
  await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
  await tungguSampai(ws, `document.querySelector('#email')`)
  await evaluasi(ws, `(() => { setTimeout(() => { throw new Error('kontrol-negatif') }, 0); return true })()`)
  await new Promise((r) => setTimeout(r, 600))
  const kontrol = await evaluasi(ws, `(() => (window.__rekam?.galat || []).join(' | '))()`)
  lapor('perekam galat menangkap galat sengaja', /kontrol-negatif/.test(kontrol), kontrol || '(tidak menangkap apa pun)')

  // --- Pemilik -------------------------------------------------------------
  const masuk = await masukSebagai(ws, 'pemilik@kos.test')
  lapor('login pemilik', masuk, await evaluasi(ws, 'location.pathname'))
  if (!masuk) throw new Error('login pemilik gagal')

  // Satu kontrak supaya halaman Tagihan, Tunggakan, dan audit tidak kosong.
  //
  // Skrip ini harus bisa dijalankan berulang tanpa `migrate:fresh` lebih dulu:
  // setiap sumber daya dicari dulu, baru dibuat kalau belum ada. Run kedua yang
  // menabrak "nomor kamar sudah dipakai" adalah kegagalan yang membingungkan
  // dan tidak ada hubungannya dengan aplikasi.
  const siap = await evaluasi(ws, `
    (async () => {
      const h = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
      }
      const p = async (jalur, metode, isi) => {
        const o = { method: metode, headers: h }
        if (isi) o.body = JSON.stringify(isi)
        const r = await fetch('${API}' + jalur, o)
        const t = await r.text()
        let d = null
        try { d = t ? JSON.parse(t) : null } catch { d = null }
        return { status: r.status, data: d }
      }
      const daftar = async (jalur) => {
        const r = await p(jalur, 'GET')
        return Array.isArray(r.data?.data) ? r.data.data : []
      }

      // Kamar: cari berdasarkan nomor, buat kalau tidak ada.
      let kamar = (await daftar('/kamar')).find((k) => k.nomor === 'ASAP-01')
      if (!kamar) {
        const buat = await p('/kamar', 'POST', { nomor: 'ASAP-01', tipe: 'Uji asap' })
        if (buat.status !== 201) return 'kamar: ' + JSON.stringify(buat.data)
        kamar = buat.data.data
      }

      // Tarif: hanya kalau kamar belum punya tarif.
      const tarifAda = await daftar('/tarif?kamar_id=' + kamar.id)
      if (tarifAda.length === 0) {
        const buat = await p('/tarif', 'POST', {
          kamar_id: kamar.id, nilai: 1500000, berlaku_dari: '2026-01-01',
        })
        if (buat.status !== 201) return 'tarif: ' + JSON.stringify(buat.data)
      }

      // Penghuni: cari berdasarkan email, buat kalau tidak ada.
      const semuaPenghuni = await daftar('/penghuni')
      let penghuni = semuaPenghuni.find((x) => x.email === 'asap@kos.test')
      if (!penghuni) {
        // Tanpa kata sandi (T27). Akun dibuat, tautan aktivasi dikirim, dan
        // skrip ini mengaktifkannya setelah login pemilik selesai - lihat
        // fungsi aktifkanAkun di bawah.
        const buat = await p('/penghuni', 'POST', {
          nama: 'Asap Satu', email: 'asap@kos.test',
        })
        if (buat.status !== 201) return 'penghuni: ' + JSON.stringify(buat.data)
        penghuni = buat.data.data
      }

      // Kontrak: '/kontrak' tidak menerima filter 'kamar_id', jadi disaring di
      // sisi klien memakai 'kamar_id' dari resource.
      const semuaKontrak = await daftar('/kontrak')
      const kontrakKamar = semuaKontrak.filter((x) => x.kamar_id === kamar.id)
      if (kontrakKamar.length === 0) {
        const buat = await p('/kontrak', 'POST', {
          kamar_id: kamar.id, penghuni_id: penghuni.id,
          tgl_masuk: '2026-01-15',
        })
        if (buat.status !== 201) return 'kontrak: ' + JSON.stringify(buat.data)
      }

      // Kebijakan kos: sejak T16, penerbitan tagihan ditolak sebelum satuan sewa
      // dan cara jatuh tempo ditetapkan. Tanpa langkah ini langkah tagihan di
      // bawah gagal 422 - dan pesannya akan terbaca seperti kerusakan halaman,
      // padahal gerbangnya memang bekerja.
      //
      // Idempoten dengan pola yang sama: baca dulu apa yang kurang, tulis hanya
      // yang kurang. kebijakan_kos bersifat append-only sehingga POST kedua
      // bertanggal sama ditolak, jadi 422 diperlakukan sebagai "sudah ada".
      const kebijakan = await p('/kebijakan?berlaku_pada=2026-01-01', 'GET')
      if (kebijakan.status !== 200) {
        return 'kebijakan: ' + JSON.stringify(kebijakan.data)
      }
      if (!kebijakan.data?.meta?.lengkap) {
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
        for (const tipe of kebijakan.data?.meta?.kurang || []) {
          const isi = bawaan[tipe]
          if (!isi) return 'kebijakan: tidak ada nilai bawaan untuk ' + tipe
          const tulis = await p('/kebijakan', 'POST', {
            tipe, ...isi, berlaku_dari: '2026-01-01',
          })
          if (tulis.status !== 201 && tulis.status !== 422) {
            return 'kebijakan ' + tipe + ': ' + JSON.stringify(tulis.data)
          }
        }
        const ulang = await p('/kebijakan?berlaku_pada=2026-01-01', 'GET')
        if (!ulang.data?.meta?.lengkap) return 'kebijakan masih belum lengkap'
      }

      // Tagihan: terbitkan untuk periode uji, dan terima 409 sebagai "sudah ada".
      const kontrak = (await daftar('/kontrak')).filter((x) => x.kamar_id === kamar.id)[0]
      if (!kontrak) return 'kontrak tidak ditemukan setelah dibuat'
      const buat = await p('/tagihan', 'POST', {
        kontrak_id: kontrak.id, periode: '2026-11-01',
      })
      if (buat.status !== 201 && buat.status !== 409) {
        return 'tagihan: ' + JSON.stringify(buat.data)
      }

      // Rekening tujuan pembayaran (REV-UI-05): tanpa satu baris pun, kartu
      // "Cara membayar" di portal penghuni tidak pernah dirender, dan cabang
      // yang tidak pernah dirender adalah cabang yang tidak pernah diuji.
      const rekeningAda = await daftar('/rekening')
      if (rekeningAda.length === 0) {
        const tulis = await p('/rekening', 'POST', {
          jenis: 'transfer', nama: 'BCA', nomor: '1234567890',
          atas_nama: 'Pemilik Kos', catatan: 'Konfirmasi lewat WhatsApp.',
        })
        if (tulis.status !== 201) return 'rekening: ' + JSON.stringify(tulis.data)
      }
      return 'ok'
    })()
  `)
  lapor('data uji disiapkan', siap === 'ok', siap)
  if (siap !== 'ok') throw new Error('data uji gagal disiapkan: ' + siap)

  /**
   * Membuka satu halaman dan menilai keadaannya.
   *
   * Menunggu sampai `#app` punya anak, bukan menunggu jeda tetap: build baru
   * dengan cache dingin butuh waktu lebih lama, dan halaman yang belum sempat
   * dirender terlihat sama persis dengan halaman yang rusak. Jeda tetap di
   * sini sempat menghasilkan kegagalan palsu pada halaman 404.
   */
  async function kunjungi(jalur, nama) {
    await bersihkanRekaman(ws)
    await kirim(ws, 'Page.navigate', { url: `${APP}${jalur}` })
    await tungguSampai(ws, `document.querySelector('#app')?.children.length > 0`, 15000)

    const ukuran = await ukurHalaman(ws)
    const rekam = await bacaRekaman(ws)

    const galatNyata = rekam.galat.filter((g) => !/ResizeObserver/.test(g))
    // 401/403/404 sah untuk halaman yang memang memfilternya; yang tidak sah
    // adalah 500 dan permintaan yang gagal karena galat JavaScript.
    const gagalNyata = rekam.gagal.filter((g) => /^5\d\d/.test(g))

    lapor(
      `${nama} (${jalur})`,
      galatNyata.length === 0 && gagalNyata.length === 0 && ukuran.panjangTeks > 60,
      [
        ukuran.judul ? `"${ukuran.judul}"` : 'tanpa h1',
        `${ukuran.panjangTeks} huruf`,
        `${ukuran.jumlahBarisTabel} baris tabel`,
        galatNyata.length ? `galat konsol: ${galatNyata.join(' | ')}` : '',
        gagalNyata.length ? `HTTP 5xx: ${gagalNyata.join(' | ')}` : '',
      ]
        .filter(Boolean)
        .join(' | '),
    )
  }

  const halamanPemilik = [
    ['/pemilik/kamar', 'Kamar'],
    ['/pemilik/penghuni', 'Penghuni'],
    ['/pemilik/kontrak', 'Kontrak'],
    ['/pemilik/tagihan', 'Tagihan'],
    // Rekap (T21) ikut disapu: ia halaman baru, dan halaman baru adalah tempat
    // kesalahan yang paling sering lolos.
    ['/pemilik/rekap', 'Rekap pendapatan'],
    ['/pemilik/tunggakan', 'Tunggakan'],
    ['/pemilik/kebijakan', 'Kebijakan'],
    // Rekening (REV-UI-05) ikut disapu: halaman baru, dan halaman baru adalah
    // tempat kesalahan yang paling sering lolos.
    ['/pemilik/rekening', 'Rekening tujuan pembayaran'],
    ['/pemilik/fasilitas', 'Fasilitas'],
    ['/pemilik/aturan', 'Aturan kos'],
    ['/pemilik/audit', 'Jejak audit'],
    // Halaman akun pengelola (T24). Ikut disapu karena ia halaman baru, dan
    // halaman baru adalah tempat kesalahan yang paling sering lolos - tidak
    // ada tes per tiket lain yang membukanya.
    ['/pemilik/pengelola', 'Pengelola'],
    // Persetujuan dan Pengaturan notifikasi (T25/T26) ikut disapu dengan alasan
    // yang sama. Persetujuan khususnya: halaman itu kosong pada database yang
    // belum punya usulan, dan halaman kosong adalah tempat kesalahan render
    // yang paling mudah lolos - tidak ada baris untuk memicu satu pun cabang.
    ['/pemilik/persetujuan', 'Persetujuan'],
    ['/pemilik/pengaturan-notifikasi', 'Pengaturan notifikasi'],
    // Bantuan (Fase 3.5) ikut disapu. Ia satu-satunya halaman yang isinya
    // seluruhnya teks dan tautan - tidak ada tabel, tidak ada formulir - jadi
    // kesalahan render di sini tidak akan tertangkap tes lain mana pun.
    ['/pemilik/bantuan', 'Bantuan'],
  ]
  for (const [jalur, nama] of halamanPemilik) {
    await kunjungi(jalur, nama)
  }

  // --- Penghuni ------------------------------------------------------------
  //
  // Akunnya belum aktif: sandinya tidak diketahui siapa pun sampai tautan
  // aktivasi dibuka. Langkah ini sekaligus menyapu dua halaman baru T27/T28
  // yang hanya bisa dibuka orang yang sudah masuk.
  const aktifP = await aktifkanAkun(ws, 'asap@kos.test', SANDI_PENGHUNI, SEJAK)
  lapor('penghuni uji mengaktifkan akunnya lewat tautan email', aktifP)
  if (!aktifP) throw new Error('aktivasi penghuni gagal')

  const masukP = await masukSebagai(ws, 'asap@kos.test', SANDI_PENGHUNI)
  lapor('login penghuni', masukP, await evaluasi(ws, 'location.pathname'))
  if (!masukP) throw new Error('login penghuni gagal')

  // Gerbang persetujuan PDP berdiri sekali untuk tiap akun baru (T28). Skrip
  // ini menyapu seluruh halaman, bukan menguji gerbangnya.
  const lolosPdp = await lewatiPersetujuanPdp(ws)
  lapor('gerbang persetujuan PDP dilewati', lolosPdp, await evaluasi(ws, 'location.pathname'))

  // Perekam sudah terpasang sekali di awal dan bertahan melewati navigasi,
  // jadi tidak dipasang lagi di sini.
  await kunjungi('/penghuni', 'Portal penghuni')

  // --- Rute yang tidak ada -------------------------------------------------
  await kunjungi('/tidak-ada-halaman-ini', 'Halaman 404')
  // Ambangnya lebih longgar: halaman 404 memang pendek, tetapi tetap harus
  // menjelaskan sesuatu dan menyediakan jalan pulang.
  const nyasar = await evaluasi(ws, `
    ({
      panjangTeks: (document.body?.innerText || '').trim().length,
      adaTautanPulang: Boolean(document.querySelector('a[href]')),
    })
  `)
  lapor(
    'halaman 404 menjelaskan dan menyediakan jalan pulang',
    nyasar.panjangTeks > 20 && nyasar.adaTautanPulang,
    `${nyasar.panjangTeks} huruf | tautan pulang: ${nyasar.adaTautanPulang}`,
  )

  await uji.selesai()
})
