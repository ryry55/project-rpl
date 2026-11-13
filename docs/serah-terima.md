# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini. **Maksimal 120 baris** —
> lebih dari itu, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: 13 November 2026, sesudah Fase 5 (laporan kerusakan dari
portal penghuni, dengan foto).

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Fase 5** — laporan kerusakan dari portal penghuni + foto (K-15) |
| Suite PHP | **898 lulus / 4072 asersi**, **53 berkas**; pint & eslint bersih |
| Suite Vitest | **19 tes** (2 berkas) untuk fungsi murni: format uang/tanggal, pemotong halaman |
| Migrasi / endpoint / tabel domain | **46** / **94** / **25** (total 34 di skema `public`) |
| Skrip uji | **34** `.mjs`: **33** (30 browser + 2 balapan + 1 jalur proxy) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **919** di 33 skrip; `e2e-kerusakan.mjs` **14/14** |
| Keputusan pemilik | K-14, K-15, K-16, K-17 dijawab |
| Ticket GitHub | **#11, #12, #13, #14 tertutup.** Tidak ada issue terbuka |

Catatan: `POST /csp-laporan` di luar `/api/`, sengaja tidak masuk hitungan 94 endpoint.

## 2. Tiga aksi berikutnya

1. **Fase 6 — akses perangkat (K-16).** Dua tabel, `PenyediaPerangkat` + 4 adaptor,
   `perangkat:uji`, `/kartu/{id}`, ±11 endpoint.
2. **Fase 7 — revisi desain.** Poles seluruh layar, mockup layar baru, audit
   diagram/dokumen terhadap kenyataan.
3. **Sebelum sidang:** latihan demo 3x, uji beban di laptop demo.

**Satu pertanyaan pemilik yang belum dijawab:** tanggal terbit yang melewati jatuh
tempo saat ini hanya **diperingatkan** di layar, tidak ditolak server. Kalau pemilik
ingin 422, itu perubahan perilaku dan harus ditanyakan lebih dulu.
## 3. Yang tidak boleh diubah

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-08 + identitas kos + laporan kerusakan | `SPEC.md` bagian 4 |
| Wewenang pengelola (8) dan Model A | `SPEC.md` bagian 5 |
| Konvensi T24-T29 | `AGENTS.md` |
| Sumber tunggal DFD / use case | `docs/dfd/dfd-data.js`, `docs/akademik/usecase-data.js` |
| Sumber tunggal diagram kelas / urutan | `docs/akademik/kelas-data.js`, `docs/akademik/urutan-data.js` |
| Keputusan pemilik | `docs/keputusan-terbuka.md` (K-01..K-17) |
| Langkah deploy manual | `docs/produk/9_DEPLOY.md` |

**Keputusan lama tetap berlaku:** `occupancy_rate` = hari-kamar; kuitansi tanpa
nomor urut; **deposit dihapus seluruhnya**; biaya perbaikan kerusakan **tidak pernah**
masuk tagihan penghuni.
**K-14 yang dikunci:** pola tagihan adalah **satu saklar global** (`tanggal_1` bawaan,
`anniversary` pilihan), bukan per kontrak. Tanggal terbit **1–28** dan **hanya menunda**,
tidak pernah memajukan (arti A). Ganti pola **tidak menagih mundur**; duplikat dicek
per **bulan**.

**K-15 yang dikunci:** `kamar_id` laporan dari portal **diambil server dari kontrak
aktif**, tidak pernah dari badan permintaan. Foto JPG/PNG/WEBP, ≤ 5 MB, ≤ 3 per
laporan, hanya untuk pelapor + pemilik/pengelola, lewat tautan bertanda tangan.

**K-17 yang dikunci:** nama kos adalah kebijakan **global berteks bebas** (1–60),
kosong = "Kos-Kosan". Layar masuk membacanya lewat endpoint **publik**; pratinjau
denda dihitung **server** lewat `Kalkulator`.

## 4. Jebakan yang menggigit di fase ini

- **Tautan foto wajib RELATIF dan rutenya `signed:relative`.** Tautan absolut ke
  port API (8081) diblokir CSP produksi (`img-src 'self'`) tanpa pesan: elemennya
  ada, gambarnya tidak muncul. Kontrol negatif: `absolute: true` membuat
  `e2e-kerusakan` merah di dua langkah foto, lalu hijau lagi.
- **`bytea` ditulis lewat `decode(?, 'base64')`, INSERT mentah dengan `RETURNING id`.**
  Parameter teks biasa tersimpan sebagai **0 byte** tanpa galat: unggahan "berhasil",
  `ukuran` terisi, gambarnya kosong. Jalurnya hanya di `PenyimpanLampiran`.
- **`<input type="file">` tidak bisa diisi dari JavaScript halaman.** E2E memakai
  `DOM.setFileInputFiles` (butuh `DOM.getDocument` + `DOM.querySelector` untuk
  `nodeId`). `DataTransfer` rakitan sendiri tidak membawa berkas asli.
- **`naturalWidth > 0` adalah satu-satunya bukti gambar termuat.** Keberadaan `img`
  atau `src` bertanda tangan tidak membuktikan apa pun: gambar yang diblokir tetap
  punya keduanya.
- **Portal penghuni memuat lima bagian sendiri-sendiri.** Setiap "Belum ada …" wajib
  dijaga `!galat*`-nya; tanpa `!galatLaporan`, `uji-galat-muat.mjs` merah. Kasus itu
  kini memblokade `/api/penghuni/*` seluruhnya — bagian yang tetap berhasil akan
  menampilkan "Belum ada" yang benar untuk dirinya sendiri, tetapi terbaca sebagai
  klaim kosong untuk halaman yang gagal.
- **`max:` untuk berkas di validasi Laravel dihitung dalam KILOBITA.** Konstanta
  `BATAS_UKURAN` dalam byte; tanpa ` / 1024` batasnya menjadi 5 byte.
- **Dokumen akademik menolak ditulis bila datanya tidak lengkap.** Rute API baru harus
  masuk `usecase-data.js`; tabel baru harus masuk `TABEL_AKHIR` (`bab4-data.js`) dan
  `lrs-data.js`. `tabel-domain.json` ditulis Node — `ConvertTo-Json` atas larik
  membungkusnya jadi `{ "value": [...] }` dan build gagal `object is not iterable`.
- **`docs/erd/build-tingkat.js` menolak panah yang berlabuh < 22 px.** Menambah
  tabel bisa membuat dua kunci asing ke induk yang sama berlabuh 15 px terpisah.
  Jarak tawaran titik labuh karena itu `JARAK_LABUH` penuh, bukan `0,55 x`.
- **`rute-api.json` dan `tabel-domain.json` adalah potret.** Menambah endpoint/tabel
  membuat `cek-angka-dokumen.js` melaporkan BEDA sampai keduanya disegarkan.
- **Regex `\\.` di berkas `.mjs` berarti "backslash literal".** Pakai
  `teksMemuat(teks, '45.000')`.
- **Urutan migrasi menentukan.** `pola_tagihan` (23 Nov), `tanggal_terbit` (24 Nov),
  `nama_kos` (25 Nov), `lampiran_kerusakan` (26 Nov) — yang lebih awal ditimpa yang
  lebih akhir bila memasang CHECK yang sama.
- **Image `web` juga dibakar.** `npm run build` saja tidak cukup: E2E membaca SPA dari
  container. Bangun **kedua** image sebelum E2E. Chrome mati saat build; gejalanya
  **semua** skrip browser gagal `fetch failed`, bukan satu asersi — jalankan gate
  `-TanpaBuild` bila image sudah dibangun.

## 5. Cara memeriksa keadaan

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1              # SEMUA LULUS
powershell -ExecutionPolicy Bypass -File periksa.ps1 -TanpaBuild  # bila image sudah dibangun
node docs/uji/cek-angka-dokumen.js                                # SEMUA ANGKA COCOK
node docs/uji/cek-diagram.js                                      # diagram tidak basi
```

Tanpa uji browser: `-Cepat`. Di Linux/CI: `./periksa.sh`. Bila mengubah `web/src`:
`npm run build`, `docker compose build web`, lalu `up -d web`. Angka §1 diubah
**sesudah** gate. Membangun ulang dokumen: `docs/erd/README.md` §"Cara membangun
ulang", `docs/akademik/README.md`.
