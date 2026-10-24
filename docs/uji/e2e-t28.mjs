// Verifikasi end-to-end T28 lewat browser sungguhan.
//
// Yang dibuktikan: tiga hak UU 27/2022 benar-benar bekerja di layar, dan yang
// paling penting - **penghapusan adalah anonimisasi, bukan penghapusan baris**.
// Catatan keuangan harus tetap utuh sesudahnya; identitasnya yang dilepas.
//
// Empat jalur yang diuji, dan kenapa masing-masing perlu:
//
//   1. **Persetujuan tercatat, bukan hanya ditampilkan.** Gerbangnya muncul
//      untuk akun baru, dan sesudah disetujui ia tidak muncul lagi. Kalau hanya
//      tampilannya yang diuji, aplikasi yang tidak pernah menyimpan apa pun
//      akan lulus.
//
//   2. **Persetujuan bisa DITOLAK.** Persetujuan yang tidak bisa ditolak bukan
//      persetujuan, melainkan syarat memakai layanan - dan UU 27/2022
//      membedakan keduanya. Yang terjadi saat ditolak: sesi berakhir.
//
//   3. **Hak akses: pengguna melihat datanya sendiri.** Termasuk jejak
//      tindakannya sendiri - dan HANYA tindakannya sendiri, bukan tindakan
//      pemilik kos atas data kos.
//
//   4. **Hak penghapusan: identitas lepas, angka tetap.** Diperiksa dari dua
//      sisi sekaligus: penghuni itu hilang dari daftar sebagai orang, tetapi
//      tagihannya masih ada di rekap pemilik dengan nilai yang sama persis.
//      Uji yang hanya memeriksa satu sisi akan lulus pada aplikasi yang
//      menghapus seluruh riwayat keuangan.
//
// Pakai: node docs/uji/e2e-t28.mjs
// Prasyarat: docker compose up sudah jalan (termasuk mailpit), Chrome
// --remote-debugging-port=9222, dan database sudah di-seed
// (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo: membuat kamar, dua penghuni, satu kontrak,
// dan satu tagihan.

import {
  APP,
  mulaiUji,
  masukSebagai,
  panggilApi,
  evaluasi,
  tungguSampai,
  kirim,
  tangkapLayar,
  jalankan,
  pastikanKebijakan,
  aktifkanAkun,
} from './_cdp.mjs'

const EMAIL_HAPUS = 'uji-t28-hapus@kos.test'
const EMAIL_TOLAK = 'uji-t28-tolak@kos.test'
const NAMA_HAPUS = 'Uji T28 Hapus'
const SANDI = 'sandi-t28-penghuni'
const POKOK = 1500000

/** Batas bawah waktu surat; lihat alasannya di e2e-t24.mjs. */
const SEJAK = new Date()

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  try {
    // --- Persiapan sebagai pemilik ----------------------------------------
    const masukPemilik = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik berhasil', masukPemilik)
    if (!masukPemilik) throw new Error('login pemilik gagal')

    const kebijakan = await pastikanKebijakan(ws)
    lapor('kebijakan kos siap', kebijakan.ok, kebijakan.alasan)
    if (!kebijakan.ok) throw new Error('kebijakan gagal disiapkan')

    const siap = await evaluasi(
      ws,
      `
      (async () => {
        const h = {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
        }
        const p = async (jalur, metode, isi) => {
          const o = { method: metode, headers: h }
          if (isi) o.body = JSON.stringify(isi)
          const r = await fetch('${APP.replace(/\/$/, '')}/api' + jalur, o)
          const t = await r.text()
          let d = null
          try { d = t ? JSON.parse(t) : null } catch { d = null }
          return { status: r.status, data: d }
        }

        // Kamar
        const daftarKamar = await p('/kamar', 'GET')
        let kamar = (daftarKamar.data?.data || []).find((k) => k.nomor === 'T28-1')
        if (!kamar) {
          const buat = await p('/kamar', 'POST', { nomor: 'T28-1', tipe: 'Uji T28' })
          if (buat.status !== 201) return { galat: 'kamar: ' + JSON.stringify(buat.data) }
          kamar = buat.data.data
        }

        const tarifAda = await p('/tarif?kamar_id=' + kamar.id, 'GET')
        if ((tarifAda.data?.data || []).length === 0) {
          const tarif = await p('/tarif', 'POST', {
            kamar_id: kamar.id, nilai: ${POKOK}, berlaku_dari: '2026-01-01',
          })
          if (tarif.status !== 201) return { galat: 'tarif: ' + JSON.stringify(tarif.data) }
        }

        // Dua penghuni: satu akan dihapus, satu akan menolak persetujuan.
        const daftarPenghuni = await p('/penghuni', 'GET')
        const cari = (email) => (daftarPenghuni.data?.data || []).find((x) => x.email === email)

        let hapus = cari(${JSON.stringify(EMAIL_HAPUS)})
        if (!hapus) {
          const buat = await p('/penghuni', 'POST', {
            nama: ${JSON.stringify(NAMA_HAPUS)}, email: ${JSON.stringify(EMAIL_HAPUS)},
          })
          if (buat.status !== 201) return { galat: 'penghuni hapus: ' + JSON.stringify(buat.data) }
          hapus = buat.data.data
        }

        let tolak = cari(${JSON.stringify(EMAIL_TOLAK)})
        if (!tolak) {
          const buat = await p('/penghuni', 'POST', {
            nama: 'Uji T28 Tolak', email: ${JSON.stringify(EMAIL_TOLAK)},
          })
          if (buat.status !== 201) return { galat: 'penghuni tolak: ' + JSON.stringify(buat.data) }
          tolak = buat.data.data
        }

        // Kontrak + tagihan untuk penghuni yang akan dihapus. Tanpa tagihan,
        // "angka tetap tersimpan" tidak bisa dibuktikan apa pun.
        const daftarKontrak = await p('/kontrak', 'GET')
        let kontrak = (daftarKontrak.data?.data || []).find(
          (k) => k.status === 'aktif' && k.kamar_id === kamar.id,
        )
        if (!kontrak) {
          const buat = await p('/kontrak', 'POST', {
            kamar_id: kamar.id, penghuni_id: hapus.id,
            tgl_masuk: '2026-01-01', deposit: 0,
          })
          if (buat.status !== 201) return { galat: 'kontrak: ' + JSON.stringify(buat.data) }
          kontrak = buat.data.data
        }

        const daftarTagihan = await p('/tagihan', 'GET')
        let tagihan = (daftarTagihan.data?.data || []).find((t) => t.kontrak_id === kontrak.id)
        if (!tagihan) {
          const buat = await p('/tagihan', 'POST', {
            kontrak_id: kontrak.id, periode: '2026-02-01',
          })
          if (buat.status !== 201) return { galat: 'tagihan: ' + JSON.stringify(buat.data) }
          tagihan = buat.data.data
        }

        return { ok: true, kontrakId: kontrak.id, tagihanId: tagihan.id }
      })()
    `,
    )
    lapor('data uji siap (kamar, dua penghuni, kontrak, tagihan)', siap?.ok === true, JSON.stringify(siap))
    if (siap?.ok !== true) throw new Error('data uji gagal disiapkan: ' + JSON.stringify(siap))

    // --- Aktifkan kedua akun ----------------------------------------------
    const aktifHapus = await aktifkanAkun(ws, EMAIL_HAPUS, SANDI, SEJAK)
    lapor('penghuni yang akan dihapus mengaktifkan akunnya', aktifHapus)
    if (!aktifHapus) throw new Error('aktivasi penghuni pertama gagal')

    const aktifTolak = await aktifkanAkun(ws, EMAIL_TOLAK, SANDI, SEJAK)
    lapor('penghuni yang akan menolak mengaktifkan akunnya', aktifTolak)
    if (!aktifTolak) throw new Error('aktivasi penghuni kedua gagal')

    // --- Gerbang persetujuan muncul untuk akun yang belum menyetujui -------
    //
    // Diperiksa lewat layar, karena justru tampilannya yang menjadi soal:
    // server tidak menolak apa pun bila persetujuan belum diberikan, jadi
    // tanpa gerbang ini persetujuan tidak pernah ditanyakan.
    const masukTolak = await masukSebagai(ws, EMAIL_TOLAK, SANDI)
    lapor('penghuni kedua bisa masuk', masukTolak)

    const kePdp = await tungguSampai(ws, `location.pathname === '/persetujuan-pdp'`, 12000)
    lapor(
      'akun yang belum menyetujui diarahkan ke layar persetujuan',
      kePdp,
      await evaluasi(ws, 'location.pathname'),
    )

    const isiPdp = await evaluasi(
      ws,
      `
      (() => {
        const tombol = [...document.querySelectorAll('button')].map(b => b.textContent.trim())
        return {
          adaSetuju: tombol.some(t => /saya setuju/i.test(t)),
          adaTolak: tombol.some(t => /tidak setuju/i.test(t)),
          versi: document.querySelector('.lencana-versi')?.textContent.trim() || '',
          panjangTeks: (document.querySelector('.isi-pdp')?.textContent || '').length,
        }
      })()
    `,
    )
    lapor(
      'layar persetujuan menyediakan pilihan setuju DAN tidak setuju',
      isiPdp?.adaSetuju === true && isiPdp?.adaTolak === true,
      JSON.stringify(isiPdp),
    )
    lapor(
      'layar persetujuan menampilkan versi teks dan isi yang memadai',
      Boolean(isiPdp?.versi) && isiPdp?.panjangTeks > 300,
      `versi "${isiPdp?.versi}", ${isiPdp?.panjangTeks} huruf`,
    )

    await tangkapLayar(ws, 'T28-persetujuan')

    // --- Menolak persetujuan mengakhiri sesi ------------------------------
    const setelahTolak = await evaluasi(
      ws,
      `
      (async () => {
        const tombol = [...document.querySelectorAll('button')]
          .find(b => /tidak setuju/i.test(b.textContent))
        if (!tombol) return 'tombol tolak tidak ada'
        tombol.click()
        await new Promise(r => setTimeout(r, 1500))
        return { jalur: location.pathname, token: localStorage.getItem('kos.token') }
      })()
    `,
    )
    lapor(
      'menolak persetujuan mengeluarkan pengguna dari aplikasi',
      setelahTolak?.jalur === '/masuk' && !setelahTolak?.token,
      JSON.stringify(setelahTolak),
    )

    // --- Menyetujui tercatat dan gerbang tidak muncul lagi ----------------
    const masukHapus = await masukSebagai(ws, EMAIL_HAPUS, SANDI)
    lapor('penghuni pertama bisa masuk', masukHapus)

    await tungguSampai(ws, `location.pathname === '/persetujuan-pdp'`, 12000)

    const setuju = await evaluasi(
      ws,
      `
      (async () => {
        const tombol = [...document.querySelectorAll('button')]
          .find(b => /saya setuju/i.test(b.textContent))
        if (!tombol) return 'tombol setuju tidak ada'
        tombol.click()
        await new Promise(r => setTimeout(r, 1800))
        return location.pathname
      })()
    `,
    )
    lapor(
      'menyetujui membawa pengguna ke halaman kerjanya',
      setuju === '/penghuni',
      String(setuju),
    )

    // Dimuat ulang: gerbang tidak boleh muncul lagi. Diperiksa setelah reload,
    // bukan hanya setelah pengalihan - gerbang yang hanya dihapus dari memori
    // akan muncul lagi pada kunjungan berikutnya.
    await kirim(ws, 'Page.navigate', { url: `${APP}/penghuni` })
    await tungguSampai(ws, `document.querySelector('h1')`, 15000)
    const tetapDiPortal = await evaluasi(ws, 'location.pathname')
    lapor(
      'gerbang persetujuan tidak muncul lagi setelah disetujui',
      tetapDiPortal === '/penghuni',
      tetapDiPortal,
    )

    const dataSaya = await panggilApi(ws, '/pdp/data-saya')
    lapor(
      'server mencatat persetujuan yang baru diberikan',
      dataSaya.status === 200 && (dataSaya.data?.data?.persetujuan || []).length >= 1,
      `status ${dataSaya.status}, ${(dataSaya.data?.data?.persetujuan || []).length} baris`,
    )

    // --- Hak akses: data pribadi lewat layar ------------------------------
    await kirim(ws, 'Page.navigate', { url: `${APP}/data-pribadi` })
    const pdpTerbuka = await tungguSampai(
      ws,
      `document.querySelector('h1')?.textContent.includes('Data pribadi')`,
      15000,
    )
    lapor('halaman Data pribadi terbuka untuk penghuni', pdpTerbuka)

    const isiData = await evaluasi(
      ws,
      `
      (() => {
        const teks = document.body.textContent.replace(/\\s+/g, ' ')
        return {
          adaEmail: teks.includes(${JSON.stringify(EMAIL_HAPUS)}),
          adaKamar: /Kamar yang pernah/.test(teks),
          adaPersetujuan: /Persetujuan yang pernah/.test(teks),
          adaJejak: /Jejak tindakan/.test(teks),
          adaKonfirmasi: Boolean(document.querySelector('#konfirmasi-hapus')),
          teksTombolHapus: [...document.querySelectorAll('button')]
            .map(b => b.textContent.trim())
            .find(t => /lepas identitas/i.test(t)) || '',
        }
      })()
    `,
    )
    lapor(
      'halaman Data pribadi menampilkan akun, kamar, persetujuan, dan jejak',
      isiData?.adaEmail && isiData?.adaKamar && isiData?.adaPersetujuan && isiData?.adaJejak,
      JSON.stringify(isiData),
    )

    await tangkapLayar(ws, 'T28-data-pribadi')

    // --- Penghapusan ditahan selama kontrak masih aktif -------------------
    //
    // Penghuni ini masih menempati kamar, jadi tombolnya harus mati dan
    // alasannya harus tertulis. Tombol mati tanpa penjelasan terbaca seperti
    // aplikasi rusak.
    const tahanan = await evaluasi(
      ws,
      `
      (async () => {
        const input = document.querySelector('#konfirmasi-hapus')
        const tombol = [...document.querySelectorAll('button')]
          .find(b => /lepas identitas/i.test(b.textContent))
        if (!input || !tombol) return { galat: 'kontrol tidak ada' }

        input.value = 'HAPUS'
        input.dispatchEvent(new Event('input', { bubbles: true }))
        await new Promise(r => setTimeout(r, 300))

        return {
          tombolMati: tombol.disabled,
          teks: document.body.textContent.replace(/\\s+/g, ' '),
        }
      })()
    `,
    )
    lapor(
      'penghapusan ditahan selama kontrak aktif, dengan alasan tertulis',
      tahanan?.tombolMati === true && /masih menempati kamar/i.test(tahanan?.teks || ''),
      `tombol mati: ${tahanan?.tombolMati}`,
    )

    // Server juga harus menolak, dan dengan 409 - bukan 403. Pengguna berhak
    // meminta; yang belum tepat adalah waktunya.
    const tolakServer = await panggilApi(ws, '/pdp/data-saya', 'DELETE', { konfirmasi: 'HAPUS' })
    lapor(
      'server menolak penghapusan kontrak aktif dengan 409',
      tolakServer.status === 409 && tolakServer.data?.kode === 'penghapusan_belum_dapat_dilakukan',
      `status ${tolakServer.status}`,
    )

    // Konfirmasi yang salah ditolak 422 - pagar terakhir sebelum tindakan
    // yang tidak bisa dibatalkan.
    const konfirmasiSalah = await panggilApi(ws, '/pdp/data-saya', 'DELETE', {
      konfirmasi: 'hapus',
    })
    lapor(
      'konfirmasi yang salah ditolak 422',
      konfirmasiSalah.status === 422,
      `status ${konfirmasiSalah.status}`,
    )

    // --- Akhiri kontrak, lalu hapus ---------------------------------------
    //
    // Diakhiri lewat API sebagai pemilik: layar kontrak sudah diuji skrip
    // lain, dan yang sedang diuji di sini adalah penghapusan.
    await masukSebagai(ws, 'pemilik@kos.test')
    const akhiri = await panggilApi(ws, `/kontrak/${siap.kontrakId}/akhiri`, 'POST')
    lapor('pemilik mengakhiri kontrak penghuni uji', akhiri.status === 200, `status ${akhiri.status}`)

    // --- Anonimisasi jejak: pengelola yang dicabut -------------------------
    //
    // Inilah satu-satunya jalan yang benar-benar menghasilkan baris jejak
    // berisi nama orang yang kemudian meminta penghapusan. Urutannya disengaja:
    // pengelola mencatat pembayaran DULU (supaya namanya masuk jejak), baru
    // dicabut, baru menghapus akunnya sendiri.
    //
    // Blok ini dijalankan SEBELUM penghapusan penghuni pertama, bukan sesudah,
    // karena ia mencatat pembayaran pada tagihan yang sama. Sejak REV-SEC-04
    // tagihan yang belum lunas menghalangi penghapusan, jadi urutan lama -
    // hapus dulu, catat pembayaran kemudian - tidak mungkin lagi: penghapusan
    // yang dijalankan lebih dulu akan ditolak 409.
    const EMAIL_MANTAN = 'uji-t28-mantan@kos.test'
    const NAMA_MANTAN = 'Uji T28 Mantan Pengelola'
    const SANDI_MANTAN = 'sandi-t28-mantan'

    const buatMantan = await panggilApi(ws, '/pengelola', 'POST', {
      nama: NAMA_MANTAN,
      email: EMAIL_MANTAN,
      wewenang: ['catat_pembayaran'],
    })
    lapor('pengelola uji dibuat untuk jalur anonimisasi', buatMantan.status === 201, `status ${buatMantan.status}`)
    if (buatMantan.status !== 201) throw new Error('pengelola uji gagal dibuat')

    const idMantan = buatMantan.data?.data?.id
    const aktifMantan = await aktifkanAkun(ws, EMAIL_MANTAN, SANDI_MANTAN, SEJAK)
    lapor('pengelola uji mengaktifkan akunnya', aktifMantan)
    if (!aktifMantan) throw new Error('aktivasi pengelola gagal')

    await masukSebagai(ws, EMAIL_MANTAN, SANDI_MANTAN)
    await tungguSampai(ws, `location.pathname === '/persetujuan-pdp'`, 12000)
    await evaluasi(
      ws,
      `
      (async () => {
        const tombol = [...document.querySelectorAll('button')]
          .find(b => /saya setuju/i.test(b.textContent))
        if (tombol) tombol.click()
        await new Promise(r => setTimeout(r, 1500))
        return location.pathname
      })()
    `,
    )

    const catat = await panggilApi(ws, `/tagihan/${siap.tagihanId}/pembayaran`, 'POST', {
      jumlah: 1000,
      tanggal_pembayaran: '2026-02-05',
      metode: 'tunai',
    })
    lapor(
      'pengelola mencatat pembayaran sehingga namanya masuk jejak audit',
      catat.status === 201 || catat.status === 200,
      `status ${catat.status}`,
    )

    // Pemilik mencabut aksesnya. Sesudah ini ia berperan penghuni - dan
    // karenanya berhak meminta penghapusan data pribadinya sendiri.
    await masukSebagai(ws, 'pemilik@kos.test')
    const cabut = await panggilApi(ws, `/pengelola/${idMantan}`, 'DELETE')
    lapor('pemilik mencabut akses pengelola uji', cabut.status === 200, `status ${cabut.status}`)

    const auditSebelum = await panggilApi(ws, '/audit-log')
    const jejakSebelum = (auditSebelum.data?.data || []).filter(
      (j) => j.aktor_nama === NAMA_MANTAN,
    )
    lapor(
      'jejak audit memuat nama pengelola sebelum ia menghapus akunnya',
      jejakSebelum.length > 0,
      `${jejakSebelum.length} baris`,
    )

    // --- Lunasi tagihan penghuni yang akan dihapus -------------------------
    //
    // Sejak REV-SEC-04 penghuni yang masih punya tagihan `belum_bayar` atau
    // `sebagian` TIDAK boleh menghapus akunnya: sesudah namanya menjadi
    // "pengguna dihapus" tidak ada lagi yang bisa ditagih atas namanya, dan
    // utangnya ikut lenyap bersama identitasnya. Karena itu utangnya
    // diselesaikan lebih dulu - dan itu justru membuat uji ini lebih kuat:
    // yang dihapus adalah penghuni yang catatan keuangannya sudah tuntas.
    //
    // Nominalnya dibaca dari `sisa`, bukan dihitung ulang di sini: `sisa`
    // sudah memperhitungkan denda berjalan, dan menebaknya di skrip berarti
    // menguji salinan rumus, bukan rumusnya.
    const tagihanKini = await panggilApi(ws, '/tagihan')
    const baris = (tagihanKini.data?.data || []).find((t) => t.id === siap.tagihanId)
    lapor(
      'tagihan uji masih ada dan bisa dibaca sebelum pelunasan',
      Boolean(baris),
      baris ? `status ${baris.status}, sisa ${baris.sisa}` : 'tidak ditemukan',
    )

    if (baris && Number(baris.sisa) > 0) {
      const lunasi = await panggilApi(ws, `/tagihan/${siap.tagihanId}/pembayaran`, 'POST', {
        jumlah: Number(baris.sisa),
        tanggal_pembayaran: '2026-02-06',
        metode: 'transfer',
      })
      lapor(
        'pemilik melunasi sisa tagihan sebelum penghapusan',
        lunasi.status === 201,
        `status ${lunasi.status}, jumlah ${baris.sisa}`,
      )
    }

    // Nilai tagihan dicatat SEBELUM penghapusan, supaya bisa dibandingkan
    // sesudahnya. Angka yang tidak berubah adalah inti dari T28.
    const tagihanSebelum = await panggilApi(ws, '/tagihan')
    const nilaiSebelum = (tagihanSebelum.data?.data || [])
      .filter((t) => t.kontrak_id === siap.kontrakId)
      .map((t) => Number(t.total))

    await masukSebagai(ws, EMAIL_HAPUS, SANDI)
    await kirim(ws, 'Page.navigate', { url: `${APP}/data-pribadi` })
    await tungguSampai(ws, `document.querySelector('#konfirmasi-hapus')`, 15000)

    const hapus = await evaluasi(
      ws,
      `
      (async () => {
        const input = document.querySelector('#konfirmasi-hapus')
        input.value = 'HAPUS'
        input.dispatchEvent(new Event('input', { bubbles: true }))
        await new Promise(r => setTimeout(r, 300))

        const tombol = [...document.querySelectorAll('button')]
          .find(b => /lepas identitas/i.test(b.textContent))
        if (!tombol) return 'tombol tidak ada'
        if (tombol.disabled) return 'tombol masih mati'

        tombol.click()
        await new Promise(r => setTimeout(r, 2000))
        return { jalur: location.pathname, token: localStorage.getItem('kos.token') }
      })()
    `,
    )
    lapor(
      'penghapusan berhasil dan pengguna keluar dari aplikasi',
      hapus?.jalur === '/masuk' && !hapus?.token,
      JSON.stringify(hapus),
    )

    const pesanHapus = await evaluasi(
      ws,
      `document.body.textContent.replace(/\\s+/g, ' ')`,
    )
    lapor(
      'layar masuk menjelaskan bahwa identitas sudah dilepas',
      /identitas anda sudah dilepas/i.test(pesanHapus),
      (pesanHapus || '').slice(0, 120),
    )

    await tangkapLayar(ws, 'T28-setelah-hapus')

    // --- Anonimisasi, bukan penghapusan baris ----------------------------
    //
    // Dua sisi diperiksa bersamaan, dan keduanya harus benar:
    //   a. penghuninya tidak lagi tampil sebagai orang;
    //   b. tagihannya MASIH ADA dengan nilai yang sama.
    //
    // Sisi ketiga - jejak audit menyebut "pengguna dihapus" - diperiksa di
    // bagian terpisah di bawah, karena akun ini tidak akan pernah menghasilkannya.
    await masukSebagai(ws, 'pemilik@kos.test')

    const daftarPenghuni = await panggilApi(ws, '/penghuni')
    const masihTampil = (daftarPenghuni.data?.data || []).some(
      (p) => p.email === EMAIL_HAPUS,
    )
    lapor('penghuni yang dihapus tidak lagi muncul sebagai orang', !masihTampil)

    const tagihanSesudah = await panggilApi(ws, '/tagihan')
    const nilaiSesudah = (tagihanSesudah.data?.data || [])
      .filter((t) => t.kontrak_id === siap.kontrakId)
      .map((t) => Number(t.total))

    lapor(
      'tagihan tetap ada dengan nilai yang sama persis setelah penghapusan',
      nilaiSesudah.length === nilaiSebelum.length &&
        nilaiSesudah.every((n, i) => n === nilaiSebelum[i]),
      `${JSON.stringify(nilaiSebelum)} -> ${JSON.stringify(nilaiSesudah)}`,
    )

    // Penghuni biasa TIDAK PERNAH muncul sebagai aktor di jejak audit: seluruh
    // tindakan yang dicatat - terbit tagihan, catat pembayaran, ubah tarif -
    // adalah tindakan pengurus kos, bukan penghuni. Karena itu yang bisa
    // diperiksa di sini hanyalah bahwa namanya tidak MUNCUL; memeriksa
    // anonimisasi jejak pada akun ini akan selalu gagal.
    //
    // Kasus yang benar-benar menghasilkan baris jejak berisi nama orang yang
    // kemudian meminta penghapusan adalah **pengelola yang dicabut lalu
    // menghapus akunnya sendiri**. Itu diuji terpisah di bawah.
    const audit = await panggilApi(ws, '/audit-log')
    const namaAsliTersisa = (audit.data?.data || []).some(
      (j) => j.aktor_nama === NAMA_HAPUS,
    )
    lapor('nama penghuni yang dihapus tidak muncul sebagai aktor jejak', !namaAsliTersisa)

    // Login dengan sandi lamanya harus gagal: akunnya masih ada (karena baris
    // audit menunjuk padanya) tetapi sandinya sudah diacak.
    const masukLagi = await panggilApi(ws, '/login', 'POST', {
      email: EMAIL_HAPUS,
      password: SANDI,
    })
    lapor(
      'akun yang dihapus tidak bisa dipakai masuk lagi',
      masukLagi.status === 401,
      `status ${masukLagi.status}`,
    )

    await tangkapLayar(ws, 'T28-audit-anonim')

    // --- Anonimisasi jejak sudah terjadi di atas --------------------------
    //
    // Pengelola uji sudah dicabut dan namanya sudah masuk jejak sebelum
    // penghapusan penghuni pertama; urutannya dibalik demi REV-SEC-04.
    // Yang tersisa: ia masuk lagi - sekarang sebagai penghuni tanpa kamar -
    // lalu menghapus akunnya sendiri lewat layar yang sama.
    const masukMantan = await masukSebagai(ws, EMAIL_MANTAN, SANDI_MANTAN)
    lapor('akun yang dicabut tetap bisa masuk sebagai penghuni', masukMantan)
    if (!masukMantan) throw new Error('login mantan pengelola gagal')

    await kirim(ws, 'Page.navigate', { url: `${APP}/data-pribadi` })
    const pdpMantan = await tungguSampai(ws, `document.querySelector('#konfirmasi-hapus')`, 15000)
    lapor('halaman Data pribadi terbuka untuk akun yang dicabut', pdpMantan)

    const hapusMantan = await evaluasi(
      ws,
      `
      (async () => {
        const input = document.querySelector('#konfirmasi-hapus')
        if (!input) return 'kolom konfirmasi tidak ada'
        input.value = 'HAPUS'
        input.dispatchEvent(new Event('input', { bubbles: true }))
        await new Promise(r => setTimeout(r, 300))

        const tombol = [...document.querySelectorAll('button')]
          .find(b => /lepas identitas/i.test(b.textContent))
        if (!tombol) return 'tombol tidak ada'
        if (tombol.disabled) return 'tombol masih mati'

        tombol.click()
        await new Promise(r => setTimeout(r, 2000))
        return location.pathname
      })()
    `,
    )
    lapor('mantan pengelola bisa menghapus akunnya sendiri', hapusMantan === '/masuk', String(hapusMantan))

    await masukSebagai(ws, 'pemilik@kos.test')
    const auditSesudah = await panggilApi(ws, '/audit-log')
    const barisAnonim = (auditSesudah.data?.data || []).filter(
      (j) => j.aktor_nama === 'pengguna dihapus',
    )
    lapor(
      'jejak audit menyebut "pengguna dihapus", bukan nama aslinya',
      barisAnonim.length > 0,
      `${barisAnonim.length} baris teranonimkan`,
    )

    const namaMantanTersisa = (auditSesudah.data?.data || []).some(
      (j) => j.aktor_nama === NAMA_MANTAN,
    )
    lapor('nama mantan pengelola tidak lagi muncul di jejak audit', !namaMantanTersisa)

    // Angka pembayarannya harus tetap ada: yang hilang hanya siapa.
    const tetapTerbayar = barisAnonim.some(
      (j) => Number(j.nilai_baru?.jumlah) === 1000,
    )
    lapor(
      'angka pembayaran tetap terbaca setelah namanya dilepas',
      tetapTerbayar,
      tetapTerbayar ? 'Rp 1.000 masih tercatat' : 'angka ikut hilang',
    )

    await tangkapLayar(ws, 'T28-jejak-teranonim')
  } finally {
    const gagal = await uji.selesai()
    if (gagal.length) {
      console.error(`\n${gagal.length} langkah gagal:`)
      for (const g of gagal) console.error(` - ${g.nama}${g.detail ? ': ' + g.detail : ''}`)
    }
  }
})
