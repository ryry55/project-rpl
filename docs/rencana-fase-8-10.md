# Rencana perbaikan Fase 8-10 — audit kode 16-17 November 2026

Audit 13 November memeriksa kebenaran kode, audit 16 November memeriksa
permukaan publik. Yang ini memeriksa **jalur uang, balapan tulis, dan
pertumbuhan kueri** — dan setiap temuan berat dibuktikan di runtime lebih
dulu, bukan hanya terbaca di kode: 6 reproduksi lewat tes sementara di
`kos_test` (berkasnya dihapus sesudah verifikasi) plus 1 penghitungan kueri
terhadap data demo. Detail keputusan uang ada di `docs/keputusan-terbuka.md`
(K-19, K-20).

## Temuan terverifikasi

| # | Temuan | Bukti runtime |
|---|---|---|
| 1 | Baris `menunggu` tanpa `redirect_url` (sisa kegagalan parsial `mulai()`) membuat percobaan berikutnya **500**, bukan tautan baru — penghuni terkunci sampai webhook `expire` | POST bayar-daring → 500 (QueryException indeks unik `satu_menunggu_per_tagihan`) |
| 2 | Membatalkan tagihan **tidak mematikan tautan Midtrans**; uang yang masuk setelah batal tidak tercatat di mana pun | setelah batal: transaksi tetap `menunggu`; webhook sah → transaksi `dibayar`, **0 baris pembayaran** |
| 3 | `PUT /penghuni/{id}` tulis dua tabel **tanpa transaksi pembungkus**; kegagalan di tulisan kedua meninggalkan tulisan pertama tersimpan | gagal disuntikkan di `users` → 500, `penghuni` terubah, `users` tidak |
| 4 | Jendela antara pemeriksaan dan penulisan status kamar di `akhiri()` bisa diisi kontrak baru — kamar tampil `kosong` padahal berpenghuni | interleave disimulasikan → kamar `kosong` + 1 kontrak `aktif` |
| 5 | N+1 di `DaftarTunggakan::susun()` dan `RekapPendapatan::susun()`: satu `SELECT SUM` per tagihan terbuka | 2 tagihan terbuka → 2 kueri SUM di kedua kelas |
| 6 | `GET /pembayaran` tanpa batas sama sekali, 6 relasi per baris | dibaca dari kode (`PembayaranController.php:53-72`) |
| 7 | Denda tumbuh setelah tautan dibuat → penghuni "lunas menurut Midtrans" tetap bersisa receh | bayar 1.650.000 (beku), total 1.695.000 tiga hari kemudian → `sebagian` |
| 8 | `now()` langsung di 5 jalur tulis (`PenerbitTagihan`, `KontrakController` ×2, `LaporanKerusakanController`, `PemutusUsulan`) — melewati `WaktuSistem`, tanggal simulasi tidak konsisten | dibaca dari kode; konvensi `WaktuSistem` dilanggar |
| 9 | Pola `galatMuat` diduplikasi 16 view (94 kemunculan) | grep `web/src/views` |
| 10 | Kode mati: `MidtransGateway::clientKey()`, injeksi `$waktu` tak terpakai di `PencatatPembayaran`, relasi `Pembayaran::asal()`, `TransaksiGateway::STATUS_DIBATALKAN` | grep seluruh pohon termasuk tes dan rute |
| 11 | Port `3000`/`8081`/`8025` terbuka ke LAN (`0.0.0.0`) | `docker compose ps` |
| 12 | `X-Powered-By: PHP/8.3.36` masih terbaca | tangkapan header 16 November |

Yang **tidak** ditemukan meski dicari khusus: bug pembulatan denda (aritmetika
bulat, `intdiv` eksak), balapan di `PencatatPembayaran`/`PembalikPembayaran`/
`PemaafDenda` (semuanya benar: transaksi + `lockForUpdate` + baca ulang), N+1
di `TagihanController`/`PembayaranController::index`.

## Fase 8 — Integritas jalur uang (temuan 1, 2, 3, 4, 7)

Urutan kerja dari yang paling mengunci ke yang paling bergantung keputusan.

1. **#1 — bersihkan sisa kegagalan `mulai()`.** Bungkus `buatTransaksi()`
   dalam `try/catch`; bila lempar, hapus baris `menunggu` yang barusan
   dibuat lalu lempar `GatewayMenolak` seperti jalur lain. Tes: baris
   `menunggu` tanpa tautan + percobaan kedua → tautan baru (201), bukan 500.
2. **#3 — bungkus `PenghuniController::update()` dengan `DB::transaction`**
   (bentuknya sudah ada di `store()`, tinggal disalin). Tes: kegagalan di
   tulisan kedua → `penghuni` dan `users` keduanya tidak berubah.
3. **#4 — `lockForUpdate` pada baris kamar** di `KontrakController::akhiri()`
   dan `LaporanKerusakanController::selesai()`, dalam transaksi (pola
   `PencatatPembayaran`). Tes: interleave akhiri + kontrak baru → kamar
   `terisi`, bukan `kosong`.
4. **#2 sesuai K-19 — kunci dua pintu + catat.** `PembatalTagihan` menandai
   transaksi `menunggu` → `dibatalkan` (konstanta `STATUS_DIBATALKAN` yang
   selama ini mati akhirnya dipakai). Webhook settlement sah untuk tagihan
   batal: catat pembayaran berlabel "perlu tindakan" — rekap jujur, refund
   di luar aplikasi. Tes: (a) setelah batal, transaksi `dibatalkan`;
   (b) uang terlanjur masuk → 1 baris pembayaran, terlihat di rekap.
5. **#7 sesuai K-20 — bekukan denda di nominal tautan.** Settlement gateway
   yang membayar jumlah beku penuh → tagihan **lunas**, `total_final` =
   pokok + denda saat tautan dibuat. Tes: tautan dibuat, 3 hari berlalu,
   dibayar penuh → `lunas` (hari ini `sebagian`).
6. Gate **penuh** (bukan `-Cepat`) + `cache:clear` + reseed demo
   (`migrate:fresh --seed` lalu `isi-demo.mjs` dengan URL tunnel di `.env`).

Perhatian: langkah 4 dan 5 mengubah respons yang dilihat penghuni — pesan
commit harus menyebut K-19/K-20. Perbaikan #2 jangan mengembalikan larangan
`PembatalTagihan` "tagihan yang sudah menerima uang tidak boleh dibatalkan"
(uang yang masuk SETELAH batal dicatat berlabel, bukan menolak pembatalan).

## Fase 9 — Performa & konsistensi (temuan 5, 6, 8) — **selesai 22 Nov 2026**

1. **#5 — `withSum('pembayaran as total_dibayar', 'jumlah')`** di
   `DaftarTunggakan` dan `RekapPendapatan::piutang()` (bentuknya sudah
   dipakai `TagihanController`). Bukti: hitung kueri sebelum/sesudah —
   2 tagihan terbuka harus turun dari 2 kueri SUM ke 0.
2. **#6 — `per_page` opsional di `GET /pembayaran`**, pola K-05 yang sudah
   dipakai `GET /tagihan`; SPA menyusul bila perlu.
3. **#8 — `now()` → `waktu->sekarang()`** di lima titik (lihat tabel).
   Tes: `X-Simulasi-Tanggal` pada terbit/batal/akhiri/selesai/usulan menulis
   tanggal simulasi.
4. Uji beban ulang (`node docs/beban/uji-beban.mjs`) — bandingkan p95 dengan
   610 ms (16 November).
5. Gate penuh + reseed demo.

## Fase 10 — Pemolesan & pra-sidang (temuan 9, 10, 11, 12) — **selesai 22 Nov 2026**

1. **#9 — composable `useMuatDaftar()`** (memuat + galatMuat + penjaga
   "kosong vs gagal muat"). Migrasi satu view dulu sebagai bukti,
   `uji-galat-muat.mjs` tetap hijau, sisanya menyusul. Perbaikan perilaku
   masa depan cukup disunting satu tempat.
2. **#10 — hapus kode mati** (empat butir di tabel). `STATUS_DIBATALKAN`
   hilang sendiri bila Fase 8 dikerjakan duluan.
3. **#11 — port ke loopback**: `127.0.0.1:3000:80` dst. di
   `docker-compose.yml`. Tunnel tetap jalan (cloudflared menghubungi
   localhost), LAN tidak lagi bisa membuka Mailpit.
4. **#12 — `fastcgi_hide_header X-Powered-By;`** di kedua nginx demo +
   template produksi.
5. Bangun ulang image produksi + `periksa-profil-produksi.sh` (pembaruan
   Composer 16 November belum masuk ke image produksi).
6. Gate penuh terakhir + reseed demo + verifikasi tunnel hidup
   (`nslookup <host> 8.8.8.8` — lihat jebakan zombi di `fase-akhir.md`).

## Aturan pengerjaan

- Satu fase = **satu sesi baru**; baca `docs/serah-terima.md` dulu, timpa di
  akhir fase, maksimal 120 baris.
- Tiap perbaikan diiringi tes yang **dibuktikan bisa gagal** (suntik cacat,
  lihat merah, kembalikan) — konvensi fase-fase sebelumnya.
- Gate penuh menghapus data demo (`migrate:fresh`): reseed dengan seeder +
  `isi-demo.mjs` lewat URL tunnel di `.env`, lalu `cache:clear`.
- Urutan bisa dipangkas: bila waktu hanya cukup satu fase, **Fase 8 saja** —
  temuan 9-12 memburuk dengan lambat, temuan 1-4 bisa mengunci penghuni dan
  menghilangkan jejak uang.
- Yang tidak dikerjakan di fase mana pun (keputusan demo, bukan cacat):
  sandi `password` tiga akun contoh, Mailpit tanpa sandi, `cors: *`.
