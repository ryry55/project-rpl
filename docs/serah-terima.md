# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: 13 November 2026, sesudah Fase 3 (E2E anniversary, amandemen
SPEC R-08, dan penyegaran angka dokumen).

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Fase 3** — E2E anniversary + amandemen SPEC R-08 + dokumen |
| Suite PHP | **872 lulus / 3987 asersi**, **51 berkas**; pint & eslint bersih |
| Suite Vitest | **19 tes** (2 berkas) untuk fungsi murni: format uang/tanggal, pemotong halaman |
| Migrasi / endpoint / tabel domain | **44** / **89** / **24** (total 33 di skema `public`) |
| Skrip uji | **32** `.mjs`: **31** (28 browser + 2 balapan + 1 jalur proxy) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **895** di 31 skrip; `e2e-anniversary.mjs` **16/16** |
| Keputusan pemilik | K-14 (pola + tanggal terbit), K-15 (laporan kerusakan), K-16 (akses perangkat) dijawab |
| Ticket GitHub | **#11, #12, #13, #14 tertutup.** Tidak ada issue terbuka |

Catatan: `POST /csp-laporan` di luar `/api/`, sengaja tidak masuk hitungan 89 endpoint.

## 2. Tiga aksi berikutnya

1. **Fase 4 — nama kos + pratinjau denda.** Kebijakan `nama_kos` (dipakai kuitansi,
   notifikasi, dan layar masuk) dan endpoint pratinjau denda yang memakai
   `Kalkulator` yang sama dengan tagihan sungguhan. Jangan menyalin rumus ke
   JavaScript.
2. **Fase 5 — laporan kerusakan + foto (K-15).** Tabel anak `lampiran_kerusakan`,
   penyimpanan privat + tautan bertanda tangan, formulir di `DashboardPenghuni.vue`,
   foto di `KamarView.vue`. ERD/DFD ikut diperbarui saat fitur mendarat.
3. **Fase 6 — akses perangkat (K-16).** Dua tabel, `PenyediaPerangkat` + 4 adaptor,
   `perangkat:uji`, `/kartu/{id}`, ±11 endpoint. Fase 7 (revisi desain) sesudahnya.

**Satu pertanyaan pemilik yang belum dijawab:** tanggal terbit yang melewati jatuh
tempo saat ini hanya **diperingatkan** di layar, tidak ditolak server. Kalau pemilik
ingin 422, itu perubahan perilaku dan harus ditanyakan lebih dulu.

## 3. Yang tidak boleh diubah

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-08 | `SPEC.md` bagian 4 |
| Wewenang pengelola (8) dan Model A | `SPEC.md` bagian 5 |
| Konvensi T24-T29 | `AGENTS.md` |
| Sumber tunggal DFD / use case | `docs/dfd/dfd-data.js`, `docs/akademik/usecase-data.js` |
| Sumber tunggal diagram kelas / urutan | `docs/akademik/kelas-data.js`, `docs/akademik/urutan-data.js` |
| Keputusan pemilik | `docs/keputusan-terbuka.md` (K-01..K-16) |
| Langkah deploy manual | `docs/produk/9_DEPLOY.md` |

**Keputusan lama tetap berlaku:** `occupancy_rate` = hari-kamar; kuitansi tanpa
nomor urut; **deposit dihapus seluruhnya**; biaya perbaikan kerusakan **tidak pernah**
masuk tagihan penghuni.

**K-14 yang dikunci:** pola tagihan adalah **satu saklar global** (`tanggal_1` bawaan,
`anniversary` pilihan), bukan per kontrak. Tanggal terbit **1–28** dan **hanya menunda**,
tidak pernah memajukan (arti A). Ganti pola **tidak menagih mundur**; duplikat dicek
per **bulan**, bukan per tanggal persis.

**Yang sengaja tidak diubah:** `PenyelesaiKebijakan`/`PencariTarif` tetap menyelesaikan
kebijakan terhadap `startOfMonth(periode)` — dikunci
`KebijakanKosTest::test_kebijakan_bertanggal_tengah_bulan_belum_berlaku_untuk_bulan_itu`.

## 4. Jebakan yang menggigit di fase ini

- **Image `web` juga dibakar.** `npm run build` saja tidak cukup: E2E membaca SPA dari
  container. Dua asersi baru (peringatan tanggal terbit, penanda "seluruh kos") gagal
  sampai `docker compose build web` dijalankan. Bangun **kedua** image sebelum E2E.
- **Chrome mati saat `docker compose build`.** Build dua image menghabiskan memori dan
  Chrome dengan `--remote-debugging-port` ikut terbunuh; gejalanya **semua** skrip
  browser gagal dengan `fetch failed`, bukan satu asersi. Jalankan gate dengan
  `-TanpaBuild` bila image sudah dibangun, atau nyalakan Chrome sesudah build.
- **`api-web` menyimpan IP lama `api`.** Sesudah `docker compose up -d api`, restart
  `api-web` bila login tiba-tiba gagal. Gejalanya menyesatkan: health API hijau.
- **Urutan migrasi menentukan.** `pola_tagihan` (23 Nov) dan `tanggal_terbit` (24 Nov)
  memasang ulang CHECK yang sama; migrasi yang lebih awal akan ditimpa yang lebih akhir.
  Jangan memberi tanggal di antara keduanya.
- **`KebijakanKos::TIPE_*` adalah alias.** Tipe baru harus ditambahkan di
  `PilihanKebijakan` **dan** dialiaskan di model, atau 17 tes gagal dengan
  `Undefined constant`.
- **Tipe global harus lewat `PilihanKebijakan::tipeGlobal()`.** Daftar itu dipakai
  validasi (`kamar_id` ditolak) dan meta layar. Menyalinnya di Vue membuat formulir
  yang terlihat sah dijawab 422.
- **`label_periode` ada karena satu batch bisa berisi tanggal berbeda.** Jangan
  kembalikan notifikasi massal ke satu tanggal.

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
