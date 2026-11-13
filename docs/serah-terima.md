# Serah-terima antar sesi

> **Ditimpa setiap fase selesai.** Isinya: apa yang barusan dikerjakan, tiga aksi
> berikutnya, dan apa yang tidak boleh diubah. Riwayatnya di `git log`.
> **Maksimal 120 baris** — lebih dari itu ia menjadi `SPEC.md` kedua.

Terakhir diperbarui: 13 November 2026, sesudah Fase 7 (revisi desain).

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Fase 7** — revisi desain, mockup layar baru, audit angka dokumen |
| Suite PHP | **912 lulus / 4114 asersi**, **54 berkas**; pint & eslint bersih |
| Suite Vitest | **19 tes** (2 berkas) untuk fungsi murni: format uang/tanggal, pemotong halaman |
| Migrasi / endpoint / tabel domain | **47** / **100** / **26** (total 35 di skema `public`) |
| Skrip uji | **35** `.mjs`: **34** (31 browser + 2 balapan + 1 jalur proxy) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **942** di 34 skrip, **546 detik** gate penuh; `asap-semua-halaman.mjs` **25/25** |
| Keputusan pemilik | K-14, K-15, K-16, K-17 dijawab |
| Ticket GitHub | **#11, #12, #13, #14 tertutup.** Tidak ada issue terbuka |

Catatan: `POST /csp-laporan` di luar `/api/`, sengaja tidak masuk hitungan 100 endpoint.

## 2. Yang Fase 7 kerjakan

Lima cacat layar, semua terlihat di potret 1440 & 390 px lalu diukur sesudahnya:

1. Kepala portal di 390px: "Tagihan saya" pecah dua baris karena berebut ruang
   dengan tiga tombol → `@media (max-width: 620px)` memberi judul barisnya sendiri.
2. Kartu **WiFi kos** pindah ke atas formulir kerusakan: "sandi WiFi-nya apa"
   ditanyakan jauh lebih sering daripada kerusakan dilaporkan.
3. `input[type=file]` memakai bahasa tombol aplikasi, bukan tombol abu-abu
   peramban → aturan global di `style.css`, butir 12 di `2_DESIGN_SYSTEM.md` §8.
4. Kartu kelompok tunggakan berisi **0** tidak lagi berwarna keparahan. Terukur
   `rgb(196,36,42)` → `rgb(10,10,10)`; "1 belum jatuh tempo" tetap abu. Merah
   pada nol membuat pemilik mencari masalah yang tidak ada.
5. Kalimat yang diulang di kepala dan kaki `PerangkatView.vue` dihapus;
   `.tarif-sejak` tidak lagi mono — yang perlu rata hanya nominalnya.

Selain itu `/pemilik/perangkat` masuk sapuan `asap-semua-halaman.mjs` (halaman
baru sebelumnya tidak disapu), dan angka basi di 12 dokumen disegarkan:
898/4072/919/941/33 skrip → 912/4114/942/34, piramida 730+100 → 796+116,
`style.css` 964 → 992 baris. **Mockup delapan layar tetap di luar repo**
(`C:\Users\ACER\mockup-kos`, PNG 2x): `perangkat.png` + `akses-saya.png` baru.

## 3. Tiga aksi berikutnya

1. **Sebelum sidang:** latihan demo 3x, uji beban di laptop demo
   (`node docs/beban/uji-beban.mjs`, tidak ikut gate).
2. **K-18 dijawab 13 November:** tanggal terbit yang melewati jatuh tempo tetap
   **peringatan saja**, bukan 422. Tidak ada perubahan kode.
3. **Opsional:** tempel `perangkat.png` / `akses-saya.png` ke slide bila sidang
   memerlukannya; gambar sumbernya bukan milik repo.

## 4. Yang tidak boleh diubah

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-08 + identitas kos + laporan kerusakan + perangkat | `SPEC.md` bagian 4 |
| Wewenang pengelola (8) dan Model A | `SPEC.md` bagian 5 |
| Konvensi T24-T29 | `AGENTS.md` |
| Sumber tunggal DFD / use case | `docs/dfd/dfd-data.js`, `docs/akademik/usecase-data.js` |
| Sumber tunggal diagram kelas / urutan | `docs/akademik/kelas-data.js`, `docs/akademik/urutan-data.js` |
| Keputusan pemilik | `docs/keputusan-terbuka.md` (K-01..K-17) |
| Langkah deploy manual | `docs/produk/9_DEPLOY.md` |

**Desain dikunci pada arah "buku besar tanpa warna"** (`2_DESIGN_SYSTEM.md`).
Fase 7 tidak mengecat ulang: tanpa token baru, tanpa palet kedua. Warna hanya
untuk uang, galat, dan keparahan yang benar-benar berisi.

**Tetap berlaku:** `occupancy_rate` = hari-kamar; kuitansi tanpa nomor urut;
**deposit dihapus**; biaya perbaikan kerusakan **tidak pernah** masuk tagihan.

**K-14:** satu saklar global (`tanggal_1`, `anniversary`); tanggal terbit 1–28
**hanya menunda**; ganti pola **tidak menagih mundur**.
**K-15:** `kamar_id` laporan diambil server dari kontrak aktif; foto ≤ 5 MB, ≤ 3
per laporan, hanya pelapor + pemilik/pengelola, lewat tautan bertanda tangan.
**K-16:** satu tabel `perangkat`; sandi lewat cast `encrypted`, tidak pernah ikut
di daftar; penghuni hanya melihat **WiFi** dan hanya bila kontraknya berjalan;
wewenangnya `kelola_kamar`, bukan wewenang kesembilan.
**K-17:** nama kos = kebijakan global berteks bebas; layar masuk membacanya lewat
endpoint **publik**; pratinjau denda dihitung **server**.

## 5. Jebakan yang menggigit di fase ini

- **Jangan menyunting berkas selama gate berjalan.** eslint membaca berkas yang
  sedang ditulis dan melaporkan konstanta "never used" yang sebenarnya dipakai.
  13 November: gate merah di eslint padahal `npx eslint .` bersih sesudahnya.
  Sunting dulu, gate kemudian; kalau terlanjur, ulangi gate, jangan hapus langkah.
- **`taste-skill-ignore` harus di baris yang SAMA dengan teks yang ditandai.**
  Komentar di baris sebelumnya tidak menekan apa pun — `lint.mjs` membaca per baris.
- **`inspect.mjs` dari skill tidak bisa login ke SPA.** Potret layar ber-sesi
  memakai `docs/uji/_cdp.mjs` (Chrome 9222). Batas ini disebut apa adanya.
- **Nol bukan keparahan.** `kelasKelompok(k)` menerima objek kelompok dan
  memasang warna hanya bila `k.jumlah > 0`; mengembalikan `k.kunci` saja membuat
  "0 di atas 60 hari" merah kembali.
- **`docs/uji/*.png` ditulis ulang setiap gate** → `git checkout -- docs/uji/*.png`
  sebelum commit. Fase ini tidak mengubah tangkapan layar.
- **`docs/revisi.md` gitignored** (masukan audit); R4-05 ✅ hanya lokal.
- **Menambah aturan di `style.css` mengubah angka dokumen.** `3_ARCHITECTURE.md`
  menyebut jumlah barisnya (kini 992), dan `cek-angka-dokumen.js` tidak memeriksa
  angka itu — ia bisa basi tanpa ada yang merah.
- **Image `api` DAN `web` dibakar.** `npm run build` saja tidak cukup; bangun
  kedua image sebelum E2E, lalu `restart api-web` (nginx menyimpan IP lama).

## 6. Cara memeriksa keadaan

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1              # SEMUA LULUS
powershell -ExecutionPolicy Bypass -File periksa.ps1 -TanpaBuild  # bila image sudah dibangun
node docs/uji/cek-angka-dokumen.js                                # SEMUA ANGKA COCOK
node docs/uji/cek-diagram.js                                      # diagram tidak basi
```

Tanpa uji browser: `-Cepat`. Di Linux/CI: `./periksa.sh`. Angka §1 diubah
**sesudah** gate. Membangun ulang dokumen: `docs/erd/README.md` dan
`docs/akademik/README.md` bagian "Cara membangun ulang".
