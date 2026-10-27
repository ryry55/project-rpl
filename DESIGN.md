---
name: Kos-Kosan
description: Sistem informasi operasional kos-kosan — kartu indeks katalog untuk pemilik kos 5–30 kamar.
colors:
  ground-manila: "#efe8d8"
  surface-card: "#faf6ec"
  surface-sunken: "#f4eddd"
  rule-line: "#c9bfa8"
  rule-hairline: "#ded5c0"
  ink: "#1a1712"
  ink-muted: "#5f594c"
  ink-accent: "#1f4b99"
  ink-accent-wash: "#dde5f4"
  ink-accent-deep: "#163a75"
  settled-green: "#2f6b46"
  settled-green-wash: "#dfeade"
  overdue-red: "#a8322a"
  overdue-red-wash: "#f6e0da"
  pending-amber: "#8a5a00"
  pending-amber-wash: "#f5e8cb"
typography:
  display:
    fontFamily: "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"
    fontSize: "30px"
    fontWeight: 700
    lineHeight: 1.15
    letterSpacing: "-0.03em"
  headline:
    fontFamily: "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"
    fontSize: "19px"
    fontWeight: 650
    lineHeight: 1.15
    letterSpacing: "-0.02em"
  label:
    fontFamily: "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"
    fontSize: "11px"
    fontWeight: 700
    lineHeight: 1.15
    letterSpacing: "0.14em"
  body:
    fontFamily: "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"
    fontSize: "15px"
    fontWeight: 400
    lineHeight: 1.55
    letterSpacing: "normal"
rounded:
  kecil: "2px"
  dasar: "3px"
spacing:
  xs: "3px"
  sm: "9px"
  md: "14px"
  lg: "18px"
  xl: "26px"
components:
  tombol-utama:
    backgroundColor: "{colors.ink-accent}"
    textColor: "{colors.surface-card}"
    rounded: "{rounded.kecil}"
    padding: "9px 15px"
  tombol-utama-hover:
    backgroundColor: "{colors.ink-accent-deep}"
    textColor: "{colors.surface-card}"
  tombol-sekunder:
    backgroundColor: "transparent"
    textColor: "{colors.ink}"
    rounded: "{rounded.kecil}"
    padding: "9px 15px"
  kartu:
    backgroundColor: "{colors.surface-card}"
    textColor: "{colors.ink}"
    rounded: "{rounded.dasar}"
  cap-status:
    backgroundColor: "transparent"
    textColor: "{colors.settled-green}"
    rounded: "{rounded.kecil}"
    padding: "2px 8px"
  masukan:
    backgroundColor: "{colors.surface-card}"
    textColor: "{colors.ink}"
    rounded: "{rounded.kecil}"
    padding: "9px 11px"
---

# Design System: Kos-Kosan

## Overview

**Creative North Star: "Kartu indeks di laci katalog"**

Setiap catatan adalah satu kartu di laci: kamar, penghuni, tagihan. Kamu tidak
menggulir tabel tanpa identitas — kamu membaca entri yang menyebut dirinya
sendiri di kepala, dengan nomor di kiri dan nominal rata kanan pada garis dasar
yang sama. Sistem ini dibangun dari karton manila hangat, tinta ketikan hampir
hitam, dan garis rambut — benda kerja yang dipakai orang yang menghitung uang
setiap hari, bukan papan iklan.

Keputusan ini lahir dari penolakan yang jelas: **tabel abu-abu tanpa batas di
atas bidang putih tanpa suhu**. Versi sebelumnya benar secara fungsi tetapi
terasa dirakit, bukan dirancang — skala tipografinya bergerak 11,5–22px dengan
selisih 0,5px, sehingga judul tidak pernah terasa sebagai judul. Sekarang skala
bergerak 12/14/16/19/24/30, pangkat dibawa oleh berat, huruf besar, dan garis.

Batas keras yang membentuk seluruh dokumen ini: aplikasi ini **mode Operate**.
Pemilik membukanya untuk menyelesaikan pekerjaan, bukan menikmati halaman.
Karena itu dunia visual hanya menyumbang empat hal — rupa huruf, palet,
densitas, dan satu gerakan khas. Ia **tidak** menyumbang tata letak, model
navigasi, atau bentuk kendali. Tabel tetap tabel; sidebar tetap sidebar; tombol
tetap tombol. Lembar bergaris yang menggantikan tabel adalah kostum, bukan
desain.

**Key Characteristics:**

- Bidang karton manila hangat, bukan putih; tinta hampir hitam, bukan hitam murni.
- Satu warna aksen untuk tindakan utama saja; aksi sekunder bergaris tanpa isian.
- Kepala tabel bergaris ganda; baris dipisah garis rambut, bukan latar belang.
- Keadaan dirender sebagai **cap karet**, bukan lencana pil.
- Angka uang selalu `tabular-nums`, rata kanan, pada garis dasar bersama.
- Sudut hampir siku (2–3px); bayangan satu arah, bukan halo.

## Colors

Palet dua suhu: kertas hangat untuk bidang, tinta dingin untuk teks, dan satu
biru tinta yang langka untuk menandai satu-satunya tindakan utama di layar.

### Primary

- **Tinta aksen** (`#1f4b99` / `--aksen`): hanya untuk tindakan utama, tautan
  dalam teks, dan penanda halaman aktif di sidebar. Satu per layar. Versi
  gelapnya (`#163a75` / `--aksen-tua`) dipakai saat hover saja. Latar bilasnya
  (`#dde5f4` / `--aksen-lemah`) dipakai untuk baris menu aktif dan cap
  "sebagian" — bukan untuk tombol.

### Neutral

- **Karton manila** (`#efe8d8` / `--bg`): bidang halaman. Ini yang memberi
  aplikasi suhunya; putih bersih membuat tabel terbaca sebagai lembar kerja
  kosong.
- **Permukaan kartu** (`#faf6ec` / `--permukaan`): panel, kartu, sidebar,
  kepala tabel yang menempel. Satu tingkat lebih terang dari bidang.
- **Permukaan turun** (`#f4eddd` / `--permukaan-turun`): baris tabel saat
  hover, kolom aksi yang menempel saat baris disorot, latar blok netral.
- **Garis** (`#c9bfa8` / `--garis`): batas struktur — tepi kartu, garis bawah
  kepala sidebar, bingkai cap.
- **Garis rambut** (`#ded5c0` / `--garis-lemah`): pemisah di dalam struktur —
  antar baris tabel, antar butir daftar.
- **Tinta** (`#1a1712` / `--teks`): seluruh teks utama dan garis ganda kepala
  tabel.
- **Tinta redup** (`#5f594c` / `--teks-lemah`): label kolom, keterangan,
  teks sekunder. Kontras 6,1:1 terhadap permukaan kartu — lolos WCAG AA.

### Named Rules

**The One Accent Rule.** Biru aksen dipakai pada **satu** tindakan utama per
layar, dan tidak pernah sebagai hiasan atau latar tombol sekunder. Ketika semua
tombol berwarna sama, mata kehilangan jalan untuk tahu mana yang ditekan lebih
dulu — itu cacat keterbacaan, bukan soal selera.

**The Colour Earns Its Place Rule.** Warna hanya dipakai untuk arti: hijau =
lunas/selesai, amber = perlu ditindak, merah = terlambat/bahaya, biru =
tindakan. Warna yang tidak membawa salah satu arti itu tidak dipakai.

## Typography

**Display Font:** tumpukan sistem (`ui-sans-serif`, `system-ui`, Segoe UI,
Roboto) — tanpa unduhan
**Body Font:** sama
**Label Font:** sama, dengan huruf besar dan spasi lebar

**Character:** satu keluarga untuk semua pangkat. Tidak ada font yang diunduh:
itu keputusan sadar, satu permintaan jaringan tambahan pada koneksi yang sudah
lambat di kos. Pangkat dibawa oleh **berat, huruf besar, dan garis** — bukan
oleh ukuran yang berbeda 0,5px.

### Hierarchy

- **Display** (700, 30px, 1.15, -0.03em): judul halaman. Satu per layar.
- **Headline** (650, 19px, 1.15, -0.02em): judul panel dan bagian.
- **Title** (700, 14px, huruf besar, 0.08em): sub-bagian di dalam panel.
- **Body** (400, 15px, 1.55): seluruh teks isi dan sel tabel. Angka uang
  memakai `tabular-nums` dari `body`, sehingga kolom nominal sejajar tanpa
  aturan per-tabel.
- **Label** (700, 11px, huruf besar, 0.14em): kepala tabel, label medan,
  keterangan kecil.

### Named Rules

**The Measured Steps Rule.** Skala tipografi harus bergerak dalam lompatan yang
bisa dirasakan (12/14/16/19/24/30), bukan 0,5px. Skala yang terlalu rapat
membuat semua teks terbaca "normal", dan seluruh layar kehilangan hierarki.

**The Tabular Money Rule.** Setiap nominal rupiah memakai angka tabular dan
rata kanan. Angka yang tidak sejajar kolomnya memaksa mata membaca ulang tiap
baris — dan pada tabel tagihan itu pekerjaan yang dilakukan setiap hari.

## Layout

Grid dua kolom: sidebar tetap 244px dan kolom isi `minmax(0, 1fr)`. Sidebar
adalah **tiga baris tetap** — kepala (merek + lonceng), menu yang menggulir
sendiri, dan kaki (akun + Keluar) yang menempel di dasar. Hanya kolom isi yang
menggulir halaman; sidebar tidak ikut berpindah.

Batas lebar isi: `1240px` untuk halaman bertabel lebar, mengikuti ruang yang
tersedia (sidebar 244px + padding 56px), bukan angka tetap. Padding kolom isi
`26px 28px 40px`; jarak antar-blok `18px`.

Densitas tabel: padding sel `9px` mendatar dan `10px` tegak. Angka ini diukur,
bukan ditebak — pada tabel tagihan 11 kolom, padding 14px menghabiskan 308px
hanya untuk ruang kosong dan memotong tombol "Rincian" di kolom terakhir.

**Breakpoint.** `860px` — sidebar berhenti menjadi kolom samping dan menjadi
kepala yang ikut menggulir; menu berubah menjadi deret mendatar yang bisa
digeser. `620px` — tabel 4+ kolom dilipat menjadi kartu, kepala halaman
membungkus, dan formulir dua kolom menjadi satu. `(pointer: coarse)` — sasaran
sentuh minimal 44×44px.

**Kolom aksi menempel.** Pada tabel yang lebih lebar daripada bungkusnya,
kolom tindakan memakai `position: sticky; right: 0` dengan latar buram. Bukan
tabelnya yang harus muat, melainkan tindakannya yang harus selalu terlihat.

## Elevation & Depth

Sistem ini **hampir datar**: kedalaman dibawa oleh perbedaan nada bidang
(karton → permukaan kartu → permukaan turun) dan oleh garis, bukan oleh
bayangan. Satu bayangan saja dipakai, dan arahnya satu: ke bawah, seperti
kartu yang menumpuk di laci.

### Shadow Vocabulary

- **Kartu terangkat** (`box-shadow: 0 1px 0 rgba(26,23,18,0.06), 0 2px 6px rgba(26,23,18,0.08)`):
  hanya pada `.kartu` — panel, kotak formulir, dan kartu tabel di ponsel.

### Named Rules

**The One-Direction Rule.** Bayangan selalu punya offset ke bawah dan blur
lembut. Halo berwarna tanpa offset adalah hiasan, bukan kedalaman, dan tidak
dipakai di sini.

## Shapes

Sudut hampir siku: `3px` untuk kartu dan panel, `2px` untuk kendali (tombol,
masukan, cap). Karton tidak membulat. Satu-satunya bentuk bulat penuh yang
tersisa adalah avatar akun dan penanda jumlah pada lonceng — keduanya penanda
identitas, bukan wadah konten.

Garis adalah bahasa bentuk utama sistem ini: garis ganda (atas dan bawah) pada
kepala tabel, garis rambut antar baris, garis tepi pada cap, dan garis kiri
sebagai penanda. Ada **dua** garis kiri, dan angkanya berbeda karena
pekerjaannya berbeda:

- **2px** menandai **pilihan**: halaman aktif di sidebar, butir yang sedang
  dibuka. Ia bergerak bersama pengguna.
- **3px** menandai **keadaan**: panel yang menuntut perhatian (gerbang
  kebijakan, piutang, kelompok wewenang sensitif, kotak pembatalan). Ia
  diam dan menunggu dibaca.

Angka yang berbeda itu disengaja, bukan kelalaian. Garis 2px yang juga dipakai
untuk keadaan akan terbaca sebagai pilihan — pemilik akan mengira panelnya bisa
diklik. **`impeccable detect` menandai garis kiri pada kartu sebagai `side-tab`**;
di sini ia diterima karena membawa arti keadaan, bukan hiasan, dan karena itu
didaftarkan sebagai pengecualian di `.impeccable/config.json` beserta alasannya.

Bingkai lebih dipercaya daripada latar berwarna, karena bingkai tidak menambah
bidang warna yang harus dibaca.

## Components

### Buttons

- **Shape:** hampir siku (2px), bingkai 1px, padding `9px 15px`, teks 14px/600.
- **Primary:** isian penuh `--aksen` dengan teks `--permukaan`. Satu per layar.
- **Hover / Focus:** hover menggelap ke `--aksen-tua`; fokus memakai cincin
  `outline: 2px solid var(--aksen); outline-offset: 2px` yang tidak boleh
  dihapus tanpa pengganti.
- **Secondary (`.halus`):** latar tembus, bingkai `--garis`, teks `--teks`.
  Hover memakai `--permukaan-turun`, bukan warna aksen — aksi sekunder tidak
  boleh bersaing dengan aksi utama.
- **Danger (`.bahaya`):** isian penuh `--bahaya`; hanya untuk membatalkan,
  menghapus, menolak.
- **Compact (`.mungil`):** padding `5px 10px`, teks 13px — untuk tombol di
  dalam baris tabel, bukan untuk tindakan halaman.

### Cap (gerakan khas)

- **Style:** huruf kapital 10,5px, berat 700, spasi `0.12em`, bingkai 1px
  `currentColor`, radius 2px, `transform: rotate(-1.5deg)`, opasitas 0.9 —
  seperti cap karet yang ditekan tangan.
- **Warna:** mengikuti arti keadaan, bukan hiasan. `--sukses` untuk lunas dan
  terisi, `--peredam` untuk belum bayar, `--aksen` untuk sebagian,
  `--teks-lemah` untuk keadaan netral.
- **Kelas `.lencana-status` dipertahankan** meski bentuknya berubah: delapan
  tempat di skrip uji memilihnya, dan nama kelas bukan bagian dari desain.

### Cards / Containers

- **Corner Style:** 3px.
- **Background:** `--permukaan` di atas bidang `--bg`.
- **Shadow Strategy:** satu bayangan terangkat (lihat Elevation).
- **Border:** 1px `--garis`.
- **Internal Padding:** 16–20px.

### Inputs / Fields

- **Style:** latar `--permukaan`, bingkai 1px `--garis`, radius 2px, padding
  `9px 11px`, teks 14px. `select` memakai panah SVG sendiri agar tingginya
  seragam dengan `input` di sebelahnya.
- **Focus:** bingkai `--aksen` + cincin `0 0 0 3px var(--aksen-lemah)`.
- **Error:** bingkai `--bahaya` lewat `aria-invalid="true"`, disertai pesan
  teks — galat tidak boleh disampaikan lewat warna saja.
- **Disabled:** teks `--teks-lemah`, latar `--permukaan-turun`.

### Navigation

- **Style:** sidebar 244px, tiga baris tetap. Item menu menyebut nama dan
  keterangan satu baris; keterangan disembunyikan di bawah 860px.
- **Default / Hover:** teks `--teks-lemah`; hover memakai `--permukaan-turun`
  dan teks `--teks`.
- **Active:** teks `--aksen`, latar `--aksen-lemah`, dan **garis kiri 2px**
  `--aksen` — bahasa tab pemisah katalog, tanpa mengubah menu menjadi tab rail.
- **Mobile:** deret mendatar yang bisa digeser, bilah gulirnya disembunyikan.

### Tables

- **Header:** huruf kapital 11px, spasi `0.14em`, garis **ganda** (`border-top`
  dan `border-bottom` 1px `--teks`), latar transparan. Garis ganda adalah
  bahasa buku besar dan memisahkan tajuk dari isi tanpa memerlukan latar.
- **Rows:** dipisah garis rambut 1px; hover memakai `--permukaan-turun`.
- **Numeric cells:** kelas `.kanan` untuk rata kanan, angka tabular.
- **Mobile (<620px):** tabel 4+ kolom dilipat menjadi kartu lewat direktif
  `v-kartu-tabel`, dengan label per sel diturunkan dari `<th>` — bukan ditulis
  ulang. Struktur `<table>`, `<tbody>`, `<tr>`, `<td>` tetap ada agar pembaca
  layar tidak kehilangan struktur.

## Do's and Don'ts

### Do:

- **Do** beri satu tindakan utama per layar, bergaya isian tinta penuh; semua
  aksi lain bergaris tipis tanpa isian.
- **Do** pakai `tabular-nums` dan rata kanan untuk setiap nominal rupiah.
- **Do** render keadaan sebagai cap: kapital, berspasi, berbingkai, sedikit
  miring.
- **Do** bawa pangkat dengan berat, huruf besar, dan garis — bukan dengan
  ukuran yang berbeda 0,5px.
- **Do** jaga kontras teks utama ≥ 4,5:1 dan teks sekunder ≥ 4,5:1; teks
  sekunder diwarnai dari hue yang sama, bukan abu-abu netral.
- **Do** pertahankan `role="alert"` pada pesan galat, `aria-invalid` pada medan
  galat, dan cincin fokus yang terlihat.
- **Do** biarkan kolom aksi menempel di tepi kanan saat tabel lebih lebar
  daripada bungkusnya.
- **Do** tuliskan alasan di layar yang menolak tindakan: apa yang kurang dan
  langkah berikutnya.

### Don't:

- **Don't** beri isian biru penuh pada lebih dari satu tombol di satu layar —
  itu menghapus hierarki tindakan.
- **Don't** pakai lencana pil, latar biru lembut, atau sudut 8–10px: itu bahasa
  sistem sebelumnya, bukan sistem ini.
- **Don't** ganti tabel dengan kartu atau lembar bergaris, dan **don't** ganti
  sidebar dengan tab rail — dunia visual mode Operate tidak boleh memasok tata
  letak maupun model navigasi.
- **Don't** pakai bayangan berhalo tanpa offset, atau gradien sebagai hiasan.
- **Don't** animasikan nominal: tidak ada animasi yang menunda munculnya angka.
- **Don't** sembunyikan angka di balik hiasan — hiasan yang menggeser nominal
  ke bawah lipatan adalah cacat, bukan gaya.
- **Don't** pakai warna yang tidak membawa arti.
