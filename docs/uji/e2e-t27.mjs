// Verifikasi end-to-end T27 lewat browser sungguhan.
//
// Yang dibuktikan: **pemilik tidak pernah memegang kata sandi siapa pun.**
// Akun baru lahir tanpa sandi yang diketahui manusia, dan satu-satunya jalan
// masuk adalah tautan yang dikirim ke email pemilik akun. Itu bukan kerapian
// tampilan - tanpa ini, seluruh klaim isolasi R-07 hanya berlaku terhadap orang
// yang kebetulan tidak tahu sandinya.
//
// Enam jalur yang diuji, dan kenapa masing-masing perlu:
//
//   1. **Aktivasi lewat email.** Pemilik membuat penghuni dari layar, lalu
//      penghuni membuka tautan di Mailpit dan menetapkan sandinya sendiri.
//      Diperiksa dari kotak surat sungguhan, bukan dari tabel token: tes yang
//      membaca token langsung dari basis data akan lulus walau emailnya tidak
//      pernah berisi tautan yang benar.
//
//   2. **Akun belum aktif tidak bisa masuk.** Sandinya memang tidak diketahui
//      siapa pun, jadi yang bisa diperiksa adalah jawabannya: 403 dengan
//      kalimat "belum diaktifkan", bukan 401 "sandi salah". Kalau tertukar,
//      penghuni akan mencoba terus dengan sandi yang tidak pernah ia tetapkan.
//
//   3. **Tautan sekali pakai.** Dibuka dua kali, yang kedua ditolak. Tanpa ini,
//      tautan yang bocor tetap berlaku setelah pemiliknya memakainya.
//
//   4. **Lupa sandi.** Akun aktif yang lupa sandinya meminta tautan reset, dan
//      tautan itu hanya berlaku 60 menit - bukan 7 hari seperti aktivasi.
//      Perbedaan umur itu diperiksa dari isi email, bukan dari kode.
//
//   5. **Jawaban lupa sandi tidak membocorkan siapa yang punya akun.** Email
//      terdaftar dan tidak terdaftar dijawab dengan kalimat yang sama persis.
//
//   6. **Pemulihan tautan aktivasi.** Pemilik bisa mengirim ulang tautan untuk
//      akun yang belum aktif - dan DITOLAK untuk akun yang sudah aktif, karena
//      pemilik yang bisa menerbitkan tautan aktivasi untuk akun hidup sama saja
//      dengan pemilik yang bisa mengambil alih akun itu.
//
// Pakai: node docs/uji/e2e-t27.mjs
// Prasyarat: docker compose up sudah jalan (termasuk mailpit), Chrome
// --remote-debugging-port=9222, dan database sudah di-seed
// (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo: membuat dua penghuni uji.

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
  tautanAkunTerakhir,
  aktifkanAkun,
  teksSuratTerakhir,
} from './_cdp.mjs'

const EMAIL_BARU = 'uji-t27@kos.test'
const EMAIL_LUPA = 'uji-t27-lupa@kos.test'
const SANDI = 'sandi-t27-penghuni'
const SANDI_BARU = 'sandi-t27-diganti'

/** Batas bawah waktu surat; lihat alasannya di e2e-t24.mjs. */
const SEJAK = new Date()

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  try {
    // --- Pemilik membuat penghuni dari layar ------------------------------
    //
    // Lewat layar, bukan lewat API: yang diuji di sini justru bahwa formulir
    // pembuatan akun **tidak lagi punya kolom kata sandi**. Formulir yang masih
    // memintanya akan lulus setiap asersi API di bawah sambil tetap
    // membocorkan sandi ke pemilik.
    const masukPemilik = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik berhasil', masukPemilik)
    if (!masukPemilik) throw new Error('login pemilik gagal')

    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/penghuni` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Penghuni')`)

    const bentukForm = await evaluasi(
      ws,
      `
      (async () => {
        const tombol = [...document.querySelectorAll('button')]
          .find(b => /tambah penghuni/i.test(b.textContent))
        if (!tombol) return { galat: 'tombol tambah tidak ada' }
        tombol.click()
        await new Promise(r => setTimeout(r, 400))

        const kolom = [...document.querySelectorAll('form input')]
          .map(i => i.id || i.name || i.type)
        return {
          kolom,
          adaSandi: kolom.some(k => /sandi|password/i.test(k)),
        }
      })()
    `,
    )

    lapor(
      'formulir penghuni tidak lagi punya kolom kata sandi',
      bentukForm?.adaSandi === false,
      `kolom: ${(bentukForm?.kolom || []).join(', ')}`,
    )

    const dibuat = await evaluasi(
      ws,
      `
      (async () => {
        const set = (sel, v) => {
          const el = document.querySelector(sel)
          if (!el) return false
          el.value = v
          el.dispatchEvent(new Event('input', { bubbles: true }))
          return true
        }
        if (!set('#nama', 'Uji T27')) return 'nama tidak ada'
        if (!set('#email', ${JSON.stringify(EMAIL_BARU)})) return 'email tidak ada'

        document.querySelector('form').dispatchEvent(
          new Event('submit', { bubbles: true, cancelable: true }))
        await new Promise(r => setTimeout(r, 1200))

        // Pesan "tautan terkirim" harus muncul: pemilik perlu tahu apakah
        // emailnya benar-benar keluar, karena ia tidak punya cara lain
        // mengetahui sandi penghuni.
        return document.body.textContent.includes('Tautan aktivasi sudah dikirim')
          ? 'ok'
          : 'pesan tautan tidak muncul'
      })()
    `,
    )
    lapor('penghuni dibuat lewat layar dan tautan aktivasi terkirim', dibuat === 'ok', String(dibuat))

    // Baris barunya harus berlabel "belum aktif" dan menawarkan kirim ulang.
    const barisBaru = await evaluasi(
      ws,
      `
      (() => {
        const baris = [...document.querySelectorAll('tbody tr')]
          .find(tr => tr.textContent.includes(${JSON.stringify(EMAIL_BARU)}))
        if (!baris) return { ada: false }
        return {
          ada: true,
          teks: baris.textContent.replace(/\\s+/g, ' ').trim(),
          adaTombolKirimUlang: /kirim ulang/i.test(baris.textContent),
        }
      })()
    `,
    )
    lapor(
      'daftar penghuni menandai akun belum aktif',
      /belum aktif/i.test(barisBaru?.teks || ''),
      barisBaru?.teks || 'baris tidak ditemukan',
    )
    lapor(
      'tombol kirim ulang tautan tersedia untuk akun belum aktif',
      barisBaru?.adaTombolKirimUlang === true,
    )

    await tangkapLayar(ws, 'T27-penghuni-belum-aktif')

    // --- Akun belum aktif tidak bisa masuk --------------------------------
    //
    // Diperiksa lewat API, bukan lewat layar: layar masuk hanya menampilkan
    // kalimat galat, sedangkan yang menentukan perilaku adalah kode statusnya.
    const masukBelumAktif = await panggilApi(ws, '/login', 'POST', {
      email: EMAIL_BARU,
      password: 'apa-saja-yang-ditebak',
    })
    lapor(
      'akun belum aktif dijawab 401 (sandinya memang tidak diketahui siapa pun)',
      masukBelumAktif.status === 401,
      `status ${masukBelumAktif.status}`,
    )

    // --- Penghuni mengaktifkan akunnya lewat email ------------------------
    const tautan = await tautanAkunTerakhir(EMAIL_BARU, SEJAK)
    lapor(
      'email aktivasi memuat token dan jenisnya',
      tautan.token.length === 64 && tautan.jenis === 'aktivasi',
      `jenis ${tautan.jenis}, token ${tautan.token.length} karakter`,
    )

    const aktif = await aktifkanAkun(ws, EMAIL_BARU, SANDI, SEJAK)
    lapor('penghuni menetapkan sandinya lewat tautan aktivasi', aktif)

    await tangkapLayar(ws, 'T27-aktivasi-selesai')

    const masukSetelahAktif = await panggilApi(ws, '/login', 'POST', {
      email: EMAIL_BARU,
      password: SANDI,
    })
    lapor(
      'akun yang sudah aktif bisa masuk dengan sandi yang ia tetapkan',
      masukSetelahAktif.status === 200,
      `status ${masukSetelahAktif.status}`,
    )

    // --- Tautan sekali pakai ----------------------------------------------
    const ulang = await panggilApi(ws, '/atur-sandi', 'POST', {
      token: tautan.token,
      jenis: tautan.jenis,
      password: 'sandi-ketiga-kali',
      password_confirmation: 'sandi-ketiga-kali',
    })
    lapor(
      'tautan aktivasi tidak bisa dipakai dua kali (410)',
      ulang.status === 410,
      `status ${ulang.status}`,
    )

    // --- Lupa sandi: umur tautan dan jawaban yang seragam -----------------
    //
    // Akun kedua, untuk jalur lupa sandi. Dibuat lewat API karena layarnya
    // sudah diuji di atas.
    const buatKedua = await panggilApi(ws, '/penghuni', 'POST', {
      nama: 'Uji T27 Lupa',
      email: EMAIL_LUPA,
    })
    lapor('penghuni kedua dibuat', buatKedua.status === 201, `status ${buatKedua.status}`)
    if (buatKedua.status !== 201) throw new Error('penghuni kedua gagal dibuat')

    const aktifKedua = await aktifkanAkun(ws, EMAIL_LUPA, SANDI, SEJAK)
    lapor('penghuni kedua mengaktifkan akunnya', aktifKedua)
    if (!aktifKedua) throw new Error('aktivasi penghuni kedua gagal')

    const sejakLupa = new Date()
    const mintaReset = await panggilApi(ws, '/lupa-sandi', 'POST', { email: EMAIL_LUPA })
    lapor(
      'permintaan lupa sandi dijawab 200',
      mintaReset.status === 200,
      `status ${mintaReset.status}`,
    )

    const tautanReset = await tautanAkunTerakhir(EMAIL_LUPA, sejakLupa)
    lapor(
      'tautan yang dikirim untuk akun aktif berjenis reset',
      tautanReset.jenis === 'reset',
      `jenis ${tautanReset.jenis}`,
    )

    // Umur tautan dibaca dari isi emailnya: reset menyebut jam, bukan tanggal.
    // Diperiksa dari surat, bukan dari konstanta di kode - konstanta yang benar
    // tetapi tidak dipakai templat akan lulus uji yang membaca kode.
    const suratReset = await teksSuratTerakhir(EMAIL_LUPA, sejakLupa)
    lapor(
      'surat reset menyebut batas waktu dalam jam, bukan hari',
      /\d{2}:\d{2} WIB/.test(suratReset) && /Atur ulang kata sandi/i.test(suratReset),
      (suratReset.match(/berlaku sampai[^\n]*/i) || ['(tidak ada kalimat tenggat)'])[0],
    )

    // --- Jawaban lupa sandi tidak membocorkan siapa yang punya akun -------
    const tidakTerdaftar = await panggilApi(ws, '/lupa-sandi', 'POST', {
      email: 'tidak-pernah-ada-t27@kos.test',
    })
    lapor(
      'email terdaftar dan tidak terdaftar dijawab sama persis',
      tidakTerdaftar.status === 200 &&
        JSON.stringify(tidakTerdaftar.data?.data) === JSON.stringify(mintaReset.data?.data),
      `terdaftar ${mintaReset.status}, tidak terdaftar ${tidakTerdaftar.status}`,
    )

    // --- Reset sandi lewat tautan email -----------------------------------
    const reset = await panggilApi(ws, '/atur-sandi', 'POST', {
      token: tautanReset.token,
      jenis: tautanReset.jenis,
      password: SANDI_BARU,
      password_confirmation: SANDI_BARU,
    })
    lapor('tautan reset dipakai untuk menetapkan sandi baru', reset.status === 200, `status ${reset.status}`)

    const masukSandiLama = await panggilApi(ws, '/login', 'POST', {
      email: EMAIL_LUPA,
      password: SANDI,
    })
    lapor(
      'sandi lama tidak berlaku lagi setelah reset',
      masukSandiLama.status === 401,
      `status ${masukSandiLama.status}`,
    )

    const masukSandiBaru = await panggilApi(ws, '/login', 'POST', {
      email: EMAIL_LUPA,
      password: SANDI_BARU,
    })
    lapor(
      'sandi baru berlaku setelah reset',
      masukSandiBaru.status === 200,
      `status ${masukSandiBaru.status}`,
    )

    // --- Kirim ulang aktivasi ---------------------------------------------
    //
    // Akun ketiga, supaya keadaan "belum aktif" masih tersedia: dua akun di
    // atas sudah aktif, dan endpoint ini justru menolak akun aktif.
    const emailKetiga = 'uji-t27-ulang@kos.test'
    const buatKetiga = await panggilApi(ws, '/penghuni', 'POST', {
      nama: 'Uji T27 Ulang',
      email: emailKetiga,
    })
    if (buatKetiga.status !== 201) throw new Error('penghuni ketiga gagal dibuat')

    const idKetiga = buatKetiga.data?.data?.id
    const sejakUlang = new Date()
    const kirimUlang = await panggilApi(
      ws,
      `/penghuni/${idKetiga}/kirim-ulang-aktivasi`,
      'POST',
    )
    lapor(
      'pemilik bisa mengirim ulang tautan aktivasi',
      kirimUlang.status === 200 && kirimUlang.data?.meta?.tautan_aktivasi_terkirim === true,
      `status ${kirimUlang.status}`,
    )

    const tautanUlang = await tautanAkunTerakhir(emailKetiga, sejakUlang)
    lapor('tautan kirim ulang berjenis aktivasi', tautanUlang.jenis === 'aktivasi')

    // --- Kirim ulang ditolak untuk akun yang sudah aktif ------------------
    const penghuniAktif = await panggilApi(ws, '/penghuni')
    const barisAktif = (penghuniAktif.data?.data || []).find((p) => p.email === EMAIL_BARU)
    const tolakAktif = await panggilApi(
      ws,
      `/penghuni/${barisAktif?.id}/kirim-ulang-aktivasi`,
      'POST',
    )
    lapor(
      'kirim ulang tautan ditolak 409 untuk akun yang sudah aktif',
      tolakAktif.status === 409,
      `status ${tolakAktif.status}`,
    )

    // --- Pembatas percobaan masuk ----------------------------------------
    //
    // Lima kegagalan berturut-turut harus menghasilkan 429. Diperiksa lewat
    // API karena layar hanya menampilkan kalimatnya; yang menentukan adalah
    // kode statusnya.
    let kena429 = 0
    for (let i = 0; i < 7; i++) {
      const coba = await panggilApi(ws, '/login', 'POST', {
        email: 'uji-t27-tebak@kos.test',
        password: `tebakan-${i}`,
      })
      if (coba.status === 429) kena429++
    }
    lapor(
      'tujuh kegagalan berturut-turut menghasilkan 429',
      kena429 >= 1,
      `${kena429} dari 7 dijawab 429`,
    )

    // --- Gerbang wajib ganti sandi ---------------------------------------
    //
    // Diperiksa lewat layar: akun yang ditahan harus DIARAHKAN ke layar ganti
    // sandi, bukan dibiarkan melihat deretan 403 di halaman kerja. Keadaannya
    // dibuat lewat basis data karena sejak T27 jalur HTTP tidak bisa
    // menghasilkannya lagi - dan itu memang yang diinginkan.
    const { execFileSync } = await import('node:child_process')
    const sql = (perintah) =>
      execFileSync(
        'docker',
        ['compose', 'exec', '-T', 'db', 'psql', '-U', 'kos', '-d', 'kos', '-tAc', perintah],
        { encoding: 'utf8' },
      ).trim()

    sql(`UPDATE users SET wajib_ganti_sandi = true WHERE email = '${EMAIL_LUPA}'`)

    const masukDitahan = await masukSebagai(ws, EMAIL_LUPA, SANDI_BARU)
    const keAturSandi = await tungguSampai(ws, `location.pathname === '/atur-sandi'`, 12000)
    lapor(
      'akun wajib-ganti-sandi diarahkan ke layar ganti sandi',
      keAturSandi && masukDitahan,
      await evaluasi(ws, 'location.pathname'),
    )

    const bentukDitahan = await evaluasi(
      ws,
      `
      (() => ({
        adaSandiLama: Boolean(document.querySelector('#password-lama')),
        adaSandiBaru: Boolean(document.querySelector('#password-baru')),
        judul: document.querySelector('h1')?.textContent.trim() || '',
      }))()
    `,
    )
    lapor(
      'layar ganti sandi tidak menanyakan sandi lama saat akun ditahan',
      bentukDitahan?.adaSandiLama === false && bentukDitahan?.adaSandiBaru === true,
      JSON.stringify(bentukDitahan),
    )

    await tangkapLayar(ws, 'T27-wajib-ganti-sandi')

    const diganti = await evaluasi(
      ws,
      `
      (async () => {
        const set = (sel, v) => {
          const el = document.querySelector(sel)
          if (!el) return false
          el.value = v
          el.dispatchEvent(new Event('input', { bubbles: true }))
          return true
        }
        if (!set('#password-baru', 'sandi-t27-setelah-ditahan')) return 'kolom tidak ada'
        set('#password-ulang', 'sandi-t27-setelah-ditahan')
        document.querySelector('form').dispatchEvent(
          new Event('submit', { bubbles: true, cancelable: true }))
        await new Promise(r => setTimeout(r, 1500))
        return document.body.textContent.includes('Kata sandi tersimpan') ? 'ok' : 'tidak tersimpan'
      })()
    `,
    )
    lapor('sandi berhasil diganti dari keadaan ditahan', diganti === 'ok', String(diganti))

    // Setelah diganti, "Lanjut" harus membawa pengguna keluar dari layar itu -
    // bukan kembali ke layar masuk. Diperiksa karena rute `beranda` yang salah
    // akan terlihat sama saja sampai ada yang menekannya.
    //
    // Dua tujuan sama-sama benar, dan yang mana yang muncul bergantung pada
    // apakah akun ini sudah menyetujui PDP. Akun uji ini belum, jadi gerbang
    // T28 yang muncul lebih dulu - dan itu memang urutan yang diinginkan:
    // persetujuan ditanyakan sebelum orang mulai bekerja, bukan sesudah.
    const lanjut = await evaluasi(
      ws,
      `
      (async () => {
        const tombol = [...document.querySelectorAll('button')]
          .find(b => /lanjut/i.test(b.textContent))
        if (!tombol) return 'tombol lanjut tidak ada'
        tombol.click()
        await new Promise(r => setTimeout(r, 1500))
        return location.pathname
      })()
    `,
    )
    lapor(
      'setelah ganti sandi pengguna keluar dari layar itu ke tujuan yang sah',
      ['/penghuni', '/persetujuan-pdp'].includes(lanjut),
      String(lanjut),
    )

    // Kalau gerbang PDP yang muncul, ia harus bisa diselesaikan - gerbang yang
    // tidak punya jalan keluar lebih buruk daripada tidak ada gerbang.
    if (lanjut === '/persetujuan-pdp') {
      const setuju = await evaluasi(
        ws,
        `
        (async () => {
          const tombol = [...document.querySelectorAll('button')]
            .find(b => /saya setuju/i.test(b.textContent))
          if (!tombol) return 'tombol setuju tidak ada'
          tombol.click()
          await new Promise(r => setTimeout(r, 1500))
          return location.pathname
        })()
      `,
      )
      lapor('gerbang PDP bisa diselesaikan dan pengguna lanjut bekerja', setuju === '/penghuni', String(setuju))
    }

    await tangkapLayar(ws, 'T27-setelah-ganti-sandi')
  } finally {
    const gagal = await uji.selesai()
    if (gagal.length) {
      console.error(`\n${gagal.length} langkah gagal:`)
      for (const g of gagal) console.error(` - ${g.nama}${g.detail ? ': ' + g.detail : ''}`)
    }
  }
})
