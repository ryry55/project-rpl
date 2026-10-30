# Fase akhir — yang tersisa, dan hanya Anda yang bisa mengerjakannya

Diperbarui 30 Oktober 2026. Semua yang bisa diperiksa mesin sudah diperiksa:
812 tes PHP, 19 tes Vitest, 30 skrip / 871 langkah browser, `uji-aksesibilitas.mjs`
27/27 di laptop **dan** di CI, gladi demo 39/39 pada database segar, uji beban
lulus. Empat pagar baru dibuktikan bisa **merah** lebih dulu, bukan hanya hijau.

Yang tertinggal di bawah ini bukan pekerjaan kode. Tidak ada yang bisa
dikerjakan agen tanpa Anda: semuanya butuh manusia, waktu, atau mesin yang
berbeda.

---

## 1. Latihan mengucapkan, tiga kali

Skripnya `docs/demo-sidang.md`. Lima menit, dihitung dengan pengatur waktu.

- [ ] Latihan 1 — sendirian, boleh berhenti dan membaca ulang.
- [ ] Latihan 2 — bertiga, dengan pembagian peran yang sudah disepakati.
- [ ] Latihan 3 — bertiga, tanpa berhenti, dihitung waktunya.

Empat hal di skrip itu baru saja diperbaiki karena **tidak cocok dengan layar**
saat dijalankan sungguhan. Jangan mengucapkan versi lama:

| Yang salah diucapkan | Yang benar di layar |
|---|---|
| "tekan Terbitkan tagihan" saat kebijakan kosong | tombolnya **tidak ada**; yang tampil panel "Kebijakan kos belum lengkap" + tautan "Isi kebijakan dulu" |
| "alasan kosong ditolak 422" | yang menolak lebih dulu **peramban** (`required`); 422 ada di server dan dibuktikan `uji-pembalik.mjs` |
| "jatuh tempo 16 September 2026" | di layar tertulis **"16 Sep 2026"** |
| "statusnya kembali menunggak" | lencananya berbunyi huruf kecil **"belum bayar"** |

## 2. Uji beban di laptop yang dipakai sidang

```powershell
node docs/beban/uji-beban.mjs
```

Lulus bila `p95` di bawah 1.000 ms dan `galat: 0`. Dua pengukuran di laptop
pengembangan: **464 ms** dan **840 ms** — selisihnya besar karena keadaan mesin,
jadi angka di mesin sidang harus diukur sendiri, bukan diwarisi dari sini.

Bila p95 melewati ambang: tutup Chrome dan tab lain lebih dulu, lalu ulangi.
Yang diukur adalah mesin yang sedang dipakai, bukan sifat aplikasi.

## 3. Tiga pertanyaan yang hampir pasti ditanya

Jawab apa adanya. Sistem ini demo lokal, dan itu bukan kelemahan yang perlu
disamarkan.

- **"Sudah dipakai penghuni sungguhan?"** — Belum. Yang ada: demo lokal dengan
  data contoh, 812 tes, dan uji browser yang menjalankan alurnya di peramban.
- **"Kalau servernya mati, datanya hilang?"** — Basis data PostgreSQL di dalam
  Docker, dan cadangannya sudah **diuji pulih**, bukan hanya dibuat:
  `docker compose exec -T api sh /var/www/html/docker/backup.sh --uji-pulih`
  memulihkan ke basis data sementara lalu menghapusnya lagi (kode keluar 0, tujuh
  tabel terhitung). Pemeriksaan 31 Oktober juga membandingkan isinya: dump data
  asli dan hasil pulih identik, dan kedelapan trigger ikut terbawa. Yang belum:
  menjalankan aplikasi terhadap data hasil pulih, dan pemulihan dari Neon (tidak
  ada deploy).
- **"Kenapa tidak online?"** — Deploy dibatalkan pemilik 29 Oktober 2026;
  penilaian dari laptop. Berkas deploy dan CI-nya tetap ada sebagai bukti kerja.

## 4. H-1, sekali saja

```powershell
docker compose up --build        # tunggu "[entrypoint] siap."
docker compose exec api php artisan migrate:fresh --seed
docker compose exec api php artisan cache:clear
```

Lalu pastikan tiga akun bisa masuk: `pemilik@kos.test`, `pengelola@kos.test`,
`penghuni@kos.test`, sandinya `password`. Jangan jalankan `migrate:fresh` lagi
sesudah gladi terakhir — data demo yang dipakai saat sidang harus yang terakhir
Anda lihat sendiri.

---

## Yang sengaja tidak dikerjakan

Sebutkan sebagai batas bila ditanya, jangan dijanjikan. Rinciannya di
`docs/utang-teknis.md` §6.

- Deploy ke hosting, Midtrans sungguhan, email Brevo sungguhan.
- Pemasangan PWA di ponsel asli, audit pembaca layar sungguhan (NVDA/VoiceOver).
- Penegakan CSP di peramban sungguhan.

**Fase 6 (laporan) masih menunggu format dari dosen.** Bila formatnya datang,
yang perlu dibuat hanya `docs/akademik/*` — berkas itu dibangkitkan dari data
yang sudah ada (`usecase-data.js`, `dfd-data.js`, hasil uji), bukan ditulis
ulang dari nol.

## Satu tiket GitHub yang tetap terbuka

**#13 (T14: laporan kerusakan properti + status kamar `perbaikan`).** Isinya
benar-benar belum ada: tidak ada tabel, model, unggahan foto, maupun status
`perbaikan` pada `kamar` — kamar rusak hari ini hanya bisa ditandai `kosong`, dan
itu berarti ia tampak siap disewakan. Bila dikerjakan, tiga hal harus Anda
putuskan lebih dulu: apakah kamar `perbaikan` tetap ditagih, siapa yang boleh
menutup laporan, dan apakah biaya perbaikan pernah masuk ke tagihan penghuni.

Tiga tiket lain ditutup 31 Oktober 2026 setelah diperiksa, bukan dibatalkan:
**#11** satuan sewa per kamar (mingguan/harian) ternyata sudah ada di
`Kalkulator` dan layar Kebijakan, **#12** mode jatuh tempo + saklar denda +
pembebasan denda sudah ada di `kebijakan_kos`, dan **#14** deposit tidak berlaku
karena deposit dihapus seluruhnya.
