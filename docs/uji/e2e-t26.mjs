// Verifikasi end-to-end T26 lewat browser sungguhan.
//
// Yang dibuktikan: pengelola yang memegang `ubah_tarif` TIDAK lagi bisa
// mengubah tarif. Ia mengusulkan, usulannya masuk antrean, dan pemilik yang
// memutuskan. Yang paling penting dibuktikan di sini bukan tampilannya,
// melainkan bahwa `POST /tarif` dari akun pengelola benar-benar tidak menulis
// baris tarif - kalau hanya tombolnya yang berbunyi lain, uji ini akan lulus
// pada aplikasi yang tetap menaikkan tarif di belakang layar.
//
// Empat jalur yang diuji, dan kenapa keempatnya perlu:
//
//   1. Usulan disetujui   - jalur normal. Membuktikan 202 bukan 201, dan bahwa
//                           baris tarif baru benar-benar lahir saat disetujui,
//                           bukan saat diajukan.
//   2. Usulan ditolak     - penolakan wajib beralasan. Tanpa alasan, pengelola
//                           mengajukan ulang hal yang sama persis.
//   3. Usulan kedaluwarsa - lewat 7 hari, ditutup tanpa cron. Kedaluwarsa
//                           adalah keadaan yang DIHITUNG, bukan kolom, jadi
//                           satu-satunya cara mengujinya adalah memundurkan
//                           tenggatnya langsung di basis data. Tidak ada
//                           endpoint untuk itu, dan itu memang disengaja.
//   4. Pengelola membaca  - ia boleh melihat nasib usulannya sendiri, tetapi
//                           tombol keputusannya tidak pernah dirender.
//
// Pakai: node docs/uji/e2e-t26.mjs
// Prasyarat: docker compose up sudah jalan, Chrome --remote-debugging-port=9222,
// APP_DEMO_MODE=true, dan database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo: membuat akun pengelola, kamar, dan usulan.

import { execFileSync } from 'node:child_process'
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
} from './_cdp.mjs'

const EMAIL_PENGELOLA = 'uji-t26@kos.test'
const NOMOR_KAMAR = 'T26-1'

/**
 * Menjalankan satu perintah SQL di container basis data.
 *
 * Dipakai HANYA untuk jalur kedaluwarsa. Tenggat usulan ditetapkan server
 * tujuh hari ke depan, dan tidak ada endpoint yang bisa memundurkannya - itu
 * memang yang diinginkan, karena endpoint semacam itu akan menjadi cara
 * mematikan usulan yang tidak disukai tanpa jejak. Karena tidak ada jalur sah
 * untuk menghasilkan usulan yang sudah lewat, satu-satunya cara menguji
 * perilakunya adalah menggeser barisnya langsung.
 *
 * Tanpa shell: argumen dikirim sebagai daftar, jadi tidak ada kutip yang perlu
 * diloloskan dan tidak ada `$` yang dimakan PowerShell.
 */
function sql(perintah) {
  return execFileSync(
    'docker',
    ['compose', 'exec', '-T', 'db', 'psql', '-U', 'kos', '-d', 'kos', '-tAc', perintah],
    { encoding: 'utf8' },
  ).trim()
}

/**
 * Menyiapkan kamar beserta satu tarif awal.
 *
 * Idempoten: dicari dulu, dibuat hanya bila belum ada. Skrip E2E dijalankan
 * berulang tanpa `migrate:fresh`, dan kamar bernomor sama dua kali ditolak
 * unique index - kegagalan yang tidak mengatakan apa pun tentang T26.
 */
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

/** Daftar tarif satu kamar, diurutkan menurut tanggal berlaku. */
async function tarifKamar(ws, kamarId) {
  const hasil = await panggilApi(ws, `/tarif?kamar_id=${kamarId}`)
  return (hasil.data?.data || [])
    .map((t) => ({ nilai: Number(t.nilai), berlaku_dari: t.berlaku_dari }))
    .sort((a, b) => a.berlaku_dari.localeCompare(b.berlaku_dari))
}

/**
 * Tanggal berlaku yang belum dipakai kamar ini.
 *
 * Kenapa tidak menulis tanggal tetap. `tarif_sewa` bersifat append-only, jadi
 * tanggal yang dipakai satu kali tidak bisa dipakai lagi selamanya. Skrip yang
 * menulis "2026-09-01" akan lulus pada `migrate:fresh` dan gagal 422 pada
 * jalan kedua - kegagalan yang terbaca seperti kerusakan T26 padahal hanya
 * tanggal yang sudah terpakai. Dicari dari daftar yang ada, bukan ditebak.
 */
function tanggalBebas(tarifAda) {
  const dipakai = new Set(tarifAda.map((t) => t.berlaku_dari))
  let tahun = 2026
  let bulan = 9

  for (let i = 0; i < 60; i += 1) {
    const calon = `${tahun}-${String(bulan).padStart(2, '0')}-01`
    if (!dipakai.has(calon)) return calon
    bulan += 1
    if (bulan > 12) {
      bulan = 1
      tahun += 1
    }
  }

  throw new Error('tidak menemukan tanggal berlaku yang bebas')
}

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

    const siap = await siapkanKamar(ws)
    lapor('kamar dan tarif awal siap', siap.ok, siap.alasan || `kamar ${siap.kamar?.id}`)
    if (!siap.ok) throw new Error('kamar gagal disiapkan')

    const kamar = siap.kamar
    const tarifAwal = await tarifKamar(ws, kamar.id)
    // Dibandingkan dengan dirinya sendiri, bukan dengan angka 1. Skrip ini boleh
    // dijalankan ulang tanpa `migrate:fresh`, dan pada jalan kedua kamarnya
    // sudah punya tarif dari jalan pertama. Yang diuji adalah "jumlahnya tidak
    // berubah karena usulan", bukan "jumlahnya satu".
    const jumlahAwal = tarifAwal.length
    lapor('tarif awal kamar ada', jumlahAwal >= 1, JSON.stringify(tarifAwal))

    // Tiga tanggal berlaku yang belum terpakai, satu untuk tiap jalur.
    const tglSetuju = tanggalBebas(tarifAwal)
    const tglTolak = tanggalBebas([...tarifAwal, { berlaku_dari: tglSetuju }])
    const tglLewat = tanggalBebas([
      ...tarifAwal,
      { berlaku_dari: tglSetuju },
      { berlaku_dari: tglTolak },
    ])

    // --- Akun pengelola dengan wewenang mengusulkan -----------------------
    //
    // Dibuat lewat API, bukan lewat formulir: pembuatan akun lewat layar sudah
    // diuji e2e-t24, dan mengulanginya di sini hanya menambah satu jalur yang
    // bisa gagal karena alasan yang tidak berhubungan dengan T26.
    //
    // Yang diberi justru wewenang SENSITIF - `ubah_tarif` dan `ubah_kebijakan`.
    // Akun tanpa wewenang itu akan ditolak 403 sebelum sampai ke logika usulan,
    // sehingga uji ini tidak akan membuktikan apa pun tentang persetujuan.
    const daftarAwal = await panggilApi(ws, '/pengelola')
    const sisa = (daftarAwal.data?.data || []).find((p) => p.email === EMAIL_PENGELOLA)
    if (sisa) {
      await panggilApi(ws, `/pengelola/${sisa.id}`, 'DELETE')
    }

    const buatPengelola = await panggilApi(ws, '/pengelola', 'POST', {
      nama: 'Uji T26',
      email: EMAIL_PENGELOLA,
      password: 'password',
      wewenang: ['ubah_tarif', 'ubah_kebijakan'],
    })
    lapor(
      'pengelola dengan wewenang usul dibuat',
      buatPengelola.status === 201,
      `status ${buatPengelola.status}`,
    )
    if (buatPengelola.status !== 201) throw new Error('pengelola gagal dibuat')

    // --- Pengelola mengusulkan lewat layar --------------------------------
    const masukPengelola = await masukSebagai(ws, EMAIL_PENGELOLA)
    lapor('pengelola bisa masuk', masukPengelola)
    if (!masukPengelola) throw new Error('login pengelola gagal')

    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/kamar` })
    const kamarTerbuka = await tungguSampai(
      ws,
      `document.querySelector('h1')?.textContent.includes('Kamar')`,
    )
    lapor('pengelola bisa membuka halaman Kamar', kamarTerbuka)
    if (!kamarTerbuka) throw new Error('halaman kamar tidak terbuka')

    const bukaTarif = await evaluasi(
      ws,
      `
      (async () => {
        const baris = [...document.querySelectorAll('tbody tr')]
          .find(tr => tr.textContent.includes(${JSON.stringify(NOMOR_KAMAR)}))
        if (!baris) return 'baris kamar tidak ditemukan'
        const tombol = [...baris.querySelectorAll('button')]
          .find(b => /atur tarif/i.test(b.textContent))
        if (!tombol) return 'tombol atur tarif tidak ada'
        tombol.click()
        await new Promise(r => setTimeout(r, 500))
        return 'ok'
      })()
    `,
    )
    lapor('panel tarif terbuka untuk pengelola', bukaTarif === 'ok', String(bukaTarif))

    // Kalimat tombolnya diperiksa lebih dulu, sebelum diklik. Tombol yang
    // berbunyi "Berlakukan tarif" untuk akun yang tidak bisa memberlakukan
    // adalah tombol yang menipu, dan itu cacat tersendiri - terlepas dari
    // apakah servernya benar.
    const labelTombol = await evaluasi(
      ws,
      `document.querySelector('form.riwayat-form button[type=submit]')?.textContent.trim() || ''`,
    )
    lapor(
      'tombol berbunyi "Ajukan perubahan tarif", bukan "Berlakukan tarif"',
      /ajukan/i.test(labelTombol),
      labelTombol,
    )

    const kirimUsulan = await evaluasi(
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
        if (!set('#nilai-tarif', '1750000')) return 'medan nilai tidak ada'
        if (!set('#berlaku-tarif', ${JSON.stringify(tglSetuju)})) return 'medan tanggal tidak ada'
        await new Promise(r => setTimeout(r, 150))
        document.querySelector('form.riwayat-form').dispatchEvent(
          new Event('submit', { bubbles: true, cancelable: true }))
        await new Promise(r => setTimeout(r, 1500))
        return document.querySelector('.pesan-sukses')?.textContent.trim() || 'tanpa pesan'
      })()
    `,
    )
    lapor(
      'layar mengatakan usulan terkirim, bukan tarif berlaku',
      /usulan/i.test(String(kirimUsulan)) && !/tarif baru berlaku/i.test(String(kirimUsulan)),
      String(kirimUsulan),
    )

    // --- Yang menentukan: server tidak menulis baris tarif ----------------
    const tarifSesudahUsul = await tarifKamar(ws, kamar.id)
    lapor(
      'usulan pengelola TIDAK menambah baris tarif',
      tarifSesudahUsul.length === jumlahAwal,
      JSON.stringify(tarifSesudahUsul),
    )

    const usulanPengelola = await panggilApi(ws, '/usulan')
    const menunggu = (usulanPengelola.data?.data || []).filter(
      (u) => u.status_efektif === 'menunggu',
    )
    // Dicari menurut nominal yang baru saja diajukan, bukan menurut urutan.
    // Skrip ini boleh dijalankan ulang tanpa `migrate::fresh`, dan pada jalan
    // kedua antreannya sudah berisi usulan dari jalan pertama.
    const usulanIni = menunggu.find((u) => /1\.750\.000/.test(u.ringkasan || ''))
    lapor(
      'usulan tercatat di antrean dengan status menunggu',
      Boolean(usulanIni),
      `${menunggu.length} usulan menunggu`,
    )
    lapor(
      'ringkasan usulan menyebut nominal yang diajukan',
      /1\.750\.000/.test(usulanIni?.ringkasan || ''),
      usulanIni?.ringkasan || '(kosong)',
    )

    const usulanId = usulanIni?.id

    // --- Pengelola melihat nasib usulannya, tanpa tombol keputusan --------
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/persetujuan` })
    const persetujuanPengelola = await tungguSampai(
      ws,
      `document.querySelector('h1')?.textContent.includes('Persetujuan')`,
    )
    lapor('pengelola bisa membuka halaman Persetujuan', persetujuanPengelola)

    const tampilanPengelola = await evaluasi(
      ws,
      `({
        usulan: document.querySelectorAll('.satu-usulan').length,
        aksi: document.querySelectorAll('.aksi-usulan').length,
        tombolSetujui: [...document.querySelectorAll('button')]
          .filter(b => b.textContent.trim() === 'Setujui').length,
      })`,
    )
    lapor(
      'pengelola melihat usulannya sendiri di antrean',
      tampilanPengelola.usulan >= 1,
      `${tampilanPengelola.usulan} baris`,
    )
    lapor(
      'tombol Setujui tidak dirender untuk pengelola',
      tampilanPengelola.tombolSetujui === 0 && tampilanPengelola.aksi === 0,
      `aksi ${tampilanPengelola.aksi}, tombol ${tampilanPengelola.tombolSetujui}`,
    )

    // Server harus menolak juga, bukan hanya layarnya yang menyembunyikan.
    // Tanpa langkah ini, pengelola yang mengirim permintaan langsung tetap
    // bisa menyetujui usulannya sendiri, dan seluruh pemisahan menjadi hiasan.
    const tembakSetujui = await panggilApi(ws, `/usulan/${usulanId}/setujui`, 'POST')
    lapor(
      'POST /usulan/{id}/setujui ditolak 403 untuk pengelola',
      tembakSetujui.status === 403,
      `status ${tembakSetujui.status}`,
    )

    // --- Pemilik menyetujui ----------------------------------------------
    const masukPemilikLagi = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('pemilik masuk lagi', masukPemilikLagi)
    if (!masukPemilikLagi) throw new Error('login pemilik gagal')

    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/persetujuan` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Persetujuan')`)
    const adaUsulan = await tungguSampai(
      ws,
      `[...document.querySelectorAll('.satu-usulan')].some(a => a.textContent.includes('1.750.000'))`,
    )
    lapor('usulan tampil di antrean pemilik', adaUsulan)

    const adaLencana = await evaluasi(
      ws,
      `[...document.querySelectorAll('nav a')].some(a => a.textContent.includes('Persetujuan'))`,
    )
    lapor('menu Persetujuan ada di navigasi', adaLencana)

    await tangkapLayar(ws, 'T26-persetujuan')

    const setujui = await evaluasi(
      ws,
      `
      (async () => {
        const baris = [...document.querySelectorAll('.satu-usulan')]
          .find(a => a.textContent.includes('1.750.000'))
        if (!baris) return 'baris usulan tidak ditemukan'
        const tombol = [...baris.querySelectorAll('.aksi-usulan button')]
          .find(b => b.textContent.trim() === 'Setujui')
        if (!tombol) return 'tombol setujui tidak ada'
        tombol.click()
        await new Promise(r => setTimeout(r, 1600))
        return document.querySelector('.pesan-sukses')?.textContent.trim() || 'tanpa pesan'
      })()
    `,
    )
    lapor('persetujuan berhasil diproses', /disetujui/i.test(String(setujui)), String(setujui))

    const tarifSesudahSetujui = await tarifKamar(ws, kamar.id)
    const barisBaru = tarifSesudahSetujui.find((t) => t.berlaku_dari === tglSetuju)
    lapor(
      'tarif baru lahir SETELAH disetujui',
      barisBaru !== undefined && barisBaru.nilai === 1750000,
      JSON.stringify(tarifSesudahSetujui),
    )

    // --- Jejak audit menyebut dua pihak ----------------------------------
    const auditUsul = await panggilApi(ws, '/audit-log?aksi=tarif.usul')
    lapor(
      'jejak tarif.usul menyebut pengusul',
      (auditUsul.data?.data || []).some((j) => j.aktor_nama === 'Uji T26'),
      `${(auditUsul.data?.data || []).length} baris`,
    )

    const auditSetujui = await panggilApi(ws, '/audit-log?aksi=tarif.setujui')
    lapor(
      'jejak tarif.setujui menyebut pemutus',
      (auditSetujui.data?.data || []).some((j) => /Yukie/.test(j.aktor_nama || '')),
      `${(auditSetujui.data?.data || []).length} baris`,
    )

    // --- Jalur penolakan --------------------------------------------------
    await masukSebagai(ws, EMAIL_PENGELOLA)
    const usulKedua = await panggilApi(ws, '/tarif', 'POST', {
      kamar_id: kamar.id,
      nilai: 1900000,
      berlaku_dari: tglTolak,
    })
    lapor(
      'pengelola mengusulkan tarif kedua (202)',
      usulKedua.status === 202,
      `status ${usulKedua.status}`,
    )

    await masukSebagai(ws, 'pemilik@kos.test')
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/persetujuan` })
    await tungguSampai(
      ws,
      `[...document.querySelectorAll('.satu-usulan')].some(a => a.textContent.includes('1.900.000'))`,
    )

    const tolak = await evaluasi(
      ws,
      `
      (async () => {
        const baris = [...document.querySelectorAll('.satu-usulan')]
          .find(a => a.textContent.includes('1.900.000'))
        if (!baris) return 'baris usulan tidak ditemukan'

        const tombolTolak = [...baris.querySelectorAll('button')]
          .find(b => b.textContent.trim() === 'Tolak')
        if (!tombolTolak) return 'tombol tolak tidak ada'
        tombolTolak.click()
        await new Promise(r => setTimeout(r, 400))

        const medan = baris.querySelector('.bidang-tolak input')
        if (!medan) return 'medan alasan tidak muncul'
        medan.value = 'Terlalu tinggi untuk kamar sebesar ini.'
        medan.dispatchEvent(new Event('input', { bubbles: true }))
        await new Promise(r => setTimeout(r, 200))

        const kirim = [...baris.querySelectorAll('button')]
          .find(b => /kirim penolakan/i.test(b.textContent))
        if (!kirim) return 'tombol kirim penolakan tidak ada'
        kirim.click()
        await new Promise(r => setTimeout(r, 1600))
        return document.querySelector('.pesan-sukses')?.textContent.trim() || 'tanpa pesan'
      })()
    `,
    )
    lapor('penolakan berhasil diproses', /ditolak/i.test(String(tolak)), String(tolak))

    const tarifSesudahTolak = await tarifKamar(ws, kamar.id)
    lapor(
      'usulan yang ditolak tidak menambah baris tarif',
      tarifSesudahTolak.every((t) => t.berlaku_dari !== tglTolak),
      JSON.stringify(tarifSesudahTolak),
    )

    // Alasan penolakan harus terbaca oleh pengusul, bukan hanya tersimpan.
    const daftarDitolak = await panggilApi(ws, '/usulan?status=ditolak')
    const ditolak = (daftarDitolak.data?.data || []).find((u) =>
      /1\.900\.000/.test(u.ringkasan || ''),
    )
    lapor(
      'alasan penolakan tersimpan dan terbaca',
      /terlalu tinggi/i.test(ditolak?.alasan || ''),
      ditolak?.alasan || '(kosong)',
    )

    // --- Jalur kedaluwarsa ------------------------------------------------
    await masukSebagai(ws, EMAIL_PENGELOLA)
    const usulKetiga = await panggilApi(ws, '/tarif', 'POST', {
      kamar_id: kamar.id,
      nilai: 2000000,
      berlaku_dari: tglLewat,
    })
    const idKetiga = usulKetiga.data?.data?.id
    lapor(
      'usulan ketiga tercatat untuk uji kedaluwarsa',
      usulKetiga.status === 202 && Boolean(idKetiga),
      `status ${usulKetiga.status}, id ${idKetiga}`,
    )

    // Tenggatnya dimundurkan langsung di basis data. Lihat catatan `sql()`.
    sql(
      `UPDATE usulan_perubahan SET kedaluwarsa_pada = now() - interval '1 day' WHERE id = ${Number(idKetiga)}`,
    )

    await masukSebagai(ws, 'pemilik@kos.test')
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/persetujuan` })
    await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Persetujuan')`)

    // Saringan "Lewat 7 hari" harus benar-benar berisi. Penyaringan yang salah
    // diterjemahkan menjadi `status = 'kedaluwarsa'` akan selalu kosong, dan
    // daftar kosong terbaca sebagai "tidak ada yang kedaluwarsa".
    const saringLewat = await evaluasi(
      ws,
      `
      (async () => {
        const pilih = document.querySelector('#saring-usulan')
        if (!pilih) return 'saringan tidak ada'
        pilih.value = 'kedaluwarsa'
        pilih.dispatchEvent(new Event('change', { bubbles: true }))
        await new Promise(r => setTimeout(r, 1200))
        return {
          baris: document.querySelectorAll('.satu-usulan').length,
          lencana: document.querySelector('.lencana-status')?.textContent.trim() || '',
          aksi: document.querySelectorAll('.aksi-usulan').length,
        }
      })()
    `,
    )
    lapor(
      'saringan "Lewat 7 hari" menampilkan usulan yang kedaluwarsa',
      saringLewat?.baris >= 1,
      `${saringLewat?.baris} baris`,
    )
    lapor(
      'usulan kedaluwarsa berlabel jelas dan tanpa tombol keputusan',
      /lewat 7 hari/i.test(saringLewat?.lencana || '') && saringLewat?.aksi === 0,
      `lencana "${saringLewat?.lencana}", aksi ${saringLewat?.aksi}`,
    )

    // Server juga harus menolak, dengan 409 dan pesan yang menjelaskan.
    const setujuiLewat = await panggilApi(ws, `/usulan/${idKetiga}/setujui`, 'POST')
    lapor(
      'menyetujui usulan kedaluwarsa ditolak 409 dengan penjelasan',
      setujuiLewat.status === 409 && /7 hari/i.test(setujuiLewat.data?.message || ''),
      `status ${setujuiLewat.status}: ${setujuiLewat.data?.message || ''}`,
    )

    const tarifAkhir = await tarifKamar(ws, kamar.id)
    lapor(
      'usulan kedaluwarsa tidak menambah baris tarif',
      tarifAkhir.every((t) => t.berlaku_dari !== tglLewat),
      JSON.stringify(tarifAkhir),
    )

    // --- Kebijakan juga punya dua nasib ----------------------------------
    //
    // Tarif dan kebijakan memakai jalur yang sama, tetapi bukan endpoint yang
    // sama. Menguji keduanya bukan pengulangan: kebijakan mengubah cara
    // SELURUH kos menagih, dan itulah perubahan yang paling perlu disetujui.
    //
    // Tanggal berlakunya dicari yang belum terpakai, dengan alasan yang sama
    // seperti tarif: `kebijakan_kos` append-only, dan unique
    // (tipe, kamar_id, berlaku_dari) menolak pengulangan. Tanpa ini, jalan
    // kedua skrip berhenti di 422 yang tidak ada hubungannya dengan T26.
    const riwayatKebijakan = await panggilApi(ws, '/kebijakan?berlaku_pada=2026-01-01')
    const tglKebijakan = tanggalBebas(
      (riwayatKebijakan.data?.data || [])
        .filter((k) => k.tipe === 'jatuh_tempo' && k.kamar_id === null)
        .map((k) => ({ berlaku_dari: k.berlaku_dari })),
    )

    await masukSebagai(ws, EMAIL_PENGELOLA)
    const usulKebijakan = await panggilApi(ws, '/kebijakan', 'POST', {
      tipe: 'jatuh_tempo',
      nilai: 'akhir_bulan',
      berlaku_dari: tglKebijakan,
    })
    lapor(
      'pengelola mengusulkan kebijakan (202)',
      usulKebijakan.status === 202,
      `status ${usulKebijakan.status}`,
    )

    const kebijakanSekarang = await panggilApi(
      ws,
      `/kebijakan?berlaku_pada=${tglKebijakan}`,
    )
    const jatuhTempo = kebijakanSekarang.data?.meta?.berlaku?.jatuh_tempo?.nilai
    lapor(
      'kebijakan yang diusulkan BELUM berlaku',
      jatuhTempo !== 'akhir_bulan',
      `nilai sekarang "${jatuhTempo}"`,
    )

    // --- Akun dengan wewenang usul tetapi bukan pemilik -------------------
    const metaKebijakan = await panggilApi(ws, '/kebijakan')
    lapor(
      'GET /kebijakan menandai akun ini hanya boleh mengusulkan',
      metaKebijakan.data?.meta?.boleh_menetapkan === false,
      `boleh_menetapkan ${metaKebijakan.data?.meta?.boleh_menetapkan}`,
    )
  } finally {
    const gagal = await uji.selesai()
    if (gagal.length) {
      console.error(`\n${gagal.length} langkah gagal:`)
      for (const g of gagal) console.error(` - ${g.nama}${g.detail ? ': ' + g.detail : ''}`)
    }
  }
})
