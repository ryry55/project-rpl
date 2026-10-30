/**
 * Pemotong halaman untuk daftar yang dimuat utuh.
 *
 * Dipotong di layar, bukan di server. `GET /tagihan` tanpa `per_page` sudah
 * mengembalikan seluruh daftar, dan kepala halaman menjumlahkan pokok, denda,
 * serta sisa dari daftar ITU. Meminta per halaman ke server akan mengubah arti
 * ketiga angka uang itu menjadi "halaman ini" tanpa ada yang menyadarinya -
 * dan `sisa` per baris hanya bisa dihitung di PHP (denda bergantung pada hari
 * ini, pemaafan, dan pembatalan), jadi menjumlahkannya ulang di SQL berarti
 * salinan kedua aturan uang.
 *
 * shortcut: satu kos berisi paling banyak 30 kamar, jadi setahun penuh masih
 * ratusan baris dan muatan awalnya tidak terasa. Pindah ke `per_page` di server
 * bila suatu kos melewati ~2.000 baris.
 */
export const PER_HALAMAN = 50

export function jumlahHalaman(total, perHalaman = PER_HALAMAN) {
  return Math.max(1, Math.ceil(total / perHalaman))
}

/**
 * Baris untuk satu halaman. Halaman di luar rentang menghasilkan daftar kosong,
 * bukan baris pertama: halaman yang tidak ada tidak boleh menampilkan data
 * halaman lain seolah-olah itu isinya.
 */
export function potong(daftar, halaman, perHalaman = PER_HALAMAN) {
  const semua = Array.isArray(daftar) ? daftar : []
  const mulai = (Math.trunc(halaman) - 1) * perHalaman
  return semua.slice(mulai, mulai + perHalaman)
}
