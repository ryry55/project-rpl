import { rupiah, tanggal } from './format.js'

/**
 * Kuitansi pembayaran yang dicetak lewat peramban (O-5).
 *
 * **Kenapa bukan PDF dari server.** Menghasilkan PDF berarti menambah pustaka,
 * berkas font, dan satu endpoint baru yang harus menjaga isolasi R-07 lagi.
 * Peramban sudah punya mesin cetak, dan "Simpan sebagai PDF" ada di dialog
 * cetaknya - jadi berkas PDF tetap bisa dihasilkan tanpa satu baris kode pun
 * di server.
 *
 * **Kenapa iframe, bukan `window.open`.** Peramban memblokir jendela baru
 * setelah satu klik pertama, dan kegagalannya tidak terlihat: kuitansi tidak
 * muncul tanpa pesan apa pun, lalu pengguna menekan tombolnya lagi. Iframe
 * tersembunyi tidak bisa diblokir.
 *
 * **Tanpa nomor urut**, atas keputusan pemilik. Penandanya id pembayaran dan
 * periode tagihan. Nomor urut berarti kolom baru di tabel pembayaran, dan
 * kuitansi yang dicetak ulang bisa bernomor berbeda dari yang sudah dipegang
 * penghuni.
 *
 * Isinya sengaja hanya fakta yang tercatat di sistem. Tidak ada tanda tangan
 * atau stempel: kuitansi ini bukti catatan, bukan bukti pembayaran yang sah
 * menurut hukum, dan mengarang kesan sebaliknya bukan tugas perangkat lunak.
 */
export function cetakKuitansi(isian) {
  const bingkai = document.createElement('iframe')
  bingkai.setAttribute('aria-hidden', 'true')
  bingkai.setAttribute('tabindex', '-1')
  bingkai.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0'
  bingkai.srcdoc = htmlKuitansi(isian)

  bingkai.addEventListener('load', () => {
    bingkai.contentWindow.focus()
    bingkai.contentWindow.print()
    // Iframe dibuang setelah dialog cetak selesai. Enam puluh detik memberi
    // ruang untuk dialog yang dibiarkan terbuka; iframe yang menumpuk akan
    // memperlambat layar tanpa terlihat.
    setTimeout(() => bingkai.remove(), 60_000)
  })

  document.body.appendChild(bingkai)
}

/**
 * Nama penghuni dan catatan berasal dari isian pemilik, jadi harus diloloskan
 * sebelum masuk ke dokumen. Tanpa ini, satu nama berisi `<script>` akan
 * dijalankan di dokumen cetak - yang masih se-asal-usul dengan aplikasi.
 */
function aman(teks) {
  return String(teks ?? '—').replace(
    /[&<>"]/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c],
  )
}

/**
 * @param {object} k
 * @param {number} k.id            id pembayaran, penanda kuitansi
 * @param {string} k.periode       periode tagihan (ISO)
 * @param {string} k.nama          nama penghuni
 * @param {string} k.kamar         nomor kamar
 * @param {string} k.tanggal       tanggal pembayaran (ISO)
 * @param {string} k.metode        label metode pembayaran
 * @param {string} k.penerima      siapa yang menerima uangnya
 * @param {number} k.totalTagihan  total tagihan periode itu
 * @param {number} k.jumlah        nominal pembayaran ini
 * @param {number} k.sisa          sisa tagihan saat kuitansi dicetak
 */
export function htmlKuitansi(k) {
  const dicetak = new Date().toLocaleString('id-ID', {
    dateStyle: 'long',
    timeStyle: 'short',
  })

  return `<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Kuitansi pembayaran #${aman(k.id)}</title>
<style>
  @page { margin: 15mm; }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font: 12px/1.55 "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    color: #0a0a0a;
  }
  h1 { margin: 0 0 2px; font-size: 16px; letter-spacing: -0.01em; }
  .lemah { color: #6b6b6b; }
  .mungil { font-size: 11px; }
  table { width: 100%; border-collapse: collapse; margin: 12px 0; }
  th, td { padding: 6px 0; text-align: left; vertical-align: top; border-bottom: 1px solid #e6e6e6; }
  th { width: 46%; font-weight: 400; color: #6b6b6b; }
  .angka { text-align: right; font-variant-numeric: tabular-nums; }
  .total th, .total td { border-bottom: 0; border-top: 1px solid #0a0a0a; font-weight: 600; }
  footer { margin-top: 20px; border-top: 1px solid #f0f0f0; padding-top: 10px; }
</style>
</head>
<body>
  <h1>Kuitansi pembayaran</h1>
  <p class="lemah mungil">
    Pembayaran #${aman(k.id)} · tagihan periode ${aman(k.periode)}
  </p>

  <table>
    <tr><th>Diterima dari</th><td>${aman(k.nama)}</td></tr>
    <tr><th>Kamar</th><td>${aman(k.kamar)}</td></tr>
    <tr><th>Tanggal bayar</th><td>${aman(k.tanggal)}</td></tr>
    <tr><th>Metode</th><td>${aman(k.metode)}</td></tr>
    <tr><th>Diterima oleh</th><td>${aman(k.penerima)}</td></tr>
  </table>

  <table>
    <tr><th>Total tagihan periode ini</th><td class="angka">${aman(rupiah(k.totalTagihan))}</td></tr>
    <tr><th>Jumlah dibayar</th><td class="angka">${aman(rupiah(k.jumlah))}</td></tr>
    <tr class="total">
      <th>Sisa tagihan saat kuitansi dicetak</th>
      <td class="angka">${aman(rupiah(k.sisa))}</td>
    </tr>
  </table>

  <footer class="lemah mungil">
    Dicetak ${aman(dicetak)} dari Sistem Informasi Operasional Kos-Kosan.
    Koreksi pembayaran diterbitkan sebagai baris pembalik, bukan sebagai
    perubahan kuitansi ini.
  </footer>
</body>
</html>`
}

/**
 * Isian kuitansi dari satu baris pembayaran dan tagihannya.
 *
 * Dipakai dua layar (rincian tagihan pemilik/pengelola dan portal penghuni)
 * supaya keduanya tidak menyusun kuitansi dengan isi yang berbeda untuk
 * pembayaran yang sama.
 */
export function isianKuitansi(p, t) {
  return {
    id: p.id,
    periode: t.periode ? tanggal(t.periode) : '—',
    nama: t.kontrak?.penghuni?.nama || '—',
    kamar: t.kontrak?.kamar?.nomor || '—',
    tanggal: p.tanggal_pembayaran ? tanggal(p.tanggal_pembayaran) : '—',
    metode: p.metode_label || '—',
    penerima: p.diterima_oleh_nama || '—',
    totalTagihan: t.total,
    jumlah: p.jumlah,
    sisa: t.sisa,
  }
}
