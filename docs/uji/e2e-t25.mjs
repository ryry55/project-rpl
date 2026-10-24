// Verifikasi end-to-end T25 lewat browser sungguhan.
//
// Yang dibuktikan: peristiwa yang mengubah uang benar-benar memberi tahu orang
// yang harus tahu, lewat DUA kanal, dan penerimanya bisa mengatur kanal mana
// yang ia mau - tanpa bisa mematikan yang wajib.
//
// Empat hal yang diuji, dan kenapa masing-masing perlu:
//
//   1. **Lencana muncul karena peristiwa, bukan karena ada baris di tabel.**
//      Notifikasi ditulis oleh `PengajuUsulan` di dalam transaksi usulan, jadi
//      membuktikannya menuntut peristiwa sungguhan - bukan menyisipkan baris
//      notifikasi lewat SQL, yang hanya akan menguji apakah komponennya bisa
//      membaca tabel.
//
//   2. **Menandai terbaca bertahan setelah halaman dimuat ulang.** Penurunan
//      lencana di layar saja tidak membuktikan apa pun: komponen yang hanya
//      mengubah angka di memori akan lulus uji itu dan tetap menampilkan
//      lencana yang sama pada kunjungan berikutnya.
//
//   3. **Email benar-benar terkirim.** Kanal email adalah setengah dari T25, dan
//      setengah itu tidak terlihat di peramban sama sekali. Diperiksa lewat
//      Mailpit - kotak surat sungguhan yang menangkap SMTP container api.
//
//   4. **`in_app` tidak bisa dimatikan.** Kalau bisa, satu klik menghapus
//      satu-satunya cara mengetahui tarif naik tanpa membuka layarnya kebetulan.
//      Centangnya dinonaktifkan di layar DAN dipaksa server; keduanya diperiksa.
//
// Pakai: node docs/uji/e2e-t25.mjs
// Prasyarat: docker compose up sudah jalan (termasuk mailpit), Chrome
// --remote-debugging-port=9222, APP_DEMO_MODE=true, dan database sudah di-seed
// (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo: membuat akun pengelola, kamar, usulan, dan
// pengaturan notifikasi milik pemilik.

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
  lewatiPersetujuanPdp,
} from './_cdp.mjs'

const EMAIL_PENGELOLA = 'uji-t25@kos.test'
const NOMOR_KAMAR = 'T25-1'
const MAILPIT = 'http://localhost:8025'

/**
 * Sandi yang ditetapkan sendiri oleh pengelola uji lewat tautan aktivasi.
 *
 * Sejak T27 pemilik tidak mengetikkan sandi siapa pun, jadi akun yang dibuat
 * di sini tidak punya sandi yang diketahui skrip sampai tautannya dibuka.
 */
const SANDI_PENGELOLA = 'sandi-t25-pengelola'

/** Batas bawah waktu surat; lihat alasannya di e2e-t24.mjs. */
const SEJAK = new Date()

/**
 * Membaca kotak surat Mailpit.
 *
 * Dijalankan dari Node, bukan dari peramban: Mailpit tidak mengirim header
 * CORS, dan `fetch` dari halaman aplikasi akan ditolak sebelum sampai. Yang
 * diuji tetap jalur sungguhan - container api mengirim SMTP ke container
 * mailpit - hanya cara membacanya yang lewat HTTP biasa.
 *
 * Mengembalikan `null` bila Mailpit tidak dapat dihubungi, supaya pemanggil
 * bisa membedakan "tidak ada email" dari "tidak bisa memeriksa".
 */
async function bacaMailpit() {
  try {
    const res = await fetch(`${MAILPIT}/api/v1/messages?limit=100`)
    if (!res.ok) return null
    const isi = await res.json()
    return Array.isArray(isi.messages) ? isi.messages : []
  } catch {
    return null
  }
}

/**
 * Mencari email tertentu di Mailpit.
 *
 * Dicari menurut penerima DAN subjek. Kotak surat Mailpit dipakai bersama oleh
 * seluruh skrip E2E dan tidak dikosongkan antar-jalan, jadi "ada email" bukan
 * bukti apa pun - yang perlu dibuktikan adalah email untuk peristiwa ini sampai
 * ke orang ini.
 */
function cariEmail(pesan, penerima, potonganSubjek) {
  return pesan.find((m) => {
    const ke = (m.To || []).map((t) => t.Address).join(',')
    return ke.includes(penerima) && (m.Subject || '').includes(potonganSubjek)
  })
}

/** Daftar tarif satu kamar. */
async function tarifKamar(ws, kamarId) {
  const hasil = await panggilApi(ws, `/tarif?kamar_id=${kamarId}`)
  return (hasil.data?.data || []).map((t) => t.berlaku_dari)
}

/** Tanggal berlaku yang belum dipakai kamar ini (append-only, lihat e2e-t26). */
function tanggalBebas(dipakai) {
  const set = new Set(dipakai)
  let tahun = 2026
  let bulan = 9

  for (let i = 0; i < 60; i += 1) {
    const calon = `${tahun}-${String(bulan).padStart(2, '0')}-01`
    if (!set.has(calon)) return calon
    bulan += 1
    if (bulan > 12) {
      bulan = 1
      tahun += 1
    }
  }

  throw new Error('tidak menemukan tanggal berlaku yang bebas')
}

/** Kamar + tarif awal, idempoten. */
async function siapkanKamar(ws) {
  const daftar = await panggilApi(ws, '/kamar')
  if (daftar.status !== 200) {
    return { ok: false, alasan: `GET /kamar gagal (${daftar.status})` }
  }

  let kamar = (daftar.data?.data || []).find((k) => k.nomor === NOMOR_KAMAR)

  if (!kamar) {
    const buat = await panggilApi(ws, '/kamar', 'POST', { nomor: NOMOR_KAMAR })
    if (buat.status !== 201) {
      return { ok: false, alasan: `POST /kamar gagal (${buat.status})` }
    }
    kamar = buat.data.data
  }

  if (!kamar.tarif_terakhir) {
    const tarif = await panggilApi(ws, '/tarif', 'POST', {
      kamar_id: kamar.id,
      nilai: 1500000,
      berlaku_dari: '2026-01-01',
    })
    if (tarif.status !== 201 && tarif.status !== 422) {
      return { ok: false, alasan: `POST /tarif gagal (${tarif.status})` }
    }
  }

  return { ok: true, kamar }
}

/** Jumlah lencana di lonceng, atau 0 bila tidak ada. */
async function bacaLencana(ws) {
  return evaluasi(ws, `Number(document.querySelector('.lonceng .jumlah')?.textContent || 0)`)
}

/**
 * Membuka panel lonceng dan menunggu isinya selesai dimuat.
 *
 * Menunggu `.panel-lonceng` saja tidak cukup: panel dirender lebih dulu dengan
 * tulisan "Memuat.", lalu daftarnya menyusul setelah `/notifikasi` menjawab.
 * Membaca isinya pada saat itu menghasilkan "0 baris" - kegagalan yang terlihat
 * seperti notifikasinya tidak pernah dibuat padahal hanya belum tiba.
 */
async function bukaPanel(ws) {
  const terbuka = await evaluasi(ws, `Boolean(document.querySelector('.panel-lonceng'))`)
  if (!terbuka) {
    await evaluasi(ws, `(() => { document.querySelector('.lonceng')?.click(); return true })()`)
  }

  const siap = await tungguSampai(
    ws,
    `(() => {
      const panel = document.querySelector('.panel-lonceng')
      if (!panel) return false
      return panel.querySelectorAll('.satu-lonceng').length > 0
        || /Belum ada notifikasi/.test(panel.innerText || '')
    })()`,
    10000,
  )

  return siap
}

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  try {
    // --- Persiapan --------------------------------------------------------
    const masukPemilik = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik berhasil', masukPemilik)
    if (!masukPemilik) throw new Error('login pemilik gagal')

    const kebijakan = await pastikanKebijakan(ws)
    lapor('kebijakan kos siap', kebijakan.ok, kebijakan.alasan)
    if (!kebijakan.ok) throw new Error('kebijakan gagal disiapkan')

    const siap = await siapkanKamar(ws)
    lapor('kamar dan tarif awal siap', siap.ok, siap.alasan || `kamar ${siap.kamar?.id}`)
    if (!siap.ok) throw new Error('kamar gagal disiapkan')

    const kamar = siap.kamar
    const tarifAda = await tarifKamar(ws, kamar.id)
    const tglPertama = tanggalBebas(tarifAda)
    const tglKedua = tanggalBebas([...tarifAda, tglPertama])

    const daftarAwal = await panggilApi(ws, '/pengelola')
    const sisa = (daftarAwal.data?.data || []).find((p) => p.email === EMAIL_PENGELOLA)
    if (sisa) {
      await panggilApi(ws, `/pengelola/${sisa.id}`, 'DELETE')
    }

    const buatPengelola = await panggilApi(ws, '/pengelola', 'POST', {
      nama: 'Uji T25',
      email: EMAIL_PENGELOLA,
      wewenang: ['ubah_tarif'],
    })
    lapor(
      'pengelola uji dibuat',
      buatPengelola.status === 201,
      `status ${buatPengelola.status}`,
    )
    if (buatPengelola.status !== 201) throw new Error('pengelola gagal dibuat')

    // Akunnya belum bisa dipakai masuk sebelum penghuninya menetapkan sandi
    // lewat tautan email - pemilik tidak memegang sandi itu.
    const aktif = await aktifkanAkun(ws, EMAIL_PENGELOLA, SANDI_PENGELOLA, SEJAK)
    lapor('pengelola uji mengaktifkan akunnya', aktif)
    if (!aktif) throw new Error('aktivasi pengelola gagal')

    // Kotak masuk pemilik dibersihkan lebih dulu. Tanpa ini, lencana yang
    // terlihat bisa berasal dari notifikasi jalan sebelumnya, dan setiap asersi
    // di bawah akan lulus tanpa membuktikan bahwa peristiwanya benar-benar
    // mengirim apa pun.
    await panggilApi(ws, '/notifikasi/baca-semua', 'POST')

    // --- Peristiwa pertama: pengelola mengusulkan tarif -------------------
    const masukPengelola = await masukSebagai(ws, EMAIL_PENGELOLA, SANDI_PENGELOLA)
    lapor('pengelola bisa masuk', masukPengelola)
    if (!masukPengelola) throw new Error('login pengelola gagal')

    // Akun yang baru diaktifkan mendarat di gerbang persetujuan PDP (T28).
    // Skrip ini menguji notifikasi, bukan gerbang itu.
    const lolosPdp = await lewatiPersetujuanPdp(ws)
    lapor('gerbang persetujuan PDP dilewati', lolosPdp, await evaluasi(ws, 'location.pathname'))

    const usul = await panggilApi(ws, '/tarif', 'POST', {
      kamar_id: kamar.id,
      nilai: 1650000,
      berlaku_dari: tglPertama,
    })
    lapor(
      'pengelola mengusulkan tarif (202)',
      usul.status === 202,
      `status ${usul.status}`,
    )

    // --- Lencana pemilik --------------------------------------------------
    await masukSebagai(ws, 'pemilik@kos.test')
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/kamar` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Kamar')`)

    const adaBadge = await tungguSampai(ws, `document.querySelector('.lonceng .jumlah')`, 10000)
    const jumlah = await bacaLencana(ws)
    lapor('lonceng menampilkan lencana setelah usulan masuk', adaBadge && jumlah >= 1, `${jumlah} belum dibaca`)

    const panel = await bukaPanel(ws)
    lapor('panel lonceng terbuka', panel)

    const isiPanel = await evaluasi(
      ws,
      `({
        baris: document.querySelectorAll('.satu-lonceng').length,
        teks: document.querySelector('.panel-lonceng')?.innerText || '',
        belum: document.querySelectorAll('.daftar-lonceng li.belum').length,
      })`,
    )
    lapor('panel berisi notifikasi usulan', isiPanel.baris >= 1, `${isiPanel.baris} baris`)
    lapor(
      'notifikasi menyebut usulan dan pengusulnya',
      /usulan/i.test(isiPanel.teks) && /Uji T25/.test(isiPanel.teks),
      isiPanel.teks.replace(/\s+/g, ' ').slice(0, 120),
    )
    lapor(
      'baris yang belum dibaca ditandai',
      isiPanel.belum >= 1,
      `${isiPanel.belum} baris bertanda`,
    )

    await tangkapLayar(ws, 'T25-lonceng')

    // --- Email benar-benar terkirim --------------------------------------
    const pesan = await bacaMailpit()

    if (pesan === null) {
      lapor('email notifikasi bisa diperiksa lewat Mailpit', false, 'Mailpit tidak dapat dihubungi di ' + MAILPIT)
    } else {
      const emailUsulan = cariEmail(pesan, 'pemilik@kos.test', 'Usulan')
      lapor(
        'email notifikasi sampai ke kotak surat pemilik',
        Boolean(emailUsulan),
        emailUsulan ? `subjek "${emailUsulan.Subject}"` : `${pesan.length} pesan diperiksa`,
      )
    }

    // --- Tandai semua terbaca --------------------------------------------
    const tandai = await evaluasi(
      ws,
      `
      (async () => {
        const tombol = [...document.querySelectorAll('.panel-lonceng button')]
          .find(b => /tandai semua/i.test(b.textContent))
        if (!tombol) return 'tombol tandai semua tidak ada'
        tombol.click()
        await new Promise(r => setTimeout(r, 1200))
        return document.querySelector('.lonceng .jumlah') ? 'lencana masih ada' : 'lencana hilang'
      })()
    `,
    )
    lapor('menandai semua terbaca menghapus lencana', tandai === 'lencana hilang', String(tandai))

    // Dimuat ulang: angka di layar saja tidak membuktikan apa pun.
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/kamar` })
    await tungguSampai(ws, `document.querySelector('.lonceng')`, 10000)
    await new Promise((r) => setTimeout(r, 1200))
    const sesudahMuatUlang = await bacaLencana(ws)
    lapor(
      'lencana tetap kosong setelah halaman dimuat ulang',
      sesudahMuatUlang === 0,
      `${sesudahMuatUlang} belum dibaca`,
    )

    // --- Peristiwa kedua: lencana kembali, lalu satu per satu -------------
    await masukSebagai(ws, EMAIL_PENGELOLA, SANDI_PENGELOLA)
    const usulKedua = await panggilApi(ws, '/tarif', 'POST', {
      kamar_id: kamar.id,
      nilai: 1700000,
      berlaku_dari: tglKedua,
    })
    lapor(
      'usulan kedua terkirim (202)',
      usulKedua.status === 202,
      `status ${usulKedua.status}`,
    )

    await masukSebagai(ws, 'pemilik@kos.test')
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/kamar` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Kamar')`)

    const badgeKedua = await tungguSampai(ws, `document.querySelector('.lonceng .jumlah')`, 10000)
    const jumlahKedua = await bacaLencana(ws)
    lapor('lencana kembali muncul untuk peristiwa baru', badgeKedua && jumlahKedua >= 1, `${jumlahKedua} belum dibaca`)

    await bukaPanel(ws)
    const klikSatu = await evaluasi(
      ws,
      `
      (async () => {
        const mulai = Date.now()
        let baris = null
        while (Date.now() - mulai < 8000) {
          baris = [...document.querySelectorAll('.satu-lonceng')]
            .find(b => /usulan/i.test(b.textContent))
          if (baris) break
          await new Promise(r => setTimeout(r, 150))
        }
        if (!baris) return 'baris usulan tidak ada'
        baris.click()
        await new Promise(r => setTimeout(r, 1500))
        return location.pathname
      })()
    `,
    )
    lapor(
      'mengklik notifikasi membawa ke halaman yang dijawabnya',
      klikSatu === '/pemilik/persetujuan',
      String(klikSatu),
    )

    const sisaBelum = await bacaLencana(ws)
    lapor(
      'mengklik satu notifikasi mengurangi lencana',
      sisaBelum < jumlahKedua,
      `${jumlahKedua} menjadi ${sisaBelum}`,
    )

    // --- Pengaturan notifikasi -------------------------------------------
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/pengaturan-notifikasi` })
    const halamanNotif = await tungguSampai(
      ws,
      `document.querySelector('h1')?.textContent.includes('Pengaturan notifikasi')`,
    )
    lapor('halaman Pengaturan notifikasi terbuka', halamanNotif)

    const matriks = await evaluasi(
      ws,
      `({
        baris: document.querySelectorAll('.tabel-notifikasi tbody tr').length,
        kolom: document.querySelectorAll('.tabel-notifikasi thead th').length,
        total: document.querySelectorAll('.pilihan-kanal input').length,
        inAppNonaktif: [...document.querySelectorAll('.pilihan-kanal input')]
          .filter(i => i.disabled).length,
        inAppTercentang: [...document.querySelectorAll('.pilihan-kanal input')]
          .filter(i => i.disabled && i.checked).length,
        emailAktif: [...document.querySelectorAll('.pilihan-kanal input')]
          .filter(i => !i.disabled && i.checked).length,
      })`,
    )
    lapor('sembilan peristiwa ditampilkan', matriks.baris === 9, `${matriks.baris} baris`)
    lapor('dua kanal ditampilkan', matriks.kolom === 3, `${matriks.kolom} kolom (termasuk judul)`)
    lapor(
      'kolom in-app nonaktif dan selalu tercentang',
      matriks.inAppNonaktif === 9 && matriks.inAppTercentang === 9,
      `${matriks.inAppNonaktif} nonaktif, ${matriks.inAppTercentang} tercentang`,
    )
    lapor('kanal email bisa diubah', matriks.emailAktif > 0, `${matriks.emailAktif} tercentang`)

    // Mematikan email untuk "Tarif sewa diubah", lalu disimpan.
    const simpan = await evaluasi(
      ws,
      `
      (async () => {
        const baris = [...document.querySelectorAll('.tabel-notifikasi tbody tr')]
          .find(tr => /tarif sewa diubah/i.test(tr.textContent))
        if (!baris) return 'baris tarif tidak ada'

        const email = [...baris.querySelectorAll('.pilihan-kanal input')]
          .find(i => !i.disabled)
        if (!email) return 'kotak email tidak ada'
        if (!email.checked) return 'email sudah mati sebelum diuji'

        email.click()
        await new Promise(r => setTimeout(r, 250))

        const tombol = [...document.querySelectorAll('button')]
          .find(b => /simpan pengaturan/i.test(b.textContent))
        if (!tombol) return 'tombol simpan tidak ada'
        tombol.click()
        await new Promise(r => setTimeout(r, 1500))
        return document.querySelector('.pesan-sukses')?.textContent.trim() || 'tanpa pesan'
      })()
    `,
    )
    lapor('pengaturan tersimpan', /disimpan/i.test(String(simpan)), String(simpan))

    await tangkapLayar(ws, 'T25-pengaturan-notifikasi')

    // Dimuat ulang: preferensi harus berasal dari server, bukan dari keadaan
    // reaktif yang masih hidup di memori komponen.
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/pengaturan-notifikasi` })
    await tungguSampai(ws, `document.querySelectorAll('.tabel-notifikasi tbody tr').length === 9`)
    const sesudahSimpan = await evaluasi(
      ws,
      `
      (() => {
        const baris = [...document.querySelectorAll('.tabel-notifikasi tbody tr')]
          .find(tr => /tarif sewa diubah/i.test(tr.textContent))
        const email = [...baris.querySelectorAll('.pilihan-kanal input')].find(i => !i.disabled)
        return { emailTercentang: Boolean(email?.checked) }
      })()
      `,
    )
    lapor(
      'pilihan email bertahan setelah dimuat ulang',
      sesudahSimpan.emailTercentang === false,
      `email tercentang: ${sesudahSimpan.emailTercentang}`,
    )

    // Server juga harus menyimpan keputusannya, bukan hanya layarnya.
    const dariServer = await panggilApi(ws, '/pengaturan-notifikasi')
    const nilaiServer = dariServer.data?.data?.matriks?.['tarif.diubah']?.email
    lapor(
      'server melaporkan email dimatikan untuk peristiwa itu',
      nilaiServer === false,
      `matriks["tarif.diubah"].email = ${nilaiServer}`,
    )

    // Dan in_app tetap true walaupun ada yang mencoba mengirim false langsung.
    const paksa = await panggilApi(ws, '/pengaturan-notifikasi', 'PUT', {
      matriks: { 'tarif.diubah': { in_app: false, email: false } },
    })
    lapor(
      'server memaksa in_app tetap menyala',
      paksa.status === 200 && paksa.data?.data?.matriks?.['tarif.diubah']?.in_app === true,
      `status ${paksa.status}, in_app ${paksa.data?.data?.matriks?.['tarif.diubah']?.in_app}`,
    )

    // --- Preferensi per akun, bukan per kos ------------------------------
    await masukSebagai(ws, EMAIL_PENGELOLA, SANDI_PENGELOLA)
    const pengaturanPengelola = await panggilApi(ws, '/pengaturan-notifikasi')
    lapor(
      'pengaturan pemilik tidak mendiamkan akun pengelola',
      pengaturanPengelola.data?.data?.matriks?.['tarif.diubah']?.email === true,
      `matriks["tarif.diubah"].email = ${pengaturanPengelola.data?.data?.matriks?.['tarif.diubah']?.email}`,
    )
  } finally {
    const gagal = await uji.selesai()
    if (gagal.length) {
      console.error(`\n${gagal.length} langkah gagal:`)
      for (const g of gagal) console.error(` - ${g.nama}${g.detail ? ': ' + g.detail : ''}`)
    }
  }
})
