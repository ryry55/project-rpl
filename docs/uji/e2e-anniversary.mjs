// Verifikasi end-to-end pola tagihan anniversary + tanggal terbit (K-14).
//
// Yang dibuktikan, dan kenapa masing-masing perlu:
//
//   1. **Siklus mengikuti tanggal masuk, bukan tanggal 1.** Kontrak yang masuk
//      tanggal 15 harus menghasilkan tagihan bertanggal 15. Kalau yang diuji
//      hanya "tagihan terbit", aplikasi yang mengabaikan pola akan lulus.
//
//   2. **Filter bulan menemukan periode bertanggal 15.** Sebelum Fase 2 filter
//      mencocokkan tanggal 1 persis, sehingga tagihan anniversary tidak pernah
//      muncul di layar daftar - terbit tetapi tidak terlihat.
//
//   3. **Tanggal terbit hanya menunda.** Disetel 20 sementara siklusnya 15:
//      tagihan tetap bertanggal 15, bukan 20 dan bukan 5. Arti ini yang dipilih
//      pemilik (K-14 A), dan membalikkannya berarti menagih sebelum penghuni
//      menempati kamar.
//
//   4. **Layar Kebijakan menampilkan kedua setelan dan peringatannya.** Tanggal
//      terbit 20 dengan jatuh tempo 10 membuat tagihan lahir sudah terlambat;
//      peringatan itu harus terbaca, bukan hanya tersimpan di server.
//
// Pakai: node docs/uji/e2e-anniversary.mjs
// Prasyarat: docker compose up sudah jalan, Chrome --remote-debugging-port=9222,
// dan database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo: menambah kebijakan pola + tanggal terbit,
// satu kamar, satu penghuni, satu kontrak, dan tagihan Maret 2026.

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

const NOMOR = 'ANN-1'
const EMAIL = 'uji-anniversary@kos.test'
const POKOK = 1500000

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  try {
    const masuk = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik berhasil', masuk)
    if (!masuk) throw new Error('login pemilik gagal')

    const kebijakan = await pastikanKebijakan(ws)
    lapor('kebijakan wajib siap', kebijakan.ok, kebijakan.alasan)
    if (!kebijakan.ok) throw new Error('kebijakan gagal disiapkan')

    // --- Setelan pola dan tanggal terbit ----------------------------------
    //
    // Dua baris baru, bukan penimpaan: `kebijakan_kos` append-only. Tanggal
    // berlaku 2026-01-01 supaya langsung dipakai periode Maret yang diuji.
    const pola = await panggilApi(ws, '/kebijakan', 'POST', {
      tipe: 'pola_tagihan',
      nilai: 'anniversary',
      berlaku_dari: '2026-01-01',
    })
    lapor(
      'pola tagihan anniversary tersimpan',
      pola.status === 201 || pola.status === 422,
      `status ${pola.status}`,
    )

    const terbit = await panggilApi(ws, '/kebijakan', 'POST', {
      tipe: 'tanggal_terbit',
      nilai: 'tanggal_tetap',
      tanggal: 20,
      berlaku_dari: '2026-01-01',
    })
    lapor(
      'tanggal terbit tanggal 20 tersimpan',
      terbit.status === 201 || terbit.status === 422,
      `status ${terbit.status}`,
    )

    // Jatuh tempo "tanggal tetap" tanggal 10, berlaku mulai Februari. Tanpa
    // ini peringatan "terbit melewati jatuh tempo" tidak bisa muncul: jatuh
    // tempo "tanggal masuk" tanggalnya berbeda per kontrak.
    const jatuh = await panggilApi(ws, '/kebijakan', 'POST', {
      tipe: 'jatuh_tempo',
      nilai: 'tanggal_tetap',
      tanggal: 10,
      berlaku_dari: '2026-02-01',
    })
    lapor(
      'jatuh tempo tanggal tetap 10 tersimpan',
      jatuh.status === 201 || jatuh.status === 422,
      `status ${jatuh.status}`,
    )

    // --- Data uji: kontrak masuk tanggal 15 -------------------------------
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

        const daftarKamar = await p('/kamar', 'GET')
        let kamar = (daftarKamar.data?.data || []).find((k) => k.nomor === ${JSON.stringify(NOMOR)})
        if (!kamar) {
          const buat = await p('/kamar', 'POST', { nomor: ${JSON.stringify(NOMOR)}, tipe: 'Uji anniversary' })
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

        const daftarPenghuni = await p('/penghuni', 'GET')
        let penghuni = (daftarPenghuni.data?.data || []).find((x) => x.email === ${JSON.stringify(EMAIL)})
        if (!penghuni) {
          const buat = await p('/penghuni', 'POST', {
            nama: 'Uji Anniversary', email: ${JSON.stringify(EMAIL)},
          })
          if (buat.status !== 201) return { galat: 'penghuni: ' + JSON.stringify(buat.data) }
          penghuni = buat.data.data
        }

        const daftarKontrak = await p('/kontrak', 'GET')
        let kontrak = (daftarKontrak.data?.data || []).find(
          (k) => k.status === 'aktif' && k.kamar_id === kamar.id,
        )
        if (!kontrak) {
          const buat = await p('/kontrak', 'POST', {
            kamar_id: kamar.id, penghuni_id: penghuni.id, tgl_masuk: '2026-03-15',
          })
          if (buat.status !== 201) return { galat: 'kontrak: ' + JSON.stringify(buat.data) }
          kontrak = buat.data.data
        }

        return { ok: true, kontrakId: kontrak.id, tglMasuk: kontrak.tgl_masuk }
      })()
    `,
    )
    lapor(
      'kontrak uji masuk tanggal 15 Maret siap',
      siap?.ok === true && String(siap?.tglMasuk).startsWith('2026-03-15'),
      JSON.stringify(siap),
    )
    if (siap?.ok !== true) throw new Error('data uji gagal disiapkan: ' + JSON.stringify(siap))

    // --- Penerbitan massal: siklus mengikuti tanggal masuk ----------------
    const massal = await panggilApi(ws, '/tagihan/terbitkan', 'POST', {
      periode: '2026-03-01',
    })
    lapor(
      'penerbitan massal Maret berhasil',
      massal.status === 201,
      `status ${massal.status}, ${JSON.stringify(massal.data?.data || massal.data)}`,
    )
    lapor(
      'hasil massal menyebut nama bulan, bukan satu tanggal',
      /Maret 2026/i.test(String(massal.data?.data?.label_periode || '')),
      String(massal.data?.data?.label_periode || '(kosong)'),
    )

    const maret = await panggilApi(ws, '/tagihan?periode=2026-03-01')
    const barisMaret = (maret.data?.data || []).filter((t) => t.kontrak_id === siap.kontrakId)
    lapor(
      'tagihan kontrak anniversary bertanggal 15 Maret',
      barisMaret.length === 1 && String(barisMaret[0].periode).startsWith('2026-03-15'),
      JSON.stringify(barisMaret.map((t) => t.periode)),
    )

    // Tanggal terbit 20 TIDAK boleh menggeser periode: ia hanya menunda
    // penerbitan, bukan memindahkan siklus.
    lapor(
      'tanggal terbit tidak menggeser periode tagihan',
      barisMaret.length === 1 && !String(barisMaret[0]?.periode).startsWith('2026-03-20'),
      String(barisMaret[0]?.periode || '(tidak ada)'),
    )

    const april = await panggilApi(ws, '/tagihan?periode=2026-04-01')
    const barisApril = (april.data?.data || []).filter((t) => t.kontrak_id === siap.kontrakId)
    lapor(
      'filter bulan tidak membocorkan tagihan Maret ke April',
      barisApril.length === 0,
      `${barisApril.length} baris`,
    )

    // --- Layar Kebijakan --------------------------------------------------
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/kebijakan` })
    await tungguSampai(ws, `document.querySelector('h1')`, 15000)

    // Judul dirender sebelum data tiba; tunggu penanda yang hanya muncul
    // setelah meta kebijakan terisi.
    await tungguSampai(
      ws,
      `document.body.textContent.includes('Tagihan terbit tanggal 20')`,
      15000,
    )

    const layar = await evaluasi(
      ws,
      `
      (() => {
        const teks = document.body.textContent.replace(/\\s+/g, ' ')
        return {
          adaPola: /Pola tagihan/.test(teks),
          adaTerbit: /Tanggal terbit/.test(teks),
          polaAnniversary: /Tanggal masuk \\(anniversary\\)/.test(teks),
          ringkasanTerbit: /Tagihan terbit tanggal 20 tiap bulan/.test(teks),
          peringatan: /sudah terlambat/.test(teks),
          seluruhKos: (teks.match(/berlaku untuk seluruh kos/g) || []).length,
        }
      })()
    `,
    )
    lapor(
      'layar Kebijakan menampilkan kartu pola tagihan dan tanggal terbit',
      layar?.adaPola === true && layar?.adaTerbit === true,
      JSON.stringify(layar),
    )
    lapor(
      'nilai yang berlaku terbaca sebagai kalimat, bukan kode',
      layar?.polaAnniversary === true && layar?.ringkasanTerbit === true,
      JSON.stringify(layar),
    )
    lapor(
      'peringatan muncul saat tanggal terbit melewati jatuh tempo',
      layar?.peringatan === true,
      JSON.stringify(layar),
    )
    lapor(
      'kedua tipe ditandai berlaku untuk seluruh kos',
      layar?.seluruhKos >= 2,
      `${layar?.seluruhKos} penanda`,
    )

    await tangkapLayar(ws, 'anniversary-kebijakan')

    // --- Daftar tagihan: periode 15 terlihat ------------------------------
    await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tagihan` })

    // Menunggu barisnya, bukan `.tabel`: tabel kosong dan tabel terisi
    // memakai elemen yang sama, jadi menunggu `.tabel` bisa lolos sebelum
    // datanya tiba - dan asersinya lalu gagal karena alasan yang salah.
    const adaBaris = await tungguSampai(
      ws,
      `document.body.textContent.includes('15 Mar 2026')`,
      15000,
    )
    const daftar = await evaluasi(
      ws,
      `document.body.textContent.replace(/\\s+/g, ' ')`,
    )
    // Tabel memakai `tanggal()` dengan bulan singkat ("15 Mar 2026"), bukan
    // nama bulan penuh - yang panjang dipakai dashboard penghuni.
    lapor(
      'daftar tagihan memuat periode 15 Maret',
      adaBaris && /15 Mar 2026/.test(daftar || ''),
      (daftar || '').slice(0, 160),
    )

    await tangkapLayar(ws, 'anniversary-tagihan')
  } finally {
    const gagal = await uji.selesai()
    if (gagal.length) {
      console.error(`\n${gagal.length} langkah gagal:`)
      for (const g of gagal) console.error(` - ${g.nama}${g.detail ? ': ' + g.detail : ''}`)
    }
  }
})
