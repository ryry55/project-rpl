// Uji tata letak: tidak ada halaman yang meluber mendatar di layar ponsel.
//
// Kenapa skrip ini ada.
//
// Seluruh uji lain memeriksa isi DOM dan jawaban HTTP. Tak satu pun memeriksa
// ukuran, sehingga satu kelas cacat lolos sepenuhnya: halaman yang benar
// isinya tetapi lebih lebar daripada layar. Gejalanya hanya terlihat di
// perangkat sungguhan - pengguna harus menggeser seluruh halaman ke samping
// untuk membaca kolom terakhir, dan yang paling kanan (tombol aksi) justru
// yang paling sering hilang dari pandangan.
//
// Cacat yang mendorong skrip ini ditulis: `.tata-letak` dan `.halaman`
// memakai `grid-template-columns: 1fr`. `1fr` berarti `minmax(auto, 1fr)`,
// dan minimum `auto` mengambil lebar min-content - sehingga sidebar dengan
// menu mendatar memaksa halaman selebar 753px di layar 390px. Tidak ada galat
// konsol, tidak ada permintaan gagal, tidak ada teks yang hilang. Hanya
// halaman yang bergulir ke samping.
//
// Pakai: node docs/uji/uji-tata-letak.mjs
// Prasyarat: docker compose up, Chrome --remote-debugging-port=9222,
// APP_DEMO_MODE=true, dan database sudah di-seed (migrate:fresh --seed).

import {
  APP,
  mulaiUji,
  masukSebagai,
  panggilApi,
  evaluasi,
  tungguSampai,
  kirim,
  jalankan,
  pastikanKebijakan,
} from './_cdp.mjs'

/**
 * Lebar yang diuji.
 *
 * 360px adalah lebar ponsel Android yang paling sempit yang masih umum
 * (mis. Galaxy A lama). Kalau halaman muat di 360, ia muat di ponsel yang
 * lebih lebar. 320px sengaja tidak dipakai: di bawah 360 tata letak memang
 * boleh mulai memampat, dan menuntutnya hanya menghasilkan perbaikan yang
 * mengejar angka.
 */
const LEBAR = [360, 390, 768]

/**
 * Memasang lebar emulasi.
 *
 * `mobile: false` bukan kelalaian. Dengan `mobile: true`, Chrome memakai
 * layout viewport bawaan ponsel (980px) alih-alih lebar yang diminta, lalu
 * memperkecil hasilnya. Akibatnya `window.innerWidth` menjadi 980 dan
 * pemeriksaan "tidak ada gulir mendatar" lulus tanpa menguji apa pun - skrip
 * yang hijau karena tidak mengukur hal yang dimaksud. Dengan `mobile: false`,
 * `innerWidth` benar-benar sama dengan lebar yang diminta, yaitu angka yang
 * dibaca media query CSS.
 */
function pasangLebar(ws, lebar) {
  return kirim(ws, 'Emulation.setDeviceMetricsOverride', {
    width: lebar,
    height: 900,
    deviceScaleFactor: 1,
    mobile: false,
  })
}

const HALAMAN_PEMILIK = [
  ['/pemilik/kamar', 'Kamar'],
  ['/pemilik/penghuni', 'Penghuni'],
  ['/pemilik/kontrak', 'Kontrak'],
  ['/pemilik/tagihan', 'Tagihan'],
  ['/pemilik/rekap', 'Rekap pendapatan'],
  ['/pemilik/tunggakan', 'Tunggakan'],
  ['/pemilik/kebijakan', 'Kebijakan kos'],
  ['/pemilik/fasilitas', 'Fasilitas'],
  ['/pemilik/aturan', 'Aturan kos'],
  ['/pemilik/audit', 'Jejak audit'],
  // Halaman pengelola (T24). Formulir wewenangnya memakai kisi dua kolom yang
  // paling mudah meluber: label seperti "Memverifikasi pembayaran" lebih lebar
  // daripada setengah layar 360px.
  ['/pemilik/pengelola', 'Pengelola'],
]

jalankan(async () => {
  const uji = await mulaiUji()
  const { ws, lapor } = uji

  try {
    const masuk = await masukSebagai(ws, 'pemilik@kos.test')
    lapor('login pemilik berhasil', masuk)
    if (!masuk) throw new Error('login gagal')

    // Halaman tagihan dan tunggakan hanya berisi kalau ada data. Tanpa ini,
    // tabelnya kosong dan luberan yang seharusnya terdeteksi tidak muncul -
    // skrip akan hijau karena tidak ada yang diukur.
    const kebijakan = await pastikanKebijakan(ws)
    lapor('kebijakan kos siap', kebijakan.ok, kebijakan.alasan)

    const kamar = await panggilApi(ws, '/kamar', 'POST', { nomor: 'TL-1', tipe: 'Uji Tata Letak' })
    const kamarId = kamar.data?.data?.id
    if (kamarId) {
      await panggilApi(ws, '/tarif', 'POST', {
        kamar_id: kamarId,
        nilai: 1500000,
        berlaku_dari: '2026-01-01',
      })
      const penghuni = await panggilApi(ws, '/penghuni', 'POST', {
        nama: 'Penghuni Tata Letak Dengan Nama Yang Cukup Panjang',
        email: 'tata-letak@kos.test',
        password: 'rahasia123',
      })
      const kontrak = await panggilApi(ws, '/kontrak', 'POST', {
        kamar_id: kamarId,
        penghuni_id: penghuni.data?.data?.id,
        tgl_masuk: '2026-01-01',
        deposit: 0,
      })
      if (kontrak.data?.data?.id) {
        await panggilApi(ws, '/tagihan', 'POST', {
          kontrak_id: kontrak.data.data.id,
          periode: '2026-02-01',
        })
      }
    }
    lapor('data uji tata letak siap', Boolean(kamarId), String(kamarId))

    for (const lebar of LEBAR) {
      for (const [jalur, judul] of HALAMAN_PEMILIK) {
        await kirim(ws, 'Page.navigate', { url: `${APP}${jalur}` })
        await tungguSampai(ws, `document.querySelector('h1')?.textContent.includes(${JSON.stringify(judul)})`)
        // Emulasi dilepas saat halaman baru dimuat, jadi dipasang lagi di sini.
        await pasangLebar(ws, lebar)
        await new Promise((r) => setTimeout(r, 500))

        const ukur = await evaluasi(
          ws,
          `
          (() => {
            // Pelaku dicari juga, bukan hanya angkanya: tanpa nama elemen,
            // laporan hanya berkata "794 lebih besar dari 390" dan tidak
            // memberi tahu apa yang harus diperbaiki.
            const batas = window.innerWidth
            const pelaku = []
            document.querySelectorAll('*').forEach((el) => {
              const r = el.getBoundingClientRect()
              // Elemen di dalam .tabel-bungkus memang boleh lebih lebar:
              // bungkus itulah yang menggulir, bukan halamannya.
              if (r.right > batas + 1 && !el.closest('.tabel-bungkus')) {
                pelaku.push(el.tagName + '.' + String(el.className || '').split(' ')[0])
              }
            })
            return {
              lebar: batas,
              dokumen: document.documentElement.scrollWidth,
              luber: document.documentElement.scrollWidth > batas + 1,
              pelaku: [...new Set(pelaku)].slice(0, 4),
            }
          })()
        `,
        )

        // Asersi pertama menjaga asersi kedua tetap bermakna: kalau emulasi
        // gagal dan halaman ditata pada lebar jendela, "tidak luber" akan
        // lulus tanpa menguji apa pun.
        lapor(
          `${lebar}px ${judul}: lebar emulasi benar-benar berlaku`,
          ukur?.lebar === lebar,
          `innerWidth ${ukur?.lebar}`,
        )
        lapor(
          `${lebar}px ${judul}: tidak meluber mendatar`,
          !ukur?.luber,
          ukur?.luber ? `dokumen ${ukur.dokumen} > ${ukur.lebar} (${ukur.pelaku.join(', ')})` : '',
        )
      }
    }

    // Portal penghuni punya tata letaknya sendiri (bukan `.tata-letak`), jadi
    // diperiksa terpisah - bukan diasumsikan ikut benar.
    await evaluasi(
      ws,
      `
      (async () => {
        localStorage.removeItem('kos.token')
        return true
      })()
    `,
    )
    const masukPenghuni = await masukSebagai(ws, 'penghuni@kos.test')
    lapor('login penghuni berhasil', masukPenghuni)

    for (const lebar of LEBAR) {
      await kirim(ws, 'Page.navigate', { url: `${APP}/penghuni` })
      await tungguSampai(ws, `document.querySelector('h1')`)
      await pasangLebar(ws, lebar)
      await new Promise((r) => setTimeout(r, 500))

      const ukur = await evaluasi(
        ws,
        `
        (() => {
          const batas = window.innerWidth
          const pelaku = []
          document.querySelectorAll('*').forEach((el) => {
            const r = el.getBoundingClientRect()
            if (r.right > batas + 1 && !el.closest('.tabel-bungkus')) {
              pelaku.push(el.tagName + '.' + String(el.className || '').split(' ')[0])
            }
          })
          return {
            lebar: batas,
            dokumen: document.documentElement.scrollWidth,
            luber: document.documentElement.scrollWidth > batas + 1,
            pelaku: [...new Set(pelaku)].slice(0, 4),
          }
        })()
      `,
      )

      lapor(
        `${lebar}px Portal penghuni: lebar emulasi benar-benar berlaku`,
        ukur?.lebar === lebar,
        `innerWidth ${ukur?.lebar}`,
      )
      lapor(
        `${lebar}px Portal penghuni: tidak meluber mendatar`,
        !ukur?.luber,
        ukur?.luber ? `dokumen ${ukur.dokumen} > ${ukur.lebar} (${ukur.pelaku.join(', ')})` : '',
      )
    }

    await kirim(ws, 'Emulation.clearDeviceMetricsOverride')
  } finally {
    await uji.selesai()
  }
})
