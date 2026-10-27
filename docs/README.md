# Peta berkas `docs/`

> Dokumen ini menjawab satu pertanyaan: **"berkas yang mana yang saya butuhkan?"**
> Kalau Anda hanya ingin tahu apa yang diserahkan ke dosen, lompat ke
> [Yang diserahkan](#yang-diserahkan-ke-dosen). Sisanya hanya perlu dibaca
> kalau Anda mau membangun ulang atau memeriksa.

## Tiga berkas yang paling sering dibuka

| Kalau Anda ingin… | Buka |
|---|---|
| melihat hasil akhir yang diserahkan | [Yang diserahkan](#yang-diserahkan-ke-dosen) di bawah |
| tahu **cara** sesuatu dibangun dan mengapa | `README.md` di folder terkait (`akademik/`, `erd/`, `dfd/`) |
| tahu **keputusan** apa yang sudah dikunci | `SPEC.md` di akar repo |

## Yang diserahkan ke dosen

Lima berkas. Tidak ada yang lain.

| Berkas | Halaman | Isi |
|---|---|---|
| `charter/Project_Charter_Kos-Kosan.pdf` | — | Project charter |
| `akademik/Proses_Bisnis_Kos_Kosan.pdf` | 4 | Narasi tujuh bab |
| `akademik/Kos_Kosan_Diagrams_Pertemuan_4.pdf` | 2 | Use case + DFD konteks |
| `akademik/Bab_4_Implementasi_dan_Pengujian.pdf` | 8 | Normalisasi NF0–3NF, ERD, LRS, spesifikasi data |
| `erd/03-L3-lrs.png` | 1 lembar A3 | LRS — **lampiran terpisah**, bukan tempelan Bab 4 |

`charter/Project_Charter_Kos-Kosan.docx` adalah sumber yang bisa disunting
untuk charter. Empat berkas `.pdf`/`.png` lainnya **dibangkitkan** — jangan
disunting tangan.

## Folder, satu baris masing-masing

| Folder | Isi | Disunting tangan? |
|---|---|---|
| `agents/` | Aturan kerja untuk agen (domain, issue tracker, label) | ya |
| `akademik/` | Laporan akademik + skrip pembangkitnya | **tidak** — hasil bangkitan |
| `charter/` | Project charter + skrip pembangkit `.docx` | `.docx` ya, sisanya tidak |
| `dfd/` | DFD Gane-Sarson (utama) dan Yourdon/DeMarco (pembanding) | **tidak** |
| `erd/` | ERD berjenjang L0→L3 dan LRS A3 | **tidak** |
| `produk/` | Dokumen produk: PRD, arsitektur, skema, deploy, operasi | ya |
| `referensi/` | Catatan susunan Bab 4 menurut laporan contoh dosen | ya |
| `uji/` | Skrip uji E2E + tangkapan layar bukti | skrip ya, `.png` tidak |

## Berkas lepas di `docs/`

| Berkas | Untuk siapa |
|---|---|
| `serah-terima.md` | **Sesi berikutnya.** Keadaan repo, apa yang belum selesai |
| `utang-teknis.md` | Hal yang diketahui kurang, beserta alasannya |
| `demo-sidang.md` | Anda, saat gladi bersih: alur demo 5 menit |
| `panduan-tim.md` | Anggota tim yang baru bergabung |
| `ci-cd.md` | Cara pipeline bekerja |
| `keputusan-terbuka.md` | Pertanyaan yang menunggu jawaban pemilik |
| `audit-dokumen-vs-fitur.md` | Anda, sebelum sidang: klaim dokumen vs yang nyata di kode |
| `build-plan.md`, `tiket.md`, `rencana-konfigurabilitas.md` | **Arsip perencanaan.** Sengaja tidak ditulis ulang agar jejak keputusan jujur. Status terakhirnya ada di `README.md` akar repo |

## Berkas sementara — boleh dihapus kapan saja

| Berkas | Kenapa ada |
|---|---|
| `revisi.md` | Masukan audit mentah dari pemeriksa luar. **Sengaja tidak di-commit** (lihat `.gitignore`) karena bentuknya berubah tiap putaran audit |
| `akademik/_ukur/` | Hasil pengukuran jumlah halaman. Dibuat ulang oleh `akademik/ukur-halaman.ps1`, juga tidak di-commit |

Kalau Anda melihat berkas bernama `~$…docx`, itu berkas kunci Word: tanda
ada dokumen yang masih terbuka. Tutup Word-nya, lalu hapus berkas itu.

## Aturan yang membuat folder ini tidak berantakan

1. **Satu sumber, satu penggambar.** Data ada di `*-data.js`, penggambar di
   `*-gambar.js` atau `build*.js`. Tidak ada angka yang diketik dua kali.
2. **Hasil bangkitan tidak disunting tangan.** Suntingannya hilang pada build
   berikutnya. Sunting sumbernya, lalu bangun ulang.
3. **Setiap angka bisa dilacak.** Jumlah halaman, jumlah tabel, jumlah
   endpoint dibaca dari sumbernya — bukan dikarang. `node
   docs/uji/cek-angka-dokumen.js` membuktikannya.
4. **Berkas yang tidak dipakai lagi dihapus, bukan dibiarkan.** Kalau sebuah
   `.png` uji tidak lagi dihasilkan skrip mana pun, ia pergi — supaya tidak ada
   dua versi kebenaran.

## Memeriksa semuanya

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1     # seluruh repo
```

Perintah itu menjalankan tes PHP, uji browser, pemeriksa ERD/LRS, dan
pemeriksa angka dokumen sekaligus. **Jangan menjalankannya dua kali
bersamaan** — keduanya berbagi satu basis data.
