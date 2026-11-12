# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: 13 November 2026, sesudah Fase 4 (nama kos + pratinjau
denda).

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Fase 4** — kebijakan `nama_kos` + pratinjau denda |
| Suite PHP | **886 lulus / 4024 asersi**, **52 berkas**; pint & eslint bersih |
| Suite Vitest | **19 tes** (2 berkas) untuk fungsi murni: format uang/tanggal, pemotong halaman |
| Migrasi / endpoint / tabel domain | **45** / **91** / **24** (total 33 di skema `public`) |
| Skrip uji | **33** `.mjs`: **32** (29 browser + 2 balapan + 1 jalur proxy) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **905** di 32 skrip; `e2e-nama-kos.mjs` **10/10** |
| Keputusan pemilik | K-14 (pola + tanggal terbit), K-15 (kerusakan), K-16 (perangkat), K-17 (nama kos) dijawab |
| Ticket GitHub | **#11, #12, #13, #14 tertutup.** Tidak ada issue terbuka |

Catatan: `POST /csp-laporan` di luar `/api/`, sengaja tidak masuk hitungan 91 endpoint.

## 2. Tiga aksi berikutnya

1. **Fase 5 — laporan kerusakan + foto (K-15).** Tabel anak `lampiran_kerusakan`,
   penyimpanan privat + tautan bertanda tangan, formulir di `DashboardPenghuni.vue`,
   foto di `KamarView.vue`. ERD/DFD ikut diperbarui saat fitur mendarat.
2. **Fase 6 — akses perangkat (K-16).** Dua tabel, `PenyediaPerangkat` + 4 adaptor,
   `perangkat:uji`, `/kartu/{id}`, ±11 endpoint.
3. **Fase 7 — revisi desain.** Poles seluruh layar, mockup layar baru, audit
   diagram/dokumen terhadap kenyataan.

**Satu pertanyaan pemilik yang belum dijawab:** tanggal terbit yang melewati jatuh
tempo saat ini hanya **diperingatkan** di layar, tidak ditolak server. Kalau pemilik
ingin 422, itu perubahan perilaku dan harus ditanyakan lebih dulu.

## 3. Yang tidak boleh diubah

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-08 + identitas kos | `SPEC.md` bagian 4 |
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
per **bulan**, bukan per tanggal persis.

**K-17 yang dikunci:** nama kos adalah kebijakan **global berteks bebas** (1–60),
kosong = "Kos-Kosan". Layar masuk membacanya lewat endpoint **publik**; pratinjau
denda dihitung **server** lewat `Kalkulator`, tidak pernah disalin ke JavaScript.

**Yang sengaja tidak diubah:** `PenyelesaiKebijakan`/`PencariTarif` tetap menyelesaikan
kebijakan terhadap `startOfMonth(periode)` — dikunci
`KebijakanKosTest::test_kebijakan_bertanggal_tengah_bulan_belum_berlaku_untuk_bulan_itu`.

## 4. Jebakan yang menggigit di fase ini

- **`nama_kos` adalah tipe teks, bukan menu.** `PilihanKebijakan::menu()` sengaja tidak
  punya entrinya, jadi `Rule::in(nilaiValid('nama_kos'))` menolak **semua** nilai.
  Validasi harus bercabang lewat `PilihanKebijakan::tipeTeks()`.
- **Endpoint `/identitas-kos` wajib di luar `auth:sanctum`.** Layar masuk dibuka sebelum
  ada token. Kontrol negatif sudah dijalankan: memindahkannya ke dalam auth membuat
  `e2e-nama-kos` merah tepat di langkah layar masuk.
- **`DendaTerpilih::dariNilai` adalah satu-satunya pemetaan bentuk denda.** Jangan
  menyalin `match` itu lagi di pengendali baru; pratinjau yang menyalinnya akan berbeda
  dari tagihan sungguhan.
- **Nama kos dibaca saat email DIKIRIM, bukan saat diantrekan.** Mailable-nya queued;
  membekukan nama di properti berarti surat memakai nama lama bila pemilik menggantinya
  sebelum antreannya jalan.
- **`inisial` sudah dipakai avatar pengguna di `TataLetakPemilik.vue`.** Lencana kos
  memakai `inisialKos`; nama yang sama membuat eslint gagal `Identifier already declared`.
- **`rute-api.json` adalah potret, bukan hitungan langsung.** Menambah endpoint membuat
  `cek-angka-dokumen.js` melaporkan BEDA sampai potretnya disegarkan
  (`route:list --json --path=api` → `docs/akademik/rute-api.json`).
- **Regex `\\.` di berkas `.mjs` berarti "backslash literal".** Terjadi lagi di
  `e2e-nama-kos` (`/45\\.000/`); pakai `teksMemuat(teks, '45.000')`.
- **Urutan migrasi menentukan.** `pola_tagihan` (23 Nov), `tanggal_terbit` (24 Nov), dan
  `nama_kos` (25 Nov) memasang ulang CHECK yang sama; yang lebih awal ditimpa yang lebih
  akhir. Jangan menyisipkan migrasi di antara tanggal-tanggal itu.
- **`KebijakanKos::TIPE_*` adalah alias.** Tipe baru harus ditambahkan di
  `PilihanKebijakan` **dan** dialiaskan di model, atau tes gagal `Undefined constant`.
- **Image `web` juga dibakar.** `npm run build` saja tidak cukup: E2E membaca SPA dari
  container. Bangun **kedua** image sebelum E2E.
- **Chrome mati saat `docker compose build`.** Gejalanya **semua** skrip browser gagal
  `fetch failed`, bukan satu asersi. Jalankan gate `-TanpaBuild` bila image sudah dibangun.

## 5. Cara memeriksa keadaan

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1              # SEMUA LULUS
powershell -ExecutionPolicy Bypass -File periksa.ps1 -TanpaBuild  # bila image sudah dibangun
node docs/uji/cek-angka-dokumen.js                                # SEMUA ANGKA COCOK
node docs/uji/cek-diagram.js                                      # diagram tidak basi
```

Tanpa uji browser: `-Cepat`. Di Linux/CI: `./periksa.sh`. Bila mengubah `web/src`:
`npm run build`, `docker compose build web`, lalu `up -d web`. Angka §1 diubah
**sesudah** gate.

Membangun ulang dokumen: `docs/erd/README.md` §"Urutan membangun", `docs/akademik/README.md`.
