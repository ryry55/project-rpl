# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: 6 November 2026, sesudah diagram kelas dan diagram urutan
(17 SVG + 17 PNG) selesai, masuk gerbang, dan pagarnya terbukti bisa menolak.

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | **Diagram kelas & urutan** — 3 lembar kelas, 14 diagram urutan; T14 sudah di `git log` |
| Suite PHP | **830 lulus / 3883 asersi**, **48 berkas**; pint & eslint bersih |
| Suite Vitest | **19 tes** (2 berkas) untuk fungsi murni: format uang/tanggal, pemotong halaman |
| Migrasi / endpoint / tabel domain | **42** / **89** / **24** (total 33 di skema `public`) |
| Skema | **209 kolom, 29 kunci asing, 8 trigger** (tidak ada trigger baru) |
| Skrip uji | **31** `.mjs`: **30** (27 browser + 2 balapan + 1 jalur proxy) dijalankan gate + `_cdp.mjs` |
| Langkah E2E | **852** di 30 skrip; `e2e-t2` **26/26**, delapan di antaranya T14 |
| Ticket GitHub | **#11, #12, #13, #14 tertutup.** Tidak ada issue terbuka |
| ERD/LRS | `cek-erd-lrs.js` **LULUS**: 24 tabel / 209 kolom / 29 kunci asing; L3 terbaca 2,74 mm di A3 |
| Diagram | `cek-diagram.js` **LULUS**: 3 lembar kelas (2,61 mm) + 14 urutan (2,58–4,46 mm), PNG tepat 2x |

Catatan: `POST /csp-laporan` di luar `/api/`, sengaja tidak masuk hitungan 89 endpoint.

## 2. Dua aksi berikutnya

**Tidak ada pekerjaan deploy.** Pemilik membatalkan deploy 29 Oktober 2026: demo dari laptop
lewat `docker compose`; `9_DEPLOY.md`, `Dockerfile.prod`, `deploy.yml` tinggal sebagai bukti.

**Rinciannya, beserta tiga pertanyaan yang akan ditanya penguji dan urutan H-1:
`docs/fase-akhir.md`.**

1. **Gladi demo bertiga, di layar** — tiga kali, dihitung waktunya
   (`docs/demo-sidang.md`). Ini satu-satunya pekerjaan yang tersisa.
2. **Fase 6 (laporan) menunggu format dosen**; bila datang, hanya `docs/akademik/*` yang dibuat.
   Diagram kelas dan urutan yang diminta dosen **sudah selesai** (6 Nov) dan tidak
   perlu menunggu format apa pun — berkasnya `docs/akademik/kelas-*.png` dan
   `urutan-UC-*.png`.

**Tidak ada keputusan pemilik yang masih menunggu.** K-01..K-13 dijawab atau
ditutup. Yang terakhir (31 Okt, **K-13**): tabel tersendiri, kamar `perbaikan`
tetap ditagih penuh, pemilik **dan** pengelola boleh menutup laporan, dan biaya
perbaikan tidak pernah masuk tagihan penghuni.

Tidak dikerjakan dan tidak akan ditawarkan lagi: Render/Neon, Midtrans sungguhan, pinger uptime,
latihan restore, verifikasi domain Brevo, domain `.my.id`, dan menegakkan CSP.

## 3. Yang tidak boleh diubah

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-07 | `SPEC.md` bagian 4 |
| Wewenang pengelola (8) dan Model A | `SPEC.md` bagian 5 |
| Konvensi T24-T29 | `AGENTS.md` |
| Sumber tunggal DFD / use case | `docs/dfd/dfd-data.js`, `docs/akademik/usecase-data.js` |
| Sumber tunggal diagram kelas / urutan | `docs/akademik/kelas-data.js`, `docs/akademik/urutan-data.js` |
| Keputusan pemilik | `docs/keputusan-terbuka.md` (K-01..K-13) |
| Langkah deploy manual | `docs/produk/9_DEPLOY.md` |

**Keputusan lama tetap berlaku:** `occupancy_rate` = hari-kamar; kuitansi tanpa
nomor urut (penandanya id pembayaran + periode); **deposit dihapus seluruhnya**.

**Bahasa visual: "buku besar tanpa warna".** Arah "kartu indeks katalog"
**ditolak pemilik**. Satu tombol hampir hitam per layar; `nav a`, `.tabel`,
`.lencana-status`, `.aksi-kepala`, `.merek p`, `.kartu-kelompok`, `.kendali`
tetap ada. **Narasi tetap 4 halaman**; **harga Charter Rp 50.000.000**.

## 4. Yang dibangun: diagram kelas & urutan

Rincian T14 (laporan kerusakan kamar) ada di `git log` dan `SPEC.md` §4; yang
baru adalah dua belas gambar untuk dosen.

| Hal | Hasil |
|---|---|
| Diagram kelas | **3 lembar** (akun/akses, kamar/sewa, tagihan/pembayaran); 22 kelas, 27 asosiasi, atribut dari `docs/erd/skema.json`, operasi dari `api/app/Models/*.php` |
| Diagram urutan | **14 lembar**, satu per UC-01..UC-14, peserta nyata dari `api/app` dan endpoint dari `rute-api.json` |
| Keterbacaan | Kelas **2,61 mm** di A3 (satu lembar akan menjadi 1,1 mm); urutan 2,58–4,46 mm |
| Asosiasi lintas lembar | Didaftar di kaki gambar (`Tagihan → Kontrak (kontrak_id, * : 1)`), tidak digambar sebagai garis menggantung |
| Pagar | Ujung garis berimpit, garis menembus kotak, kelas/rute karangan, langkah tak kembali ke aktor, huruf < 2,52 mm — semuanya menolak build |
| Bukti pagar | `node docs/uji/cek-diagram.js` merusak data di dalam dirinya sendiri; bila pagar dimatikan, ia gagal (sudah dicoba) |

## 5. Cara memeriksa keadaan

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1    # SEMUA LULUS
node docs/uji/cek-angka-dokumen.js                     # SEMUA ANGKA COCOK
node docs/uji/cek-diagram.js                           # diagram tidak basi
```

Tanpa uji browser: `-Cepat`. Di Linux/CI: `./periksa.sh`. Bila mengubah
`web/src`: `npm run build`, `docker compose build web`, lalu `up -d web` — kode
dibakar ke image. Angka §1 diubah **sesudah** gate.

Membangun ulang dokumen: `docs/erd/README.md` §"Urutan membangun", `docs/akademik/README.md`.

## 6. Jebakan yang menggigit di sesi ini

Tiga jebakan baru (ujung garis berimpit, skala Chrome, pencari kelas `final`)
sudah naik ke `AGENTS.md` bagian "Jebakan yang sudah menggigit" — jangan
dipindahkan balik ke sini.

- **Angka yang diketik tangan akan tertinggal.** `bab4-data.js` sempat menulis
  "23 tabel / 201 kolom"; tinggi huruf LRS juga pernah diketik di empat berkas.
  Kini diukur `build-tingkat.js`, disimpan di `manifest.json`, dan dibandingkan
  `cek-angka-dokumen.js`.
- **`Queue::fake()` wajib untuk menguji antrean.** Dengan `sync`, pekerjaan
  berjalan di dalam permintaan dan `Mail::assertSent` tetap lulus.
