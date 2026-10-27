# 2. Design System

> Dokumen ini menjawab: **bagaimana antarmuka ini dibangun, dan aturan mana yang
> tidak boleh dilanggar saat menambah halaman baru.**
>
> Sumber kebenaran tetap `web/src/style.css`. Dokumen ini menjelaskan *kenapa*
> isinya begitu, dan menandai bagian mana yang **tidak boleh** diubah tanpa
> memahami akibatnya.

Versi 1.0 · 26 Oktober 2026

---

## 1. Prinsip

Empat prinsip. Ketika dua prinsip bertabrakan, yang lebih atas menang.

### 1.1 Angka lebih penting daripada hiasan

Pemilik membuka aplikasi ini untuk membaca angka: sisa tagihan, jumlah
tunggakan, total masuk. Setiap piksel yang tidak membantu membaca angka adalah
piksel yang salah tempat.

> **Keputusan ini dilonggarkan pada 27 Oktober 2026.** Versi aslinya berbunyi
> "tidak ada ilustrasi, tidak ada gradien dekoratif, tidak ada animasi selain
> transisi 120ms" — dan itu **dibatalkan** oleh pemilik untuk redesign UI/UX.
> Alasannya: prinsip itu, ditegakkan terlalu ketat, menghasilkan layar yang
> benar tetapi tidak terasa dirancang, dan pemilik menilai aplikasinya "kurang
> menarik".
>
> Yang berlaku sekarang: **karakter visual boleh masuk** — palet dengan suhu,
> kedalaman satu arah, transisi halus, dan satu gerakan khas — **selama tidak
> memperlambat atau mengaburkan pembacaan angka**. Batas kerasnya: tidak ada
> animasi yang menunda munculnya nominal, tidak ada hiasan di belakang angka,
> tidak ada warna yang dipakai semata-mata sebagai hiasan (§1.2 tetap utuh).
>
> Prinsip lain di dokumen ini tetap mengikat, termasuk §1.2, §1.3, dan §1.4.
> Percobaan redesign yang ternyata mengganggu pembacaan angka dicatat di
> `web/src/style.css` sebagai komentar beserta angka pengukurannya.

Konsekuensi nyata: tidak ada pustaka komponen (Vuetify, PrimeVue) — semuanya
~560 baris CSS yang bisa dibaca dalam satu duduk.

### 1.2 Warna hanya untuk arti

Warna aksen dipakai untuk satu hal: **tindakan utama**. Merah untuk bahaya dan
sesuatu yang menuntut tindakan. Hijau untuk berhasil dan lunas. Abu-abu untuk
netral.

Ini bukan preferensi estetika. `--bahaya` dipakai pada gerbang kebijakan, dan
gerbang itu **bukan galat** — ia langkah yang belum dikerjakan. Karena itu
gerbang memakai garis kiri merah + latar merah lemah, bukan latar merah penuh:
warna penuh membuat pemilik mengira ada yang rusak.

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
| `--bg` | `#f6f7f9` | Latar halaman |
| `--permukaan` | `#ffffff` | Kartu, panel, sidebar |
| `--garis` | `#e2e5ea` | Garis pemisah yang terlihat |
| `--garis-lemah` | `#eef0f3` | Garis di dalam tabel |
| `--teks` | `#16181d` | Teks utama |
| `--teks-lemah` | `#5c636e` | Label, keterangan, teks sekunder |
| `--aksen` | `#2f6feb` | Tombol utama, tautan aktif, lencana |
| `--aksen-lemah` | `#eaf0fd` | Latar lencana aksen, cincin fokus |
| `--sukses` | `#17795e` | Lunas, aktif, terisi |
| `--sukses-lemah` | `#e7f5f0` | Latar lencana sukses |
| `--bahaya` | `#c0392b` | Galat, gerbang, tunggakan lewat 60 hari |
| `--bahaya-lemah` | `#fdeceb` | Latar lencana bahaya |

`--aksen` juga dipakai sebagai `theme_color` di `manifest.webmanifest` dan
sebagai warna ikon PWA (`web/skrip/buat-ikon.mjs`). **Mengubahnya berarti
mengubah tiga tempat.** Itu harga dari tidak memakai pustaka tema.

### 2.2 Bentuk dan bayangan

| Token | Nilai | Catatan |
|---|---|---|
| `--radius` | `10px` | Kartu dan panel |
| `--bayang` | `0 1px 2px rgba(16,24,40,.04), 0 4px 12px rgba(16,24,40,.06)` | Sangat tipis; kartu dibedakan dari latar oleh bayangan ini **dan** garis, bukan salah satunya |

Radius tombol dan input `8px` — lebih kecil dari kartu, supaya elemen yang bisa
ditekan terbaca sebagai objek yang berbeda dari wadahnya.

### 2.3 Tipografi

| Peran | Ukuran | Berat |
|---|---|---|
| `h1` | 22px | 650 |
| `h2` | 17px | 620 |
| `h3` | 12-13px | 650, huruf besar |
| Badan | 15px | 400 |
| Label | 13px | 560 |
| Tabel | 13.5px | 400 |
| Judul kolom | 11.5px | 620, huruf besar, spasi 0.04em |
| Tombol | 14px | 570 |

Font: `ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`.
Tidak ada font yang diunduh. Alasannya bukan malas: font kustom menambah satu
permintaan jaringan yang bisa gagal, dan pada Render gratis cold start sudah
~50 detik — tidak perlu ditambah.

**Angka uang memakai `font-variant-numeric: tabular-nums`** supaya kolom nominal
rata. Tanpa ini, `Rp 1.500.000` dan `Rp 900.000` tidak sejajar dan mata harus
lebih lama mencari.

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

### 4.3 Yang **tidak** boleh dilakukan

- Jangan menambahkan `overflow-x: hidden` pada `.tabel-bungkus`. Itu
  menyembunyikan kolom, bukan membuatnya muat.
- Jangan menghapus `.tabel-bungkus`. Di layar 621-860px tabel masih bisa lebih
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
  max-width: 1020px;
}
```

`minmax(0, 1fr)`, **bukan** `1fr`. `1fr` berarti `minmax(auto, 1fr)`, dan
`auto` sebagai minimum mengambil lebar min-content: tabel atau kepala halaman
yang tidak bisa menyusut memaksa kolom lebih lebar daripada layar, lalu seluruh
halaman bergulir mendatar di ponsel.

Ini bukan teori. Terukur 753px pada viewport 390px sebelum diperbaiki.

Varian: `.halaman-lebar` (1080px, untuk tabel 11 kolom), `.halaman-penuh`
(tanpa batas).

### 5.2 Kepala halaman

`.kepala` memakai `flex-wrap: wrap` **di dalam** media query, bukan global. Pada
layar lebar judul dan tombol memang muat sebaris, dan membiarkan `wrap` aktif di
sana berarti tata letak desktop bisa berubah hanya karena satu keterangan
menjadi panjang.

### 5.3 Sidebar pemilik

`.tata-letak` = `244px minmax(0, 1fr)`. Di bawah 860px menjadi satu kolom, menu
mendatar yang menggulir sendiri.

`min-width: 0` pada `.sisi` wajib: grid item memakai `min-width: auto`, jadi
menu `max-content` membuat sidebar melebar melewati layar.

### 5.4 Kepala pemilik di ponsel

Diukur pada 360×800: **203px** dari atas sampai konten pertama. Sebelum
dipadatkan: **360px** — hampir setengah layar.

| Bagian | Perlakuan di < 620px |
|---|---|
| Merek + lonceng | Satu baris (`.kepala-sisi`) |
| Menu | Deret mendatar, batang gulir disembunyikan |
| Email akun | Disembunyikan |
| Tombol Keluar | Sebaris dengan avatar (`.baris-akun`) |
| Dua tautan akun | Satu deret (`.tautan-akun`) |

Semuanya tetap ada dan tetap bisa ditekan. Yang hilang hanya tinggi.

---

## 6. Aksesibilitas

| Hal | Aturan |
|---|---|
| Fokus | `input:focus` → `border-color: --aksen` + `box-shadow: 0 0 0 3px --aksen-lemah`. Cincin fokus **tidak boleh** dihapus tanpa pengganti |
| Galat bidang | `aria-invalid="true"` pada input, bukan hanya teks merah |
| Pesan galat | `role="alert"` |
| Sasaran sentuh | ≥ 44px pada `(pointer: coarse)`. Tombol `.mungil` memakai `::after` dengan `inset: -6px` agar area sentuh meluas tanpa menggeser tata letak |
| Kontras | Teks utama `#16181d` di `#ffffff` = 17.8:1. `--teks-lemah` di `--permukaan` = 6.1:1. Keduanya lolos AA |
| Tabel kartu | `thead` disembunyikan dengan `clip-path: inset(50%)`, **bukan** `display: none` — pembaca layar masih menemukannya |
| Bahasa | `<html lang="id">` |

**Yang belum dikerjakan:** belum ada uji aksesibilitas otomatis (axe). Ini
utang teknis yang dicatat, bukan yang diabaikan.

---

## 7. PWA

| Berkas | Isi |
|---|---|
| `web/public/manifest.webmanifest` | `standalone`, `theme_color: #2f6feb`, ikon 192/512/maskable, 2 pintasan |
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
| Pustaka komponen (Vuetify, PrimeVue) | ~560 baris CSS bisa dibaca dalam satu duduk; pustaka menambah ribuan baris yang tidak dibaca siapa pun |
| Tailwind | Kelas panjang di template menyulitkan melihat struktur; dan tata letak di sini hanya butuh ~15 aturan |
| Font web | Satu permintaan jaringan tambahan pada koneksi yang sudah lambat |
| Animasi | Tidak ada yang perlu dianimasikan; angka tidak bergerak sendiri |
| Mode gelap | Belum diminta, dan menambah satu dimensi uji untuk seluruh 25 layar |
| CSS-in-JS | `<style scoped>` sudah cukup dan tidak menambah dependensi build |
