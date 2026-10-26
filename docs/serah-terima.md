# Serah-terima antar sesi

> **Berkas ini ditimpa setiap fase selesai.** Ia menjawab satu pertanyaan saja:
> sesi baru membuka repo ini, apa yang dikerjakan berikutnya, dan apa yang tidak
> boleh diubah. Riwayatnya ada di `git log`, bukan di sini.
>
> **Maksimal 120 baris.** Kalau lebih, isinya sudah menjadi `SPEC.md` kedua.

Terakhir diperbarui: QRIS + materi sidang + perbaikan bukti - 26 Oktober 2026

---## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Fase terakhir selesai | QRIS di basis data, tombol Tarik kembali, materi sidang, perbaikan bukti |
| Berikutnya | **CI/CD + deploy**, lalu verifikasi Midtrans sandbox - keduanya butuh akun/kunci pemilik |
| Suite PHP | **748 lulus / 3522 asersi**, 39 berkas tes; pint & eslint bersih |
| Migrasi / endpoint / tabel domain | **41** / **81** / **23** (total 32 di skema `public`) |
| Skrip uji | **26** (24 browser + 2 balapan), **760 langkah** |
| `periksa-profil-produksi.sh` | **22/22 LULUS**; working tree bersih |

---

## 2. Tiga aksi berikutnya

1. **CI/CD.** `deploy.yml` masih mode peringatan (`siap=false`) karena target
   Render/Neon belum ada. CI E2E **menggantung 17+ menit di runner** sementara
   lulus ~390 detik di lokal - **utang teknis yang diterima**, jangan dikejar.
2. **Verifikasi Midtrans sandbox.** Butuh `MIDTRANS_SERVER_KEY` sungguhan; kode
   dan 16 tesnya lulus dengan kredensial palsu, tetapi transaksi sungguhan ke
   sandbox belum pernah terjadi.
3. **Baca `docs/akademik/README.md`** sebelum menyentuh apa pun di sana. Semua
   berkas akademik **dibangkitkan**; suntingan tangan hilang pada build
   berikutnya. Ubah `usecase-data.js` / `dokumen-data.js` / `dfd-data.js`, lalu
   `node docs/akademik/build.js && node docs/akademik/build-dokumen.js`.

---

## 3. Yang tidak boleh diubah

Jangan menyimpang dari ini tanpa keputusan pemilik.

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-07 | `SPEC.md` bagian 4 |
| Wewenang pengelola (8) dan Model A | `SPEC.md` bagian 5 |
| Konvensi T24-T29 | `AGENTS.md` |
| Sumber tunggal DFD / use case | `docs/dfd/dfd-data.js`, `docs/akademik/usecase-data.js` |
| Keputusan terbuka | `docs/keputusan-terbuka.md` |
| Langkah deploy manual | `docs/produk/9_DEPLOY.md` |

**Narasi tetap 4 halaman, bukan 3** - sudah diputuskan pemilik. Template dosen
hanya punya 10 aliran dan 5 entitas; sistem ini 14 aliran, 12 entitas, 7 aturan.
Kedua PDF sudah diekspor: narasi 4 halaman, diagram tepat 2 halaman. Jangan
"merapikannya" tanpa bertanya. **Harga Charter tetap Rp 50.000.000**, jadwal
mengikuti pertemuan kuliah mulai 8 September 2026.

---

## 4. Cacat yang sudah ditutup sesi ini

**QRIS di basis data, bukan di disk.** `public/` read-only, Render free tanpa
disk persisten. Gambar di kolom `bytea`, keluar lewat
`GET /api/rekening/{id}/gambar` ber-token. Jebakan yang dibuktikan lebih dulu:
parameter teks biasa — termasuk `mass assignment` Eloquent — menyimpan **0 byte
tanpa galat**; hanya `decode(?, 'base64')` yang utuh.

**Tombol "Tarik kembali".** Endpoint pembalik bekerja sejak R2, tetapi tidak ada
tombolnya di layar. Sekarang ada, dan alasan penarikan wajib diisi.

**Jatuh tempo bulan masuk mendahului tanggal masuk.** Kebijakan *tanggal tetap
15* diterapkan juga pada bulan masuk: masuk 16 September, tagihan September
jatuh tempo 15 September — sehari sebelum ia menempati kamarnya, sudah
menunggak, dan dendanya berjalan dari tanggal ia belum berutang. Ditemukan saat
memverifikasi angka panduan sidang, bukan lewat tes. Dikunci dua tes.

**Dua bukti tangkapan layar byte-identik.** `T28-audit-anonim` dan
`T28-jejak-teranonim` sama persis dan isinya halaman Kamar: `e2e-t28` memanggil
API tanpa berpindah halaman lalu memotret apa adanya. Sekarang keduanya membuka
halamannya dan menunggu baris "pengguna dihapus".

**Mojibake `â€"` di antarmuka.** 16 tempat di `PengaturanPdpView.vue` dan
`KebijakanKosTest.php`: em-dash yang terbaca sebagai CP1252. Hanya terlihat lewat
potret layar — konsol PowerShell menampilkannya seolah benar.

---## 5. Cara memeriksa keadaan

```powershell
git status --short                              # harus bersih
docker compose exec -T api php artisan test      # 748 lulus / 3522 asersi
cd api; php vendor\bin\pint --test                # di HOST, bukan container
cd web; npx eslint src; npm run build             # bersih
bash docs/uji/periksa-profil-produksi.sh          # 22/22 LULUS
powershell -ExecutionPolicy Bypass -File periksa.ps1   # SEMUA LULUS
node docs/akademik/build.js                       # 81 endpoint terpetakan habis
node docs/akademik/build-dokumen.js               # 3 dokumen dibangkitkan
```

Untuk sidang, baca **`docs/demo-sidang.md`** — urutan demo 5 menit dan jawaban
atas pertanyaan yang paling sering muncul. Angka di dalamnya sudah dijalankan
pada sistem, bukan dihitung di atas kertas.

---

## 6. Jebakan yang menggigit di sesi ini

Tercatat juga di `AGENTS.md`; diulang di sini karena mahal.

1. **`docker compose exec api` menjalankan kode dari IMAGE, bukan dari disk.**
   Build image dulu setelah menyunting `api/`. **Pint dijalankan di host.**
2. **`git add -A` menelan berkas audit.** Keduanya kini di `.gitignore`.
3. **PowerShell `>` menulis UTF-16** dan `JSON.parse` menolaknya; `-replace` dan
   heredoc juga rusak tanpa suara. Pakai `WriteAllText(..., UTF8Encoding($false))`
   dan berkas `.py` untuk suntingan teks.
4. **Mengetik angka di dokumen selalu tertinggal.** Generator akademik sekarang
   menghitung migrasi dari direktori dan endpoint/tabel dari snapshot JSON.
5. **Mojibake tidak terlihat di konsol.** Em-dash yang terbaca sebagai CP1252
   tampil benar di PowerShell tetapi rusak di peramban. Periksa byte, atau
   lihat potret layarnya.
6. **Chrome menolak `--print-to-pdf` dengan path relatif.** Pakai path absolut.