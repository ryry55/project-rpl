/**
 * Berbagi rincian tagihan lewat WhatsApp dan papan klip (REV-UI-03).
 *
 * Sebelum ini pemilik yang ingin menagih lewat WhatsApp harus membuka tagihan
 * di layar, lalu mengetik ulang nominal, periode, dan jatuh temponya di
 * WhatsApp. Pekerjaan itu berulang tiap bulan untuk tiap penghuni, dan setiap
 * pengetikan ulang adalah kesempatan salah angka.
 *
 * **Kenapa teksnya disusun di sini, bukan dikirim server.** Pesannya adalah
 * kalimat untuk manusia, bukan data: ia memuat nama penghuni, nomor kamar, dan
 * kalimat penutup yang wajar. Menyimpannya di server berarti mengubah satu
 * kalimat sapaan menuntut deploy - dan pemilik kos yang ingin menambahkan
 * "terima kasih" pada pesannya tidak akan pernah bisa.
 *
 * **Kenapa `wa.me` dan bukan WhatsApp Business API.** `wa.me` membuka WhatsApp
 * yang sudah terpasang di perangkat dengan teks yang sudah terisi; pemilik
 * tetap menekan kirim sendiri. Itu yang dibutuhkan kos kecil: tidak ada biaya
 * per pesan, tidak ada verifikasi bisnis, dan tidak ada kemungkinan pesan
 * terkirim ke orang yang salah tanpa dilihat manusia. WhatsApp Business API
 * adalah keputusan produksi tersendiri yang tidak diambil di sini.
 */

import { rupiah, tanggal } from './format.js'

/**
 * Normalkan nomor telepon Indonesia menjadi bentuk yang diterima `wa.me`.
 *
 * `wa.me` menuntut kode negara tanpa tanda `+` dan tanpa pemisah. Nomor kos
 * dicatat pemilik dengan segala bentuk: `0812...`, `+62 812-...`,
 * `62 812 ...`. Menyerahkan bentuk aslinya ke `wa.me` menghasilkan tautan yang
 * terbuka ke percakapan kosong - dan itu tampak seperti fitur yang rusak,
 * bukan seperti nomor yang salah tulis.
 *
 * Mengembalikan `null` bila nomornya tidak bisa dipahami, supaya pemanggil
 * bisa memilih tidak menampilkan tombol sama sekali alih-alih menampilkan
 * tombol yang membuka WhatsApp tanpa tujuan.
 */
export function nomorWa(nomor) {
  if (!nomor) {
    return null
  }

  const angka = String(nomor).replace(/\D/g, '')

  if (angka.length < 8) {
    return null
  }

  if (angka.startsWith('62')) {
    return angka
  }

  if (angka.startsWith('0')) {
    return `62${angka.slice(1)}`
  }

  // Nomor tanpa awalan apa pun: diperlakukan sebagai nomor lokal tanpa nol.
  return `62${angka}`
}

/**
 * Susun teks tagihan yang siap dikirim.
 *
 * Sengaja memuat angka yang **sudah dihitung server**, termasuk denda dan
 * sisa. Menghitung ulang di sini akan menghasilkan dua angka berbeda untuk
 * tagihan yang sama begitu aturan denda berubah - dan yang dikirim ke penghuni
 * adalah angka yang tidak dilihat pemilik di layarnya.
 *
 * Baris denda hanya muncul bila memang ada. Menulis "Denda Rp 0" pada tagihan
 * yang belum jatuh tempo akan membuat penghuni mengira dendanya pernah
 * dihitung dan kebetulan nol, padahal saat itu belum ada denda sama sekali.
 */
export function pesanTagihan(tagihan, opsi = {}) {
  const nama = tagihan?.kontrak?.penghuni?.nama || 'Penghuni'
  const kamar = tagihan?.kontrak?.kamar?.nomor
  const baris = []

  baris.push(`Halo ${nama}, berikut tagihan kos Anda${kamar ? ` untuk kamar ${kamar}` : ''}.`)
  baris.push('')

  baris.push(`Periode: ${namaBulan(tagihan?.periode)}`)
  baris.push(`Pokok sewa: ${rupiah(tagihan?.pokok || 0)}`)

  if (Number(tagihan?.denda) > 0) {
    baris.push(`Denda keterlambatan: ${rupiah(tagihan.denda)}`)
  }

  baris.push(`Total: ${rupiah(tagihan?.total || 0)}`)

  if (Number(tagihan?.dibayar) > 0) {
    baris.push(`Sudah dibayar: ${rupiah(tagihan.dibayar)}`)
    baris.push(`Sisa: ${rupiah(tagihan?.sisa || 0)}`)
  }

  baris.push(`Jatuh tempo: ${tanggal(tagihan?.jatuh_tempo)}`)

  // Rekening disebut hanya bila memang ada. Kalimat "transfer ke rekening yang
  // tertera di portal" pada pesan WhatsApp hanya menambah satu langkah tanpa
  // memberi tahu apa pun.
  const rekening = opsi.rekening || []
  if (rekening.length > 0) {
    baris.push('')
    baris.push('Pembayaran dapat dikirim ke:')
    for (const r of rekening) {
      const bagian = [r.nama]
      if (r.nomor) {
        bagian.push(r.nomor)
      }
      if (r.atas_nama) {
        bagian.push(`a.n. ${r.atas_nama}`)
      }
      baris.push(`- ${bagian.join(' · ')}`)
    }
  }

  if (opsi.catatan) {
    baris.push('')
    baris.push(opsi.catatan)
  }

  return baris.join('\n')
}

/**
 * Nama bulan dalam bahasa Indonesia dari tanggal ISO.
 *
 * Tidak memakai `tanggal()` karena periode adalah bulan, bukan hari: menulis
 * "01 November 2026" untuk periode November menyesatkan - seolah tagihannya
 * jatuh pada tanggal itu.
 */
export function namaBulan(iso) {
  if (!iso) {
    return '—'
  }

  const [tahun, bulan] = String(iso).split('-').map(Number)

  if (!tahun || !bulan) {
    return '—'
  }

  return new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(
    new Date(tahun, bulan - 1, 1),
  )
}

/**
 * Tautan `wa.me` siap buka.
 *
 * Mengembalikan `null` bila nomornya tidak bisa dipahami, sehingga pemanggil
 * bisa menyembunyikan tombolnya.
 */
export function tautanWa(nomor, teks) {
  const tujuan = nomorWa(nomor)

  if (!tujuan) {
    return null
  }

  return `https://wa.me/${tujuan}?text=${encodeURIComponent(teks)}`
}

/**
 * Salin teks ke papan klip.
 *
 * Dua jalur, dan urutannya penting. `navigator.clipboard` hanya tersedia pada
 * konteks aman (HTTPS atau localhost); aplikasi ini juga dijalankan lewat
 * jaringan lokal pada alamat IP biasa saat demo di kelas, dan di sana API itu
 * tidak ada. Jalur kedua memakai `textarea` sementara - usang, tetapi bekerja
 * di mana saja.
 *
 * Mengembalikan `true` bila berhasil, supaya pemanggil bisa menampilkan
 * "tersalin" alih-alih diam saja. Tombol yang tidak memberi kabar apa pun
 * akan ditekan berkali-kali.
 */
export async function salinTeks(teks) {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(teks)
      return true
    }
  } catch {
    // Dilanjutkan ke jalur kedua: penolakan izin papan klip bukan alasan
    // membuat tombolnya gagal diam-diam.
  }

  try {
    const area = document.createElement('textarea')
    area.value = teks
    area.setAttribute('readonly', '')
    // Diposisikan di luar layar, bukan `display: none`: elemen yang tidak
    // ditampilkan tidak bisa dipilih teksnya di sebagian peramban.
    area.style.position = 'fixed'
    area.style.top = '-1000px'
    document.body.appendChild(area)
    area.select()
    const berhasil = document.execCommand('copy')
    document.body.removeChild(area)
    return berhasil
  } catch {
    return false
  }
}
