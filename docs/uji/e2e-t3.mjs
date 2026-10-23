// Verifikasi end-to-end T3 lewat browser sungguhan.
//
// Membuktikan dari layar: tagihan bisa diterbitkan, rincian per baris yang
// tampil jumlahnya cocok dengan pokok, dan angka prorata benar untuk kasus
// yang disebut tiket.
//
// Pakai: node docs/uji/e2e-t3.mjs
// Prasyarat: docker compose up sudah jalan, Chrome --remote-debugging-port=9222,
// dan database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo.

import {
  APP,
  API,
  CDP_BASE,
  targetBaru,
  hubungkan,
  kirim,
  evaluasi,
  tungguSampai,
  pastikanKebijakan,
} from './_cdp.mjs'

const langkah = []
function lapor(nama, lulus, detail = '') {
  langkah.push({ nama, lulus, detail })
  console.log(`${lulus ? 'LULUS' : 'GAGAL'}  ${nama}${detail ? ' - ' + detail : ''}`)
}

const tidur = (ms) => new Promise((r) => setTimeout(r, ms))

// Memanggil API langsung dari dalam halaman, memakai token yang sudah ada.
// Dipakai untuk menyiapkan data (kamar, kontrak) supaya skrip ini tidak
// bergantung pada e2e-t2 sudah dijalankan lebih dulu.
const panggilApi = (jalur, metode = 'GET', isi = null) => `
  (async () => {
    const opsi = {
      method: ${JSON.stringify(metode)},
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
      },
    }
    if (${JSON.stringify(isi)} !== null) opsi.body = JSON.stringify(${JSON.stringify(isi)})
    const res = await fetch('${API}' + ${JSON.stringify(jalur)}, opsi)
    const teks = await res.text()
    let data = null
    try { data = teks ? JSON.parse(teks) : null } catch { data = { message: teks } }
    return { status: res.status, data }
  })()
`

async function jalan() {
  const tab = await targetBaru()
  const ws = await hubungkan(tab.webSocketDebuggerUrl)

  await kirim(ws, 'Page.enable')
  await kirim(ws, 'Runtime.enable')

  // --- Login ---------------------------------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/masuk` })
  await tungguSampai(ws, `document.querySelector('#email')`)
  await evaluasi(ws, `
    (() => {
      const set = (sel, v) => {
        const el = document.querySelector(sel)
        el.value = v
        el.dispatchEvent(new Event('input', { bubbles: true }))
      }
      set('#email', 'pemilik@kos.test')
      set('#password', 'password')
      document.querySelector('form').dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }))
      return true
    })()
  `)
  const masuk = await tungguSampai(ws, `location.pathname.startsWith('/pemilik')`)
  lapor('login pemilik berhasil', Boolean(masuk), await evaluasi(ws, 'location.pathname'))

  // T16b: gerbang kebijakan kos. Dinyatakan di sini, sekali, supaya langkah
  // uji di bawahnya tidak gagal karena alasan yang tidak sedang diuji -
  // gerbangnya memang bekerja, dan yang perlu dilakukan adalah memenuhinya.
  const kebijakan = await pastikanKebijakan(ws)
  lapor('kebijakan kos siap', kebijakan.ok, kebijakan.alasan)
  if (!kebijakan.ok) throw new Error('kebijakan kos gagal disiapkan: ' + kebijakan.alasan)
  const menuTagihan = await evaluasi(ws, `
    (() => {
      const a = [...document.querySelectorAll('a')].find(a => /tagihan/i.test(a.textContent))
      return Boolean(a)
    })()
  `)
  lapor('menu Tagihan tampil di sidebar pemilik', Boolean(menuTagihan))

  // --- Siapkan data lewat API (kamar + kontrak) ----------------------------
  // Skrip ini menyiapkan datanya sendiri supaya bisa dijalankan tanpa e2e-t2.
  const siap = await evaluasi(ws, `
    (async () => {
      const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
      }
      const panggil = async (jalur, metode, isi) => {
        const opsi = { method: metode, headers }
        if (isi) opsi.body = JSON.stringify(isi)
        const res = await fetch('${API}' + jalur, opsi)
        const teks = await res.text()
        let data = null
        try { data = teks ? JSON.parse(teks) : null } catch { data = null }
        return { status: res.status, data }
      }

      // Kamar khusus T3 supaya tidak bertabrakan dengan data dari e2e-t2.
      const kamar = await panggil('/kamar', 'POST', { nomor: 'T3-1', tipe: 'Uji' })
      if (kamar.status !== 201) return { galat: 'kamar: ' + JSON.stringify(kamar.data) }

      // Tarif 1.500.000 berlaku sejak 2026-01-01.
      const tarif = await panggil('/tarif', 'POST', {
        kamar_id: kamar.data.data.id,
        nilai: 1500000,
        berlaku_dari: '2026-01-01',
      })
      if (tarif.status !== 201) return { galat: 'tarif: ' + JSON.stringify(tarif.data) }

      // Dua penghuni: satu masuk 15 Okt (prorata 17/31), satu masuk 1 Okt (penuh).
      //
      // Tanpa kolom password: sejak T27 pemilik tidak mengetikkan sandi siapa pun.
      // Akunnya lahir dengan sandi acak yang tidak diketahui siapa pun, dan
      // penghuni menetapkannya sendiri lewat tautan aktivasi. Skrip ini tidak
      // memakai akun itu untuk masuk, jadi tautannya tidak perlu dibuka.
      const p1 = await panggil('/penghuni', 'POST', {
        nama: 'Uji Prorata', email: 't3-prorata@kos.test',
      })
      if (p1.status !== 201) return { galat: 'penghuni1: ' + JSON.stringify(p1.data) }

      const p2 = await panggil('/penghuni', 'POST', {
        nama: 'Uji Penuh', email: 't3-penuh@kos.test',
      })
      if (p2.status !== 201) return { galat: 'penghuni2: ' + JSON.stringify(p2.data) }

      const k1 = await panggil('/kontrak', 'POST', {
        kamar_id: kamar.data.data.id,
        penghuni_id: p1.data.data.id,
        tgl_masuk: '2026-10-15',
        deposit: 1500000,
      })
      if (k1.status !== 201) return { galat: 'kontrak1: ' + JSON.stringify(k1.data) }

      // Kamar kedua untuk kontrak penuh.
      const kamar2 = await panggil('/kamar', 'POST', { nomor: 'T3-2', tipe: 'Uji' })
      const tarif2 = await panggil('/tarif', 'POST', {
        kamar_id: kamar2.data.data.id, nilai: 1500000, berlaku_dari: '2026-01-01',
      })
      const k2 = await panggil('/kontrak', 'POST', {
        kamar_id: kamar2.data.data.id,
        penghuni_id: p2.data.data.id,
        tgl_masuk: '2026-10-01',
        deposit: 0,
      })
      if (k2.status !== 201) return { galat: 'kontrak2: ' + JSON.stringify(k2.data) }

      return {
        kontrakProrata: k1.data.data.id,
        kontrakPenuh: k2.data.data.id,
      }
    })()
  `)
  lapor('data uji siap (2 kamar, 2 kontrak)', !siap.galat, siap.galat || JSON.stringify(siap))
  if (siap.galat) throw new Error('data uji gagal disiapkan: ' + siap.galat)

  // --- Terbitkan tagihan lewat UI -----------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tagihan` })
  await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Tagihan')`)

  const terbitkan = async (kontrakId, periode) => {
    return evaluasi(ws, `
      (async () => {
        const tombol = [...document.querySelectorAll('button')].find(b => /terbitkan tagihan/i.test(b.textContent))
        if (!tombol) return 'tombol tidak ada'
        tombol.click()
        await new Promise(r => setTimeout(r, 250))

        const set = (sel, v) => {
          const el = document.querySelector(sel)
          if (!el) throw new Error('elemen tidak ada: ' + sel)
          el.value = v
          el.dispatchEvent(new Event('input', { bubbles: true }))
          el.dispatchEvent(new Event('change', { bubbles: true }))
        }

        const kontrak = document.querySelector('#kontrak')
        const opsi = [...kontrak.options].find(o => String(o.value) === ${JSON.stringify(String(kontrakId))})
        if (!opsi) return 'opsi kontrak tidak ada'
        kontrak.value = opsi.value
        kontrak.dispatchEvent(new Event('change', { bubbles: true }))

        set('#periode', ${JSON.stringify(periode)})
        document.querySelector('form').dispatchEvent(
          new Event('submit', { bubbles: true, cancelable: true }))
        return 'dikirim'
      })()
    `)
  }

  const kirim1 = await terbitkan(siap.kontrakProrata, '2026-10-01')
  const formTertutup = await tungguSampai(ws, `!document.querySelector('#periode')`, 8000)
  lapor('formulir terbit tagihan terkirim dan tertutup', Boolean(formTertutup), String(kirim1))

  const barisProrata = await tungguSampai(ws, `
    [...document.querySelectorAll('tbody tr')].some(tr =>
      tr.textContent.includes('Uji Prorata') && /822\.581/.test(tr.textContent))
  `, 8000)
  lapor('tagihan prorata Rp 822.581 tampil di daftar', Boolean(barisProrata))

  // --- Detail: rincian harus menjumlah ke pokok ---------------------------
  const bukaDetail = await evaluasi(ws, `
    (async () => {
      const baris = [...document.querySelectorAll('tbody tr')].find(tr => tr.textContent.includes('Uji Prorata'))
      if (!baris) return false
      const tombol = [...baris.querySelectorAll('button')].find(b => /rincian/i.test(b.textContent))
      if (!tombol) return false
      tombol.click()
      await new Promise(r => setTimeout(r, 900))
      return Boolean(document.querySelector('.snapshot'))
    })()
  `)
  lapor('panel rincian tagihan terbuka', Boolean(bukaDetail))

  const cekRincian = await evaluasi(ws, `
    (() => {
      // Panel rincian adalah tabel pertama yang muncul setelah judul tagihan.
      const panel = [...document.querySelectorAll('section.kartu')]
        .find(s => /Tagihan Uji Prorata/.test(s.textContent))
      if (!panel) return { galat: 'panel rincian tidak ditemukan' }

      const baris = [...panel.querySelectorAll('tbody tr')].map(tr => {
        const sel = [...tr.querySelectorAll('td')].map(td => td.textContent.trim())
        return sel
      })
      return { baris }
    })()
  `)

  if (cekRincian.galat) {
    lapor('rincian bisa dibaca dari layar', false, cekRincian.galat)
  } else {
    const teks = JSON.stringify(cekRincian.baris)
    lapor('rincian menyebut prorata 17/31 hari', /prorata 17\/31 hari/.test(teks), teks)
    lapor('rincian menampilkan sewa Rp 822.581', /822\.581/.test(teks))
    lapor('baris Pokok dan Total tampil', /Pokok/.test(teks) && /Total/.test(teks))
  }

  // --- Jumlah rincian harus sama dengan pokok (lewat API) -----------------
  // Tanggal disematkan ke 20 Okt 2026 (hari terlambat 5, masih dalam tenggang)
  // supaya asersi "denda masih 0 saat terbit" tidak bergantung pada jam nyata.
  // Tanpa ini, tes lulus selama hari ini <= 22 Okt dan gagal sendiri mulai
  // 23 Okt - kelas kesalahan yang sudah pernah menggigit repo ini.
  const konsisten = await evaluasi(ws, `
    (async () => {
      const headers = {
        Accept: 'application/json',
        Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
        'X-Simulasi-Tanggal': '2026-10-20',
      }
      const daftar = await fetch('${API}/tagihan', { headers }).then(r => r.json())
      const tagihan = daftar.data.find(t => t.kontrak?.penghuni?.nama === 'Uji Prorata')
      if (!tagihan) return { galat: 'tagihan tidak ditemukan' }
      const jumlah = tagihan.rincian.reduce((j, r) => j + r.nilai, 0)
      return {
        pokok: tagihan.pokok,
        jumlahRincian: jumlah,
        cocok: tagihan.pokok === jumlah,
        total: tagihan.total,
        denda: tagihan.denda,
        jatuhTempo: tagihan.jatuh_tempo,
        periode: tagihan.periode,
        snapshotAda: Boolean(tagihan.tarif_terpakai?.tarif_sewa),
      }
    })()
  `)
  lapor('pokok sama dengan jumlah seluruh rincian', konsisten.cocok === true, JSON.stringify(konsisten))
  lapor('jatuh tempo = tanggal masuk (15 Okt)', konsisten.jatuhTempo === '2026-10-15', konsisten.jatuhTempo)
  lapor('periode tersimpan sebagai tanggal 1', konsisten.periode === '2026-10-01', konsisten.periode)
  lapor('snapshot tarif tersimpan di tagihan', konsisten.snapshotAda === true)
  lapor('denda masih 0 saat terbit', konsisten.denda === 0 && konsisten.total === konsisten.pokok)

  // --- Kontrak masuk tanggal 1: harus penuh -------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tagihan` })
  await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Tagihan')`)
  await terbitkan(siap.kontrakPenuh, '2026-10-01')
  await tungguSampai(ws, `!document.querySelector('#periode')`, 8000)

  const barisPenuh = await tungguSampai(ws, `
    [...document.querySelectorAll('tbody tr')].some(tr =>
      tr.textContent.includes('Uji Penuh') && /1\.500\.000/.test(tr.textContent))
  `, 8000)
  lapor('masuk tanggal 1 ditagih penuh Rp 1.500.000 (bukan 1.499.999)', Boolean(barisPenuh))

  // --- Tolak duplikat: R-02 (409) -----------------------------------------
  const duplikat = await evaluasi(ws, panggilApi('/tagihan', 'POST', {
    kontrak_id: siap.kontrakProrata,
    periode: '2026-10-01',
  }))
  lapor('menerbitkan tagihan periode sama ditolak 409', duplikat.status === 409,
    `status ${duplikat.status}: ${duplikat.data?.message || ''}`)

  // --- Tolak periode sebelum masuk ---------------------------------------
  const terlaluAwal = await evaluasi(ws, panggilApi('/tagihan', 'POST', {
    kontrak_id: siap.kontrakProrata,
    periode: '2026-09-01',
  }))
  lapor('periode sebelum tanggal masuk ditolak 422', terlaluAwal.status === 422,
    `status ${terlaluAwal.status}`)

  // --- Tangkapan layar ----------------------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tagihan` })
  await tidur(1200)
  let tangkapan = await kirim(ws, 'Page.captureScreenshot', { format: 'png' })
  const { writeFileSync } = await import('node:fs')
  writeFileSync('docs/uji/T3-tagihan.png', Buffer.from(tangkapan.data, 'base64'))

  // Screenshot panel rincian terbuka.
  await evaluasi(ws, `
    (async () => {
      const baris = [...document.querySelectorAll('tbody tr')].find(tr => tr.textContent.includes('Uji Prorata'))
      if (!baris) return false
      const tombol = [...baris.querySelectorAll('button')].find(b => /rincian/i.test(b.textContent))
      if (tombol) tombol.click()
      await new Promise(r => setTimeout(r, 900))
      return true
    })()
  `)
  tangkapan = await kirim(ws, 'Page.captureScreenshot', { format: 'png' })
  writeFileSync('docs/uji/T3-rincian.png', Buffer.from(tangkapan.data, 'base64'))
  lapor('dua tangkapan layar tersimpan di docs/uji', true)

  await kirim(ws, 'Page.close').catch(() => {})
  ws.close()

  const gagal = langkah.filter((l) => !l.lulus)
  console.log(`\n${langkah.length - gagal.length}/${langkah.length} langkah lulus`)
  if (gagal.length) process.exitCode = 1
}

jalan().catch((e) => {
  console.error('E2E gagal:', e.message)
  process.exitCode = 1
})
