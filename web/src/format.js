/**
 * Pemformat tampilan.
 *
 * Semua uang di sistem ini bilangan bulat rupiah (numeric(14,0)). Karena itu
 * tampilannya sengaja tanpa desimal: kalau ada nilai pecahan yang sampai ke
 * sini, itu tanda ada yang salah di hulu - dan `Intl` membulatkannya ke rupiah
 * terdekat, jadi jangan andalkan layar untuk menemukannya.
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

/**
 * Persentase dengan koma desimal, satu angka di belakang koma.
 *
 * `null` berarti "belum bisa dihitung" - kos yang belum punya kamar membagi
 * nol - dan ditampilkan sebagai tanda hubung. Menampilkannya sebagai "0%"
 * akan terbaca sebagai "tidak ada yang menempati", dua keadaan yang berbeda.
 */
export function persen(nilai) {
  if (nilai === null || nilai === undefined) {
    return '—'
  }
  return `${String(nilai).replace('.', ',')}%`
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

/**
 * Tanggal berlaku yang membuat kebijakan langsung terpakai untuk bulan berjalan.
 *
 * Kebijakan diselesaikan terhadap **awal periode**, bukan terhadap hari ini
 * (`PenyelesaiKebijakan::semuaBerlaku`), karena tagihan Agustus yang terbit
 * September harus memakai kebijakan yang berlaku pada Agustus. Akibatnya
 * `berlaku_dari` = hari ini tidak terpakai untuk periode bulan ini: tanggal 23
 * lebih besar dari awal bulan, jadi barisnya belum berlaku sampai bulan depan.
 *
 * Itu membingungkan di layar: pemilik menekan Tetapkan, membaca "Kebijakan
 * tersimpan", dan gerbangnya tetap menutup. Yang benar bukan mengubah cara
 * penyelesaian - itu akan merusak R-06 - melainkan menawarkan tanggal yang
 * memang langsung berlaku.
 */
export function tanggalBerlakuBulanIni() {
  const sekarang = new Date()
  const bulan = String(sekarang.getMonth() + 1).padStart(2, '0')
  return `${sekarang.getFullYear()}-${bulan}-01`
}

/**
 * Label status yang bisa dibaca: `belum_bayar` → "belum bayar".
 *
 * Dipakai di layar pemilik, yang menampilkan status apa adanya dalam huruf kecil
 * agar warnanya (dari `status-<nilai>`) dan teksnya berasal dari satu sumber.
 * Portal penghuni punya label berhuruf besar ("Belum bayar") yang sengaja
 * berbeda — jangan disatukan tanpa memutuskan ulang tampilannya.
 */
export function labelStatus(status) {
  return String(status || '').replace(/_/g, ' ')
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
