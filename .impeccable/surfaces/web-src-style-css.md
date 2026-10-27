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

**THESIS.** Setiap catatan adalah satu kartu di laci: kamar, penghuni, tagihan.
Kamu tidak menggulir tabel tanpa identitas — kamu membolak-balik kartu, dan tiap
kartu menyebut dirinya sendiri di kepala bergaris. Yang ditolak: tabel abu-abu
tanpa batas di atas bidang putih tanpa suhu.

**OWN-WORLD.** Dunia memberi empat hal saja, sesuai batas mode Operate: rupa
huruf, palet, densitas, dan satu gerakan khas. Tata letak, model navigasi, dan
kendali tetap standar web.

- *Palet:* karton manila hangat `#efe8d8` sebagai bidang, permukaan kartu
  `#faf6ec`, tinta hampir hitam `#1a1712`, garis `#c9bfa8`. Aksen satu: biru
  tinta `#1f4b99` untuk tindakan utama saja. Keadaan memakai warna arti:
  lunas hijau tua `#2f6b46`, belum bayar amber `#8a5a00`, lewat jatuh tempo
  merah `#a8322a`.
- *Rupa huruf:* satu keluarga tanpa unduhan (tumpukan sistem). Pangkat dibawa
  berat, huruf besar, dan garis — bukan ukuran. Label 11px kapital berspasi
  0.14em. Angka uang `tabular-nums`.
- *Densitas:* rapat dan beraturan seperti katalog; garis rambut memisahkan
  baris, kepala tabel bergaris ganda.
- *Gerakan khas (satu):* **cap** — keadaan dirender sebagai cap karet: kapital
  berspasi, bingkai, sedikit miring, tinta tembus pandang. Bukan lencana bulat.

**STORY.** Pemilik membuka Tagihan → baris-baris terbaca seperti entri katalog,
yang belum lunas bercap miring → ia membuka satu baris dan membaca rinciannya.

**FIRST VIEWPORT.** Halaman Tagihan: navigasi standar di kiri (tetap sidebar,
bukan tab rail). Kepala halaman dengan judul besar dan satu aksi utama berisi
tinta penuh. Di bawahnya tabel rapat; kepala tabel bergaris ganda; nomor
periode di kiri; nominal rata kanan pada garis dasar bersama; cap keadaan di
kolom paling kanan. Aksi sekunder bergaris tipis tanpa isian.

**FORM.** Kartu indeks katalog — peringkat 7 dari 7 arah yang diurutkan menurut
resonansi, sengaja arah yang paling tidak saya duga. `seed:dc339a578ac47b48`.
Raise dari `design-annual-s-plate-section`: satu rupa huruf untuk semua pangkat,
label mono 11px kapital 0.14em — menyembuhkan skala 11,5–22px yang selisihnya
0,5px. Raise dari `rw-manual-acetate-tab-board`: tinggi dan urutan menu dihitung
dari isinya, bukan ditebak — menyembuhkan `.sisi` 1204px di layar 900px, yang
mendorong tombol Keluar keluar dari layar. Tata letak tetap sidebar standar;
yang diambil hanya disiplin pengukurannya, bukan tab rail-nya.

**FINISH.** unreviewed and undocumented is unfinished; this build ends with the
finish review, the verdict, DESIGN.md, and every shipping raster carrying its
provenance.
