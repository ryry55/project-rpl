# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Tiga peran pada satu kos berukuran 5–30 kamar, semuanya di perangkat yang sama
atau berbeda.

**Pemilik** — satu orang, pemilik sekaligus pengelola. Memakai ponsel sambil
berdiri, di sela pekerjaan lain; kadang laptop saat rekap bulanan. Yang ia
butuhkan cepat: *"Siapa yang belum bayar, dan sudah berapa lama?"* Yang ia
takutkan: salah menagih dan kehilangan catatan pembayaran. Kemampuan teknis
setara pemakai WhatsApp dan aplikasi bank — tidak menulis rumus, tidak membaca
dokumentasi.

**Penghuni** — penyewa kamar, usia 18–30, akrab dengan ponsel. Memakai aplikasi
sekali saat tagihan datang, sekali saat membayar. Yang ia butuhkan cepat:
*"Berapa yang harus saya bayar, dan kapan batasnya?"* Yang ia takutkan: denda
yang muncul tanpa ia tahu hitungannya. Ia hanya boleh melihat datanya sendiri
(batas keras R-07).

**Pengelola** — bukan pengguna keempat, melainkan akun milik pemilik dengan
wewenang terbatas per pekerjaan. Ada karena pemilik perlu mendelegasikan
sebagian pekerjaan tanpa menyerahkan seluruh kos. Ia melihat sebagian menu,
dan label yang berbeda: halaman Rekap bernama "Tunai saya" untuknya.

## Product Purpose

Menggantikan buku tulis dan WhatsApp sebagai catatan operasional kos: kamar,
penghuni, kontrak, tagihan, pembayaran, dan tunggakan. Berhasil bila pemilik
berhenti menghitung prorata dan denda dengan kalkulator, dan berhenti menjawab
"sudah saya bayar belum?" dengan membuka buku.

Produk ini juga tugas besar mata kuliah Rekayasa Perangkat Lunak. Kedua peran
itu harus dipenuhi sekaligus: ia dipakai sungguhan oleh pemilik kos, dan ia
dipertanggungjawabkan di sidang.

## Positioning

Aplikasi kos di pasaran mengambil satu cara kerja — sewa bulanan, jatuh tempo
tanggal 1, denda persentase tetap — lalu memaksa setiap kos mengikutinya.
Produk ini mengambil kalimat pemilik kos sebagai tesis: **"Pain point-nya tetap
beda-beda per kos. Ini masalah utamanya."**

Mekanismenya: satuan sewa, jatuh tempo, dan bentuk denda dipilih dari **menu
tertutup**, bukan ditulis sebagai rumus. Pemilik bukan pemrogram, jadi
kustomisasi berarti memilih dan mengisi angka — bukan mengarang formula.
Empat bentuk denda × tiga jatuh tempo × tiga satuan sewa = 36 kombinasi, tanpa
satu kolom pun yang berisi formula.

## Operating Context

- Kos 5–30 kamar, satu lokasi, dikelola pemilik sendiri.
- Uang masuk lewat transfer bank, tunai, dan QRIS; ketiganya harus tercatat.
- Kebijakan kos (satuan sewa, jatuh tempo, denda) **wajib lengkap sebelum
  tagihan pertama terbit**. Tanpa itu aplikasi menebak, dan tebakan pada uang
  tidak bisa dipertanggungjawabkan.
- Tarif dan kebijakan bersifat append-only: perubahan menambah baris baru, dan
  tagihan menyimpan salinan tarif saat terbit sehingga tagihan lama tidak
  berubah ketika tarif naik.
- Perubahan tarif dan kebijakan oleh pengelola hanya **usulan**, menunggu
  keputusan pemilik, dan kedaluwarsa dalam 7 hari.
- Penghuni mengaktifkan akunnya lewat tautan surel; pemilik tidak pernah tahu
  kata sandi penghuni.
- Notifikasi dikirim in-app dan lewat surel, ke pemilik maupun penghuni.
- Zona waktu `Asia/Jakarta`. Semua nominal rupiah bulat.

## Capabilities and Constraints

Fitur: kebijakan kos, kamar dan tarif per kamar, komponen biaya per kamar,
fasilitas, aturan kos, penghuni dan kontrak, penerbitan tagihan (satuan dan
massal), prorata bulan pertama, denda berjenjang, pencatatan dan verifikasi
pembayaran, pembayaran daring lewat Midtrans, tunggakan berjenjang, rekap
pendapatan, tiga ekspor CSV, jejak audit append-only, wewenang pengelola,
persetujuan pemilik, persetujuan PDP, dan portal penghuni.

Laravel 12 API-only + Vue 3 SPA + PostgreSQL 16, satu perintah Docker Compose.
85 endpoint, 23 tabel, 792 tes PHP, 28 skrip uji browser.

Yang **sengaja tidak** dikerjakan: multi-kos untuk satu pemilik, pembukuan
akuntansi, inventaris aset, chat di dalam aplikasi, prediksi/analitik.

Keputusan desain yang **dibatalkan pemilik pada 27 Oktober 2026**:
`docs/produk/2_DESIGN_SYSTEM.md` §1.1 mengunci "angka lebih penting daripada
hiasan — tanpa ilustrasi, tanpa gradien, tanpa animasi". Pemilik melonggarkan
prinsip itu untuk redesign ini: karakter visual boleh masuk (warna status lebih
berani, kedalaman, transisi halus) selama tidak mengganggu pembacaan angka.
Prinsip lain tetap mengikat: warna hanya untuk arti, satu implementasi bukan dua
puluh salinan, dan ponsel sebagai sasaran utama.

Pemilik juga mengizinkan **nama kelas dan markup berubah**, dengan konsekuensi
28 skrip uji browser dan selektornya ikut diperbarui. Redesign menyentuh
seluruh 25 view.

Batasan keras yang tidak boleh dilanggar redesign apa pun:
- R-07: penghuni hanya melihat miliknya; data orang lain → 404, bukan 403.
- Pesan galat memakai `role="alert"`; galat bidang memakai `aria-invalid`.
- Target sentuh ≥ 44px pada `(pointer: coarse)`.
- Cincin fokus tidak boleh dihapus tanpa pengganti.
- Tabel 4+ kolom harus tetap terbaca di ponsel tanpa menggulir mendatar.
- Service worker tidak boleh menyentuh `/api/`.

## Brand Commitments

Nama yang dipakai: **Kos-Kosan** (lencana "KOS", biru `#2f6feb` saat ini).
Bahasa antarmuka: Indonesia, menyapa pemilik dengan "Anda". Nada kalimat
menjelaskan sebab, bukan memerintah — mis. "Kos ini belum siap dipakai"
disertai alasan dan langkah berikutnya.

Tidak ada font yang diunduh (keputusan sadar: satu permintaan jaringan
tambahan pada koneksi yang sudah lambat). Tidak ada logo atau aset merek lain
di luar lencana teks tersebut.

## Evidence on Hand

- `docs/produk/` — PRD, naskah wawancara pemilik kos, arsitektur, skema,
  kontrak endpoint, rencana uji, SOP operasi, catatan deploy.
- `docs/uji/` — 28 skrip uji browser yang berjalan sungguhan, dan tangkapan
  layar bukti untuk T2–T28, QR, r3, PB.
- `docs/erd/` — ERD berjenjang dan LRS, dibangkitkan dari basis data hidup.
- `docs/akademik/` — laporan akademik yang sudah diserahkan.
- Data contoh nyata di basis data uji: 2 kamar, 2 kontrak, 2 tagihan, 1
  pembayaran; akun `pemilik@kos.test`, `pengelola@kos.test`, `penghuni@kos.test`.

Yang **tidak** ada dan tidak boleh dikarang: testimoni pelanggan, jumlah
pengguna, angka pendapatan, logo, foto properti, atau studi kasus.

## Product Principles

1. **Kebenaran angka mengalahkan kenyamanan tampilan.** Angka yang salah lebih
   buruk daripada angka yang jelek. Setiap nominal harus bisa ditelusuri ke
   asalnya.
2. **Fleksibilitas adalah pilihan, bukan bahasa pemrograman.** Kustomisasi
   berarti memilih dari menu dan mengisi angka.
3. **Menjelaskan sebab, bukan melarang.** Layar yang menolak tindakan harus
   mengatakan alasannya dan apa yang harus dikerjakan lebih dulu.
4. **Kejujuran riwayat tidak bisa dinegosiasikan.** Tagihan lama tidak boleh
   berubah ketika tarif berubah; jejak audit tidak boleh disunting.
5. **Ponsel adalah sasaran utama, bukan penyesalan.** Pemilik memakai aplikasi
   ini sambil berdiri. Yang dipindah, dilipat, atau disembunyikan harus tetap
   ada di suatu tempat.

## Accessibility & Inclusion

- Target sentuh ≥ 44×44px pada `(pointer: coarse)`.
- Kontras teks utama 17,8:1; teks sekunder 6,1:1 — keduanya lolos WCAG AA.
- Galat tidak boleh disampaikan lewat warna saja: `aria-invalid` + pesan teks.
- Tabel yang berubah menjadi kartu di ponsel tetap menyimpan `<table>`,
  `<tbody>`, `<tr>`, `<td>` agar pembaca layar tidak kehilangan struktur.
- `<html lang="id">`.
- Belum ada uji aksesibilitas otomatis (axe). Ini utang teknis yang dicatat,
  bukan yang diabaikan.
