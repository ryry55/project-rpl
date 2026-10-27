# Keputusan terbuka

> Pertanyaan yang menunggu jawaban pemilik. Dipakai oleh sesi yang **tidak punya
> sesi induk** — sesi opencode baru yang dibuka langsung di repo ini.
>
> Aturannya ada di `AGENTS.md` §"Kalau sesi butuh keputusan pemilik".
>
> **Jangan menunggu di dalam sesi.** Tulis pertanyaannya di sini, commit berkas
> ini sendirian, lalu berhenti. Tidak ada yang akan menjawab selama sesi masih
> terbuka.

## Bentuk entri

```
## K-01 · <judul singkat> · menunggu

**Fase:** R5 · **Tanggal:** 7 November 2026

**Pertanyaan.** Satu pertanyaan, dirumuskan sebagai pilihan.

**Pilihan.** A / B / C, masing-masing dengan akibatnya.

**Karena.** Mengapa ini tidak boleh diputuskan sendiri — sebutkan bagian
`SPEC.md` atau keputusan terkunci yang tersentuh.

**Kalau tidak dijawab.** Apa yang sesi lakukan: berhenti, atau kerjakan bagian
lain lebih dulu.
```

Sesudah dijawab, ubah `menunggu` menjadi `dijawab`, tambahkan tanggal dan
jawabannya di entri yang sama, lalu commit. Entri **tidak dihapus** — riwayat
keputusan ikut menjadi bukti, sama seperti `log_audit`.

---

## K-01 — Midtrans: kirim deskripsi tagihan atau hapus janji DFD — menunggu

**Fase:** audit dokumen **Tanggal:** 28 Oktober 2026

**Pertanyaan.** DFD aliran O-8 menjanjikan `deskripsi_tagihan` dikirim ke gerbang
pembayaran (`docs/dfd/README.md:96,209`, `docs/akademik/dfd-konteks.md:54`), tetapi
`MidtransGateway` hanya mengirim `transaction_details` + `customer_details`.
Kirim `item_details` ("Sewa kamar A1 — November 2026"), atau hapus field itu dari
DFD?

**Pilihan.**
- **A. Kirim `item_details`.** Halaman Midtrans menampilkan apa yang dibayar.
  Menyentuh payload uang: `price` harus sama dengan nominal yang diminta, kalau
  tidak Midtrans menolak transaksi. Perlu satu tes baru yang membandingkan
  keduanya.
- **B. Hapus `deskripsi_tagihan` dari O-8.** DFD berkata jujur; halaman gateway
  tetap generik. Tidak ada kode yang berubah.
- **C. Tunda.** Catat sebagai utang teknis dan tandai O-8 "sebagian" di
  `docs/dfd/README.md` §7.

**Karena.** Ini mengubah apa yang dilihat penghuni saat membayar dan menyentuh
nominal yang dikirim ke pihak ketiga — dua hal yang `AGENTS.md` larang diputuskan
sendiri.

**Kalau tidak dijawab.** O-8 ditandai "sebagian" di §7 (paling dekat ke C), tanpa
perubahan kode.

---

## K-02 — Izin menyunting `SPEC.md` — menunggu

**Fase:** audit dokumen **Tanggal:** 28 Oktober 2026

**Pertanyaan.** `SPEC.md` memuat dua pernyataan yang sudah tidak benar: `:536`
"85 endpoint" (nyata 86, dan tabel §7 hanya memuat 79) dan `:597` `/audit-log`
bergerbang `peran:pemilik` (nyata `wewenang:baca_audit`, sehingga pengelola
ber-wewenang bisa membacanya). Boleh menyunting `SPEC.md`?

**Pilihan.**
- **A. Sunting faktual saja.** 85→86, tambahkan 7 endpoint yang hilang di §7,
  perbaiki gerbang `/audit-log`. Isi keputusan tidak berubah.
- **B. Jangan sentuh `SPEC.md`.** Koreksi hanya di `6_TOOLS_CONTRACTS.md` dan
  catatan penyimpangan di `docs/serah-terima.md`.
- **C. Adendum.** Biarkan badan `SPEC.md`, tambahkan bagian "Adendum audit" di
  akhirnya.

**Karena.** `SPEC.md` adalah keputusan terkunci; yang diminta izinnya adalah
koreksi fakta, bukan perubahan aturan.

**Kalau tidak dijawab.** Tidak ada yang disunting; B dipakai sebagai jalan
sementara.

---

## K-03 — `GET /api/penghuni/kontrak` masuk use case mana — menunggu

**Fase:** audit dokumen **Tanggal:** 28 Oktober 2026

**Pertanyaan.** Endpoint ini ada di kode (bukti O-1), tetapi tidak dipetakan ke
satu pun dari 14 use case. `docs/akademik/build.js` sekarang lolos hanya karena
potret rutenya basi; begitu potret disegarkan, build gagal. Masukkan ke UC-13
(portal penghuni) dan segarkan potretnya?

**Pilihan.**
- **A. Masuk UC-13, potret disegarkan.** `use-case-diagram.md` berubah: 86
  endpoint, semuanya terpakai. Satu kalimat di §2 dan matriks ikut berubah.
- **B. Biarkan di luar diagram.** `build.js` berhenti menuntut "terpakai habis"
  dan menulis "86 endpoint, 1 tidak dipetakan (portal penghuni)" — jujur, tetapi
  melemahkan klaim ketertelusuran yang justru nilai jual dokumen ini.
- **C. Gabungkan ke UC-03.** Dianggap bagian dari "menerbitkan tagihan" — lemah
  secara semantik, karena penghuni membaca kontrak, bukan menerbitkan tagihan.

**Karena.** Ini mengubah isi artefak akademik (diagram use case dan matriks aktor),
bukan sekadar angka.

**Kalau tidak dijawab.** Tidak ada regenerasi potret; temuan ini tetap terbuka.

---

## K-04 — Gerbang memeriksa rute hidup atau tetap potret — menunggu

**Fase:** audit dokumen **Tanggal:** 28 Oktober 2026

**Pertanyaan.** Tiga pemeriksa (`cek-angka-dokumen.js`, `cek-cakupan-kontrak.js`,
`build.js`) membaca `docs/akademik/rute-api.json`, dan tidak ada langkah di
`periksa.ps1`, `periksa.sh`, maupun workflow yang membuat ulang potret itu. Karena
itu endpoint baru lolos tanpa ada yang memberi tahu. Mana yang dipakai?

**Pilihan.**
- **A. Bandingkan dengan rute hidup di gate.** Tambah satu langkah: `route:list`
  ke berkas sementara, lalu ketiga pemeriksa membaca hasil itu. Paling benar,
  tetapi gate dan CI jadi bergantung pada container hidup (sekarang sudah
  demikian untuk tes PHP) dan bertambah beberapa detik.
- **B. Potret tetap, tetapi dijaga umurnya.** `build.js` menolak jalan bila
  `api/routes/api.php` lebih baru dari `rute-api.json`. Murah, tetapi hanya
  menangkap kelalaian, bukan rute yang berubah di tempat lain.
- **C. Biarkan.** Catat sebagai batas yang diketahui di `7_TESTING_EVALS.md`.

**Karena.** Ini menyentuh gerbang yang dipakai setiap fase berikutnya, dan
`periksa.ps1`/`periksa.sh` harus tetap sinkron (CI memeriksa daftar skripnya).

**Kalau tidak dijawab.** Tidak ada perubahan; temuan F-01..F-04 tetap terbuka.
