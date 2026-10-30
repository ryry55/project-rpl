import { describe, expect, it } from 'vitest'
import { PER_HALAMAN, jumlahHalaman, potong } from './halaman.js'

const baris = (n) => Array.from({ length: n }, (_, i) => ({ id: i + 1 }))

describe('potong', () => {
  it('halaman pertama memuat baris pertama sampai ke-' + PER_HALAMAN, () => {
    const hasil = potong(baris(120), 1)
    expect(hasil).toHaveLength(PER_HALAMAN)
    expect(hasil[0].id).toBe(1)
    expect(hasil.at(-1).id).toBe(PER_HALAMAN)
  })

  it('halaman kedua mulai dari baris berikutnya, bukan mengulang halaman satu', () => {
    const hasil = potong(baris(120), 2)
    expect(hasil[0].id).toBe(PER_HALAMAN + 1)
    expect(hasil).toHaveLength(PER_HALAMAN)
  })

  it('halaman terakhir memuat sisanya, bukan satu halaman penuh', () => {
    expect(potong(baris(120), 3)).toHaveLength(20)
  })

  it('halaman di luar rentang kosong, bukan menampilkan data halaman lain', () => {
    expect(potong(baris(120), 4)).toEqual([])
    expect(potong([], 1)).toEqual([])
  })
})

describe('jumlahHalaman', () => {
  it('daftar kosong tetap satu halaman, supaya teks "halaman 1 dari 1" tidak hilang', () => {
    expect(jumlahHalaman(0)).toBe(1)
  })

  it('tepat satu halaman penuh masih satu halaman', () => {
    expect(jumlahHalaman(PER_HALAMAN)).toBe(1)
  })

  it('satu baris lebih membuat halaman kedua', () => {
    expect(jumlahHalaman(PER_HALAMAN + 1)).toBe(2)
  })
})
