// Verifikasi end-to-end T9 lewat browser sungguhan.
//
// Yang dibuktikan: isolasi tagihan antar penghuni, dan log audit yang bisa
// dibaca pemilik tetapi tidak bisa diubah siapa pun. Keduanya diuji dari layar,
// bukan hanya lewat API, karena kebocoran yang paling berbahaya adalah yang
// terlihat normal di API tetapi tampil di layar orang yang salah.
//
// Pakai: node docs/uji/e2e-t9.mjs
// Prasyarat: docker compose up, Chrome --remote-debugging-port=9222,
// database sudah di-seed (migrate:fresh --seed).
//
// Skrip ini MENGUBAH database demo.

import {
  APP,
  API,
  kirim,
  evaluasi,
  tungguSampai,
  masukSebagai,
  panggilApi,
  tangkapLayar,
  mulaiUji,
  jalankan,
  teksMemuat,
  rupiah,
  pastikanKebijakan,
  aktifkanAkun,
  lewatiPersetujuanPdp,
} from './_cdp.mjs'

const SIMULASI = '2026-11-23'
const PERIODE = '2026-11-01'
const POKOK = 1_500_000

/**
 * Sandi yang ditetapkan sendiri oleh penghuni A lewat tautan aktivasi.
 *
 * Sejak T27 pemilik tidak mengetikkan sandi penghuni, jadi skrip ini harus
 * membuka tautan yang dikirim ke emailnya - persis seperti penghuni sungguhan.
 */
const SANDI_A = 'sandi-t9-penghuni-a'

/** Batas bawah waktu surat; lihat alasannya di e2e-t24.mjs. */
const SEJAK = new Date()

// Denda yang diharapkan: jatuh tempo 15 Nov, simulasi 23 Nov -> terlambat 8
// hari, masa tenggang 7, jadi 1 hari kena denda = 1% x 1.500.000.
const DENDA_DIHARAPKAN = 15_000

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  // --- Siapkan dua penghuni, masing-masing satu tagihan --------------------
  const masukPemilik = await masukSebagai(ws, 'pemilik@kos.test')
  lapor('login pemilik berhasil', masukPemilik, await evaluasi(ws, 'location.pathname'))
  if (!masukPemilik) throw new Error('login pemilik gagal')

  // T16b: gerbang kebijakan kos. Dinyatakan di sini, sekali, supaya langkah
  // uji di bawahnya tidak gagal karena alasan yang tidak sedang diuji -
  // gerbangnya memang bekerja, dan yang perlu dilakukan adalah memenuhinya.
  const kebijakan = await pastikanKebijakan(ws)
  lapor('kebijakan kos siap', kebijakan.ok, kebijakan.alasan)
  if (!kebijakan.ok) throw new Error('kebijakan kos gagal disiapkan: ' + kebijakan.alasan)

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

      const buat = async (nomor, nama, email) => {
        const kamar = await panggil('/kamar', 'POST', { nomor, tipe: 'Uji T9' })
        if (kamar.status !== 201) return { galat: 'kamar ' + nomor + ': ' + JSON.stringify(kamar.data) }

        const tarif = await panggil('/tarif', 'POST', {
          kamar_id: kamar.data.data.id, nilai: ${POKOK}, berlaku_dari: '2026-01-01',
        })
        if (tarif.status !== 201) return { galat: 'tarif ' + nomor + ': ' + JSON.stringify(tarif.data) }

        const penghuni = await panggil('/penghuni', 'POST', {
          nama, email,
        })
        if (penghuni.status !== 201) return { galat: 'penghuni ' + nama + ': ' + JSON.stringify(penghuni.data) }

        const kontrak = await panggil('/kontrak', 'POST', {
          kamar_id: kamar.data.data.id,
          penghuni_id: penghuni.data.data.id,
          tgl_masuk: '2026-01-15',
          deposit: 0,
        })
        if (kontrak.status !== 201) return { galat: 'kontrak ' + nama + ': ' + JSON.stringify(kontrak.data) }

        const tagihan = await panggil('/tagihan', 'POST', {
          kontrak_id: kontrak.data.data.id, periode: '${PERIODE}',
        })
        if (tagihan.status !== 201) return { galat: 'tagihan ' + nama + ': ' + JSON.stringify(tagihan.data) }

        return { tagihanId: tagihan.data.data.id }
      }

      const a = await buat('T9-A', 'Sari Sembilan', 't9-a@kos.test')
      if (a.galat) return a
      const b = await buat('T9-B', 'Bayu Sembilan', 't9-b@kos.test')
      if (b.galat) return b

      return { tagihanA: a.tagihanId, tagihanB: b.tagihanId }
    })()
  `)
  lapor(
    'dua penghuni dengan satu tagihan masing-masing siap',
    !siap.galat,
    siap.galat || `#${siap.tagihanA} dan #${siap.tagihanB}`,
  )
  if (siap.galat) throw new Error('data uji gagal disiapkan: ' + siap.galat)

  // --- Penghuni A mengaktifkan akunnya ------------------------------------
  //
  // Akunnya lahir tanpa sandi yang diketahui siapa pun. Tanpa langkah ini,
  // login di bawah akan gagal 403 "akun belum diaktifkan" - dan pesan itu
  // benar: yang belum terjadi adalah aktivasi, bukan isolasi tagihan.
  const aktifA = await aktifkanAkun(ws, 't9-a@kos.test', SANDI_A, SEJAK)
  lapor('penghuni A mengaktifkan akunnya lewat tautan email', aktifA)
  if (!aktifA) throw new Error('aktivasi penghuni A gagal')

  // --- Portal penghuni: hanya tagihannya sendiri ---------------------------
  const masukA = await masukSebagai(ws, 't9-a@kos.test', SANDI_A)
  lapor('login penghuni A berhasil', masukA, await evaluasi(ws, 'location.pathname'))
  if (!masukA) throw new Error('login penghuni gagal')

  // Akun baru mendarat di gerbang persetujuan PDP (T28). Skrip ini menguji
  // isolasi tagihan, bukan gerbang itu - membiarkannya berdiri membuat
  // kegagalannya terbaca seperti kebocoran data.
  const lolosPdp = await lewatiPersetujuanPdp(ws)
  lapor('gerbang persetujuan PDP dilewati', lolosPdp, await evaluasi(ws, 'location.pathname'))

  // Tanggal simulasi disimpan di localStorage supaya portal ikut menghitung
  // denda terhadap tanggal demo yang sama dengan layar pemilik.
  await evaluasi(ws, `
    (() => {
      localStorage.setItem('kos.simulasiTanggal', '${SIMULASI}')
      return true
    })()
  `)
  await kirim(ws, 'Page.navigate', { url: `${APP}/penghuni` })

  const portal = await tungguSampai(ws, `document.querySelector('.tabel tbody tr')`)
  lapor('portal penghuni menampilkan daftar tagihan', portal)

  const isiPortal = await evaluasi(ws, `
    (() => {
      const baris = [...document.querySelectorAll('.tabel tbody tr')]
        .filter(tr => tr.querySelector('.lencana-status'))
      const lencana = baris[0]?.querySelector('.lencana-status')
      return {
        jumlah: baris.length,
        teks: document.querySelector('.tabel')?.textContent || '',
        warna: lencana ? getComputedStyle(lencana).color : '',
        latar: lencana ? getComputedStyle(lencana).backgroundColor : '',
      }
    })()
  `)
  lapor('penghuni A hanya melihat satu tagihan', isiPortal.jumlah === 1, `${isiPortal.jumlah} baris`)
  lapor(
    'yang tampil adalah tagihan penghuni A, bukan milik B',
    teksMemuat(isiPortal.teks, 'T9-A') && !teksMemuat(isiPortal.teks, 'T9-B'),
    isiPortal.teks.replace(/\s+/g, ' ').trim().slice(0, 130),
  )
  lapor(
    'lencana status berwarna, bukan putih di atas putih',
    isiPortal.warna !== 'rgb(255, 255, 255)' && isiPortal.latar !== 'rgb(255, 255, 255)',
    `${isiPortal.warna} di atas ${isiPortal.latar}`,
  )

  // --- Rincian: denda dan riwayat muncul di layar --------------------------
  const rincian = await evaluasi(ws, `
    (async () => {
      const tombol = [...document.querySelectorAll('.tabel tbody button')]
        .find(b => /rincian/i.test(b.textContent))
      if (!tombol) return { galat: 'tombol rincian tidak ada' }
      tombol.click()
      await new Promise(r => setTimeout(r, 1600))
      const panel = document.querySelector('.rincian')
      if (!panel) return { galat: 'rincian tidak terbuka' }
      return { teks: panel.textContent }
    })()
  `)
  lapor(
    'rincian menampilkan denda dan riwayat pembayaran',
    !rincian.galat && teksMemuat(rincian.teks, 'Denda') && teksMemuat(rincian.teks, 'Riwayat pembayaran'),
    rincian.galat || rincian.teks.replace(/\s+/g, ' ').trim().slice(0, 140),
  )
  lapor(
    'rincian menyebut angka denda yang benar',
    !rincian.galat && teksMemuat(rincian.teks, DENDA_DIHARAPKAN.toLocaleString('id-ID')),
    rincian.galat || `diharapkan ${rupiah(DENDA_DIHARAPKAN)}`,
  )

  await tangkapLayar(ws, 'T9-portal-penghuni')
  lapor('tangkapan layar portal tersimpan', true)

  // --- Pintu samping: tagihan penghuni lain harus 404 ----------------------
  const milikSendiri = await panggilApi(ws, `/tagihan/${siap.tagihanA}`)
  const milikOrangLain = await panggilApi(ws, `/tagihan/${siap.tagihanB}`)
  const dendaOrangLain = await panggilApi(ws, `/tagihan/${siap.tagihanB}/denda`)
  const tidakAda = await panggilApi(ws, `/tagihan/999999`)
  const daftar = await panggilApi(ws, '/penghuni/tagihan')

  lapor('penghuni A boleh membaca tagihannya sendiri', milikSendiri.status === 200, String(milikSendiri.status))
  lapor('tagihan penghuni B dijawab 404, bukan 403', milikOrangLain.status === 404, String(milikOrangLain.status))
  lapor('pintu samping /denda juga menutup tagihan orang lain', dendaOrangLain.status === 404, String(dendaOrangLain.status))
  lapor('id yang tidak ada menghasilkan jawaban yang sama persis', tidakAda.status === 404, String(tidakAda.status))
  lapor(
    'daftar portal hanya berisi satu tagihan',
    (daftar.data?.data || []).length === 1,
    `${(daftar.data?.data || []).length} baris`,
  )

  // --- Penghuni ditolak di endpoint pemilik -------------------------------
  const tagihanPemilik = await panggilApi(ws, '/tagihan')
  const audit = await panggilApi(ws, '/audit-log')
  const kamar = await panggilApi(ws, '/kamar')
  const bayar = await panggilApi(ws, `/tagihan/${siap.tagihanA}/pembayaran`, 'POST', {
    jumlah: 1000,
    tanggal_pembayaran: SIMULASI,
  })

  lapor(
    'penghuni ditolak 403 di seluruh endpoint pemilik',
    tagihanPemilik.status === 403 && audit.status === 403 && kamar.status === 403,
    `tagihan=${tagihanPemilik.status} audit=${audit.status} kamar=${kamar.status}`,
  )
  lapor('penghuni tidak bisa mencatat pembayaran', bayar.status === 403, String(bayar.status))

  // --- Log audit: pemilik membaca, tidak ada tombol mengubah --------------
  const masukLagi = await masukSebagai(ws, 'pemilik@kos.test')
  lapor('login pemilik untuk log audit berhasil', masukLagi)
  if (!masukLagi) throw new Error('login pemilik ulang gagal')

  // Nama aktor diambil dari `/me`, bukan ditulis di skrip: nama pemilik berasal
  // dari seeder, dan asersi yang menghafalnya akan gagal setiap kali data
  // contoh diubah - kegagalan yang tidak berarti apa-apa tentang aplikasi.
  const namaPemilik = await evaluasi(ws, `
    (async () => {
      const res = await fetch('${API}/me', {
        headers: {
          Accept: 'application/json',
          Authorization: 'Bearer ' + localStorage.getItem('kos.token'),
        },
      })
      const isi = await res.json()
      return isi.data?.nama || ''
    })()
  `)

  await kirim(ws, 'Page.navigate', { url: `${APP}/pemilik/audit` })
  await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes('Jejak audit')`)
  const adaLog = await tungguSampai(ws, `document.querySelector('.tabel-audit tbody tr')`)

  const isiAudit = await evaluasi(ws, `
    (() => {
      const baris = [...document.querySelectorAll('.tabel-audit tbody tr')]
        .filter(tr => tr.querySelector('.lencana-aksi'))
      const tombol = [...document.querySelectorAll('.tabel-audit button, .tabel-audit form')]
      return {
        jumlah: baris.length,
        teks: document.querySelector('.tabel-audit')?.textContent || '',
        adaTombolUbah: tombol.length,
        adaAksi: baris.map(tr => tr.querySelector('.lencana-aksi')?.textContent.trim()),
        aktor: baris.map(tr => tr.querySelector('td:nth-child(2) strong')?.textContent.trim()),
      }
    })()
  `)
  lapor('halaman jejak audit terbuka untuk pemilik', Boolean(adaLog))
  lapor(
    'log berisi kejadian penerbitan tagihan',
    isiAudit.adaAksi.some((a) => teksMemuat(a || '', 'Tagihan diterbitkan')),
    isiAudit.adaAksi.join(', '),
  )
  lapor(
    'log menyebut aktor yang melakukannya',
    Boolean(namaPemilik) && isiAudit.aktor.every((a) => a === namaPemilik),
    `aktor: ${[...new Set(isiAudit.aktor)].join(', ') || '(kosong)'} | diharapkan ${namaPemilik}`,
  )
  lapor(
    'tidak ada tombol untuk mengubah atau menghapus log',
    isiAudit.adaTombolUbah === 0,
    `${isiAudit.adaTombolUbah} tombol/form`,
  )

  await tangkapLayar(ws, 'T9-log-audit')
  lapor('tangkapan layar log audit tersimpan', true)

  await uji.selesai()
})
