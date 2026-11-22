import { ref } from 'vue'
import { sesi } from '../sesi.js'
import { pesanGalat } from '../format.js'

/**
 * Memuat satu daftar dari API dengan tiga keadaan yang selalu dibedakan:
 * **memuat**, **gagal memuat**, dan **kosong** (REV-UI-04).
 *
 * Pola ini dulu disalin ke 16 layar, dan yang paling mahal bukan duplikasinya
 * melainkan satu kekeliruan yang ikut tersalin: `memuat` yang tidak pernah
 * kembali `false` saat gagal, atau baris "Belum ada ..." yang tampil padahal
 * datanya gagal diambil. Yang kedua bukan sekadar salah tampil - ia menyembunyikan
 * tagihan yang sudah jatuh tempo di balik kalimat "belum ada".
 *
 * `galatMuat` sengaja dibiarkan kosong sampai ada kegagalan: layar memakainya
 * sebagai satu-satunya tanda untuk menyembunyikan baris kosong.
 *
 * @param {string} jalur path API, mis. '/penghuni'
 */
export function useMuatDaftar(jalur) {
  const daftar = ref([])
  const memuat = ref(true)
  const galatMuat = ref('')

  async function muat() {
    memuat.value = true
    galatMuat.value = ''
    try {
      const hasil = await sesi.panggil(jalur)
      daftar.value = hasil.data || []
    } catch (e) {
      galatMuat.value = pesanGalat(e)
    } finally {
      memuat.value = false
    }
  }

  return { daftar, memuat, galatMuat, muat }
}
