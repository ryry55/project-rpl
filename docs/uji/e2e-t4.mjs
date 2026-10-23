// Verifikasi end-to-end T4 lewat browser sungguhan.
//
// Yang dibuktikan: tombol "Terbitkan semua" menerbitkan untuk seluruh kontrak
// aktif sekaligus, menekannya dua kali tidak membuat tagihan kedua, dan
// ringkasan hasil memisahkan terbit / dilewati / gagal.
//
// Pakai: node docs/uji/e2e-t4.mjs
// Prasyarat: docker compose up, Chrome --remote-debugging-port=9222,
// database sudah di-seed (migrate:fresh --seed).
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

const PERIODE = '2026-11-01'

const langkah = []
function lapor(nama, lulus, detail = '') {
  langkah.push({ nama, lulus, detail })
  console.log(`${lulus ? 'LULUS' : 'GAGAL'}  ${nama}${detail ? ' - ' + detail : ''}`)
}

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
  if (!masuk) throw new Error('login gagal, sisa langkah tidak bisa dijalankan')

  // T16b: gerbang kebijakan kos. Dinyatakan di sini, sekali, supaya langkah
  // uji di bawahnya tidak gagal karena alasan yang tidak sedang diuji -
  // gerbangnya memang bekerja, dan yang perlu dilakukan adalah memenuhinya.
  const kebijakan = await pastikanKebijakan(ws)
  lapor('kebijakan kos siap', kebijakan.ok, kebijakan.alasan)
  if (!kebijakan.ok) throw new Error('kebijakan kos gagal disiapkan: ' + kebijakan.alasan)
  // --- Siapkan tiga kontrak aktif, satu tanpa tarif ------------------------
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

      const hasil = []
      // Dua kontrak siap tagih.
      //
      // Dua nasib lain tidak bisa dibuat lewat API dan karena itu diuji di
      // tests/Feature/PenerbitanMassalTest.php: kontrak tanpa tarif ditolak
      // endpoint kontrak lebih dulu, dan tanggal masuk di masa depan juga
      // ditolak. Yang bisa didemokan di sini adalah "terbit" dan "dilewati"
      // (penekanan kedua).
      for (const s of [
        { kamar: 'T4-A', nama: 'Ani Empat', masuk: '2026-01-15' },
        { kamar: 'T4-B', nama: 'Budi Empat', masuk: '2026-01-15' },
      ]) {
        const kamar = await panggil('/kamar', 'POST', { nomor: s.kamar, tipe: 'Uji T4' })
        if (kamar.status !== 201) return { galat: 'kamar ' + s.kamar + ': ' + JSON.stringify(kamar.data) }

        const tarif = await panggil('/tarif', 'POST', {
          kamar_id: kamar.data.data.id, nilai: 1500000, berlaku_dari: '2026-01-01',
        })
        if (tarif.status !== 201) return { galat: 'tarif ' + s.kamar + ': ' + JSON.stringify(tarif.data) }

        const penghuni = await panggil('/penghuni', 'POST', {
          nama: s.nama, email: s.kamar.toLowerCase() + '@kos.test', password: 'rahasia123',
        })
        if (penghuni.status !== 201) return { galat: 'penghuni ' + s.nama + ': ' + JSON.stringify(penghuni.data) }

        const kontrak = await panggil('/kontrak', 'POST', {
          kamar_id: kamar.data.data.id,
          penghuni_id: penghuni.data.data.id,
          tgl_masuk: s.masuk,
          deposit: 0,
        })
        if (kontrak.status !== 201) return { galat: 'kontrak ' + s.nama + ': ' + JSON.stringify(kontrak.data) }

        hasil.push({ kamar: s.kamar, nama: s.nama, masuk: s.masuk, kontrakId: kontrak.data.data.id })
      }
      return { hasil }
    })()
  `)
  lapor('tiga kontrak uji siap', !siap.galat, siap.galat || `${siap.hasil?.length} kontrak`)
  if (siap.galat) throw new Error('data uji gagal disiapkan: ' + siap.galat)

  // --- Halaman tagihan -----------------------------------------------------
  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/tagihan` })
  await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Tagihan')`)
  // Tunggu daftar selesai dimuat: tombol kepala halaman baru muncul setelahnya.
  await tungguSampai(ws, `document.querySelector('.aksi-kepala')`)

  const adaTombol = await tungguSampai(ws, `
    [...document.querySelectorAll('button')].some(b => /terbitkan semua/i.test(b.textContent))
  `, 6000)
  lapor('tombol "Terbitkan semua" ada di halaman Tagihan', Boolean(adaTombol))

  // --- Buka panel dan terbitkan -------------------------------------------
  const buka = await evaluasi(ws, `
    (async () => {
      const tombol = [...document.querySelectorAll('button')]
        .find(b => /terbitkan semua/i.test(b.textContent))
      if (!tombol) return 'tombol tidak ada'
      tombol.click()
      await new Promise(r => setTimeout(r, 500))
      return document.querySelector('#periode-massal') ? 'terbuka' : 'panel tidak muncul'
    })()
  `)
  lapor('panel terbit massal terbuka', buka === 'terbuka', String(buka))

  const hasilPertama = await evaluasi(ws, `
    (async () => {
      const input = document.querySelector('#periode-massal')
      input.value = '${PERIODE}'
      input.dispatchEvent(new Event('input', { bubbles: true }))
      await new Promise(r => setTimeout(r, 100))

      const form = document.querySelector('.panel-massal form')
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
      await new Promise(r => setTimeout(r, 2500))

      const ringkas = document.querySelector('.hasil-massal')
      if (!ringkas) return { galat: 'ringkasan hasil tidak muncul' }

      return {
        teks: ringkas.textContent.replace(/\\s+/g, ' ').trim(),
        sukses: Boolean(ringkas.querySelector('.pesan-sukses')),
        jumlahBaris: document.querySelectorAll('.hasil-massal tbody tr').length,
      }
    })()
  `)
  lapor(
    'ringkasan hasil muncul dan menyebut jumlah terbit',
    !hasilPertama.galat && hasilPertama.sukses,
    hasilPertama.galat || hasilPertama.teks.slice(0, 110),
  )

  lapor(
    'ringkasan menyebut ketiga nasib: terbit, dilewati, gagal',
    !hasilPertama.galat &&
      /terbit/i.test(hasilPertama.teks) &&
      /dilewati/i.test(hasilPertama.teks) &&
      /gagal/i.test(hasilPertama.teks),
    hasilPertama.galat || '',
  )

  lapor(
    'ringkasan menyebut 2 terbit',
    !hasilPertama.galat && /2 terbit/.test(hasilPertama.teks),
    hasilPertama.galat || '',
  )

  // Rincian per kontrak harus menyebut hasil tiap kontrak, bukan hanya total.
  const rincian = await evaluasi(ws, `
    (async () => {
      const d = document.querySelector('.rincian-massal')
      if (!d) return { galat: 'rincian tidak ada' }
      d.open = true
      await new Promise(r => setTimeout(r, 300))
      return {
        baris: [...d.querySelectorAll('tbody tr')].map(tr =>
          tr.textContent.replace(/\\s+/g, ' ').trim()),
        // Lencana warna: tanpa warna, "Terbit" hanya teks biasa.
        lencana: [...d.querySelectorAll('.lencana-status')].map(s => ({
          teks: s.textContent.trim(),
          warna: getComputedStyle(s).color,
        })),
      }
    })()
  `)
  lapor(
    'rincian menampilkan satu baris per kontrak',
    !rincian.galat && rincian.baris?.length === 2,
    rincian.galat || `${rincian.baris?.length} baris`,
  )

  lapor(
    'rincian menyebut penghuni dan kamar tiap baris',
    !rincian.galat &&
      rincian.baris.some((b) => b.includes('Ani Empat') && b.includes('T4-A')) &&
      rincian.baris.some((b) => b.includes('Budi Empat') && b.includes('T4-B')),
    rincian.galat || (rincian.baris || []).join(' | ').slice(0, 160),
  )

  lapor(
    'lencana hasil punya warna terbaca, tidak putih di atas putih',
    !rincian.galat &&
      (rincian.lencana || []).length === 2 &&
      rincian.lencana.every((l) => l.warna !== 'rgb(255, 255, 255)'),
    JSON.stringify((rincian.lencana || []).map((l) => l.warna)),
  )

  // --- Tangkapan layar: hasil sukses, bukan penekanan kedua ----------------
  // Diambil sekarang, selagi panel masih menampilkan "2 terbit" beserta
  // rinciannya. Kalau diambil di akhir, yang tertangkap adalah keadaan
  // "tidak ada tagihan baru" - benar, tetapi tidak menunjukkan bahwa fiturnya
  // bekerja.
  const tangkapan = await kirim(ws, 'Page.captureScreenshot', { format: 'png' })
  const { writeFileSync } = await import('node:fs')
  writeFileSync('docs/uji/T4-terbit-massal.png', Buffer.from(tangkapan.data, 'base64'))
  lapor('tangkapan layar hasil sukses tersimpan', true)

  // --- Tagihan yang terbit muncul di daftar -------------------------------
  const diDaftar = await evaluasi(ws, `
    (() => {
      const tabel = [...document.querySelectorAll('.kartu .tabel')]
        .find(t => [...t.querySelectorAll('th')].some(th => /periode/i.test(th.textContent)))
      if (!tabel) return null
      return [...tabel.querySelectorAll('tbody tr')]
        .map(tr => tr.textContent.replace(/\\s+/g, ' ').trim())
        .filter(t => t.includes('Ani Empat') || t.includes('Budi Empat'))
    })()
  `)
  lapor(
    'tagihan yang baru terbit muncul di daftar',
    Array.isArray(diDaftar) && diDaftar.length === 2,
    `${diDaftar?.length || 0} baris`,
  )

  // --- Menekan lagi: 409, bukan duplikat ----------------------------------
  const hasilKedua = await evaluasi(ws, `
    (async () => {
      const form = document.querySelector('.panel-massal form')
      if (!form) return { galat: 'panel tertutup' }
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
      await new Promise(r => setTimeout(r, 2500))

      const ringkas = document.querySelector('.hasil-massal')
      if (!ringkas) return { galat: 'ringkasan tidak muncul' }
      return {
        teks: ringkas.textContent.replace(/\\s+/g, ' ').trim(),
        sukses: Boolean(ringkas.querySelector('.pesan-sukses')),
      }
    })()
  `)
  lapor(
    'menekan kedua kali tidak mengklaim sukses',
    !hasilKedua.galat && hasilKedua.sukses === false,
    hasilKedua.galat || hasilKedua.teks.slice(0, 110),
  )

  lapor(
    'menekan kedua kali menyebut tidak ada tagihan baru',
    !hasilKedua.galat && /tidak ada tagihan baru/i.test(hasilKedua.teks),
    hasilKedua.galat || '',
  )

  // --- Jumlah tagihan di API tetap benar ----------------------------------
  const jumlahApi = await evaluasi(ws, `
    (async () => {
      const res = await fetch('${API}/tagihan?periode=${PERIODE}', {
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
        },
      })
      const isi = await res.json()
      return isi.data.length
    })()
  `)
  lapor(
    'R-02: API mengembalikan tepat 2 tagihan untuk periode ini',
    jumlahApi === 2,
    `${jumlahApi} tagihan`,
  )

  await kirim(ws, 'Page.close').catch(() => {})
  ws.close()

  const gagal = langkah.filter((l) => !l.lulus)
  console.log(`\n${langkah.length - gagal.length}/${langkah.length} langkah lulus`)
  if (gagal.length) process.exitCode = 1
}

jalan().catch(async (e) => {
  console.error('E2E gagal:', e.message)
  // Tab ditutup juga saat gagal. Tanpa ini, tiap kegagalan meninggalkan tab
  // yatim - dan setelah beberapa tab, langkah login mulai gagal karena alasan
  // yang tidak ada hubungannya dengan aplikasi.
  try {
    const daftar = await fetch(`${CDP_BASE}/json/list`).then((r) => r.json())
    await Promise.all(
      daftar
        .filter((t) => t.type === 'page')
        .map((t) => fetch(`${CDP_BASE}/json/close/${t.id}`).catch(() => {})),
    )
  } catch {
    // Mengabaikan kegagalan pembersihan: galat aslinya yang perlu terlihat.
  }
  process.exitCode = 1
})
