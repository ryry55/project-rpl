# Serah-terima antar sesi

> **Ditimpa setiap fase selesai.** Isinya: apa yang barusan dikerjakan, tiga aksi
> berikutnya, dan apa yang tidak boleh diubah. Riwayatnya di `git log`.
> **Maksimal 120 baris** — lebih dari itu ia menjadi `SPEC.md` kedua.

Terakhir diperbarui: 17 November 2026, sesudah audit kode + verifikasi runtime.

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Sesi terakhir | **Audit kode 16-17 Nov** — temuan diverifikasi runtime, bukan hanya dibaca |
| Rencana berikutnya | **`docs/rencana-fase-8-10.md`** — Fase 8 (integritas uang) dikerjakan duluan |
| Suite PHP | **912 lulus / 4114 asersi**; pint & eslint bersih |
| Keputusan pemilik | **K-19, K-20 dijawab** (lihat §4); K-01..K-18 tidak berubah |
| Tunnel demo | **URL baru 16 Nov** (isi di `.env` `APP_URL`/`APP_URL_SPA`); verifikasi hidup: `nslookup <host> 8.8.8.8` |
| Working tree | Bersih sesudah commit dokumentasi audit |

## 2. Yang sesi audit kerjakan

Audit jalur uang/balapan tulis/kueri (beda dari audit 13 Nov: kebenaran kode,
dan 16 Nov: permukaan publik). **12 temuan**, semua berat dibuktikan di runtime
lewat tes sementara di `kos_test` (berkas dihapus sesudah verifikasi) —
6/6 reproduksi + 1 hitung kueri (2 tagihan terbuka → 2 kueri SUM per tagihan).
Rinciannya + langkah perbaikannya per fase: `docs/rencana-fase-8-10.md`.

Insiden saat audit: **tunnel demo mati senyap** — koneksi QUIC ke tepi
Cloudflare putus pukul 11:27 UTC, hostname jadi NXDOMAIN, tetapi proses
`cloudflared` terus hidup sebagai zombi. Dijalankan ulang, dua URL baru,
`.env` diperbarui, login/SPA/health/identitas diverifikasi hijau. Data demo
utuh (4 kamar, 3 tagihan, 2 pembayaran, 2 perangkat).

## 3. Tiga aksi berikutnya

1. **Kerjakan Fase 8** dari `docs/rencana-fase-8-10.md` — lima perbaikan
   jalur uang, tiap perbaikan diawali tes yang dibuktikan bisa gagal.
2. **Verifikasi tunnel sebelum tiap sesi demo**: `nslookup <host> 8.8.8.8`
   (NXDOMAIN = zombi, jalankan ulang; proses hidup bukan bukti — jebakan §5).
3. **Gate penuh menghapus data demo** — reseed: `migrate:fresh --seed` +
   `isi-demo.mjs` dengan URL tunnel dari `.env`, lalu `cache:clear`.

## 4. Yang tidak boleh diubah

| Hal | Di mana |
|---|---|
| Aturan bisnis R-01..R-08 + identitas kos + kerusakan + perangkat | `SPEC.md` bagian 4 |
| Wewenang pengelola (8) dan Model A | `SPEC.md` bagian 5 |
| Keputusan pemilik K-01..K-20 | `docs/keputusan-terbuka.md` |
| **K-19:** batal tagihan → transaksi gateway ikut `dibatalkan`; uang terlanjur masuk **dicatat** berlabel perlu tindakan | `docs/keputusan-terbuka.md` |
| **K-20:** bayar jumlah tautan penuh = **lunas**; denda dibekukan saat tautan dibuat | `docs/keputusan-terbuka.md` |
| Desain "buku besar tanpa warna" | `docs/produk/2_DESIGN_SYSTEM.md` |
| Deposit dihapus; biaya perbaikan tak pernah masuk tagihan | `docs/utang-teknis.md` §3 |

## 5. Jebakan yang sudah menggigit

- **Proses `cloudflared` hidup ≠ tunnel hidup.** Cek DNS (`nslookup <host>
  8.8.8.8`), bukan `Get-Process`. Berkasnya di
  `C:\Users\ACER\AppData\Local\cloudflared\` — tidak di PATH.
- **Kode dibakar ke image.** Tes baru tidak terlihat container sebelum
  `docker compose build api`; `--filter` menjawab "No tests found" padahal
  berkasnya ada. Build dulu, uji kemudian.
- **Tes reproduksi jangan menuntut hitungan setelah 500 di dalam transaksi
  RefreshDatabase** — pelanggaran unik membatalkan transaksi tes, kueri
  berikutnya gagal `25P02`. Asersi cukup sampai status responsnya.
- **`tinker --execute` menolak tag `<?php`** — hapus baris pembukanya.
- **`.env` dua baris `APP_URL`/`APP_URL_SPA` tidak bersebelahan** — sunting
  satu per satu.
- **Jangan menyunting berkas saat gate berjalan**; `docs/uji/*.png` ditulis
  ulang gate → `git checkout -- docs/uji/*.png` sebelum commit.
- **`api-web` menyimpan IP lama `api`** — selalu `restart api-web` sesudah
  mendiang ulang `api`.

## 6. Cara memeriksa keadaan

```powershell
powershell -ExecutionPolicy Bypass -File periksa.ps1              # SEMUA LULUS
powershell -ExecutionPolicy Bypass -File periksa.ps1 -TanpaBuild  # image sudah dibangun
node docs/uji/cek-angka-dokumen.js                                # angka + nama tes cocok
```

Tanpa uji browser: `-Cepat`. Di Linux/CI: `./periksa.sh`. Angka §1 diubah
**sesudah** gate. Uji beban (di luar gate): `node docs/beban/uji-beban.mjs`.
