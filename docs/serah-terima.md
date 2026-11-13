# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini. **Maksimal 120 baris** —
> lebih dari itu, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: 13 November 2026, sesudah Fase 6 (kredensial perangkat, K-16).

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Fase 6** — kredensial perangkat WiFi/CCTV/RFID (K-16) |
| Suite PHP | **912 lulus / 4114 asersi**, **54 berkas**; pint & eslint bersih |
| Suite Vitest | **19 tes** (2 berkas) untuk fungsi murni: format uang/tanggal, pemotong halaman |
| Migrasi / endpoint / tabel domain | **47** / **100** / **26** (total 35 di skema `public`) |
| Skrip uji | **35** `.mjs`: **34** (31 browser + 2 balapan + 1 jalur proxy) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **941** di 34 skrip; `e2e-perangkat.mjs` **22/22** |
| Keputusan pemilik | K-14, K-15, K-16, K-17 dijawab |
| Ticket GitHub | **#11, #12, #13, #14 tertutup.** Tidak ada issue terbuka |

Catatan: `POST /csp-laporan` di luar `/api/`, sengaja tidak masuk hitungan 100 endpoint.

## 2. Tiga aksi berikutnya

1. **Fase 7 — revisi desain.** Poles seluruh layar, mockup layar baru
   (`PerangkatView`, kartu WiFi portal), audit diagram/dokumen terhadap kenyataan.
2. **Sebelum sidang:** latihan demo 3x, uji beban di laptop demo.
3. **Opsional:** putuskan apakah tanggal terbit > jatuh tempo harus 422 (lihat §2 akhir).

**Satu pertanyaan pemilik yang belum dijawab:** tanggal terbit yang melewati jatuh
tempo saat ini hanya **diperingatkan** di layar, tidak ditolak server. Kalau pemilik
ingin 422, itu perubahan perilaku dan harus ditanyakan lebih dulu.

## 3. Yang tidak boleh diubah

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-08 + identitas kos + laporan kerusakan + perangkat | `SPEC.md` bagian 4 |
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

**K-16 yang dikunci:** perangkat = **satu tabel** `perangkat` (bukan tabel per jenis),
sandi lewat cast `encrypted`, **tidak pernah ikut di daftar** — hanya lewat
`GET /perangkat/{id}/sandi`. Penghuni cuma melihat **WiFi aktif** dan hanya bila
kontraknya masih berjalan. Wewenangnya **`kelola_kamar`**, bukan wewenang kesembilan.

**K-17 yang dikunci:** nama kos adalah kebijakan **global berteks bebas** (1–60),
kosong = "Kos-Kosan". Layar masuk membacanya lewat endpoint **publik**; pratinjau
denda dihitung **server** lewat `Kalkulator`.

## 4. Jebakan yang menggigit di fase ini

- **Sandi terenkripsi: uji isi kolomnya, bukan nilai model.** `DB::table('perangkat')
  ->value('sandi')` yang masih berbunyi teks biasa adalah satu-satunya bukti cast
  `encrypted` lupa dipasang. Model tetap mengembalikan nilai yang "benar".
- **Log audit tidak boleh memuat sandi.** `salinan()` di controller sengaja tidak
  menyertakan `sandi`; yang dicatat `ada_sandi` + penanda `sandi_diganti`. Menambah
  `sandi` ke salinan itu membatalkan seluruh gunanya enkripsi.
- **`PUT` dengan `sandi` kosong berarti "jangan ganti".** Tanpa itu, memperbaiki
  letak perangkat menghapus sandinya, dan penghuni yang meminta sandi sesudahnya
  tidak mendapat apa pun.
- **Tautan foto wajib RELATIF dan rutenya `signed:relative`.** Tautan absolut ke
  port API (8081) diblokir CSP produksi (`img-src 'self'`) tanpa pesan. Kontrol
  negatif: `absolute: true` membuat `e2e-kerusakan` merah di dua langkah foto.
- **`bytea` ditulis lewat `decode(?, 'base64')`, INSERT mentah dengan `RETURNING id`.**
  Parameter teks biasa tersimpan sebagai **0 byte** tanpa galat.
- **`<input type="file">` tidak bisa diisi dari JavaScript halaman.** E2E memakai
  `DOM.setFileInputFiles`; `DataTransfer` rakitan sendiri tidak membawa berkas asli.
  **`naturalWidth > 0` adalah satu-satunya bukti gambar termuat.**
- **Portal penghuni memuat enam bagian sendiri-sendiri.** Setiap "Belum ada …" wajib
  dijaga `!galat*`-nya; `uji-galat-muat.mjs` memblokade `/api/penghuni/*` seluruhnya.
- **Menambah tabel = lima tempat, bukan satu.** `docs/erd/tingkat-data.js` (L0/L1
  `dikecualikan` **dan** `MODUL`), `docs/erd/lrs-data.js`, `docs/akademik/kelas-data.js`,
  `docs/akademik/bab4-data.js` (`TABEL_AKHIR`), `docs/akademik/dokumen-data.js`
  (`ENTITAS_DATA`). Masing-masing menolak build dengan pesan yang berbeda.
- **Menambah endpoint = `usecase-data.js`.** `build-dokumen.js` menolak menulis bila
  ada endpoint yang tidak menjadi dasar use case mana pun.
- **`rute-api.json` dan `tabel-domain.json` adalah potret.** `tabel-domain.json`
  ditulis Node — `ConvertTo-Json` atas larik membungkusnya jadi `{ "value": [...] }`.
- **Angka huruf LRS berubah setiap tabel bertambah** (25→26 tabel: 2,62 → **2,54 mm**,
  masih di atas ambang 2,52). Tabel bentuk lembar di README **harus diukur ulang**
  dengan `$env:UKUR_KERTAS='1'; node docs/erd/build-tingkat.js`, bukan ditebak.
- **`build-tingkat.js` menolak panah yang berlabuh < 22 px** dan garis berimpit
  > 20 px. Titik labuh kini ditawar `JARAK_LABUH` penuh, bukan `0,55 x`.
- **Urutan migrasi menentukan.** `pola_tagihan` (23 Nov) … `lampiran_kerusakan`
  (26 Nov), `perangkat` (27 Nov) — yang lebih akhir memasang CHECK yang sama.
- **Image `api` DAN `web` dibakar.** `npm run build` saja tidak cukup; bangun kedua
  image sebelum E2E. Chrome mati saat build → semua skrip gagal `fetch failed`.
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
