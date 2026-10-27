/**
 * Membuat ikon PWA sebagai PNG, tanpa dependensi luar.
 *
 * Kenapa ditulis sendiri, bukan memakai paket: satu berkas ini menghasilkan
 * seluruh ikon yang dibutuhkan, bisa dijalankan ulang kapan pun, dan tidak
 * menambah satu pun dependensi produksi hanya untuk menggambar empat kotak.
 * CI tidak perlu ImageMagick atau sharp.
 *
 * PNG-nya dirakit langsung: tanda tangan, IHDR, IDAT (zlib), IEND. Setiap
 * keping butuh CRC32 sendiri, jadi tabelnya dibuat di bawah.
 *
 * Jalankan: node skrip/buat-ikon.mjs
 */
import { deflateSync } from 'node:zlib'
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const AKAR = join(dirname(fileURLToPath(import.meta.url)), '..')
const KELUARAN = join(AKAR, 'public', 'ikon')

// Warna diambil dari `src/style.css` (`--aksen`) supaya ikon dan antarmuka
// tidak berbeda biru. Kalau aksen berubah di sana, ubah juga di sini - dan
// itulah harga menulis generator sendiri alih-alih memakai pustaka tema.
// `--aksen` = #1f4b99.
const AKSEN = [31, 75, 153]
const PUTIH = [255, 255, 255]

// --- CRC32 -----------------------------------------------------------------

const TABEL_CRC = (() => {
  const t = new Uint32Array(256)
  for (let n = 0; n < 256; n++) {
    let c = n
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
    t[n] = c >>> 0
  }
  return t
})()

function crc32(buf) {
  let c = 0xffffffff
  for (let i = 0; i < buf.length; i++) c = TABEL_CRC[(c ^ buf[i]) & 0xff] ^ (c >>> 8)
  return (c ^ 0xffffffff) >>> 0
}

function keping(jenis, data) {
  const panjang = Buffer.alloc(4)
  panjang.writeUInt32BE(data.length, 0)
  const isi = Buffer.concat([Buffer.from(jenis, 'ascii'), data])
  const crc = Buffer.alloc(4)
  crc.writeUInt32BE(crc32(isi), 0)
  return Buffer.concat([panjang, isi, crc])
}

// --- Penggambaran ----------------------------------------------------------

/**
 * Gambar satu ikon.
 *
 * @param {number} ukuran  sisi kotak, piksel
 * @param {object} opsi
 * @param {boolean} opsi.maskable  sisakan zona aman 80% (ikon "maskable"
 *   boleh dipotong lingkaran oleh peluncur Android, jadi isinya tidak boleh
 *   menyentuh tepi)
 * @param {boolean} opsi.latarPenuh  isi seluruh kotak dengan aksen (untuk
 *   apple-touch-icon, iOS membulatkan sudutnya sendiri)
 */
function gambar(ukuran, { maskable = false, latarPenuh = false } = {}) {
  const SS = 4 // supersampling: 4x4 sampel per piksel, untuk tepi yang halus
  const piksel = Buffer.alloc(ukuran * ukuran * 4)

  // Skala isi. Maskable: 0.60 supaya seluruh glyph masuk lingkaran aman 80%.
  const skala = maskable ? 0.6 : 0.78
  const tengah = ukuran / 2
  const radiusSudut = latarPenuh ? ukuran / 2 : ukuran * 0.22

  // Glyph rumah dalam koordinat 0..1, relatif terhadap kotak isi.
  const atap = [
    [0.5, 0.16],
    [0.06, 0.52],
    [0.94, 0.52],
  ]
  const badan = { x0: 0.18, y0: 0.5, x1: 0.82, y1: 0.86 }
  const pintu = { x0: 0.42, y0: 0.64, x1: 0.58, y1: 0.86 }

  /** Apakah titik `[px, py]` berada di dalam kotak `{x0, y0, x1, y1}`. */
  const dalam = ([px, py], b) => px >= b.x0 && px <= b.x1 && py >= b.y0 && py <= b.y1

  // Uji titik dalam segitiga (barycentric).
  function dalamSegitiga(px, py, [a, b, c]) {
    const d1 = (px - b[0]) * (a[1] - b[1]) - (a[0] - b[0]) * (py - b[1])
    const d2 = (px - c[0]) * (b[1] - c[1]) - (b[0] - c[0]) * (py - c[1])
    const d3 = (px - a[0]) * (c[1] - a[1]) - (c[0] - a[0]) * (py - a[1])
    const neg = d1 < 0 || d2 < 0 || d3 < 0
    const pos = d1 > 0 || d2 > 0 || d3 > 0
    return !(neg && pos)
  }

  // Titik (x, y) piksel -> koordinat glyph 0..1.
  function keGlyph(x, y) {
    return [(x - tengah) / (ukuran * skala) + 0.5, (y - tengah) / (ukuran * skala) + 0.5]
  }

  for (let y = 0; y < ukuran; y++) {
    for (let x = 0; x < ukuran; x++) {
      let r = 0
      let g = 0
      let b = 0
      let a = 0

      for (let sy = 0; sy < SS; sy++) {
        for (let sx = 0; sx < SS; sx++) {
          const px = x + (sx + 0.5) / SS
          const py = y + (sy + 0.5) / SS

          // Latar: kotak membulat (atau lingkaran penuh untuk apple-touch).
          // Ikon maskable memakai latar penuh sampai tepi: peluncur Android
          // memotongnya dengan bentuknya sendiri (lingkaran, kotak membulat,
          // tetesan), dan sudut transparan akan terlihat sebagai luka.
          let diLatar
          if (latarPenuh) {
            diLatar = (px - tengah) ** 2 + (py - tengah) ** 2 <= (ukuran / 2) ** 2
          } else if (maskable) {
            diLatar = true
          } else {
            diLatar = dalamKotakMembulat(px, py, ukuran, radiusSudut)
          }

          if (!diLatar) continue // transparan di luar bentuk

          const [gx, gy] = keGlyph(px, py)
          let warna = AKSEN

          const diAtap = dalamSegitiga(gx, gy, atap)
          const diBadan = dalam([gx, gy], badan)
          const diPintu = dalam([gx, gy], pintu)

          // Glyph putih; pintu dilubangi kembali ke warna aksen.
          if ((diAtap || diBadan) && !diPintu) warna = PUTIH

          r += warna[0]
          g += warna[1]
          b += warna[2]
          a += 255
        }
      }

      const n = SS * SS
      const i = (y * ukuran + x) * 4
      // Rata-rata hanya atas sampel yang menutupi: tepi jadi campuran
      // warna-dan-transparan, bukan warna yang menggelap.
      const cakupan = a / 255
      piksel[i] = cakupan ? Math.round(r / cakupan) : 0
      piksel[i + 1] = cakupan ? Math.round(g / cakupan) : 0
      piksel[i + 2] = cakupan ? Math.round(b / cakupan) : 0
      piksel[i + 3] = Math.round(a / n)
    }
  }

  return encodePNG(ukuran, ukuran, piksel)
}

/** Kotak membulat: di dalam persegi, atau di dalam salah satu sudut. */
function dalamKotakMembulat(px, py, sisi, r) {
  const x0 = 0
  const y0 = 0
  const x1 = sisi
  const y1 = sisi
  if (px < x0 || px > x1 || py < y0 || py > y1) return false
  // Dekat sudut? Uji jarak ke titik pusat busur.
  const cx = px < x0 + r ? x0 + r : px > x1 - r ? x1 - r : px
  const cy = py < y0 + r ? y0 + r : py > y1 - r ? y1 - r : py
  if (cx === px && cy === py) return true // bukan di daerah sudut
  return (px - cx) ** 2 + (py - cy) ** 2 <= r * r
}

function encodePNG(lebar, tinggi, rgba) {
  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(lebar, 0)
  ihdr.writeUInt32BE(tinggi, 4)
  ihdr[8] = 8 // kedalaman bit
  ihdr[9] = 6 // RGBA
  ihdr[10] = 0 // kompresi deflate
  ihdr[11] = 0 // filter adaptif
  ihdr[12] = 0 // tanpa interlace

  // Setiap baris diawali satu byte filter (0 = tanpa filter).
  const baris = Buffer.alloc(tinggi * (1 + lebar * 4))
  for (let y = 0; y < tinggi; y++) {
    baris[y * (1 + lebar * 4)] = 0
    rgba.copy(baris, y * (1 + lebar * 4) + 1, y * lebar * 4, (y + 1) * lebar * 4)
  }

  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    keping('IHDR', ihdr),
    keping('IDAT', deflateSync(baris, { level: 9 })),
    keping('IEND', Buffer.alloc(0)),
  ])
}

// --- Tulis berkas ----------------------------------------------------------

mkdirSync(KELUARAN, { recursive: true })

const berkas = [
  ['ikon-192.png', 192, {}],
  ['ikon-512.png', 512, {}],
  // Maskable: isi lebih kecil, latar penuh, supaya tidak terpotong peluncur.
  ['ikon-maskable-512.png', 512, { maskable: true }],
  // iOS mengabaikan `manifest.icons` untuk ikon layar utama; ia memakai ini.
  ['apple-touch-icon.png', 180, { latarPenuh: true }],
]

for (const [nama, ukuran, opsi] of berkas) {
  const png = gambar(ukuran, opsi)
  writeFileSync(join(KELUARAN, nama), png)
  console.log(`${nama}  ${ukuran}x${ukuran}  ${png.length} bita`)
}
