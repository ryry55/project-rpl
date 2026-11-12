import { ref } from 'vue'
import { sesi } from './sesi.js'

/**
 * Nama kos untuk layar yang dibuka sebelum masuk (K-17).
 *
 * Dimuat sekali di `main.js`, bukan di tiap komponen: layar masuk, bilah
 * pemilik, dan kuitansi harus menampilkan nama yang sama, dan tiga permintaan
 * untuk satu nilai yang tidak berubah di tengah sesi hanya menambah waktu muat.
 *
 * Nilai bawaannya "Kos-Kosan" — sama dengan nilai bawaan server. Kos yang belum
 * menamai dirinya tidak boleh kehilangan judulnya hanya karena permintaannya
 * gagal atau belum selesai.
 */
export const namaKos = ref('Kos-Kosan')

let janji = null

export function muatIdentitasKos() {
  if (janji) {
    return janji
  }

  janji = sesi
    .panggil('/identitas-kos')
    .then((hasil) => {
      const nama = String(hasil?.data?.nama || '').trim()
      if (nama) {
        namaKos.value = nama
        // Judul tab ikut nama kos: itu satu-satunya tempat nama kos terlihat
        // sebelum halaman dirender, dan "Kos-Kosan" di tab kos orang lain
        // terbaca seperti aplikasi yang belum disiapkan.
        document.title = `${nama} - Sistem Informasi Operasional`
      }
    })
    .catch(() => {
      // Nama kos bukan alasan menggagalkan layar. Nilai bawaan sudah benar.
    })

  return janji
}
