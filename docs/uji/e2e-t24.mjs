// Verifikasi end-to-end T24 lewat browser sungguhan.
//
// Yang dibuktikan: pemilik dapat memberi akun pengelola beserta wewenangnya,
// pengelola bisa masuk dan bekerja sebatas wewenangnya, dan yang paling
// penting - pembatasan itu ditegakkan SERVER, bukan hanya disembunyikan
// layar. Layar yang menyembunyikan tombol bukan pengamanan: alamatnya bisa
// dibuka langsung, dan permintaannya bisa dikirim tanpa lewat layar.
//
// Urutan yang disengaja: setiap penolakan diuji dengan MENEMBAK API langsung
// memakai token pengelola yang sedang aktif di peramban. Kalau hanya diperiksa
// bahwa tombolnya tidak ada, uji ini akan lulus pada aplikasi yang sama sekali
// tidak memeriksa wewenang di server.
//
// Pakai: node docs/uji/e2e-t24.mjs
// Prasyarat: docker compose up sudah jalan, Chrome --remote-debugging-port=9222,
// APP_DEMO_MODE=true, dan database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo: membuat akun pengelola baru.

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

const EMAIL_BARU = 'uji-t24@kos.test'

/**
 * Sandi yang ditetapkan SENDIRI oleh pengelola uji lewat tautan aktivasi.
 *
 * Sejak T27 pemilik tidak mengetikkan sandi siapa pun, jadi tidak ada sandi
 * yang bisa dipakai di sini kecuali yang ditetapkan pemilik akunnya sendiri.
 * Skrip ini berperan sebagai pengelola pada detik tautannya dibuka.
 */
const SANDI_PENGELOLA = 'sandi-t24-pengelola'

/**
 * Batas bawah waktu surat.
 *
 * Mailpit tidak dikosongkan `periksa.ps1` di antara skrip, sedangkan basis data
 * di-reset. Tanpa batas ini, `aktifkanAkun()` bisa membaca tautan dari jalan
 * sebelumnya - token yang sudah tidak dikenal server - lalu melaporkan tautan
 * mati padahal surat barunya ada.
 */
const SEJAK = new Date()

/**
 * Menyiapkan satu kamar, satu penghuni, dan satu kontrak aktif.
 *
 * Idempoten: dicari dulu, dibuat hanya bila belum ada. Skrip E2E dijalankan
 * berulang tanpa `migrate:fresh`, dan membuat kamar bernomor sama dua kali akan
 * ditolak unique index - kegagalan yang tidak mengatakan apa pun tentang T24.
 *
 * `tgl_masuk` 2026-01-01 supaya tagihan periode Maret punya tarif yang berlaku
 * dan bukan prorata bulan masuk.
 */
async function siapkanDataInduk(ws) {
  const kamarAda = await panggilApi(ws, '/kamar')

  if (kamarAda.status !== 200) {
    return { ok: false, alasan: `GET /kamar gagal (${kamarAda.status})` }
  }

  let kamar = (kamarAda.data?.data || []).find((k) => k.nomor === 'T24-1')

  if (!kamar) {
    const buat = await panggilApi(ws, '/kamar', 'POST', { nomor: 'T24-1' })
    if (buat.status !== 201) {
      return { ok: false, alasan: `POST /kamar gagal (${buat.status})` }
    }
    kamar = buat.data.data
  }

  // Tarif harus ada sebelum tagihan bisa terbit, dan `POST /kontrak` menolak
  // kontrak tanpa tarif. Diperiksa dari `tarif_terakhir` di daftar kamar, bukan
  // dari `/tarif-berlaku`: endpoint itu menjawab bentuk yang berbeda dan
  // ketiadaan tarif tidak selalu terlihat sebagai nilai kosong.
  if (!kamar.tarif_terakhir) {
    const buatTarif = await panggilApi(ws, '/tarif', 'POST', {
      kamar_id: kamar.id,
      nilai: 1500000,
      berlaku_dari: '2026-01-01',
    })
    if (buatTarif.status !== 201 && buatTarif.status !== 422) {
      return { ok: false, alasan: `POST /tarif gagal (${buatTarif.status})` }
    }
  }

  const penghuniAda = await panggilApi(ws, '/penghuni')
  let penghuni = (penghuniAda.data?.data || []).find((p) => p.email === 'uji-t24-penghuni@kos.test')

  if (!penghuni) {
    // Tanpa kata sandi: sejak T27 pemilik tidak mengetikkan sandi siapa pun.
    // Akunnya lahir dengan sandi acak yang tidak diketahui siapa pun; skrip ini
    // tidak memakai akun itu untuk masuk, jadi tautan aktivasinya tidak perlu
    // dibuka.
    const buat = await panggilApi(ws, '/penghuni', 'POST', {
      nama: 'Penghuni Uji T24',
      email: 'uji-t24-penghuni@kos.test',
    })
    if (buat.status !== 201) {
      return { ok: false, alasan: `POST /penghuni gagal (${buat.status})` }
    }
    penghuni = buat.data.data
  }

  const kontrakAda = await panggilApi(ws, '/kontrak')
  const kontrak = (kontrakAda.data?.data || []).find(
    (k) => k.status === 'aktif' && k.kamar_id === kamar.id,
  )

  if (kontrak) {
    return { ok: true, alasan: 'sudah ada, dipakai ulang' }
  }

  const buatKontrak = await panggilApi(ws, '/kontrak', 'POST', {
    kamar_id: kamar.id,
    penghuni_id: penghuni.id,
    tgl_masuk: '2026-01-01',
    deposit: 0,
  })

  if (buatKontrak.status !== 201) {
    return { ok: false, alasan: `POST /kontrak gagal (${buatKontrak.status})` }
  }

  return { ok: true, alasan: 'dibuat' }
}

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  try {
    // --- Pemilik membuat akun pengelola -----------------------------------
    const masukPemilik = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik berhasil', masukPemilik)
    if (!masukPemilik) throw new Error('login pemilik gagal')

    const kebijakan = await pastikanKebijakan(ws)
    lapor('kebijakan kos siap', kebijakan.ok, kebijakan.alasan)
    if (!kebijakan.ok) throw new Error('kebijakan gagal disiapkan')

    // Seeder sengaja tidak membuat kamar maupun kontrak: kos sungguhan dimulai
    // kosong, dan data contoh yang sudah berisi membuat pemilik tidak pernah
    // melewati langkah pertama. Akibatnya skrip ini harus menyiapkannya
    // sendiri - dan itu memang bagian dari yang diuji: pengelola harus bisa
    // bekerja pada data yang dibuat pemilik, bukan pada data bawaan.
    const siapkan = await siapkanDataInduk(ws)
    lapor('data induk siap (kamar, penghuni, kontrak)', siapkan.ok, siapkan.alasan)
    if (!siapkan.ok) throw new Error('data induk gagal disiapkan')

    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/pengelola` })
    const halamanTerbuka = await tungguSampai(
      ws,
      `document.querySelector('h1')?.textContent.includes('Pengelola')`,
    )
    lapor('halaman Pengelola terbuka untuk pemilik', halamanTerbuka)

    const adaMenu = await tungguSampai(
      ws,
      `[...document.querySelectorAll('nav a')].some(a => a.textContent.includes('Pengelola'))`,
      6000,
    )
    lapor('menu Pengelola ada di navigasi pemilik', adaMenu)

    // Akun dengan email uji bisa tertinggal dari percobaan sebelumnya. Dihapus
    // lebih dulu supaya skrip boleh dijalankan berulang tanpa migrate:fresh -
    // sama seperti asap-semua-halaman.mjs yang mencari sebelum membuat.
    const daftarAwal = await panggilApi(ws, '/pengelola')
    const sisa = (daftarAwal.data?.data || []).find((p) => p.email === EMAIL_BARU)
    if (sisa) {
      await panggilApi(ws, `/pengelola/${sisa.id}`, 'DELETE')
      await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/pengelola` })
      await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Pengelola')`)
    }

    // --- Membuat pengelola lewat formulir ---------------------------------
    //
    // Formulir dibuka lebih dulu, lalu diperiksa bentuknya, baru diisi. Urutan
    // ini penting: fieldset wewenang hanya ada di dalam formulir, jadi
    // memeriksanya sebelum formulir dibuka selalu menghasilkan nol - dan uji
    // yang memeriksa sesuatu yang belum ada akan gagal atau lulus tanpa
    // mengukur apa pun, tergantung asersinya.
    const dibuka = await evaluasi(
      ws,
      `
      (async () => {
        const tombol = [...document.querySelectorAll('button')]
          .find(b => /tambah pengelola/i.test(b.textContent))
        if (!tombol) return 'tombol tambah tidak ada'
        tombol.click()
        await new Promise(r => setTimeout(r, 400))
        return 'ok'
      })()
    `,
    )
    lapor('formulir pengelola terbuka', dibuka === 'ok', String(dibuka))

    // Dua kelompok wewenang harus tampil terpisah: yang menyentuh uang diberi
    // penanda sendiri. Kalau keduanya tercampur, pemilik tidak punya cara
    // melihat bahwa satu centang mengubah tarif seluruh kamar.
    const adaDuaKelompok = await evaluasi(
      ws,
      `document.querySelectorAll('fieldset.kelompok').length === 2`,
    )
    lapor('wewenang tampil dalam dua kelompok (harian dan menyentuh uang)', adaDuaKelompok)

    const adaKelompokSensitif = await evaluasi(
      ws,
      `Boolean(document.querySelector('fieldset.kelompok-sensitif'))`,
    )
    lapor('kelompok wewenang sensitif ditandai', adaKelompokSensitif)

    const jumlahPilihan = await evaluasi(ws, `document.querySelectorAll('label.pilihan').length`)
    lapor('seluruh delapan wewenang ditampilkan', jumlahPilihan === 8, `${jumlahPilihan} kotak`)

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

        if (!set('#nama-pengelola', 'Uji T24')) return 'nama tidak ada'
        if (!set('#email-pengelola', ${JSON.stringify(EMAIL_BARU)})) return 'email tidak ada'

        // Diklik satu per satu, dengan jeda satu putaran penuh di antaranya -
        // meniru orang, bukan menembak dua event dalam satu tick.
        //
        // Alasannya bukan gaya penulisan: v-model pada kotak centang menyimpan
        // array nilai di properti elemen saat render, dan dua event change yang
        // jatuh sebelum Vue sempat render ulang sama-sama membaca array LAMA.
        // Hasilnya centang kedua menimpa yang pertama, dan formulir mengirim
        // satu wewenang alih-alih dua - kegagalan yang tidak berbunyi dan
        // sempat membuat uji ini gagal karena alasan yang tidak ada hubungannya
        // dengan wewenang.
        const klik = async (teks) => {
          const label = [...document.querySelectorAll('label.pilihan')]
            .find(l => l.textContent.includes(teks))
          if (!label) return false
          label.querySelector('input[type=checkbox]').click()
          await new Promise(r => setTimeout(r, 120))
          return label.querySelector('input[type=checkbox]').checked
        }

        // Hanya dua wewenang harian. Sengaja TIDAK mencentang ubah_tarif:
        // seluruh pembuktian di bawah bergantung pada akun yang memang tidak
        // punya izin itu.
        if (!(await klik('Mencatat pembayaran'))) return 'centang catat_pembayaran gagal'
        if (!(await klik('Menerbitkan tagihan'))) return 'centang terbitkan_tagihan gagal'

        document.querySelector('form').dispatchEvent(
          new Event('submit', { bubbles: true, cancelable: true }))
        await new Promise(r => setTimeout(r, 900))
        return 'ok'
      })()
    `,
    )
    lapor('formulir pengelola terkirim', dibuat === 'ok', String(dibuat))

    const sesudahBuat = await panggilApi(ws, '/pengelola')
    const baru = (sesudahBuat.data?.data || []).find((p) => p.email === EMAIL_BARU)
    lapor('akun pengelola tersimpan', Boolean(baru), baru ? `id ${baru.id}` : 'tidak ditemukan')

    if (!baru) throw new Error('akun pengelola tidak terbentuk')

    const wewenangBenar =
      baru.wewenang.length === 2 &&
      baru.wewenang.includes('catat_pembayaran') &&
      baru.wewenang.includes('terbitkan_tagihan')
    lapor('wewenang tersimpan tepat dua, tanpa ubah_tarif', wewenangBenar, baru.wewenang.join(', '))

    await tangkapLayar(ws, 'T24-pengelola')

    // --- Pengelola mengaktifkan akunnya sendiri ----------------------------
    //
    // Sejak T27 pemilik tidak memegang sandi pengelola. Yang membuktikan akun
    // ini benar-benar bisa dipakai bukan "pemilik tahu sandinya", melainkan
    // bahwa tautan aktivasi yang dikirim ke emailnya berfungsi. Sandi yang
    // dipakai di sini hanya diketahui skrip dan pengelola itu sendiri.
    const aktif = await aktifkanAkun(ws, EMAIL_BARU, SANDI_PENGELOLA, SEJAK)
    lapor('pengelola mengaktifkan akunnya lewat tautan email', aktif)

    const sudahAktif = await panggilApi(ws, '/pengelola')
    const baris = (sudahAktif.data?.data || []).find((p) => p.email === EMAIL_BARU)
    lapor('daftar pengelola menandai akun sudah aktif', baris?.sudah_aktif === true)

    // --- Pengelola masuk ---------------------------------------------------
    const masukPengelola = await masukSebagai(ws, EMAIL_BARU, SANDI_PENGELOLA)
    lapor('pengelola bisa masuk dengan kata sandi yang ia tetapkan sendiri', masukPengelola)
    if (!masukPengelola) throw new Error('login pengelola gagal')

    // Akun yang baru diaktifkan mendarat di gerbang persetujuan PDP (T28).
    // Skrip ini menguji wewenang, bukan gerbang itu.
    const lolosPdp = await lewatiPersetujuanPdp(ws)
    lapor('gerbang persetujuan PDP dilewati', lolosPdp, await evaluasi(ws, 'location.pathname'))

    // Pengelola diarahkan ke Tagihan, bukan ke Kamar: kamar bukan pekerjaan
    // hariannya, dan mendarat di halaman yang tidak boleh ia ubah membuat
    // kesan pertama terlihat seperti aplikasi rusak.
    await tungguSampai(ws, `location.pathname === '/pemilik/tagihan'`, 8000)
    const mendarat = await evaluasi(ws, `location.pathname`)
    lapor('pengelola diarahkan ke halaman Tagihan', mendarat === '/pemilik/tagihan', mendarat)

    // --- Menu menyesuaikan wewenang ---------------------------------------
    const menu = await evaluasi(
      ws,
      `[...document.querySelectorAll('nav a')].map(a => a.textContent.trim())`,
    )
    const sebutan = await evaluasi(ws, `document.querySelector('.merek p')?.textContent.trim()`)
    lapor('sidebar menyebut peran Pengelola, bukan Pemilik', sebutan === 'Pengelola', sebutan)

    const adaTagihanMenu = menu.some((m) => m.includes('Tagihan'))
    const adaKebijakanMenu = menu.some((m) => m.includes('Kebijakan'))
    const adaPengelolaMenu = menu.some((m) => m.includes('Pengelola'))
    const adaAuditMenu = menu.some((m) => m.includes('Jejak audit'))

    lapor('menu Tagihan tetap ada untuk pengelola', adaTagihanMenu)
    lapor('menu Kebijakan disembunyikan (tanpa ubah_kebijakan)', !adaKebijakanMenu)
    lapor('menu Pengelola disembunyikan (hanya pemilik)', !adaPengelolaMenu)
    lapor('menu Jejak audit disembunyikan (tanpa baca_audit)', !adaAuditMenu)

    // --- Pembatasan ditegakkan SERVER, bukan layar ------------------------
    //
    // Empat tembakan langsung ke API dengan token pengelola yang aktif. Kalau
    // hanya tombolnya yang disembunyikan, keempat ini akan berhasil dan uji
    // di atas tetap hijau - persis kegagalan yang ingin dicegah.
    const tembakTarif = await panggilApi(ws, '/tarif', 'POST', {
      kamar_id: 1,
      nilai: 9999999,
      berlaku_dari: '2026-07-01',
    })
    lapor('POST /tarif ditolak 403 untuk pengelola tanpa ubah_tarif', tembakTarif.status === 403, `status ${tembakTarif.status}`)

    const tembakKebijakan = await panggilApi(ws, '/kebijakan', 'POST', {
      tipe: 'satuan_sewa',
      nilai: 'bulanan',
      berlaku_dari: '2026-07-01',
    })
    lapor(
      'POST /kebijakan ditolak 403 untuk pengelola tanpa ubah_kebijakan',
      tembakKebijakan.status === 403,
      `status ${tembakKebijakan.status}`,
    )

    const tembakKamar = await panggilApi(ws, '/kamar', 'POST', { nomor: 'ZZ9' })
    lapor(
      'POST /kamar ditolak 403 untuk pengelola tanpa kelola_kamar',
      tembakKamar.status === 403,
      `status ${tembakKamar.status}`,
    )

    const tembakPengelola = await panggilApi(ws, '/pengelola', 'POST', {
      nama: 'Penyusup',
      email: 'penyusup@kos.test',
      password: 'password',
      wewenang: ['ubah_tarif'],
    })
    lapor(
      'POST /pengelola ditolak 403 (memberi wewenang tidak bisa didelegasikan)',
      tembakPengelola.status === 403,
      `status ${tembakPengelola.status}`,
    )

    // Membaca TIDAK dibatasi: orang yang mencatat pembayaran memang harus bisa
    // melihat nama penghuni dan nomor kamar. Wewenang membatasi tindakan,
    // bukan pengetahuan.
    const bacaKamar = await panggilApi(ws, '/kamar')
    lapor('GET /kamar tetap boleh untuk pengelola', bacaKamar.status === 200, `status ${bacaKamar.status}`)

    const bacaTagihan = await panggilApi(ws, '/tagihan')
    lapor('GET /tagihan tetap boleh untuk pengelola', bacaTagihan.status === 200, `status ${bacaTagihan.status}`)

    // --- Rekap ditutup, tunai-saya dibuka (REV-SEC-06) --------------------
    //
    // Batasnya sengaja asimetris. Rekap adalah gambaran utuh keuangan kos -
    // pendapatan per metode, piutang denda, setoran tiap pemegang - dan
    // pengelola yang membacanya mengetahui penghasilan pemiliknya tanpa pernah
    // diminta. Yang dibutuhkan pekerjaannya adalah "berapa uang tunai yang saya
    // pegang", dan itu dijawab endpoint yang jauh lebih sempit.
    const bacaRekap = await panggilApi(ws, '/rekap?dari=2026-01-01&sampai=2026-12-31')
    lapor('GET /rekap ditolak 403 untuk pengelola', bacaRekap.status === 403, `status ${bacaRekap.status}`)

    const bacaTunaiSaya = await panggilApi(ws, '/pembayaran/tunai-saya?dari=2026-01-01&sampai=2026-12-31')
    lapor(
      'GET /pembayaran/tunai-saya tetap boleh untuk pengelola',
      bacaTunaiSaya.status === 200,
      `status ${bacaTunaiSaya.status}`,
    )
    lapor(
      'tunai-saya hanya memuat angka milik pemanggil, bukan rekap kos',
      bacaTunaiSaya.status === 200 &&
        bacaTunaiSaya.data?.data?.per_metode === undefined &&
        bacaTunaiSaya.data?.data?.piutang === undefined,
      JSON.stringify(bacaTunaiSaya.data?.data ?? {}).slice(0, 90),
    )

    const bacaPembayaran = await panggilApi(ws, '/pembayaran?dari=2026-01-01&sampai=2026-12-31')
    lapor(
      'GET /pembayaran tetap boleh untuk pengelola',
      bacaPembayaran.status === 200,
      `status ${bacaPembayaran.status}`,
    )

    // Halaman Rekap tetap terbuka untuk pengelola, tetapi dengan wajah yang
    // berbeda: judulnya menyebut uang tunai miliknya, bukan pendapatan kos.
    // Menu yang menjanjikan "Rekap" lalu menampilkan halaman kosong akan
    // membuat pengelola mengira aplikasinya rusak.
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/rekap` })
    const judulRekapSempit = await tungguSampai(
      ws,
      `document.querySelector('h1')?.textContent.includes('Uang tunai saya')`,
    )
    lapor('halaman rekap pengelola berjudul "Uang tunai saya"', judulRekapSempit)

    const adaMenuTunaiSaya = await evaluasi(
      ws,
      `[...document.querySelectorAll('nav a')].some((a) => a.textContent.includes('Tunai saya'))`,
    )
    lapor('menu untuk pengelola berbunyi "Tunai saya", bukan "Rekap"', adaMenuTunaiSaya)

    // --- Kebijakan hanya-baca ---------------------------------------------
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/kebijakan` })
    const kebijakanTerbuka = await tungguSampai(
      ws,
      `document.querySelector('h1')?.textContent.includes('Kebijakan')`,
    )
    lapor('pengelola tetap bisa MEMBACA halaman Kebijakan', kebijakanTerbuka)

    const adaFormKebijakan = await evaluasi(
      ws,
      `document.querySelectorAll('form.bidang').length > 0`,
    )
    lapor('formulir kebijakan tidak dirender untuk pengelola', !adaFormKebijakan)

    const adaPeringatan = await evaluasi(
      ws,
      `document.body.textContent.includes('wewenang pemilik')`,
    )
    lapor('halaman menjelaskan mengapa formulirnya tidak ada', adaPeringatan)

    await tangkapLayar(ws, 'T24-kebijakan-hanya-baca')

    // --- Pengelola mencatat pembayaran ------------------------------------
    //
    // Inilah pekerjaan yang memang boleh ia lakukan, dan harus benar-benar
    // bisa. Membatasi tanpa menyisakan jalan kerja bukan pembatasan, itu
    // aplikasi yang rusak.
    //
    // Tagihannya diterbitkan sendiri oleh pengelola, bukan disiapkan pemilik
    // lebih dulu: menerbitkan tagihan termasuk wewenang yang ia punya, jadi
    // satu urutan ini menguji dua wewenang sekaligus - dan menguji keduanya
    // pada akun yang sama, yang memang begitulah keadaannya di lapangan.
    const kontrak = await panggilApi(ws, '/kontrak')
    const kontrakAktif = (kontrak.data?.data || []).find((k) => k.status === 'aktif')

    if (!kontrakAktif) {
      lapor('pengelola bisa menerbitkan tagihan', false, 'tidak ada kontrak aktif di database demo')
      lapor('pengelola bisa mencatat pembayaran', false, 'tidak ada kontrak aktif')
    } else {
      const terbit = await panggilApi(ws, '/tagihan', 'POST', {
        kontrak_id: kontrakAktif.id,
        periode: '2026-03-01',
      })
      lapor(
        'pengelola dengan terbitkan_tagihan bisa menerbitkan tagihan',
        terbit.status === 201,
        `status ${terbit.status}`,
      )

      const idTagihan = terbit.data?.data?.id

      if (idTagihan) {
        const bayar = await panggilApi(ws, `/tagihan/${idTagihan}/pembayaran`, 'POST', {
          jumlah: 1000,
          tanggal_pembayaran: '2026-03-05',
          metode: 'tunai',
        })
        lapor(
          'pengelola dengan catat_pembayaran bisa mencatat pembayaran',
          bayar.status === 201 || bayar.status === 200,
          `status ${bayar.status}`,
        )
      } else {
        lapor('pengelola dengan catat_pembayaran bisa mencatat pembayaran', false, 'tagihan tidak terbentuk')
      }
    }

    // --- Jejak audit menyebut pengelola, bukan pemilik ---------------------
    await masukSebagai(ws, 'pemilik@kos.test')
    const audit = await panggilApi(ws, '/audit-log?aksi=pengelola.tambah')
    // Dicocokkan lewat `entitas_id`, bukan lewat email di `nilai_baru`: sejak
    // T28 jejak akun tidak lagi menyalin nama dan email subjeknya, karena
    // salinan itu tinggal di tabel yang tidak bisa diubah dan karenanya tidak
    // akan pernah ikut terlepas saat penghuninya menghapus akun.
    const jejakPengelola = (audit.data?.data || []).find(
      (j) => Number(j.entitas_id) === Number(baru.id),
    )
    lapor(
      'pembuatan akun pengelola tercatat di jejak audit',
      Boolean(jejakPengelola),
      jejakPengelola ? `oleh ${jejakPengelola.aktor_nama}` : 'tidak ditemukan',
    )

    // Nama aksinya `tagihan.bayar`, bukan `pembayaran.tambah`: yang dicatat
    // adalah kejadian pada tagihannya (status berubah, sisa berkurang), bukan
    // baris pembayarannya. Menebak namanya dari nama tabel adalah cara paling
    // mudah menulis asersi yang selalu gagal.
    const jejakBayar = await panggilApi(ws, '/audit-log?aksi=tagihan.bayar')
    const adaJejakBayar = (jejakBayar.data?.data || []).some((j) => j.aktor_nama === 'Uji T24')
    lapor('pembayaran yang dicatat pengelola menyebut namanya sendiri', adaJejakBayar)

    // --- Mencabut akses ----------------------------------------------------
    const cabut = await panggilApi(ws, `/pengelola/${baru.id}`, 'DELETE')
    lapor('pemilik bisa mencabut akses pengelola', cabut.status === 200, `status ${cabut.status}`)

    // Token yang dipegang pengelola ikut mati: permintaan berikutnya harus 401,
    // bukan 403. Bedanya penting - 401 berarti akunnya sudah tidak berlaku,
    // 403 berarti akunnya masih sah tetapi tidak berwenang.
    const tokenPalsu = JSON.stringify('token-palsu-t24')
    await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
    await evaluasi(ws, `localStorage.setItem('kos.token', ${tokenPalsu})`)
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tagihan` })
    await tungguSampai(ws, `location.pathname.startsWith('/masuk')`, 8000)
    const diusir = await evaluasi(ws, `location.pathname.startsWith('/masuk')`)
    lapor('token yang dicabut tidak bisa dipakai lagi', diusir)
  } finally {
    const gagal = await uji.selesai()
    if (gagal.length) {
      console.error(`\n${gagal.length} langkah gagal:`)
      for (const g of gagal) console.error(` - ${g.nama}${g.detail ? ': ' + g.detail : ''}`)
    }
  }
})
