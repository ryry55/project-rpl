/**
 * `v-kartu-tabel` — mengubah tabel lebar menjadi kartu di layar sempit.
 *
 * === Kenapa direktif, bukan mengubah 20 tabel satu per satu ===
 *
 * Cara yang biasa dipakai adalah menuliskan `data-label="Periode"` pada setiap
 * `<td>` lalu menyembunyikan `<thead>` lewat CSS. Itu bekerja, tetapi labelnya
 * menjadi salinan kedua dari judul kolom: begitu judul di `<th>` diubah, label
 * di `<td>` tertinggal dan kartu menampilkan nama kolom yang sudah tidak ada.
 * Dengan 20 tabel dan ~150 sel, salinan itu pasti melenceng.
 *
 * Direktif ini menurunkan label dari `<th>` yang memang sudah ada, sehingga
 * hanya ada satu sumber kebenaran. Ia juga menandai tabelnya sendiri
 * (`kelas tabel-kartu`) hanya bila kolomnya cukup banyak untuk perlu dilipat -
 * tabel 2-3 kolom sudah muat di ponsel dan tidak perlu diubah bentuk.
 *
 * === Kenapa struktur DOM tidak diubah ===
 *
 * Yang berubah hanya CSS. `<table>`, `<tbody>`, `<tr>`, dan `<td>` tetap ada di
 * tempatnya, jadi seluruh selektor uji (`table.tabel tbody tr`, `td`,
 * `.lencana-status`) tetap bekerja, dan pembaca layar tetap menemukan tabel
 * yang sah. Mengganti tabel menjadi `<div>` akan merusak keduanya sekaligus.
 */

/** Jumlah kolom minimum agar tabel dilipat menjadi kartu. */
const KOLOM_MINIMUM = 4

/**
 * Menyalin teks `<th>` ke `data-label` setiap `<td>` pada baris yang sama.
 *
 * Dijalankan ulang setiap kali isi tabel berubah, karena barisnya datang
 * belakangan dari API - saat `mounted` tabel masih kosong.
 */
function terapkan(el) {
  const judul = Array.from(el.querySelectorAll('thead th')).map((th) =>
    (th.textContent || '').trim(),
  )

  // Tabel sempit tidak perlu dilipat. Dilepas lagi bila jumlah kolomnya
  // berubah, supaya keputusannya selalu mengikuti isi yang terakhir.
  if (judul.length < KOLOM_MINIMUM) {
    el.classList.remove('tabel-kartu')
    return
  }
  el.classList.add('tabel-kartu')

  for (const tr of el.querySelectorAll('tbody tr')) {
    const sel = tr.querySelectorAll('td')
    // Baris "kosong" memakai satu sel ber-`colspan`; ia bukan data, jadi tidak
    // diberi label - kartunya akan menampilkan nama kolom di atas kalimat
    // "Belum ada tagihan", yang menyesatkan.
    if (sel.length === 1 && sel[0].hasAttribute('colspan')) continue

    sel.forEach((td, i) => {
      const label = judul[i]
      if (label) td.setAttribute('data-label', label)
      else td.removeAttribute('data-label')
    })
  }
}

export default {
  mounted(el) {
    terapkan(el)

    // Baris muncul setelah permintaan API selesai, dan Vue menambahkannya
    // sebagai anak baru - bukan sebagai pembaruan komponen. Tanpa pengamat ini,
    // tabel yang dimuat asinkron akan tetap tanpa label selamanya.
    //
    // Hanya `childList` yang diamati. `data-label` adalah perubahan atribut,
    // dan mengamati atribut akan memicu dirinya sendiri tanpa henti.
    el._pengamatKartu = new MutationObserver(() => terapkan(el))
    el._pengamatKartu.observe(el, { childList: true, subtree: true })
  },

  updated(el) {
    terapkan(el)
  },

  unmounted(el) {
    if (el._pengamatKartu) {
      el._pengamatKartu.disconnect()
      delete el._pengamatKartu
    }
  },
}
