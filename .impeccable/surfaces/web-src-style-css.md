---
version: 1
slug: "web-src-style-css"
primary_target: "web/src/style.css"
related_targets: ["web/src/views/pemilik/TagihanView.vue"]
---

# Permukaan: seluruh SPA Kos-Kosan

Mode: **operate** — pengunjung menyelesaikan pekerjaan, bukan menikmati halaman.

## Cakupan

25 view: 15 halaman pemilik, portal penghuni, masuk/lupa sandi/atur sandi,
persetujuan PDP, pengaturan PDP, halaman tidak ditemukan.

Pengguna: pemilik kos 5–30 kamar (ponsel sambil berdiri, kadang laptop saat
rekap), penghuni (sekali saat tagihan datang), pengelola (akun pemilik dengan
wewenang terbatas).

Tugas yang harus selesai cepat: *"Siapa yang belum bayar, dan sudah berapa
lama?"* (pemilik), *"Berapa yang harus saya bayar, dan kapan batasnya?"*
(penghuni).

## yang tidak boleh berubah

- R-07: penghuni hanya melihat miliknya; data orang lain → 404, bukan 403.
- `role="alert"` pada pesan galat, `aria-invalid` pada medan galat.
- Target sentuh ≥ 44px pada `(pointer: coarse)`.
- Cincin fokus tidak boleh hilang tanpa pengganti.
- Tabel 4+ kolom tetap terbaca di ponsel tanpa menggulir mendatar.
- Service worker tidak menyentuh `/api/`.
- Angka uang: rupiah bulat, `tabular-nums`.

## yang membuat hasil rapi terasa salah

- Kartu dekoratif yang menggeser angka ke bawah lipatan.
- Warna yang dipakai semata-mata sebagai hiasan.
- Animasi yang menunda pembacaan angka.
- Lebih dari satu tindakan utama per layar.

## ## Direction contract

**THESIS.** Aplikasi ini buku besar, bukan katalog. Isinya angka yang harus
terbaca sama setiap pagi: siapa belum bayar, berapa, sejak kapan. Yang ditolak:
warna merek di kerangka, pil hijau di setiap baris, dan kartu yang mengambang di
atas data. Kerangka sepenuhnya tinta dan abu-abu; warna hanya untuk arti.

**OWN-WORLD.** Dunia memberi empat hal saja, sesuai batas mode Operate: rupa
huruf, palet, densitas, dan satu gerakan khas. Tata letak, model navigasi, dan
kendali tetap standar web.

- *Palet:* bidang `#f8f8f8`, panel putih `#ffffff`, garis `#e6e6e6`, tinta
  `#0a0a0a`, redup `#6b6b6b`. Aksi utama hampir hitam `#141414` — satu per
  layar. Keadaan memakai warna arti: lunas hijau `#2e7d32` di `#dcfae5`, belum
  bayar amber `#92400e` di `#fffbeb`, terlambat merah `#c4242a` di `#ffedea`.
  Satu warna data: biru `#1d5fbf` pada bilah kemajuan.
- *Rupa huruf:* Geist di-host sendiri (variabel 100–900) untuk teks, Geist Mono
  untuk nominal. Skala 12/13/14/16/24. Kepala tabel 12px abu-abu sebagai
  kalimat, bukan kapital berspasi.
- *Densitas:* baris tabel 44px, kepala 38px, padding sel `10px 12px`; panel
  radius 8px, kendali 6px; tanpa bayangan saat diam.
- *Gerakan khas (satu):* **pil status** — 22px, radius penuh, teks kalimat.
  Hanya pengecualian yang diberi tint; keadaan normal ("Terisi", "Aktif")
  tampil sebagai teks biasa.

**STORY.** Pemilik membuka Tagihan → baris-baris terbaca seperti buku besar,
nominal rata kanan di kolom yang sama → yang belum lunas ditandai pil amber,
bukan sebelas pil hijau → ia membuka satu baris dan membaca rinciannya.

**FIRST VIEWPORT.** Halaman Tagihan: sidebar 256px dengan menu berkelompok
(judul, Uang, Kos, Akses, Bantuan) → kepala halaman `h1` 24px dan satu aksi
utama hampir hitam → panel putih dengan tabel rapat; kepala tabel bergaris
rambut; nomor periode di kiri; nominal mono rata kanan; pil keadaan di kolom
status. Aksi sekunder bergaris tipis.

**FORM.** Buku besar tanpa warna. `seed:2144816512`, kind `finance`, light.
Menolak hasil percobaan pertama ("kartu indeks katalog": kertas manila hangat,
biru tinta, cap karet miring) karena pemilik menilai terlalu kuning, kuno, dan
warna mereknya bersaing dengan angka. Yang diambil dari percobaan itu hanya
disiplin pengukurannya: baris 44px, densitas tabel, dan satu gerakan khas —
bentuknya diganti dari cap menjadi pil.

**FINISH.** unreviewed and undocumented is unfinished; this build ends with the
finish review, the verdict, DESIGN.md, and every shipping raster carrying its
provenance.
