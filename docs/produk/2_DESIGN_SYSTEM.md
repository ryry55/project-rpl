# 2. Design System

> Dokumen ini menjawab: **bagaimana antarmuka ini dibangun, dan aturan mana yang
> tidak boleh dilanggar saat menambah halaman baru.**
>
> Sumber kebenaran tetap `web/src/style.css`. Dokumen ini menjelaskan *kenapa*
> isinya begitu, dan menandai bagian mana yang **tidak boleh** diubah tanpa
> memahami akibatnya.

Versi 2.0 · 28 Oktober 2026 — arah "buku besar tanpa warna"

---

## 1. Prinsip

Empat prinsip. Ketika dua prinsip bertabrakan, yang lebih atas menang.

### 1.1 Angka lebih penting daripada hiasan

Pemilik membuka aplikasi ini untuk membaca angka: sisa tagihan, jumlah
tunggakan, total masuk. Setiap piksel yang tidak membantu membaca angka adalah
piksel yang salah tempat.

> **Keputusan ini dilonggarkan pada 27 Oktober 2026, lalu diarahkan ulang pada
> 28 Oktober 2026.** Versi aslinya berbunyi "tidak ada ilustrasi, tidak ada
> gradien dekoratif, tidak ada animasi selain transisi 120ms" — dan itu
> dibatalkan oleh pemilik: layarnya benar tetapi tidak terasa dirancang.
>
> Percobaan pertama memberi aplikasi **suhu** (kertas manila, biru aksen, cap
> karet). Pemilik menolaknya: terlalu kuning, terasa kuno, dan warna merek
> bersaing dengan angka. Arah yang berlaku sekarang adalah **buku besar tanpa
> warna** — kerangka sepenuhnya tinta dan abu-abu, warna hanya di data.
>
> Yang boleh masuk: rupa huruf, palet, densitas, dan satu gerakan khas (pil
> status) — **selama tidak memperlambat atau mengaburkan pembacaan angka**.
> Batas kerasnya: tidak ada animasi yang menunda munculnya nominal, tidak ada
> hiasan di belakang angka, tidak ada warna yang dipakai semata-mata sebagai
> hiasan (§1.2 tetap utuh). Prinsip lain di dokumen ini tetap mengikat, termasuk
> §1.2, §1.3, dan §1.4. Percobaan yang ternyata mengganggu pembacaan angka
> dicatat di `web/src/style.css` sebagai komentar beserta angka pengukurannya.

Konsekuensi nyata: tidak ada pustaka komponen (Vuetify, PrimeVue) — semuanya
~950 baris CSS yang bisa dibaca dalam satu duduk.

### 1.2 Warna hanya untuk arti

Warna dipakai untuk tiga hal: **keadaan uang** (hijau lunas, amber belum
bayar, merah terlambat), **bahaya** (galat, tindakan merusak), dan **satu warna
data** (bilah kemajuan). Kerangka — sidebar, bilah atas, tombol, kepala halaman —
tidak berwarna. Tombol utama hampir hitam, bukan biru.

Ini bukan preferensi estetika. `--peredam` (amber) dipakai pada gerbang
kebijakan, dan gerbang itu **bukan galat** — ia langkah yang belum dikerjakan.
Karena itu gerbang memakai garis kiri amber + latar amber lemah, bukan merah:
merah membuat pemilik mencari kesalahan yang tidak ada.

### 1.3 Satu implementasi, bukan dua puluh salinan

Sebelumnya `.halaman`, `.kepala`, `.panel`, dan `.aksi` ditulis ulang di blok
`<style scoped>` hampir setiap view. Lima definisi `.kepala` yang identik berarti
satu perubahan tata letak harus disalin ke lima berkas — dan cepat atau lambat
satu terlewat.

Sekarang kelas itu **global**. Aturannya: kelas yang dipakai lebih dari satu
halaman hidup di `style.css`; yang benar-benar khas satu halaman tinggal di
view-nya.

### 1.4 Ponsel adalah sasaran utama, bukan penyesalan

Pemilik memakai aplikasi ini di ponsel sambil berdiri. Karena itu:

- Sasaran sentuh minimal 44×44px pada `(pointer: coarse)`.
- Tabel 4+ kolom menjadi kartu di bawah 620px.
- Kepala halaman dipadatkan di bawah 620px.

Yang **tidak** boleh dilakukan: memperbaiki tampilan ponsel dengan menghapus
informasi. Yang dipindah, dilipat, atau disembunyikan harus tetap ada di suatu
tempat.

---

## 2. Token

Didefinisikan di `:root` pada `web/src/style.css`. Semua warna dan radius
memakai token; tidak ada nilai heksadesimal lepas di view.

### 2.1 Warna

| Token | Nilai | Dipakai untuk |
|---|---|---|
| `--bg` | `#f8f8f8` | Latar halaman |
| `--permukaan` | `#ffffff` | Kartu, panel, sidebar, bilah atas |
| `--permukaan-turun` | `#f2f2f2` | Hover tombol sekunder, blok netral |
| `--aktif` | `#ececec` | Item navigasi aktif, pil netral |
| `--hover` | `#fafafa` | Baris tabel saat disorot |
| `--garis` | `#e6e6e6` | Batas struktur, garis bawah kepala tabel |
| `--garis-lemah` | `#f0f0f0` | Pemisah antar baris tabel |
| `--kendali` | `#d4d4d4` | Bingkai masukan dan tombol sekunder |
| `--teks` | `#0a0a0a` | Teks utama |
| `--teks-lemah` | `#6b6b6b` | Label, keterangan, teks sekunder |
| `--aksen` | `#141414` | Satu tombol utama per layar |
| `--aksen-lemah` | `#ececec` | Cincin fokus, latar netral |
| `--aksen-tua` | `#2a2a2a` | Tombol utama saat hover |
| `--sukses` | `#2e7d32` | Lunas |
| `--sukses-lemah` | `#dcfae5` | Latar lunas |
| `--bahaya` | `#c4242a` | Galat, tindakan merusak, terlambat |
| `--bahaya-lemah` | `#ffedea` | Latar bahaya |
| `--peredam` | `#92400e` | Belum bayar, sebagian, peringatan |
| `--peredam-lemah` | `#fffbeb` | Latar peredam |
| `--grafik` | `#1d5fbf` | Bilah kemajuan — satu-satunya warna data |

`--aksen` juga dipakai sebagai warna ikon PWA (`web/skrip/buat-ikon.mjs`), dan
`--permukaan` sebagai `theme_color` di dua tempat: `web/public/manifest.webmanifest`
dan `<meta name="theme-color">` di `web/index.html`. **Mengubah salah satunya
berarti mengubah keempatnya.** Itu harga dari tidak memakai pustaka tema; uji
`uji-pwa.mjs` membandingkan meta dengan manifest supaya tidak ada yang tertinggal.

### 2.2 Bentuk dan bayangan

| Token | Nilai | Catatan |
|---|---|---|
| `--radius` | `8px` | Kartu dan panel |
| `--radius-kecil` | `6px` | Tombol, input |
| `--baris` | `44px` | Tinggi baris tabel |
| `--kendali-tinggi` | `36px` | Tinggi tombol dan input |
| `--bayang` | `0 8px 24px rgba(10,10,10,.12)` | **Hanya** panel notifikasi yang mengambang |

Tidak ada bayangan pada kartu atau tombol saat diam. Kedalaman dibawa oleh nada
bidang (bidang → putih → turun) dan garis. Kartu yang tampak mengambang bersaing
dengan isi tabel, dan isi tabel itulah yang dibaca pemilik.

### 2.3 Tipografi

| Peran | Ukuran | Berat |
|---|---|---|
| `h1` | 24px | 600, tracking -0.015em |
| `h2` | 16px | 600 |
| `h3` | 13px | 600 |
| Badan | 14px/20px | 400 |
| Label | 12px | 500 |
| Tabel | 13px | 400 |
| Judul kolom | 12px | 400, abu-abu, tanpa huruf besar |
| Tombol | 13px | 500 |
| Uang | 13px Geist Mono | 400, tabular |

Font: **Geist** untuk teks dan **Geist Mono** untuk nominal, di-host sendiri di
`web/public/font/` dan dimuat lewat `@font-face` variabel (100–900). Di-host
sendiri, bukan dari CDN: satu permintaan pihak ketiga lebih sedikit, aplikasi
tetap utuh saat luring lewat service worker, dan tidak ada kilatan font pengganti.

Skala bergerak 12/13/14/16/24 — lompatan yang bisa dirasakan. Skala 0,5px
membuat semua teks terbaca "normal" dan hierarki hilang.

**Angka uang memakai `font-variant-numeric: tabular-nums`** yang dipasang di
`body`, bukan diingat per tabel, dan dirender dengan Geist Mono. Tanpa ini,
`Rp 1.500.000` dan `Rp 900.000` tidak sejajar dan mata harus lebih lama mencari.

---

## 3. Komponen

Semua di `web/src/komponen/ui/`. Enam komponen, dan tidak ada yang ketujuh tanpa
alasan kuat.

### 3.1 `PanelKartu`

```vue
<PanelKartu>…</PanelKartu>
```

Akar harus tetap `<section class="kartu panel">`. Kelas itu dipakai uji E2E
(`section.kartu`, `.kartu.panel`) dan gaya `.panel` menempel padanya.

### 3.2 `KepalaHalaman`

```vue
<KepalaHalaman judul="Tagihan" keterangan="…">
  <template #aksi>…</template>
</KepalaHalaman>
```

Keterangan memakai **slot** `#keterangan`, bukan prop. Alasannya: keterangan
kadang perlu memuat elemen (mis. lencana), dan prop teks tidak bisa.

### 3.3 `LencanaStatus`

```vue
<LencanaStatus status="lunas" />
```

Memetakan status ke kelas `.lencana-status.status-*`. Kelas `.lencana-status`
**dipakai uji**; mengganti namanya mematahkan `e2e-t9.mjs` dan `e2e-t3.mjs`.

Bentuknya pil 22px, tetapi **hanya pengecualian yang diberi tint**:

| Keadaan | Tampilan |
|---|---|
| `terisi`, `aktif` | Teks biasa, tanpa pil — ini keadaan normal |
| `lunas` | Pil hijau |
| `belum_bayar`, `sebagian` | Pil amber |
| `kosong`, `selesai`, `dibatalkan`, `nonaktif`, `dipesan` | Pil abu |

Sebelas pil hijau di satu tabel membuat warna berhenti berarti. Huruf pertama
dinaikkan di komponen, bukan lewat `text-transform`, supaya hanya kata pertama
berhuruf besar: "Belum bayar", bukan "Belum Bayar".

### 3.4 `PesanBaris`

```vue
<PesanBaris jenis="galat" mungil peran="alert">…</PesanBaris>
```

Prop: `jenis`, `mungil`, `peran`. **Tidak ada prop `kelas`.** Kalau butuh gaya
tambahan, bungkus dengan elemen induk.

`peran` diteruskan ke `role` ARIA. Pesan galat memakai `alert` supaya pembaca
layar langsung membacakannya.

### 3.5 `MedanForm`

```vue
<MedanForm id="nomor" label="Nomor kamar" :galat="galatBidang.nomor">
  <input id="nomor" … />
</MedanForm>
```

`id` dan `for` harus cocok. Galat memakai `aria-invalid` pada input, bukan hanya
teks merah — warna saja tidak terbaca pembaca layar dan tidak terlihat oleh
pengguna buta warna.

### 3.6 `BarisKosong`

```vue
<BarisKosong :kolom="7">Belum ada tagihan.</BarisKosong>
```

Merender `<td class="sel-kosong" colspan="N">`. Kelas `.sel-kosong` dipakai uji
**dan** dipakai CSS kartu untuk tidak memberi label pada baris kosong.

---

## 4. Tabel

Tabel adalah komponen terpenting di aplikasi ini — 20 di antaranya, dan pemilik
membacanya dengan memindai kolom.

### 4.1 Tabel menjadi kartu di ponsel

Direktif global `v-kartu-tabel` (di `web/src/direktif/kartu-tabel.js`).

```vue
<table class="tabel" v-kartu-tabel>
```

Apa yang dilakukannya:

1. Membaca teks setiap `<th>` di `<thead>`.
2. Bila kolomnya **≥ 4**, menambahkan kelas `tabel-kartu` ke `<table>`.
3. Menyalin teks `<th>` ke `data-label` setiap `<td>` pada kolom yang sama.
4. Mengulanginya lewat `MutationObserver` setiap kali baris bertambah — karena
   baris datang dari API setelah `mounted`.

CSS lalu memakai `td::before { content: attr(data-label) }` untuk menampilkan
label di kiri dan nilai di kanan.

**Kenapa direktif, bukan `data-label` manual.** Cara manual membuat label
menjadi salinan kedua dari judul kolom. Dengan 20 tabel dan ~150 sel, satu
judul yang diubah meninggalkan label yang menyesatkan — dan tidak ada yang
menangkapnya.

**Kenapa DOM tidak diubah.** `<table>`, `<tbody>`, `<tr>`, `<td>` tetap ada,
jadi seluruh selektor uji (`table.tabel tbody tr`, `td`, `.lencana-status`) dan
pembaca layar tidak terpengaruh. Mengganti tabel menjadi `<div>` akan merusak
keduanya sekaligus.

### 4.2 Aturan tabel

| Aturan | Alasan |
|---|---|
| Kolom nominal memakai kelas `.kanan` | Angka rata kanan; mata membandingkan digit dari kanan |
| Kolom aksi `<th>`-nya kosong | Tidak ada label untuk diturunkan; sel itu dibiarkan tanpa `data-label` |
| Baris kosong memakai `BarisKosong` | `colspan` penuh, tanpa label |
| `white-space: nowrap` hanya di kolom aksi | Menahan tombol satu baris; kolom teks harus boleh membungkus |
| Kepala 38px, baris 44px, padding sel `10px 12px` | Cukup padat untuk 11 kolom tagihan, cukup lapang untuk dua baris teks |
| Kolom tindakan memakai `.kolom-aksi` | Hanya kolom itu yang menempel di kanan; `:last-child` akan menutupi angka di tabel lain |

### 4.3 Yang **tidak** boleh dilakukan

- Jangan menambahkan `overflow-x: hidden` pada `.tabel-bungkus`. Itu
  menyembunyikan kolom, bukan membuatnya muat.
- Jangan menghapus `.tabel-bungkus`. Di layar 621–900px tabel masih bisa lebih
  lebar dari layar, dan bungkus itulah yang menggulir.
- Jangan menambah tabel baru tanpa `v-kartu-tabel`.

---

## 5. Tata letak

### 5.1 Halaman

```css
.halaman {
  display: grid;
  gap: 16px;
  grid-template-columns: minmax(0, 1fr);
  max-width: 1100px;
  margin-inline: auto;
}
```

`minmax(0, 1fr)`, **bukan** `1fr`. `1fr` berarti `minmax(auto, 1fr)`, dan
`auto` sebagai minimum mengambil lebar min-content: tabel atau kepala halaman
yang tidak bisa menyusut memaksa kolom lebih lebar daripada layar, lalu seluruh
halaman bergulir mendatar di ponsel.

Ini bukan teori. Terukur 753px pada viewport 390px sebelum diperbaiki.

Varian: `.halaman-lebar` (1240px, untuk tabel 11 kolom), `.halaman-penuh`
(tanpa batas).

### 5.2 Kepala halaman

`.kepala` memakai `flex-wrap: wrap` **global**, dan `.aksi-kepala` juga. Yang
kedua itu yang mudah terlewat: `flex-wrap` pada induk hanya memindahkan seluruh
kelompok tombol ke barisnya sendiri; selama kelompoknya masih `flex-shrink: 0`,
ia tetap lebih lebar daripada layar dan luberannya keluar ke dokumen. Karena itu
`.aksi-kepala` wajib `flex-wrap: wrap`, `flex-shrink: 0` dibuang, dan
`min-width: 0` dipasang.

Terukur: pada viewport 200px dokumen berhenti menyusut di 328px; penyebabnya
`.aksi-kepala` (291px, dua tombol sebaris).

### 5.3 Sidebar pemilik

`.tata-letak` = `256px minmax(0, 1fr)`. Di bawah **900px** sidebar berhenti
menjadi kolom dan menjadi **laci** dari kiri, lebar `min(320px, 86vw)`, ditutup
lewat tombol yang sama atau `Esc`. Bilah atas 52px muncul dengan nama produk dan
tombol menu.

`min-width: 0` pada kolom isi wajib: grid item memakai `min-width: auto`, jadi
menu `max-content` membuat sidebar melebar melewati layar.

Menu dikelompokkan (tanpa judul, Uang, Kos, Akses, Bantuan) dengan item 30px dan
ikon 16px, supaya 15 item muat pada tinggi 760px tanpa menggulir sidebar.

### 5.4 Kepala pemilik di ponsel

Diukur pada 360×800: **52px** bilah atas, konten pertama segera setelahnya.
Sebelum dipadatkan, kepala lama memakan 203px — hampir seperempat layar.

| Bagian | Perlakuan di < 900px |
|---|---|
| Merek + tombol menu | Bilah atas 52px, `position: sticky` |
| Menu lengkap | Laci dari kiri, dengan latar penutup |
| Nama halaman | Sudah menjadi `h1` di dalam isi; bilah atas menyebut produk |
| Email akun | Tetap di kaki laci |

Semuanya tetap ada dan tetap bisa ditekan. Yang hilang hanya tinggi.

### 5.5 Navigasi bawah di ponsel

Di bawah **900px** muncul bilah navigasi tetap di kaki layar: tiga tujuan
bulanan (Tagihan, Tunggakan, Rekap — untuk pengelola berlabel "Tunai saya")
dan tombol **Menu** yang membuka laci. Empat kolom sama lebar, tinggi
`44px` + `env(safe-area-inset-bottom)`.

Yang dipilih hanya pekerjaan yang dibuka tiap bulan; lima belas item di bilah
bawah adalah daftar menu yang lebih buruk daripada laci. Isinya diambil dari
larik `menu` yang sama, jadi label per peran tidak ditulis dua kali.

`padding-bottom` isi halaman ditambah tinggi bilah itu, kalau tidak baris
terakhir tabel tertutup dan tidak bisa ditekan. Di layar ≥ 900px bilah ini
tidak dirender sama sekali (`v-if`, bukan `display: none`), sehingga tidak ada
tautan duplikat bagi pembaca layar di desktop. Dijaga `uji-tata-letak.mjs`.

---

## 6. Aksesibilitas

| Hal | Aturan |
|---|---|
| Fokus | `:focus-visible` → `outline: 2px solid var(--teks); outline-offset: 2px`; `input:focus` → bingkai `--teks` + cincin `0 0 0 3px var(--aksen-lemah)`. Cincin fokus **tidak boleh** dihapus tanpa pengganti |
| Galat bidang | `aria-invalid="true"` pada input, bukan hanya teks merah |
| Pesan galat | `role="alert"` |
| Sasaran sentuh | ≥ 44px pada `(pointer: coarse)`. Tombol `.mungil` memakai `::after` dengan `inset: -6px` agar area sentuh meluas tanpa menggeser tata letak |
| Kontras | Teks utama `#0a0a0a` di `#ffffff` = 19,6:1. `--teks-lemah` di `--permukaan` = 5,3:1. Keduanya lolos AA |
| Status | Tidak pernah hanya warna: teks status selalu ikut ditulis |
| Tabel kartu | `thead` disembunyikan dengan `clip-path: inset(50%)`, **bukan** `display: none` — pembaca layar masih menemukannya |
| Bahasa | `<html lang="id">` |

**Uji otomatis (axe).** Sejak 30 Oktober 2026 `docs/uji/uji-aksesibilitas.mjs`
menjalankan axe-core pada 21 halaman (publik, pemilik, penghuni, dan halaman
404) dengan aturan WCAG 2.0/2.1 level A dan AA. Cacat pertama yang ditemukannya
nyata: kode peristiwa di layar Pengaturan notifikasi berkontras 3,49:1 karena
`opacity: 0.8` di atas `--teks-lemah`; transparansinya dibuang sehingga menjadi
5,3:1. Yang tetap tidak tercakup mesin: urutan fokus yang masuk akal dan teks
alternatif yang benar-benar menjelaskan gambarnya.

---

## 7. PWA

| Berkas | Isi |
|---|---|
| `web/public/manifest.webmanifest` | `standalone`, `theme_color: #ffffff`, `background_color: #f8f8f8`, ikon 192/512/maskable, 2 pintasan |
| `web/public/sw.js` | Kerangka jaringan-dulu, aset ber-hash cache-dulu, **`/api` dilewatkan** |
| `web/skrip/buat-ikon.mjs` | Generator PNG tanpa dependensi |

### 7.1 Aturan yang tidak boleh dilanggar

**Service worker tidak boleh menyentuh `/api/`.**

Bukan soal kesegaran data — soal kebocoran. Cache peramban dipakai bersama
semua akun di perangkat yang sama. Bila satu respons `/api/tagihan` tersimpan,
lalu pemilik keluar dan penghuni masuk di perangkat itu, penghuni bisa menerima
tagihan milik orang sebelumnya. Itu pelanggaran langsung R-07.

Karena itu `/api/` **dilewatkan sepenuhnya** (tanpa `respondWith`), bukan
"di-cache dengan hati-hati". Dijaga `uji-pwa.mjs`, dan pagarnya sudah dibuktikan
bisa gagal: dengan blok itu dicabut, uji melaporkan `bocor: /api/me`.

### 7.2 Mengapa kerangka memakai jaringan-dulu

`index.html` menyebut nama berkas ber-hash (`index-C6mYtjbs.css`). Bila versi
lama disajikan dari cache, ia menunjuk berkas yang sudah tidak ada setelah
deploy berikutnya — dan aplikasi gagal dimuat dengan 404 yang membingungkan.
Karena itu navigasi selalu mencoba jaringan dulu; cache hanya dipakai saat
luring.

### 7.3 Mengapa `skipWaiting()` wajib

Tanpa itu, versi baru menunggu sampai **semua** tab lama ditutup. Pada aplikasi
yang dipasang ke layar utama, tab lama itu praktis tidak pernah tertutup — jadi
perbaikan pada service worker sendiri tidak pernah sampai.

Ini bukan kemungkinan teoretis: sudah terjadi sekali, dan versi tanpa pagar
`/api` sempat menyimpan respons API ke cache sebelum ketahuan.

---

## 8. Menambah halaman baru — daftar periksa

1. Pakai `.halaman` (atau `.halaman-lebar`).
2. Pakai `KepalaHalaman` dengan slot `#aksi`.
3. Bungkus isi dengan `PanelKartu`.
4. Setiap `<table>` diberi `v-kartu-tabel`.
5. Kolom nominal diberi `.kanan`.
6. Baris kosong memakai `BarisKosong`.
7. Tombol aksi memakai `button` atau `.tombol-tautan` — jangan `<div @click>`.
8. Pesan galat memakai `PesanBaris` dengan `peran="alert"`.
9. Galat bidang memakai `MedanForm` + `aria-invalid`.
10. Uji di 360px: `node docs/uji/uji-tata-letak.mjs` dan
    `node docs/uji/uji-kartu-tabel.mjs`.
11. Jangan menambah warna lepas. Pakai token.

---

## 9. Yang sengaja tidak dipakai

| Tidak dipakai | Kenapa |
|---|---|
| Pustaka komponen (Vuetify, PrimeVue) | ~900 baris CSS bisa dibaca dalam satu duduk; pustaka menambah ribuan baris yang tidak dibaca siapa pun |
| Tailwind | Kelas panjang di template menyulitkan melihat struktur; dan tata letak di sini hanya butuh ~15 aturan |
| Font dari CDN | Dua berkas Geist di-host sendiri; CDN menambah permintaan pihak ketiga dan gagal saat luring |
| Animasi | Tidak ada yang perlu dianimasikan; angka tidak bergerak sendiri |
| Mode gelap | Belum diminta, dan menambah satu dimensi uji untuk seluruh 25 layar |
| CSS-in-JS | `<style scoped>` sudah cukup dan tidak menambah dependensi build |
