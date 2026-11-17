# Serah-terima antar sesi

> **Ditimpa setiap fase selesai.** Isinya: apa yang barusan dikerjakan, tiga aksi
> berikutnya, dan apa yang tidak boleh diubah. Riwayatnya di `git log`.
> **Maksimal 120 baris** — lebih dari itu ia menjadi `SPEC.md` kedua.

Terakhir diperbarui: 17 November 2026, audit + fase dokumentasi sesudah Fase 8.

---

## 1. Keadaan sekarang

| Hal | Nilai |
|---|---|
| Sesi terakhir | **Fase 8** — lima perbaikan audit jalur uang (K-19, K-20) |
| Rencana berikutnya | **Review charter oleh pemilik**, lalu Fase 9-10 `docs/rencana-fase-8-10.md` |
| Suite PHP | **918 lulus / 4142 asersi**; pint & eslint bersih |
| Gate penuh | **SEMUA LULUS** 17 Nov (pemeriksa dokumen + 34 skrip browser ikut hijau) |
| Keputusan pemilik | **K-19, K-20 dijawab & diterapkan** (lihat §4); K-01..K-18 tidak berubah |
| Tunnel demo | URL baru 17 Nov di `.env` (URL 16 Nov mati); hidup dicek `nslookup <host> 8.8.8.8` |
| Paket serahan | **`dokumen/`** — satu subfolder per dokumen; segarkan dengan `dokumen\segarkan.ps1` |
| CI GitHub | **hijau** pada `70996a8` (run `44029172981`) — kode Fase 8 tervalidasi run yang sama |
| Working tree | Bersih sesudah commit audit dokumen |

## 2. Yang sesi Fase 8 kerjakan

Lima temuan audit 16-17 Nov (lihat `docs/rencana-fase-8-10.md`):

1. **#1** `mulai()`: sisa baris `menunggu` tanpa tautan dihapus saat ditemukan;
   panggilan gateway dibungkus try/catch — kegagalan/timeout menghapus barisnya
   (`GatewayMenolak::tidakMenjawab`), percobaan berikutnya dapat tautan baru.
2. **#3** `PUT /penghuni/{id}` dibungkus `DB::transaction` (surep di luar).
3. **#4** `lockForUpdate` baris kamar di `akhiri()` (setelah kontrak selesai,
   sebelum cek `masihAktif`), `store()`, dan `selesai()` kerusakan.
4. **K-19** `PembatalTagihan` menandai tautan `menunggu` → `dibatalkan`
   (audit `tautan_gateway_dimatikan`); uang yang masuk untuk tagihan batal
   TETAP dicatat berlabel "perlu tindakan" (rekap jujur; refund di luar app);
   webhook tidak menurunkan transaksi `dibatalkan`.
5. **K-20** kolom `transaksi_gateway.total_beku`; bayar nominal tautan penuh =
   LUNAS, `total_final` dibekukan saat tautan dibuat.

Konvensi dipegang: 6 tes baru **dibuktikan merah dulu**, lalu hijau; suite
912 → 918. Dokumen ikut disegarkan: kolom 228 → 229, migrasi 47 → 48, huruf
LRS 2,54 → 2,56 mm (celah kotak 86 → 78 px; **ambang 2,52 mm tidak dilepas** —
build pertama memang ditolak pagar, itu bukti pagarnya hidup), tabel ukur 24 sel
di dua README, diagram kelas-c memuat `total_beku`, PDF Bab 4 + folder
`docs/akademik/final/` dibangun ulang (lembar b → b1/b2).

Fase dokumentasi (17 Nov, sesi audit + perbaikan):

- **UC-08 digambar ulang** sebagai alur webhook Midtrans — nominal dicocokkan
  dengan `total_beku` (K-20), cabang "perlu tindakan" bila tagihan batal
  (K-19); catat manual tetap memakai `PencatatPembayaran` yang sama.
- **UC-13 digambar ulang** sebagai alur `bayar-daring` — tautan dipakai ulang,
  nominal dibekukan saat tautan dibuat, sisa transaksi gagal dibuang (temuan
  #1). Judul UC-09 dikoreksi ("daring" salah — diagramnya verifikasi manual).
- **Peta ketertelusuran** +4 baris (K-19 dua perilaku, K-20, temuan #1);
  **DFD level-2 REV-FIN-02** memuat K-19 pada perilaku + titik uji.
- Pagar diagram terbukti hidup lagi: dua iterasi label sampai kanvas <= 1400
  px dan huruf >= 2,52 mm (UC-08 2,63 mm; UC-13 2,79 mm).

## 3. Tiga aksi berikutnya

1. **Review charter `docs/produk/KONTEKS_PROYEK.md` oleh pemilik** — angka
   sudah dikoreksi audit 17 Nov; yang dicek: kejujuran ruang lingkup, tabel
   "Yang sudah selesai", dan klaim demo. Fase dokumentasi kode sudah tuntas;
   diagram kelas memang tidak menunggu apa pun (dijaga pemeriksa gate).
2. **Fase 9** (performa): withSum N+1, `per_page` pembayaran, `now()` →
   `WaktuSistem` di 5 titik, uji beban banding p95 610 ms.
3. **Fase 10** (pemolesan): composable `useMuatDaftar`, kode mati, port ke
   loopback, `X-Powered-By`, image produksi.

## 4. Keputusan terkunci (baru)

- **K-19 "kunci dua pintu + catat"**: batal mematikan tautan; uang pasca-batal
  dicatat berlabel — BUKAN menolak pembatalan, BUKAN idempotency.
- **K-20 "bekukan denda di nominal tautan"**: bayar penuh = lunas;
  `total_beku` nullable (baris lama pakai total hidup).

## 5. Jebakan yang sudah menggigit (baru)

- **Closure `use` lupa menangkap parameter baru** → `ErrorException` → 500
  tanpa jejak jelas. Dua regresi dari satu pola (`$sebelum`, `$totalBeku`/
  `$terimaPadaBatal`). Selalu periksa daftar `use` saat menambah parameter.
- **Tes balapan via hook `retrieved`**: hook menembak saat baris dikunci
  (`lockForUpdate`) — kalau hook membuat kontrak aktif kedua SEBELUM kontrak
  lama berstatus `selesai`, indeks `kontrak_satu_aktif_per_kamar` yang
  menolak, bukan kode. Letakkan hook pada titik yang mau diuji (setelah
  kontrak selesai, sebelum cek `masihAktif`).
- **`kelas-data.js` menurunkan atribut dari `skema.json`**: migrasi kolom
  membuat `kelas-c.svg` basi OTOMATIS — gate merah di "diagram kelas dan
  urutan tidak basi". Perbaikannya `node docs/akademik/build-diagram.js` +
  `export-png.ps1`, bukan menyunting SVG.
- **Pipa `Select-Object -Last N` pada gate latar membuang output**: hanya N
  baris terakhir yang tersimpan di berkas .out — waktu/jumlah langkah tak bisa
  diverifikasi dari run itu. Jalankan gate tanpa pipa bila butuh angkanya.
- **Klaim status di `dokumen/README.md` dibuktikan ke pemeriksa gate dulu**:
  "metode kelas menunggu sinkron" ternyata salah diagnosis — metode diagram
  kelas diturunkan otomatis dari model, dan kelas layanan domain memang tidak
  digambar (aturan `kelas-data.js`: tiap kelas wajib punya tabel di skema).
- **Hitung trigger dari basis data hidup, bukan dari teks migrasi**:
  `parameter_denda_append_only` ikut mati bersama tabelnya di `hapus_deposit`
  — migrasi memuat 9 nama, DB hidup punya 8. Query langsung
  `docker compose exec db psql -U kos -d kos -tAc "..."` (jangan lewat
  `sh -c` — baris hasilnya tertelan).

## 6. Verifikasi klaim berkas ini

```powershell
docker compose exec api php artisan test        # 918 tes, 4142 asersi
node docs/uji/cek-angka-dokumen.js              # SEMUA ANGKA COCOK
node docs/uji/cek-erd-lrs.js                     # 26 tabel, 229 kolom
node docs/uji/cek-diagram.js                    # SVG = data, PNG = 2x SVG
```
