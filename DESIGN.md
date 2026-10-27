---
name: Kos-Kosan
description: Sistem informasi operasional kos-kosan — buku besar tanpa warna untuk pemilik kos 5–30 kamar.
colors:
  ground: "#f8f8f8"
  surface: "#ffffff"
  raised: "#f2f2f2"
  active: "#ececec"
  hover: "#fafafa"
  border: "#e6e6e6"
  rule: "#f0f0f0"
  control: "#d4d4d4"
  ink: "#0a0a0a"
  ink-muted: "#6b6b6b"
  primary: "#141414"
  primary-hover: "#2a2a2a"
  chart-hue: "#1d5fbf"
  positive: "#2e7d32"
  positive-wash: "#dcfae5"
  negative: "#c4242a"
  negative-wash: "#ffedea"
  warning: "#92400e"
  warning-wash: "#fffbeb"
typography:
  display:
    fontFamily: "Geist, Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "24px"
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: "-0.015em"
  headline:
    fontFamily: "Geist, Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "16px"
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: "-0.015em"
  title:
    fontFamily: "Geist, Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "13px"
    fontWeight: 600
    lineHeight: 1.2
  body:
    fontFamily: "Geist, Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: "20px"
  label:
    fontFamily: "Geist, Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "12px"
    fontWeight: 500
  money:
    fontFamily: "Geist Mono, ui-monospace, Cascadia Mono, monospace"
    fontSize: "13px"
    fontWeight: 400
    letterSpacing: "-0.01em"
rounded:
  kartu: "8px"
  kendali: "6px"
  pil: "999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "32px"
components:
  tombol-utama:
    backgroundColor: "{colors.primary}"
    textColor: "#ffffff"
    rounded: "{rounded.kendali}"
    height: "36px"
    padding: "7px 13px"
  tombol-utama-hover:
    backgroundColor: "{colors.primary-hover}"
  tombol-sekunder:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.kendali}"
    height: "36px"
  kartu:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.kartu}"
  pil-status:
    backgroundColor: "{colors.active}"
    textColor: "#3d3d3d"
    rounded: "{rounded.pil}"
    height: "22px"
    padding: "0 9px"
  masukan:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    rounded: "{rounded.kendali}"
    height: "36px"
    padding: "8px 11px"
---

# Design System: Kos-Kosan

## Overview

**Creative North Star: "Buku besar tanpa warna"**

Aplikasi ini adalah buku besar, bukan katalog. Isinya angka yang harus terbaca
sama setiap pagi: siapa belum bayar, berapa, sejak kapan. Karena itu kerangkanya
sepenuhnya tinta dan abu-abu — tidak ada warna merek di sidebar, tombol, atau
kepala halaman. Warna hanya muncul di tempat yang membawa arti: hijau untuk lunas,
amber untuk perlu ditindak, merah untuk terlambat, dan satu biru untuk data.

Yang membuatnya terasa dirancang bukan hiasan, melainkan keputusan yang konsisten:
satu aksi gelap per layar, angka uang berjarak tetap di kolom kanan, dan kepala
tabel yang dibaca sebagai kalimat — bukan cap huruf besar berspasi.

Batas kerasnya: aplikasi ini **mode Operate**. Pemilik membukanya untuk
menyelesaikan pekerjaan. Dunia visual hanya menyumbang empat hal — rupa huruf,
palet, densitas, dan satu gerakan khas (pil status). Ia tidak menyumbang tata
letak, model navigasi, atau bentuk kendali. Tabel tetap tabel; sidebar tetap
sidebar; tombol tetap tombol.

**Key Characteristics:**

- Bidang `#f8f8f8`, panel putih, garis `#e6e6e6`; tanpa warna merek di kerangka.
- Satu tombol gelap (`#141414`) per layar; aksi lain bergaris tipis.
- Huruf Geist untuk teks, Geist Mono untuk nominal uang.
- Keadaan normal adalah teks biasa; hanya pengecualian yang menjadi pil.
- Kepala tabel 12px abu-abu tanpa huruf besar; baris 44px.
- Tanpa bayangan saat diam. Satu bayangan hanya untuk panel mengambang.
- Sudut 8px untuk panel, 6px untuk kendali.

## Colors

### Primary

- **Tinta aksi** (`#141414` / `--aksen`): hanya untuk satu tindakan utama per
  layar. Hover menggelap ke `#2a2a2a` (`--aksen-tua`). Bilasnya `#ececec`
  (`--aksen-lemah`) dipakai untuk baris menu aktif dan pil netral — tidak pernah
  sebagai isian tombol kedua.

### Neutral

- **Bidang** (`#f8f8f8` / `--bg`): latar halaman.
- **Permukaan** (`#ffffff` / `--permukaan`): panel, kartu, sidebar, bilah atas.
- **Permukaan turun** (`#f2f2f2` / `--permukaan-turun`): hover tombol sekunder
  dan latar blok netral.
- **Aktif** (`#ececec` / `--aktif`): item navigasi aktif, pil netral.
- **Hover** (`#fafafa` / `--hover`): baris tabel saat disorot.
- **Garis** (`#e6e6e6` / `--garis`): batas struktur — tepi kartu, garis bawah
  kepala tabel.
- **Garis rambut** (`#f0f0f0` / `--garis-lemah`): pemisah antar baris tabel dan
  antar butir daftar.
- **Kendali** (`#d4d4d4` / `--kendali`): bingkai masukan dan tombol sekunder.
- **Tinta** (`#0a0a0a` / `--teks`): seluruh teks utama.
- **Tinta redup** (`#6b6b6b` / `--teks-lemah`): label kolom, keterangan, teks
  sekunder. Kontras 5,3:1 terhadap putih — lolos WCAG AA.

### State

- **Lunas** `#2e7d32` di atas `#dcfae5` (`--sukses`, `--sukses-lemah`).
- **Belum bayar / sebagian** `#92400e` di atas `#fffbeb` (`--peredam`,
  `--peredam-lemah`). Amber, bukan merah: belum dibayar adalah keadaan yang perlu
  ditindak, bukan kerusakan.
- **Terlambat / bahaya** `#c4242a` di atas `#ffedea` (`--bahaya`,
  `--bahaya-lemah`).
- **Data** `#1d5fbf` (`--grafik`): satu-satunya warna data, dipakai bilah
  kemajuan di ringkasan tagihan.

### Named Rules

**The Achromatic Chrome Rule.** Sidebar, bilah atas, kepala halaman, dan tombol
tidak memakai warna merek. Kalau sebuah elemen kerangka berwarna, warnanya harus
sedang menyampaikan keadaan.

**The One Primary Rule.** Satu tombol gelap penuh per layar. Ketika semua tombol
berwarna sama, mata kehilangan jalan untuk tahu mana yang ditekan lebih dulu.

**The Colour Earns Its Place Rule.** Warna hanya untuk arti: hijau = lunas, amber
= perlu ditindak, merah = terlambat, biru = data. Warna tanpa salah satu arti itu
tidak dipakai.

## Typography

**Display / Body / Label Font:** Geist, di-host sendiri dari
`/font/geist.woff2` (variabel 100–900).
**Money Font:** Geist Mono, `/font/geist-mono.woff2`.

**Character:** satu keluarga untuk semua pangkat; mono hanya untuk angka. Geist
memberi bentuk huruf yang tegas pada ukuran kecil tanpa menjadi grotesk yang
berteriak. Di-host sendiri, bukan dari CDN: satu permintaan jaringan lebih sedikit
pada koneksi kos, dan aplikasi tetap utuh saat luring lewat service worker.

### Hierarchy

- **Display** (600, 24px, 1.2, -0.015em): judul halaman. Satu per layar.
- **Headline** (600, 16px, 1.2): judul panel dan bagian.
- **Title** (600, 13px): sub-bagian di dalam panel.
- **Body** (400, 14px/20px): teks isi dan sel tabel.
- **Label** (500, 12px): label medan, keterangan, kepala tabel. Bukan huruf
  besar, bukan berspasi lebar.
- **Money** (400, 13px, Geist Mono, -0.01em): seluruh nominal rupiah.

### Named Rules

**The Measured Steps Rule.** Skala bergerak 12/13/14/16/24, bukan 0,5px. Skala
yang terlalu rapat membuat semua teks terbaca "normal", dan layar kehilangan
hierarki.

**The Tabular Money Rule.** `font-variant-numeric: tabular-nums` dipasang di
`body`, bukan diingat per tabel, dan nominal memakai Geist Mono. Angka yang tidak
sejajar kolomnya memaksa mata membaca ulang tiap baris — pekerjaan yang dilakukan
setiap hari di tabel tagihan.

## Layout

Grid dua kolom: sidebar tetap `256px` dan kolom isi `minmax(0, 1fr)`. Sidebar
adalah **tiga baris tetap** — merek + lonceng, menu, dan kaki akun + Keluar.
Hanya kolom isi yang menggulir.

Lebar isi `1100px`, terpusat (`margin-inline: auto`); halaman bertabel lebar
menimpanya dengan `.halaman-lebar` (`1240px`). Padding kolom isi
`32px 32px 56px`; jarak antar-blok `16px`.

Densitas tabel: kepala `38px`, baris `44px` (`--baris`), padding sel
`10px 12px`. Padding 14px mendatar memotong tombol di kolom terakhir tabel
tagihan 11 kolom pada 1440px — angka ini diukur, bukan ditebak.

**Breakpoint.**

- `900px` — sidebar menjadi laci yang menutupi isi; bilah atas `52px` muncul
  dengan nama produk dan tombol menu.
- `620px` — tabel 4+ kolom dilipat menjadi kartu lewat direktif `v-kartu-tabel`;
  formulir dua kolom menjadi satu; kepala halaman membungkus.
- `(pointer: coarse)` — sasaran sentuh minimal 44×44px.

**Kolom aksi menempel.** Pada tabel yang lebih lebar daripada bungkusnya, kolom
bertanda `.kolom-aksi` memakai `position: sticky; right: 0` dengan latar buram
dan garis pemisah 1px. Bukan tabelnya yang harus muat, melainkan tindakannya
yang harus selalu terlihat. Bungkusnya tetap bisa digeser mendatar, dengan bilah
gulir tipis yang terlihat.

## Elevation & Depth

Sistem ini **datar**. Kedalaman dibawa oleh nada bidang (bidang → putih → turun)
dan oleh garis, bukan bayangan. Satu bayangan ada, dan hanya untuk satu hal:
panel notifikasi yang benar-benar mengambang di atas halaman
(`0 8px 24px rgba(10,10,10,0.12)` / `--bayang`).

### Named Rules

**The Flat-at-Rest Rule.** Tidak ada `box-shadow` pada kartu, panel, atau tombol
saat diam maupun saat hover. Kartu yang tampak mengambang bersaing dengan isi
tabel — dan isi tabel yang dibayar pemilik untuk dibaca.

## Shapes

Sudut `8px` untuk panel dan kartu (`--radius`), `6px` untuk kendali — tombol,
masukan, tautan bergaya tombol (`--radius-kecil`). Pil status dan penanda jumlah
melingkar penuh (`999px`), karena bentuknya menyampaikan "label", bukan "kotak
isian".

Garis adalah bahasa bentuk kedua. Ia menandai struktur (tepi kartu, kepala
tabel) dan keadaan (garis kiri 3px pada panel yang menuntut perhatian: gerbang
kebijakan, penyiapan, pembatalan). Garis kiri itu diterima karena membawa arti,
bukan hiasan.

## Components

### Buttons

- **Shape:** `6px`, tinggi `36px` (`--kendali-tinggi`), padding `7px 13px`,
  teks 13px/500.
- **Primary:** isian `--aksen` dengan teks putih. Satu per layar.
- **Hover / Focus:** hover ke `--aksen-tua`; fokus memakai
  `outline: 2px solid var(--teks); outline-offset: 2px` yang tidak boleh
  dihapus tanpa pengganti.
- **Secondary (`.halus`):** latar putih, bingkai `--kendali`, teks `--teks`.
  Hover memakai `--permukaan-turun`.
- **Danger (`.bahaya`):** isian `--bahaya`; hanya untuk membatalkan, menghapus,
  menolak.
- **Compact (`.mungil`):** tinggi 28px, teks 12px — untuk tombol di dalam baris
  tabel. Di layar sentuh area sentuhnya diperluas ke 44px lewat `::after` tanpa
  mengubah tata letak.
- **Link (`.tautan-teks`):** teks redup tanpa bingkai; hover menjadi tinta penuh
  dan bergaris bawah. Untuk aksi sekunder yang tidak boleh tampil sebagai
  tombol kedua.

### Status pill (gerakan khas)

- **Style:** tinggi 22px, padding `0 9px`, radius `999px`, teks 12px/500.
- **Normal bukan pil.** `status-terisi` dan `status-aktif` dirender sebagai teks
  biasa. Pengecualian yang diberi tint: lunas (hijau), belum bayar / sebagian
  (amber), terlambat (merah), kosong/selesai/dibatalkan/nonaktif (abu).
  Sebelas pil hijau di satu tabel membuat warna berhenti berarti.
- **Teks:** kalimat, bukan kapital berspasi — "Belum bayar", bukan "BELUM BAYAR".
  Huruf pertama dinaikkan di `LencanaStatus.vue`, sehingga hanya kata pertama
  yang berhuruf besar.
- **Kelas `.lencana-status` dipertahankan** meski bentuknya berubah: delapan
  tempat di skrip uji memilihnya, dan nama kelas bukan bagian dari desain.

### Cards / Containers

- **Corner:** `8px`.
- **Background:** `--permukaan` di atas bidang `--bg`.
- **Border:** 1px `--garis`. Tanpa bayangan.
- **Internal padding:** 14–20px.

### Inputs / Fields

- **Style:** latar putih, bingkai 1px `--kendali`, radius `6px`, tinggi 36px,
  padding `8px 11px`, teks 14px. `select` memakai panah SVG sendiri agar
  tingginya seragam dengan `input` di sebelahnya.
- **Focus:** bingkai `--teks` + cincin `0 0 0 3px var(--aksen-lemah)`.
- **Error:** bingkai `--bahaya` lewat `aria-invalid="true"`, disertai pesan teks
  — galat tidak boleh disampaikan lewat warna saja.
- **Disabled:** teks redup, latar `--permukaan-turun`.

### Navigation

- **Style:** sidebar `256px`, tiga baris tetap, item 30px dengan ikon 16px.
  Item dikelompokkan (tanpa judul, Uang, Kos, Akses, Bantuan).
- **Default / Hover:** teks `--teks-lemah`; hover latar `--permukaan-turun` dan
  teks `--teks`.
- **Active:** latar `--aktif`, teks `--teks`, berat 500. Tanpa garis kiri —
  bilah latar sudah cukup, dan garis kiri dipesan untuk keadaan.
- **Mobile:** laci dari kiri, lebar `min(320px, 86vw)`, ditutup dengan tombol
  yang sama atau `Esc`.

### Tables

- **Header:** 12px/400 `--teks-lemah`, satu garis rambut `--garis` di bawahnya,
  tanpa latar, tanpa huruf besar. Judul kolom dibaca sebagai kalimat.
- **Rows:** 44px, dipisah `--garis-lemah`; hover `--hover`.
- **Numeric cells:** kelas `.kanan` untuk rata kanan; Geist Mono dipasang lewat
  `.tabel td.kanan:not(:has(button))`, sehingga sel yang berisi tombol tidak ikut
  menjadi mono.
- **Mobile (<620px):** tabel 4+ kolom dilipat menjadi kartu lewat direktif
  `v-kartu-tabel`, dengan label per sel diturunkan dari `<th>` — bukan ditulis
  ulang. Struktur `<table>`, `<tbody>`, `<tr>`, `<td>` tetap ada agar pembaca
  layar tidak kehilangan struktur.

## Do's and Don'ts

### Do:

- **Do** beri satu tindakan utama per layar, bergaya isian gelap penuh; semua
  aksi lain bergaris tipis.
- **Do** pakai Geist Mono dan angka tabular untuk setiap nominal rupiah.
- **Do** render keadaan normal sebagai teks dan hanya pengecualian sebagai pil.
- **Do** bawa pangkat dengan berat dan ukuran yang bisa dirasakan, bukan 0,5px.
- **Do** jaga kontras teks utama dan sekunder ≥ 4,5:1.
- **Do** pertahankan `role="alert"` pada pesan galat, `aria-invalid` pada medan
  galat, dan cincin fokus yang terlihat.
- **Do** biarkan kolom aksi menempel di tepi kanan saat tabel lebih lebar
  daripada bungkusnya.
- **Do** tuliskan alasan di layar yang menolak tindakan: apa yang kurang dan
  langkah berikutnya.

### Don't:

- **Don't** beri warna merek pada sidebar, bilah atas, atau lebih dari satu
  tombol per layar.
- **Don't** pakai huruf besar berspasi untuk kepala tabel atau label medan.
- **Don't** beri tint hijau pada setiap status yang selesai — warna yang muncul
  di mana-mana berhenti berarti.
- **Don't** pakai bayangan pada kartu atau tombol saat diam.
- **Don't** ganti tabel dengan kartu di layar lebar, atau sidebar dengan tab
  rail: dunia visual mode Operate tidak boleh memasok tata letak maupun model
  navigasi.
- **Don't** pakai gradien atau bayangan berhalo sebagai hiasan.
- **Don't** animasikan nominal: tidak ada animasi yang menunda munculnya angka.
- **Don't** sembunyikan angka di balik hiasan — hiasan yang menggeser nominal ke
  bawah lipatan adalah cacat, bukan gaya.
