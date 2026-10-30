import { describe, expect, it } from 'vitest'
import { galatPerBidang, labelStatus, persen, pesanGalat, rupiah, tanggal } from './format.js'

// Intl menyisipkan spasi tak-terpisah antara "Rp" dan angkanya. Disamakan di
// sini supaya asersinya tidak bergantung pada karakter tak terlihat.
const tanpaSpasiKhusus = (teks) => teks.replace(/\u00a0/g, ' ')

describe('rupiah', () => {
  it('menulis jutaan dengan titik ribuan dan tanpa desimal', () => {
    expect(tanpaSpasiKhusus(rupiah(1500000))).toBe('Rp 1.500.000')
  })

  it('nol adalah Rp 0, bukan tanda hubung', () => {
    expect(tanpaSpasiKhusus(rupiah(0))).toBe('Rp 0')
  })

  it('nilai kosong tampil sebagai tanda hubung, bukan Rp 0', () => {
    expect(tanpaSpasiKhusus(rupiah(null))).toBe('—')
    expect(tanpaSpasiKhusus(rupiah(''))).toBe('—')
  })

  it('miliaran rupiah tetap terbaca', () => {
    expect(tanpaSpasiKhusus(rupiah(500000000))).toBe('Rp 500.000.000')
  })
})

describe('persen', () => {
  it('memakai koma desimal', () => {
    expect(persen(87.5)).toBe('87,5%')
  })

  it('null berarti belum bisa dihitung, bukan nol persen', () => {
    expect(persen(null)).toBe('—')
  })
})

describe('tanggal', () => {
  it('tanggal murni tidak bergeser sehari karena zona waktu', () => {
    // Cacat yang dijaga: `new Date("2026-10-01")` adalah tengah malam UTC, yang
    // di WIB sudah 1 Oktober pukul 07.00 - tetapi di zona lain bisa terbaca
    // 30 September. Kalau bergeser, hasilnya akan sama dengan tanggal sehari
    // sebelumnya.
    expect(tanggal('2026-10-01')).not.toBe(tanggal('2026-09-30'))
    expect(tanggal('2026-10-01')).toContain('2026')
  })

  it('teks kosong jadi tanda hubung, teks asing dikembalikan apa adanya', () => {
    expect(tanggal('')).toBe('—')
    expect(tanggal('bukan tanggal')).toBe('bukan tanggal')
  })
})

describe('labelStatus', () => {
  it('mengganti garis bawah dengan spasi', () => {
    expect(labelStatus('belum_bayar')).toBe('belum bayar')
  })
})

describe('pesan galat', () => {
  it('menggabungkan seluruh pesan validasi 422', () => {
    const e = { message: 'Data tidak valid', errors: { nama: ['wajib diisi'], nomor: ['sudah dipakai'] } }
    expect(pesanGalat(e)).toBe('wajib diisi sudah dipakai')
  })

  it('tanpa rincian bidang memakai pesan umum', () => {
    expect(pesanGalat({ message: 'Terlarang' })).toBe('Terlarang')
  })

  it('galatPerBidang mengambil pesan pertama tiap bidang', () => {
    const e = { errors: { nama: ['wajib diisi', 'terlalu pendek'], nomor: 'sudah dipakai' } }
    expect(galatPerBidang(e)).toEqual({ nama: 'wajib diisi', nomor: 'sudah dipakai' })
  })
})
