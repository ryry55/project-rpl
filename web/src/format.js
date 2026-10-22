/**
 * Pemformat tampilan.
 *
 * Semua uang di sistem ini bilangan bulat rupiah (numeric(14,0)). Karena itu
 * pemformatan tidak pernah membulatkan: kalau ada nilai pecahan yang sampai ke
 * sini, itu tanda ada yang salah di hulu, dan tampilannya akan menunjukkan
 * pecahan itu alih-alih menyembunyikannya.
 */

const pemformatRupiah = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  minimumFractionDigits: 0,
  maximumFractionDigits: 0,
})

export function rupiah(nilai) {
  if (nilai === null || nilai === undefined || nilai === '') {
    return '—'
  }
  return pemformatRupiah.format(Number(nilai))
}

const pemformatTanggal = new Intl.DateTimeFormat('id-ID', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
})

/**
 * Tanggal dari API berbentuk "YYYY-MM-DD" (tanggal murni, tanpa zona waktu).
 * `new Date("2026-10-15")` menafsirkannya sebagai tengah malam UTC, yang di
 * WIB sudah bergeser ke hari berikutnya pukul 07.00 — jadi tanggalnya bisa
 * tampil salah satu hari. Karena itu bagian tanggalnya diurai manual.
 */
export function tanggal(teks) {
  if (!teks) {
    return '—'
  }
  const cocok = /^(\d{4})-(\d{2})-(\d{2})/.exec(teks)
  if (!cocok) {
    return teks
  }
  const [, t, b, h] = cocok
  return pemformatTanggal.format(new Date(Number(t), Number(b) - 1, Number(h)))
}

export function tanggalHariIni() {
  const sekarang = new Date()
  const bulan = String(sekarang.getMonth() + 1).padStart(2, '0')
  const hari = String(sekarang.getDate()).padStart(2, '0')
  return `${sekarang.getFullYear()}-${bulan}-${hari}`
}

export function pesanGalat(e) {
  if (e?.errors && Object.keys(e.errors).length > 0) {
    return Object.values(e.errors).flat().join(' ')
  }
  return e?.message || 'Terjadi kesalahan yang tidak terduga.'
}

/**
 * Ringkas galat validasi 422 menjadi peta { namaBidang: pesan } agar bisa
 * ditempel langsung di bawah bidang yang salah.
 */
export function galatPerBidang(e) {
  const hasil = {}
  for (const [bidang, daftar] of Object.entries(e?.errors || {})) {
    hasil[bidang] = Array.isArray(daftar) ? daftar[0] : String(daftar)
  }
  return hasil
}
